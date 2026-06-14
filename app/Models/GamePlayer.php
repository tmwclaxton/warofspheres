<?php

namespace App\Models;

use App\Games\Services\GuestGameIdentity;
use Database\Factories\GamePlayerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['game_id', 'user_id', 'guest_key', 'display_name', 'slot', 'color', 'team_index'])]
class GamePlayer extends Model
{
    /** @use HasFactory<GamePlayerFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Game, $this>
     */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function displayLabel(): string
    {
        if ($this->user !== null) {
            return is_string($this->user->game_display_name) && $this->user->game_display_name !== ''
                ? $this->user->game_display_name
                : $this->user->name;
        }

        return is_string($this->display_name) && $this->display_name !== '' ? $this->display_name : 'Guest';
    }

    public function broadcastConnection(): string
    {
        if ($this->user_id !== null) {
            return 'u'.$this->user_id;
        }

        if (! is_string($this->guest_key) || $this->guest_key === '') {
            throw new LogicException('Game player is missing user_id and guest_key.');
        }

        return GuestGameIdentity::broadcastSegment($this->guest_key);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'pause_requested' => 'boolean',
        ];
    }
}
