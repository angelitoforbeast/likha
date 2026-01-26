<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\Jnt\JntClient;

class JntSandboxTest extends Command
{
    protected $signature = 'jnt:test
        {--billcode= : Billcode to track}
        {--lang=en : Language}
        {--dump : Dump lastRequest/lastResponse}';

    protected $description = 'Quick J&T sandbox connectivity + signing test';

    public function handle()
    {
        $client = JntClient::fromConfig();

        $billcode = $this->option('billcode') ?: 'TEST_BILLCODE_123';
        $lang = $this->option('lang') ?: 'en';

        $this->info("Tracking billcode: {$billcode} ({$lang})");
        $res = $client->trackForJson($billcode, $lang);

        $this->line(json_encode($res, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));

        if ($this->option('dump')) {
            $this->warn("---- lastRequest ----");
            $this->line(json_encode($client->lastRequest, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
            $this->warn("---- lastResponse ----");
            $this->line(json_encode($client->lastResponse, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
        }

        return self::SUCCESS;
    }
}
