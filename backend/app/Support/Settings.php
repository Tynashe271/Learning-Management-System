<?php

namespace App\Support;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Institution-wide settings an administrator can change in the app instead of editing server files.
 *
 * Each setting has a home in the ordinary configuration (`config`). The saved values are laid over that configuration when a
 * request starts, so the rest of the code keeps reading `config(...)` and needs to know nothing about this class. Without a saved
 * value the setting keeps whatever the configuration (environment) says, and "reset" simply deletes the saved value.
 */
class Settings
{
    public const CACHE_KEY = 'settings:overrides';

    /** File types an administrator may allow. Anything that can run in a browser (html, svg, js) is deliberately absent. */
    public const UPLOAD_TYPES = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'odt', 'ods', 'odp', 'rtf', 'txt', 'md', 'csv', 'zip', 'png', 'jpg', 'jpeg', 'gif', 'mp3', 'mp4'];

    public const LOCALES = ['en', 'en-GB', 'en-US', 'fr', 'es', 'pt', 'de', 'it', 'sw', 'ar'];

    /** @var array<string, mixed> defaults as they were before any saved value was applied */
    private array $defaults = [];

    private bool $applied = false;

    public function __construct(private Config $config) {}

    /**
     * @return array<string, array{group: string, label: string, help: string, type: string, config: list<string>, rules: list<mixed>, options?: list<string>, min?: int, max?: int, to_config?: callable, from_config?: callable}>
     */
    public function schema(): array
    {
        $php = (int) $this->config->get('lms.limits.server_max_mb') ?: $this->phpUploadLimitMb();

        return [
            'institution.name' => ['group' => 'Institution', 'label' => 'Institution name', 'help' => 'Shown on the sign-in page, in the header and in emails.', 'type' => 'string', 'config' => ['lms.institution.name', 'app.name'], 'rules' => ['required', 'string', 'max:120']],
            'institution.short_name' => ['group' => 'Institution', 'label' => 'Short name', 'help' => 'For example the initials used in the browser tab.', 'type' => 'string', 'config' => ['lms.institution.short_name'], 'rules' => ['nullable', 'string', 'max:20']],
            'institution.support_email' => ['group' => 'Institution', 'label' => 'Support email', 'help' => 'Where people are told to write when they cannot sign in.', 'type' => 'email', 'config' => ['lms.institution.support_email'], 'rules' => ['nullable', 'email', 'max:255']],
            'institution.support_phone' => ['group' => 'Institution', 'label' => 'Support phone', 'help' => '', 'type' => 'string', 'config' => ['lms.institution.support_phone'], 'rules' => ['nullable', 'string', 'max:40']],
            'institution.website' => ['group' => 'Institution', 'label' => 'Website', 'help' => '', 'type' => 'url', 'config' => ['lms.institution.website'], 'rules' => ['nullable', 'url:http,https', 'max:255']],
            'institution.timezone' => ['group' => 'Institution', 'label' => 'Time zone', 'help' => 'Dates are shown in this zone, and daily reminders go out at 08:00 in it.', 'type' => 'string', 'config' => ['lms.institution.timezone'], 'rules' => ['required', 'timezone:all']],
            'institution.locale' => ['group' => 'Institution', 'label' => 'Date and number format', 'help' => 'Chooses how dates and numbers are written. Screens are in English.', 'type' => 'enum', 'options' => self::LOCALES, 'config' => ['lms.institution.locale'], 'rules' => ['required', 'in:'.implode(',', self::LOCALES)]],

            'academic.appeal_window_days' => ['group' => 'Academic rules', 'label' => 'Grade appeal window (days)', 'help' => 'How long after a grade is published a student may appeal it.', 'type' => 'int', 'min' => 1, 'max' => 90, 'config' => ['lms.appeals.window_days'], 'rules' => ['required', 'integer', 'between:1,90']],
            'academic.checkin_late_minutes' => ['group' => 'Academic rules', 'label' => 'Late after (minutes)', 'help' => 'A student who checks in this long after a class starts is marked late.', 'type' => 'int', 'min' => 0, 'max' => 120, 'config' => ['lms.checkin.late_after_minutes'], 'rules' => ['required', 'integer', 'between:0,120']],

            'limits.upload_mb' => ['group' => 'Files and storage', 'label' => 'Largest course file or submission (MB)', 'help' => "The server accepts up to {$php} MB per file; a larger number is refused.", 'type' => 'int', 'min' => 1, 'max' => $php, 'config' => ['lms.limits.upload_mb'], 'rules' => ['required', 'integer', "between:1,{$php}"]],
            'limits.upload_types' => ['group' => 'Files and storage', 'label' => 'Allowed file types', 'help' => 'Content is checked against the real file type, not just the name.', 'type' => 'list', 'options' => self::UPLOAD_TYPES, 'config' => ['lms.upload_mimes'], 'rules' => ['required', 'array', 'min:1'], 'to_config' => fn (array $v) => implode(',', $v), 'from_config' => fn ($v) => array_values(array_filter(array_map('trim', explode(',', (string) $v))))],
            'security.password_min_length' => ['group' => 'Security', 'label' => 'Minimum password length', 'help' => 'Applies to new and changed passwords.', 'type' => 'int', 'min' => 8, 'max' => 64, 'config' => ['lms.security.password.min_length'], 'rules' => ['required', 'integer', 'between:8,64']],
            'security.password_mixed_case' => ['group' => 'Security', 'label' => 'Require upper and lower case letters', 'help' => '', 'type' => 'bool', 'config' => ['lms.security.password.mixed_case'], 'rules' => ['required', 'boolean']],
            'security.password_number' => ['group' => 'Security', 'label' => 'Require a number', 'help' => '', 'type' => 'bool', 'config' => ['lms.security.password.number'], 'rules' => ['required', 'boolean']],
            'security.password_symbol' => ['group' => 'Security', 'label' => 'Require a symbol', 'help' => '', 'type' => 'bool', 'config' => ['lms.security.password.symbol'], 'rules' => ['required', 'boolean']],
            'security.lockout_attempts' => ['group' => 'Security', 'label' => 'Lock an account after this many wrong passwords', 'help' => '', 'type' => 'int', 'min' => 3, 'max' => 10, 'config' => ['lms.security.lockout_attempts'], 'rules' => ['required', 'integer', 'between:3,10']],
            'security.lockout_minutes' => ['group' => 'Security', 'label' => 'Lockout length (minutes)', 'help' => '', 'type' => 'int', 'min' => 1, 'max' => 1440, 'config' => ['lms.security.lockout_minutes'], 'rules' => ['required', 'integer', 'between:1,1440']],
            'security.session_minutes' => ['group' => 'Security', 'label' => 'Sign-in lasts (minutes)', 'help' => 'After this long people must sign in again. Changing it affects sign-ins from now on.', 'type' => 'int', 'min' => 15, 'max' => 10080, 'config' => ['sanctum.expiration'], 'rules' => ['required', 'integer', 'between:15,10080']],

            'notifications.email_enabled' => ['group' => 'Notifications', 'label' => 'Send email notifications', 'help' => 'Grade, appeal and reminder emails. Password-reset and welcome emails are always sent.', 'type' => 'bool', 'config' => ['lms.notifications.email'], 'rules' => ['required', 'boolean']],
            'notifications.due_reminders' => ['group' => 'Notifications', 'label' => 'Remind students before deadlines', 'help' => '', 'type' => 'bool', 'config' => ['lms.notifications.due_reminders'], 'rules' => ['required', 'boolean']],
            'notifications.due_reminder_days' => ['group' => 'Notifications', 'label' => 'Remind this many days ahead', 'help' => '', 'type' => 'int', 'min' => 1, 'max' => 7, 'config' => ['lms.notifications.due_reminder_days'], 'rules' => ['required', 'integer', 'between:1,7']],
            'notifications.digest_default' => ['group' => 'Notifications', 'label' => 'Summary email for new accounts', 'help' => 'People can change their own setting.', 'type' => 'enum', 'options' => ['off', 'daily', 'weekly'], 'config' => ['lms.digest.default'], 'rules' => ['required', 'in:off,daily,weekly']],

            'privacy.privacy_url' => ['group' => 'Privacy and retention', 'label' => 'Privacy policy address', 'help' => 'Linked from the sign-in page and footer.', 'type' => 'url', 'config' => ['lms.legal.privacy_url'], 'rules' => ['nullable', 'url:http,https', 'max:255']],
            'privacy.terms_url' => ['group' => 'Privacy and retention', 'label' => 'Terms of use address', 'help' => '', 'type' => 'url', 'config' => ['lms.legal.terms_url'], 'rules' => ['nullable', 'url:http,https', 'max:255']],
            'privacy.security_event_days' => ['group' => 'Privacy and retention', 'label' => 'Keep security events for (days)', 'help' => 'Sign-in and access records older than this are deleted.', 'type' => 'int', 'min' => 30, 'max' => 730, 'config' => ['lms.retention.security_event_days'], 'rules' => ['required', 'integer', 'between:30,730']],
            'privacy.notification_days' => ['group' => 'Privacy and retention', 'label' => 'Keep read notifications for (days)', 'help' => '', 'type' => 'int', 'min' => 30, 'max' => 730, 'config' => ['lms.retention.notification_days'], 'rules' => ['required', 'integer', 'between:30,730']],

            'backups.enabled' => ['group' => 'Backups', 'label' => 'Back up automatically every night', 'help' => 'Runs at 02:30 in the institution time zone.', 'type' => 'bool', 'config' => ['lms.backups.enabled'], 'rules' => ['required', 'boolean']],
            'backups.keep' => ['group' => 'Backups', 'label' => 'Keep the most recent backups', 'help' => 'Older ones are deleted.', 'type' => 'int', 'min' => 1, 'max' => 90, 'config' => ['lms.backups.keep'], 'rules' => ['required', 'integer', 'between:1,90']],
            'backups.include_files' => ['group' => 'Backups', 'label' => 'Include uploaded files', 'help' => 'Submissions and course files. Makes backups larger.', 'type' => 'bool', 'config' => ['lms.backups.include_files'], 'rules' => ['required', 'boolean']],

            'maintenance.enabled' => ['group' => 'Maintenance', 'label' => 'Maintenance mode', 'help' => 'Only super administrators can use the system while this is on. Everyone else sees the message below.', 'type' => 'bool', 'config' => ['lms.maintenance.enabled'], 'rules' => ['required', 'boolean']],
            'maintenance.message' => ['group' => 'Maintenance', 'label' => 'Message shown during maintenance', 'help' => '', 'type' => 'string', 'config' => ['lms.maintenance.message'], 'rules' => ['nullable', 'string', 'max:500']],
        ];
    }

    /** Lays the saved values over the configuration. Safe to call before the database exists. */
    public function apply(): void
    {
        foreach ($this->schema() as $key => $definition) {
            $this->defaults[$key] ??= $this->read($definition);
        }
        $saved = $this->saved();
        foreach ($this->schema() as $key => $definition) {
            $value = array_key_exists($key, $saved) ? $saved[$key] : $this->defaults[$key];
            $this->write($definition, $value);
        }
        $this->applied = true;
    }

    /** @return array<string, mixed> */
    public function saved(): array
    {
        // Booting must never fail because of this: with the cache down the saved values are read straight from the database,
        // and with the database down (or its table not yet created) everything simply keeps its configured default.
        try {
            return Cache::rememberForever(self::CACHE_KEY, $this->load(...));
        } catch (Throwable) {
            try {
                return $this->load();
            } catch (Throwable) {
                return [];
            }
        }
    }

    /** @return array<string, mixed> */
    private function load(): array
    {
        return DB::table('settings')->pluck('value', 'key')->map(fn ($v) => json_decode($v, true))->all();
    }

    /** The current value of one setting (the saved one, or the default). */
    public function get(string $key): mixed
    {
        $definition = $this->schema()[$key] ?? throw new \InvalidArgumentException("Unknown setting {$key}");

        return $this->read($definition);
    }

    /**
     * Everything an administrator can change, grouped for a settings screen.
     *
     * @return list<array{group: string, settings: list<array<string, mixed>>}>
     */
    public function describe(): array
    {
        $saved = $this->saved();
        $groups = [];
        foreach ($this->schema() as $key => $definition) {
            $groups[$definition['group']][] = [
                'key' => $key,
                'label' => $definition['label'],
                'help' => $definition['help'],
                'type' => $definition['type'],
                'options' => $definition['options'] ?? null,
                'min' => $definition['min'] ?? null,
                'max' => $definition['max'] ?? null,
                'value' => $this->read($definition),
                'default' => $this->defaults[$key] ?? $this->read($definition),
                'overridden' => array_key_exists($key, $saved),
            ];
        }

        return collect($groups)->map(fn ($settings, $group) => ['group' => $group, 'settings' => $settings])->values()->all();
    }

    /**
     * Saves changes, and removes the saved value of every key in $reset so that setting goes back to its default. Nothing is
     * saved unless every value is valid.
     *
     * @param  array<string, mixed>  $changes
     * @param  list<string>  $reset
     * @return list<string> the keys that changed
     */
    public function update(array $changes, array $reset, ?int $userId): array
    {
        $schema = $this->schema();
        foreach ([...array_keys($changes), ...$reset] as $key) {
            if (! isset($schema[$key])) {
                throw ValidationException::withMessages(['settings' => "Unknown setting {$key}."]);
            }
        }
        $rules = [];
        foreach ($changes as $key => $value) {
            $rules[$key] = $schema[$key]['rules'];
            if (($schema[$key]['type'] ?? '') === 'list') {
                $rules[$key.'.*'] = ['string', 'in:'.implode(',', $schema[$key]['options'])];
            }
        }
        // Dotted keys ("institution.name") mean nesting to the validator, so validate under safe names.
        $flat = [];
        $flatRules = [];
        foreach ($changes as $key => $value) {
            $safe = str_replace('.', '__', $key);
            $flat[$safe] = $value;
            $flatRules[$safe] = $rules[$key];
            if (isset($rules[$key.'.*'])) {
                $flatRules[$safe.'.*'] = $rules[$key.'.*'];
            }
        }
        $validator = Validator::make($flat, $flatRules);
        if ($validator->fails()) {
            throw ValidationException::withMessages(collect($validator->errors()->messages())->mapWithKeys(fn ($m, $k) => [str_replace('__', '.', $k) => $m])->all());
        }

        $changed = [];
        DB::transaction(function () use ($changes, $reset, $userId, &$changed) {
            foreach ($changes as $key => $value) {
                DB::table('settings')->updateOrInsert(['key' => $key], ['value' => json_encode($value), 'updated_by' => $userId, 'updated_at' => now(), 'created_at' => now()]);
                $changed[] = $key;
            }
            foreach ($reset as $key) {
                if (DB::table('settings')->where('key', $key)->delete()) {
                    $changed[] = $key;
                }
            }
        });
        Cache::forget(self::CACHE_KEY);
        $this->apply();

        return $changed;
    }

    // ---- helpers ----------------------------------------------------------------------------------------------------

    /** @param  array<string, mixed>  $definition */
    private function read(array $definition): mixed
    {
        $value = $this->config->get($definition['config'][0]);

        return isset($definition['from_config']) ? $definition['from_config']($value) : $value;
    }

    /** @param  array<string, mixed>  $definition */
    private function write(array $definition, mixed $value): void
    {
        foreach ($definition['config'] as $path) {
            $this->config->set($path, isset($definition['to_config']) ? $definition['to_config']($value) : $value);
        }
    }

    /** The biggest single file the server's PHP accepts, in whole megabytes (a setting above it could never be honoured). */
    private function phpUploadLimitMb(): int
    {
        $bytes = fn (string $v) => (int) match (strtolower(substr(trim($v), -1))) {
            'g' => (int) $v * 1024 ** 3,
            'm' => (int) $v * 1024 ** 2,
            'k' => (int) $v * 1024,
            default => (int) $v,
        };
        $limit = min($bytes((string) ini_get('upload_max_filesize')) ?: PHP_INT_MAX, $bytes((string) ini_get('post_max_size')) ?: PHP_INT_MAX);

        return max(1, min(100, (int) floor($limit / 1024 ** 2)));
    }
}
