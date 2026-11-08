<?php

namespace Tests\Feature\SystemMonitor;

use App\Services\ServerStorage;

/** Fake numbers; binaryLogs() stays real unless $binaryLogs is set, so the refused case runs the real catch. */
class FakeServerStorage extends ServerStorage
{
    public ?array $disk = null;

    public bool $diskThrows = false;

    public ?float $databaseBytes = null;

    public ?array $binaryLogs = null;

    /** Server stats (spec 243 S4); null reads as nothing readable, never the test machine's own numbers. */
    public ?array $server = null;

    public int $diskCalls = 0;

    public int $databaseCalls = 0;

    public int $binaryLogCalls = 0;

    public int $serverCalls = 0;

    public function disk(): ?array
    {
        $this->diskCalls++;
        if ($this->diskThrows) {
            throw new \RuntimeException('disk read failed');
        }

        return $this->disk;
    }

    public function databaseBytes(): ?float
    {
        $this->databaseCalls++;

        return $this->databaseBytes;
    }

    public function binaryLogs(): ?array
    {
        $this->binaryLogCalls++;

        return $this->binaryLogs ?? parent::binaryLogs();
    }

    public function serverStats(): array
    {
        $this->serverCalls++;

        return $this->server ?? self::emptyServerStats();
    }
}
