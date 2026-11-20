<?php

namespace Tests\Feature\Chat;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/** chat:push-keys writes the VAPID keys into .env only when they are missing, and never prints a key. */
class PushKeysCommandTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        PushCryptoTest::needsEcKeys($this);
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'likha-push-keys-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        $this->app->useEnvironmentPath($this->dir);
        config(['mail.from.address' => 'likha@example.test']);
    }

    protected function tearDown(): void
    {
        if (isset($this->dir)) {
            @unlink($this->dir . '/.env');
            @rmdir($this->dir);
        }
        parent::tearDown();
    }

    private function env(): string
    {
        return (string) file_get_contents($this->dir . '/.env');
    }

    private function value(string $key): ?string
    {
        return preg_match('/^' . $key . '=(.*)$/m', $this->env(), $m) ? trim($m[1], "\"' ") : null;
    }

    public function test_it_creates_both_keys_when_missing_and_prints_only_created(): void
    {
        file_put_contents($this->dir . '/.env', "APP_NAME=Likha\nMAIL_FROM_ADDRESS=likha@example.test\n");

        $code = Artisan::call('chat:push-keys');
        $out = Artisan::output();

        $this->assertSame(0, $code);
        $this->assertSame('created', trim($out));
        $public = $this->value('VAPID_PUBLIC_KEY');
        $private = $this->value('VAPID_PRIVATE_KEY');
        // P-256: a 65-byte public point and a 32-byte private key, base64url.
        $this->assertSame(87, strlen($public));
        $this->assertSame(43, strlen($private));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $public . $private);
        $this->assertSame('mailto:likha@example.test', $this->value('VAPID_SUBJECT'));
        $this->assertStringNotContainsString($private, $out);
        $this->assertStringNotContainsString($public, $out);
        // The rest of the file is kept.
        $this->assertStringStartsWith("APP_NAME=Likha\nMAIL_FROM_ADDRESS=likha@example.test\n", $this->env());
    }

    public function test_it_changes_nothing_when_the_keys_are_set(): void
    {
        file_put_contents($this->dir . '/.env', "APP_NAME=Likha\n");
        Artisan::call('chat:push-keys');
        $before = $this->env();

        $code = Artisan::call('chat:push-keys');
        $out = Artisan::output();

        $this->assertSame(0, $code);
        $this->assertSame('already set', trim($out));
        $this->assertSame($before, $this->env());
        $this->assertStringNotContainsString((string) $this->value('VAPID_PRIVATE_KEY'), $out);
    }

    public function test_an_empty_key_counts_as_missing(): void
    {
        file_put_contents($this->dir . '/.env', "VAPID_PUBLIC_KEY=\nVAPID_PRIVATE_KEY=\"\"\n");
        Artisan::call('chat:push-keys');
        $this->assertSame('created', trim(Artisan::output()));
        $this->assertSame(87, strlen((string) $this->value('VAPID_PUBLIC_KEY')));
        $this->assertSame(1, substr_count($this->env(), 'VAPID_PUBLIC_KEY='));
        $this->assertSame(1, substr_count($this->env(), 'VAPID_PRIVATE_KEY='));
    }

    public function test_one_key_without_the_other_is_refused_and_nothing_changes(): void
    {
        file_put_contents($this->dir . '/.env', "VAPID_PRIVATE_KEY=keep-me-secret\n");
        $code = Artisan::call('chat:push-keys');
        $out = Artisan::output();

        $this->assertSame(1, $code);
        $this->assertStringNotContainsString('keep-me-secret', $out);
        $this->assertSame("VAPID_PRIVATE_KEY=keep-me-secret\n", $this->env());
    }

    public function test_an_existing_subject_is_kept(): void
    {
        file_put_contents($this->dir . '/.env', "VAPID_SUBJECT=mailto:owner@example.test\n");
        Artisan::call('chat:push-keys');
        $this->assertSame('mailto:owner@example.test', $this->value('VAPID_SUBJECT'));
    }

    public function test_without_an_env_file_it_fails_and_writes_nothing(): void
    {
        $this->assertSame(1, Artisan::call('chat:push-keys'));
        $this->assertFileDoesNotExist($this->dir . '/.env');
    }
}
