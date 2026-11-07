<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Status;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TaskWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-07 09:00:00', 'UTC'));
        $this->manager = User::factory()->create();
        Sanctum::actingAs($this->manager);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public function test_a_transition_persists_the_new_stage_and_preserves_entry_snapshots(): void
    {
        $group = $this->group();
        $analysis = $this->makeStatus($group);
        $approvalId = $this->postJson("/api/groups/{$group->id}/status", [
            'name' => 'Ready for Approval', 'color' => '#6366f1',
            'description' => 'Approval stage', 'group_id' => $group->id,
            'category' => 'waiting', 'sla_minutes' => 30, 'position' => 20,
        ])->assertSuccessful()->assertJsonPath('category', 'waiting')->assertJsonPath('sla_minutes', 30)->json('id');
        $approval = Status::findOrFail($approvalId);
        $task = $this->task($group, $analysis, ['manager_id' => $this->manager->id]);
        $originalManagerName = $this->manager->name;
        $this->assertDatabaseHas('task_status_histories', [
            'task_id' => $task->id, 'task_type' => 'feature', 'manager_id' => $this->manager->id,
            'status_name' => 'Analysis', 'status_position' => 10, 'entered_at' => '2026-11-07 09:00:00',
            'deadline' => '2026-11-07 10:00:00', 'left_at' => null,
        ]);

        $this->putJson("/api/groups/{$group->id}/statuses/{$analysis->id}", [
            'name' => 'Renamed analysis', 'position' => 50, 'sla_minutes' => 5,
        ])->assertOk();
        $this->manager->update(['name' => 'Renamed manager']);
        $this->travel(2)->hours();
        $this->getJson("/api/groups/{$group->id}/tasks")->assertOk();
        $this->assertTrue($task->fresh()->expired);
        $this->putJson("/api/groups/{$group->id}/tasks/{$task->id}", ['status_id' => $approval->id])
            ->assertOk()->assertJsonPath('expired', false);

        $this->assertDatabaseCount('task_status_histories', 2);
        $this->assertDatabaseHas('tasks', [
            'id' => $task->id, 'status_id' => $approval->id, 'expired' => false,
            'deadline' => '2026-11-07 11:30:00', 'status_changed_at' => '2026-11-07 11:00:00',
        ]);
        $this->assertDatabaseHas('task_status_histories', [
            'task_id' => $task->id, 'status_id' => $analysis->id, 'status_name' => 'Analysis',
            'manager_name' => $originalManagerName, 'deadline' => '2026-11-07 10:00:00',
            'left_at' => '2026-11-07 11:00:00',
        ]);
        $this->assertDatabaseHas('task_status_histories', [
            'task_id' => $task->id, 'status_id' => $approval->id, 'status_original_id' => $approval->id,
            'status_category' => 'waiting', 'entered_at' => '2026-11-07 11:00:00',
            'deadline' => '2026-11-07 11:30:00', 'left_at' => null, 'is_rework' => false,
        ]);
    }

    public function test_api_deadlines_are_parsed_in_utc_and_domain_rejections_become_validation_errors(): void
    {
        $group = $this->group();
        $analysis = $this->makeStatus($group);
        $review = $this->makeStatus($group, ['name' => 'Review', 'position' => 20]);
        $task = $this->task($group, $analysis);
        $url = "/api/groups/{$group->id}/tasks/{$task->id}";

        $this->putJson($url, [
            'status_id' => $review->id, 'deadline' => '2026-11-07T12:25:00.999999+03:00',
        ])->assertOk();
        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'deadline' => '2026-11-07 09:25:00']);
        $this->putJson($url, ['deadline' => '2026-11-07T11:00:00Z'])
            ->assertUnprocessable()->assertJsonValidationErrors('deadline');

        $this->travelTo(Carbon::parse('2026-11-07T09:00:00.100000Z'));
        $this->putJson($url, [
            'status_id' => $analysis->id, 'deadline' => '2026-11-07T09:00:00.999999Z',
        ])->assertUnprocessable()->assertJsonValidationErrors('deadline');

        $this->assertDatabaseCount('task_status_histories', 2);
        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'status_id' => $review->id]);
        $this->assertDatabaseHas('task_status_histories', [
            'task_id' => $task->id, 'status_id' => $review->id, 'left_at' => null,
        ]);
    }

    public function test_expiry_is_persisted_on_read_and_by_the_idle_command_without_changing_history(): void
    {
        $group = $this->group();
        $status = $this->makeStatus($group);
        $readTask = $this->task($group, $status);
        $idleTask = $this->task($group, $status, ['deadline' => '2026-11-07T10:01:00Z']);
        $completedTask = $this->task($group, $status, ['completed' => true]);
        $this->travelTo(Carbon::parse('2026-11-07T09:59:59Z'));
        $this->getJson("/api/groups/{$group->id}/tasks")->assertOk();
        $this->assertFalse($readTask->fresh()->expired);

        $this->travelTo(Carbon::parse('2026-11-07T10:00:00Z'));
        $this->getJson("/api/groups/{$group->id}/tasks/{$readTask->id}")
            ->assertOk()->assertJsonPath('expired', true);
        $this->assertFalse($idleTask->fresh()->expired);
        $this->travelTo(Carbon::parse('2026-11-07T10:01:00Z'));
        $this->artisan('tasks:mark-expired')->assertExitCode(0);

        $this->assertTrue($idleTask->fresh()->expired);
        $this->assertFalse($completedTask->fresh()->expired);
        $this->assertDatabaseCount('task_status_histories', 3);
        $this->assertDatabaseHas('task_status_histories', [
            'task_id' => $idleTask->id, 'left_at' => null, 'deadline' => '2026-11-07 10:01:00',
        ]);
    }

    public function test_completion_and_reopening_persist_two_visits_with_an_unobserved_finished_gap(): void
    {
        $group = $this->group();
        $status = $this->makeStatus($group);
        $task = $this->task($group, $status);
        $url = "/api/groups/{$group->id}/tasks/{$task->id}";
        $this->travel(30)->minutes();
        $this->putJson($url, ['completed' => true])->assertOk();
        $this->travel(2)->hours();
        $this->putJson($url, ['completed' => false, 'deadline' => '2026-11-07T11:50:00Z'])
            ->assertOk()->assertJsonPath('completed', false);

        $this->assertDatabaseCount('task_status_histories', 2);
        $this->assertDatabaseHas('task_status_histories', [
            'task_id' => $task->id, 'entered_at' => '2026-11-07 09:00:00', 'left_at' => '2026-11-07 09:30:00',
        ]);
        $this->assertDatabaseHas('task_status_histories', [
            'task_id' => $task->id, 'status_id' => $status->id, 'entered_at' => '2026-11-07 11:30:00',
            'deadline' => '2026-11-07 11:50:00', 'left_at' => null, 'is_rework' => false,
        ]);
    }

    public function test_a_group_created_by_the_client_is_available_with_its_manager_list(): void
    {
        $groupId = $this->postJson('/api/group', [
            'name' => 'Delivery', 'description' => 'Test process',
        ])->assertSuccessful()->json('id');
        $this->assertDatabaseHas('user_groups', ['group_id' => $groupId, 'user_id' => $this->manager->id]);
        $this->getJson('/api/groups')->assertOk()->assertJsonPath('0.id', $groupId);
        $this->getJson("/api/groups/{$groupId}/members")
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $this->manager->id);
    }

    public function test_server_owned_fields_are_rejected_even_when_their_values_are_empty(): void
    {
        $group = $this->group();
        $status = $this->makeStatus($group);
        $task = $this->task($group, $status);
        foreach ([
            ['status_changed_at' => null],
            ['expired' => null], ['expired' => false], ['expired' => true],
        ] as $forbidden) {
            $this->postJson("/api/groups/{$group->id}/task", array_merge([
                'name' => 'Server-owned fields', 'status_id' => $status->id,
                'description' => 'Server-owned deadline state', 'group_id' => $group->id, 'completed' => false,
            ], $forbidden))->assertUnprocessable()->assertJsonValidationErrors(array_keys($forbidden));
            $this->putJson("/api/groups/{$group->id}/tasks/{$task->id}", $forbidden)
                ->assertUnprocessable()->assertJsonValidationErrors(array_keys($forbidden));
        }
        $this->assertDatabaseCount('tasks', 1);
        $this->assertDatabaseCount('statuses', 1);
        $this->assertDatabaseCount('task_status_histories', 1);
    }

    public function test_task_writes_require_a_manager_from_the_selected_group(): void
    {
        $group = $this->group();
        $status = $this->makeStatus($group);
        $task = $this->task($group, $status);
        $outsider = User::factory()->create();
        $this->postJson("/api/groups/{$group->id}/task", [
            'name' => 'Invalid manager', 'description' => 'Manager must be a group member',
            'status_id' => $status->id, 'group_id' => $group->id, 'completed' => false,
            'manager_id' => $outsider->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('manager_id');
        $this->putJson("/api/groups/{$group->id}/tasks/{$task->id}", ['manager_id' => $outsider->id])
            ->assertUnprocessable()->assertJsonValidationErrors('manager_id');
        $this->assertDatabaseCount('tasks', 1);
        $this->assertDatabaseCount('task_status_histories', 1);
    }

    public function test_legacy_payloads_and_group_transfers_preserve_the_observed_process(): void
    {
        $group = $this->group();
        $destination = $this->group();
        $sourceManager = User::factory()->create();
        $group->users()->attach($sourceManager->id);
        $legacyColor = 'rgb(255, 0, 0)';
        $statusId = $this->postJson("/api/groups/{$group->id}/status", [
            'name' => 'Legacy status', 'description' => 'Existing status payload',
            'color' => $legacyColor, 'group_id' => $group->id,
        ])->assertSuccessful()->json('id');
        $taskId = $this->postJson("/api/groups/{$group->id}/task", [
            'name' => 'Legacy task', 'description' => 'Existing task payload',
            'status_id' => $statusId, 'group_id' => $group->id, 'completed' => false,
            'manager_id' => $sourceManager->id,
        ])->assertSuccessful()->json('id');
        $this->assertDatabaseHas('task_status_histories', [
            'task_id' => $taskId, 'status_color' => $legacyColor,
        ]);

        $review = $this->makeStatus($group, ['name' => 'Review', 'position' => 20]);
        $this->travel(30)->minutes();
        $this->putJson("/api/groups/{$group->id}/tasks/{$taskId}", ['status_id' => $review->id])->assertOk();
        $this->travel(15)->minutes();
        $this->putJson("/api/groups/{$group->id}/tasks/{$taskId}", ['group_id' => $destination->id])->assertOk();

        $this->assertDatabaseHas('tasks', [
            'id' => $taskId, 'group_id' => $destination->id, 'status_id' => $review->id,
            'deadline' => '2026-11-07 10:30:00', 'status_changed_at' => '2026-11-07 09:30:00',
        ]);
        $this->assertDatabaseCount('task_status_histories', 2);
        $this->assertDatabaseMissing('task_status_histories', ['task_id' => $taskId, 'group_id' => $group->id]);
        $this->getJson("/api/groups/{$destination->id}/tasks/{$taskId}/history")
            ->assertOk()->assertJsonCount(2)
            ->assertJsonPath('0.group_id', $destination->id)->assertJsonPath('1.group_id', $destination->id);
        $this->getJson("/api/groups/{$group->id}/analytics/process-debt")
            ->assertOk()->assertJsonPath('summary.task_count', 0);
        $this->getJson("/api/groups/{$destination->id}/analytics/process-debt")
            ->assertOk()->assertJsonPath('summary.task_count', 1)->assertJsonPath('summary.total_seconds', 2700);
        $this->getJson("/api/groups/{$destination->id}/analytics/process-debt?manager_id={$sourceManager->id}")
            ->assertOk()->assertJsonPath('summary.task_count', 1)->assertJsonPath('summary.total_seconds', 2700)
            ->assertJsonPath('managers.0.manager_id', $sourceManager->id);
    }

    public function test_deleting_an_empty_status_keeps_its_historical_snapshots(): void
    {
        $group = $this->group();
        $analysis = $this->makeStatus($group);
        $review = $this->makeStatus($group, ['name' => 'Review', 'position' => 20]);
        $task = $this->task($group, $analysis);
        $this->travel(30)->minutes();
        $this->putJson("/api/groups/{$group->id}/tasks/{$task->id}", ['status_id' => $review->id])->assertOk();
        $analysis->delete();
        $this->assertDatabaseMissing('statuses', ['id' => $analysis->id]);
        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'status_id' => $review->id]);
        $this->assertDatabaseHas('task_status_histories', [
            'task_id' => $task->id, 'status_id' => null, 'status_original_id' => $analysis->id,
            'status_name' => 'Analysis', 'status_color' => '#64748b', 'left_at' => '2026-11-07 09:30:00',
        ]);
        $this->getJson("/api/groups/{$group->id}/tasks/{$task->id}/history")
            ->assertOk()->assertJsonCount(2)->assertJsonPath('0.status_name', 'Analysis');
    }

    private function group(): Group
    {
        $group = Group::create(['name' => 'Delivery', 'description' => 'Test process']);
        $group->users()->attach($this->manager->id);

        return $group;
    }

    private function makeStatus(Group $group, array $attributes = []): Status
    {
        return Status::create(array_merge([
            'group_id' => $group->id, 'name' => 'Analysis', 'description' => '',
            'color' => '#64748b', 'category' => 'work', 'sla_minutes' => 60, 'position' => 10,
        ], $attributes));
    }

    private function task(Group $group, Status $status, array $attributes = []): Task
    {
        $response = $this->postJson("/api/groups/{$group->id}/task", array_merge([
            'name' => 'Integration task', 'description' => 'Exercise the workflow',
            'status_id' => $status->id, 'group_id' => $group->id, 'completed' => false, 'type' => 'feature',
        ], $attributes))->assertSuccessful();

        return Task::findOrFail($response->json('id'));
    }
}
