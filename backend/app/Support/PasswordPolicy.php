<?php

namespace App\Support;

use Illuminate\Validation\Rules\Password;

/** The institution's password rules (Administration > Settings > Security), for any place a new password is accepted. */
class PasswordPolicy
{
    /** @return list<mixed> validation rules for a new password */
    public static function rules(): array
    {
        $policy = config('lms.security.password');
        $rule = Password::min(max(8, (int) $policy['min_length']));
        if ($policy['mixed_case']) {
            $rule->mixedCase();
        }
        if ($policy['number']) {
            $rule->numbers();
        }
        if ($policy['symbol']) {
            $rule->symbols();
        }

        return ['required', 'string', 'max:128', $rule];
    }

    /** What the rules are, for showing beside a password field. @return array{min_length: int, mixed_case: bool, number: bool, symbol: bool} */
    public static function describe(): array
    {
        $policy = config('lms.security.password');

        return ['min_length' => (int) $policy['min_length'], 'mixed_case' => (bool) $policy['mixed_case'], 'number' => (bool) $policy['number'], 'symbol' => (bool) $policy['symbol']];
    }
}
