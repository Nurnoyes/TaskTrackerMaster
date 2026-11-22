<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TaskStoreRequest extends FormRequest
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
            'status_id' => 'required|integer|exists:statuses,id',
            'group_id' => 'required|integer|exists:groups,id',
            'completed' => 'required|boolean',
            'type' => ['sometimes', 'required', 'string', 'max:100'],
            'manager_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('user_groups', 'user_id')->where('group_id', $this->input('group_id')),
            ],
            'deadline' => ['sometimes', 'required', 'date', 'after:now'],
            'expired' => ['missing'],
            'status_changed_at' => ['missing'],
        ];
    }
}
