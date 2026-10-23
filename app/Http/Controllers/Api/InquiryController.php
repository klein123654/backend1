<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inquiry;
use App\Models\Property;
use App\Services\DeepSeekService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        $inquiry = Inquiry::create([
            ...$data,
            'property_id' => $property->id,
            'buyer_id' => $request->user()->id,
            'assigned_broker_id' => $property->broker_id,
            'status' => 'new',
            'ai_summary' => null,
            'ai_priority' => 'unscored',
        ]);

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
        $query = Inquiry::query()->with('property:id,title,owner_id,broker_id')->latest();

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
            'data' => $user->buyerInquiries()->with('property:id,title,owner_id,broker_id')->latest()->paginate(50)->items(),
        ]);
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
