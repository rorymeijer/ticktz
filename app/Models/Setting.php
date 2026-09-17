<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $key
 * @property string|null $value
 * @property string $type
 */
class Setting extends Model
{
    protected $fillable = ['group', 'key', 'value', 'type', 'is_public'];

    protected function casts(): array
    {
        return ['is_public' => 'boolean'];
    }

    /**
     * Decode the stored string back into its declared type.
     */
    public function typedValue(): mixed
    {
        return match ($this->type) {
            'integer' => $this->value === null ? null : (int) $this->value,
            'boolean' => filter_var($this->value, FILTER_VALIDATE_BOOL),
            'json', 'array' => $this->value === null ? null : json_decode($this->value, true),
            default => $this->value,
        };
    }

    public static function encode(mixed $value, string $type): ?string
    {
        return match ($type) {
            'boolean' => $value ? '1' : '0',
            'json', 'array' => $value === null ? null : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            default => $value === null ? null : (string) $value,
        };
    }
}
