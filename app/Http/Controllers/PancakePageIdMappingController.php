<?php

namespace App\Http\Controllers;

use App\Models\PancakeId;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class PancakePageIdMappingController extends Controller
{
    public function index(Request $request)
    {
        $mappings = PancakeId::query()
            ->orderBy('pancake_page_name')
            ->orderBy('pancake_page_id')
            ->get();

        // Distinct page_name list from ads_manager_reports.page_name
        $pageNames = collect();
        if (
            Schema::hasTable('ads_manager_reports') &&
            Schema::hasColumn('ads_manager_reports', 'page_name')
        ) {
            $pageNames = DB::table('ads_manager_reports')
                ->whereNotNull('page_name')
                ->whereRaw("TRIM(page_name) <> ''")
                ->distinct()
                ->orderBy('page_name')
                ->pluck('page_name');
        }

        // Unmapped pancake_page_id list from pancake_conversations (IDs not in pancake_id table)
        $unmapped = collect();
        if (
            Schema::hasTable('pancake_conversations') &&
            Schema::hasColumn('pancake_conversations', 'pancake_page_id')
        ) {
            $unmapped = DB::table('pancake_conversations as pc')
                ->leftJoin('pancake_id as pi', 'pi.pancake_page_id', '=', 'pc.pancake_page_id')
                ->whereNull('pi.pancake_page_id')
                ->whereNotNull('pc.pancake_page_id')
                ->whereRaw("TRIM(pc.pancake_page_id) <> ''")
                ->groupBy('pc.pancake_page_id')
                ->select([
                    'pc.pancake_page_id',
                    DB::raw('COUNT(*) as conversations_count'),
                ])
                ->orderByDesc('conversations_count')
                ->orderBy('pc.pancake_page_id')
                ->get();
        }

        return view('pancake.page-page-id-mapping', [
            'mappings'  => $mappings,
            'unmapped'  => $unmapped,
            'pageNames' => $pageNames,
        ]);
    }

    public function store(Request $request)
    {
        $pageNames = $this->getPageNames();

        $rules = [
            'pancake_page_id' => ['required', 'string', 'max:255', 'unique:pancake_id,pancake_page_id'],
            // 1-to-1: page_name must be unique in pancake_id
            'pancake_page_name' => ['required', 'string', 'max:255', 'unique:pancake_id,pancake_page_name'],
        ];

        // Must come from ads_manager_reports.page_name if available
        if ($pageNames->count() > 0) {
            $rules['pancake_page_name'][] = Rule::in($pageNames->values()->all());
        }

        $data = $request->validate($rules);

        $data['pancake_page_id'] = trim($data['pancake_page_id']);
        $data['pancake_page_name'] = trim($data['pancake_page_name']);

        PancakeId::create($data);

        return redirect()
            ->to('/pancake/page-id-mapping')
            ->with('success', 'Mapping added successfully.');
    }

    public function update(Request $request, $id)
    {
        $mapping = PancakeId::findOrFail($id);
        $pageNames = $this->getPageNames();

        $rules = [
            'pancake_page_id' => [
                'required', 'string', 'max:255',
                Rule::unique('pancake_id', 'pancake_page_id')->ignore($mapping->id),
            ],
            // 1-to-1: page_name must be unique in pancake_id (ignore current row)
            'pancake_page_name' => [
                'required', 'string', 'max:255',
                Rule::unique('pancake_id', 'pancake_page_name')->ignore($mapping->id),
            ],
        ];

        // Must come from ads_manager_reports.page_name if available
        if ($pageNames->count() > 0) {
            $rules['pancake_page_name'][] = Rule::in($pageNames->values()->all());
        }

        $data = $request->validate($rules);

        $data['pancake_page_id'] = trim($data['pancake_page_id']);
        $data['pancake_page_name'] = trim($data['pancake_page_name']);

        $mapping->update($data);

        return redirect()
            ->to('/pancake/page-id-mapping')
            ->with('success', 'Mapping updated successfully.');
    }

    public function destroy($id)
    {
        PancakeId::findOrFail($id)->delete();

        return redirect()
            ->to('/pancake/page-id-mapping')
            ->with('success', 'Mapping deleted successfully.');
    }

    private function getPageNames()
    {
        if (
            Schema::hasTable('ads_manager_reports') &&
            Schema::hasColumn('ads_manager_reports', 'page_name')
        ) {
            return DB::table('ads_manager_reports')
                ->whereNotNull('page_name')
                ->whereRaw("TRIM(page_name) <> ''")
                ->distinct()
                ->orderBy('page_name')
                ->pluck('page_name');
        }

        return collect();
    }
}
