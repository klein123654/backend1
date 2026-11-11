<?php

namespace App\Http\Controllers;

use App\Models\Property;
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
        if ($property->availability === 'available') {
            $property->markAvailabilityConfirmed();
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
    public function sendReminders(Request $request, ListingAvailabilityReminders $reminders): JsonResponse
    {
        $secret = (string) config('services.cron.secret');
        abort_if($secret === '' || ! hash_equals($secret, (string) $request->header('X-Cron-Secret')), 403);

        return response()->json(['reminders_sent' => $reminders->sendDue()]);
    }
}
