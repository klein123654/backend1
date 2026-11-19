<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inquiry;
use App\Models\Property;
use App\Models\User;
use App\Notifications\InquiryReceived;
use App\Notifications\InquiryReplyReceived;
use App\Services\DeepSeekService;
use App\Services\MessageSafety;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class InquiryController extends Controller
{
    public function store(Request $request, Property $property, DeepSeekService $deepSeek): JsonResponse
    {
        abort_unless(
            $request->user() &&
            $request->user()->account_status === 'active' &&
            $request->user()->role !== 'admin',
            403,
            'Sign in with an active member account to send an inquiry.'
        );
        abort_unless($property->isPubliclyVisible(), 404);
        abort_unless($property->isOnMarket(), 409, 'This property is no longer available, so it cannot receive new inquiries.');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'message' => ['required', 'string', 'min:10', 'max:10000'],
        ]);

        $inquiry = DB::transaction(function () use ($data, $property, $request): Inquiry {
            $inquiry = Inquiry::create([
                ...$data,
                'property_id' => $property->id,
                'buyer_id' => $request->user()->id,
                'assigned_broker_id' => $property->broker_id,
                'status' => 'new',
                'ai_summary' => null,
                'ai_priority' => 'unscored',
            ]);

            $sentAt = now();
            $inquiry->messages()->create([
                'sender_id' => $request->user()->id,
                'body' => $data['message'],
            ]);
            $inquiry->forceFill(['last_message_at' => $sentAt])->save();

            return $inquiry;
        });

        try {
            $score = $deepSeek->scoreInquiry($inquiry->message);

            if ($score['success']) {
                $inquiry->forceFill([
                    'ai_summary' => $score['summary'],
                    'ai_priority' => $score['priority'],
                ])->save();
            }
        } catch (\Throwable) {
            // AI scoring is optional and must not prevent inquiry creation.
        }

        $recipients = User::query()
            ->whereIn('id', array_filter([$property->owner_id, $property->broker_id]))
            ->where('id', '!=', $request->user()->id)
            ->get();

        if ($recipients->isNotEmpty()) {
            $this->notifySafely($recipients, new InquiryReceived($inquiry->setRelation('property', $property)));
        }

        return response()->json(['data' => $inquiry->load('property:id,title,owner_id,broker_id')], 201);
    }

    public function mine(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->account_status === 'active', 403);
        $query = Inquiry::query()
            ->with('property:id,title,owner_id,broker_id')
            ->orderByRaw('COALESCE(last_message_at, created_at) DESC')
            ->orderByDesc('id');

        if ($user->role === 'buyer') {
            $query->where('buyer_id', $user->id);
        } elseif ($user->role === 'owner') {
            $query->whereHas('property', fn ($builder) => $builder->where('owner_id', $user->id));
        } elseif ($user->role === 'broker') {
            $query->where('assigned_broker_id', $user->id);
        } else {
            abort(403);
        }

        return response()->json(['data' => $query->paginate(50)->items()]);
    }

    public function sent(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->account_status === 'active' && $user->role !== 'admin', 403);

        return response()->json([
            'data' => $user->buyerInquiries()
                ->with('property:id,title,owner_id,broker_id')
                ->orderByRaw('COALESCE(last_message_at, created_at) DESC')
                ->orderByDesc('id')
                ->paginate(50)
                ->items(),
        ]);
    }

    public function messages(Request $request, Inquiry $inquiry): JsonResponse
    {
        Gate::authorize('view', $inquiry);

        return response()->json([
            'data' => $inquiry->messages()
                ->where('is_internal', false)
                ->with('sender:id,name')
                ->orderBy('created_at')
                ->orderBy('id')
                ->get(['id', 'inquiry_id', 'sender_id', 'body', 'safety_warning', 'created_at']),
        ]);
    }

    public function storeMessage(Request $request, Inquiry $inquiry, MessageSafety $safety): JsonResponse
    {
        Gate::authorize('view', $inquiry);
        $data = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ]);

        $user = $request->user();
        $rateLimitKey = 'inquiry-messages:user:'.$user->id;
        abort_if(RateLimiter::increment($rateLimitKey, 60) > 20, 429, 'You may send up to 20 messages per minute.');

        $body = trim($data['body']);
        $warning = $safety->check($body);

        $message = DB::transaction(function () use ($body, $warning, $inquiry, $user) {
            $message = $inquiry->messages()->create([
                'sender_id' => $user->id,
                'body' => $body,
                'safety_warning' => $warning,
            ]);

            $updates = ['last_message_at' => $message->created_at];
            // A publisher's first reply moves the inquiry out of "new".
            if ($user->id !== $inquiry->buyer_id && $inquiry->status === 'new') {
                $updates['status'] = 'in_progress';
            }
            $inquiry->forceFill($updates)->save();

            return $message;
        });

        $this->notifyReplyRecipients($inquiry, $message, $user);

        return response()->json([
            'data' => $message->load('sender:id,name'),
        ], 201);
    }

    /**
     * AI-drafted reply for the publisher to review and edit. Nothing is sent.
     */
    public function suggestReply(Request $request, Inquiry $inquiry, DeepSeekService $deepSeek): JsonResponse
    {
        Gate::authorize('update', $inquiry);
        $user = $request->user();
        abort_if($user->role === 'admin', 403, 'Only the listing publisher can draft replies.');
        abort_if(RateLimiter::increment('inquiry-reply-drafts:user:'.$user->id, 60) > 10, 429, 'You can draft up to 10 replies per minute.');

        $property = $inquiry->property()->first();
        $conversation = $inquiry->messages()
            ->where('is_internal', false)
            ->latest('id')
            ->limit(8)
            ->get(['sender_id', 'body'])
            ->reverse()
            ->map(fn ($message): array => [
                'from' => (int) $message->sender_id === (int) $inquiry->buyer_id ? 'inquirer' : 'publisher',
                'text' => Str::limit($message->body, 1200),
            ])
            ->values()
            ->all();

        $draft = $deepSeek->suggestReply([
            'publisher_name' => $user->name,
            'inquirer_name' => $inquiry->name,
            'listing' => $property ? array_filter([
                'title' => $property->title,
                'property_type' => $property->property_type,
                'listing_type' => $property->listing_type,
                'price_php' => (float) $property->price,
                'price_note' => $property->listing_type === 'rent' ? 'per month' : null,
                'floor_area_sqm' => $property->floor_area ? (float) $property->floor_area : null,
                'lot_area_sqm' => $property->lot_area ? (float) $property->lot_area : null,
                'parking_spaces' => $property->parking_spaces,
                'address' => trim($property->address_line.', '.$property->barangay.', '.$property->city, ', '),
                'availability' => $property->availability,
                'description' => Str::limit((string) $property->description, 1500),
            ], fn ($value) => $value !== null && $value !== '') : null,
            'conversation' => $conversation,
        ]);

        if (! $draft['success']) {
            return response()->json(['message' => $draft['message'], 'type' => 'ai_unavailable'], 503);
        }

        return response()->json(['reply' => $draft['reply']]);
    }

    /** One reply email per recipient per conversation in this window. */
    private const REPLY_EMAIL_COOLDOWN_SECONDS = 600;

    /**
     * Email the other side of the conversation. Rapid back-and-forth sends at
     * most one email per recipient every ten minutes.
     */
    private function notifyReplyRecipients(Inquiry $inquiry, $message, User $sender): void
    {
        $inquiry->loadMissing('property:id,title,owner_id,broker_id');
        $notification = new InquiryReplyReceived($inquiry, $message, $sender->name);
        $shouldSend = fn (string $recipientKey): bool => Cache::add(
            'inquiry-reply-email:'.$inquiry->id.':'.$recipientKey,
            true,
            self::REPLY_EMAIL_COOLDOWN_SECONDS
        );

        if ($sender->id === $inquiry->buyer_id) {
            $recipients = User::query()
                ->whereIn('id', array_filter([$inquiry->property?->owner_id, $inquiry->assigned_broker_id]))
                ->where('id', '!=', $sender->id)
                ->where('account_status', 'active')
                ->get()
                ->filter(fn (User $recipient): bool => $shouldSend('user:'.$recipient->id));

            if ($recipients->isNotEmpty()) {
                $this->notifySafely($recipients, $notification);
            }

            return;
        }

        $buyer = $inquiry->buyer_id ? User::find($inquiry->buyer_id) : null;
        if ($buyer && $buyer->account_status === 'active') {
            if ($shouldSend('user:'.$buyer->id)) {
                $this->notifySafely($buyer, $notification);
            }
        } elseif (! $buyer && $inquiry->email && ! str_ends_with($inquiry->email, '.invalid') && $shouldSend('email:'.$inquiry->email)) {
            try {
                Notification::route('mail', $inquiry->email)->notify($notification);
            } catch (\Throwable $exception) {
                report($exception);
            }
        }
    }

    public function update(Request $request, Inquiry $inquiry): JsonResponse
    {
        Gate::authorize('update', $inquiry);

        $data = $request->validate(['status' => ['required', 'in:new,in_progress,resolved']]);
        $inquiry->update($data);

        return response()->json(['data' => $inquiry->fresh()->load('property:id,title,owner_id,broker_id')]);
    }
}
