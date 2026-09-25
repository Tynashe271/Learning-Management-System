<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\CreateBackup;
use App\Services\BackupService;
use App\Support\SecurityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Backups, for super administrators. Making, listing, checking, downloading and deleting them is here; restoring is
 * deliberately not: it replaces everything, so it is done at the server with `php artisan lms:restore`.
 */
class BackupController extends Controller
{
    public function __construct(private BackupService $backups) {}

    public function index(Request $request): JsonResponse
    {
        $this->allow($request);

        return response()->json([
            'backups' => $this->backups->list(),
            'status' => $this->backups->status(),
            'automatic' => ['enabled' => (bool) config('lms.backups.enabled'), 'keep' => (int) config('lms.backups.keep'), 'include_files' => (bool) config('lms.backups.include_files'), 'at' => '02:30 '.config('lms.institution.timezone')],
            'location' => (string) config('lms.backups.disk'),
            'restore_command' => 'php artisan lms:restore <backup file name>',
        ]);
    }

    /** Starts a backup in the background; the list shows it when it is done. */
    public function store(Request $request): JsonResponse
    {
        $this->allow($request);
        $data = $request->validate(['include_files' => ['sometimes', 'boolean']]);
        if ($this->backups->status()['running']) {
            return response()->json(['message' => 'A backup is already running.'], 409);
        }
        CreateBackup::start($request->boolean('include_files', (bool) config('lms.backups.include_files')), $request->user()->id);

        return response()->json(['message' => 'The backup has been started.', 'status' => $this->backups->status()], 202);
    }

    /** Reads the whole backup and checks it against its checksums. */
    public function verify(Request $request, string $name): JsonResponse
    {
        $this->allow($request);
        try {
            $result = $this->backups->verify($name);
        } catch (RuntimeException $e) {
            abort(404, $e->getMessage());
        }
        activity()->causedBy($request->user())->withProperties(['backup' => $name, 'ok' => $result['ok']])->log('backup verified');

        return response()->json(['ok' => $result['ok'], 'problems' => $result['problems'], 'summary' => $result['summary'], 'checked' => $result['checked']]);
    }

    /** A backup holds every account (with password hashes) and every grade, so each download is recorded. */
    public function download(Request $request, string $name): StreamedResponse
    {
        $this->allow($request);
        if (! preg_match(BackupService::NAME_PATTERN, $name) || ! $this->backups->disk()->exists($name)) {
            abort(404);
        }
        activity()->causedBy($request->user())->withProperties(['backup' => $name])->log('backup downloaded');
        SecurityLog::event('backup.downloaded', ['backup' => $name], 'warning');

        return $this->backups->disk()->download($name, $name, ['X-Content-Type-Options' => 'nosniff']);
    }

    public function destroy(Request $request, string $name): JsonResponse
    {
        $this->allow($request);
        try {
            if (! $this->backups->disk()->exists($name)) {
                abort(404);
            }
            $this->backups->delete($name);
        } catch (RuntimeException) {
            abort(404);
        }
        activity()->causedBy($request->user())->withProperties(['backup' => $name])->log('backup deleted');

        return response()->json(['message' => 'Backup deleted.']);
    }

    private function allow(Request $request): void
    {
        abort_unless($request->user()->can('manage-system'), 403);
    }
}
