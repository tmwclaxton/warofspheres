<?php

namespace App\Events;

use App\Models\Game;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class GameStateUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<string, mixed>  $state
     * @param  list<array{credits?: int, incomePerTick?: int}>|null  $economySnapshot
     */
    public function __construct(
        public Game $game,
        public string $broadcastConnection,
        public array $state,
        public ?array $economySnapshot = null,
        public ?int $worldTick = null,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('game.'.$this->game->uuid.'.'.$this->broadcastConnection),
        ];
    }

    public function broadcastAs(): string
    {
        return 'GameStateUpdated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $payload = [
            'gameUuid' => $this->game->uuid,
            'state' => $this->state,
        ];

        if ($this->economySnapshot !== null) {
            $payload['economy'] = $this->economySnapshot;
        }

        if ($this->worldTick !== null) {
            $payload['worldTick'] = $this->worldTick;
        }

        return $payload;
    }
}
