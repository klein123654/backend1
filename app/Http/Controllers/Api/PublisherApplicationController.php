<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PublisherApplication;
use App\Support\BrokerCredentials;
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
            'meta' => [
                'dhsud_notice' => BrokerCredentials::DHSUD_NOTICE,
                'suggested_affiliations' => BrokerCredentials::SUGGESTED_AFFILIATIONS,
            ],
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
            'requested_role' => ['required', 'in:owner,broker'],
            'ownership_type' => ['required_if:requested_role,owner', 'nullable', 'in:individual,company,representative'],
            'business_name' => ['nullable', 'string', 'max:255'],
            ...BrokerCredentials::rules('requested_role,broker'),
        ], [
            'requested_role.in' => 'Choose whether you are applying as a property owner or a licensed broker.',
            ...BrokerCredentials::messages(),
        ]);

        // Keep only the details that belong to the chosen role.
        $brokerFields = array_keys(array_diff_key(BrokerCredentials::rules(), ['affiliations.*' => true]));
        $data = $data['requested_role'] === 'broker'
            ? BrokerCredentials::clean(array_diff_key($data, ['ownership_type' => true, 'business_name' => true]))
            : array_diff_key($data, array_flip($brokerFields));

        $application = PublisherApplication::create([
            ...$data,
            'user_id' => $user->id,
            'status' => 'pending',
        ]);

        return response()->json(['data' => $application], 201);
    }
}
