<?php

namespace App\Boardroom\Orchestrator;

use App\Models\Boardroom\Issue;
use App\Models\Boardroom\Meeting;
use App\Models\Boardroom\Message;

/**
 * Nagva-validate ng structured output ng model BAGO ito gamitin ng backend.
 * Ang model ay nagmumungkahi lang; ang mga ID at transition ay tinitingnan dito laban sa database.
 *
 * Bawat method ay nagbabalik ng ['ok' => true, 'data' => [...]] o ['ok' => false, 'error' => '...'].
 */
class OutputInterpreter
{
    public function __construct(private Rules $rules)
    {
    }

    /** Tolerant na JSON parse: tinatanggal ang code fence at anumang text sa labas ng object. */
    public function parseJson(string $text): ?array
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $text) ?? $text;

        $data = json_decode($text, true);
        if (! is_array($data)) {
            $start = strpos($text, '{');
            $end   = strrpos($text, '}');
            if ($start === false || $end === false || $end <= $start) {
                return null;
            }
            $data = json_decode(substr($text, $start, $end - $start + 1), true);
        }

        return (is_array($data) && ! array_is_list($data)) ? $data : null;
    }

    public function route(Meeting $m, int $selfAgentId, string $text): array
    {
        $d = $this->parseJson($text);
        if ($d === null) {
            return $this->fail('Output is not a valid JSON object.');
        }
        foreach (['action', 'recipient_agent_id', 'reply_to_message_id', 'issue_id', 'public_message', 'discussion_status'] as $key) {
            if (! array_key_exists($key, $d)) {
                return $this->fail("Missing key: {$key}.");
            }
        }

        $action = (string) $d['action'];
        if (! in_array($action, Prompts::ROUTE_ACTIONS, true)) {
            return $this->fail("Invalid action \"{$action}\". Allowed: " . implode(', ', Prompts::ROUTE_ACTIONS) . '.');
        }
        $status = (string) $d['discussion_status'];
        if (! in_array($status, Prompts::ROUTE_STATUSES, true)) {
            return $this->fail("Invalid discussion_status \"{$status}\".");
        }
        $public = trim((string) (is_string($d['public_message']) ? $d['public_message'] : ''));
        if ($public === '') {
            return $this->fail('public_message must be a non-empty string.');
        }

        $recipient = $this->intOrNull($d['recipient_agent_id']);
        $issueId   = $this->intOrNull($d['issue_id']);
        $replyTo   = $this->intOrNull($d['reply_to_message_id']);

        if ($d['recipient_agent_id'] !== null && $recipient === null) {
            return $this->fail('recipient_agent_id must be an integer or null.');
        }

        // ── Mga ID: dapat kabilang sa meeting na ITO ──
        if ($issueId !== null && ! Issue::where('meeting_id', $m->id)->whereKey($issueId)->exists()) {
            return $this->fail("issue_id {$issueId} does not exist in this meeting. Use an issue_id from ISSUES or null.");
        }
        if ($replyTo !== null && ! Message::where('meeting_id', $m->id)->whereKey($replyTo)->exists()) {
            return $this->fail("reply_to_message_id {$replyTo} does not exist in this meeting. Use a [#id] from the transcript or null.");
        }

        if ($action === 'ask_agent') {
            if ($recipient === null) {
                return $this->fail('ask_agent requires recipient_agent_id.');
            }
            if ($recipient === $selfAgentId) {
                return $this->fail('recipient_agent_id cannot be your own agent_id.');
            }
            if (! $m->member($recipient)) {
                return $this->fail("recipient_agent_id {$recipient} is not a participant of this meeting. Use an agent_id from PARTICIPANTS.");
            }
        } elseif ($action === 'request_revision') {
            if ($recipient !== null) {
                $member = $m->member($recipient);
                if (! $member || ($member['role_type'] ?? '') !== 'contributor') {
                    return $this->fail("recipient_agent_id {$recipient} is not a contributor in this meeting. Use a contributor agent_id or null for all.");
                }
            }
        } else {
            $recipient = null;
        }

        $decision = [
            'action'              => $action,
            'recipient_agent_id'  => $recipient,
            'reply_to_message_id' => $replyTo,
            'issue_id'            => $issueId,
            'public_message'      => $public,
            'discussion_status'   => $status,
        ];

        // ── Transition: pinapayagan ba ng mga limit? Kung hindi, final na — may nakikitang paliwanag. ──
        if (! in_array($action, $this->rules->allowedActions($m), true)) {
            $decision['overridden_from'] = $action;
            $decision['action']          = 'finalize';
            $decision['discussion_status'] = 'ready_for_final';
            $decision['recipient_agent_id'] = null;
        }

        return ['ok' => true, 'data' => $decision];
    }

    public function review(Meeting $m, string $text): array
    {
        $d = $this->parseJson($text);
        if ($d === null) {
            return $this->fail('Output is not a valid JSON object.');
        }
        foreach (['public_message', 'verdict', 'issues', 'resolved_issue_ids'] as $key) {
            if (! array_key_exists($key, $d)) {
                return $this->fail("Missing key: {$key}.");
            }
        }

        $public = trim((string) (is_string($d['public_message']) ? $d['public_message'] : ''));
        if ($public === '') {
            return $this->fail('public_message must be a non-empty string.');
        }
        $verdict = (string) $d['verdict'];
        if (! in_array($verdict, Prompts::REVIEW_VERDICTS, true)) {
            return $this->fail("Invalid verdict \"{$verdict}\".");
        }
        if (! is_array($d['issues']) || ! is_array($d['resolved_issue_ids'])) {
            return $this->fail('issues and resolved_issue_ids must be arrays.');
        }
        if (count($d['issues']) > 12) {
            return $this->fail('Too many issues (max 12). Merge related ones.');
        }

        $issues = [];
        foreach ($d['issues'] as $n => $row) {
            if (! is_array($row)) {
                return $this->fail("issues[{$n}] must be an object.");
            }
            $title = trim((string) ($row['title'] ?? ''));
            if ($title === '') {
                return $this->fail("issues[{$n}].title is required.");
            }
            $severity = (string) ($row['severity'] ?? '');
            if (! in_array($severity, Prompts::SEVERITIES, true)) {
                return $this->fail("issues[{$n}].severity must be one of: " . implode(', ', Prompts::SEVERITIES) . '.');
            }
            $target = $this->intOrNull($row['target_agent_id'] ?? null);
            if ($target !== null && ! $m->member($target)) {
                return $this->fail("issues[{$n}].target_agent_id {$target} is not a participant. Use an agent_id from PARTICIPANTS or null.");
            }
            $issues[] = [
                'title'           => mb_substr($title, 0, 300),
                'detail'          => trim((string) ($row['detail'] ?? '')),
                'severity'        => $severity,
                'target_agent_id' => $target,
            ];
        }

        $resolved = [];
        foreach ($d['resolved_issue_ids'] as $id) {
            $id = $this->intOrNull($id);
            if ($id === null || ! Issue::where('meeting_id', $m->id)->whereKey($id)->exists()) {
                return $this->fail('resolved_issue_ids contains an id that is not in this meeting. Use issue_id values from ISSUES.');
            }
            $resolved[] = $id;
        }

        return ['ok' => true, 'data' => [
            'public_message'     => $public,
            'verdict'            => $verdict,
            'issues'             => $issues,
            'resolved_issue_ids' => array_values(array_unique($resolved)),
        ]];
    }

    /** Review ng mga sagot sa isang tanong ng user. */
    public function qreview(string $text): array
    {
        $d = $this->parseJson($text);
        if ($d === null) {
            return $this->fail('Output is not a valid JSON object.');
        }
        foreach (['public_message', 'verdict'] as $key) {
            if (! array_key_exists($key, $d)) {
                return $this->fail("Missing key: {$key}.");
            }
        }
        $public = trim((string) (is_string($d['public_message']) ? $d['public_message'] : ''));
        if ($public === '') {
            return $this->fail('public_message must be a non-empty string.');
        }
        $verdict = (string) $d['verdict'];
        if (! in_array($verdict, Prompts::QREVIEW_VERDICTS, true)) {
            return $this->fail("Invalid verdict \"{$verdict}\". Allowed: " . implode(', ', Prompts::QREVIEW_VERDICTS) . '.');
        }

        return ['ok' => true, 'data' => ['public_message' => $public, 'verdict' => $verdict]];
    }

    public function final(Meeting $m, string $text): array
    {
        $d = $this->parseJson($text);
        if ($d === null) {
            return $this->fail('Output is not a valid JSON object.');
        }
        foreach (['status', 'approach', 'reasons', 'alternatives', 'unresolved', 'next_actions', 'approvals_needed', 'issue_updates'] as $key) {
            if (! array_key_exists($key, $d)) {
                return $this->fail("Missing key: {$key}.");
            }
        }

        $status = (string) $d['status'];
        if (! in_array($status, Prompts::FINAL_STATUSES, true)) {
            return $this->fail("Invalid status \"{$status}\".");
        }
        $approach = trim((string) (is_string($d['approach']) ? $d['approach'] : ''));
        if ($approach === '') {
            return $this->fail('approach must be a non-empty string.');
        }

        $reasons = [];
        if (! is_array($d['reasons'])) {
            return $this->fail('reasons must be an array of strings.');
        }
        foreach ($d['reasons'] as $r) {
            if (is_string($r) && trim($r) !== '') {
                $reasons[] = trim($r);
            }
        }

        $pairs = [];
        foreach (['alternatives' => ['option', 'why_not'], 'unresolved' => ['item', 'detail'],
                     'next_actions' => ['action', 'owner'], 'approvals_needed' => ['title', 'detail']] as $key => [$a, $b]) {
            if (! is_array($d[$key])) {
                return $this->fail("{$key} must be an array.");
            }
            $pairs[$key] = [];
            foreach ($d[$key] as $n => $row) {
                if (! is_array($row) || trim((string) ($row[$a] ?? '')) === '') {
                    return $this->fail("{$key}[{$n}].{$a} is required.");
                }
                $pairs[$key][] = [$a => trim((string) $row[$a]), $b => trim((string) ($row[$b] ?? ''))];
            }
        }

        if (! is_array($d['issue_updates'])) {
            return $this->fail('issue_updates must be an array.');
        }
        $updates = [];
        foreach ($d['issue_updates'] as $n => $row) {
            $id = $this->intOrNull(is_array($row) ? ($row['issue_id'] ?? null) : null);
            if ($id === null || ! Issue::where('meeting_id', $m->id)->whereKey($id)->exists()) {
                return $this->fail("issue_updates[{$n}].issue_id is not in this meeting. Use issue_id values from ISSUES.");
            }
            $st = (string) ($row['status'] ?? '');
            if (! in_array($st, ['resolved', 'unresolved'], true)) {
                return $this->fail("issue_updates[{$n}].status must be resolved or unresolved.");
            }
            $updates[] = ['issue_id' => $id, 'status' => $st, 'note' => trim((string) ($row['note'] ?? ''))];
        }

        return ['ok' => true, 'data' => [
            'status'           => $status,
            'approach'         => $approach,
            'reasons'          => $reasons,
            'alternatives'     => $pairs['alternatives'],
            'unresolved'       => $pairs['unresolved'],
            'next_actions'     => $pairs['next_actions'],
            'approvals_needed' => $pairs['approvals_needed'],
            'issue_updates'    => $updates,
        ]];
    }

    /** Ang final recommendation bilang nababasang text sa chat. */
    public function renderFinal(array $f): string
    {
        $label = match ($f['status']) {
            'needs_user_input' => 'KAILANGAN NG SAGOT MO',
            'blocked'          => 'BLOCKED',
            default            => 'INIREREKOMENDA',
        };

        $out = "FINAL RECOMMENDATION — {$label}\n\nInirerekomendang approach\n{$f['approach']}\n";

        if ($f['reasons']) {
            $out .= "\nMga dahilan at ebidensya\n";
            foreach ($f['reasons'] as $i => $r) {
                $out .= ($i + 1) . ". {$r}\n";
            }
        }
        $sections = [
            'alternatives'     => ['Mga alternatibong tinimbang', 'option', 'why_not'],
            'unresolved'       => ['Hindi pa nareresolba / mga panganib', 'item', 'detail'],
            'next_actions'     => ['Mga susunod na hakbang', 'action', 'owner'],
            'approvals_needed' => ['Mga desisyong kailangan ng approval mo', 'title', 'detail'],
        ];
        foreach ($sections as $key => [$title, $a, $b]) {
            if (empty($f[$key])) {
                continue;
            }
            $out .= "\n{$title}\n";
            foreach ($f[$key] as $row) {
                $tail = $row[$b] !== '' ? ($key === 'next_actions' ? " (Owner: {$row[$b]})" : " — {$row[$b]}") : '';
                $out .= "- {$row[$a]}{$tail}\n";
            }
        }

        return trim($out);
    }

    private function intOrNull(mixed $v): ?int
    {
        if (is_int($v)) {
            return $v;
        }
        if (is_string($v) && preg_match('/^\d+$/', $v)) {
            return (int) $v;
        }
        if (is_float($v) && floor($v) === $v) {
            return (int) $v;
        }

        return null;
    }

    private function fail(string $error): array
    {
        return ['ok' => false, 'error' => $error];
    }
}
