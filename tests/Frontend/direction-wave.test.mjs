import assert from 'node:assert/strict';
import test from 'node:test';

import { buildDirectionWaves, DIRECTIONS } from '../../resources/js/lib/direction-wave.ts';

function row(key, values = {}) {
    return {
        key,
        label: key,
        wallpapers: 0,
        result_count: 0,
        prize_total: 0,
        average_prize: null,
        prize_per_ticket: null,
        ticket_result_count: 0,
        ...values,
    };
}

test('uses eight clockwise directions and ignores rows without an adopted direction', () => {
    const keys = ['north', 'northeast', 'east', 'southeast', 'south', 'southwest', 'west', 'northwest'];
    const stats = keys.map((key, index) => row(key, { wallpapers: index + 1, result_count: index + 1 })).reverse();
    stats.push(...['legacy', 'none', 'undetermined'].map((key) => row(key, { wallpapers: 1000, result_count: 1000 })));

    const waves = buildDirectionWaves(stats);

    assert.deepEqual(
        DIRECTIONS.map(({ key }) => key),
        keys,
    );
    assert.deepEqual(
        DIRECTIONS.map(({ label }) => label),
        ['北', '北東', '東', '南東', '南', '南西', '西', '北西'],
    );
    assert.deepEqual(
        waves.map(({ key }) => key),
        ['result_count', 'prize_total', 'average_prize', 'prize_per_ticket'],
    );
    assert.deepEqual(waves[0].values, [1, 2, 3, 4, 5, 6, 7, 8]);
    assert.equal(waves[0].maximum, 8);
});

test('normalizes every metric against its own eight-direction maximum', () => {
    const waves = buildDirectionWaves([
        row('north', { wallpapers: 4, result_count: 2, prize_total: 1000000, average_prize: 500000, prize_per_ticket: 250000 }),
        row('east', { wallpapers: 8, result_count: 4, prize_total: 4000000, average_prize: 1000000, prize_per_ticket: 1000000 }),
    ]);

    assert.deepEqual(
        waves.map(({ maximum }) => maximum),
        [4, 4000000, 1000000, 1000000],
    );
    assert.deepEqual(
        waves.map(({ normalized }) => normalized[0]),
        [0.5, 0.25, 0.5, 0.25],
    );
    assert.deepEqual(
        waves.map(({ normalized }) => normalized[2]),
        [1, 1, 1, 1],
    );
    assert.deepEqual(
        waves.map(({ unit }) => unit),
        ['件', 'VND', 'VND', 'VND'],
    );
    assert.deepEqual(
        waves.map(({ color }) => color),
        [2, 3, 4, 5].map((index) => `var(--chart-${index})`),
    );
});

test('draws missing monetary data as zero without changing the source stats', () => {
    const stats = [
        row('north', { wallpapers: 1 }),
        row('east', { wallpapers: 1, result_count: 1, average_prize: 0, prize_per_ticket: 0, ticket_result_count: 1 }),
        row('south', { wallpapers: 1, result_count: 1, prize_total: 1000, average_prize: 1000 }),
    ];
    const waves = buildDirectionWaves(stats);

    assert.deepEqual(waves[0].values, [0, 0, 1, 0, 1, 0, 0, 0]);
    assert.deepEqual(waves[1].values, [0, 0, 0, 0, 1000, 0, 0, 0]);
    assert.deepEqual(waves[2].values, [0, 0, 0, 0, 1000, 0, 0, 0]);
    assert.deepEqual(waves[3].values, Array(8).fill(0));
    assert.deepEqual(waves[0].normalized, waves[0].values);
    assert.deepEqual(waves[2].normalized, [0, 0, 0, 0, 1, 0, 0, 0]);
    assert.deepEqual(waves[3].normalized, waves[3].values);
    assert.equal(stats[0].average_prize, null);
    assert.equal(stats[2].prize_per_ticket, null);
});

test('handles empty data and all-zero registered data without invalid normalized values', () => {
    const empty = buildDirectionWaves([]);

    assert.deepEqual(
        empty.map(({ values }) => values),
        Array.from({ length: 4 }, () => Array(8).fill(0)),
    );
    assert.ok(empty.every(({ maximum }) => maximum === 0));
    assert.ok(empty.every(({ values, normalized }) => JSON.stringify(values) === JSON.stringify(normalized)));

    const zeros = buildDirectionWaves(DIRECTIONS.map(({ key }) => row(key, { result_count: 1, average_prize: 0, prize_per_ticket: 0 })));

    for (const wave of zeros) {
        assert.deepEqual(wave.normalized, Array(8).fill(wave.key === 'result_count' ? 1 : 0));
    }
});
