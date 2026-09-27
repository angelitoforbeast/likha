<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class BoardroomKey extends Command
{
    protected $signature = 'boardroom:key';

    protected $description = 'Gumawa ng hiwalay na encryption key para sa AI Boardroom credentials (ilagay sa .env bilang BOARDROOM_ENCRYPTION_KEY)';

    public function handle(): int
    {
        $this->line('BOARDROOM_ENCRYPTION_KEY=base64:' . base64_encode(random_bytes(32)));
        $this->newLine();
        $this->warn('Ilagay ito sa .env BAGO mag-save ng kahit anong API key sa /boardroom/agents.');
        $this->warn('Kapag pinalitan ito pagkatapos, hindi na mababasa ang mga naka-save na key — kailangang i-enter ulit.');

        return self::SUCCESS;
    }
}
