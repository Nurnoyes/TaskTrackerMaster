<?php

namespace App\Domain\Tasks;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class TaskTransition
{
    /**
     * Dates are absolute instants; persistence uses UTC with second precision.
     * The caller supplies the position recorded at entry, rather than a renamed
     * or reordered status's current position.
     *
     * @param  array{status_id: int, status_position: int, completed: bool, deadline: ?DateTimeImmutable, status_changed_at: ?DateTimeImmutable}|null  $current
     * @param  array{id: int, position: int, category: string, sla_minutes: int}  $status
     * @param  array{completed?: bool, deadline?: DateTimeImmutable}  $patch
     * @return array{new_visit: bool, close_visit: bool, close_visit_at: ?DateTimeImmutable, deadline: ?DateTimeImmutable, status_changed_at: ?DateTimeImmutable, completed: bool, expired: bool, is_rework: bool, visit_left_at: ?DateTimeImmutable}
     */
    public static function decide(?array $current, array $status, array $patch, DateTimeImmutable $now): array
    {
        $now = self::atSecond($now);
        $statusChanged = $current !== null && $current['status_id'] !== $status['id'];
        $completed = $status['category'] === 'done'
            || ($patch['completed'] ?? ($statusChanged ? false : ($current['completed'] ?? false)));
        $reopened = $current !== null && $current['completed'] && ! $completed;
        $newVisit = $current === null || $statusChanged || $reopened;
        $closeVisit = $current !== null && ($newVisit || ($completed && ! $current['completed']));

        if (array_key_exists('deadline', $patch) && ! $newVisit) {
            throw new InvalidArgumentException('Срок можно задать только при смене статуса или повторном открытии задачи.');
        }

        $deadline = $current['deadline'] ?? null;
        if ($newVisit) {
            $deadline = array_key_exists('deadline', $patch)
                ? self::atSecond($patch['deadline'])
                : self::atTimestamp($now->getTimestamp() + $status['sla_minutes'] * 60);

            if ($deadline <= $now) {
                throw new InvalidArgumentException('Срок должен быть в будущем.');
            }
        }

        return [
            'new_visit' => $newVisit,
            'close_visit' => $closeVisit,
            'close_visit_at' => $closeVisit ? $now : null,
            'deadline' => $deadline,
            'status_changed_at' => $newVisit ? $now : $current['status_changed_at'],
            'completed' => $completed,
            'expired' => self::expired($completed, $deadline, $now),
            'is_rework' => $statusChanged && $status['position'] < $current['status_position'],
            'visit_left_at' => $newVisit && $completed ? $now : null,
        ];
    }

    public static function expired(bool $completed, ?DateTimeImmutable $deadline, DateTimeImmutable $now): bool
    {
        return ! $completed && $deadline !== null && $deadline->getTimestamp() <= $now->getTimestamp();
    }

    private static function atSecond(DateTimeImmutable $date): DateTimeImmutable
    {
        return self::atTimestamp($date->getTimestamp());
    }

    private static function atTimestamp(int $timestamp): DateTimeImmutable
    {
        return (new DateTimeImmutable('@'.$timestamp))->setTimezone(new DateTimeZone('UTC'));
    }
}
