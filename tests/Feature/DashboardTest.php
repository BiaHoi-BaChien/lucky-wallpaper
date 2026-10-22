<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wallpaper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\KigakuFixtures as Fixtures;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_empty_dashboard_has_all_groups_and_no_averages(): void
    {
        $this->actingAs(User::factory()->create())->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')->has('directionStats', 11)
            ->where('latestWallpaperDate', null)
            ->where('stats.wallpapers', 0)->where('directionStats.0.average_prize', null)
            ->where('directionStats.0.prize_per_ticket', null)->missing('ninePalace'));
    }

    public function test_latest_wallpaper_shortcut_uses_target_date_even_with_a_registered_result(): void
    {
        $latest = Wallpaper::factory()->create([
            'target_date' => '2026-10-22', 'prize_vnd' => 0, 'created_at' => '2026-10-20 12:00:00',
        ]);
        Wallpaper::factory()->create([
            'target_date' => '2026-10-21', 'created_at' => '2026-10-21 12:00:00',
        ]);

        $this->actingAs(User::factory()->create())->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('latestWallpaperDate', '2026-10-22'));

        $this->get(route('results.index', ['date' => '2026-10-22']))->assertInertia(fn (Assert $page) => $page
            ->component('results/index')->where('selectedDate', '2026-10-22')
            ->where('wallpaper.id', $latest->id)->where('wallpaper.prize_vnd', 0));
    }

    public function test_counts_only_primary_direction_and_uses_weighted_ticket_totals(): void
    {
        Fixtures::wallpaper('2026-10-01', attributes: ['prize_vnd' => 1000, 'purchase_count' => 1]);
        Fixtures::wallpaper('2026-10-02', attributes: ['prize_vnd' => 9000, 'purchase_count' => 9]);
        Fixtures::wallpaper('2026-10-03', attributes: ['prize_vnd' => null, 'purchase_count' => 100]);
        Fixtures::wallpaper('2026-10-04', attributes: ['prize_vnd' => 0, 'purchase_count' => 2]);
        $selected = Fixtures::wallpaper('2026-10-05', attributes: ['prize_vnd' => 10000, 'purchase_count' => null]);
        $unselected = $selected->chosenProposal->replicate();
        $unselected->sequence = 2;
        $unselected->status = 'proposed';
        $context = $unselected->calendar_context;
        $context['kigaku']['primary_direction'] = 'east';
        $unselected->calendar_context = $context;
        $unselected->save();
        $this->actingAs(User::factory()->create())->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('stats.wallpapers', 5)->where('stats.result_count', 4)->where('stats.total_prize_vnd', 20000)
            ->where('directionStats.0.wallpapers', 5)->where('directionStats.0.result_count', 4)
            ->where('directionStats.0.prize_total', 20000)->where('directionStats.0.average_prize', 5000)
            ->where('directionStats.0.ticket_result_count', 3)->where('directionStats.0.ticket_total', 12)
            ->where('directionStats.0.prize_per_ticket', 833)->where('directionStats.2.wallpapers', 0));
    }

    public function test_fallback_undetermined_and_legacy_are_separate_groups(): void
    {
        Fixtures::wallpaper('2026-10-01', status: 'none', attributes: ['prize_vnd' => 0]);
        Fixtures::wallpaper('2026-10-02', status: 'undetermined', attributes: ['prize_vnd' => 4000]);
        Wallpaper::factory()->create(['target_date' => '2026-10-03', 'composition_zone' => 'top', 'prize_vnd' => 1000]);
        $unapproved = Fixtures::wallpaper('2026-10-04', attributes: ['prize_vnd' => 2000]);
        $unapproved->chosenProposal->update(['status' => 'proposed']);
        $this->actingAs(User::factory()->create())->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('directionStats.0.wallpapers', 0)
            ->where('directionStats.8.wallpapers', 1)->where('directionStats.8.result_count', 1)
            ->where('directionStats.9.prize_total', 4000)->where('directionStats.10.wallpapers', 2)
            ->where('directionStats.10.prize_total', 3000));
    }

    public function test_dashboard_always_aggregates_all_periods_even_with_legacy_date_parameters(): void
    {
        Fixtures::wallpaper('2026-09-30', attributes: ['prize_vnd' => 99999]);
        Fixtures::wallpaper('2026-10-01', attributes: ['prize_vnd' => 100]);
        Fixtures::wallpaper('2026-10-02', direction: 'east', attributes: ['prize_vnd' => 200]);
        Fixtures::wallpaper('2026-10-03', attributes: ['prize_vnd' => 99999]);
        $this->actingAs(User::factory()->create());
        foreach ([
            '/dashboard?date_from=2026-10-01&date_to=2026-10-02',
            '/dashboard?date_from=2026-10-03&date_to=2026-10-01',
            '/dashboard?date_from=invalid&date_to=invalid',
        ] as $url) {
            $this->get($url)->assertInertia(fn (Assert $page) => $page
                ->missing('filters')->where('latestWallpaperDate', '2026-10-03')
                ->where('stats.wallpapers', 4)->where('stats.result_count', 4)->where('stats.total_prize_vnd', 200298)
                ->where('directionStats.0.prize_total', 200098)->where('directionStats.2.prize_total', 200));
        }
    }

    public function test_history_rejects_invalid_period_and_direction(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/wallpapers?date_from=2026-10-03&date_to=2026-10-01')->assertSessionHasErrors('date_to');
        $this->get('/wallpapers?direction=invalid')->assertSessionHasErrors('direction');
        $this->get('/wallpapers?date_from=invalid')->assertSessionHasErrors('date_from');
    }

    public function test_history_filters_by_direction_and_period(): void
    {
        $north = Fixtures::wallpaper('2026-10-01');
        Fixtures::wallpaper('2026-10-02', direction: 'east');
        Fixtures::wallpaper('2026-09-30');
        Fixtures::wallpaper('2026-10-03', status: 'none');
        Fixtures::wallpaper('2026-10-04', status: 'undetermined');
        $legacy = Wallpaper::factory()->create(['target_date' => '2026-10-05']);
        $this->actingAs(User::factory()->create())->get('/wallpapers?direction=north&date_from=2026-10-01&date_to=2026-10-31')
            ->assertInertia(fn (Assert $page) => $page->component('wallpapers/index')->has('wallpapers.data', 1)
                ->where('wallpapers.data.0.id', $north->id)->where('wallpapers.data.0.direction_label', '北')
                ->where('filters.direction', 'north'));
        foreach (['none' => '吉方位なし', 'undetermined' => '判定不能', 'legacy' => '方位情報なし'] as $key => $label) {
            $this->get('/wallpapers?direction='.$key)->assertInertia(fn (Assert $page) => $page
                ->has('wallpapers.data', 1)->where('wallpapers.data.0.direction_label', $label));
        }
        $this->get('/wallpapers?direction=legacy')->assertInertia(fn (Assert $page) => $page->where('wallpapers.data.0.id', $legacy->id));
    }

    public function test_another_wallpapers_proposal_cannot_supply_the_primary_direction(): void
    {
        $owner = Fixtures::wallpaper('2026-10-01');
        $other = Wallpaper::factory()->create(['target_date' => '2026-10-02', 'chosen_proposal_id' => $owner->chosen_proposal_id]);
        $this->actingAs(User::factory()->create())->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('directionStats.0.wallpapers', 1)->where('directionStats.10.wallpapers', 1));
        $this->get('/wallpapers?direction=legacy')->assertInertia(fn (Assert $page) => $page->where('wallpapers.data.0.id', $other->id));
    }
}
