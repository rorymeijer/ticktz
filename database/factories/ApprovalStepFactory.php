<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ApprovalStep;
use App\Models\ApprovalWorkflow;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApprovalStep>
 */
class ApprovalStepFactory extends Factory
{
    protected $model = ApprovalStep::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'approval_workflow_id' => ApprovalWorkflow::factory(),
            'mode' => ApprovalStep::ANY,
            'approver_type' => 'users',
            'approver_ids' => [],
            'position' => 0,
        ];
    }
}
