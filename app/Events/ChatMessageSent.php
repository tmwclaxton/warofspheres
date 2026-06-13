<?php

namespace App\Events;

use App\Models\ChatMessage;
use App\Models\Game;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ChatMessageSent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Game $game,
        public ChatMessage $chatMessage,
        public string $senderName,
        public int $senderSlot,
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
        return 'ChatMessageSent';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->chatMessage->id,
            'body' => $this->chatMessage->body,
            'senderName' => $this->senderName,
            'senderSlot' => $this->senderSlot,
            'createdAt' => $this->chatMessage->created_at?->toISOString(),
        ];
    }
}
