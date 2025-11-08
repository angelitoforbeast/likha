<?php

namespace App\Imports;

use App\Models\AdsManagerReport;
use App\Models\AdCampaignCreative;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Carbon\Carbon;

// Keep long numeric IDs as strings (avoid scientific notation/precision loss)
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;

class AdsManagerReportImport extends DefaultValueBinder implements ToCollection, WithHeadingRow, WithCustomValueBinder
{
    public $inserted = 0;
    public $updated  = 0;

    // Force all cell values to string
    public function bindValue(Cell $cell, $value)
    {
        $cell->setValueExplicit((string)$value, DataType::TYPE_STRING);
        return true;
    }

    public function collection(Collection $rows)
    {
        foreach ($rows as $row) {
            // --- Normalize key fields ---
            $day  = $this->cast($row['day'] ?? null, 'date');         // Y-m-d
            $adId = trim((string)($row['ad_id'] ?? ''));

            // skip silently if missing keys (no extra noise)
            if ($day === null || $adId === '') {
                continue;
            }

            // --- Lookup by: day + ad_id ---
            $existing = AdsManagerReport::where('day', $day)
                ->where('ad_id', $adId)
                ->first();

            // Prepare payload
            $data = [
                // keys
                'day'   => $day,
                'ad_id' => $adId,

                // ids (keep as strings)
                'campaign_id' => isset($row['campaign_id']) ? trim((string)$row['campaign_id']) : null,
                'ad_set_id'   => isset($row['ad_set_id'])   ? trim((string)$row['ad_set_id'])   : null,

                // descriptors
                'page_name'         => $row['page_name']         ?? null,
                'campaign_name'     => $row['campaign_name']     ?? null,
                'ad_set_name'       => $row['ad_set_name']       ?? null,
                'campaign_delivery' => $row['campaign_delivery'] ?? null,
                'ad_set_delivery'   => $row['ad_set_delivery']   ?? null,

                // metrics
                'reach'                           => $this->cast($row['reach'] ?? null, 'int'),
                'impressions'                     => $this->cast($row['impressions'] ?? null, 'int'),
                'ad_set_budget'                   => $this->cast($row['ad_set_budget'] ?? null, 'float'),
                'ad_set_budget_type'              => $row['ad_set_budget_type'] ?? null,
                'attribution_setting'             => $row['attribution_setting'] ?? null,
                'result_type'                     => $row['result_type'] ?? null,
                'results'                         => $this->cast($row['results'] ?? null, 'int'),
                'amount_spent_php'                => $this->cast($row['amount_spent_php'] ?? null, 'float'),
                'cost_per_result'                 => $this->cast($row['cost_per_result'] ?? null, 'float'),
                'starts'                          => $this->cast($row['starts'] ?? null, 'datetime'),
                'ends'                            => $this->cast($row['ends'] ?? null, 'datetime'),
                'messaging_conversations_started' => $this->cast($row['messaging_conversations_started'] ?? null, 'int'),
                'purchases'                       => $this->cast($row['purchases'] ?? null, 'int'),
                'reporting_starts'                => $this->cast($row['reporting_starts'] ?? null, 'datetime'),
                'reporting_ends'                  => $this->cast($row['reporting_ends'] ?? null, 'datetime'),

                // creatives (still stored here)
                'headline'         => $row['headline'] ?? null,
                'body_ad_settings' => $row['body_ad_settings'] ?? null,
            ];

            if ($existing) {
                $existing->update($data);
                $this->updated++;
                Log::info('AMR UPDATE', ['day' => $day, 'ad_id' => $adId]); // per-row UPDATE
            } else {
                AdsManagerReport::create($data);
                $this->inserted++;
                Log::info('AMR INSERT', ['day' => $day, 'ad_id' => $adId]); // per-row INSERT
            }

            // --- Creative sync by campaign_id (no extra logs) ---
            $campaignId = $data['campaign_id'] ?? null;
            if (!empty($campaignId)) {
                $hasActive = AdsManagerReport::where('campaign_id', $campaignId)
                    ->whereRaw("LOWER(ad_set_delivery) = 'active'")
                    ->exists();

                $creative = AdCampaignCreative::where('campaign_id', $campaignId)->first();

                if (!$creative) {
                    AdCampaignCreative::create([
                        'campaign_id'      => $campaignId,
                        'campaign_name'    => $data['campaign_name'] ?? null,
                        'page_name'        => $data['page_name'] ?? null,
                        'headline'         => $data['headline'] ?? null,
                        'body_ad_settings' => $data['body_ad_settings'] ?? null,
                        'ad_set_delivery'  => $hasActive ? 'Active' : 'Inactive',
                    ]);
                } else {
                    $creative->campaign_name    = $data['campaign_name'] ?? $creative->campaign_name;
                    $creative->page_name        = $data['page_name'] ?? $creative->page_name;
                    $creative->body_ad_settings = $data['body_ad_settings'] ?? $creative->body_ad_settings;
                    $creative->ad_set_delivery  = $hasActive ? 'Active' : 'Inactive';
                    $creative->save();
                }
            }
        }

        // no summary log here (you wanted per-row only)
    }

    public function getSummary()
    {
        return [
            'inserted' => $this->inserted,
            'updated'  => $this->updated,
        ];
    }

    private function cast($value, $type)
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            switch ($type) {
                case 'int':
                    return is_numeric($value) ? (int)$value : null;
                case 'float':
                    return is_numeric($value) ? (float)$value : null;
                case 'date':
                    return Carbon::parse($value)->format('Y-m-d');
                case 'datetime':
                    return Carbon::parse($value)->format('Y-m-d H:i:s');
                default:
                    return $value;
            }
        } catch (\Exception $e) {
            return null; // keep logs clean as requested
        }
    }
}
