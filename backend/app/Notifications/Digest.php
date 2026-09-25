<?php

namespace App\Notifications;

use App\Support\Channels;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/** The periodic summary email. */
class Digest extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param  array<string, mixed>  $summary  from DigestBuilder */
    public function __construct(public array $summary, public string $frequency) {}

    public function via(object $notifiable): array
    {
        return Channels::withMail([]);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject(config('app.name').': your '.$this->frequency.' summary')->greeting('Hello '.$notifiable->name.',');
        $s = $this->summary;

        if (isset($s['notifications'])) {
            $mail->line('**'.$s['notifications']['total'].' unread '.($s['notifications']['total'] === 1 ? 'update' : 'updates').'**');
            $mail->line(collect($s['notifications']['groups'])->map(fn ($g) => '- '.$g['label'].': '.$g['count'].($g['titles'] ? ' ('.collect($g['titles'])->map(fn ($t) => $this->plain($t))->implode(', ').')' : ''))->implode("\n"));
        }
        if (! empty($s['deadlines'])) {
            $mail->line('**Due in the next 7 days**');
            $mail->line(collect($s['deadlines'])->map(fn ($d) => '- '.$this->plain($d['title']).' ('.$d['course'].', '.($d['type'] === 'quiz' ? 'quiz' : 'assignment').') due '.Carbon::parse($d['due_at'])->utc()->format('D j M, H:i').' UTC')->implode("\n"));
        }
        if (! empty($s['to_grade'])) {
            $mail->line('**Waiting for a published grade**');
            $mail->line(collect($s['to_grade'])->map(fn ($g) => '- '.$this->plain($g['assignment']).' ('.$g['course'].'): '.$g['awaiting'])->implode("\n"));
        }
        if (! empty($s['open_appeals'])) {
            $mail->line('**Grade appeals waiting for a decision:** '.$s['open_appeals']);
        }

        return $mail->action('Open the LMS', config('lms.frontend_url'))
            ->line('You get this email because your summary is set to "'.$this->frequency.'". You can change how often you receive it, or turn it off, in your LMS settings.');
    }

    /** Titles are typed by other people, so strip the characters that would turn them into links or formatting. */
    private function plain(string $text): string
    {
        return mb_substr(trim(preg_replace('/[\[\]()*_`<>#|\\\\\r\n]+/', ' ', $text)), 0, 80);
    }
}
