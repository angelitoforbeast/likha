<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-video AI analysis result for the GPT Ad Generator.
 *
 * Created when user uploads a video and clicks "🎬 Analyze Video".
 * Backend extracts frames + transcript, runs them through GPT-4o Vision +
 * Whisper, and persists the structured AI output here. The original video
 * file is deleted right after analysis — only metadata + AI outputs survive.
 *
 * Lookup-by-hash lets us avoid re-spending OpenAI tokens for repeat uploads
 * of the same file (matched by SHA-256).
 */
class GptVideoAnalysis extends Model
{
    protected $table = 'gpt_video_analyses';

    protected $fillable = [
        'file_name',
        'file_sha256',
        'file_size_bytes',
        'duration_seconds',
        'frame_count',
        'uploaded_by_user_id',
        'uploaded_by_email',
        'transcript',
        'summary',
        'item_name',
        'description',
        'model_used',
        'cost_estimate_php',
        'analyzed_at',
    ];

    protected $casts = [
        'analyzed_at'       => 'datetime',
        'duration_seconds'  => 'float',
        'cost_estimate_php' => 'float',
        'frame_count'       => 'integer',
        'file_size_bytes'   => 'integer',
    ];

    /** The user who uploaded this video. Nullable for legacy/system rows. */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
