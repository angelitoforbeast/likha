<?php

namespace App\Boardroom\Orchestrator;

use App\Boardroom\Capabilities\CapabilityRegistry;
use App\Models\Boardroom\Meeting;
use App\Models\Boardroom\Turn;

/**
 * Mga limit ng isang meeting: bilang ng model call, kabuuang output tokens, at gastos.
 * Ang budget ay nirereserba BAGO mag-schedule (kasama ang parallel na proposals), at laging may
 * nakatabing kapasidad para sa final summary.
 */
class Budget
{
    // Tantiyang input tokens kada call para sa worst-case na gastos (para lang sa spending limit).
    public const INPUT_ALLOWANCE = 20000;

    public function __construct(private CapabilityRegistry $registry)
    {
    }

    public function maxOut(array $member): int
    {
        $resolved = $this->registry->resolve((string) $member['provider'], (string) $member['model'], (array) ($member['settings'] ?? []));

        return (int) $resolved['applied']['max_output_tokens'];
    }

    /** Worst-case na gastos ng isang call; null kapag hindi alam ang presyo. */
    public function worstCost(array $member): ?float
    {
        return $this->registry->estimateCost(
            (string) $member['provider'],
            (string) $member['model'],
            self::INPUT_ALLOWANCE,
            $this->maxOut($member)
        );
    }

    /** Dapat bang magtabi pa ng kapasidad para sa final summary? */
    public function reservesFinal(Meeting $m): bool
    {
        return ! in_array($m->phase, ['final', 'done'], true);
    }

    /**
     * Pwede bang mag-schedule ng mga bagong call para sa $members?
     *
     * @param  array<int, array>  $members  snapshot ng mga role na tatawagin
     * @return string|null  null = pwede; kung hindi, ang dahilan (call_limit | token_limit | spend_limit | price_unknown)
     */
    public function deny(Meeting $m, array $members, bool $reserveFinal): ?string
    {
        $n       = count($members);
        $reserve = $reserveFinal ? 1 : 0;

        if ((int) $m->calls_used + (int) $m->reserved_calls + $n + $reserve > (int) $m->max_calls) {
            return 'call_limit';
        }

        $moderator = $m->moderator();
        $planned   = $members;
        if ($reserveFinal && $moderator) {
            $planned[] = $moderator;
        }
        $pending = $this->pendingMembers($m);

        if ($m->max_total_output_tokens) {
            $need = (int) $m->tokens_out;
            foreach (array_merge($pending, $planned) as $member) {
                $need += $this->maxOut($member);
            }
            if ($need > (int) $m->max_total_output_tokens) {
                return 'token_limit';
            }
        }

        if ($m->spend_limit_usd !== null) {
            if ((int) $m->unpriced_calls > 0) {
                return 'price_unknown';
            }
            $need = (float) $m->est_cost_usd;
            foreach (array_merge($pending, $planned) as $member) {
                $cost = $this->worstCost($member);
                if ($cost === null) {
                    return 'price_unknown';
                }
                $need += $cost;
            }
            if ($need > (float) $m->spend_limit_usd) {
                return 'spend_limit';
            }
        }

        return null;
    }

    /** Mga role na may turn pang hindi tapos (naka-reserba na ang budget nila). */
    private function pendingMembers(Meeting $m): array
    {
        $out = [];
        // Hindi kasama ang mga turn ng tanong ng user: hiwalay ang limit ng mga iyon.
        $ids = Turn::where('meeting_id', $m->id)->whereNull('round_id')->where('purpose', '!=', 'direct')
            ->whereIn('status', Turn::PENDING)->pluck('agent_id');
        foreach ($ids as $agentId) {
            $member = $m->member((int) $agentId);
            if ($member) {
                $out[] = $member;
            }
        }

        return $out;
    }

    public static function reasonText(string $reason): string
    {
        return match ($reason) {
            'call_limit'    => 'naabot na ang limit sa bilang ng model call',
            'token_limit'   => 'naabot na ang limit sa kabuuang output tokens',
            'spend_limit'   => 'naabot na ang spending limit',
            'price_unknown' => 'hindi maipatupad ang spending limit dahil may model na walang alam na presyo',
            'cycle_limit'   => 'naabot na ang pinakamaraming review/revision cycle',
            default         => $reason,
        };
    }
}
