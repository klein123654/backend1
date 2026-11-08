<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inquiry;
use App\Models\Property;
use App\Services\DeepSeekService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        $this->authorizeConversation($request, $inquiry);

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
        $this->authorizeConversation($request, $inquiry);
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

    private function authorizeConversation(Request $request, Inquiry $inquiry): void
    {
        $user = $request->user();
        abort_unless($user && $user->account_status === 'active', 403);

        $property = $inquiry->property;
        $authorized = $user->role === 'admin'
            || ($user->role === 'buyer' && (int) $inquiry->buyer_id === (int) $user->id)
            || (in_array($user->role, ['owner', 'developer'], true) && (int) $property->owner_id === (int) $user->id)
            || ($user->role === 'broker' && (
                (int) $inquiry->assigned_broker_id === (int) $user->id
                || (int) $property->broker_id === (int) $user->id
            ));

        abort_unless($authorized, 403);
    }

    public function update(Request $request, Inquiry $inquiry): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->account_status === 'active', 403);
        $authorized = $user->role === 'admin'
            || (in_array($user->role, ['owner', 'developer'], true) && (int) $inquiry->property()->value('owner_id') === (int) $user->id)
            || ($user->role === 'broker' && (int) $inquiry->assigned_broker_id === (int) $user->id);
        abort_unless($authorized, 403);

        $data = $request->validate(['status' => ['required', 'in:new,in_progress,resolved']]);
        $inquiry->update($data);

        return response()->json(['data' => $inquiry->fresh()->load('property:id,title,owner_id,broker_id')]);
    }
}
