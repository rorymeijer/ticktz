<?php

declare(strict_types=1);

namespace App\Models\Concerns;

/**
 * A `name` that follows the reader's language.
 *
 * Statuses, priorities and labels are configured by an administrator but read
 * by requesters, so their names cannot live in the language files — they are
 * data. The base `name` is the fallback for any locale without an override.
 */
trait HasTranslatableName
{
    public function translatedName(?string $locale = null): string
    {
        return $this->name_translations[$locale ?? app()->getLocale()] ?? $this->name;
    }
}
