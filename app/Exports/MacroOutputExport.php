<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class MacroOutputExport implements FromCollection, WithHeadings
{
    protected $records;

    public function __construct($records)
    {
        $this->records = $records;
    }

    public function collection()
    {
        return $this->records;
    }

    public function headings(): array
    {
        return [
            'FULL NAME',
            'PHONE NUMBER',
            'ADDRESS',
            'PROVINCE',
            'CITY',
            'BARANGAY',
            'ITEM_NAME',
            'COD',
        ];
    }
}
