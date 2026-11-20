<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

/**
 * Likha Chat (spec 308): ginagawa ang VAPID keys ng web push at isinusulat sa .env kapag wala pa. Ang ipinapakita lang ay
 * "created" o "already set", kailanman hindi ang key, para mapatakbo ito ng architect sa server nang walang nakikitang
 * sikreto. Binabasa ang .env file mismo (hindi ang config, na puwedeng naka-cache). Pagkatapos: php artisan config:clear.
 */
class ChatPushKeys extends Command
{
    protected $signature = 'chat:push-keys';

    protected $description = 'Create the web push (VAPID) keys in .env when they are missing. Prints only "created" or "already set".';

    public function handle(): int
    {
        $path = $this->laravel->environmentFilePath();
        if (! is_file($path) || ! is_writable($path)) {
            $this->error('No writable .env file. Nothing was changed.');

            return self::FAILURE;
        }
        $env = (string) file_get_contents($path);
        $public = $this->value($env, 'VAPID_PUBLIC_KEY');
        $private = $this->value($env, 'VAPID_PRIVATE_KEY');

        if ($public !== '' && $private !== '') {
            $this->line('already set');

            return self::SUCCESS;
        }
        if ($public !== '' || $private !== '') {
            // Isa lang ang naroon: hindi pinapalitan (baka may naka-subscribe na sa lumang public key).
            $this->error('Only one of VAPID_PUBLIC_KEY / VAPID_PRIVATE_KEY is set. Fix .env by hand (set both, or remove both). Nothing was changed.');

            return self::FAILURE;
        }

        try {
            $keys = VAPID::createVapidKeys();
        } catch (\Throwable $e) {
            // Hal. Windows PHP na walang openssl.cnf (OPENSSL_CONF). Walang key sa mensahe.
            $this->error('Could not create the keys (openssl cannot make P-256 keys here). Nothing was changed.');

            return self::FAILURE;
        }
        $env = $this->put($env, 'VAPID_PUBLIC_KEY', $keys['publicKey']);
        $env = $this->put($env, 'VAPID_PRIVATE_KEY', $keys['privateKey']);
        if ($this->value($env, 'VAPID_SUBJECT') === '') {
            $env = $this->put($env, 'VAPID_SUBJECT', 'mailto:' . (config('mail.from.address') ?: 'hello@example.com'));
        }
        file_put_contents($path, $env, LOCK_EX);
        $this->line('created');

        return self::SUCCESS;
    }

    private function value(string $env, string $key): string
    {
        return preg_match('/^' . preg_quote($key, '/') . '=(.*)$/m', $env, $m) ? trim(trim($m[1]), "\"'") : '';
    }

    /** Palitan ang linya (kahit blangko) o idagdag sa dulo. */
    private function put(string $env, string $key, string $value): string
    {
        $line = $key . '=' . $value;
        $pattern = '/^' . preg_quote($key, '/') . '=.*$/m';
        if (preg_match($pattern, $env)) {
            return (string) preg_replace_callback($pattern, fn () => $line, $env, 1);
        }

        return ($env === '' || str_ends_with($env, "\n") ? $env : $env . "\n") . $line . "\n";
    }
}
