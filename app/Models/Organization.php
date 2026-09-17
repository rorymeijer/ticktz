<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A customer organisation. Requesters are matched to one by e-mail domain when
 * they are auto-provisioned from an inbound message.
 *
 * @property string $name
 * @property array<int, string>|null $email_domains
 * @property bool $shared_ticket_visibility
 */
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use Auditable, HasFactory, SoftDeletes;

    protected $fillable = [
        'name', 'slug', 'description', 'email_domains', 'contact_email',
        'phone', 'is_active', 'shared_ticket_visibility',
    ];

    protected function casts(): array
    {
        return [
            'email_domains' => 'array',
            'is_active' => 'boolean',
            'shared_ticket_visibility' => 'boolean',
        ];
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Find the organisation that claims an e-mail address' domain.
     */
    public static function matchingEmail(string $email): ?self
    {
        $domain = mb_strtolower(trim(substr(strrchr($email, '@') ?: '', 1)));

        if ($domain === '') {
            return null;
        }

        return static::query()
            ->where('is_active', true)
            ->whereNotNull('email_domains')
            ->get()
            ->first(fn (self $organization) => in_array(
                $domain,
                array_map('mb_strtolower', $organization->email_domains ?? []),
                true
            ));
    }
}
