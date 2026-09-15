<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AssetType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AssetType>
 */
class AssetTypeFactory extends Factory
{
    protected $model = AssetType::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = 'Type '.$this->faker->unique()->word();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'tag_prefix' => mb_strtoupper(Str::random(3)),
            'color' => '#64748b',
            'is_active' => true,
            'position' => 100,
        ];
    }

    public function named(string $name, string $prefix): self
    {
        return $this->state(fn () => [
            'name' => $name,
            'slug' => Str::slug($name),
            'tag_prefix' => $prefix,
        ]);
    }
}
