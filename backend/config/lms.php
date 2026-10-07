<?php

return [
    // Extensions accepted for course files and submissions (checked against the file's content type as well).
    'upload_mimes' => env('LMS_UPLOAD_MIMES', 'pdf,doc,docx,ppt,pptx,xls,xlsx,odt,ods,odp,txt,md,csv,zip,png,jpg,jpeg,gif,mp3,mp4'),

    // Where the single-page frontend lives; used to build password-reset and sign-in links.
    'frontend_url' => env('LMS_FRONTEND_URL', env('APP_URL', 'http://localhost')),

    // The version of this software, shown on the system screen.
    'version' => env('LMS_VERSION', '1.1.0'),

    // Details of the institution running this system. All of these can be changed in the app (Administration > Settings).
    'institution' => [
        'name' => env('LMS_INSTITUTION_NAME', 'University LMS'),
        'short_name' => env('LMS_INSTITUTION_SHORT_NAME'),
        'support_email' => env('LMS_SUPPORT_EMAIL'),
        'support_phone' => env('LMS_SUPPORT_PHONE'),
        'website' => env('LMS_INSTITUTION_WEBSITE'),
        'timezone' => env('LMS_TIMEZONE', 'UTC'),
        'locale' => env('LMS_LOCALE', 'en'),
    ],

    'notifications' => [
        // Grade, appeal and reminder emails. Password-reset and welcome emails are always sent.
        'email' => (bool) env('LMS_EMAIL_NOTIFICATIONS', true),
        'due_reminders' => (bool) env('LMS_DUE_REMINDERS', true),
        'due_reminder_days' => (int) env('LMS_DUE_REMINDER_DAYS', 1),
    ],

    // How long records that are only useful for a while are kept.
    'retention' => [
        'security_event_days' => (int) env('LMS_SECURITY_EVENT_DAYS', 180),
        'notification_days' => (int) env('LMS_NOTIFICATION_DAYS', 365),
    ],

    'backups' => [
        'enabled' => (bool) env('LMS_BACKUPS', true),
        'keep' => (int) env('LMS_BACKUPS_KEEP', 14),
        'include_files' => (bool) env('LMS_BACKUP_FILES', true),
        // Where backups are written: a disk from config/filesystems.php.
        'disk' => env('LMS_BACKUP_DISK', 'backups'),
    ],

    // While on, only super administrators can use the system.
    'maintenance' => [
        'enabled' => (bool) env('LMS_MAINTENANCE', false),
        'message' => env('LMS_MAINTENANCE_MESSAGE', 'The system is being maintained and will be back shortly.'),
    ],

    'security' => [
        // After this many failed sign-ins for one email address, sign-in for it is refused for the lockout period.
        'lockout_attempts' => (int) env('LMS_LOCKOUT_ATTEMPTS', 5),
        'lockout_minutes' => (int) env('LMS_LOCKOUT_MINUTES', 15),
        // Send the HSTS header even when the request did not arrive over HTTPS (TLS is terminated in front of this app).
        'force_hsts' => (bool) env('LMS_FORCE_HSTS', false),
        'password' => [
            'min_length' => (int) env('LMS_PASSWORD_MIN_LENGTH', 12),
            'mixed_case' => (bool) env('LMS_PASSWORD_MIXED_CASE', false),
            'number' => (bool) env('LMS_PASSWORD_NUMBER', false),
            'symbol' => (bool) env('LMS_PASSWORD_SYMBOL', false),
        ],
    ],

    'limits' => [
        // Largest single course file or submission, in megabytes. The server's own limit (infra/php/lms.ini) is the ceiling.
        'upload_mb' => (int) env('LMS_UPLOAD_MB', 25),
        // Set only to override the size detected from PHP's own limits (mostly for tests).
        'server_max_mb' => env('LMS_SERVER_MAX_MB'),
        // Largest ordinary JSON request body. File uploads have their own limits (see infra/php/lms.ini).
        'json_body_kb' => (int) env('LMS_JSON_BODY_KB', 512),
    ],

    // Links the frontend can show in its footer and on the sign-in page. The institution must supply the actual documents.
    'legal' => [
        'privacy_url' => env('LMS_PRIVACY_URL'),
        'terms_url' => env('LMS_TERMS_URL'),
    ],

    // A student may appeal a published grade for this many days after it was published.
    'appeals' => ['window_days' => (int) env('LMS_APPEAL_WINDOW_DAYS', 14)],

    // Self check-in counts as late once this many minutes have passed since the session's start time.
    'checkin' => ['late_after_minutes' => (int) env('LMS_CHECKIN_LATE_AFTER_MINUTES', 10)],

    // What new accounts get for the summary email: off, daily, or weekly. People change their own setting.
    'digest' => ['default' => env('LMS_DIGEST_DEFAULT', 'off')],

    // Uploads are streamed to a ClamAV daemon (see the `scan` profile in compose.yaml) before they are stored.
    'virus_scan' => [
        'enabled' => (bool) env('LMS_VIRUS_SCAN', false),
        'host' => env('CLAMAV_HOST', 'clamav'),
        'port' => (int) env('CLAMAV_PORT', 3310),
        'timeout' => (int) env('CLAMAV_TIMEOUT', 30),
        // false: reject uploads while the scanner is unreachable (safer). true: accept them and log a warning.
        'fail_open' => (bool) env('LMS_VIRUS_SCAN_FAIL_OPEN', false),
    ],

    // The AI learning/teaching assistant (roadmap items 15-16). Off unless both are set: a real key costs money per request.
    'ai' => [
        'enabled' => (bool) env('LMS_AI_ENABLED', false),
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('LMS_AI_MODEL', 'claude-sonnet-5'),
        'base_url' => env('LMS_AI_BASE_URL', 'https://api.anthropic.com/v1/messages'),
        // Course material is truncated to roughly this many characters total before being sent, to bound cost per request.
        'max_source_chars' => (int) env('LMS_AI_MAX_SOURCE_CHARS', 12000),
    ],

    // In-app video for class sessions and in-class tests, through Daily.co, instead of linking out to an external meeting
    // tool. Off unless both are set. Get an API key at https://dashboard.daily.co/developers (free tier available).
    'video' => [
        'enabled' => (bool) env('LMS_VIDEO_ENABLED', false),
        'api_key' => env('DAILY_API_KEY'),
        'base_url' => env('LMS_VIDEO_BASE_URL', 'https://api.daily.co/v1'),
        // How long after a session/test ends its room stays reachable, in minutes, before Daily expires it.
        'room_grace_minutes' => (int) env('LMS_VIDEO_ROOM_GRACE_MINUTES', 30),
    ],

    // Single sign-on through any OpenID Connect provider (Microsoft Entra ID, Google Workspace, Okta, Keycloak, ...).
    'sso' => [
        'enabled' => (bool) env('LMS_SSO_ENABLED', false),
        'label' => env('LMS_SSO_LABEL', 'University sign-in'),
        'issuer' => env('LMS_SSO_ISSUER'),
        'client_id' => env('LMS_SSO_CLIENT_ID'),
        'client_secret' => env('LMS_SSO_CLIENT_SECRET'),
        'redirect_uri' => env('LMS_SSO_REDIRECT_URI'),
        'scopes' => env('LMS_SSO_SCOPES', 'openid email profile'),
        // Create a student account on first sign-in when none exists. Staff roles are always assigned by an administrator.
        'auto_provision' => (bool) env('LMS_SSO_AUTO_PROVISION', false),
        'allowed_domains' => array_values(array_filter(array_map('trim', explode(',', (string) env('LMS_SSO_ALLOWED_DOMAINS', ''))))),
        'require_verified_email' => (bool) env('LMS_SSO_REQUIRE_VERIFIED_EMAIL', true),
        // false: only super-admins keep password sign-in (a break-glass account); everyone else must use SSO.
        'password_login' => (bool) env('LMS_SSO_PASSWORD_LOGIN', true),
    ],
];
