<?php

declare(strict_types=1);

namespace App\Models\Concerns;

/**
 * Keeps a model's pre-save attribute values available after `save()`.
 *
 * Eloquent syncs `original` as part of finishing a save, so by the time a
 * controller calls `AuditLogger::updated()` the "before" values are already
 * gone. This trait snapshots them on the `updating` event instead, which is
 * what makes a real before/after diff possible in the audit trail.
 */
trait Auditable
{
    /**
     * Attribute values as they were before the current save.
     *
     * Declared as a real property (not an Eloquent attribute) so assigning it
     * never turns into a column write.
     *
     * @var array<string, mixed>
     */
    public array $auditOriginal = [];

    public static function bootAuditable(): void
    {
        static::updating(function (self $model): void {
            $model->auditOriginal = $model->getOriginal();
        });
    }
}
