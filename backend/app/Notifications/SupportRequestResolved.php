<?php

namespace App\Notifications;

use App\Models\SupportRequest;
use App\Support\Channels;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SupportRequestResolved extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $requestId, public string $subject, public ?string $resolutionNote) {}

    public static function for(SupportRequest $request): self
    {
        return new self($request->id, $request->subject, $request->resolution_note);
    }

    public function via(object $notifiable): array
    {
        return Channels::withMail(['database']);
    }

    public function toArray(object $notifiable): array
    {
        return ['support_request_id' => $this->requestId, 'subject' => $this->subject, 'resolution_note' => $this->resolutionNote];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject('Your support request has been resolved: '.$this->subject)
            ->line('Your support request "'.$this->subject.'" has been marked resolved.');
        if ($this->resolutionNote) {
            $mail->line($this->resolutionNote);
        }

        return $mail->line('Sign in to the LMS if you still need help.');
    }
}
