<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Task extends Model
{
    protected $fillable = [
        'name',
        'description',
        'completed',
        'status_id',
        'group_id',
    ];
    protected $table = 'tasks';

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
}
