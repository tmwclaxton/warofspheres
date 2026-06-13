<?php

namespace App\Events;

use App\Models\Game;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class GameInitialized implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array{
     *     terrain: list<list<float>>,
     *     forest: list<list<float>>,
     *     cityPositions: list<array{0: float, 1: float}>,
     *     world: array{width: int, height: int, cellSize: int},
     *     terrainCells?: list<list<string>>
     * }  $terrainInfo
     */
    public function __construct(
        public Game $game,
        public string $broadcastConnection,
        public int $slot,
        public array $terrainInfo,
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
        return 'GameInitialized';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $this->game->loadMissing('players');

        $color = $this->game->players->firstWhere('slot', $this->slot)?->color ?? '#c0392b';

        $payload = [
            'gameUuid' => $this->game->uuid,
            'slot' => $this->slot,
            'color' => $color,
            'terrain' => $this->terrainInfo['terrain'],
            'forest' => $this->terrainInfo['forest'],
            'cityPositions' => $this->terrainInfo['cityPositions'],
            'world' => $this->terrainInfo['world'],
        ];

        if (isset($this->terrainInfo['terrainCells']) && is_array($this->terrainInfo['terrainCells'])) {
            $payload['terrainCells'] = $this->terrainInfo['terrainCells'];
        }

        if (isset($this->terrainInfo['economy']) && is_array($this->terrainInfo['economy'])) {
            $payload['economy'] = $this->terrainInfo['economy'];
        }

        if (isset($this->terrainInfo['worldTick'])) {
            $payload['worldTick'] = (int) $this->terrainInfo['worldTick'];
        }

        return $payload;
    }
}
