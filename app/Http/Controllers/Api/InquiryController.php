<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inquiry;
use App\Models\Property;
use App\Models\User;
use App\Notifications\InquiryReceived;
use App\Services\DeepSeekService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;

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
        abort_unless($property->status === 'published' && $property->published_at, 404);

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
        } elseif (in_array($user->role, ['owner', 'developer'], true)) {
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
                ->get(['id', 'inquiry_id', 'sender_id', 'body', 'created_at']),
        ]);
    }

    public function storeMessage(Request $request, Inquiry $inquiry): JsonResponse
    {
        Gate::authorize('view', $inquiry);
        $data = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ]);

        $user = $request->user();
        $rateLimitKey = 'inquiry-messages:user:'.$user->id;
        abort_if(RateLimiter::increment($rateLimitKey, 60) > 20, 429, 'You may send up to 20 messages per minute.');

        $message = DB::transaction(function () use ($data, $inquiry, $user) {
            $message = $inquiry->messages()->create([
                'sender_id' => $user->id,
                'body' => trim($data['body']),
            ]);

            $inquiry->forceFill(['last_message_at' => $message->created_at])->save();

            return $message;
        });

        return response()->json([
            'data' => $message->load('sender:id,name'),
        ], 201);
    }

    public function update(Request $request, Inquiry $inquiry): JsonResponse
    {
        Gate::authorize('update', $inquiry);

        $data = $request->validate(['status' => ['required', 'in:new,in_progress,resolved']]);
        $inquiry->update($data);

        return response()->json(['data' => $inquiry->fresh()->load('property:id,title,owner_id,broker_id')]);
    }
}
