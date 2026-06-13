<?php

namespace App\Http\Middleware;

use App\Enums\GameStatus;
use App\Games\Services\GuestGameIdentity;
use App\Models\GamePlayer;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    /**
     * @return array{uuid: string, status: string}|null
     */
    private function resolveActiveGame(Request $request, mixed $user, mixed $guestKey): ?array
    {
        $query = GamePlayer::query()
            ->whereHas('game', fn ($q) => $q->whereIn('status', [GameStatus::Lobby->value, GameStatus::Playing->value]));

        if ($user !== null) {
            $query->where('user_id', $user->id);
        } elseif (is_string($guestKey) && Str::isUuid($guestKey)) {
            $query->where('guest_key', $guestKey);
        } else {
            return null;
        }

        $player = $query->with('game:id,uuid,status')->latest('id')->first();

        if ($player === null) {
            return null;
        }

        return [
            'uuid' => $player->game->uuid,
            'status' => $player->game->status->value,
        ];
    }

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        $guestBroadcast = null;
        $guestKey = $request->session()->get(GuestGameIdentity::SESSION_KEY);
        if (is_string($guestKey) && Str::isUuid($guestKey)) {
            $guestBroadcast = GuestGameIdentity::broadcastSegment($guestKey);
        }

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'appDebug' => (bool) config('app.debug'),
            'auth' => [
                'user' => $user,
                'isAdmin' => $user?->isAdmin() ?? false,
            ],
            'guestBroadcast' => $guestBroadcast,
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'activeGame' => $this->resolveActiveGame($request, $user, $guestKey),
        ];
    }
}
