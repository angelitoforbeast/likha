<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * CEO-only mirror of the Cogs model.
 *
 * Same schema as `cogs` but isolated sa `cogs_ceo` table — let's the CEO maintain
 * their own item_value separate from what Marketing/MOIC sees and edits.
 *
 * Used by OwnerPrivateController + summary computations when the viewer is CEO:
 * profit calcs pull from this table instead of `cogs`. Marketing/MOIC viewers
 * never touch this table.
 */
class CogsCeo extends Model
{
    protected $table = 'cogs_ceo';

    protected $fillable = ['item_name', 'date', 'unit_cost', 'history_logs'];

    protected $casts = [
        'date'         => 'date',
        'history_logs' => 'array',
        'unit_cost'    => 'decimal:2',
    ];
}
