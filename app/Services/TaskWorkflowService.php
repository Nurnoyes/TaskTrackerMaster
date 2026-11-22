<?php

namespace App\Services;

use App\Domain\Tasks\TaskTransition;
use App\Models\Group;
use App\Models\Status;
use App\Models\Task;
use App\Models\TaskStatusHistory;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class TaskWorkflowService
{
    public function create(Group $group, array $data): Task
    {
        return DB::transaction(function () use ($group, $data) {
            $status = Status::query()->lockForUpdate()->findOrFail($data['status_id']);
            $decision = $this->decision(null, $status, $data, CarbonImmutable::now());
            $task = new Task(array_merge($data, [
                'group_id' => $group->id,
                'deadline' => $decision['deadline'],
                'status_changed_at' => $decision['status_changed_at'],
                'completed' => $decision['completed'],
            ]));
            $task->expired = $decision['expired'];
            $task->save();
            $this->openVisit($task, $status, $decision);

            return $task->load(['status', 'manager:id,name']);
        });
    }

    public function update(Group $group, int $taskId, array $data): Task
    {
        return DB::transaction(function () use ($group, $taskId, $data) {
            $task = $group->tasks()->lockForUpdate()->findOrFail($taskId);
            $status = Status::query()->lockForUpdate()->findOrFail($data['status_id'] ?? $task->status_id);
            $previousVisit = $task->history()->reorder()->orderByDesc('id')->first();
            $now = CarbonImmutable::now();
            $decision = $this->decision([
                'status_id' => (int) $task->status_id,
                'status_position' => $previousVisit?->status_position ?? $task->status->position,
                'completed' => $task->completed,
                'deadline' => $task->deadline,
                'status_changed_at' => $task->status_changed_at,
            ], $status, $data, $now);

            if ($decision['close_visit']) {
                $task->history()->whereNull('left_at')->update(['left_at' => $decision['close_visit_at']]);
            }

            $task->fill(array_merge($data, [
                'deadline' => $decision['deadline'],
                'status_changed_at' => $decision['status_changed_at'],
                'completed' => $decision['completed'],
            ]));
            $task->expired = $decision['expired'];
            $task->save();

            if ((int) $task->group_id !== (int) $group->id) {
                // Preserve the existing group_id update contract without splitting a task's history.
                $task->history()->update(['group_id' => $task->group_id]);
            }

            if ($decision['new_visit']) {
                $this->openVisit($task, $status, $decision);
            }

            return $task->load(['status', 'manager:id,name']);
        });
    }

    public function markExpired(?int $groupId = null): int
    {
        // One conditional UPDATE is safe against transitions protected by row locks.
        return Task::query()
            ->when($groupId !== null, fn ($query) => $query->where('group_id', $groupId))
            ->where('completed', false)
            ->where('expired', false)
            ->whereNotNull('deadline')
            ->where('deadline', '<=', CarbonImmutable::now()->startOfSecond())
            ->update(['expired' => true]);
    }

    private function decision(?array $current, Status $status, array $data, DateTimeImmutable $now): array
    {
        $patch = [];
        if (array_key_exists('completed', $data)) {
            $patch['completed'] = (bool) $data['completed'];
        }

        try {
            if (array_key_exists('deadline', $data)) {
                $patch['deadline'] = CarbonImmutable::parse($data['deadline']);
            }

            return TaskTransition::decide($current, [
                'id' => (int) $status->id,
                'position' => $status->position,
                'category' => $status->category,
                'sla_minutes' => $status->sla_minutes,
            ], $patch, $now);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['deadline' => $exception->getMessage()]);
        }
    }

    private function openVisit(Task $task, Status $status, array $decision): void
    {
        TaskStatusHistory::create([
            'task_id' => $task->id,
            'group_id' => $task->group_id,
            'status_id' => $status->id,
            'status_original_id' => $status->id,
            'manager_id' => $task->manager_id,
            'manager_name' => $task->manager?->name,
            'task_type' => $task->type,
            'status_name' => $status->name,
            'status_color' => $status->color,
            'status_category' => $status->category,
            'status_position' => $status->position,
            'entered_at' => $decision['status_changed_at'],
            'left_at' => $decision['visit_left_at'],
            'deadline' => $task->deadline,
            'is_rework' => $decision['is_rework'],
        ]);
    }
}
