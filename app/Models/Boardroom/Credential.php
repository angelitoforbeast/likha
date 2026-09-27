<?php

namespace App\Models\Boardroom;

use Illuminate\Database\Eloquent\Model;

class Credential extends Model
{
    protected $table = 'br_credentials';

    protected $fillable = [
        'agent_id', 'provider', 'label', 'secret_encrypted', 'last4', 'updated_by',
        'last_tested_at', 'last_test_ok', 'last_test_message',
    ];

    // Hindi kailanman isinasama sa array/JSON ang encrypted secret.
    protected $hidden = ['secret_encrypted'];

    protected $casts = [
        'last_tested_at' => 'datetime',
        'last_test_ok'   => 'boolean',
    ];

    /** Ang tanging anyo ng credential na pwedeng ipadala sa browser. */
    public function masked(): array
    {
        return [
            'provider'          => $this->provider,
            'label'             => $this->label,
            'masked'            => '••••••••' . ($this->last4 ?: ''),
            'updated_at'        => optional($this->updated_at)->toDateTimeString(),
            'last_tested_at'    => optional($this->last_tested_at)->toDateTimeString(),
            'last_test_ok'      => $this->last_test_ok,
            'last_test_message' => $this->last_test_message,
        ];
    }
}
