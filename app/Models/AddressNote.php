<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Isang natutunang address (spec 135): ang isinulat ng customer at ang J&T address na itinama o na-deliver. */
class AddressNote extends Model
{
    protected $table = 'address_notes';

    protected $guarded = [];

    protected $casts = ['conflict' => 'boolean', 'conflicts' => 'array', 'order_ids' => 'array', 'ledger' => 'array', 'reviewed_at' => 'datetime'];

    /** @return array<int, string> */
    public function sources(): array
    {
        return array_values(array_filter(explode(',', (string) $this->source)));
    }
}
