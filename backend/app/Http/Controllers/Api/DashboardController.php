<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AgendaBuilder;
use App\Services\StudentInsights;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/** The data behind the personalised dashboard: a merged schedule, grade-derived insights, and a calendar feed of both. */
class DashboardController extends Controller
{
    public function agenda(Request $request, AgendaBuilder $agenda): JsonResponse
    {
        $days = (int) $request->query('days', 14);
        $days = max(1, min($days, 90));

        return response()->json($agenda->build($request->user(), $days));
    }

    public function insights(Request $request, AgendaBuilder $agenda, StudentInsights $insights): JsonResponse
    {
        abort_unless($request->user()->can('submit-assignments'), 403);

        return response()->json([
            'missing' => $agenda->missing($request->user()),
            'recent_feedback' => $insights->recentFeedback($request->user()),
            'weak_topics' => $insights->weakTopics($request->user()),
        ]);
    }

    public function calendarToken(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->calendar_token) {
            $user->forceFill(['calendar_token' => Str::random(40)])->save();
        }

        return response()->json(['url' => url("/api/me/calendar/{$user->calendar_token}.ics")]);
    }

    public function calendarFeed(AgendaBuilder $agenda, string $token): Response
    {
        $user = User::where('calendar_token', $token)->first();
        abort_unless($user, 404);
        $data = $agenda->build($user, 60);

        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//University LMS//Agenda//EN', 'CALSCALE:GREGORIAN'];
        foreach ($data['sessions'] as $s) {
            $lines = array_merge($lines, $this->event('session-'.md5($s['title'].$s['at']), $s['at'], $s['ends_at'], "{$s['course']}: {$s['title']}", $s['location']));
        }
        foreach ($data['deadlines'] as $d) {
            $lines = array_merge($lines, $this->event($d['type'].'-'.$d['id'], $d['at'], null, "Due: {$d['title']} ({$d['course']})", null));
        }
        $lines[] = 'END:VCALENDAR';

        return response(implode("\r\n", $lines)."\r\n", 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="agenda.ics"',
        ]);
    }

    /** @return list<string> */
    private function event(string $uid, string $start, ?string $end, string $summary, ?string $location): array
    {
        $ics = fn (string $iso) => Carbon::parse($iso)->utc()->format('Ymd\THis\Z');
        $lines = [
            'BEGIN:VEVENT',
            'UID:'.$uid.'@lms',
            'DTSTAMP:'.now()->utc()->format('Ymd\THis\Z'),
            'DTSTART:'.$ics($start),
            'DTEND:'.$ics($end ?? Carbon::parse($start)->addMinutes(30)->toIso8601String()),
            'SUMMARY:'.$this->escape($summary),
        ];
        if ($location) {
            $lines[] = 'LOCATION:'.$this->escape($location);
        }
        $lines[] = 'END:VEVENT';

        return $lines;
    }

    private function escape(string $text): string
    {
        return str_replace(["\\", ",", ";", "\n"], ["\\\\", "\\,", "\\;", "\\n"], $text);
    }
}
