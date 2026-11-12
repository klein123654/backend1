<?php

namespace App\Http\Controllers;

use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Facades\Notification;
use Throwable;

abstract class Controller
{
    /**
     * Send an email notification without letting mail failures break the request.
     *
     * @param  iterable<mixed>|mixed  $notifiables
     */
    protected function notifySafely(mixed $notifiables, BaseNotification $notification): void
    {
        try {
            Notification::send($notifiables, $notification);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
