<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PageSenderMappingController extends Controller
{
    private function getDefaultColumns(): int
    {
        $val = DB::table('app_settings')
            ->where('key', 'jnt_sender_default_columns')
            ->value('value');
        return (int) ($val ?: 2);
    }

    public function index(Request $request)
    {
        $search = $request->input('search');
        $defaultColumns = $this->getDefaultColumns();

        $query = DB::table('page_sender_mappings')->orderBy('created_at', 'desc');

        $likeOperator = DB::getDriverName() === 'pgsql' ? 'ILIKE' : 'LIKE';

        if ($search) {
            $query->where(function ($q) use ($search, $likeOperator) {
                $q->where('PAGE', $likeOperator, '%' . $search . '%')
                  ->orWhere('SENDER_NAME', $likeOperator, '%' . $search . '%')
                  ->orWhere('item_name', $likeOperator, '%' . $search . '%');
            });
        }

        $mappings = $query->paginate(100);

        // Build shop name → page map for conflict detection in live preview
        // If a shop name appears on multiple pages (existing violations), first occurrence wins
        $shopPageMap = DB::table('page_sender_mappings')
            ->select('SENDER_NAME', 'PAGE')
            ->orderBy('created_at', 'asc')
            ->get()
            ->unique('SENDER_NAME')
            ->pluck('PAGE', 'SENDER_NAME');

        return view('jnt.sender-name', compact('mappings', 'defaultColumns', 'shopPageMap'));
    }

    public function saveSetting(Request $request)
    {
        $cols = (int) $request->input('default_columns', 2);
        $cols = in_array($cols, [2, 3]) ? $cols : 2;

        DB::table('app_settings')->upsert(
            [['key' => 'jnt_sender_default_columns', 'value' => (string) $cols, 'created_at' => now(), 'updated_at' => now()]],
            ['key'],
            ['value', 'updated_at']
        );

        return back()->with('success', "Default set to {$cols} columns.");
    }

    public function save(Request $request)
    {
        $rawInput = $request->input('bulk_data');

        if (!$rawInput) {
            return back()->with('error', 'No data pasted.');
        }

        $lines = explode("\n", trim($rawInput));
        $rowsToInsert = [];
        $skippedCount = 0;
        $conflictCount = 0;

        // Load existing shop→page map for conflict detection
        $shopPageMap = DB::table('page_sender_mappings')
            ->select('SENDER_NAME', 'PAGE')
            ->orderBy('created_at', 'asc')
            ->get()
            ->unique('SENDER_NAME')
            ->pluck('PAGE', 'SENDER_NAME')
            ->toArray();

        // Also track what we're about to insert (within this batch)
        $batchShopPageMap = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;

            $parts = preg_split("/\t+/", $line);

            if (count($parts) === 2) {
                // 2-column: PAGE + SENDER_NAME
                $page       = trim($parts[0]);
                $itemName   = null;
                $senderName = trim($parts[1]);
            } elseif (count($parts) >= 3) {
                // 3-column: PAGE + ITEM_NAME + SENDER_NAME
                $page       = trim($parts[0]);
                $itemName   = trim($parts[1]);
                $senderName = trim($parts[2]);
            } else {
                continue;
            }

            if (!$page || !$senderName) continue;

            // Check: shop name already used by a different page (existing DB records)
            if (isset($shopPageMap[$senderName]) && $shopPageMap[$senderName] !== $page) {
                $conflictCount++;
                continue;
            }

            // Check: shop name already used by a different page (within this batch)
            if (isset($batchShopPageMap[$senderName]) && $batchShopPageMap[$senderName] !== $page) {
                $conflictCount++;
                continue;
            }

            // Check for existing exact duplicate
            $exists = DB::table('page_sender_mappings')
                ->where('PAGE', $page)
                ->where('SENDER_NAME', $senderName)
                ->when($itemName !== null,
                    fn($q) => $q->where('item_name', $itemName),
                    fn($q) => $q->whereNull('item_name')
                )
                ->exists();

            if (!$exists) {
                $rowsToInsert[] = [
                    'PAGE'        => $page,
                    'item_name'   => $itemName,
                    'SENDER_NAME' => $senderName,
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ];
                $batchShopPageMap[$senderName] = $page;
                // Update local map so subsequent rows in same batch reflect this
                $shopPageMap[$senderName] = $page;
            } else {
                $skippedCount++;
            }
        }

        $parts = [];
        if (count($rowsToInsert)) {
            DB::table('page_sender_mappings')->insert($rowsToInsert);
            $parts[] = count($rowsToInsert) . ' inserted';
        }
        if ($skippedCount) $parts[] = $skippedCount . ' skipped (already exist)';
        if ($conflictCount) $parts[] = $conflictCount . ' skipped (shop name already used by a different page)';

        if (count($rowsToInsert)) {
            return back()->with('success', implode('. ', $parts) . '.');
        }

        return back()->with('error', implode('. ', $parts) ?: 'Nothing inserted.');
    }

    public function delete($id)
    {
        DB::table('page_sender_mappings')->where('id', $id)->delete();
        return back()->with('success', 'Deleted successfully.');
    }
}
