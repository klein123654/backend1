<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BrokerVerification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VerificationController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        abort_unless($request->user()->role === 'broker' && $request->user()->account_status === 'active', 403);

        return response()->json(['data' => $request->user()->brokerVerifications()->first()]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->role === 'broker' && $request->user()->account_status === 'active', 403);
        $data = $request->validate([
            'license_number' => ['required', 'string', 'max:100'],
            'agency_name' => ['nullable', 'string', 'max:255'],
        ]);
        $verification = BrokerVerification::updateOrCreate(
            ['broker_id' => $request->user()->id],
            [...$data, 'status' => 'pending', 'reviewed_by' => null, 'reviewed_at' => null, 'review_notes' => null],
        );

        return response()->json(['data' => $verification], 201);
    }
}
