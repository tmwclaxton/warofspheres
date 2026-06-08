<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class EnsureUserIsAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['auth', 'admin'])
            ->get('/_test/admin', fn () => response('admin'));
    }

    public function test_allows_configured_admin_emails(): void
    {
        foreach (['tmwclaxton@gmail.com', 'toby@grantgunner.org'] as $email) {
            $user = User::factory()->create(['email' => $email]);

            $this->actingAs($user)
                ->get('/_test/admin')
                ->assertOk()
                ->assertSee('admin');
        }
    }

    public function test_forbids_non_admin_users(): void
    {
        $user = User::factory()->create(['email' => 'someone@example.com']);

        $this->actingAs($user)
            ->get('/_test/admin')
            ->assertForbidden();
    }

    public function test_forbids_guests(): void
    {
        $this->get('/_test/admin')
            ->assertRedirect(route('login'));
    }
}
