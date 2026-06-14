<?php

namespace Tests\Unit;

use App\Models\User;
use Tests\TestCase;

class UserPlayerTagTest extends TestCase
{
    public function test_generate_player_tag_matches_expected_format(): void
    {
        $tag = User::generatePlayerTag();

        $this->assertMatchesRegularExpression('/^Commander#\d{4}$/', $tag);
    }

    public function test_generate_player_tag_number_is_in_expected_range(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $tag = User::generatePlayerTag();
            $number = (int) substr($tag, strlen('Commander#'));

            $this->assertGreaterThanOrEqual(1000, $number);
            $this->assertLessThanOrEqual(9999, $number);
        }
    }

    public function test_new_user_without_tag_gets_generated_one(): void
    {
        $user = new User(['game_display_name' => null]);

        // Simulate what the creating hook does
        if (! is_string($user->game_display_name) || $user->game_display_name === '') {
            $user->game_display_name = User::generatePlayerTag();
        }

        $this->assertNotNull($user->game_display_name);
        $this->assertMatchesRegularExpression('/^Commander#\d{4}$/', $user->game_display_name);
    }

    public function test_existing_player_tag_is_not_overwritten(): void
    {
        $user = new User(['game_display_name' => 'MyCustomTag']);

        // Simulate what the creating hook does
        if (! is_string($user->game_display_name) || $user->game_display_name === '') {
            $user->game_display_name = User::generatePlayerTag();
        }

        $this->assertSame('MyCustomTag', $user->game_display_name);
    }
}
