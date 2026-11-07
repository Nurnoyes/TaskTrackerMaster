<?php

namespace App\Services;

use App\Domain\ProcessDebt\ProcessDebtCalculator;
use App\Models\Group;
use App\Models\Task;
use App\Models\TaskStatusHistory;
use Illuminate\Support\Carbon;

class ProcessDebtService
{
    public function __construct(private readonly ProcessDebtCalculator $calculator) {}

    public function analyze(Group $group, ?string $type = null, ?int $managerId = null): array
    {
        $now = Carbon::now()->getTimestamp();
        $expiredTasks = Task::query()
            ->where('group_id', $group->id)
            ->where('completed', false)
            ->where('expired', true)
            ->when($type !== null, fn ($query) => $query->where('type', $type))
            ->when($managerId !== null, fn ($query) => $query->where('manager_id', $managerId))
            ->count();

        // Sorting belongs to the query boundary; the calculator consumes this stream.
        $histories = TaskStatusHistory::query()
            ->where('group_id', $group->id)
            ->orderBy('task_id')
            ->orderBy('entered_at')
            ->orderBy('id')
            ->cursor()
            ->map(static fn (TaskStatusHistory $history) => [
                'id' => (int) $history->id,
                'task_id' => (int) $history->task_id,
                'status_id' => $history->status_id === null ? null : (int) $history->status_id,
                'status_original_id' => $history->status_original_id === null ? null : (int) $history->status_original_id,
                'status_name' => $history->status_name,
                'status_color' => $history->status_color,
                'status_category' => $history->status_category,
                'manager_id' => $history->manager_id === null ? null : (int) $history->manager_id,
                'manager_name' => $history->manager_name,
                'task_type' => $history->task_type,
                'entered_at' => $history->entered_at->getTimestamp(),
                'left_at' => $history->left_at?->getTimestamp(),
                'deadline' => $history->deadline?->getTimestamp(),
                'is_rework' => (bool) $history->is_rework,
            ]);

        return $this->calculator->calculate($histories, $now, $type, $managerId, $expiredTasks);
    }
}
