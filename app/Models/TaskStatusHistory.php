<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskStatusHistory extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'task_id', 'group_id', 'status_id', 'status_original_id', 'manager_id', 'manager_name', 'task_type',
        'status_name', 'status_color', 'status_category', 'status_position',
        'entered_at', 'left_at', 'deadline', 'is_rework',
    ];

    protected function casts(): array
    {
        return [
            'entered_at' => 'immutable_datetime',
            'left_at' => 'immutable_datetime',
            'deadline' => 'immutable_datetime',
            'is_rework' => 'boolean',
            'status_position' => 'integer',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Status::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }
}
