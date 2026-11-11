<?php

namespace App\Notifications;

use App\Models\Property;
use App\Support\FrontendUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

class ListingAvailabilityReminder extends Notification
{
    use Queueable;

    public function __construct(public Property $property) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $hiddenOn = $this->property->availability_confirmed_at
            ?->copy()->addDays(Property::HIDE_AFTER_DAYS)->toFormattedDateString();

        $confirmUrl = URL::temporarySignedRoute(
            'listings.confirm-availability',
            now()->addDays(30),
            ['property' => $this->property->id],
        );

        $section = $notifiable->role === 'broker' ? 'listings' : 'properties';

        return (new MailMessage)
            ->subject('Is "'.$this->property->title.'" still available?')
            ->greeting('Hello '.$notifiable->name.',')
            ->line('Please confirm that your listing "'.$this->property->title.'" is still available, so buyers only see current properties.')
            ->action('Yes, it’s still available', $confirmUrl)
            ->line('If it has been rented or sold, mark it in your workspace: '.FrontendUrl::workspace($notifiable->role, $section))
            ->line($hiddenOn
                ? 'If we don’t hear from you, the listing will be hidden from the public on '.$hiddenOn.'. You can confirm it again at any time.'
                : 'If we don’t hear from you, the listing will be hidden from the public. You can confirm it again at any time.');
    }
}
