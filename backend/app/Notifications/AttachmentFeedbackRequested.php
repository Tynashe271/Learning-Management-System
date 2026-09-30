<?php

namespace App\Notifications;

use App\Models\AttachmentPlacement;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Sent to a workplace supervisor's email address directly - they have no LMS account - with a link to assess their student's attachment. */
class AttachmentFeedbackRequested extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private AttachmentPlacement $placement, private string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = rtrim(config('lms.frontend_url'), '/').'/attachment-feedback/'.$this->token;
        $student = $this->placement->student;

        return (new MailMessage)->subject('Feedback requested: '.$student->name.'\'s attachment at '.$this->placement->organisation)
            ->greeting('Hello '.$this->placement->supervisor_name.',')
            ->line($student->name.' has listed you as their workplace supervisor for an industrial attachment running from '.$this->placement->starts_on->toFormattedDateString().' to '.$this->placement->ends_on->toFormattedDateString().'.')
            ->line('Please rate their performance and leave a short comment when you have a moment.')
            ->action('Give feedback', $url)
            ->line('This link works once, for 14 days. If you were not expecting this email, you can ignore it.');
    }
}
