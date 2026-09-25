<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\PasswordPolicy;
use App\Support\SecurityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Throwable;

class AccountController extends Controller
{
    /** The signed-in person with their roles and what those roles allow, so a frontend can show the right screens. */
    public function me(Request $request): JsonResponse
    {
        return response()->json($this->profile($request->user()));
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);
        $request->user()->update($data);

        return response()->json($this->profile($request->user()));
    }

    /** @return array<string, mixed> */
    private function profile(User $user): array
    {
        return $user->load('roles')->toArray() + ['permissions' => $user->getAllPermissions()->pluck('name')->sort()->values()->all()];
    }

    public function changePassword(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => [...PasswordPolicy::rules(), 'confirmed', 'different:current_password'],
        ]);
        if (! Hash::check($data['current_password'], $user->password)) {
            SecurityLog::event('password.change_failed', [], 'warning');
            throw ValidationException::withMessages(['current_password' => 'The current password is incorrect.']);
        }
        $user->update(['password' => $data['password']]);

        // Sign out everywhere else, keeping the token used for this request.
        $current = $user->currentAccessToken();
        $user->tokens()->when($current instanceof PersonalAccessToken, fn ($q) => $q->where('id', '!=', $current->id))->delete();
        activity()->causedBy($user)->performedOn($user)->log('password changed');
        SecurityLog::event('password.changed');

        return response()->json(['message' => 'Password changed.']);
    }

    /** Always answers the same way, so it cannot be used to discover which emails have accounts. */
    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        try {
            $user = User::whereRaw('lower(email) = ?', [mb_strtolower($data['email'])])->where('is_active', true)->first();
            SecurityLog::event('password.reset_requested', ['email' => SecurityLog::emailFingerprint($data['email']), 'account_found' => (bool) $user]);
            if ($user) {
                Password::sendResetLink(['email' => $user->email]);
            }
        } catch (Throwable $e) {
            report($e);
        }

        return response()->json(['message' => 'If that account exists, a reset link has been sent.']);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => [...PasswordPolicy::rules(), 'confirmed'],
        ]);
        $account = User::whereRaw('lower(email) = ?', [mb_strtolower($data['email'])])->first();
        $data['email'] = $account?->email ?? $data['email'];
        $apply = function (User $user, string $password) {
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            $user->tokens()->delete();
            activity()->causedBy($user)->performedOn($user)->log('password reset');
            SecurityLog::event('password.reset', ['user_id' => $user->id]);
        };
        // A normal reset link lasts an hour; the welcome link sent to bulk-created accounts lasts a week.
        $status = Password::broker('users')->reset($data, $apply);
        if ($status === Password::INVALID_TOKEN) {
            $status = Password::broker('invitations')->reset($data, $apply);
        }
        if ($status !== Password::PASSWORD_RESET) {
            SecurityLog::event('password.reset_failed', ['email' => SecurityLog::emailFingerprint($data['email']), 'reason' => $status], 'warning');
            throw ValidationException::withMessages(['email' => [__($status)]]);
        }

        return response()->json(['message' => 'Password reset. You can now sign in.']);
    }
}
