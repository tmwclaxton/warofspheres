<?php

namespace App\Http\Requests\Maps;

use App\Maps\ValidatesMapSavePayload;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class PublishMapRequest extends FormRequest
{
    public function authorize(): bool
    {
        $map = $this->route('map');

        return $map !== null
            && $this->user() !== null
            && $this->user()->id === $map->user_id
            && ! $map->published;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return ValidatesMapSavePayload::rules($this);
    }

    public function prepareForValidation(): void
    {
        $map = $this->route('map');
        if ($map === null) {
            return;
        }

        $this->merge([
            'name' => $map->name,
            'data' => $map->data,
        ]);
    }

    public function withValidator(Validator $validator): void
    {
        ValidatesMapSavePayload::afterValidator($validator, fullMarkerValidation: true);
    }
}
