<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLogEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Writes the audit trail.
 *
 * Every mutating action in Ticktz goes through here. The logger deliberately
 * records *what changed* rather than a full snapshot: storing entire models
 * would balloon the table and leak attributes (password hashes, LDAP DNs) that
 * have no business in an audit record.
 */
class AuditLogger
{
    /**
     * Attributes that are never written to the audit trail.
     *
     * @var array<int, string>
     */
    private const REDACTED = [
        'password', 'remember_token', 'bind_password', 'token', 'secret',
        'api_key', 'ldap_dn', 'ldap_guid',
    ];

    private string $actorType = 'user';

    private ?string $actorLabel = null;

    private ?User $actor = null;

    /**
     * Attribute the next entries to something other than the signed-in user:
     * `AuditLogger::as('system')`, `as('automation', 'Rule #12')`, ...
     */
    public function as(string $actorType, ?string $label = null): self
    {
        $clone = clone $this;
        $clone->actorType = $actorType;
        $clone->actorLabel = $label;
        $clone->actor = null;

        return $clone;
    }

    public function actingAs(User $user): self
    {
        $clone = clone $this;
        $clone->actorType = 'user';
        $clone->actor = $user;
        $clone->actorLabel = $user->name;

        return $clone;
    }

    /**
     * Record an arbitrary event against a model.
     *
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     * @param  array<string, mixed>  $context
     */
    public function log(
        Model $subject,
        string $event,
        ?string $description = null,
        ?array $old = null,
        ?array $new = null,
        array $context = [],
    ): AuditLogEntry {
        $user = $this->actor ?? ($this->actorType === 'user' ? Auth::user() : null);

        return AuditLogEntry::query()->create([
            'actor_type' => $this->actorType,
            'user_id' => $user?->getKey(),
            'actor_label' => $this->actorLabel ?? $user?->name,
            'auditable_type' => $subject->getMorphClass(),
            'auditable_id' => $subject->getKey(),
            'event' => $event,
            'description' => $description,
            'old_values' => $this->redact($old),
            'new_values' => $this->redact($new),
            'ip_address' => $this->requestValue(fn () => Request::ip()),
            'user_agent' => $this->truncate($this->requestValue(fn () => Request::userAgent()), 255),
            'context' => $context === [] ? null : $context,
        ]);
    }

    /**
     * Record an event that has no Eloquent subject — application settings,
     * a directory test, a maintenance command.
     *
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     * @param  array<string, mixed>  $context
     */
    public function logGlobal(
        string $subjectType,
        string $event,
        ?string $description = null,
        ?array $old = null,
        ?array $new = null,
        array $context = [],
    ): AuditLogEntry {
        $user = $this->actor ?? ($this->actorType === 'user' ? Auth::user() : null);

        return AuditLogEntry::query()->create([
            'actor_type' => $this->actorType,
            'user_id' => $user?->getKey(),
            'actor_label' => $this->actorLabel ?? $user?->name,
            'auditable_type' => $subjectType,
            'auditable_id' => 0,
            'event' => $event,
            'description' => $description,
            'old_values' => $this->redact($old),
            'new_values' => $this->redact($new),
            'ip_address' => $this->requestValue(fn () => Request::ip()),
            'user_agent' => $this->truncate($this->requestValue(fn () => Request::userAgent()), 255),
            'context' => $context === [] ? null : $context,
        ]);
    }

    public function created(Model $subject, ?string $description = null): AuditLogEntry
    {
        return $this->log(
            $subject,
            'created',
            $description,
            null,
            $this->attributesOf($subject),
        );
    }

    /**
     * Records only the attributes that actually changed. Returns null when the
     * save was a no-op, so "updated" entries always carry a real diff.
     */
    public function updated(Model $subject, ?string $description = null): ?AuditLogEntry
    {
        $changes = $subject->getChanges();
        unset($changes['updated_at']);

        if ($changes === []) {
            return null;
        }

        // `Auditable` snapshots the pre-save values; without the trait we can
        // still report what changed, just not what it changed from.
        $before = property_exists($subject, 'auditOriginal') ? $subject->auditOriginal : [];
        $original = array_intersect_key($before, $changes);

        return $this->log($subject, 'updated', $description, $original, $changes);
    }

    public function deleted(Model $subject, ?string $description = null): AuditLogEntry
    {
        return $this->log($subject, 'deleted', $description, $this->attributesOf($subject));
    }

    /**
     * @return array<string, mixed>
     */
    private function attributesOf(Model $subject): array
    {
        $attributes = $subject->getAttributes();
        unset($attributes['created_at'], $attributes['updated_at']);

        return $attributes;
    }

    /**
     * @param  array<string, mixed>|null  $values
     * @return array<string, mixed>|null
     */
    private function redact(?array $values): ?array
    {
        if ($values === null || $values === []) {
            return null;
        }

        foreach (self::REDACTED as $key) {
            if (array_key_exists($key, $values)) {
                $values[$key] = '••••';
            }
        }

        return $values;
    }

    /**
     * Console commands and queued jobs have no request context.
     */
    private function requestValue(callable $resolver): ?string
    {
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return null;
        }

        try {
            $value = $resolver();

            return is_string($value) ? $value : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function truncate(?string $value, int $length): ?string
    {
        return $value === null ? null : mb_substr($value, 0, $length);
    }
}
