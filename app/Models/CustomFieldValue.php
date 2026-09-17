<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\RichText\RichTextSanitizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One stored answer. Scalars are kept as text and multi-value fields as a JSON
 * array; `typed()` turns either back into something PHP can work with.
 */
class CustomFieldValue extends Model
{
    protected $fillable = ['custom_field_id', 'entity_type', 'entity_id', 'value'];

    /**
     * @return BelongsTo<CustomField, $this>
     */
    public function field(): BelongsTo
    {
        return $this->belongsTo(CustomField::class, 'custom_field_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function entity(): MorphTo
    {
        return $this->morphTo();
    }

    public function typed(?CustomField $field = null): mixed
    {
        $field ??= $this->field;

        if ($this->value === null) {
            return null;
        }

        return match ($field?->type) {
            'multiselect' => json_decode($this->value, true) ?: [],
            'checkbox' => filter_var($this->value, FILTER_VALIDATE_BOOL),
            'number' => str_contains($this->value, '.') ? (float) $this->value : (int) $this->value,
            'user' => (int) $this->value,
            default => $this->value,
        };
    }

    public static function encode(mixed $value, CustomField $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($field->type) {
            'multiselect' => json_encode(array_values((array) $value), JSON_UNESCAPED_UNICODE),
            'checkbox' => $value ? '1' : '0',
            // The one field type somebody writes sentences into. Sanitised
            // here rather than through HasRichText, because every type shares
            // this column and only this one is prose — a date has no business
            // going near an HTML allowlist. A value that cleans down to
            // nothing is stored as nothing, which is what deletes the row.
            'textarea' => is_scalar($value)
                ? (app(RichTextSanitizer::class)->clean((string) $value) ?: null)
                : null,
            default => is_scalar($value) ? (string) $value : json_encode($value),
        };
    }
}
