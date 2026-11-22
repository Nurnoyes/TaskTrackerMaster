<?php

namespace App\Domain\ProcessDebt;

/** Pure presentation of measured SLA results; no claims of causal productivity loss. */
final class ProcessDebtInsights
{
    public function describe(array $summary, array $statuses, array $types, array $bottlenecks): array
    {
        if ($summary['total_seconds'] === 0) {
            return ['Пока недостаточно времени наблюдения. Аналитика появится по мере движения задач между статусами.'];
        }

        $insights = [
            sprintf('Сверх согласованных сроков этапов прошло %s%% наблюдаемого времени. Это Process Debt по SLA, а не измерение личной продуктивности.', $summary['debt_percent']),
        ];
        $waitingStatuses = array_values(array_filter($statuses, fn (array $status) => $status['waiting_seconds'] > 0));
        usort($waitingStatuses, fn (array $first, array $second) => $second['waiting_seconds'] <=> $first['waiting_seconds']);
        if ($waitingStatuses !== []) {
            $status = $waitingStatuses[0];
            $share = round($status['waiting_seconds'] / $summary['total_seconds'] * 100, 2);
            $insights[] = sprintf('В ожидании в статусе «%s» задачи провели %s%% всего наблюдаемого времени. Это кандидат на проверку узкого места.', $status['name'], $share);
        }
        $typesByName = array_column($types, null, 'type');
        foreach ($bottlenecks as $bottleneck) {
            $type = $typesByName[$bottleneck['type']];
            $insights[] = sprintf('Задачи типа «%s» провели %s%% времени в ожидании в статусе «%s». Доля всех ожиданий для этого типа — %s%%.', $bottleneck['type'], $bottleneck['time_percent'], $bottleneck['status_name'], $type['waiting_percent']);
        }
        if ($summary['rework_count'] > 0) {
            $insights[] = sprintf('Зафиксировано возвратов к более ранним этапам: %d. Их время уже учтено в общей длительности и не прибавляется повторно к долгу.', $summary['rework_count']);
        }

        return $insights;
    }
}
