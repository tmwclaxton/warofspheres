<?php

namespace App\Http\Requests\Games;

use Illuminate\Foundation\Http\FormRequest;

class SubmitOrdersRequest extends FormRequest
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
            'troop_orders' => ['present', 'array'],
            'troop_orders.*.0' => ['required', 'integer'],
            'troop_orders.*.1' => ['required', 'array'],
            'troop_orders.*.1.*.0' => ['required', 'numeric'],
            'troop_orders.*.1.*.1' => ['required', 'numeric'],
            'city_orders' => ['present', 'array'],
            'city_orders.*.0' => ['required', 'integer'],
            'city_orders.*.1' => ['present', 'array'],
        ];
    }
}
