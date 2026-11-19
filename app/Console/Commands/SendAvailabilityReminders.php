<?php

namespace App\Console\Commands;

use App\Services\BrokerCredentialReminders;
use App\Services\ListingAvailabilityReminders;
use Illuminate\Console\Command;

class SendAvailabilityReminders extends Command
{
    protected $signature = 'listings:send-availability-reminders';

    protected $description = 'Ask publishers to confirm older live listings, and remind brokers whose credentials expire soon';

    public function handle(ListingAvailabilityReminders $reminders, BrokerCredentialReminders $credentialReminders): int
    {
        $this->info('Sent '.$reminders->sendDue().' availability reminder(s).');
        $this->info('Sent '.$credentialReminders->sendDue().' broker credential reminder(s).');

        return self::SUCCESS;
    }
}
