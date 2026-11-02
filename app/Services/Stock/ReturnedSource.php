<?php

namespace App\Services\Stock;

/**
 * Saan galing ang returned pieces na idinadagdag pabalik sa stock ng /item. Isang source lang para sa buong history
 * (app_settings.stock_returned_source); hindi pinaghahalo.
 */
interface ReturnedSource
{
    /** 'jnt' | 'nexus' */
    public function key(): string;

    /** Returned pieces mula $start (Y-m-d, Manila) hanggang $until (Y-m-d H:i:s, Manila). */
    public function returned(string $start, string $until): ReturnedResult;
}
