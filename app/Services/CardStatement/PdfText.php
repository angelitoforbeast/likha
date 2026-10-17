<?php

namespace App\Services\CardStatement;

use Illuminate\Support\Facades\Process;
use Smalot\PdfParser\Parser;

/**
 * Text ng PDF statement: pdftotext -layout kung gumagana sa server (mas maayos ang columns, hanggang 15 s),
 * kung hindi smalot/pdfparser — para lang sa PDF na hanggang 5 MB, dahil mabagal at mabigat ito sa malaki
 * at dapat matapos ang request bago ang 60 s ng nginx. Walang text (scan) → '' — screenshot ang i-upload.
 */
class PdfText
{
    private const PDFTOTEXT_TIMEOUT = 15;
    private const SMALOT_MAX_BYTES = 5 * 1024 * 1024;

    public function text(string $path): string
    {
        try {
            $res = Process::timeout(self::PDFTOTEXT_TIMEOUT)->run([(string) config('services.card_statement.pdftotext', 'pdftotext'), '-enc', 'UTF-8', '-layout', $path, '-']);
            if ($res->successful() && trim($res->output()) !== '') return $res->output();
        } catch (\Throwable $e) {
            // walang pdftotext o lumampas sa oras → smalot
        }

        if ((int) @filesize($path) > self::SMALOT_MAX_BYTES) {
            throw new StatementReadException('Masyadong malaki ang PDF para basahin dito (hanggang 5 MB kapag walang pdftotext sa server). Hatiin ang PDF o i-upload ang screenshot.');
        }

        try {
            return (new Parser())->parseFile($path)->getText();
        } catch (\Throwable $e) {
            return '';
        }
    }
}
