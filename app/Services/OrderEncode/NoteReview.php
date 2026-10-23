<?php

namespace App\Services\OrderEncode;

use App\Models\AddressNote;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * Ang mga aksyon ng CEO sa address_notes (spec 135): approve, reject, palitan ang sagot (sa J&T combination lang), at ang bulk
 * approve. Ang may reviewed_at ay hindi na ginagalaw ng build. Walang pangalan o phone na isinusulat dito.
 */
class NoteReview
{
    public function __construct(private NotesBuilder $builder)
    {
    }

    public function approve(AddressNote $n, string $by, ?string $note = null): void
    {
        $this->mark($n, 'approved', $by, $note);
    }

    public function reject(AddressNote $n, string $by, ?string $note = null): void
    {
        $this->mark($n, 'rejected', $by, $note);
    }

    /**
     * Bagong sagot ng note. Ang dating sagot ay napupunta sa conflict (kasama ang bilang nito) at ang sagot na nasa conflict na ay
     * kinukuha ang bilang niya.
     * @throws InvalidArgumentException kapag ang tatlong value ay hindi isang kombinasyon sa J&T file
     */
    public function edit(AddressNote $n, string $province, string $city, string $barangay, string $by, ?string $note = null): void
    {
        $labels = $this->builder->jntLabels($province, $city, $barangay);
        if ($labels === null) throw new InvalidArgumentException('That is not a combination in the J&T address list.');
        [$p, $c, $b] = $labels;

        $current = ['province' => $n->province, 'city' => $n->city, 'barangay' => $n->barangay, 'seen' => (int) $n->seen, 'delivered' => (int) $n->delivered];
        if (Text::lower("{$current['province']}|{$current['city']}|{$current['barangay']}") !== Text::lower("{$p}|{$c}|{$b}")) {
            $others = [];
            $taken = ['seen' => 0, 'delivered' => 0];
            foreach ((array) $n->conflicts as $o) {
                if (Text::lower("{$o['province']}|{$o['city']}|{$o['barangay']}") === Text::lower("{$p}|{$c}|{$b}")) $taken = ['seen' => (int) $o['seen'], 'delivered' => (int) $o['delivered']];
                else $others[] = $o;
            }
            $others[] = $current;
            $n->fill(['province' => $p, 'city' => $c, 'barangay' => $b, 'seen' => $taken['seen'], 'delivered' => $taken['delivered'], 'conflict' => true, 'conflicts' => $others]);
        }
        $n->fill(['reviewed_by' => mb_substr($by, 0, 100), 'reviewed_at' => now(), 'review_note' => $this->text($note)]);
        $n->save();
    }

    /**
     * Aprubahan ang mga pending na note ng query na walang conflict. Ang may conflict ay hindi isinasama (tingin ng tao).
     * @return array{approved: int, conflicts: int}
     */
    public function approveMany(Builder $query, string $by, ?string $note = null): array
    {
        $pending = (clone $query)->where('status', 'pending');
        $conflicts = (clone $pending)->where('conflict', true)->count();
        $ok = (clone $pending)->where('conflict', false);
        $count = (clone $ok)->count();
        $ok->update(['status' => 'approved', 'reviewed_by' => mb_substr($by, 0, 100), 'reviewed_at' => now(), 'review_note' => $this->text($note)]);

        return ['approved' => $count, 'conflicts' => $conflicts];
    }

    private function mark(AddressNote $n, string $status, string $by, ?string $note): void
    {
        $n->update(['status' => $status, 'reviewed_by' => mb_substr($by, 0, 100), 'reviewed_at' => now(), 'review_note' => $this->text($note)]);
    }

    private function text(?string $note): ?string
    {
        $t = mb_substr(trim((string) $note), 0, 500);

        return $t === '' ? null : $t;
    }
}
