<?php

namespace Tests\Feature\OrderEncode;

use App\Models\AppSetting;
use App\Services\OrderEncode\Settings;
use App\Services\PageSettings;
use PHPUnit\Framework\Attributes\DataProvider;

/** Ang config ng order encode: page_settings (provider order_encode) at app_settings; walang bagong table para sa settings. */
class ConfigTest extends OrderEncodeTestCase
{
    public static function bad(): array
    {
        return [
            'unknown key' => ['model', 'gpt-5'],
            'mode with a typo' => ['mode', 'shadw'],
            'compare sheet that is not a sheet' => ['compare_sheet', 'not a sheet'],
            'macro sheet that is not a sheet' => ['macro_sheet', 'https://example.com/x'],
        ];
    }

    #[DataProvider('bad')]
    public function test_S_130_7_only_the_three_keys_with_valid_values_are_accepted(string $key, string $value): void
    {
        $this->assertNotNull(app(PageSettings::class)->validate(self::PAGE, 'order_encode', $key, $value));
        $this->expectException(\InvalidArgumentException::class);
        app(PageSettings::class)->set(self::PAGE, 'order_encode', $key, $value);
    }

    public function test_S_130_7_good_values_are_stored_and_read_back(): void
    {
        $this->configurePage('Shadow', lists: false);
        $s = app(Settings::class);

        $this->assertSame('shadow', $s->mode(self::PAGE));
        $this->assertSame(self::AFTER, $s->sheetId(self::PAGE, 'compare_sheet'));
        $this->assertSame(self::MACRO, $s->sheetId(self::PAGE, 'macro_sheet'));
        $this->assertSame('off', $s->mode('Page Without Settings'));
    }

    public function test_S_130_7_the_other_providers_keep_their_free_keys(): void
    {
        $this->assertNull(app(PageSettings::class)->validate(self::PAGE, 'other', 'model', 'whatever'));
    }

    public function test_S_130_8_models_default_and_can_be_changed_in_app_settings(): void
    {
        $s = app(Settings::class);
        $this->assertSame(['gpt-4o-mini', 'gpt-5.2'], [$s->modelExtract(), $s->modelChecker()]);

        AppSetting::set('order_encode_model_extract', 'gpt-4.1-mini');
        AppSetting::set('order_encode_model_checker', ' gpt-5.5 ');

        $this->assertSame(['gpt-4.1-mini', 'gpt-5.5'], [$s->modelExtract(), $s->modelChecker()]);
    }

    public function test_S_130_8_the_key_comes_from_the_order_encode_config(): void
    {
        config(['services.openai.order_encode_key' => ' sk-from-config ']);

        $this->assertSame('sk-from-config', app(Settings::class)->key());
    }
}
