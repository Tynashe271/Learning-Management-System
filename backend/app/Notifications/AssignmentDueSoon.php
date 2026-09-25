<?php

namespace App\Notifications;

use App\Models\Assignment;
use App\Support\Channels;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AssignmentDueSoon extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $assignmentId, public string $title, public string $dueAt) {}

    public static function fromAssignment(Assignment $assignment): self
    {
        return new self($assignment->id, $assignment->title, $assignment->due_at->toIso8601String());
    }

    public function via(object $notifiable): array
    {
        return Channels::withMail(['database']);
    }

    public function toArray(object $notifiable): array
    {
        return ['assignment_id' => $this->assignmentId, 'title' => $this->title, 'due_at' => $this->dueAt];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Assignment due soon: '.$this->title)
            ->line($this->title.' is due at '.$this->dueAt.'.');
    }
}
