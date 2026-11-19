<?php

namespace App\Http\Controllers;

use App\Models\Property;
use App\Services\BrokerCredentialReminders;
use App\Services\ListingAvailabilityReminders;
use App\Support\FrontendUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ListingAvailabilityController extends Controller
{
    /**
     * One-click "still available" link from the reminder email (signed URL).
     */
    public function confirm(Property $property): RedirectResponse
    {
        if (in_array($property->availability, Property::OPEN_AVAILABILITY, true)) {
            $property->markAvailabilityConfirmed($property->availability);
            $property->save();
        }

        $owner = $property->owner;
        $section = $owner?->role === 'broker' ? 'listings' : 'properties';

        return redirect()->away(
            FrontendUrl::workspace($owner?->role ?? 'owner', $section.'/'.$property->id).'?availability=confirmed'
        );
    }

    /**
     * Daily trigger for the reminder emails, called by a scheduled GitHub
     * Actions workflow with the shared CRON_SECRET.
     */
    public function sendReminders(
        Request $request,
        ListingAvailabilityReminders $reminders,
        BrokerCredentialReminders $credentialReminders,
    ): JsonResponse {
        $secret = (string) config('services.cron.secret');
        abort_if($secret === '' || ! hash_equals($secret, (string) $request->header('X-Cron-Secret')), 403);

        return response()->json([
            'reminders_sent' => $reminders->sendDue(),
            'credential_reminders_sent' => $credentialReminders->sendDue(),
        ]);
    }
}
