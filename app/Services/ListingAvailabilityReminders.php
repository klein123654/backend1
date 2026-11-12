<?php

namespace App\Services;

use App\Models\Property;
use App\Notifications\ListingAvailabilityReminder;
use Illuminate\Support\Facades\Notification;
use Throwable;

class ListingAvailabilityReminders
{
    /**
     * Email publishers whose live listings have not been confirmed for
     * Property::REMIND_AFTER_DAYS days. Each listing is reminded once per
     * confirmation period. Returns the number of reminders sent.
     */
    public function sendDue(): int
    {
        $sent = 0;

        Property::query()
            ->with('owner')
            ->publiclyVisible()
            ->where('availability_confirmed_at', '<=', now()->subDays(Property::REMIND_AFTER_DAYS))
            ->whereNull('availability_reminder_sent_at')
            ->chunkById(100, function ($properties) use (&$sent): void {
                foreach ($properties as $property) {
                    if (! $property->owner) {
                        continue;
                    }

                    try {
                        Notification::send($property->owner, new ListingAvailabilityReminder($property));
                    } catch (Throwable $exception) {
                        report($exception);

                        continue;
                    }

                    $property->forceFill(['availability_reminder_sent_at' => now()])->save();
                    $sent++;
                }
            });

        return $sent;
    }
}
