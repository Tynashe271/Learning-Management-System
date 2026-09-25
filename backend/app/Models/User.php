<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'sso_subject', 'calendar_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    public const ROLES = ['super-admin', 'university-admin', 'registrar', 'department-admin', 'lecturer', 'teaching-assistant', 'student'];

    public const DIGEST_FREQUENCIES = ['off', 'daily', 'weekly'];

    protected $attributes = ['is_active' => true];

    protected static function booted(): void
    {
        // New accounts (created by an admin, an import, or SSO) start on the institution's default digest setting.
        static::creating(function (User $user) {
            if (! array_key_exists('digest_frequency', $user->getAttributes())) {
                $default = config('lms.digest.default');
                $user->digest_frequency = in_array($default, self::DIGEST_FREQUENCIES, true) ? $default : 'off';
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'digest_sent_at' => 'immutable_datetime',
        ];
    }
}
