<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LobbiesTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get(route('lobbies.index'))
            ->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_lobbies_page(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('lobbies.index'))
            ->assertOk();
    }

    public function test_authenticated_users_can_visit_the_map_builder_page(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('map-builder'))
            ->assertOk();
    }

    public function test_authenticated_users_can_visit_match_history_pages(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('matches.ongoing'))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('matches.past'))
            ->assertOk();
    }
}
