<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\OidcClient;
use App\Services\ScannerUnavailable;
use App\Services\VirusScanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * How the system is connected to the rest of the institution: single sign-on, email, file storage, the virus scanner, the
 * student-records system and online meetings. Each is shown with its current state, and the ones that can be tried have a test.
 * The connection details themselves (addresses, passwords, secrets) live in the server's configuration, not here, so they are
 * never shown or stored in the app; each entry says which setting to change.
 */
class IntegrationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->allow($request);

        return response()->json(['integrations' => [
            $this->sso(), $this->email(), $this->storage(), $this->scanner(), $this->studentRecords(), $this->meetings(), $this->payments(), $this->api(),
        ]]);
    }

    /** Tries one connection for real and says what happened. `key` is sso, email, storage or scanner. */
    public function test(Request $request, string $key): JsonResponse
    {
        $this->allow($request);
        $result = match ($key) {
            'sso' => app(OidcClient::class)->selfTest(),
            'email' => $this->testMail($request),
            'storage' => $this->testStorage(),
            'scanner' => $this->testScanner(),
            default => abort(404),
        };
        activity()->causedBy($request->user())->withProperties(['integration' => $key, 'ok' => $result['ok']])->log('integration tested');

        return response()->json($result);
    }

    // ---- states -------------------------------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function sso(): array
    {
        $sso = config('lms.sso');
        $complete = filled($sso['issuer']) && filled($sso['client_id']) && filled($sso['client_secret']);

        return [
            'key' => 'sso', 'label' => 'Single sign-on (OpenID Connect)', 'can_test' => true,
            'status' => ! $sso['enabled'] ? 'off' : ($complete ? 'ok' : 'error'),
            'summary' => ! $sso['enabled'] ? 'Off. People sign in with a password.' : ($complete ? 'On, through '.$sso['issuer'] : 'Switched on but incomplete: the issuer, client id and client secret are all needed.'),
            'details' => ['Issuer' => $sso['issuer'], 'Client id' => $sso['client_id'], 'Client secret' => filled($sso['client_secret']) ? 'set' : 'not set', 'Redirect address' => app(OidcClient::class)->redirectUri(),
                'Creates accounts on first sign-in' => $sso['auto_provision'] ? 'yes (as students)' : 'no', 'Allowed email domains' => $sso['allowed_domains'] ? implode(', ', $sso['allowed_domains']) : 'any', 'Password sign-in still allowed' => $sso['password_login'] ? 'yes' : 'only super administrators'],
            'change' => 'LMS_SSO_* in backend/.env (see the README, "Single sign-on")',
        ];
    }

    /** @return array<string, mixed> */
    private function email(): array
    {
        $mailer = (string) config('mail.default');
        $host = (string) config('mail.mailers.smtp.host');
        $testing = in_array($mailer, ['log', 'array'], true) || in_array($host, ['mailpit', 'localhost', '127.0.0.1'], true);

        return [
            'key' => 'email', 'label' => 'Email', 'can_test' => true,
            'status' => $testing ? 'warn' : 'ok',
            'summary' => $mailer === 'log' ? 'Emails are only written to the log; nobody receives them.' : ($testing ? 'Emails go to a local test inbox, not to real people.' : "Sent through {$host}."),
            'details' => ['Method' => $mailer, 'Server' => $mailer === 'smtp' ? $host.':'.config('mail.mailers.smtp.port') : '—', 'Sent from' => config('mail.from.address').' ('.config('mail.from.name').')', 'Email notifications' => config('lms.notifications.email') ? 'on' : 'off (Settings > Notifications)'],
            'change' => 'MAIL_* in backend/.env',
        ];
    }

    /** @return array<string, mixed> */
    private function storage(): array
    {
        $disk = config('filesystems.disks.s3');

        return [
            'key' => 'storage', 'label' => 'File storage', 'can_test' => true, 'status' => 'ok',
            'summary' => 'Uploaded files are kept in private storage ('.($disk['bucket'] ?? '?').').',
            'details' => ['Kind' => 'S3-compatible', 'Address' => $disk['endpoint'] ? parse_url((string) $disk['endpoint'], PHP_URL_HOST) : 'AWS', 'Bucket' => $disk['bucket'] ?? '—', 'Region' => $disk['region'] ?? '—'],
            'change' => 'AWS_* in backend/.env',
        ];
    }

    /** @return array<string, mixed> */
    private function scanner(): array
    {
        $on = (bool) config('lms.virus_scan.enabled');

        return [
            'key' => 'scanner', 'label' => 'Virus scanning (ClamAV)', 'can_test' => true,
            'status' => $on ? 'ok' : 'warn',
            'summary' => $on ? 'Every upload is scanned before it is stored.' : 'Off. Uploads are checked for file type but not scanned for viruses.',
            'details' => ['Scanner' => config('lms.virus_scan.host').':'.config('lms.virus_scan.port'), 'If the scanner is down' => config('lms.virus_scan.fail_open') ? 'accept uploads (logged)' : 'refuse uploads'],
            'change' => 'LMS_VIRUS_SCAN and CLAMAV_* in backend/.env; start the scanner with: podman compose --profile scan up -d',
        ];
    }

    /** @return array<string, mixed> */
    private function studentRecords(): array
    {
        $last = fn (string $what) => DB::table('activity_log')->where('description', $what)->latest('id')->value('created_at');

        return [
            'key' => 'student_records', 'label' => 'Student records system', 'can_test' => false, 'status' => 'manual',
            'summary' => 'Connected by file: export accounts and enrolments from the registration system as CSV and import them here. There is no live connection.',
            'details' => ['Accounts imported last' => $last('users imported') ?? 'never', 'Enrolments imported last' => $last('enrolments imported') ?? 'never', 'Where' => 'Administration > Import accounts, and each course\'s People tab'],
            'change' => 'Nothing to configure. A system that can call this API may do the same automatically (see "API access").',
        ];
    }

    /** @return array<string, mixed> */
    private function meetings(): array
    {
        $count = ['Zoom' => 0, 'Microsoft Teams' => 0, 'Google Meet' => 0, 'Other' => 0];
        foreach (DB::table('class_sessions')->whereNotNull('join_url')->pluck('join_url') as $url) {
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            $count[match (true) {
                str_contains($host, 'zoom.') => 'Zoom', str_contains($host, 'teams.') => 'Microsoft Teams', str_contains($host, 'meet.google') => 'Google Meet', default => 'Other',
            }]++;
        }

        return [
            'key' => 'meetings', 'label' => 'Online meetings', 'can_test' => false, 'status' => 'manual',
            'summary' => 'Teachers paste a meeting link into each class; students see a Join button. Meetings are not created automatically.',
            'details' => array_filter($count) ?: ['Classes with a meeting link' => 0],
            'change' => 'Nothing to configure.',
        ];
    }

    /** @return array<string, mixed> */
    private function payments(): array
    {
        return [
            'key' => 'payments', 'label' => 'Payments and fees', 'can_test' => false, 'status' => 'off',
            'summary' => 'Not part of this system. Tuition and fees belong in the finance system; nothing here charges or stores payment details.',
            'details' => [], 'change' => 'Not applicable.',
        ];
    }

    /** @return array<string, mixed> */
    private function api(): array
    {
        return [
            'key' => 'api', 'label' => 'API access for other systems', 'can_test' => false, 'status' => 'info',
            'summary' => 'Any system can use this API. Create an account for it with the Registrar role, sign in as that account, and send the token with each request.',
            'details' => ['Address' => rtrim((string) config('app.url'), '/').'/api', 'Useful endpoints' => 'POST /users/import, POST /offerings/{id}/enrolments/import, GET /reports/*', 'Limits' => '120 requests a minute per account; heavy endpoints 10 a minute'],
            'change' => 'See the README for every endpoint.',
        ];
    }

    // ---- tests --------------------------------------------------------------------------------------------------------

    /** @return array{ok: bool, message: string} */
    private function testMail(Request $request): array
    {
        $to = $request->user()->email;
        try {
            Mail::raw('This is a test email from '.config('lms.institution.name').'. If you can read it, sending email works.', fn ($m) => $m->to($to)->subject(config('lms.institution.name').': test email'));
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'The email could not be sent: '.Str::limit($e->getMessage(), 200)];
        }

        return ['ok' => true, 'message' => "A test email was sent to {$to}. Check that it arrives."];
    }

    /** @return array{ok: bool, message: string} */
    private function testStorage(): array
    {
        $path = '.healthcheck/'.Str::random(12);
        try {
            $disk = Storage::disk('s3');
            $disk->put($path, 'ok');
            $back = $disk->get($path);
            $disk->delete($path);
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'File storage failed: '.Str::limit($e->getMessage(), 200)];
        }

        return $back === 'ok' ? ['ok' => true, 'message' => 'A test file was written, read back and deleted.'] : ['ok' => false, 'message' => 'A file was written but read back differently.'];
    }

    /** @return array{ok: bool, message: string} */
    private function testScanner(): array
    {
        if (! config('lms.virus_scan.enabled')) {
            return ['ok' => false, 'message' => 'Virus scanning is switched off (LMS_VIRUS_SCAN).'];
        }
        $tmp = tempnam(sys_get_temp_dir(), 'scan');
        file_put_contents($tmp, 'harmless test text');
        try {
            $found = app(VirusScanner::class)->scan($tmp);
        } catch (ScannerUnavailable $e) {
            return ['ok' => false, 'message' => 'The scanner could not be reached: '.$e->getMessage()];
        } finally {
            @unlink($tmp);
        }

        return $found === null ? ['ok' => true, 'message' => 'The scanner answered and found the test file clean.'] : ['ok' => false, 'message' => "The scanner flagged a harmless test file ({$found}). Something is wrong with it."];
    }

    private function allow(Request $request): void
    {
        abort_unless($request->user()->can('manage-settings'), 403);
    }
}
