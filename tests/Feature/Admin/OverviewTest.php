<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OverviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('admin.overview'))
            ->assertRedirect(route('login'));
    }

    public function test_non_admin_users_are_forbidden(): void
    {
        $user = User::factory()->create(['email' => 'someone@example.com']);

        $this->actingAs($user)
            ->get(route('admin.overview'))
            ->assertForbidden();
    }

    public function test_admin_flag_is_shared_with_inertia_for_admin_users(): void
    {
        $user = User::factory()->create(['email' => 'toby@grantgunner.org']);

        $this->actingAs($user)
            ->get(route('home'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.isAdmin', true)
            );
    }

    public function test_admin_flag_is_false_for_non_admin_users(): void
    {
        $user = User::factory()->create(['email' => 'someone@example.com']);

        $this->actingAs($user)
            ->get(route('home'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.isAdmin', false)
            );
    }

    public function test_admin_users_can_view_the_overview_page(): void
    {
        $user = User::factory()->create(['email' => 'tmwclaxton@gmail.com']);

        $this->actingAs($user)
            ->get(route('admin.overview'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/Overview')
                ->has('stats.summary', fn (Assert $summary) => $summary
                    ->hasAll(['totalUsers', 'activeGames', 'matchesToday', 'publishedMaps'])
                    ->whereAllType([
                        'totalUsers' => 'integer',
                        'activeGames' => 'integer',
                        'matchesToday' => 'integer',
                        'publishedMaps' => 'integer',
                    ])
                )
                ->has('stats.games', fn (Assert $games) => $games
                    ->hasAll(['lobby', 'playing', 'finished'])
                )
                ->has('stats.maps', fn (Assert $maps) => $maps
                    ->hasAll(['total', 'published', 'draft', 'forks', 'votes'])
                )
                ->has('stats.engagement', fn (Assert $eng) => $eng
                    ->hasAll(['avgPlayersPerGame', 'avgGameDurationMinutes', 'returningPlayersPercent', 'newUsersThisWeek'])
                )
                ->has('stats.topMaps')
                ->has('stats.recentActivity')
            );
    }
}
