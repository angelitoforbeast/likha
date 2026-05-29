<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Imported conversation rows from /conversation/cancel-detector sources.
 *
 * `ai_analysis` is Phase 2 — gets filled by background OpenAI job that
 * classifies the conversation as 'cancel' | 'not_cancel' | 'unknown'.
 * NULL = not yet analyzed.
 */
class CancelDetector extends Model
{
    protected $fillable = [
        'page_name',
        'name',
        'phone_number',
        'shop_details',
        'conversation',
        'ai_analysis',
        'ai_analyzed_at',
        'imported_run_id',
    ];

    protected $casts = [
        'ai_analyzed_at' => 'datetime',
    ];
}
