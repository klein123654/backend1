<?php

namespace App\Notifications;

use App\Models\Property;
use App\Support\FrontendUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ListingReviewed extends Notification
{
    use Queueable;

    public function __construct(public Property $property, public bool $wasUnpublished = false) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $outcome = match (true) {
            $this->wasUnpublished => 'was unpublished by an administrator',
            $this->property->status === 'published' => 'has been approved and is now live',
            $this->property->status === 'rejected' => 'was not approved',
            default => 'was returned to draft',
        };

        $message = (new MailMessage)
            ->subject('Your listing "'.$this->property->title.'" '.$outcome)
            ->greeting('Hello '.$notifiable->name.',')
            ->line('Your listing "'.$this->property->title.'" '.$outcome.'.');

        if ($this->property->review_notes) {
            $message->line('Reviewer notes: '.$this->property->review_notes);
        }

        $section = $notifiable->role === 'broker' ? 'listings' : 'properties';

        return $message->action('View your listings', FrontendUrl::workspace($notifiable->role, $section));
    }
}
