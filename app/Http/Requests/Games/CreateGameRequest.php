<?php

namespace App\Http\Requests\Games;

use App\Games\GameConstants;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'max_players' => ['required', 'integer', 'min:'.GameConstants::MIN_PLAYERS, 'max:'.GameConstants::MAX_PLAYERS],
            'map_uuid' => [
                'nullable',
                'string',
                'uuid',
                Rule::exists('maps', 'uuid')->where('published', true),
            ],
        ];
    }
}
