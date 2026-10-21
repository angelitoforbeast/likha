<?php

namespace App\Console\Commands;

use App\Services\BotcakeChatGuard;
use Illuminate\Console\Command;

/**
 * Pansamantalang test IP para sa /api/botcake/chat (walang rate limit, gaya ng IP ng Botcake), nang hindi binabago ang
 * .env: nasa cache na may expiry (hanggang 12 oras), kaya ang nakalimutang IP ay kusang nawawala.
 */
class BotcakeChatTestIp extends Command
{
    protected $signature   = 'botcake:chat-test-ip {ip : The tester\'s IP (any value with --list)} {--hours=2 : How long, 1 to 12} {--remove : Take the IP off the list} {--list : Show the test IPs and their expiry}';
    protected $description = 'Let a tester\'s IP send to /api/botcake/chat without the rate limit, for a few hours';

    public function handle(): int
    {
        if ($this->option('list')) {
            $ips = BotcakeChatGuard::testIps();
            if (!$ips) { $this->info('No test IPs.'); return self::SUCCESS; }
            $this->table(['IP', 'Expires (Manila)'], array_map(fn ($ip, $until) => [$ip, now()->setTimestamp($until)->timezone('Asia/Manila')->format('Y-m-d H:i')], array_keys($ips), $ips));
            return self::SUCCESS;
        }

        $ip = trim((string) $this->argument('ip'));
        if (!filter_var($ip, FILTER_VALIDATE_IP)) { $this->error('Not an IP address.'); return self::FAILURE; }
        if ($this->option('remove')) {
            BotcakeChatGuard::setTestIp($ip, null);
            $this->info("{$ip} removed.");
            return self::SUCCESS;
        }
        $hours = filter_var($this->option('hours'), FILTER_VALIDATE_INT);
        if ($hours === false || $hours < 1 || $hours > BotcakeChatGuard::TEST_IP_MAX_HOURS) {
            $this->error('Use --hours from 1 to ' . BotcakeChatGuard::TEST_IP_MAX_HOURS . '.');
            return self::FAILURE;
        }
        BotcakeChatGuard::setTestIp($ip, $hours);
        $this->info("{$ip} has no chat rate limit until " . now()->addHours($hours)->timezone('Asia/Manila')->format('Y-m-d H:i') . ' (Manila).');

        return self::SUCCESS;
    }
}
