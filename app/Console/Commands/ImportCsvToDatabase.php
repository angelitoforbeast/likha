<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ImportCsvToDatabase extends Command
{
    protected $signature = 'csv:import';
    protected $description = 'Import all CSV files in storage/app/csv to their corresponding tables';

    public function handle()
    {
        $files = Storage::files('csv');

        foreach ($files as $file) {
            if (!str_ends_with($file, '.csv')) continue;

            $table = basename($file, '.csv');
            $path = storage_path('app/' . $file);
            $csv = array_map('str_getcsv', file($path));

            if (count($csv) < 2) {
                $this->warn("⚠️ Skipping {$file}: Not enough rows.");
                continue;
            }

            $columns = array_map('trim', $csv[0]);
            $rows = array_slice($csv, 1);
            $imported = 0;

            foreach ($rows as $index => $row) {
                if (count($row) !== count($columns)) {
                    $this->warn("⚠️ Skipping row {$index} in {$file}: column count mismatch.");
                    continue;
                }

                $data = array_combine($columns, $row);

                try {
                    DB::table($table)->insert($data);
                    $imported++;
                } catch (\Exception $e) {
                    $this->error("❌ Failed to insert into {$table}: " . $e->getMessage());
                    continue;
                }
            }

            $this->info("✅ Imported {$imported} rows into [{$table}]");
        }

        $this->info("🎉 All CSV files processed.");
    }
}
