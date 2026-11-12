<?php

namespace App\Notifications;

use App\Models\Inquiry;
use App\Support\FrontendUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class InquiryReceived extends Notification
{
    use Queueable;

    public function __construct(public Inquiry $inquiry) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $propertyTitle = $this->inquiry->property?->title ?? 'your listing';

        return (new MailMessage)
            ->subject('New inquiry for '.$propertyTitle)
            ->greeting('Hello '.$notifiable->name.',')
            ->line($this->inquiry->name.' sent an inquiry about "'.$propertyTitle.'":')
            ->line('"'.Str::limit($this->inquiry->message, 300).'"')
            ->action('Reply to inquiry', FrontendUrl::workspace($notifiable->role, 'inquiries'))
            ->line('Replying quickly helps buyers trust your listings.');
    }
}
