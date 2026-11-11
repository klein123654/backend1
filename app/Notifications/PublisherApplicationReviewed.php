<?php

namespace App\Notifications;

use App\Models\PublisherApplication;
use App\Support\FrontendUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PublisherApplicationReviewed extends Notification
{
    use Queueable;

    public function __construct(public PublisherApplication $application) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $isApproved = $this->application->status === 'approved';

        $message = (new MailMessage)
            ->subject($isApproved ? 'Your publisher application was approved' : 'Your publisher application was not approved')
            ->greeting('Hello '.$notifiable->name.',')
            ->line($isApproved
                ? 'You can now publish listings as a '.$this->application->requested_role.'.'
                : 'Your request to publish as a '.$this->application->requested_role.' was not approved.');

        if ($this->application->review_notes) {
            $message->line('Reviewer notes: '.$this->application->review_notes);
        }

        return $message->action(
            $isApproved ? 'Open your workspace' : 'View application',
            $isApproved ? FrontendUrl::workspace($this->application->requested_role, 'dashboard') : FrontendUrl::to('/publisher/apply'),
        );
    }
}
