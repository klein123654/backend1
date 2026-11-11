<?php

namespace App\Console\Commands;

use App\Services\ListingAvailabilityReminders;
use Illuminate\Console\Command;

class SendAvailabilityReminders extends Command
{
    protected $signature = 'listings:send-availability-reminders';

    protected $description = 'Ask publishers to confirm that their older live listings are still available';

    public function handle(ListingAvailabilityReminders $reminders): int
    {
        $this->info('Sent '.$reminders->sendDue().' availability reminder(s).');

        return self::SUCCESS;
    }
}
