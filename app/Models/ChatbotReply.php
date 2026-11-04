<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/** Isang padala ng chatbot (send_content) o isang sheet row, kasama ang skipped at failed. */
class ChatbotReply extends Model
{
    public $timestamps = false;

    protected $fillable = ['page_id', 'psid', 'chat_id', 'flow_id', 'node_id', 'user_id', 'kind', 'content', 'status', 'error', 'raw', 'http', 'sent_at',
        'flow_version_id', 'sequence_id', 'sequence_step', 'ad_id', 'tags', 'replied_at', 'reply_seconds', 'via',
        'client_ref', 'confirmed_at', 'confirmed_status', 'route'];

    protected $casts = ['content' => 'array', 'sent_at' => 'datetime', 'tags' => 'array', 'replied_at' => 'datetime', 'confirmed_at' => 'datetime'];

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

    /** @var array{0: \WeakReference, 1: bool}|null */
    private static ?array $hasVia = null;

    /** May column na via na (aling bot ang nagdala)? Bago ma-migrate: hindi isinusulat. Isang tingin kada app. */
    public static function hasVia(): bool
    {
        if (self::$hasVia === null || self::$hasVia[0]->get() !== app()) {
            self::$hasVia = [\WeakReference::create(app()), Schema::hasTable('chatbot_replies') && Schema::hasColumn('chatbot_replies', 'via')];
        }

        return self::$hasVia[1];
    }

    /** Ang via para sa create(): ['via' => …] kapag may column at may value, kung hindi ay wala. */
    public static function viaColumn(?string $via): array
    {
        return $via !== null && self::hasVia() ? ['via' => $via] : [];
    }

    /** @var array{0: \WeakReference, 1: bool}|null */
    private static ?array $hasRoute = null;

    /** May column na route na (ang address ng forbeast gateway na dinaanan)? Bago ma-migrate: hindi isinusulat. Isang tingin kada app. */
    public static function hasRoute(): bool
    {
        if (self::$hasRoute === null || self::$hasRoute[0]->get() !== app()) {
            self::$hasRoute = [\WeakReference::create(app()), Schema::hasTable('chatbot_replies') && Schema::hasColumn('chatbot_replies', 'route')];
        }

        return self::$hasRoute[1];
    }

    /**
     * Ang address para sa create(): ['route' => …] kapag forbeast ang nagdala, may address at may column; kung hindi ay wala
     * (ang ibang daan ay walang address; ang null ay ang default).
     */
    public static function routeColumn(?string $via, ?string $route): array
    {
        return $via === \App\Services\Chatbot\ChatbotProvider::FORBEAST && $route !== null && $route !== '' && self::hasRoute() ? ['route' => mb_substr($route, 0, 30)] : [];
    }

    /** @var array{0: \WeakReference, 1: bool}|null */
    private static ?array $hasConfirm = null;

    /** May column na client_ref, confirmed_at at confirmed_status na (ang totoong oras ng padala)? Isang tingin kada app. */
    public static function hasConfirm(): bool
    {
        if (self::$hasConfirm === null || self::$hasConfirm[0]->get() !== app()) {
            self::$hasConfirm = [\WeakReference::create(app()), Schema::hasTable('chatbot_replies') && Schema::hasColumn('chatbot_replies', 'confirmed_at')];
        }

        return self::$hasConfirm[1];
    }

    /**
     * Ang client_ref at ang kumpirmasyon para sa create(). Ang Botcake ay sabay (bumabalik kapag naipadala na sa Meta), kaya
     * ang sent nito ay kumpirmado na sa oras ng talaan ($sentAt: ang parehong value ng sent_at); ang Forbeast ay hinihintay
     * ang send_status. Wala pang column = wala.
     */
    public static function confirmColumns(?string $clientRef, ?string $via, string $status, \DateTimeInterface $sentAt): array
    {
        if (!self::hasConfirm()) return [];
        $out = ['client_ref' => $clientRef];
        if ($via === \App\Services\Chatbot\ChatbotProvider::BOTCAKE && $status === 'sent') $out += ['confirmed_at' => $sentAt, 'confirmed_status' => 'sent'];

        return $out;
    }
}
