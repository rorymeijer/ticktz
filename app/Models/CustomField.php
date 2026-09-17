<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\CustomFieldFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A reusable field definition.
 *
 * @property string $key
 * @property string $label
 * @property string $type
 * @property array<int, array{value: string, label: string}>|null $options
 * @property array<string, mixed>|null $validation
 */
class CustomField extends Model
{
    /** @use HasFactory<CustomFieldFactory> */
    use Auditable, HasFactory;

    /**
     * Field types that store more than one value.
     */
    public const MULTI_VALUE_TYPES = ['multiselect'];

    /**
     * Field types whose `options` list is meaningful.
     */
    public const OPTION_TYPES = ['select', 'multiselect'];

    public const TYPES = [
        'text', 'textarea', 'number', 'date', 'datetime',
        'select', 'multiselect', 'checkbox', 'email', 'url', 'user',
    ];

    protected $fillable = [
        'key', 'label', 'label_translations', 'type', 'options', 'placeholder',
        'help_text', 'default_value', 'is_required', 'is_public', 'validation',
        'entity', 'is_active', 'position',
    ];

    protected function casts(): array
    {
        return [
            'label_translations' => 'array',
            'options' => 'array',
            'validation' => 'array',
            'is_required' => 'boolean',
            'is_public' => 'boolean',
            'is_active' => 'boolean',
            'position' => 'integer',
        ];
    }

    /**
     * @return HasMany<CustomFieldValue, $this>
     */
    public function values(): HasMany
    {
        return $this->hasMany(CustomFieldValue::class);
    }

    /**
     * The label in the active locale, falling back to the base label.
     */
    public function translatedLabel(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return $this->label_translations[$locale] ?? $this->label;
    }

    public function isMultiValue(): bool
    {
        return in_array($this->type, self::MULTI_VALUE_TYPES, true);
    }

    /**
     * Laravel validation rules for this field.
     *
     * @return array<int, mixed>
     */
    public function validationRules(bool $required): array
    {
        $rules = [$required ? 'required' : 'nullable'];
        $extra = $this->validation ?? [];

        match ($this->type) {
            'number' => $rules[] = 'numeric',
            'date' => $rules[] = 'date',
            'datetime' => $rules[] = 'date',
            'email' => $rules[] = 'email',
            'url' => $rules[] = 'url',
            'checkbox' => $rules[] = 'boolean',
            'multiselect' => $rules[] = 'array',
            'user' => $rules[] = 'integer',
            default => $rules[] = 'string',
        };

        if (isset($extra['min'])) {
            $rules[] = $this->type === 'number' ? "min:{$extra['min']}" : "min:{$extra['min']}";
        }

        if (isset($extra['max'])) {
            $rules[] = $this->type === 'number' ? "max:{$extra['max']}" : "max:{$extra['max']}";
        }

        if (isset($extra['regex']) && is_string($extra['regex'])) {
            $rules[] = 'regex:'.$extra['regex'];
        }

        if ($this->type === 'select') {
            $rules[] = 'in:'.implode(',', $this->optionValues());
        }

        if ($this->type === 'user') {
            $rules[] = 'exists:users,id';
        }

        return $rules;
    }

    /**
     * @return array<int, string>
     */
    public function optionValues(): array
    {
        return collect($this->options ?? [])
            ->pluck('value')
            ->filter(fn ($value) => is_scalar($value))
            ->map(fn ($value) => (string) $value)
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function toFormArray(?bool $requiredOverride = null, ?string $helpOverride = null): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'label' => $this->translatedLabel(),
            'type' => $this->type,
            'options' => $this->options ?? [],
            'placeholder' => $this->placeholder,
            'help_text' => $helpOverride ?? $this->help_text,
            'default_value' => $this->default_value,
            'is_required' => $requiredOverride ?? $this->is_required,
            'validation' => $this->validation ?? [],
        ];
    }
}
