<?php

namespace App\Services\CardStatement;

use Illuminate\Support\Facades\Process;
use Smalot\PdfParser\Parser;

/**
 * Text ng PDF statement: pdftotext -layout kung gumagana sa server (mas maayos ang columns), kung hindi smalot/pdfparser.
 * Walang text (scan) → '' — screenshot ang i-upload sa ganoon.
 */
class PdfText
{
    public function text(string $path): string
    {
        try {
            $res = Process::timeout(60)->run([(string) config('services.card_statement.pdftotext', 'pdftotext'), '-enc', 'UTF-8', '-layout', $path, '-']);
            if ($res->successful() && trim($res->output()) !== '') return $res->output();
        } catch (\Throwable $e) {
            // walang pdftotext → smalot
        }

        try {
            return (new Parser())->parseFile($path)->getText();
        } catch (\Throwable $e) {
            return '';
        }
    }
}
