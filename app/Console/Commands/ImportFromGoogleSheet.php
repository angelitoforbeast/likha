<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Google_Client;
use Google_Service_Sheets;
use Google_Service_Sheets_ValueRange;
use App\Models\Fromgsheet;

class ImportFromGoogleSheet extends Command
{
    protected $signature = 'gsheet:import';
    protected $description = 'Import data from Google Sheet into fromgsheet table and mark DONE';

    public function handle()
    {
        $client = new Google_Client();
        $client->setAuthConfig(storage_path('app/banded-arch-432107-c9-bcdb53dc5a49.json'));
        $client->addScope(Google_Service_Sheets::SPREADSHEETS);

        $service = new Google_Service_Sheets($client);

        $spreadsheetId = '1pakj4S5a4V7fCKfa4Dv2X3nc6SocXrAjmec1bQcMEwo';
        $range = 'Test!A2:Z'; // Get all rows from row 2 onwards (including column Z)

        $response = $service->spreadsheets_values->get($spreadsheetId, $range);
        $values = $response->getValues();

        if (empty($values)) {
            $this->info('No data found.');
            return;
        }

        $updatedValues = [];
        $updateRowNumbers = [];

        foreach ($values as $index => $row) {
            // Skip if column Z (index 25) is already marked as DONE
            if (isset($row[25]) && strtolower(trim($row[25])) === 'done') {
                continue;
            }

            // Import into the database
            Fromgsheet::create([
                'column1' => $row[0] ?? null,
                'column2' => $row[1] ?? null,
                'column3' => $row[2] ?? null,
                'column4' => $row[3] ?? null,
            ]);

            // Store DONE marker to the correct row number (row 2 + index)
            $rowNumber = $index + 2;
            $updatedValues[] = ['range' => "Test!Z{$rowNumber}", 'values' => [['DONE']]];
        }

        // Batch update all the Z cells we marked
        if (!empty($updatedValues)) {
            $data = array_map(function ($item) {
                return new Google_Service_Sheets_ValueRange($item);
            }, $updatedValues);

            $body = new \Google_Service_Sheets_BatchUpdateValuesRequest([
                'valueInputOption' => 'RAW',
                'data' => $data,
            ]);

            $service->spreadsheets_values->batchUpdate($spreadsheetId, $body);
        }

        $this->info('Import completed and DONE markers applied!');
    }
}
