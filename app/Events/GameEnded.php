<?php

namespace App\Events;

use App\Models\Game;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class GameEnded implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Game $game,
        public ?int $winnerUserId,
        public ?int $winnerSlot,
        public string $winnerName,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $this->game->loadMissing('players');

        return $this->game->players
            ->map(fn ($player) => new PrivateChannel('game.'.$this->game->uuid.'.'.$player->broadcastConnection()))
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
            'winnerSlot' => $this->winnerSlot,
            'winnerName' => $this->winnerName,
        ];
    }
}
