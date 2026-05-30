<?php

namespace App\Events;

use App\Models\Game;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class GameEnded implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Game $game,
        public ?int $winnerUserId,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return $this->game->players
            ->map(fn ($player) => new PrivateChannel('game.'.$this->game->uuid.'.'.$player->user_id))
            ->all();
    }

    public function broadcastAs(): string
    {
        return 'GameEnded';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'gameUuid' => $this->game->uuid,
            'winnerUserId' => $this->winnerUserId,
        ];
    }
}
