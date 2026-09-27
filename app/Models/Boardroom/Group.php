<?php

namespace App\Models\Boardroom;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Group extends Model
{
    protected $table = 'br_groups';

    protected $fillable = ['name', 'description', 'is_default'];

    protected $casts = ['is_default' => 'boolean'];

    public function agents(): BelongsToMany
    {
        return $this->belongsToMany(Agent::class, 'br_group_agents', 'group_id', 'agent_id')
            ->withPivot('sort_order')->orderBy('br_group_agents.sort_order');
    }
}
