<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AccountInvitation extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = rtrim(config('lms.frontend_url'), '/').'/reset-password?token='.$this->token.'&email='.urlencode($notifiable->getEmailForPasswordReset());

        return (new MailMessage)->subject('Your '.config('app.name').' account')
            ->greeting('Hello '.$notifiable->name.',')
            ->line('An account has been created for you. Choose a password to start using it.')
            ->action('Set your password', $url)
            ->line('This link works for 7 days. If you were not expecting this email, you can ignore it.');
    }
}
