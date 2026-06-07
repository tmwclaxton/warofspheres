<?php

namespace App\Http\Controllers\Maps;

use App\Http\Controllers\Controller;
use App\Http\Requests\Maps\SaveMapRequest;
use App\Maps\MapEditorGrid;
use App\Maps\MapMarkers;
use App\Maps\TerrainCatalog;
use App\Models\Map;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class MapController extends Controller
{
    public function builder(): InertiaResponse
    {
        $maps = Map::query()
            ->where('user_id', auth()->id())
            ->orderByDesc('updated_at')
            ->get(['id', 'uuid', 'name', 'updated_at']);

        return Inertia::render('MapBuilder', [
            'maps' => $maps,
            'terrainTypes' => TerrainCatalog::forClient(),
            'teamColors' => MapMarkers::teamColorsForClient(),
            'defaults' => MapEditorGrid::emptyData(),
        ]);
    }

    public function index(): JsonResponse
    {
        $maps = Map::query()
            ->where('user_id', auth()->id())
            ->orderByDesc('updated_at')
            ->get(['id', 'uuid', 'name', 'updated_at']);

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

    /**
     * @return array{id: int, uuid: string, name: string, data: array<string, mixed>, updated_at: string|null}
     */
    private function mapFull(Map $map): array
    {
        return [
            'id' => $map->id,
            'uuid' => $map->uuid,
            'name' => $map->name,
            'data' => $map->data,
            'updated_at' => $map->updated_at?->toIso8601String(),
        ];
    }
}
