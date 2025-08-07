<?php

namespace App\Jobs;

use App\Models\FromJnt;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Foundation\Bus\Dispatchable;

class ProcessJntUpload implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $path;

    public function __construct($path)
    {
        $this->path = $path;
    }

    public function handle()
    {
        if (!file_exists($this->path)) {
            \Log::error("❌ File not found: {$this->path}");
            return;
        }

        \Log::info("📖 Reading spreadsheet from: {$this->path}");

        $reader = IOFactory::createReaderForFile($this->path);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($this->path);
        $sheet = $spreadsheet->getActiveSheet();
        $data = $sheet->toArray(null, true, true, true);

        $header = array_map('trim', $data[1]);
        $rows = [];

        foreach (array_slice($data, 2) as $row) {
            $assoc = [];
            foreach ($header as $col => $key) {
                $assoc[$key] = $row[$col] ?? null;
            }
            $rows[] = $assoc;
        }

        foreach (array_chunk($rows, 1000) as $chunk) {
            \DB::table('from_jnt')->insert($chunk);
        }

        \Log::info("✅ JNT import complete.");
    }
}





