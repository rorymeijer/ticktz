<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Priority;
use App\Models\Queue;
use App\Models\TicketStatus;
use App\Models\Workflow;
use App\Models\WorkflowTransition;
use Illuminate\Database\Seeder;

/**
 * The out-of-the-box service desk process: five statuses, four priorities, one
 * workflow and four queues.
 *
 * Idempotent. Everything it creates is editable afterwards — this is a
 * starting point, not a constraint.
 */
class TicketWorkflowSeeder extends Seeder
{
    /**
     * @var array<int, array{slug: string, name: string, nl: string, category: string, color: string, pauses_sla?: bool, is_public?: bool, position: int}>
     */
    private const STATUSES = [
        ['slug' => 'new', 'name' => 'New', 'nl' => 'Nieuw', 'category' => 'new', 'color' => '#2563eb', 'position' => 10],
        ['slug' => 'open', 'name' => 'In progress', 'nl' => 'In behandeling', 'category' => 'open', 'color' => '#7c3aed', 'position' => 20],
        ['slug' => 'waiting-for-requester', 'name' => 'Waiting for requester', 'nl' => 'Wacht op melder', 'category' => 'pending', 'color' => '#d97706', 'pauses_sla' => true, 'position' => 30],
        ['slug' => 'waiting-for-third-party', 'name' => 'Waiting for a third party', 'nl' => 'Wacht op derde partij', 'category' => 'pending', 'color' => '#ca8a04', 'pauses_sla' => true, 'position' => 40],
        ['slug' => 'resolved', 'name' => 'Resolved', 'nl' => 'Opgelost', 'category' => 'resolved', 'color' => '#059669', 'position' => 50],
        ['slug' => 'closed', 'name' => 'Closed', 'nl' => 'Gesloten', 'category' => 'closed', 'color' => '#475569', 'position' => 60],
    ];

    /**
     * @var array<int, array{slug: string, name: string, nl: string, level: int, color: string, is_default?: bool, position: int}>
     */
    private const PRIORITIES = [
        ['slug' => 'urgent', 'name' => 'Urgent', 'nl' => 'Urgent', 'level' => 1, 'color' => '#dc2626', 'position' => 10],
        ['slug' => 'high', 'name' => 'High', 'nl' => 'Hoog', 'level' => 2, 'color' => '#ea580c', 'position' => 20],
        ['slug' => 'normal', 'name' => 'Normal', 'nl' => 'Normaal', 'level' => 3, 'color' => '#2563eb', 'is_default' => true, 'position' => 30],
        ['slug' => 'low', 'name' => 'Low', 'nl' => 'Laag', 'level' => 4, 'color' => '#64748b', 'position' => 40],
    ];

    /**
     * from => [to, ...]. NULL as the key means "from any status".
     *
     * @var array<string, array<int, string>>
     */
    private const TRANSITIONS = [
        'new' => ['open', 'waiting-for-requester', 'waiting-for-third-party', 'resolved'],
        'open' => ['waiting-for-requester', 'waiting-for-third-party', 'resolved'],
        'waiting-for-requester' => ['open', 'resolved'],
        'waiting-for-third-party' => ['open', 'resolved'],
        'resolved' => ['open', 'closed'],
        'closed' => ['open'],
    ];

    public function run(): void
    {
        $statuses = collect(self::STATUSES)->mapWithKeys(fn (array $status) => [
            $status['slug'] => TicketStatus::query()->updateOrCreate(
                ['slug' => $status['slug']],
                [
                    'name' => $status['name'],
                    'name_translations' => ['nl' => $status['nl']],
                    'category' => $status['category'],
                    'color' => $status['color'],
                    'pauses_sla' => $status['pauses_sla'] ?? false,
                    'is_public' => $status['is_public'] ?? true,
                    'is_system' => true,
                    'position' => $status['position'],
                ],
            ),
        ]);

        foreach (self::PRIORITIES as $priority) {
            Priority::query()->updateOrCreate(['slug' => $priority['slug']], [
                'name' => $priority['name'],
                'name_translations' => ['nl' => $priority['nl']],
                'level' => $priority['level'],
                'color' => $priority['color'],
                'is_default' => $priority['is_default'] ?? false,
                'position' => $priority['position'],
            ]);
        }

        $workflow = Workflow::query()->updateOrCreate(['slug' => 'default'], [
            'name' => 'Standard support',
            'description' => 'New → In progress → Resolved, with waiting states that pause the SLA clock.',
            'is_default' => true,
            'is_active' => true,
        ]);

        $workflow->statuses()->sync(
            $statuses->mapWithKeys(fn (TicketStatus $status, string $slug) => [
                $status->getKey() => [
                    'is_initial' => $slug === 'new',
                    'position' => $status->position,
                ],
            ])->all()
        );

        $position = 0;

        foreach (self::TRANSITIONS as $from => $targets) {
            foreach ($targets as $to) {
                $position += 10;

                WorkflowTransition::query()->updateOrCreate(
                    [
                        'workflow_id' => $workflow->getKey(),
                        'from_status_id' => $statuses[$from]->getKey(),
                        'to_status_id' => $statuses[$to]->getKey(),
                    ],
                    [
                        'requires_comment' => $to === 'resolved',
                        'position' => $position,
                    ],
                );
            }
        }

        $this->seedQueues();
    }

    private function seedQueues(): void
    {
        $queues = [
            [
                'slug' => 'unassigned',
                'name' => 'Unassigned',
                'description' => 'Open tickets nobody has picked up yet.',
                'filters' => ['status_category' => ['new', 'open'], 'assignee' => ['unassigned']],
                'sort_by' => 'created_at',
                'sort_direction' => 'asc',
                'position' => 10,
            ],
            [
                'slug' => 'my-tickets',
                'name' => 'Assigned to me',
                'description' => 'Everything currently on your plate.',
                'filters' => ['status_category' => ['new', 'open', 'pending'], 'assignee' => ['me']],
                'sort_by' => 'priority_id',
                'sort_direction' => 'desc',
                'position' => 20,
            ],
            [
                'slug' => 'needs-reply',
                'name' => 'Waiting on us',
                'description' => 'Open tickets where the requester spoke last.',
                'filters' => ['unanswered' => true],
                'sort_by' => 'last_activity_at',
                'sort_direction' => 'asc',
                'position' => 30,
            ],
            [
                'slug' => 'all-open',
                'name' => 'All open',
                'description' => 'Every ticket that is not resolved or closed.',
                'filters' => ['status_category' => ['new', 'open', 'pending']],
                'sort_by' => 'updated_at',
                'sort_direction' => 'desc',
                'position' => 40,
            ],
        ];

        foreach ($queues as $queue) {
            Queue::query()->updateOrCreate(['slug' => $queue['slug']], $queue + [
                'is_shared' => true,
                'is_active' => true,
                'columns' => Queue::DEFAULT_COLUMNS,
            ]);
        }
    }
}
