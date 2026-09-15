<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Laravel\Sanctum\PersonalAccessToken as SanctumToken;

/**
 * A personal access token, with the two things Sanctum leaves out.
 *
 * `description` is what the token is for in words — a list of eight tokens all
 * called "token" is a list from which nobody dares revoke anything.
 *
 * `rate_limit` is a ceiling for this token alone. The instance-wide limit
 * cannot tell one integration from another, so without this a script polling
 * every second starves every other caller and the only fix is to lower the
 * limit for everyone.
 *
 * @property string $name
 * @property string|null $description
 * @property int|null $rate_limit
 * @property array<int, string>|null $abilities
 */
class ApiToken extends SanctumToken
{
    protected $table = 'personal_access_tokens';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'description',
        'token',
        'abilities',
        'rate_limit',
        'expires_at',
    ];

    /**
     * The hash is the credential. It is never needed by anything that renders
     * a token, and a token list that accidentally serialises it hands out
     * nothing usable but is still the wrong thing to have written to a log.
     *
     * @var list<string>
     */
    protected $hidden = ['token'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'abilities' => 'json',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * @param  Builder<ApiToken>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where(function (Builder $scoped): void {
            $scoped->whereNull('expires_at')->orWhere('expires_at', '>', now());
        });
    }

    /**
     * @return array<int, string>
     */
    public function scopeNames(): array
    {
        /** @var array<int, string> $abilities */
        $abilities = $this->abilities ?? [];

        return $abilities;
    }

    /**
     * @return array<string, mixed>
     */
    public function toDisplayArray(): array
    {
        return [
            'id' => $this->getKey(),
            'name' => $this->name,
            'description' => $this->description,
            'scopes' => $this->scopeNames(),
            'rate_limit' => $this->rate_limit,
            'last_used_at' => $this->last_used_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'expired' => $this->hasExpired(),
            'created_at' => $this->created_at?->toIso8601String(),
            'owner' => $this->relationLoaded('tokenable') && $this->tokenable instanceof User
                ? ['id' => $this->tokenable->getKey(), 'name' => $this->tokenable->name]
                : null,
        ];
    }
}
