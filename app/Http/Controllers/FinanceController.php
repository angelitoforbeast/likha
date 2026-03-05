<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\FinanceCategory;
use App\Models\FinanceTransaction;

class FinanceController extends Controller
{
    /* ─── helpers ─── */

    private function scope(Request $request): string
    {
        return str_contains(strtolower((string) $request->getHost()), 'incepxion')
            ? 'incepxion' : 'likha';
    }

    private function assertCEO(): void
    {
        $role = Auth::user()?->employeeProfile?->role ?? '';
        $norm = preg_replace('/\s+/u', ' ', trim($role));
        if (! preg_match('/^ceo$/iu', $norm)) {
            abort(403);
        }
    }

    /* ═══════════════════════════════════════════
       PAGE
    ═══════════════════════════════════════════ */

    public function index(Request $request)
    {
        $this->assertCEO();
        $scope = $this->scope($request);

        $categories = FinanceCategory::where('host_scope', $scope)
            ->orderBy('type')->orderBy('name')->get();

        return view('finance.index', compact('categories', 'scope'));
    }

    /* ═══════════════════════════════════════════
       TRANSACTIONS  (JSON API)
    ═══════════════════════════════════════════ */

    public function transactionsData(Request $request)
    {
        $this->assertCEO();
        $scope = $this->scope($request);

        $query = FinanceTransaction::with('category')
            ->where('host_scope', $scope);

        if ($request->filled('month') && $request->filled('year')) {
            $query->whereMonth('date', $request->month)
                  ->whereYear('date', $request->year);
        } elseif ($request->filled('year')) {
            $query->whereYear('date', $request->year);
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        $transactions = $query->orderByDesc('date')->orderByDesc('id')->get();

        return response()->json(['transactions' => $transactions]);
    }

    public function storeTransaction(Request $request)
    {
        $this->assertCEO();
        $scope = $this->scope($request);

        $data = $request->validate([
            'date'        => 'required|date',
            'type'        => 'required|in:income,expense',
            'category_id' => 'required|exists:finance_categories,id',
            'description' => 'required|string|max:255',
            'amount'      => 'required|numeric|min:0.01',
            'notes'       => 'nullable|string|max:1000',
        ]);

        $data['host_scope']  = $scope;
        $data['created_by']  = Auth::id();

        $txn = FinanceTransaction::create($data);
        $txn->load('category');

        return response()->json(['transaction' => $txn], 201);
    }

    public function updateTransaction(Request $request, FinanceTransaction $transaction)
    {
        $this->assertCEO();

        $data = $request->validate([
            'date'        => 'required|date',
            'type'        => 'required|in:income,expense',
            'category_id' => 'required|exists:finance_categories,id',
            'description' => 'required|string|max:255',
            'amount'      => 'required|numeric|min:0.01',
            'notes'       => 'nullable|string|max:1000',
        ]);

        $transaction->update($data);
        $transaction->load('category');

        return response()->json(['transaction' => $transaction]);
    }

    public function destroyTransaction(FinanceTransaction $transaction)
    {
        $this->assertCEO();
        $transaction->delete();
        return response()->json(['ok' => true]);
    }

    /* ═══════════════════════════════════════════
       DASHBOARD DATA  (JSON API)
    ═══════════════════════════════════════════ */

    public function dashboardData(Request $request)
    {
        $this->assertCEO();
        $scope = $this->scope($request);

        $query = FinanceTransaction::where('host_scope', $scope);

        // optional year filter
        $year = $request->input('year', date('Y'));
        $query->whereYear('date', $year);

        $all = $query->get();

        $totalIncome  = $all->where('type', 'income')->sum('amount');
        $totalExpense = $all->where('type', 'expense')->sum('amount');
        $netIncome    = $totalIncome - $totalExpense;
        $margin       = $totalIncome > 0 ? round(($netIncome / $totalIncome) * 100, 2) : 0;

        // Monthly breakdown for chart
        $monthly = [];
        for ($m = 1; $m <= 12; $m++) {
            $monthItems = $all->filter(fn($t) => (int) $t->date->format('m') === $m);
            $inc = $monthItems->where('type', 'income')->sum('amount');
            $exp = $monthItems->where('type', 'expense')->sum('amount');
            $monthly[] = [
                'month'   => $m,
                'label'   => date('M', mktime(0, 0, 0, $m, 1)),
                'income'  => round($inc, 2),
                'expense' => round($exp, 2),
                'net'     => round($inc - $exp, 2),
            ];
        }

        // Category breakdown (expense)
        $expByCategory = $all->where('type', 'expense')
            ->groupBy('category_id')
            ->map(function ($items) {
                $cat = $items->first()->category;
                return [
                    'category' => $cat ? $cat->name : 'Unknown',
                    'total'    => round($items->sum('amount'), 2),
                ];
            })->values();

        return response()->json([
            'total_income'  => round($totalIncome, 2),
            'total_expense' => round($totalExpense, 2),
            'net_income'    => round($netIncome, 2),
            'margin'        => $margin,
            'monthly'       => $monthly,
            'expense_by_category' => $expByCategory,
            'year'          => (int) $year,
        ]);
    }

    /* ═══════════════════════════════════════════
       CATEGORIES  (JSON API)
    ═══════════════════════════════════════════ */

    public function categoriesData(Request $request)
    {
        $this->assertCEO();
        $scope = $this->scope($request);

        $cats = FinanceCategory::where('host_scope', $scope)
            ->withCount('transactions')
            ->orderBy('type')->orderBy('is_system', 'desc')->orderBy('name')
            ->get();

        return response()->json(['categories' => $cats]);
    }

    public function storeCategory(Request $request)
    {
        $this->assertCEO();
        $scope = $this->scope($request);

        $data = $request->validate([
            'name' => 'required|string|max:100',
            'type' => 'required|in:income,expense',
        ]);

        $cat = FinanceCategory::create([
            'name'       => $data['name'],
            'type'       => $data['type'],
            'is_system'  => false,
            'host_scope' => $scope,
        ]);

        return response()->json(['category' => $cat], 201);
    }

    public function destroyCategory(FinanceCategory $category)
    {
        $this->assertCEO();

        if ($category->is_system) {
            return response()->json(['error' => 'Cannot delete system category.'], 422);
        }

        $count = $category->transactions()->count();
        if ($count > 0) {
            return response()->json([
                'error' => "Cannot delete: {$count} transaction(s) use this category. Reassign them first.",
            ], 422);
        }

        $category->delete();
        return response()->json(['ok' => true]);
    }
}
