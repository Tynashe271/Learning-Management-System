<?php

namespace App\Notifications;

use App\Models\GradeAppeal;
use App\Support\Channels;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells the teaching staff a student has appealed a grade. The reason itself stays in the LMS. */
class AppealFiled extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $appealId, public int $assignmentId, public string $title) {}

    public static function for(GradeAppeal $appeal, string $assignmentTitle, int $assignmentId): self
    {
        return new self($appeal->id, $assignmentId, $assignmentTitle);
    }

    public function via(object $notifiable): array
    {
        return Channels::withMail(['database']);
    }

    public function toArray(object $notifiable): array
    {
        return ['appeal_id' => $this->appealId, 'assignment_id' => $this->assignmentId, 'title' => $this->title];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Grade appeal filed: '.$this->title)
            ->line('A student has appealed their grade for "'.$this->title.'".')
            ->line('Sign in to the LMS to read the appeal and respond.');
    }
}
