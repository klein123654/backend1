<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\PublisherProfile;
use Illuminate\Http\JsonResponse;

class PublisherController extends Controller
{
    /**
     * Public profile page of a verified publisher (owner or broker)
     * with the number of listings they currently have live.
     */
    public function show(User $user): JsonResponse
    {
        abort_unless($user->isPublisher() && $user->account_status === 'active', 404);

        $user->load(['brokerVerifications' => PublisherProfile::verifiedCredentials(...)]);

        $profile = PublisherProfile::make($user);
        abort_unless($profile['approved'], 404);

        return response()->json([
            'data' => [
                ...$profile,
                'active_listing_count' => $user->ownedProperties()->onMarket()->count(),
            ],
        ]);
    }
}
