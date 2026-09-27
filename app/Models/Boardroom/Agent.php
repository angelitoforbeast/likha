<?php

namespace App\Models\Boardroom;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Agent extends Model
{
    protected $table = 'br_agents';

    public const ROLE_TYPES = ['moderator', 'contributor', 'reviewer'];

    protected $fillable = [
        'handle', 'display_name', 'role_type', 'description', 'instructions',
        'provider', 'model', 'settings', 'enabled', 'sort_order', 'archived_at',
    ];

    protected $casts = [
        'settings'    => 'array',
        'enabled'     => 'boolean',
        'archived_at' => 'datetime',
    ];

    public function credentials(): HasMany
    {
        return $this->hasMany(Credential::class, 'agent_id');
    }

    /** Ang credential ng role na ito para sa KASALUKUYANG provider nito (hindi kailanman ng ibang role/provider). */
    public function credential(): ?Credential
    {
        return $this->credentials()->where('provider', $this->provider)->first();
    }

    public function scopeActive($q)
    {
        return $q->whereNull('archived_at');
    }

    /** Non-secret config na kinukunan ng snapshot kada meeting run. */
    public function snapshot(): array
    {
        return [
            'agent_id'     => $this->id,
            'handle'       => $this->handle,
            'display_name' => $this->display_name,
            'role_type'    => $this->role_type,
            'instructions' => (string) $this->instructions,
            'provider'     => $this->provider,
            'model'        => $this->model,
            'settings'     => $this->settings ?: [],
        ];
    }
}
