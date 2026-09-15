<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'locale',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Two-letter monogram used by the avatar component.
     */
    public function initials(): string
    {
        $parts = preg_split('/\s+/', trim($this->name)) ?: [];
        $letters = array_map(
            static fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)),
            array_slice(array_filter($parts), 0, 2)
        );

        return $letters === [] ? '?' : implode('', $letters);
    }

    /**
     * Deterministic avatar tint so the same person always looks the same
     * without storing an extra column or uploading images.
     */
    public function avatarColor(): string
    {
        $palette = ['#4f46e5', '#0891b2', '#059669', '#d97706', '#dc2626', '#7c3aed', '#db2777', '#0284c7'];

        return $palette[crc32((string) $this->getKey()) % count($palette)];
    }

    /**
     * The shape shared with the React front-end. Kept explicit so we never
     * leak a column (password hash, LDAP DN, ...) by accident.
     *
     * @return array<string, mixed>
     */
    public function toInertiaArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'locale' => $this->locale ?? config('app.locale'),
            'initials' => $this->initials(),
            'avatar_color' => $this->avatarColor(),
            'is_admin' => false,
            'is_agent' => false,
            'is_requester' => true,
            'roles' => [],
            'permissions' => [],
            'team_ids' => [],
            'organization_id' => null,
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
        ];
    }
}
