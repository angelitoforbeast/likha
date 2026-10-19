<?php

namespace App\Console\Commands;

use App\Services\BotcakePageSetup;
use App\Services\ChatblastReady;
use App\Services\ChatblastSetupCheck;
use App\Services\PageSettings;
use Illuminate\Console\Command;

/**
 * Ang setup check ng chatblast (flow, custom field at template ayon sa mga pangalan ng Setup box). Isang page, o lahat
 * (--all, gabi-gabi 21:30 Manila). --dry-run: ipinapakita lang kada page ang kasalukuyang ID, ang nahanap, ang resulta at
 * kung magbabago ang Ready; walang isinusulat at walang token test na itinatala.
 */
class BotcakeSetupCheck extends Command
{
    protected $signature   = 'botcake:setup-check {page? : One page name} {--all : Every page with a Botcake ID and token} {--dry-run : Show what would change; write nothing}';
    protected $description = 'Set the chatblast flow and message field by the Setup box names, and check the template, per page';

    public function handle(PageSettings $settings, ChatblastSetupCheck $check, BotcakePageSetup $setup, ChatblastReady $ready): int
    {
        $page = trim((string) $this->argument('page'));
        if ($page === '' && !$this->option('all')) { $this->error('Name a page, or use --all.'); return self::FAILURE; }
        $pages = $page !== '' ? [$page] : $settings->pagesWith('botcake', ['page_id', 'token']);
        $names = $check->names();
        $this->line('Names: flow "' . $names['flow'] . '", field "' . $names['field'] . '", template "' . $names['template'] . '"' . ($this->option('dry-run') ? ' (dry run: nothing is written)' : ''));

        $rows = [];
        foreach ($pages as $p) {
            if ($this->option('dry-run')) {
                $r = $check->run($p, null, null, true);
                $before = $ready->tested($p);
                $after = $r['status'] !== 'checked' ? $before : ($r['ok'] && ($before || $this->systemWouldSet($settings, $p, $r)));
                $rows[] = [$p, $this->id($r['flow'] ?? []), $this->id($r['field'] ?? []), $this->result($r), ($before ? 'Ready' : 'not Ready') . ($after !== $before ? ' → ' . ($after ? 'Ready' : 'not Ready') : '')];
                continue;
            }
            $res = $setup->check($p);
            $r = $res['check'] ?? ['status' => $res['status'] === 'ok' ? 'error' : $res['status'], 'error' => $res['error'] ?? ('token ' . $res['status'])];
            $rows[] = [$p, $this->id($r['flow'] ?? []), $this->id($r['field'] ?? []), $this->result($r), $ready->tested($p) ? 'Ready' : 'not Ready'];
        }
        $this->table(['Page', 'Flow (current → found)', 'Field (current → found)', 'Result', 'Ready'], $rows);

        return self::SUCCESS;
    }

    /** Ang check ang susulat ng flow at field (system) kapag iba ang nahanap, o kapag system na ang naroon. */
    private function systemWouldSet(PageSettings $settings, string $page, array $r): bool
    {
        foreach (['flow.shipping' => $r['flow'], 'field.message' => $r['field']] as $key => $x) {
            if (($x['current'] ?? '') === ($x['id'] ?? '')) {
                $row = $settings->pageQuery($page)->where('provider', 'botcake')->where('key', $key)->where('active', true)->orderByDesc('id')->first();
                if ($row?->created_by !== null) return false; // ang tao ang naglagay: kailangan pa rin ng test
            }
        }

        return true;
    }

    private function id(array $x): string
    {
        if (!$x) return '';
        $cur = ($x['current'] ?? '') !== '' ? $x['current'] : '—';

        return $cur . ' → ' . ($x['id'] ?? '—');
    }

    private function result(array $r): string
    {
        return match ($r['status'] ?? '') {
            'checked' => $r['ok'] ? '✔ check' : 'X ' . implode('; ', $r['x']),
            'skipped' => 'skipped: no Botcake ID or token',
            default => 'Check failed: ' . ($r['error'] ?? 'unknown'),
        };
    }
}
