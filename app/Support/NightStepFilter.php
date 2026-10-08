<?php

namespace App\Support;

use App\Models\NightRunStep;
use App\Services\NightAstraRun;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ang `night_step` sa URL ng Checker 1: ang mga row ng isang gabi na iniwan ni Astra para sa tao
 * (state `done` at hindi PROCEED, anuman ang code) — kapareho ng binibilang na "for a person" sa Night run.
 *
 * Galing sa address bar ang value kaya hindi ito pinagkakatiwalaan: digits lang (hanggang 9), at ang step ay
 * dapat umiiral at kind `astra`. Ang hindi pasado ay "hindi valid" at WALANG row na ipinapakita — hindi
 * kailanman ang buong araw. Ang step id lang at dalawang petsa ang hawak nito: walang laman ng night row.
 */
class NightStepFilter
{
    private function __construct(
        public readonly bool $valid,
        public readonly ?int $stepId = null,
        public readonly ?string $nightDate = null,
        public readonly ?string $ordersDate = null,
    ) {
    }

    /** null = walang filter (walang parameter o blangko): walang query na ginagawa, ang page ay gaya ng dati. */
    public static function fromRequest(Request $request): ?self
    {
        $raw = $request->query('night_step');
        if ($raw === null || $raw === '') {
            return null;
        }

        // Array, sign, decimal, exponent, sobrang haba: hindi na umaabot sa database.
        if (!is_string($raw) || preg_match('/\A[0-9]{1,9}\z/', $raw) !== 1) {
            return new self(false);
        }

        $step = Schema::hasTable('night_run_steps') && Schema::hasTable('night_astra_rows')
            ? NightRunStep::where('id', (int) $raw)->where('kind', NightAstraRun::KIND)->first()
            : null;
        if (!$step) {
            return new self(false);
        }

        $nightDate = substr((string) $step->night_date, 0, 10);

        return new self(true, (int) $step->id, $nightDate, NightAstraRun::ordersDate($nightDate));
    }

    /**
     * Dagdag na AND sa query ng macro_output: kaya lang nitong paliitin ang resulta.
     * Subquery ang mga id (hanggang libo-libo ang row ng isang gabi): hindi kinakarga sa PHP at wala sa URL.
     */
    public function narrow($query): void
    {
        if (!$this->valid) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereIn('macro_output.id', $this->nightRows()->select('macro_output_id'));
    }

    /** Ilan ang row ng gabi ngayon (kasama ang mga order na nabura na o inilipat ng petsa). Para sa valid na filter lang. */
    public function nightRowCount(): int
    {
        return $this->nightRows()->count();
    }

    private function nightRows()
    {
        return DB::table('night_astra_rows')->where('step_id', $this->stepId)->where('state', 'done')->where('proceed', false);
    }
}
