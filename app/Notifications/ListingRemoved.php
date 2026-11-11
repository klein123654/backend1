<?php

namespace App\Notifications;

use App\Support\FrontendUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ListingRemoved extends Notification
{
    use Queueable;

    public function __construct(public string $propertyTitle, public ?string $reason = null) {}

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
            ->subject('Your listing "'.$this->propertyTitle.'" was removed')
            ->greeting('Hello '.$notifiable->name.',')
            ->line('An administrator removed your listing "'.$this->propertyTitle.'" from ButuanSpaces.');

        if ($this->reason) {
            $message->line('Reason: '.$this->reason);
        }

        $section = $notifiable->role === 'broker' ? 'listings' : 'properties';

        return $message->action('View your listings', FrontendUrl::workspace($notifiable->role, $section));
    }
}
