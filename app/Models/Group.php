<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Group extends Model
{
    protected $fillable = [
        'name',
        'description'
    ];
    protected $table = 'groups';

    public function tasks() {
        return $this->hasMany('App\Models\Task', 'group_id');
    }

    public function users() {
        return $this->belongsToMany(User::class, 'user_groups');
    }
}
