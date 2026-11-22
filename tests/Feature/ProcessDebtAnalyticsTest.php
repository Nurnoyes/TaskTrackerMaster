<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Status;
use App\Models\Task;
use App\Models\TaskStatusHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Integration contracts only; elapsed-time and graph rules live in calculator unit tests. */
class ProcessDebtAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-07 12:00:00', 'UTC'));
    }

    public function test_endpoint_maps_group_history_to_client_metrics_and_updates_current_overdue_flags(): void
    {
        [$group, $manager] = $this->workspace();
        $work = $this->makeStatus($group, 'Разработка', 'work', 1);
        $waiting = $this->makeStatus($group, 'Ready for Approval', 'waiting', 2);
        $task = $this->task($group, $waiting, $manager);
        $this->visit($task, $work, $manager, '08:00', '10:00', '10:00');
        $this->visit($task, $waiting, $manager, '10:00', null, '11:00');

        // The same member can access a second group; its observations must not leak.
        $otherGroup = Group::create(['name' => 'Другая команда', 'description' => '']);
        $otherGroup->users()->attach($manager->id);
        $otherStatus = $this->makeStatus($otherGroup, 'Анализ', 'work', 1);
        $otherTask = $this->task($otherGroup, $otherStatus, $manager);
        $this->visit($otherTask, $otherStatus, $manager, '07:00', null, '08:00');
        Sanctum::actingAs($manager);

        $this->getJson("/api/groups/{$group->id}/analytics/process-debt")
            ->assertOk()
            ->assertJsonPath('summary.task_count', 1)
            ->assertJsonPath('summary.total_seconds', 14400)
            ->assertJsonPath('summary.expired_tasks', 1)
            ->assertJsonPath('managers.0.manager_id', $manager->id)
            ->assertJsonPath('types.0.type', 'bug')
            ->assertJsonPath('transitions.0.from_key', 'status:'.$work->id)
            ->assertJsonPath('transitions.0.to_key', 'status:'.$waiting->id)
            ->assertJsonStructure([
                'generated_at',
                'summary' => ['debt_seconds', 'debt_percent', 'waiting_seconds', 'waiting_percent', 'rework_count'],
                'statuses' => [['key', 'status_id', 'name', 'color', 'time_percent', 'debt_percent', 'visits']],
                'insights',
            ]);
        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'expired' => true]);
    }

    public function test_filters_map_to_historical_snapshots_but_overdue_counts_use_current_assignment(): void
    {
        [$group, $originalManager] = $this->workspace();
        $nextManager = User::factory()->create();
        $group->users()->attach($nextManager->id);
        $analysis = $this->makeStatus($group, 'Анализ', 'work', 1);
        $development = $this->makeStatus($group, 'Разработка', 'work', 2);
        $task = $this->task($group, $development, $nextManager, ['type' => 'feature']);
        $this->visit($task, $analysis, $originalManager, '08:00', '10:00', '09:00');
        $this->visit($task, $development, $nextManager, '10:00', null, '11:00', ['task_type' => 'feature']);
        Sanctum::actingAs($originalManager);

        $this->getJson("/api/groups/{$group->id}/analytics/process-debt?type=bug&manager_id={$originalManager->id}")
            ->assertOk()
            ->assertJsonPath('summary.task_count', 1)
            ->assertJsonPath('summary.total_seconds', 7200)
            ->assertJsonPath('summary.expired_tasks', 0)
            ->assertJsonPath('managers.0.manager_id', $originalManager->id)
            ->assertJsonPath('types.0.type', 'bug');
    }

    public function test_endpoint_requires_group_membership_and_filters_only_that_groups_history(): void
    {
        [$group, $member] = $this->workspace();
        $path = "/api/groups/{$group->id}/analytics/process-debt";
        $this->getJson($path)->assertUnauthorized();

        $outsider = User::factory()->create();
        Sanctum::actingAs($outsider);
        $this->getJson($path)->assertNotFound();

        Sanctum::actingAs($member);
        $this->getJson($path.'?manager_id='.$outsider->id)
            ->assertOk()
            ->assertJsonPath('summary.task_count', 0)
            ->assertJsonPath('summary.total_seconds', 0);
    }

    private function workspace(): array
    {
        $manager = User::factory()->create();
        $group = Group::create(['name' => 'Команда', 'description' => '']);
        $group->users()->attach($manager->id);

        return [$group, $manager];
    }

    private function makeStatus(Group $group, string $name, string $category, int $position): Status
    {
        return Status::create([
            'group_id' => $group->id,
            'name' => $name,
            'description' => '',
            'color' => '#6366f1',
            'category' => $category,
            'position' => $position,
            'sla_minutes' => 60,
        ]);
    }

    private function task(Group $group, Status $status, User $manager, array $overrides = []): Task
    {
        return Task::create(array_merge([
            'name' => 'Проверка API',
            'description' => '',
            'group_id' => $group->id,
            'status_id' => $status->id,
            'type' => 'bug',
            'manager_id' => $manager->id,
            'completed' => false,
            'deadline' => '2026-11-07 11:00:00',
            'status_changed_at' => '2026-11-07 10:00:00',
        ], $overrides));
    }

    private function visit(
        Task $task,
        Status $status,
        User $manager,
        string $entered,
        ?string $left,
        ?string $deadline,
        array $overrides = [],
    ): void {
        $date = static fn (?string $time) => $time === null ? null : '2026-11-07 '.$time.':00';

        TaskStatusHistory::create(array_merge([
            'task_id' => $task->id,
            'group_id' => $task->group_id,
            'status_id' => $status->id,
            'status_original_id' => $status->id,
            'manager_id' => $manager->id,
            'manager_name' => $manager->name,
            'task_type' => 'bug',
            'status_name' => $status->name,
            'status_color' => $status->color,
            'status_category' => $status->category,
            'status_position' => $status->position,
            'entered_at' => $date($entered),
            'left_at' => $date($left),
            'deadline' => $date($deadline),
            'is_rework' => false,
        ], $overrides));
    }
}
