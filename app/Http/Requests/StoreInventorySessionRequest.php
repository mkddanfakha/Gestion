<?php

namespace App\Http\Requests;

use App\Enums\InventoryScopeType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInventorySessionRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'scope_type' => ['required', Rule::enum(InventoryScopeType::class)],
            'scope_value' => ['nullable', 'array'],
            'scope_value.category_id' => [
                Rule::requiredIf(fn () => $this->input('scope_type') === InventoryScopeType::Category->value),
                'integer',
                'exists:categories,id',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Le nom de la session est obligatoire.',
            'scope_type.required' => 'Le type de périmètre est obligatoire.',
            'scope_value.category_id.required' => 'Une catégorie est requise pour ce périmètre.',
            'scope_value.category_id.exists' => 'La catégorie sélectionnée est invalide.',
        ];
    }
}
