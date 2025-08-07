<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PageSenderMappingController extends Controller
{
    /**
     * Show the sender-name page with existing mappings
     */
    public function index(Request $request)
{
    $search = $request->input('search');

    $query = DB::table('page_sender_mappings')->orderBy('created_at', 'desc');

    $likeOperator = DB::getDriverName() === 'pgsql' ? 'ILIKE' : 'LIKE';

if ($search) {
    $query->where(function ($q) use ($search, $likeOperator) {
        $q->where('PAGE', $likeOperator, '%' . $search . '%')
          ->orWhere('SENDER_NAME', $likeOperator, '%' . $search . '%');
    });
}


    $mappings = $query->paginate(100);

    return view('jnt.sender-name', compact('mappings'));
}

    public function delete($id)
{
    DB::table('page_sender_mappings')->where('id', $id)->delete();
    return back()->with('success', 'Deleted successfully.');
}


    /**
     * Save pasted sender-name data to database (no duplicates)
     */
    public function save(Request $request)
    {
        $rawInput = $request->input('bulk_data');

        if (!$rawInput) {
            return back()->with('error', 'No data pasted.');
        }

        $lines = explode(PHP_EOL, trim($rawInput));
        $rowsToInsert = [];
        $skippedCount = 0;

        foreach ($lines as $line) {
            $parts = preg_split("/\t+/", trim($line));

            if (count($parts) < 2) {
                continue;
            }

            $page = trim($parts[0]);
            $senderName = trim($parts[1]);

            if ($page && $senderName) {
                // Check for existing row
                $exists = DB::table('page_sender_mappings')
                    ->where('PAGE', $page)
                    ->where('SENDER_NAME', $senderName)
                    ->exists();

                if (!$exists) {
                    $rowsToInsert[] = [
                        'PAGE' => $page,
                        'SENDER_NAME' => $senderName,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                } else {
                    $skippedCount++;
                }
            }
        }

        if (count($rowsToInsert)) {
            DB::table('page_sender_mappings')->insert($rowsToInsert);
        }

        if (count($rowsToInsert)) {
            return back()->with('success', count($rowsToInsert) . ' inserted. ' . $skippedCount . ' skipped (already exist).');
        }

        return back()->with('error', 'All entries already exist. Nothing inserted.');
    }
}
