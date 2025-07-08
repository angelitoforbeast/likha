<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

class MesSegregatorController extends Controller
{
    public function index()
    {
        return view('data_encoder.index');
    }

    public function segregate(Request $request)
    {
        ini_set('memory_limit', '512M');
        set_time_limit(120);

        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv'
        ]);

        $file = $request->file('file');
        $spreadsheet = IOFactory::load($file);
        $sheet = $spreadsheet->getSheetByName('List');

        if (!$sheet) {
            return back()->withErrors(['file' => 'Sheet named "List" not found.']);
        }

        $data = $sheet->toArray();
        $headers = array_slice($data, 0, 8);
        $content = array_slice($data, 8);

        $filtered = array_filter($content, fn($row) => !empty($row[13]));
        $groups = collect($filtered)->groupBy(fn($row) => $row[13]);

        $downloads = [];
        $savedFiles = [];

        foreach ($groups as $key => $rows) {
            $newSpreadsheet = new Spreadsheet();
            $newSheet = $newSpreadsheet->getActiveSheet();
            $newSheet->fromArray($headers, null, 'A1');
            $newSheet->fromArray($rows->values()->all(), null, 'A9');

            $filename = 'segregated_' . Str::slug($key) . '_' . uniqid() . '.xlsx';
            $tempPath = storage_path('app/public/' . $filename);

            $writer = new Xlsx($newSpreadsheet);
            $writer->save($tempPath);

            $savedFiles[] = $tempPath;

            $downloads[] = [
                'label' => "Download: {$key} – " . $rows->count(),
                'url' => asset('storage/' . $filename)
            ];
        }

        // Create ZIP of all files
        $zipName = 'segregated_all_' . time() . '.zip';
        $zipPath = storage_path('app/public/' . $zipName);
        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::CREATE) === TRUE) {
            foreach ($savedFiles as $path) {
                $zip->addFile($path, basename($path));
            }
            $zip->close();

            array_unshift($downloads, [
                'label' => '📦 Download All as ZIP',
                'url' => asset('storage/' . $zipName)
            ]);
        }

        return view('data_encoder.index', compact('downloads'));
    }
}