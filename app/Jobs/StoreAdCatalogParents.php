<?php

namespace App\Jobs;

use App\Services\Chatbot\AdGroups;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Ang ad set at campaign ng ad na nakuha sa Meta (AdGroups lookup), isinusulat sa row ng ad sa ad_catalog. Nasa default
 * queue, hindi sa daan ng sagot: ang import ng Ads sync ay may hawak na lock sa ad_catalog habang buong chunk, kaya ang
 * paghihintay ay dito, hindi sa customer. Naka-cache na ang mga ID bago ito, kaya walang pangalawang tawag sa Meta habang
 * naghihintay. ID lang ang laman (walang token, walang customer).
 */
class StoreAdCatalogParents implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;
    public array $backoff = [30, 120];

    public function __construct(public string $adId, public ?string $adSetId, public ?string $campaignId)
    {
    }

    public function handle(): void
    {
        AdGroups::storeParents($this->adId, $this->adSetId, $this->campaignId);
    }
}
