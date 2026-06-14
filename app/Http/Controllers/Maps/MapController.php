<?php

namespace App\Http\Controllers\Maps;

use App\Http\Controllers\Controller;
use App\Http\Requests\Maps\ExploreMapsRequest;
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
use Illuminate\Pagination\LengthAwarePaginator;
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
    public function explore(ExploreMapsRequest $request): InertiaResponse
    {
        $filters = $request->exploreFilters();

        $query = Map::query()
            ->where('published', true)
            ->with([
                'user:id,name',
                'forkedFrom:id,uuid,name,user_id',
                'forkedFrom.user:id,name',
            ]);

        if ($filters['q'] !== '') {
            $like = '%'.$this->escapeLike(mb_strtolower($filters['q'], 'UTF-8')).'%';
            $query->whereRaw('LOWER(maps.name) LIKE ?', [$like]);
        }

        if ($filters['author'] !== '') {
            $like = '%'.$this->escapeLike(mb_strtolower($filters['author'], 'UTF-8')).'%';
            $query->whereHas('user', function ($userQuery) use ($like): void {
                $userQuery->whereRaw('LOWER(users.name) LIKE ?', [$like]);
            });
        }

        if ($filters['uuid'] !== '') {
            $query->where('uuid', $filters['uuid']);
        }

        match ($filters['sort']) {
            'oldest' => $query->orderBy('published_at')->orderBy('id'),
            'name_az' => $query->orderBy('name')->orderBy('id'),
            'name_za' => $query->orderByDesc('name')->orderBy('id'),
            'most_likes' => $query->orderByDesc('likes_count')->orderByDesc('published_at')->orderByDesc('id'),
            'most_forks' => $query->orderByDesc('forks_count')->orderByDesc('published_at')->orderByDesc('id'),
            'most_games' => $query->orderByDesc('games_count')->orderByDesc('published_at')->orderByDesc('id'),
            default => $query->orderByDesc('published_at')->orderByDesc('id'),
        };

        /** @var LengthAwarePaginator<int, Map> $paginator */
        $paginator = $query->paginate($filters['per_page'])->withQueryString();

        $viewerId = $request->user()?->id;
        $voteByMapId = [];
        if ($viewerId !== null && $paginator->isNotEmpty()) {
            $voteByMapId = MapVote::query()
                ->where('user_id', $viewerId)
                ->whereIn('map_id', $paginator->pluck('id'))
                ->get()
                ->keyBy('map_id')
                ->all();
        }

        $cards = $paginator->getCollection()->map(function (Map $map) use ($voteByMapId) {
            $vote = $voteByMapId[$map->id] ?? null;

            return $this->exploreCard($map, $vote instanceof MapVote ? $vote : null);
        })->values()->all();

        return Inertia::render('MapExplore', [
            'maps' => $cards,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
                'prev_url' => $paginator->previousPageUrl(),
                'next_url' => $paginator->nextPageUrl(),
                'pages' => $this->explorePaginationPages($paginator),
            ],
            'filters' => [
                'q' => $filters['q'],
                'author' => $filters['author'],
                'uuid' => $filters['uuid'],
                'sort' => $filters['sort'],
                'per_page' => $filters['per_page'],
            ],
        ]);
    }

    /**
     * Map editor. Optional {@link Map} UUID in the path reloads that map (same as choosing it in the sidebar).
     *
     * Guests may open **published** maps only (read-only in the UI). The bare editor requires authentication.
     */
    public function builder(Request $request, ?Map $map = null): InertiaResponse
    {
        if ($map !== null) {
            Gate::authorize('view', $map);
        }

        $maps = $request->user() !== null
            ? Map::query()
                ->where('user_id', $request->user()->id)
                ->orderByDesc('updated_at')
                ->get(['id', 'uuid', 'name', 'updated_at', 'published'])
            : collect();

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

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * @param  LengthAwarePaginator<int, Map>  $paginator
     * @return list<array{page: int, url: string, active: bool}>
     */
    private function explorePaginationPages(LengthAwarePaginator $paginator): array
    {
        if ($paginator->lastPage() <= 1) {
            return [];
        }

        $current = $paginator->currentPage();
        $last = $paginator->lastPage();
        $window = 7;
        $start = max(1, $current - (int) floor($window / 2));
        $end = min($last, $start + $window - 1);
        $start = max(1, $end - $window + 1);

        $pages = [];
        for ($i = $start; $i <= $end; $i++) {
            $pages[] = [
                'page' => $i,
                'url' => $paginator->url($i),
                'active' => $i === $current,
            ];
        }

        return $pages;
    }
}
