{{--
  Inline campaigns/adsets/ads drilldown for /owner/private rows.
  Reuses /ads_manager/campaigns/data JSON endpoint with page_name +
  optional campaign_id / ad_set_id filters. Tree-style nested expand.
  Date range = this calendar month (PH) — NOT the /owner/private filter.

  Expected scope when included:
    row              — current page row from sortedRows()
    expandedPages    — Alpine state, keyed by page_name
    expandedCampaigns— Alpine state, keyed by campaign_id
    expandedAdSets   — Alpine state, keyed by ad_set_id
    helpers: money, num, fmtDate, daysSince
--}}
<div class="px-4 py-3" style="background:#f8fafc;border-top:1px solid #e2e8f0;border-bottom:1px solid #e2e8f0;">

  {{-- Header strip with date-range note + close hint --}}
  <div class="flex items-center justify-between mb-2">
    <div class="text-xs text-slate-600">
      <span class="font-semibold" x-text="row.page_name"></span>
      <span class="text-slate-400 mx-1">·</span>
      <span>Campaigns (this month, default — independent ng filter sa taas)</span>
    </div>
    <button class="text-xs text-slate-500 hover:text-slate-700"
            @click="togglePageExpand(row.page_name)">✕ Close</button>
  </div>

  {{-- Loading / error / empty --}}
  <template x-if="(expandedPages[row.page_name] || {}).loading">
    <div class="text-xs text-slate-500 italic py-2">Loading campaigns…</div>
  </template>
  <template x-if="(expandedPages[row.page_name] || {}).error">
    <div class="text-xs text-red-600 py-2"
         x-text="'⚠ ' + (expandedPages[row.page_name] || {}).error"></div>
  </template>
  <template x-if="!(expandedPages[row.page_name] || {}).loading
                 && !(expandedPages[row.page_name] || {}).error
                 && Array.isArray((expandedPages[row.page_name] || {}).campaigns)
                 && (expandedPages[row.page_name] || {}).campaigns.length === 0">
    <div class="text-xs text-slate-500 italic py-2">Walang campaigns para sa page na ito sa this-month range.</div>
  </template>

  {{-- Campaigns table --}}
  <template x-if="!(expandedPages[row.page_name] || {}).loading
                 && !(expandedPages[row.page_name] || {}).error
                 && Array.isArray((expandedPages[row.page_name] || {}).campaigns)
                 && (expandedPages[row.page_name] || {}).campaigns.length > 0">
    <table class="w-full text-xs border-collapse" style="background:white;border:1px solid #e2e8f0;">
      <thead style="background:#f1f5f9;">
        <tr class="text-left text-slate-600">
          <th class="w-6 px-2 py-1.5"></th>{{-- chevron --}}
          <th class="w-20 px-2 py-1.5">Off / On</th>
          <th class="px-2 py-1.5">Campaign</th>
          <th class="px-2 py-1.5 whitespace-nowrap">First launched</th>
          <th class="px-2 py-1.5 whitespace-nowrap">Days running</th>
          <th class="px-2 py-1.5 whitespace-nowrap">Latest start</th>
          <th class="px-2 py-1.5 text-right">Spend</th>
          <th class="px-2 py-1.5 text-right">CPM (1k)</th>
          <th class="px-2 py-1.5 text-right">Cost/Msg</th>
          <th class="px-2 py-1.5 text-right">Cost/Result</th>
          <th class="px-2 py-1.5 text-right">Cost/Purchase</th>
          <th class="px-2 py-1.5 text-right">Impr.</th>
          <th class="px-2 py-1.5 text-right">Msgs</th>
          <th class="px-2 py-1.5 text-right">Purchases</th>
        </tr>
      </thead>

      <template x-for="c in ((expandedPages[row.page_name] || {}).campaigns || [])" :key="c.campaign_id">
        <tbody class="border-t border-slate-200">
          {{-- Campaign row --}}
          <tr class="hover:bg-slate-50">
            <td class="px-2 py-1.5 align-top">
              <button class="text-slate-500 hover:text-slate-900 select-none"
                      @click="toggleCampaignExpand(c.campaign_id, row.page_name)"
                      x-text="(expandedCampaigns[c.campaign_id] || {}).open ? '▼' : '▶'"
                      :title="(expandedCampaigns[c.campaign_id] || {}).open ? 'Hide ad sets' : 'Show ad sets'"></button>
            </td>
            <td class="px-2 py-1.5 align-top">
              <span class="inline-flex items-center gap-1.5">
                <span class="inline-block w-2 h-2 rounded-full"
                      :class="c.on ? 'bg-emerald-600' : 'bg-slate-400'"></span>
                <span class="text-[11px]" x-text="c.on ? 'Active' : 'Off'"></span>
              </span>
            </td>
            <td class="px-2 py-1.5 align-top">
              <div class="font-medium text-slate-800" x-text="c.campaign_name || ('Campaign '+c.campaign_id)"></div>
            </td>
            <td class="px-2 py-1.5 align-top whitespace-nowrap">
              <span x-show="c.first_started" x-text="fmtDate(c.first_started)"></span>
              <span x-show="!c.first_started" class="text-slate-400">—</span>
            </td>
            <td class="px-2 py-1.5 align-top whitespace-nowrap">
              <span x-show="c.first_started && c.on" x-text="daysSince(c.first_started)"></span>
              <span x-show="!c.first_started || !c.on" class="text-slate-400">—</span>
            </td>
            <td class="px-2 py-1.5 align-top whitespace-nowrap">
              <span x-show="c.latest_started" x-text="fmtDate(c.latest_started)"></span>
              <span x-show="!c.latest_started" class="text-slate-400">—</span>
            </td>
            <td class="px-2 py-1.5 text-right align-top" x-text="money(c.spend)"></td>
            <td class="px-2 py-1.5 text-right align-top" x-text="c.cpm_1000!=null?money(c.cpm_1000):'—'"></td>
            <td class="px-2 py-1.5 text-right align-top" x-text="c.cpm_msg !=null?money(c.cpm_msg ):'—'"></td>
            <td class="px-2 py-1.5 text-right align-top" x-text="c.cpr     !=null?money(c.cpr    ):'—'"></td>
            <td class="px-2 py-1.5 text-right align-top" x-text="c.cpp     !=null?money(c.cpp    ):'—'"></td>
            <td class="px-2 py-1.5 text-right align-top" x-text="num(c.impressions)"></td>
            <td class="px-2 py-1.5 text-right align-top" x-text="num(c.messages)"></td>
            <td class="px-2 py-1.5 text-right align-top" x-text="num(c.purchases)"></td>
          </tr>

          {{-- Ad sets nested under this campaign --}}
          <tr x-show="(expandedCampaigns[c.campaign_id] || {}).open">
            <td colspan="14" style="background:#eef2f7;padding:10px 12px;">
              <template x-if="(expandedCampaigns[c.campaign_id] || {}).loading">
                <div class="text-xs text-slate-500 italic">Loading ad sets…</div>
              </template>
              <template x-if="(expandedCampaigns[c.campaign_id] || {}).error">
                <div class="text-xs text-red-600"
                     x-text="'⚠ ' + (expandedCampaigns[c.campaign_id] || {}).error"></div>
              </template>
              <template x-if="!(expandedCampaigns[c.campaign_id] || {}).loading
                             && !(expandedCampaigns[c.campaign_id] || {}).error
                             && Array.isArray((expandedCampaigns[c.campaign_id] || {}).adsets)
                             && (expandedCampaigns[c.campaign_id] || {}).adsets.length === 0">
                <div class="text-xs text-slate-500 italic">Walang ad sets sa loob ng campaign na ito.</div>
              </template>

              <template x-if="!(expandedCampaigns[c.campaign_id] || {}).loading
                             && !(expandedCampaigns[c.campaign_id] || {}).error
                             && Array.isArray((expandedCampaigns[c.campaign_id] || {}).adsets)
                             && (expandedCampaigns[c.campaign_id] || {}).adsets.length > 0">
                <table class="w-full text-xs border-collapse" style="background:white;border:1px solid #cbd5e1;">
                  <thead style="background:#e2e8f0;">
                    <tr class="text-left text-slate-700">
                      <th class="w-6 px-2 py-1.5"></th>
                      <th class="w-20 px-2 py-1.5">Off / On</th>
                      <th class="px-2 py-1.5">Ad set</th>
                      <th class="px-2 py-1.5 whitespace-nowrap">First launched</th>
                      <th class="px-2 py-1.5 whitespace-nowrap">Days running</th>
                      <th class="px-2 py-1.5 whitespace-nowrap">Latest start</th>
                      <th class="px-2 py-1.5 text-right">Spend</th>
                      <th class="px-2 py-1.5 text-right">CPM (1k)</th>
                      <th class="px-2 py-1.5 text-right">Cost/Msg</th>
                      <th class="px-2 py-1.5 text-right">Cost/Result</th>
                      <th class="px-2 py-1.5 text-right">Cost/Purchase</th>
                      <th class="px-2 py-1.5 text-right">Impr.</th>
                      <th class="px-2 py-1.5 text-right">Msgs</th>
                      <th class="px-2 py-1.5 text-right">Purchases</th>
                    </tr>
                  </thead>

                  <template x-for="aset in ((expandedCampaigns[c.campaign_id] || {}).adsets || [])" :key="aset.ad_set_id">
                    <tbody class="border-t border-slate-200">
                      <tr class="hover:bg-slate-50">
                        <td class="px-2 py-1.5 align-top">
                          <button class="text-slate-500 hover:text-slate-900 select-none"
                                  @click="toggleAdSetExpand(aset.ad_set_id, c.campaign_id, row.page_name)"
                                  x-text="(expandedAdSets[aset.ad_set_id] || {}).open ? '▼' : '▶'"
                                  :title="(expandedAdSets[aset.ad_set_id] || {}).open ? 'Hide ads' : 'Show ads'"></button>
                        </td>
                        <td class="px-2 py-1.5 align-top">
                          <span class="inline-flex items-center gap-1.5">
                            <span class="inline-block w-2 h-2 rounded-full"
                                  :class="aset.on ? 'bg-emerald-600' : 'bg-slate-400'"></span>
                            <span class="text-[11px]" x-text="aset.on ? 'Active' : 'Off'"></span>
                          </span>
                        </td>
                        <td class="px-2 py-1.5 align-top">
                          <div class="font-medium text-slate-800" x-text="aset.ad_set_name || ('Ad set '+aset.ad_set_id)"></div>
                        </td>
                        <td class="px-2 py-1.5 align-top whitespace-nowrap">
                          <span x-show="aset.first_started" x-text="fmtDate(aset.first_started)"></span>
                          <span x-show="!aset.first_started" class="text-slate-400">—</span>
                        </td>
                        <td class="px-2 py-1.5 align-top whitespace-nowrap">
                          <span x-show="aset.first_started && aset.on" x-text="daysSince(aset.first_started)"></span>
                          <span x-show="!aset.first_started || !aset.on" class="text-slate-400">—</span>
                        </td>
                        <td class="px-2 py-1.5 align-top whitespace-nowrap">
                          <span x-show="aset.latest_started" x-text="fmtDate(aset.latest_started)"></span>
                          <span x-show="!aset.latest_started" class="text-slate-400">—</span>
                        </td>
                        <td class="px-2 py-1.5 text-right align-top" x-text="money(aset.spend)"></td>
                        <td class="px-2 py-1.5 text-right align-top" x-text="aset.cpm_1000!=null?money(aset.cpm_1000):'—'"></td>
                        <td class="px-2 py-1.5 text-right align-top" x-text="aset.cpm_msg !=null?money(aset.cpm_msg ):'—'"></td>
                        <td class="px-2 py-1.5 text-right align-top" x-text="aset.cpr     !=null?money(aset.cpr    ):'—'"></td>
                        <td class="px-2 py-1.5 text-right align-top" x-text="aset.cpp     !=null?money(aset.cpp    ):'—'"></td>
                        <td class="px-2 py-1.5 text-right align-top" x-text="num(aset.impressions)"></td>
                        <td class="px-2 py-1.5 text-right align-top" x-text="num(aset.messages)"></td>
                        <td class="px-2 py-1.5 text-right align-top" x-text="num(aset.purchases)"></td>
                      </tr>

                      {{-- Ads nested under this ad set --}}
                      <tr x-show="(expandedAdSets[aset.ad_set_id] || {}).open">
                        <td colspan="14" style="background:#dde4ed;padding:10px 12px;">
                          <template x-if="(expandedAdSets[aset.ad_set_id] || {}).loading">
                            <div class="text-xs text-slate-500 italic">Loading ads…</div>
                          </template>
                          <template x-if="(expandedAdSets[aset.ad_set_id] || {}).error">
                            <div class="text-xs text-red-600"
                                 x-text="'⚠ ' + (expandedAdSets[aset.ad_set_id] || {}).error"></div>
                          </template>
                          <template x-if="!(expandedAdSets[aset.ad_set_id] || {}).loading
                                         && !(expandedAdSets[aset.ad_set_id] || {}).error
                                         && Array.isArray((expandedAdSets[aset.ad_set_id] || {}).ads)
                                         && (expandedAdSets[aset.ad_set_id] || {}).ads.length === 0">
                            <div class="text-xs text-slate-500 italic">Walang ads sa loob ng ad set na ito.</div>
                          </template>

                          <template x-if="!(expandedAdSets[aset.ad_set_id] || {}).loading
                                         && !(expandedAdSets[aset.ad_set_id] || {}).error
                                         && Array.isArray((expandedAdSets[aset.ad_set_id] || {}).ads)
                                         && (expandedAdSets[aset.ad_set_id] || {}).ads.length > 0">
                            <table class="w-full text-xs border-collapse" style="background:white;border:1px solid #94a3b8;">
                              <thead style="background:#cbd5e1;">
                                <tr class="text-left text-slate-700">
                                  <th class="w-20 px-2 py-1.5">Off / On</th>
                                  <th class="px-2 py-1.5">Ad (Headline)</th>
                                  <th class="px-2 py-1.5 whitespace-nowrap">First launched</th>
                                  <th class="px-2 py-1.5 whitespace-nowrap">Days running</th>
                                  <th class="px-2 py-1.5 whitespace-nowrap">Latest start</th>
                                  <th class="px-2 py-1.5 text-right">Spend</th>
                                  <th class="px-2 py-1.5 text-right">CPM (1k)</th>
                                  <th class="px-2 py-1.5 text-right">Cost/Msg</th>
                                  <th class="px-2 py-1.5 text-right">Cost/Result</th>
                                  <th class="px-2 py-1.5 text-right">Cost/Purchase</th>
                                  <th class="px-2 py-1.5 text-right">Impr.</th>
                                  <th class="px-2 py-1.5 text-right">Msgs</th>
                                  <th class="px-2 py-1.5 text-right">Purchases</th>
                                </tr>
                              </thead>
                              <tbody>
                                <template x-for="ad in ((expandedAdSets[aset.ad_set_id] || {}).ads || [])" :key="ad.ad_id">
                                  <tr class="border-t border-slate-200 hover:bg-slate-50">
                                    <td class="px-2 py-1.5 align-top">
                                      <span class="inline-flex items-center gap-1.5">
                                        <span class="inline-block w-2 h-2 rounded-full"
                                              :class="ad.on ? 'bg-emerald-600' : 'bg-slate-400'"></span>
                                        <span class="text-[11px]" x-text="ad.on ? 'Active' : 'Off'"></span>
                                      </span>
                                    </td>
                                    <td class="px-2 py-1.5 align-top">
                                      <div class="font-medium text-slate-800" x-text="ad.headline || ('Ad '+ad.ad_id)"></div>
                                      <div class="text-[10px] text-slate-500" x-text="ad.item_name"></div>
                                    </td>
                                    <td class="px-2 py-1.5 align-top whitespace-nowrap">
                                      <span x-show="ad.first_started" x-text="fmtDate(ad.first_started)"></span>
                                      <span x-show="!ad.first_started" class="text-slate-400">—</span>
                                    </td>
                                    <td class="px-2 py-1.5 align-top whitespace-nowrap">
                                      <span x-show="ad.first_started && ad.on" x-text="daysSince(ad.first_started)"></span>
                                      <span x-show="!ad.first_started || !ad.on" class="text-slate-400">—</span>
                                    </td>
                                    <td class="px-2 py-1.5 align-top whitespace-nowrap">
                                      <span x-show="ad.latest_started" x-text="fmtDate(ad.latest_started)"></span>
                                      <span x-show="!ad.latest_started" class="text-slate-400">—</span>
                                    </td>
                                    <td class="px-2 py-1.5 text-right align-top" x-text="money(ad.spend)"></td>
                                    <td class="px-2 py-1.5 text-right align-top" x-text="ad.cpm_1000!=null?money(ad.cpm_1000):'—'"></td>
                                    <td class="px-2 py-1.5 text-right align-top" x-text="ad.cpm_msg !=null?money(ad.cpm_msg ):'—'"></td>
                                    <td class="px-2 py-1.5 text-right align-top" x-text="ad.cpr     !=null?money(ad.cpr    ):'—'"></td>
                                    <td class="px-2 py-1.5 text-right align-top" x-text="ad.cpp     !=null?money(ad.cpp    ):'—'"></td>
                                    <td class="px-2 py-1.5 text-right align-top" x-text="num(ad.impressions)"></td>
                                    <td class="px-2 py-1.5 text-right align-top" x-text="num(ad.messages)"></td>
                                    <td class="px-2 py-1.5 text-right align-top" x-text="num(ad.purchases)"></td>
                                  </tr>
                                </template>
                              </tbody>
                            </table>
                          </template>
                        </td>
                      </tr>
                    </tbody>
                  </template>
                </table>
              </template>
            </td>
          </tr>
        </tbody>
      </template>
    </table>
  </template>

</div>
