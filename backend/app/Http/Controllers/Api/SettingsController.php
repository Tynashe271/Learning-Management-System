<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\SecurityLog;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function __construct(private Settings $settings) {}

    /** Every setting an administrator may change, grouped, with its current value and its default. */
    public function show(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('manage-settings'), 403);

        return response()->json(['groups' => $this->settings->describe()]);
    }

    /**
     * Saves some settings (`settings`: key => value) and/or puts others back to their default (`reset`: list of keys).
     * Nothing is saved unless every value is valid.
     */
    public function update(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('manage-settings'), 403);
        $data = $request->validate([
            'settings' => ['sometimes', 'array'],
            'reset' => ['sometimes', 'array'],
            'reset.*' => ['string'],
        ]);

        $before = collect($this->settings->schema())->keys()->mapWithKeys(fn ($k) => [$k => $this->settings->get($k)]);
        $changed = $this->settings->update($data['settings'] ?? [], $data['reset'] ?? [], $request->user()->id);
        if ($changed !== []) {
            $changes = collect($changed)->mapWithKeys(fn ($k) => [$k => ['from' => $before[$k], 'to' => $this->settings->get($k)]])->all();
            activity()->causedBy($request->user())->withProperties(['changes' => $changes])->log('settings changed');
            if (collect($changed)->contains(fn ($k) => str_starts_with($k, 'security.') || str_starts_with($k, 'maintenance.'))) {
                SecurityLog::event('settings.security_changed', ['keys' => $changed], 'warning');
            }
        }

        return response()->json(['changed' => $changed, 'groups' => $this->settings->describe()]);
    }
}
