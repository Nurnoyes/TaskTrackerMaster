<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Task extends Model
{
    protected $fillable = [
        'name',
        'description',
        'completed',
        'status_id',
        'group_id',
        'type',
        'manager_id',
        'deadline',
        'status_changed_at',
    ];
    protected $table = 'tasks';

    protected $attributes = ['expired' => false, 'completed' => false, 'type' => 'general'];

    protected function casts(): array
    {
        return [
            'completed' => 'boolean',
            'expired' => 'boolean',
            'deadline' => 'immutable_datetime',
            'status_changed_at' => 'immutable_datetime',
        ];
    }

    public function status(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo('App\Models\Status', 'status_id');
    }

    public function group(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo('App\Models\Group', 'group_id');
    }

    public function users() {
        return $this->belongsToMany(User::class, 'user_tasks');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function history(): HasMany
    {
        return $this->hasMany(TaskStatusHistory::class)->orderBy('entered_at')->orderBy('id');
    }
}
