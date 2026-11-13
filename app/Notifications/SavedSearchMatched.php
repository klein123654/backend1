<?php

namespace App\Notifications;

use App\Models\Property;
use App\Models\SavedSearch;
use App\Support\FrontendUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SavedSearchMatched extends Notification
{
    use Queueable;

    public function __construct(public SavedSearch $search, public Property $property) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $price = '₱'.number_format((float) $this->property->price);

        return (new MailMessage)
            ->subject('New listing for "'.$this->search->name.'": '.$this->property->title)
            ->greeting('Hello '.$notifiable->name.',')
            ->line('A new listing matches your saved search "'.$this->search->name.'":')
            ->line('**'.$this->property->title.'** · '.$this->property->property_type.' · '.$price)
            ->line(trim(($this->property->barangay ? $this->property->barangay.', ' : '').$this->property->city))
            ->action('View listing', FrontendUrl::to('/properties/'.$this->property->id))
            ->line('You can edit or remove your saved searches at any time: '.FrontendUrl::to('/buyer/saved-properties'));
    }
}
