<?php

namespace App\Imports;

use App\Models\AdsManagerReport;
use App\Models\AdCampaignCreative;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Carbon\Carbon;

class AdsManagerReportImport implements ToCollection, WithHeadingRow
{
    public $inserted = 0;
    public $updated = 0;

    public function collection(Collection $rows)
    {
        foreach ($rows as $row) {
            $existing = AdsManagerReport::where('day', $this->cast($row['day'] ?? null, 'date'))
                ->where('campaign_id', $row['campaign_id'] ?? null)
                ->where('ad_set_id', $row['ad_set_id'] ?? null)
                ->first();

            $data = [
                'page_name' => $row['page_name'] ?? null,
                'campaign_name' => $row['campaign_name'] ?? null,
                'ad_set_name' => $row['ad_set_name'] ?? null,
                'campaign_delivery' => $row['campaign_delivery'] ?? null,
                'ad_set_delivery' => $row['ad_set_delivery'] ?? null,
                'reach' => $this->cast($row['reach'] ?? null, 'int'),
                'impressions' => $this->cast($row['impressions'] ?? null, 'int'),
                'ad_set_budget' => $this->cast($row['ad_set_budget'] ?? null, 'float'),
                'ad_set_budget_type' => $row['ad_set_budget_type'] ?? null,
                'attribution_setting' => $row['attribution_setting'] ?? null,
                'result_type' => $row['result_type'] ?? null,
                'results' => $this->cast($row['results'] ?? null, 'int'),
                'amount_spent_php' => $this->cast($row['amount_spent_php'] ?? null, 'float'),
                'cost_per_result' => $this->cast($row['cost_per_result'] ?? null, 'float'),
                'starts' => $this->cast($row['starts'] ?? null, 'datetime'),
                'ends' => $this->cast($row['ends'] ?? null, 'datetime'),
                'messaging_conversations_started' => $this->cast($row['messaging_conversations_started'] ?? null, 'int'),
                'purchases' => $this->cast($row['purchases'] ?? null, 'int'),
                'reporting_starts' => $this->cast($row['reporting_starts'] ?? null, 'datetime'),
                'reporting_ends' => $this->cast($row['reporting_ends'] ?? null, 'datetime'),

                // Deprecated fields — moved to creatives
                'headline' => $row['headline'] ?? null,
                'body_ad_settings' => $row['body_ad_settings'] ?? null,
                'ad_id' => $row['ad_id'] ?? null,
            ];

            if ($existing) {
                $existing->update($data);
                $this->updated++;
            } else {
                AdsManagerReport::create(array_merge([
                    'day' => $this->cast($row['day'] ?? null, 'date'),
                    'campaign_id' => $row['campaign_id'] ?? null,
                    'ad_set_id' => $row['ad_set_id'] ?? null,
                ], $data));
                $this->inserted++;
            }

            // ✅ Sync creative data without overwriting existing headline
            if (!empty($row['campaign_id'])) {
                $hasActive = AdsManagerReport::where('campaign_id', $row['campaign_id'])
                    ->whereRaw("LOWER(ad_set_delivery) = 'active'")
                    ->exists();

                $creative = AdCampaignCreative::where('campaign_id', $row['campaign_id'])->first();

                if (!$creative) {
                    // First time insert, include headline
                    AdCampaignCreative::create([
                        'campaign_id' => $row['campaign_id'],
                        'campaign_name' => $row['campaign_name'] ?? null,
                        'page_name' => $row['page_name'] ?? null,
                        'headline' => $row['headline'] ?? null,
                        'body_ad_settings' => $row['body_ad_settings'] ?? null,
                        'ad_set_delivery' => $hasActive ? 'Active' : 'Inactive',
                    ]);
                } else {
                    // Update other fields only, preserve existing headline
                    $creative->campaign_name = $row['campaign_name'] ?? $creative->campaign_name;
                    $creative->page_name = $row['page_name'] ?? $creative->page_name;
                    $creative->body_ad_settings = $row['body_ad_settings'] ?? $creative->body_ad_settings;
                    $creative->ad_set_delivery = $hasActive ? 'Active' : 'Inactive';
                    $creative->save();
                }
            }
        }
    }

    public function getSummary()
    {
        return [
            'inserted' => $this->inserted,
            'updated' => $this->updated,
        ];
    }

    private function cast($value, $type)
    {
        if (is_null($value) || $value === '') return null;

        try {
            switch ($type) {
                case 'int':
                    return is_numeric($value) ? (int) $value : null;
                case 'float':
                    return is_numeric($value) ? (float) $value : null;
                case 'date':
                    return Carbon::parse($value)->format('Y-m-d');
                case 'datetime':
                    return Carbon::parse($value)->format('Y-m-d H:i:s');
                default:
                    return $value;
            }
        } catch (\Exception $e) {
            return null;
        }
    }
}
