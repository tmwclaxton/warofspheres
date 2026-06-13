<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GameReplaySnapshot extends Model
{
    public $timestamps = false;

    protected $fillable = ['game_id', 'world_tick', 'state_json'];

    protected $casts = [
        'created_at' => 'datetime',
        'world_tick' => 'integer',
    ];

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    /**
     * Decode the gzip-compressed JSON state.
     *
     * @return array<string, mixed>
     */
    public function decodeState(): array
    {
        $raw = is_resource($this->state_json)
            ? stream_get_contents($this->state_json)
            : $this->state_json;

        /** @var string $raw */
        $decompressed = gzdecode($raw);
        if ($decompressed === false) {
            return json_decode($raw, true) ?? [];
        }

        return json_decode($decompressed, true) ?? [];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public static function encodeState(array $state): string
    {
        $json = json_encode($state);

        return gzencode($json ?: '{}') ?: '';
    }
}
