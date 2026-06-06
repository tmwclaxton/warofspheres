<?php

namespace App\Http\Requests\Maps;

use App\Maps\MapEditorGrid;
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

    protected function prepareForValidation(): void
    {
        $data = $this->input('data');
        if (! is_array($data) || ! isset($data['bridges']) || ! is_array($data['bridges'])) {
            return;
        }

        foreach ($data['bridges'] as $r => $row) {
            if (! is_array($row)) {
                continue;
            }
            foreach ($row as $c => $v) {
                $bool = filter_var($v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                $data['bridges'][$r][$c] = $bool === null ? false : $bool;
            }
        }

        $this->merge(['data' => $data]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'data' => ['required', 'array'],
            'data.version' => ['required', 'integer', Rule::in([1])],
            'data.cellRows' => ['required', 'integer', 'min:'.MapEditorGrid::MIN_CELL_ROWS, 'max:'.MapEditorGrid::MAX_CELL_ROWS],
            'data.cellCols' => ['required', 'integer', 'min:'.MapEditorGrid::MIN_CELL_COLS, 'max:'.MapEditorGrid::MAX_CELL_COLS],
            'data.cells' => ['required', 'array'],
            'data.bridges' => ['required', 'array'],
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
            $bridges = $data['bridges'] ?? null;

            if (! is_array($cells) || ! is_array($bridges)) {
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

            if (count($bridges) !== $expectedRows) {
                $validator->errors()->add('data.bridges', "Bridge grid must have {$expectedRows} rows.");

                return;
            }

            for ($r = 0; $r < $expectedRows; $r++) {
                if (! is_array($cells[$r]) || count($cells[$r]) !== $expectedCols) {
                    $validator->errors()->add('data.cells', "Row {$r} must have {$expectedCols} cells.");

                    return;
                }

                if (! is_array($bridges[$r]) || count($bridges[$r]) !== $expectedCols) {
                    $validator->errors()->add('data.bridges', "Bridge row {$r} must have {$expectedCols} values.");

                    return;
                }

                for ($c = 0; $c < $expectedCols; $c++) {
                    $terrain = $cells[$r][$c];
                    if (! is_string($terrain) || ! TerrainCatalog::isValid($terrain)) {
                        $validator->errors()->add('data.cells', "Invalid terrain at row {$r}, column {$c}.");

                        return;
                    }

                    if (! is_bool($bridges[$r][$c])) {
                        $validator->errors()->add('data.bridges', "Bridge at row {$r}, column {$c} must be boolean.");

                        return;
                    }
                }
            }
        });
    }
}
