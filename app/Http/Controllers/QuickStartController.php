<?php

namespace App\Http\Controllers;

use App\Games\Services\GameManager;
use App\Games\Services\GuestGameIdentity;
use App\Models\QuickStartEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class QuickStartController extends Controller
{
    public function join(Request $request, GameManager $gameManager): JsonResponse
    {
        $user = $request->user();
        $guestKey = $this->guestKeyFromSession($request);

        if ($user === null && $guestKey === null) {
            return response()->json(['error' => 'No identity'], 422);
        }

        // Upsert: one entry per identity.
        if ($user !== null) {
            QuickStartEntry::updateOrCreate(
                ['user_id' => $user->id],
                ['status' => 'queued', 'game_id' => null, 'created_at' => now()],
            );
        } else {
            QuickStartEntry::updateOrCreate(
                ['guest_key' => $guestKey],
                ['status' => 'queued', 'game_id' => null, 'created_at' => now()],
            );
        }

        $gameManager->runQuickStart();

        return response()->json($this->currentStatus($user?->id, $guestKey));
    }

    public function leave(Request $request): JsonResponse
    {
        $user = $request->user();
        $guestKey = $this->guestKeyFromSession($request);

        QuickStartEntry::query()
            ->when($user !== null, fn ($q) => $q->where('user_id', $user->id))
            ->when($user === null && $guestKey !== null, fn ($q) => $q->where('guest_key', $guestKey))
            ->where('status', 'queued')
            ->delete();

        return response()->json(['status' => 'none', 'queueSize' => $this->queueSize()]);
    }

    public function status(Request $request, GameManager $gameManager): JsonResponse
    {
        $user = $request->user();
        $guestKey = $this->guestKeyFromSession($request);

        // Re-run matching on every poll so waiting players are picked up
        // within one polling cycle (2 s) after a suitable lobby appears.
        if (QuickStartEntry::where('status', 'queued')->exists()) {
            $gameManager->runQuickStart();
        }

        return response()->json($this->currentStatus($user?->id, $guestKey));
    }

    /** @return array{status: string, queueSize: int, gameUuid: string|null} */
    private function currentStatus(?int $userId, ?string $guestKey): array
    {
        $entry = null;

        if ($userId !== null) {
            $entry = QuickStartEntry::where('user_id', $userId)->first();
        } elseif ($guestKey !== null) {
            $entry = QuickStartEntry::where('guest_key', $guestKey)->first();
        }

        return [
            'status' => $entry?->status ?? 'none',
            'queueSize' => $this->queueSize(),
            'gameUuid' => $entry?->game?->uuid,
        ];
    }

    private function queueSize(): int
    {
        return QuickStartEntry::where('status', 'queued')->count();
    }

    private function guestKeyFromSession(Request $request): ?string
    {
        $key = $request->session()->get(GuestGameIdentity::SESSION_KEY);

        if (! is_string($key) || ! Str::isUuid($key)) {
            return null;
        }

        return $key;
    }
}
