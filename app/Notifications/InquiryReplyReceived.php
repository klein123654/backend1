<?php

namespace App\Notifications;

use App\Models\Inquiry;
use App\Models\InquiryMessage;
use App\Support\FrontendUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class InquiryReplyReceived extends Notification
{
    use Queueable;

    public function __construct(public Inquiry $inquiry, public InquiryMessage $message, public string $senderName) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $title = $this->inquiry->property?->title ?? 'a listing';
        $role = $notifiable->role ?? 'buyer';
        $name = $notifiable->name ?? $this->inquiry->name;

        $mail = (new MailMessage)
            ->subject('New reply about '.$title)
            ->greeting('Hello '.$name.',')
            ->line($this->senderName.' replied to your conversation about "'.$title.'":')
            ->line('"'.Str::limit($this->message->body, 400).'"');

        if ($this->message->safety_warning) {
            $mail->line('⚠ Be careful: '.$this->message->safety_warning.' Never send a deposit or reservation fee before you have seen the property and checked the documents.');
        }

        return $mail
            ->action('Open conversation', FrontendUrl::workspace($role, 'inquiries'))
            ->line('Reply on ButuanSpaces to keep the whole conversation in one place.');
    }
}
