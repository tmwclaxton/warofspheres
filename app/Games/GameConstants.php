<?php

namespace App\Games;

final class GameConstants
{
    public const int CELL_SIZE = 20;

    public const int WORLD_X = 1280;

    public const int WORLD_Y = 700;

    public const int ROWS = 64;

    public const int COLS = 35;

    public const float THRESHOLD = 0.5;

    public const int TICK_RATE = 30;

    /**
     * Per-tick step along a move order: {@code terrainSpeed * this} world units at {@see self::TICK_RATE} Hz.
     * Plains baseline uses {@see self::TERRAIN_SPEEDS} (plains = 1.0); raise this for faster marches.
     */
    public const float TROOP_MOVEMENT_PER_TICK_SCALE = 0.75;

    /** Ticks (~seconds×30) fresh troops get an attack “adrenaline” bonus that decays to neutral. */
    public const int TROOP_WARMUP_TICKS = 120;

    /** Peak multiplier at spawn (decays linearly over warmup). */
    public const float TROOP_WARMUP_ATTACK_PEAK = 1.45;

    public const int TROOP_MORALE_MIN = 15;

    public const int TROOP_MORALE_MAX = 100;

    /** Morale lost per tick while engaged with an enemy in range. */
    public const float TROOP_MORALE_COMBAT_DRAIN = 0.35;

    /** Morale recovered per tick when not in combat and in supply (near owned city). */
    public const float TROOP_MORALE_REST_GAIN = 0.22;

    /** If supply line to capital is this blocked (0–1), apply extra morale drain (encirclement). */
    public const float TROOP_SUPPLY_CUT_THRESHOLD = 0.55;

    public const float TROOP_SUPPLY_CUT_MORALE_DRAIN = 0.5;

    /** Starting credits per commander (spent on recruits). */
    public const int ECONOMY_STARTING_CREDITS = 220;

    /** Credits earned per owned city per tick (flags + capitals). */
    public const int ECONOMY_INCOME_PER_CITY_PER_TICK = 1;

    /** Cost to recruit one infantry at your capital. */
    public const int ECONOMY_RECRUIT_COST = 200;

    /** Minimum clearance from other units when spawning recruits. */
    public const int ECONOMY_RECRUIT_CLEARANCE = 22;

    /** Maximum infantry units per commander (auto-spawns + recruits). */
    public const int ECONOMY_MAX_ARMY_PER_PLAYER = 24;

    public const int MAX_PLAYERS = 6;

    public const int MIN_PLAYERS = 2;

    /** Wall-clock age after which an open lobby is closed without starting. */
    public const int LOBBY_MAX_AGE_SECONDS = 3600;

    /** If every commander has had no activity for this long, the match ends with no winner. */
    public const int MATCH_ALL_PLAYERS_INACTIVE_SECONDS = 120;

    public const string ABORTED_LOBBY_TIMEOUT = 'lobby_timeout';

    public const string ABORTED_MATCH_INACTIVITY = 'match_inactivity';

    /** @var array<string, float> */
    /**
     * Elevation thresholds used by {@see Engine\Environment::getTerrainName()}.
     * Values must stay in ascending order; iterate reversed (mountain → water).
     *
     * New extended terrain types encoded in the gap between existing thresholds:
     *   swamp  0.025–0.05  | beach 0.05–0.1 | snow 0.1–0.55 | desert 0.55–0.7
     */
    public const array TERRAIN_VALUES = [
        'water' => -0.1,
        'swamp' => 0.025,
        'beach' => 0.05,
        'plains' => 0.1,
        'snow' => 0.30,
        'desert' => 0.55,
        'hill' => 0.7,
        'mountain' => 0.83,
    ];

    /** @var array<int, array{0: int, 1: int, 2: int}> */
    public const array COLORS = [
        [255, 0, 0],
        [0, 0, 255],
        [255, 150, 0],
        [175, 0, 175],
        [0, 175, 0],
        [0, 255, 255],
    ];

    /** @var array<string, float> */
    public const array TERRAIN_SPEEDS = [
        'water' => 0.6,
        'forest' => 0.8,
        'plains' => 1.0,
        'hill' => 0.7,
        'mountain' => 3.0,
        // Extended terrain types
        'swamp' => 0.4,
        'beach' => 0.8,
        'desert' => 1.0,
        'snow' => 0.7,
    ];

    /** @var array<string, float> */
    public const array TERRAIN_ATTACKS = [
        'water' => 0.5,
        'forest' => 0.75,
        'plains' => 1.0,
        'hill' => 1.5,
        'mountain' => 0.0,
        // Extended terrain types
        'swamp' => 0.6,
        'beach' => 0.8,
        'desert' => 1.0,
        'snow' => 0.9,
    ];

    /** Minimum world-unit separation enforced between any two troops during movement resolution. */
    public const int TROOP_MIN_SEPARATION = 14;

    /** World-unit radius within which an enemy troop registers as "in melee range". */
    public const int TROOP_COMBAT_RANGE = 32;

    /** Threshold within which an enemy is considered "hit" (triggers attack calculation). */
    public const int TROOP_HIT_RANGE = 28;

    /** Starting minimum city-grid separation used during procedural map generation. */
    public const int CITY_GEN_MIN_SEPARATION = 15;

    /**
     * Fraction of total cities a player must own (plus all enemy capitals) to win.
     * Matches War of Dots' 80% capture threshold.
     */
    public const float VICTORY_CITY_THRESHOLD = 0.8;

    /**
     * Maximum units each owned city can supply. Troops in excess of
     * (ownedCities × CITY_SUPPLY_CAP) lose 1 HP per tick (starvation).
     */
    public const int CITY_SUPPLY_CAP = 5;

    /** HP drained per tick from each unsupported (starving) troop. */
    public const int STARVATION_DAMAGE_PER_TICK = 1;

    // -------------------------------------------------------------------------
    // Ship / water conversion
    // -------------------------------------------------------------------------

    /** Consecutive water ticks before a troop converts to a ship (150 ticks ≈ 5 s at 30 Hz). */
    public const int SHIP_CONVERSION_TICKS = 150;

    /** Speed multiplier applied to ships on water terrain (faster than normal water movement). */
    public const float SHIP_WATER_SPEED_MULT = 3.0;

    /** HP drained per tick while a unit is on water (before ship conversion). */
    public const int WATER_DAMAGE_PER_TICK = 1;

    // -------------------------------------------------------------------------
    // Tank unit type
    // -------------------------------------------------------------------------

    /** Max health for infantry units. */
    public const int INFANTRY_MAX_HEALTH = 100;

    /** Max health for tank units — twice as durable as infantry. */
    public const int TANK_MAX_HEALTH = 200;

    /** Credits to recruit one tank at the player's capital. */
    public const int ECONOMY_RECRUIT_COST_TANK = 400;

    /**
     * Terrain movement speeds for tanks (compare to {@see TERRAIN_SPEEDS} for infantry).
     * Tanks are slower in rough terrain but identical speed in desert.
     *
     * @var array<string, float>
     */
    public const array TANK_TERRAIN_SPEEDS = [
        'water' => 0.50,
        'forest' => 0.32,
        'plains' => 0.60,
        'hill' => 0.28,
        'mountain' => 3.0,
        // Extended terrains (used when extra terrain types are active)
        'desert' => 1.0,
        'swamp' => 0.35,
        'beach' => 0.90,
        'snow' => 0.40,
    ];

    /**
     * Terrain attack multipliers for tanks.
     * Tanks deal 2× damage on open ground but lose the bonus in forests.
     *
     * @var array<string, float>
     */
    public const array TANK_TERRAIN_ATTACKS = [
        'water' => 0.5,
        'forest' => 0.75,
        'plains' => 2.0,
        'hill' => 3.0,
        'mountain' => 0.0,
        'desert' => 2.0,
        'swamp' => 0.75,
        'beach' => 1.75,
        'snow' => 1.5,
    ];

    public static function colorHex(int $slot): string
    {
        $rgb = self::COLORS[$slot] ?? self::COLORS[0];

        return sprintf('#%02x%02x%02x', $rgb[0], $rgb[1], $rgb[2]);
    }
}
