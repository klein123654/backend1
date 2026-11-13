<?php

namespace App\Notifications\Account;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

class VerifyEmailAddress extends Notification
{
    use Queueable;

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = URL::temporarySignedRoute('account.verify-email', now()->addDays(3), [
            'user' => $notifiable->getKey(),
            'hash' => sha1($notifiable->email),
        ]);

        return (new MailMessage)
            ->subject('Confirm your email address')
            ->greeting('Welcome to ButuanSpaces, '.$notifiable->name.'!')
            ->line('Please confirm this is your email address so you can receive inquiry replies and password reset links.')
            ->action('Confirm email address', $url)
            ->line('This link expires in 3 days. If you did not create an account, you can ignore this email.');
    }
}
