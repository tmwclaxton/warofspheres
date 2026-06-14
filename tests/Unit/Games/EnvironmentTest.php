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
        $sampleTroop = null;

        for ($slot = 0; $slot < 2; $slot++) {
            $info = $environment->drawInfo($slot, 0);

            if ($info['troops'] !== []) {
                $sampleTroop = $info['troops'][0];
                break;
            }
        }

        $this->assertNotNull($sampleTroop, 'Expected at least one visible troop for drawInfo in this fixture.');
        $info0 = $environment->drawInfo(0, 0);
        $this->assertNotEmpty($info0['cities']);
        $this->assertArrayHasKey('vision', $info0);
        $this->assertArrayHasKey('territory', $info0);
        $this->assertArrayHasKey('morale', $sampleTroop);
        $this->assertArrayHasKey('warmupMultiplier', $sampleTroop);
        $this->assertArrayHasKey('combatMultiplier', $sampleTroop);
    }

    public static function playerCounts(): array
    {
        return [
            [2],
            [4],
            [6],
        ];
    }

    public function test_assign_troop_paths_from_orders_sets_each_troop_in_one_batch(): void
    {
        $environment = Environment::create(777, 2);
        $troopIdA = $environment->players[0]->troops[0]->id;
        $troopIdB = $environment->players[1]->troops[0]->id;
        $this->assertNotSame($troopIdA, $troopIdB);

        $pathA = [[110.0, 120.0], [210.0, 220.0]];
        $pathB = [[310.0, 320.0], [410.0, 420.0]];

        $environment->assignTroopPathsFromOrders([
            [$troopIdA, $pathA],
            [$troopIdB, $pathB],
        ]);

        $this->assertSame($pathA, $environment->players[0]->troops[0]->path);
        $this->assertSame($pathB, $environment->players[1]->troops[0]->path);
    }

    public function test_assign_troop_paths_from_orders_last_row_wins_duplicate_id(): void
    {
        $environment = Environment::create(778, 2);
        $troopIdA = $environment->players[0]->troops[0]->id;
        $first = [[10.0, 10.0], [20.0, 20.0]];
        $second = [[30.0, 30.0], [40.0, 40.0]];

        $environment->assignTroopPathsFromOrders([
            [$troopIdA, $first],
            [$troopIdA, $second],
        ]);

        $this->assertSame($second, $environment->players[0]->troops[0]->path);
    }

    public function test_draw_info_always_includes_own_troops_even_when_vision_is_dark(): void
    {
        $environment = Environment::create(321, 2);
        $player = $environment->players[0];
        $maxX = $environment->gridMaxX;
        $maxY = $environment->gridMaxY;

        for ($x = 0; $x <= $maxX; $x++) {
            $player->vision->grid[$x] = array_fill(0, $maxY + 1, 0.0);
        }

        $info = $environment->drawInfo(0, 0);
        $ownTroops = array_values(array_filter(
            $info['troops'],
            static fn (array $t): bool => ($t['ownerSlot'] ?? -1) === 0,
        ));

        $this->assertNotEmpty($ownTroops, 'Own troops must be visible to the owning player regardless of fog sampling.');
    }

    public function test_from_array_skips_procedural_generation_and_round_trips(): void
    {
        $original = Environment::create(4242, 2);
        $payload = $original->toArray();

        $restored = Environment::fromArray($payload);

        $this->assertSame($original->gridMaxX, $restored->gridMaxX);
        $this->assertSame($original->gridMaxY, $restored->gridMaxY);
        $this->assertCount(count($original->cities), $restored->cities);
        $this->assertCount(count($original->players), $restored->players);
    }

    public function test_from_array_tolerates_absurd_grid_metadata_from_json_floats(): void
    {
        $original = Environment::create(7, 2);
        $payload = $original->toArray();
        $payload['gridMaxX'] = 3.478395492209091E+27;
        $payload['gridMaxY'] = 1.5;

        $restored = Environment::fromArray($payload);

        $this->assertGreaterThanOrEqual(1, $restored->gridMaxX);
        $this->assertGreaterThanOrEqual(1, $restored->gridMaxY);
        $this->assertLessThanOrEqual(4096, $restored->gridMaxX);
        $this->assertLessThanOrEqual(4096, $restored->gridMaxY);
    }

    public function test_troop_at_own_capital_is_in_own_territory(): void
    {
        $environment = Environment::create(1001, 2);
        $player = $environment->players[0];
        $troop = $player->troops[0];

        // Run one tick so border brushes are stamped from both troops and cities.
        $environment->updateTroops([], 1);

        $info = $environment->drawInfo(0, 1);
        $cs = GameConstants::CELL_SIZE;
        $gx = (int) ($troop->position[0] / $cs);
        $gy = (int) ($troop->position[1] / $cs);

        $this->assertSame($player->slot, $info['territory'][$gx][$gy],
            'A troop at its own capital should occupy territory owned by its player.');
    }

    public function test_troop_heals_in_own_territory_when_not_in_combat(): void
    {
        $environment = Environment::create(1004, 2);
        $player = $environment->players[0];
        $troop = $player->troops[0];

        // Damage the troop so healing is observable.
        $troop->health = $troop->maxHealth() - 10;
        $troop->path = [];

        $environment->updateTroops([], 1);

        $this->assertGreaterThan($troop->maxHealth() - 10, $troop->health,
            'A damaged troop resting in its own territory should gain 1 HP per tick.');
    }

    public function test_troop_does_not_heal_in_enemy_territory(): void
    {
        $environment = Environment::create(1005, 2);
        $player0 = $environment->players[0];
        $player1 = $environment->players[1];

        $troop = $player0->troops[0];
        $troop->health = $troop->maxHealth() - 10;
        $initialHealth = $troop->health;

        // Move player 1's troop far away so it cannot attack player 0's troop.
        foreach ($player1->troops as $enemyTroop) {
            $enemyTroop->position = $player0->startPos;
        }

        // Teleport the troop deep into the enemy's starting area so it lands in
        // enemy territory after the tick's border brushes are applied.
        $troop->position = $player1->startPos;
        $troop->path = [];

        $environment->updateTroops([], 1);

        $this->assertSame($initialHealth, $troop->health,
            'A damaged troop in enemy territory should not heal.');
    }

    public function test_encircled_troop_loses_extra_morale_when_in_combat(): void
    {
        $environment = Environment::create(1006, 2);
        $player0 = $environment->players[0];
        $player1 = $environment->players[1];

        $attacker = $player0->troops[0];
        $defender = $player1->troops[0];

        // Place the attacker on the enemy's start position so it is in enemy territory,
        // and place the defender within combat range alongside it.
        $attacker->position = $player1->startPos;
        $attacker->path = [];
        $attacker->morale = 85;

        $defender->position = [
            $player1->startPos[0] + GameConstants::TROOP_HIT_RANGE - 1,
            $player1->startPos[1],
        ];
        $defender->path = [];

        $environment->updateTroops([], 1);

        // Combined drain: TROOP_MORALE_COMBAT_DRAIN (0.35) + TROOP_SUPPLY_CUT_MORALE_DRAIN (0.5) = 0.85,
        // which rounds to a real drain of 1 from 85 → 84.
        $this->assertLessThan(85, $attacker->morale,
            'An encircled troop in active combat should drain more morale than just combat alone.');
    }
}
