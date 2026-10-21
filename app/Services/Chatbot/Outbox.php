<?php

namespace App\Services\Chatbot;

/** Kung saan napupunta ang mga padala at sheet row ng isang takbo: Botcake at Google (live), o ang Test tab. */
interface Outbox
{
    /**
     * @param array<int, array{type: string, text?: string, url?: string}> $messages
     * @return array{status: string, error: ?string} status: sent | failed | skipped
     */
    public function send(FlowRun $run, string $nodeId, array $messages): array;

    /**
     * @param array<int, string> $row
     * @return array{status: string, error: ?string}
     */
    public function appendRow(FlowRun $run, string $nodeId, string $spreadsheetId, string $tab, array $row): array;
}
