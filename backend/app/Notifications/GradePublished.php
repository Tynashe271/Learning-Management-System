<?php

namespace App\Notifications;

use App\Models\Assignment;
use App\Models\Submission;
use App\Support\Channels;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class GradePublished extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $assignmentId, public int $submissionId, public string $title) {}

    public static function for(Submission $submission, Assignment $assignment): self
    {
        return new self($assignment->id, $submission->id, $assignment->title);
    }

    public function via(object $notifiable): array
    {
        return Channels::withMail(['database']);
    }

    public function toArray(object $notifiable): array
    {
        return ['assignment_id' => $this->assignmentId, 'submission_id' => $this->submissionId, 'title' => $this->title];
    }

    // The mark itself stays out of email; students read it after signing in.
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Grade published: '.$this->title)
            ->line('Your grade for "'.$this->title.'" has been published.')
            ->line('Sign in to the LMS to see your mark and feedback.');
    }
}
