<?php

namespace App\Services\Chatbot;

/** Ang Test tab: walang ipinapadala sa Botcake at walang isinusulat sa sheet; itinatala lang sa takbo. */
class TestOutbox implements Outbox
{
    public function send(FlowRun $run, string $nodeId, array $messages): array
    {
        return ['status' => 'test', 'error' => null];
    }

    public function appendRow(FlowRun $run, string $nodeId, string $spreadsheetId, string $tab, array $row): array
    {
        return ['status' => 'test', 'error' => null];
    }
}
