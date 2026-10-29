<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Isang flow ng sariling chatbot: ang graph (nodes at edges) ay iisang JSON (FlowGraph). Ang graph ay ang published
 * (ang live na takbo); ang draft_graph ay ang ini-edit sa editor (null = walang hindi pa naipa-publish).
 */
class ChatbotFlow extends Model
{
    protected $fillable = ['name', 'graph', 'draft_graph', 'updated_by'];

    protected $casts = ['graph' => 'array', 'draft_graph' => 'array', 'draft_version' => 'integer', 'published_at' => 'datetime'];

    /** Anumang sulat o bura: ang naka-cache na ayos ng bot (BotConfigCache) ay binubura. */
    protected static function booted(): void
    {
        static::saved(fn (self $m) => \App\Services\Chatbot\BotConfigCache::forgetFlow((int) $m->id));
        static::deleted(fn (self $m) => \App\Services\Chatbot\BotConfigCache::forgetFlow((int) $m->id));
    }

    /** Ang mga titik ng template code: walang 0/O at 1/I/L, para hindi magkamali sa pagbasa at pag-type. */
    public const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    public const CODE_PATTERN = '/^LK-[ABCDEFGHJKMNPQRSTUVWXYZ2-9]{4}-[ABCDEFGHJKMNPQRSTUVWXYZ2-9]{2}$/';

    /** Ang code gaya ng itinatabi (malalaking titik, walang espasyo sa gilid), o null kapag hindi anyong LK-XXXX-XX. */
    public static function normalizeCode(mixed $code): ?string
    {
        $code = is_string($code) ? strtoupper(trim($code)) : '';

        return preg_match(self::CODE_PATTERN, $code) ? $code : null;
    }

    /** Ang flow na may ganitong code (hindi case-sensitive), o null: hindi kilala, binawi o maling anyo. */
    public static function findByTemplateCode(mixed $code): ?self
    {
        $code = self::normalizeCode($code);

        return $code === null ? null : self::where('template_code', $code)->first();
    }

    /** Bagong code (ang luma, kung mayroon, ay hindi na gumagana). Ang unique index ang huling bantay sa sabay na gawa. */
    public function issueTemplateCode(): string
    {
        $pick = fn (int $n) => implode('', array_map(fn () => self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)], range(1, $n)));
        for ($try = 1; ; $try++) {
            $code = 'LK-' . $pick(4) . '-' . $pick(2);
            if (self::where('template_code', $code)->exists()) continue;
            try {
                $this->forceFill(['template_code' => $code])->save();

                return $code;
            } catch (\Illuminate\Database\QueryException $e) {
                if ($try >= 5) throw $e;
            }
        }
    }

    /** Binawi: ang code ay hindi na gumagana; ang mga page na nakatali na sa flow ay hindi ginagalaw. */
    public function revokeTemplateCode(): void
    {
        $this->forceFill(['template_code' => null])->save();
    }

    /** Ang graph na ini-edit: ang draft, o ang published kapag walang draft. */
    public function draft(): ?array
    {
        return is_array($this->draft_graph) ? $this->draft_graph : (is_array($this->graph) ? $this->graph : null);
    }

    /** never | draft | published */
    public function status(): string
    {
        if ($this->published_at === null) return 'never';

        return $this->draft_graph !== null ? 'draft' : 'published';
    }
}
