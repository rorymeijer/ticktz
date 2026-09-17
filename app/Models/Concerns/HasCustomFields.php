<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Collection;

/**
 * Attaches custom-field storage to a model.
 *
 * Values are read and written as a `key => value` map, so callers never touch
 * the encoding or the pivot rows.
 */
trait HasCustomFields
{
    /**
     * @return MorphMany<CustomFieldValue, $this>
     */
    public function customFieldValues(): MorphMany
    {
        return $this->morphMany(CustomFieldValue::class, 'entity');
    }

    /**
     * @return array<string, mixed> field key => decoded value
     */
    public function customFields(): array
    {
        $this->loadMissing('customFieldValues.field');

        return $this->customFieldValues
            ->filter(fn (CustomFieldValue $value) => $value->field !== null)
            ->mapWithKeys(fn (CustomFieldValue $value) => [$value->field->key => $value->typed()])
            ->all();
    }

    public function customField(string $key): mixed
    {
        return $this->customFields()[$key] ?? null;
    }

    /**
     * Write a `key => value` map, deleting entries that were cleared.
     *
     * @param  array<string, mixed>  $values
     */
    public function setCustomFields(array $values): void
    {
        if ($values === []) {
            return;
        }

        /** @var Collection<string, CustomField> $fields */
        $fields = CustomField::query()
            ->whereIn('key', array_keys($values))
            ->get()
            ->keyBy('key');

        foreach ($values as $key => $value) {
            $field = $fields->get($key);

            if (! $field) {
                continue;
            }

            $encoded = CustomFieldValue::encode($value, $field);

            if ($encoded === null) {
                $this->customFieldValues()->where('custom_field_id', $field->getKey())->delete();

                continue;
            }

            $this->customFieldValues()->updateOrCreate(
                ['custom_field_id' => $field->getKey()],
                ['value' => $encoded],
            );
        }

        $this->unsetRelation('customFieldValues');
    }

    /**
     * Presentation-ready list for a detail page: label, raw value and the
     * rendered value, in the order the fields are configured.
     *
     * @return array<int, array<string, mixed>>
     */
    public function customFieldSummary(bool $includePrivate = true): array
    {
        $this->loadMissing('customFieldValues.field');

        return $this->customFieldValues
            ->filter(fn (CustomFieldValue $value) => $value->field !== null)
            ->filter(fn (CustomFieldValue $value) => $includePrivate || $value->field->is_public)
            ->sortBy(fn (CustomFieldValue $value) => $value->field->position)
            ->map(fn (CustomFieldValue $value) => [
                'key' => $value->field->key,
                'label' => $value->field->translatedLabel(),
                'type' => $value->field->type,
                'value' => $value->typed(),
                'display' => self::displayValue($value),
            ])
            ->values()
            ->all();
    }

    private static function displayValue(CustomFieldValue $value): string
    {
        $field = $value->field;
        $typed = $value->typed();

        if ($typed === null) {
            return '—';
        }

        if ($field->type === 'checkbox') {
            return $typed ? __('common.labels.yes') : __('common.labels.no');
        }

        if (in_array($field->type, CustomField::OPTION_TYPES, true)) {
            $labels = collect($field->options ?? [])->pluck('label', 'value');

            return collect((array) $typed)
                ->map(fn ($item) => $labels[$item] ?? $item)
                ->implode(', ');
        }

        if ($field->type === 'user') {
            return User::query()->whereKey($typed)->value('name') ?? (string) $typed;
        }

        return is_array($typed) ? implode(', ', $typed) : (string) $typed;
    }
}
