<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ScanInventoryItemRequest extends FormRequest
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
            'barcode' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'barcode.required' => 'Le code-barres est obligatoire.',
            'barcode.string' => 'Le code-barres est invalide.',
        ];
    }
}
