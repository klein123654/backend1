<?php

namespace App\Notifications;

use App\Models\BrokerVerification;
use App\Support\BrokerCredentials;
use App\Support\FrontendUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BrokerVerificationReviewed extends Notification
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
        $isVerified = $this->verification->status === 'verified';

        $message = (new MailMessage)
            ->subject($isVerified ? 'Your broker credentials are verified' : 'Your broker verification needs attention')
            ->greeting('Hello '.$notifiable->name.',')
            ->line($isVerified
                ? 'Your PRC license '.$this->verification->license_number.' and DHSUD registration '.$this->verification->dhsud_registration_number.' have been verified. You can now publish listings.'
                : 'We could not verify your broker credentials (PRC license '.$this->verification->license_number.', DHSUD registration '.($this->verification->dhsud_registration_number ?? 'not given').'). You cannot add or edit listings until they are verified.');

        if ($this->verification->review_notes) {
            $message->line('Reviewer notes: '.$this->verification->review_notes);
        }

        $message->line(BrokerCredentials::DHSUD_NOTICE);

        return $message->action('Open broker profile', FrontendUrl::workspace('broker', 'profile'));
    }
}
