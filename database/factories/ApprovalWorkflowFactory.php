<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ApprovalStep;
use App\Models\ApprovalWorkflow;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ApprovalWorkflow>
 */
class ApprovalWorkflowFactory extends Factory
{
    protected $model = ApprovalWorkflow::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = 'Approval '.$this->faker->unique()->word();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'description' => $this->faker->sentence(),
            'is_active' => true,
        ];
    }

    /**
     * One step, one named approver: the ordinary case.
     */
    public function single(User $approver): self
    {
        return $this->afterCreating(function (ApprovalWorkflow $workflow) use ($approver): void {
            $workflow->steps()->create([
                'mode' => ApprovalStep::ANY,
                'approver_type' => 'users',
                'approver_ids' => [$approver->getKey()],
                'position' => 0,
            ]);
        });
    }

    /**
     * One step, several approvers, all of whom must agree.
     *
     * @param  array<int, User>  $approvers
     */
    public function parallel(array $approvers, string $mode = ApprovalStep::ALL): self
    {
        return $this->afterCreating(function (ApprovalWorkflow $workflow) use ($approvers, $mode): void {
            $workflow->steps()->create([
                'mode' => $mode,
                'approver_type' => 'users',
                'approver_ids' => array_map(fn (User $user) => $user->getKey(), $approvers),
                'position' => 0,
            ]);
        });
    }

    /**
     * One step per approver, in order.
     *
     * @param  array<int, User>  $approvers
     */
    public function sequential(array $approvers): self
    {
        return $this->afterCreating(function (ApprovalWorkflow $workflow) use ($approvers): void {
            foreach (array_values($approvers) as $position => $approver) {
                $workflow->steps()->create([
                    'name' => 'Step '.($position + 1),
                    'mode' => ApprovalStep::ANY,
                    'approver_type' => 'users',
                    'approver_ids' => [$approver->getKey()],
                    'position' => $position,
                ]);
            }
        });
    }
}
