<?php

namespace Tests\Unit;

use App\Services\KigakuCalendar;
use App\Services\KigakuDetermination;
use App\Services\KigakuPolicy;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class KigakuDeterminationTest extends TestCase
{
    public function test_fixed_board_geometry_exclusions_and_candidate_order(): void
    {
        $rules = app(KigakuDetermination::class);
        $five = $rules->evaluate(['center_star' => 5, 'branch' => 1]);
        $this->assertSame(['north' => 1, 'northeast' => 8, 'east' => 3, 'southeast' => 4,
            'south' => 9, 'southwest' => 2, 'west' => 7, 'northwest' => 6], $five['stars']);
        $this->assertSame(['north', 'northeast', 'west'], $five['candidate_directions']);
        $this->assertSame(['本命的殺'], $five['excluded']['southeast']);
        $this->assertSame(['破'], $five['excluded']['southwest']);
        $this->assertNotContains('五黄殺', array_merge(...array_values($five['excluded'])));
        $this->assertNotContains('暗剣殺', array_merge(...array_values($five['excluded'])));
        $six = $rules->evaluate(['center_star' => 6, 'branch' => 2]);
        $this->assertNotContains('本命殺', array_merge(...array_values($six['excluded'])));
        $this->assertNotContains('本命的殺', array_merge(...array_values($six['excluded'])));
        $this->assertSame(['五黄殺'], $six['excluded']['southeast']);
        $this->assertSame(['暗剣殺'], $six['excluded']['northwest']);
        $this->assertSame(['破'], $six['excluded']['southwest']);
    }

    public function test_every_possible_board_obeys_friendly_stars_and_exclusions(): void
    {
        $rules = app(KigakuDetermination::class);
        for ($star = 1; $star <= 9; $star++) {
            for ($branch = 0; $branch < 12; $branch++) {
                $board = $rules->evaluate(['center_star' => $star, 'branch' => $branch]);
                $this->assertSame(array_values(array_intersect(array_keys(KigakuPolicy::DIRECTIONS), $board['candidate_directions'])), $board['candidate_directions']);
                foreach ($board['candidate_directions'] as $direction) {
                    $this->assertContains($board['stars'][$direction], [2, 8, 7, 1]);
                    $this->assertArrayNotHasKey($direction, $board['excluded']);
                }
            }
        }
    }

    #[DataProvider('boardPriority')]
    public function test_only_the_first_usable_board_is_selected(array $emptyBoards, ?string $selected): void
    {
        $calendar = Mockery::mock(KigakuCalendar::class);
        foreach ($emptyBoards as $type) {
            $calendar->shouldReceive('board')->once()->with('2026-10-22', $type)->ordered()->andReturn(['center_star' => 2, 'branch' => 10]);
        }
        if ($selected !== null) {
            $calendar->shouldReceive('board')->once()->with('2026-10-22', $selected)->ordered()->andReturn(['center_star' => 5, 'branch' => 1]);
        }
        $result = (new KigakuDetermination($calendar))->determine('2026-10-22');
        $this->assertSame($selected, $result['selected_board']);
        $this->assertSame($selected === null ? 'none' : 'auspicious', $result['status']);
        $this->assertSame($selected === null ? [] : ['north', 'northeast', 'west'], $result['candidate_directions']);
        $this->assertSame($selected === null ? null : 'north', $result['primary_direction']);
        $this->assertSame($selected === null ? null : 1, $result['direction_star']);
        $this->assertSame(KigakuPolicy::position($result['primary_direction']), $result['subject_position']);
    }

    public static function boardPriority(): array
    {
        return [[[], 'day'], [['day'], 'month'], [['day', 'month'], 'year'], [['day', 'month', 'year'], null]];
    }

    #[DataProvider('requiredFailures')]
    public function test_unavailable_required_board_stops_without_trying_a_lower_board(string $failed, array $emptyBoards): void
    {
        $calendar = Mockery::mock(KigakuCalendar::class);
        foreach ($emptyBoards as $type) {
            $calendar->shouldReceive('board')->once()->with('2026-10-22', $type)->ordered()->andReturn(['center_star' => 2, 'branch' => 10]);
        }
        $calendar->shouldReceive('board')->once()->with('2026-10-22', $failed)->ordered()->andThrow(new \RuntimeException('calendar unavailable'));
        $result = (new KigakuDetermination($calendar))->determine('2026-10-22');
        $this->assertSame('undetermined', $result['status']);
        $this->assertSame([], $result['candidate_directions']);
        $this->assertNull($result['selected_board']);
        $this->assertNull($result['direction_star']);
        $this->assertSame(['x_percent' => 50, 'y_percent' => 55], $result['subject_position']);
    }

    public static function requiredFailures(): array
    {
        return [['day', []], ['month', ['day']], ['year', ['day', 'month']]];
    }

    public function test_positions_are_fixed_in_screen_coordinates(): void
    {
        $expected = [[50, 40], [70, 40], [70, 55], [70, 70], [50, 70], [30, 70], [30, 55], [30, 40]];
        foreach (array_keys(KigakuPolicy::DIRECTIONS) as $i => $direction) {
            $this->assertSame(['x_percent' => $expected[$i][0], 'y_percent' => $expected[$i][1]], KigakuPolicy::position($direction));
        }
        $this->assertSame(['x_percent' => 50, 'y_percent' => 55], KigakuPolicy::position(null));
    }
}
