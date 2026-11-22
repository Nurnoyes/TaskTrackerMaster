<?php

namespace App\Domain\ProcessDebt;

use DateTimeImmutable;

final class ProcessDebtCalculator
{
    /**
     * Each record is a plain immutable snapshot. All times are epoch seconds.
     * Supply records in task_id, entered_at, id order; ordered iterables can stream.
     * The current clock and current overdue-task count are explicit inputs because
     * historical ownership cannot determine either of them.
     *
     * @param iterable<array{
     *     id: int, task_id: int, status_id: ?int, status_original_id: ?int,
     *     status_name: string, status_color: string, status_category: string,
     *     manager_id: ?int, manager_name: ?string, task_type: string,
     *     entered_at: int, left_at: ?int, deadline: ?int, is_rework: bool
     * }> $histories
     */
    public function calculate(
        iterable $histories,
        int $now,
        ?string $type = null,
        ?int $managerId = null,
        int $expiredTasks = 0,
    ): array {
        $summary = [
            'task_count' => 0,
            'total_seconds' => 0,
            'debt_seconds' => 0,
            'debt_percent' => 0.0,
            'waiting_seconds' => 0,
            'waiting_percent' => 0.0,
            'rework_count' => 0,
            'expired_tasks' => $expiredTasks,
        ];
        $statuses = [];
        $types = [];
        $typeWaitingStatuses = [];
        $managers = [];
        $transitions = [];
        $taskIds = [];
        $previous = null;

        foreach ($histories as $history) {
            if ($history['entered_at'] > $now) {
                continue;
            }

            $matches = ($type === null || $history['task_type'] === $type)
                && ($managerId === null || $history['manager_id'] === $managerId);
            $statusKey = $this->statusKey($history);

            // Filters select destination visits, never erase their actual predecessor.
            // Reopening the same status is another visit, not a status transition.
            if ($matches && $previous !== null && $previous['task_id'] === $history['task_id']
                && $this->statusKey($previous) !== $statusKey) {
                $previousKey = $this->statusKey($previous);
                $key = $previousKey.'->'.$statusKey;
                $transitions[$key] ??= [
                    'from_key' => $previousKey,
                    'to_key' => $statusKey,
                    'from_status_id' => $previous['status_id'],
                    'to_status_id' => $history['status_id'],
                    'from_name' => $previous['status_name'],
                    'to_name' => $history['status_name'],
                    'count' => 0,
                    'rework_count' => 0,
                ];
                $transitions[$key]['count']++;
                $transitions[$key]['rework_count'] += (int) $history['is_rework'];
            }
            $previous = $history;

            if (! $matches) {
                continue;
            }

            $entered = $history['entered_at'];
            $left = min($history['left_at'] ?? $now, $now);
            $seconds = $history['status_category'] === 'done' ? 0 : max(0, $left - $entered);
            $debt = $history['deadline'] === null || $history['status_category'] === 'done'
                ? 0
                : max(0, $left - max($entered, $history['deadline']));
            $waiting = $history['status_category'] === 'waiting' ? $seconds : 0;
            $taskIds[$history['task_id']] = true;
            $summary['total_seconds'] += $seconds;
            $summary['debt_seconds'] += $debt;
            $summary['waiting_seconds'] += $waiting;
            $summary['rework_count'] += (int) $history['is_rework'];

            $statuses[$statusKey] ??= array_merge($this->emptyBucket(), [
                'key' => $statusKey,
                'status_id' => $history['status_id'],
                'name' => $history['status_name'],
                'color' => $history['status_color'],
                'category' => $history['status_category'],
                'visits' => 0,
            ]);
            $statuses[$statusKey]['visits']++;
            $this->addVisit($statuses[$statusKey], $history['task_id'], $seconds, $debt, $waiting);

            $types[$history['task_type']] ??= array_merge($this->emptyBucket(), ['type' => $history['task_type']]);
            $this->addVisit($types[$history['task_type']], $history['task_id'], $seconds, $debt, $waiting);
            if ($waiting > 0) {
                $typeWaitingStatuses[$history['task_type']][$statusKey] ??= [
                    'name' => $history['status_name'],
                    'waiting_seconds' => 0,
                ];
                $typeWaitingStatuses[$history['task_type']][$statusKey]['waiting_seconds'] += $waiting;
            }

            $managerKey = $history['manager_id'] ?? 'unassigned:'.($history['manager_name'] ?? '');
            $managers[$managerKey] ??= array_merge($this->emptyBucket(), [
                'manager_id' => $history['manager_id'],
                'name' => $history['manager_name'] ?? 'Без ответственного',
            ]);
            $this->addVisit($managers[$managerKey], $history['task_id'], $seconds, $debt, $waiting);
        }

        $summary['task_count'] = count($taskIds);
        $summary['debt_percent'] = $this->percent($summary['debt_seconds'], $summary['total_seconds']);
        $summary['waiting_percent'] = $this->percent($summary['waiting_seconds'], $summary['total_seconds']);
        $statuses = $this->finishBuckets($statuses, $summary['total_seconds']);
        $types = $this->finishBuckets($types);
        $managers = $this->finishBuckets($managers);
        $bottlenecks = $this->bottlenecks($types, $typeWaitingStatuses);

        return [
            'generated_at' => (new DateTimeImmutable('@'.$now))->format('Y-m-d\TH:i:s.000000\Z'),
            'summary' => $summary,
            'statuses' => $statuses,
            'types' => $types,
            'managers' => $managers,
            'transitions' => array_values($transitions),
            'bottlenecks' => $bottlenecks,
            'insights' => (new ProcessDebtInsights)->describe($summary, $statuses, $types, $bottlenecks),
        ];
    }

    private function bottlenecks(array $types, array $waitingByType): array
    {
        $bottlenecks = [];
        foreach ($types as $type) {
            if ($type['waiting_seconds'] === 0) {
                continue;
            }

            $waitingByStatus = $waitingByType[$type['type']];
            uasort($waitingByStatus, fn (array $first, array $second) => $second['waiting_seconds'] <=> $first['waiting_seconds']);
            $statusKey = array_key_first($waitingByStatus);
            $top = $waitingByStatus[$statusKey];
            $bottlenecks[] = [
                'type' => $type['type'],
                'status_key' => $statusKey,
                'status_name' => $top['name'],
                'waiting_seconds' => $top['waiting_seconds'],
                'time_percent' => $this->percent($top['waiting_seconds'], $type['total_seconds']),
            ];
        }

        return $bottlenecks;
    }

    private function emptyBucket(): array
    {
        return ['total_seconds' => 0, 'debt_seconds' => 0, 'waiting_seconds' => 0, '_task_ids' => []];
    }

    private function addVisit(array &$bucket, int $taskId, int $seconds, int $debt, int $waiting): void
    {
        $bucket['total_seconds'] += $seconds;
        $bucket['debt_seconds'] += $debt;
        $bucket['waiting_seconds'] += $waiting;
        $bucket['_task_ids'][$taskId] = true;
    }

    private function finishBuckets(array $buckets, ?int $allSeconds = null): array
    {
        foreach ($buckets as &$bucket) {
            $bucket['task_count'] = count($bucket['_task_ids']);
            $bucket['debt_percent'] = $this->percent($bucket['debt_seconds'], $bucket['total_seconds']);
            $bucket['waiting_percent'] = $this->percent($bucket['waiting_seconds'], $bucket['total_seconds']);
            if ($allSeconds !== null) {
                $bucket['time_percent'] = $this->percent($bucket['total_seconds'], $allSeconds);
            }
            unset($bucket['_task_ids']);
        }
        unset($bucket);

        $buckets = array_values($buckets);
        usort($buckets, fn (array $first, array $second) => $second['debt_seconds'] <=> $first['debt_seconds']
            ?: $second['total_seconds'] <=> $first['total_seconds']);

        return $buckets;
    }

    private function percent(int $numerator, int $denominator): float
    {
        return $denominator > 0 ? round($numerator / $denominator * 100, 2) : 0.0;
    }

    private function statusKey(array $history): string
    {
        $originalId = $history['status_original_id'] ?? $history['status_id'];

        return $originalId === null
            ? 'deleted:'.sha1($history['status_name'])
            : 'status:'.$originalId;
    }
}
