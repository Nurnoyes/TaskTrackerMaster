<?php

namespace App\Http\Requests;

use App\Models\Task;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TaskUpdateRequest extends FormRequest
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
        $groupId = $this->input('group_id') ?? Task::find($this->route('taskId'))?->group_id;

        return [
            'name' => 'max:100',
            'description' => 'max:255|string|nullable',
            'status_id' => 'integer|exists:statuses,id',
            'group_id' => 'integer|exists:groups,id',
            'completed' => 'boolean',
            'type' => ['sometimes', 'required', 'string', 'max:100'],
            'manager_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('user_groups', 'user_id')->where('group_id', $groupId),
            ],
            'deadline' => ['sometimes', 'required', 'date', 'after:now'],
            'expired' => ['missing'],
            'status_changed_at' => ['missing'],
        ];
    }
}
