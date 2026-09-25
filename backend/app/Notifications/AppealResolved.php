<?php

namespace App\Notifications;

use App\Models\GradeAppeal;
use App\Support\Channels;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AppealResolved extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $appealId, public string $title, public string $outcome) {}

    public static function for(GradeAppeal $appeal, string $assignmentTitle): self
    {
        return new self($appeal->id, $assignmentTitle, $appeal->status);
    }

    public function via(object $notifiable): array
    {
        return Channels::withMail(['database']);
    }

    public function toArray(object $notifiable): array
    {
        return ['appeal_id' => $this->appealId, 'title' => $this->title, 'outcome' => $this->outcome];
    }

    // The email states the outcome; the reviewer's explanation and any new mark are read after signing in.
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Your grade appeal has been decided: '.$this->title)
            ->line('Your appeal for "'.$this->title.'" was '.($this->outcome === 'upheld' ? 'upheld: your grade has been revised.' : 'reviewed and your grade stands.'))
            ->line('Sign in to the LMS to read the reviewer\'s response.');
    }
}
