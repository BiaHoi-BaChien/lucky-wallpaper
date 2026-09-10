<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wallpaper;
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
            ->has('ninePalace.stars', 9)
            ->missing('moonChart')
            ->missing('moonChartMinimumPrizeVnd')
            ->missing('todayMoon')
            ->missing('latestSync'));
    }

    public function test_dashboard_groups_prizes_by_nine_star_and_composition_zone(): void
    {
        $this->travelTo('2026-07-26 12:00:00');
        $user = User::factory()->create();
        Wallpaper::factory()->create([
            'target_date' => '2026-07-26',
            'composition_zone' => 'center',
            'prize_vnd' => 1_000,
            'purchase_count' => 2,
        ]);
        Wallpaper::factory()->create([
            'target_date' => '2026-08-04',
            'composition_zone' => 'center',
            'prize_vnd' => 3_000,
            'purchase_count' => 3,
        ]);
        Wallpaper::factory()->create([
            'target_date' => '2026-08-13',
            'composition_zone' => 'top',
            'prize_vnd' => 9_000,
            'purchase_count' => null,
        ]);
        Wallpaper::factory()->create([
            'target_date' => '2026-08-14',
            'composition_zone' => null,
            'prize_vnd' => 5_000,
        ]);

        $this->actingAs($user)->get('/dashboard')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('ninePalace.todayNineStar', '八白土洞明')
            ->where('ninePalace.unclassifiedRecords', 1)
            ->where('ninePalace.stars.7.name', '八白土洞明')
            ->where('ninePalace.stars.7.zones.center.records', 2)
            ->where('ninePalace.stars.7.zones.center.averagePrizeVnd', 2_000)
            ->where('ninePalace.stars.7.zones.center.averagePrizePerTicketVnd', 750)
            ->where('ninePalace.stars.7.zones.center.heatLevel', 2)
            ->where('ninePalace.stars.7.zones.top.records', 1)
            ->where('ninePalace.stars.7.zones.top.averagePrizeVnd', 9_000)
            ->where('ninePalace.stars.7.zones.top.averagePrizePerTicketVnd', null)
            ->where('ninePalace.stars.7.zones.top.heatLevel', 4));
    }
}
