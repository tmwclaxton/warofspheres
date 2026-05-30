<?php

namespace Tests\Unit\Games;

use App\Games\Engine\Environment;
use App\Games\GameConstants;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EnvironmentTest extends TestCase
{
    #[DataProvider('playerCounts')]
    public function test_environment_generates_for_player_counts(int $count): void
    {
        $environment = Environment::create(12345, $count);

        $this->assertCount(10, $environment->cities);
        $this->assertCount($count, $environment->players);

        foreach ($environment->players as $index => $player) {
            $this->assertSame($index, $player->slot);
            $this->assertNotEmpty($player->troops);
        }
    }

    public function test_draw_info_includes_spawn_troops_before_first_tick(): void
    {
        $environment = Environment::create(999, 2);
        $info = $environment->drawInfo(0);

        $this->assertNotEmpty($info['cities']);
        $this->assertArrayHasKey('vision', $info);
        $this->assertArrayHasKey('border', $info);
    }

    public static function playerCounts(): array
    {
        return [
            [2],
            [4],
            [6],
        ];
    }
}
