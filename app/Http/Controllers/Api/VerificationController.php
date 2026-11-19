<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BrokerVerification;
use App\Support\BrokerCredentials;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VerificationController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        abort_unless($request->user()->role === 'broker' && $request->user()->account_status === 'active', 403);

        return response()->json([
            'data' => $request->user()->brokerVerifications()->first(),
            'meta' => [
                'dhsud_notice' => BrokerCredentials::DHSUD_NOTICE,
                'suggested_affiliations' => BrokerCredentials::SUGGESTED_AFFILIATIONS,
            ],
        ]);
    }

    /**
     * Submit or renew the broker's credentials and firm details. Every
     * submission goes back to an administrator for checking; until it is
     * approved the broker cannot add or edit listings, but existing listings
     * stay public.
     */
    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->role === 'broker' && $request->user()->account_status === 'active', 403);
        $data = BrokerCredentials::clean($request->validate(BrokerCredentials::rules(), BrokerCredentials::messages()));
        $data += ['firm_position' => null, 'firm_address' => null, 'firm_registration_number' => null, 'affiliations' => null];

        $verification = BrokerVerification::updateOrCreate(
            ['broker_id' => $request->user()->id],
            [
                ...$data,
                'status' => 'pending',
                'reviewed_by' => null,
                'reviewed_at' => null,
                'review_notes' => null,
                'expiry_reminder_sent_at' => null,
            ],
        );

        return response()->json([
            'message' => 'Your credentials were sent to an administrator for checking.',
            'data' => $verification,
        ], 201);
    }
}
