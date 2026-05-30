<?php

namespace App\Games\Engine;

use App\Games\GameConstants;

final class Environment
{
    public MarchingSquares $terrainMarching;

    public MarchingSquares $forestMarching;

    /** @var list<City> */
    public array $cities = [];

    /** @var list<list<float>> */
    public array $defaultVision = [];

    /** @var list<Player> */
    public array $players = [];

    /** @var list<list<Player>> */
    public array $playersInCities = [];

    public Brush $visionBrush;

    public Brush $cityVisionBrush;

    public Brush $borderBrush;

    public Brush $cityBorderBrush;

    private int $nextCityId = 1;

    private int $nextTroopId = 1;

    private int $rngSeed;

    public function __construct(private int $seed, private int $playerCount)
    {
        $this->rngSeed = $seed;
        $this->terrainMarching = new MarchingSquares;
        $this->forestMarching = new MarchingSquares;
        $this->generateTerrain();
        $this->generateDefaultVision();
        $this->assignPlayers();
        $this->visionBrush = new Brush(75, 1, 0);
        $this->cityVisionBrush = new Brush(175, 1, 0);
        $this->borderBrush = new Brush(40, 0.05, 0);
        $this->cityBorderBrush = new Brush(80, 0.05, 0);
        $this->playersInCities = array_fill(0, count($this->cities), []);
    }

    public static function create(int $seed, int $playerCount): self
    {
        return new self($seed, $playerCount);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'seed' => $this->seed,
            'playerCount' => $this->playerCount,
            'terrain' => $this->terrainMarching->grid,
            'forest' => $this->forestMarching->grid,
            'defaultVision' => $this->defaultVision,
            'cities' => array_map(fn (City $c) => $c->toArray(), $this->cities),
            'players' => array_map(fn (Player $p) => $p->toArray(), $this->players),
            'nextCityId' => $this->nextCityId,
            'nextTroopId' => $this->nextTroopId,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $environment = new self($data['seed'], $data['playerCount']);
        $environment->terrainMarching->setGrid($data['terrain']);
        $environment->forestMarching->setGrid($data['forest']);
        $environment->defaultVision = $data['defaultVision'];
        $environment->nextCityId = $data['nextCityId'];
        $environment->nextTroopId = $data['nextTroopId'] ?? 1;
        $environment->cities = [];
        $environment->players = [];

        foreach ($data['players'] as $playerData) {
            $environment->players[] = Player::fromArray($playerData, $environment);
        }

        foreach ($data['cities'] as $cityData) {
            $environment->cities[] = City::fromArray($cityData, $environment);
        }

        $environment->playersInCities = array_fill(0, count($environment->cities), []);

        return $environment;
    }

    private function generateTerrain(): void
    {
        $noise = new PerlinNoise($this->seed, 3);

        for ($y = 0; $y <= GameConstants::COLS; $y++) {
            for ($x = 0; $x <= GameConstants::ROWS; $x++) {
                $value = max(0, min(1, ($noise->noise([$x / 25, $y / 25]) - 0.2) + $this->elevationBias($x, $y)));
                $this->terrainMarching->grid[$x][$y] = $value;
            }
        }

        $forestNoise = new PerlinNoise($this->seed + 1, 2);

        for ($y = 0; $y <= GameConstants::COLS; $y++) {
            for ($x = 0; $x <= GameConstants::ROWS; $x++) {
                $terrainValue = $this->terrainMarching->grid[$x][$y];
                $value = (min(0.6, $forestNoise->noise([$x / 30, $y / 30]) * 2.0)) + 0.3;
                $plainsDiff = max(0, (GameConstants::TERRAIN_VALUES['plains'] + 0.1) - $terrainValue);
                $hillDiff = max(0, $terrainValue - (GameConstants::TERRAIN_VALUES['hill'] - 0.1));
                $this->forestMarching->grid[$x][$y] = ($value - ($plainsDiff * 10)) - ($hillDiff * 10);
            }
        }

        $tries = 0;
        $distance = 15;

        while (count($this->cities) < 10) {
            $cx = $this->randomInt(0, GameConstants::ROWS);
            $cy = $this->randomInt(0, GameConstants::COLS);
            $terrainValue = $this->terrainMarching->grid[$cx][$cy];

            $validDistance = true;
            foreach ($this->cities as $city) {
                $dist = abs($cx * GameConstants::CELL_SIZE - $city->position[0])
                    + abs($cy * GameConstants::CELL_SIZE - $city->position[1]);
                if ($dist < GameConstants::CELL_SIZE * $distance) {
                    $validDistance = false;
                    break;
                }
            }

            if (
                $terrainValue > GameConstants::TERRAIN_VALUES['plains']
                && $terrainValue < GameConstants::TERRAIN_VALUES['hill']
                && $validDistance
                && $this->withinEdges($cx, $cy)
                && $this->forestMarching->grid[$cx][$cy] < GameConstants::THRESHOLD
            ) {
                $this->cities[] = new City([$cx * GameConstants::CELL_SIZE, $cy * GameConstants::CELL_SIZE], $this->nextCityId++);
                $distance = 15;
            }

            $tries++;
            if ($tries >= 100) {
                $distance = max(2, $distance - 2);
                $tries = 0;
            }
        }
    }

    private function generateDefaultVision(): void
    {
        $this->defaultVision = MarchingSquares::emptyGrid();

        for ($y = 0; $y <= GameConstants::COLS; $y++) {
            for ($x = 0; $x <= GameConstants::ROWS; $x++) {
                $terrainValue = $this->terrainMarching->grid[$x][$y];
                $forestValue = $this->forestMarching->grid[$x][$y];
                $this->defaultVision[$x][$y] = 0.35 + (
                    max(min((($terrainValue + 0.1) / 1) + 0.2, 1), 0.2)
                    + ($forestValue > 0.6 ? 0.8 : 0.0)
                );
            }
        }
    }

    private function assignPlayers(): void
    {
        $leftBottomCity = $this->minCity(fn (City $c) => $c->position[0] + $c->position[1]);
        $topLeftCity = $this->minCity(fn (City $c) => $c->position[0] - $c->position[1]);
        $middleTopCity = $this->minCity(fn (City $c) => (abs($c->position[0] - (GameConstants::ROWS * GameConstants::CELL_SIZE) / 2) * 1.5) + $c->position[1]);
        $middleBottomCity = $this->maxCity(fn (City $c) => $c->position[1] - (abs($c->position[0] - (GameConstants::ROWS * GameConstants::CELL_SIZE) / 2) * 1.5));
        $topRightCity = $this->maxCity(fn (City $c) => $c->position[0] - $c->position[1]);
        $rightBottomCity = $this->maxCity(fn (City $c) => $c->position[0] + $c->position[1]);
        $leftCity = $this->minCity(fn (City $c) => $c->position[0]);
        $rightCity = $this->maxCity(fn (City $c) => $c->position[0]);
        $topCity = $this->maxCity(fn (City $c) => $c->position[1]);
        $middleCity = $this->minCity(fn (City $c) => abs($c->position[0] - (GameConstants::ROWS * GameConstants::CELL_SIZE) / 2) + abs($c->position[1] - (GameConstants::COLS * GameConstants::CELL_SIZE) / 2));

        $spawnMap = [
            2 => [
                [$leftCity, 0],
                [$rightCity, 1],
            ],
            3 => [
                [$leftBottomCity, 0],
                [$rightBottomCity, 1],
                [$topCity, 2],
            ],
            4 => [
                [$leftBottomCity, 0],
                [$topLeftCity, 1],
                [$topRightCity, 2],
                [$rightBottomCity, 3],
            ],
            5 => [
                [$leftBottomCity, 0],
                [$topLeftCity, 1],
                [$middleCity, 2],
                [$topRightCity, 3],
                [$rightBottomCity, 4],
            ],
            6 => [
                [$leftBottomCity, 0],
                [$topLeftCity, 1],
                [$middleTopCity, 2],
                [$middleBottomCity, 3],
                [$topRightCity, 4],
                [$rightBottomCity, 5],
            ],
        ];

        $spawns = $spawnMap[$this->playerCount] ?? $spawnMap[2];

        foreach ($spawns as [$city, $slot]) {
            $player = new Player($city->position, GameConstants::COLORS[$slot], $slot, $this, $this->nextTroopId++);
            $city->owner = $player;
            $this->players[] = $player;
        }
    }

    /**
     * @param  callable(City): float  $metric
     */
    private function minCity(callable $metric): City
    {
        $best = $this->cities[0];
        $bestValue = $metric($best);

        foreach ($this->cities as $city) {
            $value = $metric($city);
            if ($value < $bestValue) {
                $best = $city;
                $bestValue = $value;
            }
        }

        return $best;
    }

    /**
     * @param  callable(City): float  $metric
     */
    private function maxCity(callable $metric): City
    {
        $best = $this->cities[0];
        $bestValue = $metric($best);

        foreach ($this->cities as $city) {
            $value = $metric($city);
            if ($value > $bestValue) {
                $best = $city;
                $bestValue = $value;
            }
        }

        return $best;
    }

    private function elevationBias(float $x, float $y): float
    {
        $cx = GameConstants::ROWS / 2;
        $cy = GameConstants::COLS / 2;
        $dx = abs($x - $cx);
        $dy = abs($y - $cy);
        $dist = sqrt($dx ** 2 + $dy ** 2);
        $maxDist = sqrt($cx ** 2 + $cy ** 2);

        return 1.0 - ($dist / $maxDist);
    }

    private function withinEdges(int $cx, int $cy): bool
    {
        $edgeMargin = 1;

        return $cx >= $edgeMargin
            && $cx <= GameConstants::ROWS - $edgeMargin
            && $cy >= $edgeMargin
            && $cy <= GameConstants::COLS - $edgeMargin;
    }

    private function randomInt(int $min, int $max): int
    {
        $this->rngSeed = ($this->rngSeed * 1103515245 + 12345) & 0x7fffffff;

        return $min + ($this->rngSeed % ($max - $min + 1));
    }

    public function getTerrainName(float $value, float $fvalue): string
    {
        if ($fvalue > GameConstants::THRESHOLD) {
            return 'forest';
        }

        $name = 'forest';
        foreach (array_reverse(GameConstants::TERRAIN_VALUES, true) as $terrainName => $threshold) {
            if ($value > $threshold) {
                return $terrainName;
            }
        }

        return $name;
    }

    /**
     * @return array{
     *     terrain: list<list<float>>,
     *     forest: list<list<float>>,
     *     cityPositions: list<array{0: float, 1: float}>
     * }
     */
    public function getTerrainInfo(): array
    {
        return [
            'terrain' => $this->terrainMarching->grid,
            'forest' => $this->forestMarching->grid,
            'cityPositions' => array_map(fn (City $c) => $c->position, $this->cities),
        ];
    }

    /**
     * @param  list<array{0: int, 1: list<array{0: float, 1: float}>}>  $pathsToApply
     */
    public function updateTroops(array $pathsToApply): void
    {
        $this->playersInCities = array_fill(0, count($this->cities), []);

        $troopIds = array_column($pathsToApply, 0);
        $troopPaths = array_column($pathsToApply, 1);

        foreach ($this->players as $player) {
            $player->vision->setGrid($this->defaultVision);

            foreach ($this->cities as $city) {
                if ($city->owner === $player) {
                    $this->cityVisionBrush->apply($player->vision, $city->position, 0);
                    $this->cityBorderBrush->apply($player->border, $city->position, 1.0);
                }
            }

            foreach ($this->players as $otherPlayer) {
                if ($otherPlayer === $player) {
                    continue;
                }

                foreach ($this->cities as $city) {
                    if ($city->owner === $otherPlayer) {
                        $this->cityBorderBrush->apply($player->border, $city->position, 0.0);
                    }
                }
            }

            $toRemove = [];

            foreach ($player->troops as $troop) {
                if ($troop->health <= 0) {
                    $toRemove[] = $troop;

                    continue;
                }

                $tidx = array_search($troop->id, $troopIds, true);
                if ($tidx !== false) {
                    $troop->path = $troopPaths[$tidx];
                }

                $oldPos = $troop->position;
                $owned = array_values(array_map(
                    fn (City $city) => $city->position,
                    array_filter($this->cities, fn (City $city) => $city->owner === $player),
                ));

                if ($owned !== []) {
                    $closestCity = $owned[0];
                    $closestDist = PHP_FLOAT_MAX;
                    foreach ($owned as $cityPos) {
                        [, $dist] = GameMath::xyToDirDis([$oldPos[0] - $cityPos[0], $oldPos[1] - $cityPos[1]]);
                        if ($dist < $closestDist) {
                            $closestCity = $cityPos;
                            $closestDist = $dist;
                        }
                    }

                    [$cityDir, $cityDist] = GameMath::xyToDirDis([$oldPos[0] - $closestCity[0], $oldPos[1] - $closestCity[1]]);
                    $samplePoints = [];
                    for ($dist = 0; $dist < (int) ($cityDist / 20); $dist++) {
                        $samplePoints[] = GameMath::dirDisToXy($cityDir, $dist * 20);
                    }

                    $borderAvg = 0;
                    if ($samplePoints !== []) {
                        $borderAvgs = [];
                        foreach ($this->players as $otherPlayer) {
                            if ($otherPlayer === $player) {
                                continue;
                            }

                            $sum = 0.0;
                            foreach ($samplePoints as $sample) {
                                $sum += $otherPlayer->border->getGridValue(
                                    ($closestCity[0] + $sample[0]) / GameConstants::CELL_SIZE,
                                    ($closestCity[1] + $sample[1]) / GameConstants::CELL_SIZE,
                                );
                            }
                            $borderAvgs[] = $sum / count($samplePoints);
                        }
                        $borderAvg = (int) (array_sum($borderAvgs) / count($borderAvgs));
                        $distPenal = max((($cityDist + 250) / 1000), 0.5);
                        $healingPower = (1 - ($borderAvg / 2)) - $distPenal;
                    } else {
                        $healingPower = -0.5;
                    }

                    $troop->health += (int) ($healingPower / 25);
                    if ($troop->health > 100) {
                        $troop->health = 100;
                    }
                }

                $enemiesInRange = [];

                $gx = $oldPos[0] / GameConstants::CELL_SIZE;
                $gy = $oldPos[1] / GameConstants::CELL_SIZE;
                $terrain = $this->terrainMarching->getGridValue($gx, $gy);
                $forest = $this->forestMarching->getGridValue($gx, $gy);
                $onTerrain = $this->getTerrainName($terrain, $forest);

                if ($troop->path !== []) {
                    $target = $troop->path[0];
                    $terrainSpeed = GameConstants::TERRAIN_SPEEDS[$onTerrain];
                    [$dir, ] = GameMath::xyToDirDis([$target[0] - $oldPos[0], $target[1] - $oldPos[1]]);
                    $distance = $terrainSpeed * 0.1;
                    [$newOffX, $newOffY] = GameMath::dirDisToXy($dir, $distance);
                    $newPos = [$oldPos[0] + $newOffX, $oldPos[1] + $newOffY];

                    foreach ($player->troops as $otherT) {
                        if ($otherT === $troop) {
                            continue;
                        }
                        [$otherX, $otherY] = $otherT->position;
                        $oldOffX = $newPos[0] - $otherX;
                        $oldOffY = $newPos[1] - $otherY;
                        [$dir, $sepDist] = GameMath::xyToDirDis([$oldOffX, $oldOffY]);
                        if ($sepDist < 14) {
                            $sepDist = 14;
                            [$newOffX, $newOffY] = GameMath::dirDisToXy($dir, $sepDist);
                            $changeX = $newOffX - $oldOffX;
                            $changeY = $newOffY - $oldOffY;
                            $newPos = [$newPos[0] + $changeX, $newPos[1] + $changeY];
                        }
                    }

                    [$hitEnemy, $enemiesInRange, $onTerrain, $newPos] = $this->resolveTroopMove($player, $troop, $newPos, $enemiesInRange, $onTerrain);

                    if (! $hitEnemy) {
                        [$dir, $distToTarget] = GameMath::xyToDirDis([$target[0] - $troop->position[0], $target[1] - $troop->position[1]]);
                        if ($distToTarget < ($terrainSpeed * 2)) {
                            array_shift($troop->path);
                        }
                    }
                } else {
                    $newPos = $oldPos;
                    foreach ($player->troops as $otherT) {
                        if ($otherT === $troop) {
                            continue;
                        }
                        [$otherX, $otherY] = $otherT->position;
                        $oldOffX = $newPos[0] - $otherX;
                        $oldOffY = $newPos[1] - $otherY;
                        [$dir, $sepDist] = GameMath::xyToDirDis([$oldOffX, $oldOffY]);
                        if ($sepDist < 15) {
                            $sepDist += 0.025;
                            [$newOffX, $newOffY] = GameMath::dirDisToXy($dir, $sepDist);
                            $changeX = $newOffX - $oldOffX;
                            $changeY = $newOffY - $oldOffY;
                            $newPos = [$newPos[0] + $changeX, $newPos[1] + $changeY];
                        }
                    }

                    [$hitEnemy, $enemiesInRange, $onTerrain, $newPos] = $this->resolveTroopMove($player, $troop, $newPos, $enemiesInRange, $onTerrain);
                }

                if ($enemiesInRange !== []) {
                    $attackPower = (int) (GameConstants::TERRAIN_ATTACKS[$onTerrain] / 25);
                    usort($enemiesInRange, fn ($a, $b) => $a[1] <=> $b[1]);
                    $enemiesInRange[0][0]->health -= $attackPower;
                }

                if ($onTerrain === 'hill') {
                    $this->cityVisionBrush->apply($player->vision, $troop->position, 0);
                } else {
                    $this->visionBrush->apply($player->vision, $troop->position, 0);
                }
                $this->borderBrush->apply($player->border, $troop->position, 1.0);

                foreach ($this->cities as $i => $city) {
                    [$cx, $cy] = $city->position;
                    [$tx, $ty] = $troop->position;
                    [, $dist] = GameMath::xyToDirDis([$tx - $cx, $ty - $cy]);
                    if ($dist < 15) {
                        $this->playersInCities[$i][] = $player;
                        break;
                    }
                }
            }

            foreach (array_reverse($toRemove) as $troop) {
                $player->troops = array_values(array_filter(
                    $player->troops,
                    fn (Troop $t) => $t !== $troop,
                ));
            }
        }
    }

    /**
     * @param  list<array{0: Troop, 1: float}>  $enemiesInRange
     * @return array{0: bool, 1: list<array{0: Troop, 1: float}>, 2: string, 3: array{0: float, 1: float}}
     */
    private function resolveTroopMove(Player $player, Troop $troop, array $newPos, array $enemiesInRange, string $onTerrain): array
    {
        $hitEnemy = false;

        foreach ($this->players as $otherPlayer) {
            if ($otherPlayer === $player) {
                continue;
            }

            $this->borderBrush->apply($otherPlayer->border, $troop->position, 0.0);

            foreach ($otherPlayer->troops as $otherT) {
                [$otherX, $otherY] = $otherT->position;
                [$offX, $offY] = [$newPos[0] - $otherX, $newPos[1] - $otherY];
                [, $distance] = GameMath::xyToDirDis([$offX, $offY]);
                if ($distance < 28) {
                    $hitEnemy = true;
                }
                if ($distance < 32) {
                    $enemiesInRange[] = [$otherT, $distance];
                }
            }
        }

        $gx = $newPos[0] / GameConstants::CELL_SIZE;
        $gy = $newPos[1] / GameConstants::CELL_SIZE;
        $terrain = $this->terrainMarching->getGridValue($gx, $gy);
        $forest = $this->forestMarching->getGridValue($gx, $gy);
        $newTerrain = $this->getTerrainName($terrain, $forest);

        $outOfWorld = $newPos[0] > GameConstants::WORLD_X
            || $newPos[0] < 0
            || $newPos[1] > GameConstants::WORLD_Y
            || $newPos[1] < 0;

        if ($newTerrain !== 'mountain' && ! $hitEnemy && ! $outOfWorld) {
            $troop->position = $newPos;
            $onTerrain = $newTerrain;
        }

        return [$hitEnemy, $enemiesInRange, $onTerrain, $newPos];
    }

    /**
     * @param  list<array{0: int, 1: list<array{0: float, 1: float}>}>  $pathsToApply
     */
    public function updateCities(array $pathsToApply): void
    {
        $cityIds = array_column($pathsToApply, 0);
        $cityPaths = array_column($pathsToApply, 1);

        foreach ($this->cities as $i => $city) {
            $cidx = array_search($city->id, $cityIds, true);
            if ($cidx !== false) {
                $city->path = $cityPaths[$cidx];
            }

            [$cx, $cy] = $city->position;
            $lastOwner = $city->owner;

            if (count($this->playersInCities[$i] ?? []) === 1) {
                $city->owner = $this->playersInCities[$i][0];
            }

            if ($lastOwner !== $city->owner) {
                $city->timer = 0;
                $city->path = [];
            }

            if ($city->owner !== null) {
                $city->timer++;
                $ownedCount = count(array_filter($this->cities, fn (City $c) => $c->owner === $city->owner));
                $tPerC = count($city->owner->troops) / max(1, $ownedCount);

                if ($city->timer >= 45 * (30 * $tPerC) && $tPerC < 10) {
                    $city->owner->spawnTroop(
                        [$cx + $this->randomInt(-6, 5), $cy + $this->randomInt(-6, 5)],
                        $city->path,
                        $this->nextTroopId++,
                    );
                    $city->timer = 0;
                }
            }
        }
    }

    /**
     * @return array{
     *     vision: list<list<float>>,
     *     border: list<list<float>>,
     *     troops: list<array<string, mixed>>,
     *     cities: list<array<string, mixed>>
     * }
     */
    public function drawInfo(int $playerSlot): array
    {
        $player = $this->players[$playerSlot];
        $troops = [];

        foreach ($this->players as $otherPlayer) {
            foreach ($otherPlayer->troops as $troop) {
                $gx = max(0, min(GameConstants::ROWS, $troop->position[0] / GameConstants::CELL_SIZE));
                $gy = max(0, min(GameConstants::COLS, $troop->position[1] / GameConstants::CELL_SIZE));

                if ($player->vision->getGridValue($gx, $gy) >= GameConstants::THRESHOLD) {
                    $troops[] = [
                        'position' => $troop->position,
                        'color' => $troop->owner->color,
                        'id' => $troop->id,
                        'ownerSlot' => $troop->owner->slot,
                        'path' => $troop->path,
                        'health' => $troop->health,
                    ];
                }
            }
        }

        $cities = array_map(function (City $city) {
            return [
                'ownerColor' => $city->owner?->color,
                'position' => $city->position,
                'id' => $city->id,
                'path' => $city->path,
                'ownerSlot' => $city->owner?->slot,
            ];
        }, $this->cities);

        return [
            'vision' => $player->vision->grid,
            'border' => $player->border->grid,
            'troops' => $troops,
            'cities' => $cities,
        ];
    }

    /**
     * @return list<int>
     */
    public function eliminatedSlots(): array
    {
        $eliminated = [];

        foreach ($this->players as $player) {
            $ownedCities = array_filter($this->cities, fn (City $c) => $c->owner === $player);
            if ($player->troops === [] && $ownedCities === []) {
                $eliminated[] = $player->slot;
            }
        }

        return $eliminated;
    }

    public function winnerSlot(): ?int
    {
        $active = array_filter($this->players, function (Player $player) {
            $ownedCities = array_filter($this->cities, fn (City $c) => $c->owner === $player);

            return $player->troops !== [] || $ownedCities !== [];
        });

        if (count($active) === 1) {
            return array_values($active)[0]->slot;
        }

        return null;
    }
}
