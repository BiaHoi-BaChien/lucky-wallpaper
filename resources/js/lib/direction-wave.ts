export interface DirectionStats {
    key: string;
    label: string;
    wallpapers: number;
    result_count: number;
    prize_total: number;
    average_prize: number | null;
    prize_per_ticket: number | null;
    ticket_result_count: number;
}

export const DIRECTIONS = [
    { key: 'north', label: '北' },
    { key: 'northeast', label: '北東' },
    { key: 'east', label: '東' },
    { key: 'southeast', label: '南東' },
    { key: 'south', label: '南' },
    { key: 'southwest', label: '南西' },
    { key: 'west', label: '西' },
    { key: 'northwest', label: '北西' },
] as const;

const METRICS = [
    { key: 'result_count', label: '実績登録件数', unit: '件', color: 'var(--chart-2)' },
    { key: 'prize_total', label: '賞金合計', unit: 'VND', color: 'var(--chart-3)' },
    { key: 'average_prize', label: '1日平均賞金', unit: 'VND', color: 'var(--chart-4)' },
    { key: 'prize_per_ticket', label: '1口あたり賞金', unit: 'VND', color: 'var(--chart-5)' },
] as const;

export function buildDirectionWaves(stats: DirectionStats[]) {
    const rows = new Map(stats.map((row) => [row.key, row]));

    return METRICS.map((metric) => {
        const values = DIRECTIONS.map(({ key }) => rows.get(key)?.[metric.key] ?? 0);
        const maximum = Math.max(0, ...values);

        return {
            ...metric,
            values,
            normalized: values.map((value) => (maximum === 0 ? 0 : value / maximum)),
            maximum,
        };
    });
}
