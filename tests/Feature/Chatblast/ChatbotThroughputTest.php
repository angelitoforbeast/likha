<?php

namespace Tests\Feature\Chatblast;

use App\Services\Chatbot\ChatbotPage;
use App\Services\ChatbotFields;
use App\Services\PageSettings;
use Illuminate\Support\Facades\DB;

/**
 * Ang chatbot sa 50–100 chat bawat segundo: ang Horizon supervisor na chatbot (min, max, bilis ng paglaki,
 * memory), ang default na sabay na AI request, at ang label ng mga botfield na hindi na binabasa sa lahat ng page sa
 * bawat takbo.
 */
class ChatbotThroughputTest extends ChatblastTestCase
{
    /** @var array<string, string|false> */
    private array $saved = [];

    protected function tearDown(): void
    {
        foreach ($this->saved as $key => $value) {
            if ($value === false) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                putenv("$key=$value");
                $_ENV[$key] = $_SERVER[$key] = $value;
            }
        }
        parent::tearDown();
    }

    private function env(array $vars): void
    {
        foreach ($vars as $key => $value) {
            if (!array_key_exists($key, $this->saved)) $this->saved[$key] = getenv($key);
            if ($value === null) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
                continue;
            }
            putenv("$key=$value");
            $_ENV[$key] = $_SERVER[$key] = $value;
        }
    }

    public function test_S_161_1_the_chatbot_supervisor_starts_at_4_per_queue_and_grows_fast_to_40(): void
    {
        $this->env(['HORIZON_CHATBOT_MAX' => null, 'HORIZON_CHATBLAST_MAX' => null]);
        $horizon = (require config_path('horizon.php'))['defaults'];
        $chatbot = $horizon['chatbot'];

        $this->assertSame([4, 40, 5, 1, 256], [$chatbot['minProcesses'], $chatbot['maxProcesses'], $chatbot['balanceMaxShift'], $chatbot['balanceCooldown'], $chatbot['memory']]);
        // Ang chatblast ay gaya ng dati.
        $this->assertSame([2, 8, 1, 3, 256], [$horizon['chatblast']['minProcesses'], $horizon['chatblast']['maxProcesses'], $horizon['chatblast']['balanceMaxShift'], $horizon['chatblast']['balanceCooldown'], $horizon['chatblast']['memory']]);

        $this->env(['HORIZON_CHATBOT_MAX' => '24']);
        $this->assertSame(24, (require config_path('horizon.php'))['defaults']['chatbot']['maxProcesses']);
    }

    public function test_S_161_2_the_ai_pool_sends_up_to_100_at_a_time_unless_env_says_otherwise(): void
    {
        $this->env(['CHATBOT_AI_CONCURRENCY' => null]);
        $this->assertSame(100, (require config_path('services.php'))['chatbot']['ai_concurrency']);

        $this->env(['CHATBOT_AI_CONCURRENCY' => '30']);
        $this->assertSame(30, (require config_path('services.php'))['chatbot']['ai_concurrency']);
    }

    public function test_S_161_3_a_run_reads_the_botfield_labels_without_scanning_every_pages_settings(): void
    {
        $this->page('Page Uno', '1001');
        $settings = app(PageSettings::class);
        $settings->set('Page Uno', ChatbotFields::PROVIDER, 'prompt_1', 'Be kind');
        $page = app(ChatbotPage::class);
        $this->assertSame('Be kind', $page->botfields('Page Uno')['Prompt 1']);

        $scans = 0;
        DB::listen(function ($q) use (&$scans) {
            if (str_contains($q->sql, 'distinct') && str_contains($q->sql, 'page_settings')) $scans++;
        });
        $page->botfields('Page Uno');
        app(ChatbotPage::class)->botfields('Page Uno');
        $this->assertSame(0, $scans, 'the next runs reuse the labels');

        // Ang bagong column sa "+ Column" ay agad na may label sa takbo.
        app(ChatbotFields::class)->add('Free Gift');
        $settings->set('Page Uno', ChatbotFields::PROVIDER, 'free_gift', 'Keychain');
        $this->assertSame('Keychain', app(ChatbotPage::class)->botfields('Page Uno')['Free Gift']);
    }
}
