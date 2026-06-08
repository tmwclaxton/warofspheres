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

    public function test_admin_users_can_view_the_overview_page(): void
    {
        $user = User::factory()->create(['email' => 'tmwclaxton@gmail.com']);

        $this->actingAs($user)
            ->get(route('admin.overview'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/Overview')
                ->has('stats.summary', fn (Assert $summary) => $summary
                    ->where('totalUsers', 1284)
                    ->where('activeGames', 17)
                    ->where('matchesToday', 42)
                    ->where('publishedMaps', 86)
                )
                ->has('stats.topMaps', 5)
                ->has('stats.recentActivity', 6)
            );
    }
}
