<?php

namespace App\Http\Controllers\Maps;

use App\Http\Controllers\Controller;
use App\Http\Requests\Maps\PublishMapRequest;
use App\Http\Requests\Maps\SaveMapRequest;
use App\Maps\MapEditorGrid;
use App\Maps\MapMarkers;
use App\Maps\TerrainCatalog;
use App\Models\Map;
use App\Models\MapVote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class MapController extends Controller
{
    /**
     * Public gallery of published community maps.
     */
    public function explore(Request $request): InertiaResponse
    {
        $published = Map::query()
            ->where('published', true)
            ->with([
                'user:id,name',
                'forkedFrom:id,uuid,name,user_id',
                'forkedFrom.user:id,name',
            ])
            ->latest('published_at')
            ->limit(48)
            ->get();

        $viewerId = $request->user()?->id;
        $voteByMapId = [];
        if ($viewerId !== null && $published->isNotEmpty()) {
            $voteByMapId = MapVote::query()
                ->where('user_id', $viewerId)
                ->whereIn('map_id', $published->pluck('id'))
                ->get()
                ->keyBy('map_id')
                ->all();
        }

        $cards = $published->map(function (Map $map) use ($voteByMapId) {
            $vote = $voteByMapId[$map->id] ?? null;

            return $this->exploreCard($map, $vote instanceof MapVote ? $vote : null);
        });

        return Inertia::render('MapExplore', [
            'maps' => $cards,
        ]);
    }

    /**
     * Map editor. Optional {@link Map} UUID in the path reloads that map (same as choosing it in the sidebar).
     */
    public function builder(?Map $map = null): InertiaResponse
    {
        if ($map !== null) {
            Gate::authorize('view', $map);
        }

        $maps = Map::query()
            ->where('user_id', auth()->id())
            ->orderByDesc('updated_at')
            ->get(['id', 'uuid', 'name', 'updated_at', 'published']);

        return Inertia::render('MapBuilder', [
            'maps' => $maps,
            'terrainTypes' => TerrainCatalog::forClient(),
            'teamColors' => MapMarkers::teamColorsForClient(),
            'defaults' => MapEditorGrid::emptyData(),
            'initialDocument' => $map === null ? null : [
                'uuid' => $map->uuid,
                'name' => $map->name,
                'data' => $map->data,
                'published' => $map->published,
            ],
        ]);
    }

    public function index(): JsonResponse
    {
        $maps = Map::query()
            ->where('user_id', auth()->id())
            ->orderByDesc('updated_at')
            ->get(['id', 'uuid', 'name', 'updated_at', 'published']);

        return response()->json(['maps' => $maps]);
    }

    public function store(SaveMapRequest $request): JsonResponse
    {
        $map = $request->user()->maps()->create([
            'name' => $request->validated('name'),
            'data' => $request->validated('data'),
        ]);

        return response()->json(['map' => $this->mapFull($map)], 201);
    }

    public function show(Map $map): JsonResponse
    {
        Gate::authorize('view', $map);

        return response()->json(['map' => $this->mapFull($map)]);
    }

    public function update(SaveMapRequest $request, Map $map): JsonResponse
    {
        Gate::authorize('update', $map);

        $map->update([
            'name' => $request->validated('name'),
            'data' => $request->validated('data'),
        ]);

        return response()->json(['map' => $this->mapFull($map->fresh())]);
    }

    public function destroy(Map $map): HttpResponse
    {
        Gate::authorize('delete', $map);
        $map->delete();

        return response()->noContent();
    }

    public function publish(PublishMapRequest $request, Map $map): JsonResponse
    {
        $map->update([
            'published' => true,
            'published_at' => now(),
        ]);

        return response()->json(['map' => $this->mapFull($map->fresh())]);
    }

    public function fork(Request $request, Map $map): JsonResponse
    {
        Gate::authorize('fork', $map);

        $copy = DB::transaction(function () use ($request, $map) {
            $name = Str::limit($map->name.' (copy)', 120);
            $newMap = $request->user()->maps()->create([
                'name' => $name,
                'data' => $map->data,
                'forked_from_id' => $map->id,
            ]);
            $map->increment('forks_count');

            return $newMap;
        });

        return response()->json(['map' => $this->mapFull($copy)], 201);
    }

    public function vote(Request $request, Map $map): JsonResponse
    {
        Gate::authorize('vote', $map);

        $validated = $request->validate([
            'vote' => ['required', 'string', 'in:like,dislike,clear'],
        ]);

        $userId = (int) $request->user()->id;

        DB::transaction(function () use ($map, $userId, $validated): void {
            if ($validated['vote'] === 'clear') {
                MapVote::query()->where('map_id', $map->id)->where('user_id', $userId)->delete();
            } else {
                $value = $validated['vote'] === 'like' ? 1 : -1;
                MapVote::query()->updateOrCreate(
                    [
                        'map_id' => $map->id,
                        'user_id' => $userId,
                    ],
                    ['value' => $value],
                );
            }

            $likes = MapVote::query()->where('map_id', $map->id)->where('value', 1)->count();
            $dislikes = MapVote::query()->where('map_id', $map->id)->where('value', -1)->count();
            $map->forceFill([
                'likes_count' => $likes,
                'dislikes_count' => $dislikes,
            ])->saveQuietly();
        });

        $viewerVote = MapVote::query()
            ->where('map_id', $map->id)
            ->where('user_id', $userId)
            ->first();

        return response()->json([
            'map' => $this->exploreCard($map->fresh(['user:id,name', 'forkedFrom:id,uuid,name,user_id', 'forkedFrom.user:id,name']), $viewerVote),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function exploreCard(Map $map, ?MapVote $viewerVote): array
    {
        $forkAttribution = null;
        if ($map->forked_from_id !== null && $map->forkedFrom !== null) {
            $forkAttribution = [
                'parentName' => $map->forkedFrom->name,
                'parentAuthorName' => $map->forkedFrom->user?->name ?? 'Unknown',
                'parentUuid' => $map->forkedFrom->uuid,
            ];
        }

        return [
            'uuid' => $map->uuid,
            'name' => $map->name,
            'ownerName' => $map->user?->name ?? 'Unknown',
            'ownerId' => $map->user_id,
            'data' => $map->data,
            'gamesCount' => $map->games_count,
            'likesCount' => $map->likes_count,
            'dislikesCount' => $map->dislikes_count,
            'forksCount' => $map->forks_count,
            'publishedAt' => $map->published_at?->toIso8601String(),
            'forkAttribution' => $forkAttribution,
            'viewerVote' => $viewerVote === null
                ? null
                : ($viewerVote->value === 1 ? 'like' : 'dislike'),
        ];
    }

    /**
     * @return array{id: int, uuid: string, name: string, data: array<string, mixed>, updated_at: string|null, published: bool}
     */
    private function mapFull(Map $map): array
    {
        return [
            'id' => $map->id,
            'uuid' => $map->uuid,
            'name' => $map->name,
            'data' => $map->data,
            'updated_at' => $map->updated_at?->toIso8601String(),
            'published' => $map->published,
        ];
    }
}
