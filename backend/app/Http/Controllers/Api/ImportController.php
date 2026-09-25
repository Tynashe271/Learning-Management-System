<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\AccountInvitation;
use App\Support\CsvReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ImportController extends Controller
{
    private const MAX_ROWS = 1000;

    /**
     * Creates accounts from a CSV with the columns name, email, and role. Each new user gets an unusable random
     * password and a welcome email with a link (valid for 7 days) to choose their own. Rows that fail are reported and
     * skipped; the rest are created. Pass dry_run=1 to check the file without creating anything.
     */
    public function users(Request $request): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor->can('manage-users'), 403);
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:2048'],
            'dry_run' => ['sometimes', 'boolean'],
            'send_invitations' => ['sometimes', 'boolean'],
        ]);
        $dryRun = $request->boolean('dry_run');
        $invite = $request->boolean('send_invitations', true);
        $rows = CsvReader::rows($request->file('file')->getRealPath(), self::MAX_ROWS);
        if ($rows === []) {
            return response()->json(['message' => 'The file has no data rows.', 'errors' => ['file' => ['The file has no data rows.']]], 422);
        }
        $headers = array_keys($rows[0]);
        if (array_diff(['name', 'email', 'role'], $headers) !== []) {
            return response()->json(['message' => 'The first row must name the columns: name, email, role.', 'errors' => ['file' => ['The first row must name the columns: name, email, role.']]], 422);
        }

        $existing = User::whereIn(DB::raw('lower(email)'), collect($rows)->pluck('email')->map(fn ($e) => mb_strtolower($e))->all())->pluck('email')->map(fn ($e) => mb_strtolower($e))->all();
        $seen = [];
        $valid = [];
        $errors = [];
        foreach ($rows as $i => $row) {
            $line = $i + 2; // header is line 1
            $email = mb_strtolower($row['email']);
            $validator = Validator::make($row, [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255'],
                'role' => ['required', Rule::in(User::ROLES)],
            ]);
            $problems = $validator->errors()->all();
            if (! $problems && $row['role'] === 'super-admin' && ! $actor->hasRole('super-admin')) {
                $problems[] = 'Only a super-admin can create a super-admin.';
            }
            if (! $problems && in_array($email, $existing, true)) {
                $problems[] = 'An account with this email already exists.';
            }
            if (! $problems && isset($seen[$email])) {
                $problems[] = 'This email appears more than once in the file (first on line '.$seen[$email].').';
            }
            if ($problems) {
                $errors[] = ['line' => $line, 'email' => $row['email'], 'errors' => $problems];

                continue;
            }
            $seen[$email] = $line;
            $valid[] = $row;
        }

        if (! $dryRun) {
            DB::transaction(function () use ($valid, &$created) {
                $created = [];
                foreach ($valid as $row) {
                    $user = User::create(['name' => $row['name'], 'email' => $row['email'], 'password' => Str::random(48)]);
                    $user->assignRole($row['role']);
                    $created[] = $user;
                }
            });
            if ($invite) {
                foreach ($created as $user) {
                    $user->notify(new AccountInvitation(Password::broker('invitations')->createToken($user)));
                }
            }
            activity()->causedBy($actor)->withProperties(['created' => count($created), 'rejected' => count($errors), 'invited' => $invite])->log('users imported');
        }

        return response()->json([
            'dry_run' => $dryRun,
            'created' => $dryRun ? 0 : count($created),
            'would_create' => count($valid),
            'invited' => ! $dryRun && $invite,
            'errors' => $errors,
        ]);
    }
}
