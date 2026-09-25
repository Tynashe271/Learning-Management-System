<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Support\SecurityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Sign-ins, lockouts, password changes and refused requests: who tried what, when, from where. */
class SecurityEventController extends Controller
{
    /** Events that mean someone may be attacking or struggling. */
    private const CONCERNING = ['login.failed', 'login.locked', 'login.blocked_while_locked', 'password.reset_failed', 'password.change_failed', 'sso.failed'];

    /**
     * `?event=` one kind, `?level=`, `?user_id=`, `?email=` (an address to look for: it is compared by its short hash, since
     * addresses are never stored), `?from=`/`?to=` dates, `?q=` part of an event name.
     */
    public function index(Request $request): JsonResponse
    {
        $this->allow($request);
        $data = $request->validate([
            'event' => ['sometimes', 'string', 'max:60'], 'q' => ['sometimes', 'string', 'max:60'], 'level' => ['sometimes', Rule::in(['info', 'warning', 'error'])],
            'user_id' => ['sometimes', 'integer'], 'email' => ['sometimes', 'email'], 'from' => ['sometimes', 'date'], 'to' => ['sometimes', 'date'],
        ]);
        $query = SecurityEvent::query()->latest('id');
        foreach (['event', 'level', 'user_id'] as $field) {
            if (isset($data[$field])) {
                $query->where($field, $data[$field]);
            }
        }
        if (isset($data['q'])) {
            $query->where('event', 'like', '%'.str_replace(['%', '_'], ['', ''], $data['q']).'%');
        }
        if (isset($data['email'])) {
            $query->where('email_hash', SecurityLog::emailFingerprint($data['email']));
        }
        if (isset($data['from'])) {
            $query->where('created_at', '>=', $data['from']);
        }
        if (isset($data['to'])) {
            $query->where('created_at', '<=', date('Y-m-d 23:59:59', strtotime($data['to'])));
        }

        $page = $query->paginate(50);
        $names = User::whereIn('id', $page->getCollection()->pluck('user_id')->filter()->unique())->pluck('name', 'id');
        $page->getCollection()->transform(fn ($e) => $e->toArray() + ['user_name' => $names[$e->user_id] ?? null]);

        return response()->json($page);
    }

    /** Counts for a "what is happening" panel: the last 24 hours and the last 7 days, and the busiest failing sources. */
    public function summary(Request $request): JsonResponse
    {
        $this->allow($request);
        $window = fn (string $since) => SecurityEvent::where('created_at', '>=', $since);
        $count = fn (string $since, array $events) => $window($since)->whereIn('event', $events)->count();
        $day = now()->subDay()->toDateTimeString();
        $week = now()->subDays(7)->toDateTimeString();

        return response()->json([
            'last_24_hours' => [
                'sign_ins' => $count($day, ['login.success']), 'failed_sign_ins' => $count($day, ['login.failed']), 'lockouts' => $count($day, ['login.locked']),
                'password_changes' => $count($day, ['password.changed', 'password.reset']), 'access_denied' => $count($day, ['access.denied']),
            ],
            'last_7_days' => [
                'sign_ins' => $count($week, ['login.success']), 'failed_sign_ins' => $count($week, ['login.failed']), 'lockouts' => $count($week, ['login.locked']),
                'password_changes' => $count($week, ['password.changed', 'password.reset']), 'access_denied' => $count($week, ['access.denied']),
            ],
            'busiest_failing_addresses' => $window($day)->whereIn('event', self::CONCERNING)->whereNotNull('ip')->select('ip', DB::raw('count(*) as total'))->groupBy('ip')->orderByDesc('total')->limit(5)->get(),
            'events' => SecurityEvent::select('event', DB::raw('count(*) as total'))->groupBy('event')->orderBy('event')->pluck('total', 'event'),
        ]);
    }

    private function allow(Request $request): void
    {
        abort_unless($request->user()->can('manage-users'), 403);
    }
}
