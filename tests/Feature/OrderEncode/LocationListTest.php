<?php

namespace Tests\Feature\OrderEncode;

use App\Services\OrderEncode\LocationList;
use Tests\TestCase;

/** Ang address file ng order encode: ang J&T file ng Likha, binabasa lang, ayon sa ayos ng file, naka-cache ayon sa mtime. */
class LocationListTest extends TestCase
{
    public function test_S_130_63_the_real_file_is_read_in_file_order_without_being_changed(): void
    {
        $list = new LocationList();
        $before = md5_file($list->path());

        $rows = $list->rows();

        $this->assertSame(['ABRA', 'ABRA-DOLORES', 'BAYAAN'], $rows[0]);
        $this->assertSame(['ABRA', 'ABRA-DOLORES', 'CABAROAN'], $rows[1]);
        $this->assertGreaterThan(40000, count($rows));
        $this->assertNotNull($list->macro());
        $this->assertNotNull($list->locations());
        $this->assertSame($before, md5_file($list->path()));
    }

    public function test_S_130_64_the_parsed_list_is_cached_until_the_file_changes_and_a_missing_file_is_empty(): void
    {
        $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'order-encode-list-' . bin2hex(random_bytes(4)) . '.txt';
        file_put_contents($file, "A|B|C\n\n bad line\nD|E|F\r\n");
        $list = new LocationList($file);

        $this->assertSame([['A', 'B', 'C'], ['D', 'E', 'F']], $list->rows());
        $this->assertSame($list->macro(), $list->macro());

        file_put_contents($file, "X|Y|Z\n");
        touch($file, time() + 10); // ibang mtime
        clearstatcache();
        $this->assertSame([['X', 'Y', 'Z']], $list->rows());

        unlink($file);
        $this->assertFalse($list->has());
        $this->assertSame([], $list->rows());
        $this->assertNull($list->macro());
    }
}
