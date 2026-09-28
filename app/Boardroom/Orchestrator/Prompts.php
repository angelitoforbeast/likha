<?php

namespace App\Boardroom\Orchestrator;

/**
 * Mga task instruction at JSON schema ng bawat uri ng turn.
 * Ang role instructions (galing sa settings) ay hiwalay at nauuna sa system prompt.
 */
class Prompts
{
    public const ROUTE_ACTIONS   = ['ask_agent', 'request_revision', 'finalize', 'needs_user_input', 'blocked'];
    public const ROUTE_STATUSES  = ['continuing', 'ready_for_revision', 'ready_for_final', 'needs_user_input', 'blocked'];
    public const REVIEW_VERDICTS = ['approve', 'revise', 'needs_user_input', 'blocked'];
    public const SEVERITIES      = ['low', 'medium', 'high'];
    public const FINAL_STATUSES  = ['recommended', 'needs_user_input', 'blocked'];

    public const QREVIEW_VERDICTS = ['ok', 'revise'];

    /** Mga turn na JSON ang kailangang sagot. */
    public const STRUCTURED = ['review', 'route', 'final', 'qreview'];

    public static function system(array $member, array $members): string
    {
        $roster = [];
        foreach ($members as $m) {
            $roster[] = "- {$m['handle']} — {$m['display_name']} ({$m['role_type']})";
        }

        return trim((string) $member['instructions']) . "\n\n"
            . "=== BOARDROOM RULES ===\n"
            . "You are {$member['display_name']} (handle: {$member['handle']}) in a boardroom meeting. Participants:\n"
            . implode("\n", $roster) . "\n\n"
            . "1. Write only as your own role. Never write lines for another participant and never invent a dialogue.\n"
            . "2. You have NO tools. You cannot browse, run commands, deploy anything, or message customers. Never claim that you did.\n"
            . "3. Label important statements: [VERIFIED FACT] only for information given in this context, [ASSUMPTION] for guesses, "
            . "[PROPOSAL] for suggestions, [APPROVED DECISION] only for decisions listed as approved by the user.\n"
            . "4. Do not show step-by-step private reasoning. Give conclusions with short, checkable reasons.\n"
            . "5. If information is missing, say exactly what is needed from the user instead of inventing data, prices, or results.\n"
            . "6. Disagreement is allowed. Do not pretend to agree just to reach consensus.\n"
            . "7. Reply in the language used in the meeting objective (Taglish is fine).";
    }

    public static function task(string $purpose, array $ctx = []): string
    {
        return match ($purpose) {
            'brief' => "YOUR TASK — write the meeting BRIEF that the contributors will work from.\n"
                . "Include: (1) the objective restated clearly, (2) scope and what is out of scope, (3) constraints, "
                . "(4) what each contributor should cover, (5) how proposals will be judged, (6) open questions and assumptions.\n"
                . "Plain text only. Keep it focused; the contributors will see ONLY this brief.",

            'proposal' => "YOUR TASK — write your INDEPENDENT PROPOSAL based only on the brief above.\n"
                . "You cannot see any other proposal, and nobody can see yours until all proposals are in.\n"
                . "Structure: Summary; Proposed approach; Key steps; Risks; Assumptions; What needs user input.\nPlain text only.",

            'review' => "YOUR TASK — REVIEW the proposals" . (($ctx['cycle'] ?? 1) > 1 ? ' and the revisions made since the last review' : '') . ".\n"
                . "Find gaps, contradictions, unproven assumptions, and risks. Each issue must be specific and answerable.\n"
                . "If earlier issues are now resolved, list their ids in resolved_issue_ids. Use only ids shown in ISSUES.\n"
                . "Return ONLY a JSON object with exactly these keys:\n"
                . "{\"public_message\": string (your review as it will appear in the chat),\n"
                . " \"verdict\": one of " . json_encode(self::REVIEW_VERDICTS) . ",\n"
                . " \"issues\": [{\"title\": string, \"detail\": string, \"severity\": one of " . json_encode(self::SEVERITIES)
                . ", \"target_agent_id\": integer agent_id from PARTICIPANTS or null}],\n"
                . " \"resolved_issue_ids\": [integer]}\n"
                . "Use verdict \"approve\" only when no significant issue remains open.",

            'route' => "YOUR TASK — decide the NEXT STEP of the discussion. You are proposing; the system validates it.\n"
                . "Allowed actions right now: " . json_encode($ctx['allowed'] ?? self::ROUTE_ACTIONS) . "\n"
                . "- ask_agent: ask ONE participant a targeted question about ONE issue (set recipient_agent_id and issue_id).\n"
                . "- request_revision: contributors revise their proposals (recipient_agent_id = one contributor, or null for all).\n"
                . "- finalize: enough is known; go to the final recommendation.\n"
                . "- needs_user_input: the meeting cannot continue without an answer from the user; ask it in public_message.\n"
                . "- blocked: the objective cannot be achieved as stated; explain why in public_message.\n"
                . "Use only ids shown in PARTICIPANTS, ISSUES, and the [#id] message numbers. Never use your own agent_id as recipient.\n"
                . "Return ONLY a JSON object with exactly these keys:\n"
                . "{\"action\": one of " . json_encode(self::ROUTE_ACTIONS) . ",\n"
                . " \"recipient_agent_id\": integer or null,\n"
                . " \"reply_to_message_id\": integer or null,\n"
                . " \"issue_id\": integer or null,\n"
                . " \"public_message\": string (what you say in the chat),\n"
                . " \"discussion_status\": one of " . json_encode(self::ROUTE_STATUSES) . "}",

            'answer' => "YOUR TASK — answer the moderator's question addressed to you (the last question in the transcript).\n"
                . "Be direct. State what you are sure of, what is an assumption, and whether your position changed.\nPlain text only.",

            'revision' => "YOUR TASK — write your REVISED PROPOSAL.\n"
                . "Address the open issues and the discussion. For every issue say: addressed (how), or not accepted (why).\n"
                . "You may keep your position if you disagree. Plain text only.",

            'final' => "YOUR TASK — write the FINAL RECOMMENDATION for the user.\n"
                . (! empty($ctx['early']) ? "The discussion ended early ({$ctx['early']}). Be explicit about everything that remains unresolved.\n" : '')
                . "Do not force consensus: list objections that were not resolved.\n"
                . "Return ONLY a JSON object with exactly these keys:\n"
                . "{\"status\": one of " . json_encode(self::FINAL_STATUSES) . ",\n"
                . " \"approach\": string (the recommended approach),\n"
                . " \"reasons\": [string] (reasons and evidence, each labelled as fact or assumption),\n"
                . " \"alternatives\": [{\"option\": string, \"why_not\": string}],\n"
                . " \"unresolved\": [{\"item\": string, \"detail\": string}] (unresolved objections and risks),\n"
                . " \"next_actions\": [{\"action\": string, \"owner\": string}],\n"
                . " \"approvals_needed\": [{\"title\": string, \"detail\": string}] (decisions that need the user's approval),\n"
                . " \"issue_updates\": [{\"issue_id\": integer, \"status\": \"resolved\" or \"unresolved\", \"note\": string}]}",

            'direct' => ($ctx['cycle'] ?? 1) > 1
                ? "YOUR TASK — REVISE your answer to the USER QUESTION above, using the reviewer's latest feedback in the transcript.\n"
                    . "Say what you changed. You may keep your position if you disagree, but say why. Plain text only."
                : "YOUR TASK — the user addressed you directly. Answer the USER QUESTION above.\n"
                    . "Be direct. If the question is about something you were not told, say what you need to know. Plain text only.",

            'qreview' => "YOUR TASK — REVIEW the answers given to the USER QUESTION above (cycle " . ($ctx['cycle'] ?? 1) . " of " . ($ctx['max_cycles'] ?? 1) . ").\n"
                . "Check: do they actually answer the question, do they contradict each other, what is unproven.\n"
                . "Use verdict \"revise\" only if another round of answers would materially improve the result.\n"
                . "Return ONLY a JSON object with exactly these keys:\n"
                . "{\"public_message\": string (your review as it will appear in the chat),\n"
                . " \"verdict\": one of " . json_encode(self::QREVIEW_VERDICTS) . "}",

            'qsummary' => "YOUR TASK — write a SHORT SUMMARY for the user of the answers to the USER QUESTION above.\n"
                . "Cover: the answer in one or two sentences, where the roles agree, where they do not, and what the user should do next.\n"
                . "Do not force consensus. Plain text only. Keep it brief.",

            default => 'Answer in plain text.',
        };
    }

    public static function repair(string $purpose, string $error, string $badOutput): string
    {
        return "YOUR PREVIOUS OUTPUT WAS REJECTED.\nReason: {$error}\n\n"
            . "--- previous output (for reference) ---\n{$badOutput}\n--- end ---\n\n"
            . "Return ONLY the corrected JSON object. No code fences, no commentary. Keep it concise.";
    }

    /** JSON schema (strict-compatible: lahat required, additionalProperties=false). */
    public static function schema(string $purpose): ?array
    {
        $nullableInt = ['anyOf' => [['type' => 'integer'], ['type' => 'null']]];
        $str         = ['type' => 'string'];
        $obj = fn (array $props) => [
            'type'                 => 'object',
            'properties'           => $props,
            'required'             => array_keys($props),
            'additionalProperties' => false,
        ];
        $list = fn (array $items) => ['type' => 'array', 'items' => $items];

        return match ($purpose) {
            'route' => ['name' => 'boardroom_routing', 'schema' => $obj([
                'action'              => ['type' => 'string', 'enum' => self::ROUTE_ACTIONS],
                'recipient_agent_id'  => $nullableInt,
                'reply_to_message_id' => $nullableInt,
                'issue_id'            => $nullableInt,
                'public_message'      => $str,
                'discussion_status'   => ['type' => 'string', 'enum' => self::ROUTE_STATUSES],
            ])],

            'qreview' => ['name' => 'boardroom_question_review', 'schema' => $obj([
                'public_message' => $str,
                'verdict'        => ['type' => 'string', 'enum' => self::QREVIEW_VERDICTS],
            ])],

            'review' => ['name' => 'boardroom_review', 'schema' => $obj([
                'public_message' => $str,
                'verdict'        => ['type' => 'string', 'enum' => self::REVIEW_VERDICTS],
                'issues'         => $list($obj([
                    'title'           => $str,
                    'detail'          => $str,
                    'severity'        => ['type' => 'string', 'enum' => self::SEVERITIES],
                    'target_agent_id' => $nullableInt,
                ])),
                'resolved_issue_ids' => $list(['type' => 'integer']),
            ])],

            'final' => ['name' => 'boardroom_final', 'schema' => $obj([
                'status'           => ['type' => 'string', 'enum' => self::FINAL_STATUSES],
                'approach'         => $str,
                'reasons'          => $list($str),
                'alternatives'     => $list($obj(['option' => $str, 'why_not' => $str])),
                'unresolved'       => $list($obj(['item' => $str, 'detail' => $str])),
                'next_actions'     => $list($obj(['action' => $str, 'owner' => $str])),
                'approvals_needed' => $list($obj(['title' => $str, 'detail' => $str])),
                'issue_updates'    => $list($obj([
                    'issue_id' => ['type' => 'integer'],
                    'status'   => ['type' => 'string', 'enum' => ['resolved', 'unresolved']],
                    'note'     => $str,
                ])),
            ])],

            default => null,
        };
    }
}
