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
