<?php

namespace App\Services\CardStatement;

/**
 * Isang bank layout. Bawat linya ay [sale_date, post_date (Y-m-d o null), description, amount "1234.56", is_credit].
 * Bagong bangko = bagong class dito at sa StatementParser::layouts().
 */
interface StatementLayout
{
    public function key(): string;

    /** Ito ba ang layout ng text na ito (para sa "auto")? */
    public function detects(string $text): bool;

    /** @return list<array{sale_date: ?string, post_date: ?string, description: string, amount: string, is_credit: bool}> */
    public function parse(string $text): array;
}
