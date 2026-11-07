<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StatusStoreRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|max:100',
            'description' => 'max:255|string|nullable',
            'color' => 'required',
            'group_id' => 'required|exists:groups,id',
            'category' => ['sometimes', Rule::in(['work', 'waiting', 'done'])],
            'sla_minutes' => ['sometimes', 'integer', 'min:1', 'max:525600'],
            'position' => ['sometimes', 'integer', 'min:0', 'max:10000'],
        ];
    }
}

