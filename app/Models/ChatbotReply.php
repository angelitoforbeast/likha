<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/** Isang padala ng chatbot (send_content) o isang sheet row, kasama ang skipped at failed. */
class ChatbotReply extends Model
{
    public $timestamps = false;

    protected $fillable = ['page_id', 'psid', 'chat_id', 'flow_id', 'node_id', 'user_id', 'kind', 'content', 'status', 'error', 'raw', 'http', 'sent_at',
        'flow_version_id', 'sequence_id', 'sequence_step', 'ad_id', 'tags', 'replied_at', 'reply_seconds'];

    protected $casts = ['content' => 'array', 'sent_at' => 'datetime', 'tags' => 'array', 'replied_at' => 'datetime'];

    /** @var array{0: \WeakReference, 1: bool}|null [ang app na tumingin, may column] */
    private static ?array $hasRaw = null;

    /**
     * May column na raw na (ang buong sagot ng pumalyang padala)? Bago ma-migrate: hindi isinusulat. Isang tingin kada
     * app (proseso, o bawat test).
     */
    public static function hasRaw(): bool
    {
        if (self::$hasRaw === null || self::$hasRaw[0]->get() !== app()) {
            self::$hasRaw = [\WeakReference::create(app()), Schema::hasTable('chatbot_replies') && Schema::hasColumn('chatbot_replies', 'raw')];
        }

        return self::$hasRaw[1];
    }
}
