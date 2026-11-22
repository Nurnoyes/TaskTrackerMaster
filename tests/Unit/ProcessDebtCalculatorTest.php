<?php

namespace Tests\Unit;

use App\Domain\ProcessDebt\ProcessDebtCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProcessDebtCalculatorTest extends TestCase
{
    #[DataProvider('stageIntervals')]
    public function test_observed_stage_time_and_sla_debt_are_bounded_by_the_explicit_clock(
        array $stage,
        int $now,
        int $seconds,
        int $debt,
        int $waiting,
        int $taskCount,
    ): void {
        $result = (new ProcessDebtCalculator)->calculate([$this->visit($stage)], $now);

        $this->assertSame($seconds, $result['summary']['total_seconds']);
        $this->assertSame($debt, $result['summary']['debt_seconds']);
        $this->assertSame($waiting, $result['summary']['waiting_seconds']);
        $this->assertSame($taskCount, $result['summary']['task_count']);
    }

    public static function stageIntervals(): iterable
    {
        yield 'closed waiting stage stops accruing at exit' => [
            ['status_category' => 'waiting', 'left_at' => 120, 'deadline' => 60], 300, 120, 60, 120, 1,
        ];
        yield 'open stage accrues until supplied now' => [[], 120, 120, 60, 0, 1];
        yield 'before deadline' => [['deadline' => 180], 120, 120, 0, 0, 1];
        yield 'at deadline has zero excess time' => [['deadline' => 120], 120, 120, 0, 0, 1];
        yield 'terminal stage never accrues process time' => [['status_category' => 'done'], 120, 0, 0, 0, 1];
        yield 'unknown deadline does not invent debt' => [['deadline' => null], 120, 120, 0, 0, 1];
        yield 'early deadline cannot charge time before entry' => [
            ['entered_at' => 60, 'left_at' => 120, 'deadline' => 0], 200, 60, 60, 0, 1,
        ];
        yield 'instantaneous visit has no elapsed time' => [
            ['entered_at' => 120, 'left_at' => 120], 120, 0, 0, 0, 1,
        ];
        yield 'future visit is not observed' => [['entered_at' => 121], 120, 0, 0, 0, 0];
        yield 'recorded exit beyond now is capped at now' => [
            ['entered_at' => 60, 'left_at' => 300, 'deadline' => 90], 120, 60, 30, 0, 1,
        ];
    }

    public function test_aggregation_counts_tasks_once_and_keeps_waiting_separate_from_excess_time(): void
    {
        $result = (new ProcessDebtCalculator)->calculate([
            $this->visit(['left_at' => 60, 'deadline' => 60]),
            $this->visit(['id' => 2, 'status_id' => 2, 'status_original_id' => 2, 'status_category' => 'waiting', 'entered_at' => 60, 'left_at' => 180, 'deadline' => 120]),
            $this->visit(['id' => 3, 'status_id' => 3, 'status_original_id' => 3, 'status_category' => 'done', 'entered_at' => 180]),
            $this->visit(['id' => 4, 'task_id' => 2, 'status_id' => 2, 'status_original_id' => 2, 'status_category' => 'waiting', 'entered_at' => 60, 'deadline' => 120]),
        ], 180, expiredTasks: 1);

        $this->assertSame(2, $result['summary']['task_count']);
        $this->assertSame(300, $result['summary']['total_seconds']);
        $this->assertSame(120, $result['summary']['debt_seconds']);
        $this->assertSame(40.0, $result['summary']['debt_percent']);
        $this->assertSame(80.0, $result['summary']['waiting_percent']);
        $this->assertSame(1, $result['summary']['expired_tasks']);
        $this->assertSame(2, $result['statuses'][0]['visits']);
        $this->assertSame(2, $result['statuses'][0]['task_count']);
        $this->assertSame(2, $result['managers'][0]['task_count']);
        $this->assertSame(2, $result['types'][0]['task_count']);
    }

    public function test_snapshot_filters_retain_the_real_predecessor_instead_of_inventing_shortcuts(): void
    {
        $result = (new ProcessDebtCalculator)->calculate([
            $this->visit(['left_at' => 60, 'deadline' => 30]),
            $this->visit(['id' => 2, 'status_id' => 2, 'status_original_id' => 2, 'manager_id' => 2, 'task_type' => 'feature', 'entered_at' => 60, 'left_at' => 120, 'deadline' => 120]),
            $this->visit(['id' => 3, 'status_id' => 3, 'status_original_id' => 3, 'entered_at' => 120, 'left_at' => 180, 'deadline' => 150]),
            $this->visit(['id' => 4, 'status_id' => 4, 'status_original_id' => 4, 'entered_at' => 181]),
        ], 180, type: 'bug', managerId: 1);

        $this->assertSame(120, $result['summary']['total_seconds']);
        $this->assertSame(60, $result['summary']['debt_seconds']);
        $this->assertSame(1, $result['summary']['task_count']);
        $this->assertSame(1, $result['managers'][0]['manager_id']);
        $this->assertSame('bug', $result['types'][0]['type']);
        $this->assertCount(2, $result['statuses']);
        $this->assertCount(1, $result['transitions']);
        $this->assertSame('status:2', $result['transitions'][0]['from_key']);
        $this->assertSame('status:3', $result['transitions'][0]['to_key']);
    }

    public function test_rework_and_same_status_reopening_preserve_deleted_status_identity_without_double_counting(): void
    {
        $deleted = ['status_id' => null, 'status_name' => 'Ревью'];
        $result = (new ProcessDebtCalculator)->calculate([
            $this->visit($deleted + ['left_at' => 60, 'deadline' => 60]),
            $this->visit($deleted + ['id' => 2, 'status_original_id' => 2, 'entered_at' => 60, 'left_at' => 120, 'deadline' => 120]),
            $this->visit($deleted + ['id' => 3, 'entered_at' => 120, 'left_at' => 180, 'deadline' => 150, 'is_rework' => true]),
            $this->visit($deleted + ['id' => 4, 'entered_at' => 240, 'left_at' => 300, 'deadline' => 300]),
        ], 360);

        $this->assertSame(240, $result['summary']['total_seconds']);
        $this->assertSame(30, $result['summary']['debt_seconds']);
        $this->assertSame(1, $result['summary']['task_count']);
        $this->assertSame(1, $result['summary']['rework_count']);
        $this->assertCount(2, $result['statuses']);
        $this->assertSame('status:1', $result['statuses'][0]['key']);
        $this->assertSame(3, $result['statuses'][0]['visits']);
        $this->assertCount(2, $result['transitions']);
        $this->assertSame('status:2', $result['transitions'][1]['from_key']);
        $this->assertSame('status:1', $result['transitions'][1]['to_key']);
        $this->assertSame(1, $result['transitions'][1]['rework_count']);
    }

    #[DataProvider('typeNames')]
    public function test_type_bottleneck_facts_use_the_types_time_instead_of_all_observed_time(string $type): void
    {
        $result = (new ProcessDebtCalculator)->calculate([
            $this->visit(['task_type' => $type, 'left_at' => 120, 'deadline' => 120]),
            $this->visit(['id' => 2, 'task_type' => $type, 'status_id' => 3, 'status_original_id' => 3, 'status_name' => 'Уточнение', 'status_category' => 'waiting', 'entered_at' => 120, 'left_at' => 180, 'deadline' => 180]),
            $this->visit(['id' => 3, 'task_type' => $type, 'status_id' => 2, 'status_original_id' => 2, 'status_name' => 'Ready for Approval', 'status_category' => 'waiting', 'entered_at' => 180, 'left_at' => 300, 'deadline' => 240]),
            $this->visit(['id' => 4, 'task_id' => 2, 'task_type' => 'other', 'deadline' => 600]),
        ], 600);

        // The top waiting stage uses 120 of this type's 300 seconds; another type adds 600.
        // Using all 900 observed seconds would incorrectly report 13.33%.
        $this->assertSame(900, $result['summary']['total_seconds']);
        $this->assertCount(1, $result['bottlenecks']);
        $bottleneck = $result['bottlenecks'][0];
        $this->assertSame($type, $bottleneck['type']);
        $this->assertSame('status:2', $bottleneck['status_key']);
        $this->assertSame('Ready for Approval', $bottleneck['status_name']);
        $this->assertSame(120, $bottleneck['waiting_seconds']);
        $this->assertSame(40.0, $bottleneck['time_percent']);
    }

    public static function typeNames(): iterable
    {
        yield 'ordinary type' => ['bug'];
        yield 'numeric string remains a string' => ['0'];
        yield 'object-prototype-looking string is ordinary data' => ['__proto__'];
    }

    public function test_no_observations_means_no_invented_bottleneck_or_nonfinite_percentages(): void
    {
        $result = (new ProcessDebtCalculator)->calculate([], 120);

        $this->assertSame(0, $result['summary']['task_count']);
        $this->assertSame(0.0, $result['summary']['debt_percent']);
        $this->assertSame(0.0, $result['summary']['waiting_percent']);
        $this->assertSame([], $result['statuses']);
        $this->assertSame([], $result['transitions']);
        $this->assertSame([], $result['bottlenecks']);
        $this->assertCount(1, $result['insights']);
    }

    /** Each test lists these snapshots in task_id, entered_at, id order. */
    private function visit(array $overrides = []): array
    {
        return array_replace([
            'id' => 1,
            'task_id' => 1,
            'status_id' => 1,
            'status_original_id' => 1,
            'status_name' => 'Разработка',
            'status_color' => '#6366f1',
            'status_category' => 'work',
            'manager_id' => 1,
            'manager_name' => 'Менеджер',
            'task_type' => 'bug',
            'entered_at' => 0,
            'left_at' => null,
            'deadline' => 60,
            'is_rework' => false,
        ], $overrides);
    }
}
