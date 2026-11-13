<?php

namespace Tests\Feature\OwnerPrivate;

use PHPUnit\Framework\TestCase;

/**
 * Spec 261: ang /owner/private ay isang beses lang humihingi ng item-summary sa bawat bukas.
 * Alpine 3 ay kusang tinatawag ang init() ng x-data object; ang x-init="init()" sa parehong element
 * ay pangalawang tawag (dalawang magkaparehong item-summary request). Source-level, walang JS harness.
 */
class ItemSummaryOnceTest extends TestCase
{
    public function test_init_runs_once_so_item_summary_is_requested_once(): void
    {
        $src = file_get_contents(__DIR__ . '/../../../resources/views/owner/private.blade.php');

        $this->assertMatchesRegularExpression('/<html[^>]*x-data="privateUI\(\)"/', $src);
        $this->assertMatchesRegularExpression('/\n      async init\(\)\{/', $src, 'privateUI() has its own init(), which Alpine calls by itself');
        preg_match('/<html[^>]*>/', $src, $m);
        $this->assertStringNotContainsString('x-init', $m[0], 'x-init="init()" would call init() a second time');
        $this->assertSame(1, substr_count($src, "await this.load();\n        // Apply ?item= prefilter"), 'init() loads once');
    }
}
