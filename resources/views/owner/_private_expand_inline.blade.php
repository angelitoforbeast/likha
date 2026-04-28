{{--
  Inline campaigns/adsets/ads drilldown for /owner/private rows.
  FB Ads Manager-like styling. Cols-driven via visibleCampaignsCols()
  (visibility + order managed globally via /owner/column-settings).

  Reuses /ads_manager/campaigns/data JSON endpoint with page_name +
  optional campaign_id / ad_set_id filters. Tree-style nested expand.
  Date range = this calendar month (PH).

  Expected scope (from Alpine privateUI):
    row              — current page row from sortedRows()
    expandedPages    — keyed by page_name
    expandedCampaigns— keyed by campaign_id
    expandedAdSets   — keyed by ad_set_id
    helpers: money, num, fmtDate, daysSince, visibleCampaignsCols,
             campLabel(col, level), campRowName(row, level)
--}}
<div class="expand-panel">

  <div class="expand-header">
    <div class="expand-title">
      <b x-text="row.page_name"></b>
      <span style="margin:0 6px;color:#cbd5e1;">·</span>
      <span>
        <span x-text="row.anchor_first_date ? ('since ' + fmtMD(row.anchor_first_date) + ' (when ' + row.item_name + ' became primary)') : ('range start')"></span>
        →
        <span x-text="endDate"></span>
        · all campaigns with spend
      </span>
    </div>
    <button class="expand-close" @click="togglePageExpand(row.page_name)">✕ Close</button>
  </div>

  <template x-if="(expandedPages[row.page_name] || {}).loading">
    <div class="text-xs italic" style="color:#65676b;padding:14px 6px;">Loading campaigns…</div>
  </template>
  <template x-if="(expandedPages[row.page_name] || {}).error">
    <div class="text-xs" style="color:#dc2626;padding:14px 6px;"
         x-text="'⚠ ' + (expandedPages[row.page_name] || {}).error"></div>
  </template>
  <template x-if="!(expandedPages[row.page_name] || {}).loading
                 && !(expandedPages[row.page_name] || {}).error
                 && Array.isArray((expandedPages[row.page_name] || {}).campaigns)
                 && (expandedPages[row.page_name] || {}).campaigns.length === 0">
    <div class="text-xs italic" style="color:#65676b;padding:14px 6px;">Walang campaigns na may spend para sa page na ito sa selected date range.</div>
  </template>

  <template x-if="!(expandedPages[row.page_name] || {}).loading
                 && !(expandedPages[row.page_name] || {}).error
                 && Array.isArray((expandedPages[row.page_name] || {}).campaigns)
                 && (expandedPages[row.page_name] || {}).campaigns.length > 0">
    <div class="expand-wrap">
    <table class="fb-table">
      <thead>
        <tr>
          <th style="width:32px;padding:7px 4px 7px 10px;"></th>{{-- chevron col --}}
          <template x-for="col in visibleCampaignsCols()" :key="'ec-h-'+col.id">
            <th :class="(col.sort ? 'sortable ' : '') + (col.align==='right' ? 'text-right' : '')"
                :style="col.minw ? ('min-width:'+col.minw+'px') : ''">
              <span x-text="campLabel(col, 'campaigns')"></span>
            </th>
          </template>
        </tr>
      </thead>

      <template x-for="c in ((expandedPages[row.page_name] || {}).campaigns || [])" :key="c.campaign_id">
        <tbody style="border-top:1px solid #e4e6eb;">

          {{-- Campaign row --}}
          <tr>
            <td style="text-align:center;padding:8px 4px 8px 10px;">
              <button @click="toggleCampaignExpand(c.campaign_id, row.page_name)"
                      style="background:none;border:none;cursor:pointer;color:#65676b;font-size:11px;line-height:1;padding:2px;"
                      :title="(expandedCampaigns[c.campaign_id] || {}).open ? 'Hide ad sets' : 'Show ad sets'"
                      x-text="(expandedCampaigns[c.campaign_id] || {}).open ? '▼' : '▶'"></button>
            </td>
            <template x-for="col in visibleCampaignsCols()" :key="'ec-c-'+col.id">
              <td :class="(col.align==='right' ? 'num' : '')"
                  :style="campaignsCellFormatStyle(col.id, c, c[col.id])">
                <template x-if="col.type==='on'">
                  <span :class="'fb-pill ' + (c.on ? 'active' : 'off')"
                        x-text="c.on ? 'Active' : 'Off'"></span>
                </template>
                <template x-if="col.type==='name'">
                  <div>
                    <div class="name" x-text="campRowName(c, 'campaigns')"></div>
                  </div>
                </template>
                <template x-if="col.type==='date'">
                  <span>
                    <template x-if="c[col.id]">
                      <span style="white-space:nowrap;"
                            :title="(col.id==='first_started' && c.running_at_start) ? 'Already running at our earliest record — true launch is before our data' : ''">
                        <template x-if="col.id==='first_started' && c.running_at_start">
                          <span style="color:#64748b;font-weight:600;">≥&nbsp;</span>
                        </template>
                        <span x-text="fmtDate(c[col.id])"></span>
                      </span>
                    </template>
                    <template x-if="!c[col.id]"><span style="color:#bcc0c4;">—</span></template>
                  </span>
                </template>
                <template x-if="col.type==='days_running'">
                  <span>
                    <template x-if="c.first_started && c.on">
                      <span :title="c.running_at_start ? 'Lower bound — already running before our data starts' : ''">
                        <template x-if="c.running_at_start">
                          <span style="color:#64748b;font-weight:600;">≥&nbsp;</span>
                        </template>
                        <span x-text="daysSince(c.first_started)"></span>
                      </span>
                    </template>
                    <template x-if="!c.first_started || !c.on">
                      <span style="color:#bcc0c4;">—</span>
                    </template>
                  </span>
                </template>
                <template x-if="col.type==='money'">
                  <span x-text="c[col.id] != null ? money(c[col.id]) : '—'"></span>
                </template>
                <template x-if="col.type==='integer'">
                  <span x-text="c[col.id] != null ? num(c[col.id]) : '—'"></span>
                </template>
                <template x-if="col.type==='percent'">
                  <span x-text="c[col.id] != null ? (Number(c[col.id]).toFixed(1) + '%') : '—'"></span>
                </template>
                <template x-if="col.type==='account'">
                  <span>
                    <template x-if="c.account_id && c.account_name">
                      <span>
                        <span style="font-weight:500;" x-text="c.account_name"></span>
                        <div style="font-size:10px;color:#94a3b8;font-family:monospace;" x-text="c.account_id"></div>
                      </span>
                    </template>
                    <template x-if="c.account_id && !c.account_name">
                      <span title="Hindi pa naka-register sa /ads_manager/ad_account">
                        <span style="color:#dc2626;font-weight:600;">⚠ unmapped</span>
                        <div style="font-size:10px;color:#94a3b8;font-family:monospace;" x-text="c.account_id"></div>
                      </span>
                    </template>
                    <template x-if="!c.account_id"><span style="color:#cbd5e1;">—</span></template>
                  </span>
                </template>
              </td>
            </template>
          </tr>

          {{-- Ad sets nested under this campaign (level 1) --}}
          <tr x-show="(expandedCampaigns[c.campaign_id] || {}).open" class="expand-nest-1">
            <td class="nest-host" :colspan="visibleCampaignsCols().length + 1">
              <template x-if="(expandedCampaigns[c.campaign_id] || {}).loading">
                <div class="text-xs italic" style="color:#65676b;">Loading ad sets…</div>
              </template>
              <template x-if="(expandedCampaigns[c.campaign_id] || {}).error">
                <div class="text-xs" style="color:#dc2626;"
                     x-text="'⚠ ' + (expandedCampaigns[c.campaign_id] || {}).error"></div>
              </template>
              <template x-if="!(expandedCampaigns[c.campaign_id] || {}).loading
                             && !(expandedCampaigns[c.campaign_id] || {}).error
                             && Array.isArray((expandedCampaigns[c.campaign_id] || {}).adsets)
                             && (expandedCampaigns[c.campaign_id] || {}).adsets.length === 0">
                <div class="text-xs italic" style="color:#65676b;">Walang ad sets sa loob ng campaign na ito.</div>
              </template>

              <template x-if="!(expandedCampaigns[c.campaign_id] || {}).loading
                             && !(expandedCampaigns[c.campaign_id] || {}).error
                             && Array.isArray((expandedCampaigns[c.campaign_id] || {}).adsets)
                             && (expandedCampaigns[c.campaign_id] || {}).adsets.length > 0">
                <div class="expand-wrap" style="border-color:#94a3b8;">
                <table class="fb-table">
                  <thead>
                    <tr>
                      <th style="width:32px;padding:7px 4px 7px 10px;"></th>
                      <template x-for="col in visibleCampaignsCols()" :key="'ea-h-'+col.id">
                        <th :class="(col.sort ? 'sortable ' : '') + (col.align==='right' ? 'text-right' : '')"
                            :style="col.minw ? ('min-width:'+col.minw+'px') : ''">
                          <span x-text="campLabel(col, 'adsets')"></span>
                        </th>
                      </template>
                    </tr>
                  </thead>

                  <template x-for="aset in ((expandedCampaigns[c.campaign_id] || {}).adsets || [])" :key="aset.ad_set_id">
                    <tbody style="border-top:1px solid #e4e6eb;">

                      {{-- Ad set row --}}
                      <tr>
                        <td style="text-align:center;padding:8px 4px 8px 10px;">
                          <button @click="toggleAdSetExpand(aset.ad_set_id, c.campaign_id, row.page_name)"
                                  style="background:none;border:none;cursor:pointer;color:#65676b;font-size:11px;line-height:1;padding:2px;"
                                  :title="(expandedAdSets[aset.ad_set_id] || {}).open ? 'Hide ads' : 'Show ads'"
                                  x-text="(expandedAdSets[aset.ad_set_id] || {}).open ? '▼' : '▶'"></button>
                        </td>
                        <template x-for="col in visibleCampaignsCols()" :key="'ea-c-'+col.id">
                          <td :class="(col.align==='right' ? 'num' : '')"
                              :style="campaignsCellFormatStyle(col.id, aset, aset[col.id])">
                            <template x-if="col.type==='on'">
                              <span :class="'fb-pill ' + (aset.on ? 'active' : 'off')"
                                    x-text="aset.on ? 'Active' : 'Off'"></span>
                            </template>
                            <template x-if="col.type==='name'">
                              <div class="name" x-text="campRowName(aset, 'adsets')"></div>
                            </template>
                            <template x-if="col.type==='date'">
                              <span>
                                <template x-if="aset[col.id]">
                                  <span style="white-space:nowrap;"
                                        :title="(col.id==='first_started' && aset.running_at_start) ? 'Already running at earliest record — true launch is before our data' : ''">
                                    <template x-if="col.id==='first_started' && aset.running_at_start">
                                      <span style="color:#64748b;font-weight:600;">≥&nbsp;</span>
                                    </template>
                                    <span x-text="fmtDate(aset[col.id])"></span>
                                  </span>
                                </template>
                                <template x-if="!aset[col.id]"><span style="color:#bcc0c4;">—</span></template>
                              </span>
                            </template>
                            <template x-if="col.type==='days_running'">
                              <span>
                                <template x-if="aset.first_started && aset.on">
                                  <span :title="aset.running_at_start ? 'Lower bound — already running before our data' : ''">
                                    <template x-if="aset.running_at_start">
                                      <span style="color:#64748b;font-weight:600;">≥&nbsp;</span>
                                    </template>
                                    <span x-text="daysSince(aset.first_started)"></span>
                                  </span>
                                </template>
                                <template x-if="!aset.first_started || !aset.on"><span style="color:#bcc0c4;">—</span></template>
                              </span>
                            </template>
                            <template x-if="col.type==='money'">
                              <span x-text="aset[col.id] != null ? money(aset[col.id]) : '—'"></span>
                            </template>
                            <template x-if="col.type==='integer'">
                              <span x-text="aset[col.id] != null ? num(aset[col.id]) : '—'"></span>
                            </template>
                            <template x-if="col.type==='percent'">
                              <span x-text="aset[col.id] != null ? (Number(aset[col.id]).toFixed(1) + '%') : '—'"></span>
                            </template>
                            <template x-if="col.type==='account'">
                              <span>
                                <template x-if="aset.account_id && aset.account_name">
                                  <span>
                                    <span style="font-weight:500;" x-text="aset.account_name"></span>
                                    <div style="font-size:10px;color:#94a3b8;font-family:monospace;" x-text="aset.account_id"></div>
                                  </span>
                                </template>
                                <template x-if="aset.account_id && !aset.account_name">
                                  <span title="Hindi pa naka-register sa /ads_manager/ad_account">
                                    <span style="color:#dc2626;font-weight:600;">⚠ unmapped</span>
                                    <div style="font-size:10px;color:#94a3b8;font-family:monospace;" x-text="aset.account_id"></div>
                                  </span>
                                </template>
                                <template x-if="!aset.account_id"><span style="color:#cbd5e1;">—</span></template>
                              </span>
                            </template>
                          </td>
                        </template>
                      </tr>

                      {{-- Ads nested under this ad set (level 2 — leaf, no further expand) --}}
                      <tr x-show="(expandedAdSets[aset.ad_set_id] || {}).open" class="expand-nest-2">
                        <td class="nest-host" :colspan="visibleCampaignsCols().length + 1">
                          <template x-if="(expandedAdSets[aset.ad_set_id] || {}).loading">
                            <div class="text-xs italic" style="color:#65676b;">Loading ads…</div>
                          </template>
                          <template x-if="(expandedAdSets[aset.ad_set_id] || {}).error">
                            <div class="text-xs" style="color:#dc2626;"
                                 x-text="'⚠ ' + (expandedAdSets[aset.ad_set_id] || {}).error"></div>
                          </template>
                          <template x-if="!(expandedAdSets[aset.ad_set_id] || {}).loading
                                         && !(expandedAdSets[aset.ad_set_id] || {}).error
                                         && Array.isArray((expandedAdSets[aset.ad_set_id] || {}).ads)
                                         && (expandedAdSets[aset.ad_set_id] || {}).ads.length === 0">
                            <div class="text-xs italic" style="color:#65676b;">Walang ads sa loob ng ad set na ito.</div>
                          </template>

                          <template x-if="!(expandedAdSets[aset.ad_set_id] || {}).loading
                                         && !(expandedAdSets[aset.ad_set_id] || {}).error
                                         && Array.isArray((expandedAdSets[aset.ad_set_id] || {}).ads)
                                         && (expandedAdSets[aset.ad_set_id] || {}).ads.length > 0">
                            <div class="expand-wrap" style="border-color:#64748b;">
                            <table class="fb-table">
                              <thead>
                                <tr>
                                  <template x-for="col in visibleCampaignsCols()" :key="'ed-h-'+col.id">
                                    <th :class="(col.sort ? 'sortable ' : '') + (col.align==='right' ? 'text-right' : '')"
                                        :style="col.minw ? ('min-width:'+col.minw+'px') : ''">
                                      <span x-text="campLabel(col, 'ads')"></span>
                                    </th>
                                  </template>
                                </tr>
                              </thead>
                              <tbody>
                                <template x-for="ad in ((expandedAdSets[aset.ad_set_id] || {}).ads || [])" :key="ad.ad_id">
                                  <tr>
                                    <template x-for="col in visibleCampaignsCols()" :key="'ed-c-'+col.id">
                                      <td :class="(col.align==='right' ? 'num' : '')"
                                          :style="campaignsCellFormatStyle(col.id, ad, ad[col.id])">
                                        <template x-if="col.type==='on'">
                                          <span :class="'fb-pill ' + (ad.on ? 'active' : 'off')"
                                                x-text="ad.on ? 'Active' : 'Off'"></span>
                                        </template>
                                        <template x-if="col.type==='name'">
                                          <div>
                                            <div class="name" x-text="campRowName(ad, 'ads')"></div>
                                            <template x-if="ad.item_name">
                                              <div class="sub" x-text="ad.item_name"></div>
                                            </template>
                                          </div>
                                        </template>
                                        <template x-if="col.type==='date'">
                                          <span>
                                            <template x-if="ad[col.id]">
                                              <span style="white-space:nowrap;"
                                                    :title="(col.id==='first_started' && ad.running_at_start) ? 'Already running at earliest record — true launch is before our data' : ''">
                                                <template x-if="col.id==='first_started' && ad.running_at_start">
                                                  <span style="color:#64748b;font-weight:600;">≥&nbsp;</span>
                                                </template>
                                                <span x-text="fmtDate(ad[col.id])"></span>
                                              </span>
                                            </template>
                                            <template x-if="!ad[col.id]"><span style="color:#bcc0c4;">—</span></template>
                                          </span>
                                        </template>
                                        <template x-if="col.type==='days_running'">
                                          <span>
                                            <template x-if="ad.first_started && ad.on">
                                              <span :title="ad.running_at_start ? 'Lower bound — already running before our data' : ''">
                                                <template x-if="ad.running_at_start">
                                                  <span style="color:#64748b;font-weight:600;">≥&nbsp;</span>
                                                </template>
                                                <span x-text="daysSince(ad.first_started)"></span>
                                              </span>
                                            </template>
                                            <template x-if="!ad.first_started || !ad.on"><span style="color:#bcc0c4;">—</span></template>
                                          </span>
                                        </template>
                                        <template x-if="col.type==='money'">
                                          <span x-text="ad[col.id] != null ? money(ad[col.id]) : '—'"></span>
                                        </template>
                                        <template x-if="col.type==='integer'">
                                          <span x-text="ad[col.id] != null ? num(ad[col.id]) : '—'"></span>
                                        </template>
                                        <template x-if="col.type==='percent'">
                                          <span x-text="ad[col.id] != null ? (Number(ad[col.id]).toFixed(1) + '%') : '—'"></span>
                                        </template>
                                        <template x-if="col.type==='account'">
                                          <span>
                                            <template x-if="ad.account_id && ad.account_name">
                                              <span>
                                                <span style="font-weight:500;" x-text="ad.account_name"></span>
                                                <div style="font-size:10px;color:#94a3b8;font-family:monospace;" x-text="ad.account_id"></div>
                                              </span>
                                            </template>
                                            <template x-if="ad.account_id && !ad.account_name">
                                              <span title="Hindi pa naka-register sa /ads_manager/ad_account">
                                                <span style="color:#dc2626;font-weight:600;">⚠ unmapped</span>
                                                <div style="font-size:10px;color:#94a3b8;font-family:monospace;" x-text="ad.account_id"></div>
                                              </span>
                                            </template>
                                            <template x-if="!ad.account_id"><span style="color:#cbd5e1;">—</span></template>
                                          </span>
                                        </template>
                                      </td>
                                    </template>
                                  </tr>
                                </template>
                              </tbody>
                            </table>
                            </div>
                          </template>
                        </td>
                      </tr>
                    </tbody>
                  </template>
                </table>
                </div>
              </template>
            </td>
          </tr>

        </tbody>
      </template>
    </table>
    </div>
  </template>

</div>
