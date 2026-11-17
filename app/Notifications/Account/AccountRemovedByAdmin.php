<?php

namespace App\Notifications\Account;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Sent to the account's email address after an administrator deletes the account. */
class AccountRemovedByAdmin extends Notification
{
    use Queueable;

    public function __construct(public string $name, public ?string $reason = null) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject('Your ButuanSpaces account was removed')
            ->greeting('Hello '.$this->name.',')
            ->line('An administrator has deleted your ButuanSpaces account, along with any listings and saved items in it.');

        if ($this->reason) {
            $message->line('Reason: '.$this->reason);
        }

        return $message->line('If you think this was a mistake, reply to this email or use the Report an Issue page on the site.');
    }
}
