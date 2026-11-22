<?php

namespace Tests\Unit;

use App\Domain\Tasks\TaskTransition;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TaskTransitionTest extends TestCase
{
    public function test_creation_starts_one_stage_using_its_sla_and_second_precision(): void
    {
        $decision = TaskTransition::decide(null, $this->makeStatus(), [], $this->date('09:00:00.800000'));

        $this->assertEquals([
            'new_visit' => true,
            'close_visit' => false,
            'close_visit_at' => null,
            'deadline' => $this->date('09:30:00'),
            'status_changed_at' => $this->date('09:00:00'),
            'completed' => false,
            'expired' => false,
            'is_rework' => false,
            'visit_left_at' => null,
        ], $decision);
    }

    public function test_custom_deadlines_are_normalized_to_utc_and_whole_seconds(): void
    {
        $decision = TaskTransition::decide(null, $this->makeStatus(), [
            'deadline' => new DateTimeImmutable('2026-11-07T12:25:00.999999+03:00'),
        ], $this->date('09:00:00'));

        $this->assertSame($this->date('09:25:00')->getTimestamp(), $decision['deadline']->getTimestamp());
        $this->assertSame('000000', $decision['deadline']->format('u'));
        $this->assertSame('UTC', $decision['deadline']->getTimezone()->getName());
    }

    #[DataProvider('nonFutureDeadlines')]
    public function test_deadlines_must_still_be_future_after_storage_precision_is_applied(string $deadline): void
    {
        $this->expectException(InvalidArgumentException::class);

        TaskTransition::decide(null, $this->makeStatus(), [
            'deadline' => new DateTimeImmutable($deadline),
        ], $this->date('09:00:00.100000'));
    }

    public static function nonFutureDeadlines(): array
    {
        return [
            'past' => ['2026-11-07T08:59:59.999999Z'],
            'equal' => ['2026-11-07T09:00:00Z'],
            'future within the same stored second' => ['2026-11-07T09:00:00.999999Z'],
        ];
    }

    public function test_the_next_whole_second_is_a_valid_custom_deadline(): void
    {
        $decision = TaskTransition::decide(null, $this->makeStatus(), [
            'deadline' => $this->date('09:00:01'),
        ], $this->date('09:00:00.999999'));

        $this->assertEquals($this->date('09:00:01'), $decision['deadline']);
        $this->assertFalse($decision['expired']);
    }

    public function test_metadata_changes_and_the_same_status_cannot_reset_the_stage_or_its_debt(): void
    {
        $current = $this->current();
        $decision = TaskTransition::decide($current, $this->makeStatus(['id' => 1, 'sla_minutes' => 5]), [], $this->date('10:00:00'));

        $this->assertEquals([
            'new_visit' => false,
            'close_visit' => false,
            'close_visit_at' => null,
            'deadline' => $this->date('10:00:00'),
            'status_changed_at' => $this->date('09:00:00'),
            'completed' => false,
            'expired' => true,
            'is_rework' => false,
            'visit_left_at' => null,
        ], $decision);
        $this->assertEquals($this->current(), $current);
    }

    public function test_even_resubmitting_the_same_deadline_without_a_new_stage_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        TaskTransition::decide($this->current(), $this->makeStatus(['id' => 1]), [
            'deadline' => $this->date('10:00:00'),
        ], $this->date('09:30:00'));
    }

    public function test_a_status_change_closes_the_previous_visit_and_resets_the_stage_deadline(): void
    {
        $decision = TaskTransition::decide($this->current(), $this->makeStatus(['category' => 'waiting']), [], $this->date('11:00:00'));

        $this->assertEquals([
            'new_visit' => true,
            'close_visit' => true,
            'close_visit_at' => $this->date('11:00:00'),
            'deadline' => $this->date('11:30:00'),
            'status_changed_at' => $this->date('11:00:00'),
            'completed' => false,
            'expired' => false,
            'is_rework' => false,
            'visit_left_at' => null,
        ], $decision);
    }

    #[DataProvider('stageDirections')]
    public function test_only_a_return_to_an_earlier_recorded_position_is_rework(int $targetPosition, bool $rework): void
    {
        $decision = TaskTransition::decide($this->current(['status_position' => 20]), $this->makeStatus([
            'position' => $targetPosition,
        ]), [], $this->date('09:30:00'));

        $this->assertSame($rework, $decision['is_rework']);
    }

    public static function stageDirections(): array
    {
        return ['backward' => [10, true], 'same rank' => [20, false], 'forward' => [30, false]];
    }

    public function test_manual_completion_closes_observation_without_restarting_the_stage(): void
    {
        $decision = TaskTransition::decide($this->current(), $this->makeStatus(['id' => 1]), [
            'completed' => true,
        ], $this->date('11:00:00'));

        $this->assertTrue($decision['close_visit']);
        $this->assertEquals($this->date('11:00:00'), $decision['close_visit_at']);
        $this->assertFalse($decision['new_visit']);
        $this->assertTrue($decision['completed']);
        $this->assertFalse($decision['expired']);
        $this->assertEquals($this->date('10:00:00'), $decision['deadline']);
        $this->assertEquals($this->date('09:00:00'), $decision['status_changed_at']);
    }

    public function test_editing_an_already_completed_task_does_not_restart_observation(): void
    {
        $decision = TaskTransition::decide($this->current(['completed' => true]), $this->makeStatus(['id' => 1]), [], $this->date('11:00:00'));

        $this->assertFalse($decision['new_visit']);
        $this->assertFalse($decision['close_visit']);
        $this->assertTrue($decision['completed']);
        $this->assertFalse($decision['expired']);
    }

    #[DataProvider('reopeningDeadlines')]
    public function test_same_status_reopening_is_distinct_from_a_metadata_edit(?string $customDeadline, string $expectedDeadline): void
    {
        $patch = ['completed' => false];
        if ($customDeadline !== null) {
            $patch['deadline'] = $this->date($customDeadline);
        }
        $decision = TaskTransition::decide($this->current(['completed' => true]), $this->makeStatus(['id' => 1]), $patch, $this->date('11:00:00'));

        $this->assertTrue($decision['new_visit']);
        $this->assertTrue($decision['close_visit']);
        $this->assertFalse($decision['completed']);
        $this->assertFalse($decision['expired']);
        $this->assertFalse($decision['is_rework']);
        $this->assertEquals($this->date($expectedDeadline), $decision['deadline']);
        $this->assertEquals($this->date('11:00:00'), $decision['status_changed_at']);
    }

    public static function reopeningDeadlines(): array
    {
        return ['status SLA' => [null, '11:30:00'], 'explicit new agreement' => ['11:20:00', '11:20:00']];
    }

    public function test_a_done_stage_forces_completion_and_starts_a_closed_visit(): void
    {
        $now = $this->date('09:30:00');
        $decision = TaskTransition::decide($this->current(), $this->makeStatus(['category' => 'done']), [
            'completed' => false,
        ], $now);

        $this->assertTrue($decision['completed']);
        $this->assertFalse($decision['expired']);
        $this->assertTrue($decision['new_visit']);
        $this->assertTrue($decision['close_visit']);
        $this->assertEquals($now, $decision['visit_left_at']);
    }

    public function test_creating_an_already_completed_task_does_not_leave_an_open_visit(): void
    {
        $now = $this->date('09:00:00');
        $decision = TaskTransition::decide(null, $this->makeStatus(), ['completed' => true], $now);

        $this->assertTrue($decision['new_visit']);
        $this->assertFalse($decision['close_visit']);
        $this->assertEquals($now, $decision['visit_left_at']);
        $this->assertFalse($decision['expired']);
    }

    public function test_moving_from_done_to_work_reopens_without_needing_a_completion_flag(): void
    {
        $decision = TaskTransition::decide($this->current(['completed' => true, 'status_position' => 40]), $this->makeStatus(), [], $this->date('11:00:00'));

        $this->assertFalse($decision['completed']);
        $this->assertFalse($decision['expired']);
        $this->assertTrue($decision['new_visit']);
        $this->assertTrue($decision['is_rework']);
        $this->assertEquals($this->date('11:30:00'), $decision['deadline']);
    }

    #[DataProvider('expiryCases')]
    public function test_expiry_depends_on_completion_and_the_deadline_boundary(bool $completed, ?string $deadline, bool $expected): void
    {
        $this->assertSame($expected, TaskTransition::expired(
            $completed,
            $deadline === null ? null : $this->date($deadline),
            $this->date('10:00:00'),
        ));
    }

    public static function expiryCases(): array
    {
        return [
            'active without a known deadline' => [false, null, false],
            'active before deadline' => [false, '10:00:01', false],
            'active at deadline' => [false, '10:00:00', true],
            'active after deadline' => [false, '09:59:59', true],
            'completed without deadline' => [true, null, false],
            'completed before deadline' => [true, '10:00:01', false],
            'completed at deadline' => [true, '10:00:00', false],
            'completed after deadline' => [true, '09:59:59', false],
        ];
    }

    private function current(array $overrides = []): array
    {
        return array_replace([
            'status_id' => 1,
            'status_position' => 10,
            'completed' => false,
            'deadline' => $this->date('10:00:00'),
            'status_changed_at' => $this->date('09:00:00'),
        ], $overrides);
    }

    private function makeStatus(array $overrides = []): array
    {
        return array_replace(['id' => 2, 'position' => 20, 'category' => 'work', 'sla_minutes' => 30], $overrides);
    }

    private function date(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-11-07T'.$time.'Z');
    }
}
