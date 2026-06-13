<?php

namespace App\Http\Controllers;

use App\Jobs\MatchmakingJob;
use App\Models\MatchmakingEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MatchmakingController extends Controller
{
    public function show(Request $request): Response
    {
        $user = $request->user();
        $entry = $user
            ? MatchmakingEntry::query()
                ->where('user_id', $user->id)
                ->where('status', 'queued')
                ->first()
            : null;

        return Inertia::render('matchmaking/Queue', [
            'queued' => $entry !== null,
            'mmr' => $user?->mmr ?? 1000,
        ]);
    }

    public function join(Request $request): JsonResponse|RedirectResponse
    {
        $user = $request->user();
        if ($user === null) {
            abort(403);
        }

        MatchmakingEntry::query()->updateOrCreate(
            ['user_id' => $user->id],
            ['status' => 'queued', 'game_id' => null],
        );

        MatchmakingJob::dispatch();

        return response()->json(['ok' => true]);
    }

    public function leave(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            abort(403);
        }

        MatchmakingEntry::query()
            ->where('user_id', $user->id)
            ->where('status', 'queued')
            ->update(['status' => 'cancelled']);

        return response()->json(['ok' => true]);
    }

    public function status(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['status' => 'unauthenticated']);
        }

        $entry = MatchmakingEntry::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->first();

        if ($entry === null) {
            return response()->json(['status' => 'idle']);
        }

        return response()->json([
            'status' => $entry->status,
            'gameUuid' => $entry->game?->uuid,
        ]);
    }
}
