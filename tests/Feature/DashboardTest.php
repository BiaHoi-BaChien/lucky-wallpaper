<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wallpaper;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_authenticated_users_can_visit_the_dashboard()
    {
        $this->actingAs($user = User::factory()->create());

        $this->get('/dashboard')->assertInertia(fn (AssertableInertia $page) => $page
            ->component('dashboard', false)
            ->has('stats')
            ->has('moonChart')
            ->missing('latestSync'));
    }

    public function test_dashboard_filters_small_prizes_and_recalculates_percentiles_with_ties(): void
    {
        $user = User::factory()->create();
        $wallpapers = collect([
            Wallpaper::factory()->create(['target_date' => '2026-08-01', 'prize_vnd' => 0]),
            Wallpaper::factory()->create(['target_date' => '2026-08-02', 'prize_vnd' => 10_000]),
            Wallpaper::factory()->create(['target_date' => '2026-08-03', 'prize_vnd' => 10_001]),
            Wallpaper::factory()->create(['target_date' => '2026-08-04', 'prize_vnd' => 100_000]),
            Wallpaper::factory()->create(['target_date' => '2026-08-05', 'prize_vnd' => 100_000]),
        ]);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-07 08:00', 'Asia/Ho_Chi_Minh'));

        try {
            $this->actingAs($user)->get('/dashboard')
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->has('moonChart', 3)
                    ->where('moonChartMinimumPrizeVnd', 10_000)
                    ->where('moonChart.0.id', $wallpapers[2]->id)
                    ->where('moonChart.0.prize_percentile', 0.3333)
                    ->where('moonChart.1.prize_percentile', 1)
                    ->where('moonChart.2.prize_percentile', 1)
                    ->where('moonChart.2.moon_phase', fn (mixed $phase): bool => is_string($phase) && $phase !== '')
                    ->missing('moonChart.0.season')
                    ->where('todayMoon.target_date', '2026-08-07')
                    ->where('todayMoon.moon_age', fn (mixed $age): bool => is_float($age))
                    ->where('todayMoon.moon_phase', fn (mixed $phase): bool => is_string($phase) && $phase !== ''));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }
}
