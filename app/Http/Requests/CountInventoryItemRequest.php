<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CountInventoryItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'quantity_counted' => ['required', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'quantity_counted.required' => 'La quantité comptée est obligatoire.',
            'quantity_counted.integer' => 'La quantité comptée doit être un entier.',
            'quantity_counted.min' => 'La quantité comptée doit être supérieure ou égale à 0.',
        ];
    }
}
