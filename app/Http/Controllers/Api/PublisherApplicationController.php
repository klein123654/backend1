<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PublisherApplication;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublisherApplicationController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        abort_unless($request->user()->account_status === 'active', 403);

        return response()->json([
            'data' => PublisherApplication::query()
                ->where('user_id', $request->user()->id)
                ->latest()
                ->first(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->account_status === 'active', 403);
        abort_unless($user->role === 'buyer', 409, 'This account already has publisher access.');
        abort_if(
            PublisherApplication::where('user_id', $user->id)->where('status', 'pending')->exists(),
            409,
            'You already have a publisher application awaiting review.'
        );

        $data = $request->validate([
            'requested_role' => ['required', 'in:owner,broker,developer'],
            'ownership_type' => ['required_if:requested_role,owner', 'nullable', 'in:individual,company,representative'],
            'business_name' => ['required_if:requested_role,developer', 'nullable', 'string', 'max:255'],
            'license_number' => ['required_if:requested_role,broker', 'nullable', 'string', 'max:100'],
            'agency_name' => ['nullable', 'string', 'max:255'],
        ]);

        $application = PublisherApplication::create([
            ...$data,
            'user_id' => $user->id,
            'status' => 'pending',
        ]);

        return response()->json(['data' => $application], 201);
    }
}
