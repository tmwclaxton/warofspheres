<?php

namespace App\Http\Requests\Maps;

use App\Games\GameConstants;
use App\Maps\MapEditorGrid;
use App\Maps\MapMarkers;
use App\Maps\TerrainCatalog;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveMapRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($this->isMethod('POST')) {
            return $this->user() !== null;
        }

        $map = $this->route('map');

        return $map !== null && $this->user()?->id === $map->user_id;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'data' => ['required', 'array'],
            'data.version' => ['required', 'integer', Rule::in([1, 2])],
            'data.cellRows' => ['required', 'integer', 'min:'.MapEditorGrid::MIN_CELL_ROWS, 'max:'.MapEditorGrid::MAX_CELL_ROWS],
            'data.cellCols' => ['required', 'integer', 'min:'.MapEditorGrid::MIN_CELL_COLS, 'max:'.MapEditorGrid::MAX_CELL_COLS],
            'data.cells' => ['required', 'array'],
            'data.teamCount' => [
                Rule::requiredIf(fn (): bool => (int) $this->input('data.version', 1) === 2),
                'nullable',
                'integer',
                'min:'.GameConstants::MIN_PLAYERS,
                'max:'.GameConstants::MAX_PLAYERS,
            ],
            'data.markers' => [
                Rule::requiredIf(fn (): bool => (int) $this->input('data.version', 1) === 2),
                'nullable',
                'array',
            ],
            // Must be listed here or `validated('data')` drops it and palette colours reset on reload.
            'data.teamPaletteSlots' => ['sometimes', 'nullable', 'array'],
            'data.teamPaletteSlots.*' => ['integer', 'min:0', 'max:'.(GameConstants::MAX_PLAYERS - 1)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $data = $this->input('data');
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

            foreach (MapMarkers::validatePersistable($data) as $message) {
                $validator->errors()->add('data.markers', $message);
            }
        });
    }
}
