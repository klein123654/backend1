<?php

namespace App\Notifications\Account;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/** Sent to the NEW address; the change only happens when the link is opened. */
class ConfirmEmailChange extends Notification
{
    use Queueable;

    public function __construct(public User $user, public string $newEmail) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = URL::temporarySignedRoute('account.confirm-email-change', now()->addHours(24), [
            'user' => $this->user->getKey(),
            'email' => $this->newEmail,
        ]);

        return (new MailMessage)
            ->subject('Confirm your new email address')
            ->greeting('Hello '.$this->user->name.',')
            ->line('You asked to change the email address on your ButuanSpaces account to '.$this->newEmail.'.')
            ->action('Confirm new email', $url)
            ->line('This link expires in 24 hours. Your old address keeps working until you confirm.');
    }
}
