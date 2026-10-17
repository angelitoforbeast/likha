<?php

namespace App\Http\Controllers;

use App\Models\CardStatement;
use App\Services\CardStatement\CardReconcile;
use App\Services\CardStatement\StatementParser;
use App\Services\CardStatement\StatementReadException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

/**
 * /ads_manager/payment/reconcile — card statement vs Meta ad payments. CEO lang: 404 sa ibang role,
 * kahit naka-check sa nav settings (walang NavAccess dito), dahil may card at bayad na makikita.
 */
class CardReconcileController extends Controller
{
    public function __construct(private CardReconcile $reconcile) {}

    private function getNormalizedRole(): string
    {
        $raw  = Auth::user()?->employeeProfile?->role ?? '';
        $norm = preg_replace('/\s+/u', ' ', trim((string) $raw));
        if (preg_match('/^ceo$/iu', $norm)) return 'CEO';

        return $norm;
    }

    private function ceoOnly(): void
    {
        abort_unless($this->getNormalizedRole() === 'CEO', 404);
    }

    public function index()
    {
        $this->ceoOnly();
        $ready = Schema::hasTable('card_statements') && Schema::hasTable('card_statement_lines');

        return view('ads_manager.payment.reconcile.index', [
            'ready'      => $ready,
            'statements' => $ready ? CardStatement::orderByDesc('id')->limit(50)->get() : collect(),
            'cards'      => $this->reconcile->cards(),
            'banks'      => app(StatementParser::class)->bankKeys(),
            'suspicious' => $this->reconcile->suspicious(),
        ]);
    }

    public function store(Request $request)
    {
        $this->ceoOnly();
        abort_unless(Schema::hasTable('card_statements'), 404);
        $data = $request->validate([
            'statement'  => ['required', 'file', 'mimes:pdf,png,jpg,jpeg', 'max:10240'],
            'bank'       => ['required', Rule::in(array_merge(['auto'], app(StatementParser::class)->bankKeys()))],
            'card_label' => ['nullable', 'string', Rule::in($this->reconcile->cards())],
        ]);

        try {
            $s = $this->reconcile->upload($request->file('statement'), $data['bank'], $data['card_label'] ?? null, Auth::id());
        } catch (StatementReadException $e) {
            return back()->withErrors(['statement' => $e->getMessage()])->withInput($request->except('statement'));
        }

        return redirect()->route('ads_payment.reconcile.show', $s);
    }

    public function show(Request $request, CardStatement $statement)
    {
        $this->ceoOnly();
        $lines = $statement->lines()->get();
        $suggest = $statement->status === 'preview' ? $this->reconcile->suggest($statement) : null;
        $result = $statement->status === 'matched' ? $this->reconcile->result($statement) : null;

        return view('ads_manager.payment.reconcile.show', [
            's'       => $statement,
            'lines'   => $lines,
            'cards'   => $this->reconcile->cards(),
            'suggest' => $suggest,
            'result'  => $result,
            'status'  => (string) $request->query('status', ''),
        ]);
    }

    public function match(Request $request, CardStatement $statement)
    {
        $this->ceoOnly();
        // Ang mga edit ay dumarating bilang isang JSON field ("edits": {line id: {...}}), hindi isang input bawat
        // cell: ang mahabang statement ay lalampas sa max_input_vars ng PHP at tahimik na mapuputol.
        $request->validate(['edits' => ['nullable', 'string', 'json', 'max:1000000']]);
        $edits = json_decode((string) $request->input('edits', ''), true) ?? [];
        $data = validator(['card_label' => $request->input('card_label'), 'lines' => $edits], [
            'card_label'          => ['required', 'string', Rule::in($this->reconcile->cards())],
            'lines'               => ['array', 'max:2000'],
            'lines.*'             => ['array'],
            'lines.*.sale_date'   => ['nullable', 'date_format:Y-m-d'],
            'lines.*.post_date'   => ['nullable', 'date_format:Y-m-d'],
            'lines.*.amount'      => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'lines.*.is_credit'   => ['sometimes', 'boolean'],
            'lines.*.is_ads'      => ['sometimes', 'boolean'],
        ])->validate();

        // Mga edit sa preview: linya lang ng statement na ito ang ginagalaw.
        $own = $statement->lines()->pluck('id')->flip();
        foreach ($data['lines'] ?? [] as $id => $edit) {
            if (!isset($own[$id])) continue;
            DB::table('card_statement_lines')->where('id', $id)->where('card_statement_id', $statement->id)->update([
                'sale_date'  => $edit['sale_date'] ?? null,
                'post_date'  => $edit['post_date'] ?? null,
                'amount'     => number_format((float) $edit['amount'], 2, '.', ''),
                'is_credit'  => (bool) ($edit['is_credit'] ?? false),
                'is_ads'     => (bool) ($edit['is_ads'] ?? false),
                'updated_at' => now(),
            ]);
        }

        $this->reconcile->match($statement, $data['card_label']);

        return redirect()->route('ads_payment.reconcile.show', $statement)->with('status', 'Naitugma na.');
    }
}
