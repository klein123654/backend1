<?php

namespace App\Services;

use App\Models\BrokerVerification;
use App\Notifications\BrokerCredentialsExpiring;
use Illuminate\Support\Facades\Notification;
use Throwable;

class BrokerCredentialReminders
{
    /**
     * Email verified brokers whose PRC licence or DHSUD registration expires
     * within BrokerVerification::RENEWAL_REMINDER_DAYS days (or already has).
     * Each broker is reminded once until they submit renewed credentials.
     * Returns the number of reminders sent.
     */
    public function sendDue(): int
    {
        $sent = 0;
        $cutoff = today()->addDays(BrokerVerification::RENEWAL_REMINDER_DAYS);

        BrokerVerification::query()
            ->with('broker')
            ->where('status', 'verified')
            ->whereNull('expiry_reminder_sent_at')
            ->where(fn ($query) => $query
                ->whereDate('prc_valid_until', '<=', $cutoff)
                ->orWhereDate('dhsud_valid_until', '<=', $cutoff))
            ->chunkById(100, function ($verifications) use (&$sent): void {
                foreach ($verifications as $verification) {
                    if (! $verification->broker || $verification->broker->account_status !== 'active') {
                        continue;
                    }

                    try {
                        Notification::send($verification->broker, new BrokerCredentialsExpiring($verification));
                    } catch (Throwable $exception) {
                        report($exception);

                        continue;
                    }

                    $verification->forceFill(['expiry_reminder_sent_at' => now()])->save();
                    $sent++;
                }
            });

        return $sent;
    }
}
