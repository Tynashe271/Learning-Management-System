<?php

namespace App\Notifications;

use App\Models\InterventionPlan;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A private, supportive note to a student a lecturer has flagged as possibly needing help. Never includes the lecturer's internal reasoning. */
class SupportCheckIn extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private InterventionPlan $plan, private string $courseName, private string $message) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        return ['offering_id' => $this->plan->course_offering_id, 'title' => $this->courseName, 'message' => $this->message];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('A note from your '.$this->courseName.' teacher')
            ->greeting('Hello '.$notifiable->name.',')
            ->line($this->message)
            ->line('Reply to this course\'s announcements or reach out to your teacher if you would like to talk.');
    }
}
