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
    const stats = keys.map((key, index) => row(key, { wallpapers: index + 1 })).reverse();
    stats.push(...['legacy', 'none', 'undetermined'].map((key) => row(key, { wallpapers: 1000 })));

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
        ['wallpapers', 'result_count', 'prize_total', 'average_prize', 'prize_per_ticket'],
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
        [8, 4, 4000000, 1000000, 1000000],
    );
    assert.deepEqual(
        waves.map(({ normalized }) => normalized[0]),
        [0.5, 0.5, 0.25, 0.5, 0.25],
    );
    assert.deepEqual(
        waves.map(({ normalized }) => normalized[2]),
        [1, 1, 1, 1, 1],
    );
    assert.deepEqual(
        waves.map(({ unit }) => unit),
        ['件', '件', 'VND', 'VND', 'VND'],
    );
    assert.deepEqual(
        waves.map(({ color }) => color),
        [1, 2, 3, 4, 5].map((index) => `var(--chart-${index})`),
    );
});

test('keeps unregistered monetary values distinct from a registered zero prize', () => {
    const waves = buildDirectionWaves([
        row('north', { wallpapers: 1 }),
        row('east', { wallpapers: 1, result_count: 1, average_prize: 0, prize_per_ticket: 0, ticket_result_count: 1 }),
        row('south', { wallpapers: 1, result_count: 1, average_prize: 0 }),
    ]);

    assert.deepEqual(waves[2].values, [null, null, 0, null, 0, null, null, null]);
    assert.deepEqual(waves[3].values, [null, null, 0, null, 0, null, null, null]);
    assert.deepEqual(waves[4].values, [null, null, 0, null, null, null, null, null]);
    assert.deepEqual(waves[2].normalized, waves[2].values);
    assert.deepEqual(waves[4].normalized, waves[4].values);
});

test('handles empty data and all-zero registered data without invalid normalized values', () => {
    const empty = buildDirectionWaves([]);

    assert.deepEqual(
        empty.slice(0, 2).map(({ values }) => values),
        [Array(8).fill(0), Array(8).fill(0)],
    );
    assert.deepEqual(
        empty.slice(2).map(({ values }) => values),
        Array.from({ length: 3 }, () => Array(8).fill(null)),
    );
    assert.ok(empty.every(({ maximum }) => maximum === 0));
    assert.ok(empty.every(({ values, normalized }) => JSON.stringify(values) === JSON.stringify(normalized)));

    const zeros = buildDirectionWaves(DIRECTIONS.map(({ key }) => row(key, { result_count: 1, average_prize: 0, prize_per_ticket: 0 })));

    for (const wave of zeros) {
        assert.deepEqual(wave.normalized, Array(8).fill(wave.key === 'result_count' ? 1 : 0));
    }
});
