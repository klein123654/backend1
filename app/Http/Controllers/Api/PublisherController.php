<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\PublisherProfile;
use Illuminate\Http\JsonResponse;

class PublisherController extends Controller
{
    /**
     * Public profile page of a verified publisher (owner, developer or broker)
     * with the number of listings they currently have live.
     */
    public function show(User $user): JsonResponse
    {
        abort_unless($user->isPublisher() && $user->account_status === 'active', 404);

        $user->load(['brokerVerifications' => fn ($query) => $query
            ->where('status', 'verified')
            ->select(['id', 'broker_id', 'license_number', 'agency_name', 'status'])]);

        $profile = PublisherProfile::make($user);
        abort_unless($profile['verified'], 404);

        return response()->json([
            'data' => [
                ...$profile,
                'active_listing_count' => $user->ownedProperties()->publiclyVisible()->count(),
            ],
        ]);
    }
}
