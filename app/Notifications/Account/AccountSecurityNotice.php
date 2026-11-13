<?php

namespace App\Notifications\Account;

use App\Support\FrontendUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** "Your password / email was changed" heads-up so account takeovers get noticed. */
class AccountSecurityNotice extends Notification
{
    use Queueable;

    public function __construct(public string $subject, public string $body) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->subject)
            ->greeting('Hello'.(isset($notifiable->name) ? ' '.$notifiable->name : '').',')
            ->line($this->body)
            ->line('If this was you, no action is needed.')
            ->line('If it wasn\'t, reset your password right away: '.FrontendUrl::to('/forgot-password'));
    }
}
