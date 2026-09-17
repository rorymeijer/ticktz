<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Asset;
use App\Models\AssetType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Asset>
 */
class AssetFactory extends Factory
{
    protected $model = Asset::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'asset_type_id' => AssetType::factory(),
            'asset_tag' => 'AST-'.mb_strtoupper(Str::random(6)),
            'name' => $this->faker->words(2, true),
            'serial_number' => mb_strtoupper(Str::random(10)),
            'manufacturer' => $this->faker->company(),
            'model' => $this->faker->word(),
            'status' => Asset::IN_STOCK,
        ];
    }

    public function assignedTo(User $user): self
    {
        return $this->state(fn () => [
            'assigned_to' => $user->getKey(),
            'organization_id' => $user->organization_id,
            'status' => Asset::IN_USE,
        ]);
    }

    public function retired(): self
    {
        return $this->state(fn () => ['status' => Asset::RETIRED]);
    }
}
