<?php

namespace App\Models\Boardroom;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    protected $table = 'br_projects';

    protected $fillable = ['user_id', 'name', 'description', 'archived_at'];

    protected $casts = ['archived_at' => 'datetime'];

    public function meetings(): HasMany
    {
        return $this->hasMany(Meeting::class, 'project_id');
    }
}
