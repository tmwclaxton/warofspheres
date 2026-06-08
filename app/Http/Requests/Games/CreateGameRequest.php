<?php

namespace App\Http\Requests\Games;

use App\Maps\MapMarkers;
use App\Models\Map;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CreateGameRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'map_uuid' => [
                'required',
                'string',
                'uuid',
                Rule::exists('maps', 'uuid')->where('published', true),
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $map = Map::query()
                ->where('uuid', $this->string('map_uuid'))
                ->where('published', true)
                ->first();

            if ($map === null) {
                return;
            }

            $data = $map->data;
            if (! is_array($data)) {
                $validator->errors()->add('map_uuid', 'This map has invalid data.');

                return;
            }

            foreach (MapMarkers::validate($data) as $message) {
                $validator->errors()->add('map_uuid', $message);
            }
        });
    }
}
