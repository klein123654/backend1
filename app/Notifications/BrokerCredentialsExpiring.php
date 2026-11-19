<?php

namespace App\Notifications;

use App\Models\BrokerVerification;
use App\Support\BrokerCredentials;
use App\Support\FrontendUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BrokerCredentialsExpiring extends Notification
{
    use Queueable;

    public function __construct(public BrokerVerification $verification) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $verification = $this->verification;
        $expired = $verification->credential_status === 'expired';

        $message = (new MailMessage)
            ->subject($expired ? 'Your broker credentials have expired' : 'Your broker credentials expire soon')
            ->greeting('Hello '.$notifiable->name.',')
            ->line($expired
                ? 'One of your broker credentials on ButuanSpaces has expired. Until you submit the renewed details, you cannot add or edit listings, and your listings show that your registration is being renewed.'
                : 'One of your broker credentials on ButuanSpaces expires within '.BrokerVerification::RENEWAL_REMINDER_DAYS.' days.')
            ->line('PRC license '.$verification->license_number.': valid until '.$verification->prc_valid_until?->toFormattedDateString().'.')
            ->line('DHSUD registration '.$verification->dhsud_registration_number.': valid until '.$verification->dhsud_valid_until?->toFormattedDateString().'.')
            ->line('After you renew, enter the new validity dates in your broker profile. An administrator will check them.')
            ->line(BrokerCredentials::DHSUD_NOTICE);

        return $message->action('Update broker credentials', FrontendUrl::workspace('broker', 'profile'));
    }
}
