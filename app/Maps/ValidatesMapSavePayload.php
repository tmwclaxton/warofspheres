<?php

namespace App\Maps;

use App\Games\GameConstants;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class ValidatesMapSavePayload
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public static function rules(Request $request): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'data' => ['required', 'array'],
            'data.version' => ['required', 'integer', Rule::in([1, 2])],
            'data.cellRows' => ['required', 'integer', 'min:'.MapEditorGrid::MIN_CELL_ROWS, 'max:'.MapEditorGrid::MAX_CELL_ROWS],
            'data.cellCols' => ['required', 'integer', 'min:'.MapEditorGrid::MIN_CELL_COLS, 'max:'.MapEditorGrid::MAX_CELL_COLS],
            'data.cells' => ['required', 'array'],
            'data.teamCount' => [
                Rule::requiredIf(fn (): bool => (int) $request->input('data.version', 1) === 2),
                'nullable',
                'integer',
                'min:'.GameConstants::MIN_PLAYERS,
                'max:'.GameConstants::MAX_PLAYERS,
            ],
            'data.markers' => ['nullable', 'array'],
            'data.teamPaletteSlots' => ['sometimes', 'nullable', 'array'],
            'data.teamPaletteSlots.*' => ['integer', 'min:0', 'max:'.(GameConstants::MAX_PLAYERS - 1)],
        ];
    }

    /**
     * @param  bool  $fullMarkerValidation  When true, runs full playability rules (capitals, flags, spacing,
     *                                      connectivity) in addition to structural checks. Use for publish.
     */
    public static function afterValidator(Validator $validator, bool $fullMarkerValidation = false): void
    {
        $validator->after(function (Validator $validator) use ($fullMarkerValidation): void {
            $data = $validator->getData()['data'] ?? null;
            if (! is_array($data)) {
                return;
            }

            $cells = $data['cells'] ?? null;

            if (! is_array($cells)) {
                return;
            }

            $declaredRows = $data['cellRows'] ?? null;
            $declaredCols = $data['cellCols'] ?? null;
            if (! is_numeric($declaredRows)) {
                $validator->errors()->add('data.cellRows', 'cellRows must be a number.');

                return;
            }
            if (! is_numeric($declaredCols)) {
                $validator->errors()->add('data.cellCols', 'cellCols must be a number.');

                return;
            }
            $expectedRows = (int) $declaredRows;
            $expectedCols = (int) $declaredCols;

            if (! MapEditorGrid::dimensionsAreAllowed($expectedRows, $expectedCols)) {
                $validator->errors()->add('data.cellRows', 'Map dimensions are out of allowed range.');

                return;
            }

            if (count($cells) !== $expectedRows) {
                $validator->errors()->add('data.cells', "Terrain grid must have {$expectedRows} rows (cellRows).");

                return;
            }

            for ($r = 0; $r < $expectedRows; $r++) {
                if (! is_array($cells[$r]) || count($cells[$r]) !== $expectedCols) {
                    $validator->errors()->add('data.cells', "Row {$r} must have {$expectedCols} cells.");

                    return;
                }

                for ($c = 0; $c < $expectedCols; $c++) {
                    $terrain = $cells[$r][$c];
                    if (! is_string($terrain) || ! TerrainCatalog::isValid($terrain)) {
                        $validator->errors()->add('data.cells', "Invalid terrain at row {$r}, column {$c}.");

                        return;
                    }
                }
            }

            $version = (int) ($data['version'] ?? 1);
            if ($version !== 2) {
                return;
            }

            if (! array_key_exists('markers', $data)) {
                $validator->errors()->add(
                    'data.markers',
                    'Capitals & troops (markers) must be sent with the map - use an empty array if none are placed yet.',
                );

                return;
            }

            $markerErrors = $fullMarkerValidation
                ? MapMarkers::validate($data)
                : MapMarkers::validatePersistable($data);

            foreach ($markerErrors as $message) {
                $validator->errors()->add('data.markers', $message);
            }
        });
    }
}
