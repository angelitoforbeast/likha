<?php

namespace App\Services\CardStatement;

use App\Models\CardStatement;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Daloy ng card counter-check: basahin ang statement (PDF → text → layout, larawan → vision), i-save ang mga linya
 * (hindi ang file), at itugma sa Meta records ng card. payment_activity_ads_manager ay binabasa lang.
 */
class CardReconcile
{
    public function __construct(
        private StatementParser $parser,
        private PdfText $pdf,
        private StatementVision $vision,
    ) {}

    /** Mga card (payment_method) na nasa Meta records. */
    public function cards(): array
    {
        return DB::table('payment_activity_ads_manager')
            ->whereNotNull('payment_method')->where('payment_method', '!=', '')
            ->distinct()->orderBy('payment_method')->pluck('payment_method')->all();
    }

    /** Basa + save bilang preview. Ang upload temp file ay binabasa lang, hindi kinokopya kahit saan. */
    public function upload(UploadedFile $file, string $bank, ?string $card, ?int $userId): CardStatement
    {
        $isPdf = strtolower((string) $file->guessExtension()) === 'pdf';
        if ($isPdf) {
            $text = $this->pdf->text($file->getRealPath());
            $read = $this->parser->parseText($text, $bank);
        } else {
            $read = ['bank' => 'image', 'lines' => $this->parser->finish($this->vision->read($file->getRealPath(), (string) $file->getMimeType()))];
        }
        if ($read['lines'] === []) {
            throw new StatementReadException($isPdf
                ? 'Walang nabasang linya sa PDF (baka scan ito). I-upload ang screenshot, o piliin ang tamang bangko.'
                : 'Walang nabasang linya sa screenshot.');
        }

        return DB::transaction(function () use ($read, $file, $isPdf, $card, $userId) {
            [$start, $end] = CardMatcher::period($read['lines']);
            $s = CardStatement::create([
                'card_label'    => $card,
                'bank'          => $read['bank'],
                'source_kind'   => $isPdf ? 'pdf' : 'image',
                'original_name' => mb_substr(CardNumberMask::mask((string) $file->getClientOriginalName()), 0, 255),
                'period_start'  => $start,
                'period_end'    => $end,
                'status'        => 'preview',
                'lines_count'   => count($read['lines']),
                'uploaded_by'   => $userId,
            ]);
            $now = now();
            $rows = [];
            foreach ($read['lines'] as $i => $l) {
                $rows[] = [
                    'card_statement_id' => $s->id, 'line_no' => $i + 1, 'sale_date' => $l['sale_date'], 'post_date' => $l['post_date'],
                    'description' => mb_substr($l['description'], 0, 255), 'amount' => $l['amount'],
                    'is_credit' => $l['is_credit'], 'is_ads' => $l['is_ads'], 'created_at' => $now, 'updated_at' => $now,
                ];
            }
            foreach (array_chunk($rows, 200) as $chunk) DB::table('card_statement_lines')->insert($chunk);

            return $s;
        });
    }

    /** Mga linya bilang array para sa CardMatcher. */
    public function lineArrays(CardStatement $s): array
    {
        return $s->lines()->get()->map(fn ($l) => [
            'id' => $l->id, 'sale_date' => $l->sale_date?->toDateString(), 'post_date' => $l->post_date?->toDateString(),
            'amount' => (string) $l->amount, 'is_credit' => (bool) $l->is_credit, 'is_ads' => (bool) $l->is_ads,
        ])->all();
    }

    /** Meta records na pwedeng tumugma: mula period − 2 araw hanggang period + 2 araw; isang card o lahat. */
    public function meta(array $period, ?string $card = null): array
    {
        if ($period[0] === null) return [];
        $q = DB::table('payment_activity_ads_manager')
            ->whereBetween('date', [
                Carbon::parse($period[0])->subDays(CardMatcher::NEAR_DAYS)->toDateString(),
                Carbon::parse($period[1])->addDays(CardMatcher::NEAR_DAYS)->toDateString(),
            ])
            ->whereNotNull('amount')
            ->select(['id', 'date', 'amount', 'transaction_id', 'ad_account', 'payment_method']);
        if ($card !== null) $q->where('payment_method', $card);

        return $q->orderBy('id')->get()->map(fn ($r) => [
            'id' => (int) $r->id, 'date' => substr((string) $r->date, 0, 10), 'amount' => (string) $r->amount,
            'transaction_id' => $r->transaction_id, 'ad_account' => $r->ad_account, 'payment_method' => $r->payment_method,
        ])->all();
    }

    public function suggest(CardStatement $s): array
    {
        $lines = $this->lineArrays($s);
        $byCard = array_fill_keys($this->cards(), []);
        foreach ($this->meta(CardMatcher::period($lines)) as $m) {
            if (isset($byCard[$m['payment_method']])) $byCard[$m['payment_method']][] = $m;
        }

        return CardMatcher::suggest($lines, $byCard);
    }

    /** Resulta ng tugma ngayon (binabasa ulit ang Meta, kaya kasama ang mga bagong import). */
    public function result(CardStatement $s): array
    {
        $lines = $this->lineArrays($s);
        $meta = $this->meta(CardMatcher::period($lines), (string) $s->card_label);
        $result = CardMatcher::match($lines, $meta);
        $result['meta_by_id'] = array_column($meta, null, 'id');

        return $result;
    }

    /** Itugma at i-save ang resulta sa mga linya at ang totals sa statement. */
    public function match(CardStatement $s, string $card): array
    {
        $s->card_label = $card;
        $result = $this->result($s);

        DB::transaction(function () use ($s, $result) {
            foreach ($result['lines'] as $id => $r) {
                DB::table('card_statement_lines')->where('id', $id)->where('card_statement_id', $s->id)->update($r + ['updated_at' => now()]);
            }
            $s->fill($result['totals'] + [
                'period_start' => $result['period'][0],
                'period_end'   => $result['period'][1],
                'status'       => 'matched',
                'matched_at'   => now(),
            ])->save();
        });

        return $result;
    }

    /** Meta records na malinaw na mali ang petsa (lampas sa araw na ito), para suriin ng tao. */
    public function suspicious(): array
    {
        return DB::table('payment_activity_ads_manager')
            ->where('date', '>', now('Asia/Manila')->toDateString())
            ->orderByDesc('date')->limit(50)
            ->get(['id', 'date', 'transaction_id', 'amount', 'ad_account', 'payment_method'])->all();
    }
}
