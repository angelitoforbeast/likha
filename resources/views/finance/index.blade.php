<!doctype html>
<html lang="en" x-data="financeApp()" x-init="init()" x-cloak>
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <meta name="csrf-token" content="{{ csrf_token() }}"/>
  <title>Finance &bull; {{ ucfirst($scope) }}</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script defer src="https://unpkg.com/alpinejs@3/dist/cdn.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
  <style>[x-cloak]{display:none!important}.fade-in{animation:fadeIn .3s ease}@keyframes fadeIn{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}</style>
</head>
<body class="bg-gray-50 min-h-screen">

{{-- ═══ TOP BAR ═══ --}}
<div class="bg-white border-b sticky top-0 z-40 px-4 py-3 flex items-center justify-between">
  <div class="flex items-center gap-3">
    <a href="/" class="text-gray-400 hover:text-gray-600"><i class="fa-solid fa-arrow-left"></i></a>
    <h1 class="text-lg md:text-xl font-bold text-gray-800"><i class="fa-solid fa-wallet mr-2 text-emerald-600"></i>Finance</h1>
    <span class="text-xs bg-emerald-100 text-emerald-700 px-2 py-0.5 rounded-full font-medium" x-text="year"></span>
  </div>
  <div class="flex items-center gap-2">
    <button @click="year--; loadAll()" class="p-1.5 rounded hover:bg-gray-100 text-gray-500"><i class="fa-solid fa-chevron-left text-xs"></i></button>
    <button @click="year++; loadAll()" class="p-1.5 rounded hover:bg-gray-100 text-gray-500"><i class="fa-solid fa-chevron-right text-xs"></i></button>
    <button @click="year = new Date().getFullYear(); loadAll()" class="text-xs px-2 py-1 rounded bg-gray-100 hover:bg-gray-200 text-gray-600">Today</button>
    <button @click="showCatModal = true; loadCategories()" class="text-xs px-3 py-1.5 rounded bg-gray-100 hover:bg-gray-200 text-gray-600 ml-2"><i class="fa-solid fa-tags mr-1"></i>Categories</button>
  </div>
</div>

<div class="max-w-7xl mx-auto px-4 py-6 space-y-6">

  {{-- ═══ DASHBOARD CARDS ═══ --}}
  <div class="grid grid-cols-2 md:grid-cols-5 gap-3 md:gap-4">
    <div class="bg-white rounded-xl shadow-sm border p-4">
      <div class="text-xs text-gray-500 mb-1">Total Revenue</div>
      <div class="text-lg md:text-2xl font-bold text-emerald-600" x-text="'₱' + fmt(dash.total_income)"></div>
    </div>
    <div class="bg-white rounded-xl shadow-sm border p-4">
      <div class="text-xs text-gray-500 mb-1">Total Expenses</div>
      <div class="text-lg md:text-2xl font-bold text-red-500" x-text="'₱' + fmt(dash.total_expense)"></div>
    </div>
    <div class="bg-white rounded-xl shadow-sm border p-4">
      <div class="text-xs text-gray-500 mb-1">Net Income</div>
      <div class="text-lg md:text-2xl font-bold" :class="dash.net_income >= 0 ? 'text-emerald-600' : 'text-red-500'" x-text="'₱' + fmt(dash.net_income)"></div>
    </div>
    <div class="bg-white rounded-xl shadow-sm border p-4">
      <div class="text-xs text-gray-500 mb-1">Profit Margin</div>
      <div class="text-lg md:text-2xl font-bold" :class="dash.margin >= 0 ? 'text-blue-600' : 'text-red-500'" x-text="dash.margin + '%'"></div>
    </div>
    <div class="bg-white rounded-xl shadow-sm border p-4 col-span-2 md:col-span-1">
      <div class="text-xs text-gray-500 mb-1">Transactions</div>
      <div class="text-lg md:text-2xl font-bold text-gray-700" x-text="transactions.length"></div>
    </div>
  </div>

  {{-- ═══ CHART ═══ --}}
  <div class="bg-white rounded-xl shadow-sm border p-4">
    <div class="flex items-center justify-between mb-3">
      <h2 class="text-sm font-semibold text-gray-700">Monthly Trend</h2>
      <div class="flex gap-3 text-xs">
        <span class="flex items-center gap-1"><span class="w-3 h-3 rounded bg-emerald-500 inline-block"></span>Revenue</span>
        <span class="flex items-center gap-1"><span class="w-3 h-3 rounded bg-red-400 inline-block"></span>Expenses</span>
        <span class="flex items-center gap-1"><span class="w-3 h-3 rounded bg-blue-500 inline-block"></span>Net</span>
      </div>
    </div>
    <div class="relative" style="height:260px">
      <canvas id="trendChart"></canvas>
    </div>
  </div>

  {{-- ═══ FILTERS + ADD BUTTON ═══ --}}
  <div class="flex flex-wrap items-center gap-2">
    <select x-model="filterMonth" @change="loadTransactions()" class="text-sm border rounded-lg px-3 py-2 bg-white">
      <option value="">All Months</option>
      <template x-for="m in months" :key="m.v">
        <option :value="m.v" x-text="m.l"></option>
      </template>
    </select>
    <select x-model="filterType" @change="loadTransactions()" class="text-sm border rounded-lg px-3 py-2 bg-white">
      <option value="">All Types</option>
      <option value="income">Income</option>
      <option value="expense">Expense</option>
    </select>
    <select x-model="filterCategory" @change="loadTransactions()" class="text-sm border rounded-lg px-3 py-2 bg-white">
      <option value="">All Categories</option>
      <template x-for="c in categories" :key="c.id">
        <option :value="c.id" x-text="c.name + ' (' + c.type + ')'"></option>
      </template>
    </select>
    <div class="flex-1"></div>
    <button @click="openAddModal()" class="bg-emerald-600 hover:bg-emerald-700 text-white text-sm px-4 py-2 rounded-lg shadow-sm transition">
      <i class="fa-solid fa-plus mr-1"></i>Add Transaction
    </button>
  </div>

  {{-- ═══ TRANSACTIONS TABLE (Desktop) ═══ --}}
  <div class="hidden md:block bg-white rounded-xl shadow-sm border overflow-hidden">
    <table class="w-full text-sm">
      <thead class="bg-gray-50 border-b">
        <tr>
          <th class="text-left px-4 py-3 font-medium text-gray-600">Date</th>
          <th class="text-left px-4 py-3 font-medium text-gray-600">Type</th>
          <th class="text-left px-4 py-3 font-medium text-gray-600">Category</th>
          <th class="text-left px-4 py-3 font-medium text-gray-600">Description</th>
          <th class="text-right px-4 py-3 font-medium text-gray-600">Amount</th>
          <th class="text-left px-4 py-3 font-medium text-gray-600">Notes</th>
          <th class="text-center px-4 py-3 font-medium text-gray-600 w-24">Actions</th>
        </tr>
      </thead>
      <tbody>
        <template x-if="transactions.length === 0">
          <tr><td colspan="7" class="text-center py-12 text-gray-400">No transactions found.</td></tr>
        </template>
        <template x-for="t in transactions" :key="t.id">
          <tr class="border-b hover:bg-gray-50 transition">
            <td class="px-4 py-3 whitespace-nowrap" x-text="t.date"></td>
            <td class="px-4 py-3">
              <span class="px-2 py-0.5 rounded-full text-xs font-medium"
                    :class="t.type === 'income' ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-700'"
                    x-text="t.type === 'income' ? 'Income' : 'Expense'"></span>
            </td>
            <td class="px-4 py-3" x-text="t.category ? t.category.name : '—'"></td>
            <td class="px-4 py-3" x-text="t.description"></td>
            <td class="px-4 py-3 text-right font-mono font-medium"
                :class="t.type === 'income' ? 'text-emerald-600' : 'text-red-500'"
                x-text="(t.type === 'income' ? '+' : '-') + '₱' + fmt(t.amount)"></td>
            <td class="px-4 py-3 text-gray-500 text-xs max-w-[200px] truncate" x-text="t.notes || '—'"></td>
            <td class="px-4 py-3 text-center">
              <button @click="openEditModal(t)" class="text-blue-500 hover:text-blue-700 mr-2"><i class="fa-solid fa-pen-to-square"></i></button>
              <button @click="confirmDelete(t)" class="text-red-400 hover:text-red-600"><i class="fa-solid fa-trash"></i></button>
            </td>
          </tr>
        </template>
      </tbody>
    </table>
  </div>

  {{-- ═══ TRANSACTIONS CARDS (Mobile) ═══ --}}
  <div class="md:hidden space-y-3">
    <template x-if="transactions.length === 0">
      <div class="text-center py-12 text-gray-400 bg-white rounded-xl border">No transactions found.</div>
    </template>
    <template x-for="t in transactions" :key="t.id">
      <div class="bg-white rounded-xl border p-4 fade-in">
        <div class="flex items-start justify-between mb-2">
          <div>
            <span class="px-2 py-0.5 rounded-full text-xs font-medium"
                  :class="t.type === 'income' ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-700'"
                  x-text="t.type === 'income' ? 'Income' : 'Expense'"></span>
            <span class="text-xs text-gray-400 ml-2" x-text="t.date"></span>
          </div>
          <div class="font-mono font-bold text-sm"
               :class="t.type === 'income' ? 'text-emerald-600' : 'text-red-500'"
               x-text="(t.type === 'income' ? '+' : '-') + '₱' + fmt(t.amount)"></div>
        </div>
        <div class="text-sm font-medium text-gray-800 mb-1" x-text="t.description"></div>
        <div class="text-xs text-gray-500 mb-2" x-text="t.category ? t.category.name : '—'"></div>
        <template x-if="t.notes">
          <div class="text-xs text-gray-400 mb-2" x-text="t.notes"></div>
        </template>
        <div class="flex gap-2 pt-2 border-t">
          <button @click="openEditModal(t)" class="text-xs text-blue-500 hover:text-blue-700"><i class="fa-solid fa-pen-to-square mr-1"></i>Edit</button>
          <button @click="confirmDelete(t)" class="text-xs text-red-400 hover:text-red-600"><i class="fa-solid fa-trash mr-1"></i>Delete</button>
        </div>
      </div>
    </template>
  </div>

  {{-- ═══ MONTHLY SUMMARY TABLE ═══ --}}
  <div class="bg-white rounded-xl shadow-sm border overflow-hidden">
    <div class="px-4 py-3 border-b bg-gray-50">
      <h2 class="text-sm font-semibold text-gray-700">Monthly Summary</h2>
    </div>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="border-b">
            <th class="text-left px-4 py-2 font-medium text-gray-600">Month</th>
            <th class="text-right px-4 py-2 font-medium text-gray-600">Revenue</th>
            <th class="text-right px-4 py-2 font-medium text-gray-600">Expenses</th>
            <th class="text-right px-4 py-2 font-medium text-gray-600">Net Income</th>
            <th class="text-right px-4 py-2 font-medium text-gray-600">Margin</th>
          </tr>
        </thead>
        <tbody>
          <template x-for="m in dash.monthly" :key="m.month">
            <template x-if="m.income > 0 || m.expense > 0">
              <tr class="border-b hover:bg-gray-50">
                <td class="px-4 py-2 font-medium" x-text="m.label + ' ' + year"></td>
                <td class="px-4 py-2 text-right text-emerald-600" x-text="'₱' + fmt(m.income)"></td>
                <td class="px-4 py-2 text-right text-red-500" x-text="'₱' + fmt(m.expense)"></td>
                <td class="px-4 py-2 text-right font-medium" :class="m.net >= 0 ? 'text-emerald-600' : 'text-red-500'" x-text="'₱' + fmt(m.net)"></td>
                <td class="px-4 py-2 text-right" :class="m.income > 0 && (m.net / m.income * 100) >= 0 ? 'text-blue-600' : 'text-red-500'"
                    x-text="m.income > 0 ? ((m.net / m.income * 100).toFixed(1) + '%') : '—'"></td>
              </tr>
            </template>
          </template>
        </tbody>
        <tfoot class="bg-gray-50 font-semibold">
          <tr>
            <td class="px-4 py-2">Total</td>
            <td class="px-4 py-2 text-right text-emerald-600" x-text="'₱' + fmt(dash.total_income)"></td>
            <td class="px-4 py-2 text-right text-red-500" x-text="'₱' + fmt(dash.total_expense)"></td>
            <td class="px-4 py-2 text-right" :class="dash.net_income >= 0 ? 'text-emerald-600' : 'text-red-500'" x-text="'₱' + fmt(dash.net_income)"></td>
            <td class="px-4 py-2 text-right" :class="dash.margin >= 0 ? 'text-blue-600' : 'text-red-500'" x-text="dash.margin + '%'"></td>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>

</div>

{{-- ═══ ADD / EDIT TRANSACTION MODAL ═══ --}}
<div x-show="showTxnModal" x-transition.opacity class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
  <div @click.away="showTxnModal = false" class="bg-white rounded-2xl shadow-xl w-full max-w-md max-h-[90vh] overflow-y-auto">
    <div class="px-6 py-4 border-b flex items-center justify-between">
      <h3 class="font-semibold text-gray-800" x-text="editingTxn ? 'Edit Transaction' : 'Add Transaction'"></h3>
      <button @click="showTxnModal = false" class="text-gray-400 hover:text-gray-600"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="px-6 py-4 space-y-4">
      {{-- Type --}}
      <div>
        <label class="block text-xs font-medium text-gray-600 mb-1">Type</label>
        <div class="flex gap-2">
          <button @click="txnForm.type = 'income'; filterCatsForForm()"
                  :class="txnForm.type === 'income' ? 'bg-emerald-600 text-white' : 'bg-gray-100 text-gray-600'"
                  class="flex-1 py-2 rounded-lg text-sm font-medium transition">Income</button>
          <button @click="txnForm.type = 'expense'; filterCatsForForm()"
                  :class="txnForm.type === 'expense' ? 'bg-red-500 text-white' : 'bg-gray-100 text-gray-600'"
                  class="flex-1 py-2 rounded-lg text-sm font-medium transition">Expense</button>
        </div>
      </div>
      {{-- Date --}}
      <div>
        <label class="block text-xs font-medium text-gray-600 mb-1">Date</label>
        <input type="date" x-model="txnForm.date" class="w-full border rounded-lg px-3 py-2 text-sm"/>
      </div>
      {{-- Category --}}
      <div>
        <label class="block text-xs font-medium text-gray-600 mb-1">Category</label>
        <select x-model="txnForm.category_id" class="w-full border rounded-lg px-3 py-2 text-sm">
          <option value="">Select category...</option>
          <template x-for="c in formCats" :key="c.id">
            <option :value="c.id" x-text="c.name + (c.is_system ? ' (System)' : '')"></option>
          </template>
        </select>
      </div>
      {{-- Description --}}
      <div>
        <label class="block text-xs font-medium text-gray-600 mb-1">Description</label>
        <input type="text" x-model="txnForm.description" placeholder="e.g. J&T Remittance March 1-5" class="w-full border rounded-lg px-3 py-2 text-sm"/>
      </div>
      {{-- Amount --}}
      <div>
        <label class="block text-xs font-medium text-gray-600 mb-1">Amount (₱)</label>
        <input type="number" x-model="txnForm.amount" step="0.01" min="0.01" placeholder="0.00" class="w-full border rounded-lg px-3 py-2 text-sm font-mono"/>
      </div>
      {{-- Notes --}}
      <div>
        <label class="block text-xs font-medium text-gray-600 mb-1">Notes (optional)</label>
        <textarea x-model="txnForm.notes" rows="2" placeholder="Any additional info..." class="w-full border rounded-lg px-3 py-2 text-sm"></textarea>
      </div>
      {{-- Error --}}
      <template x-if="txnError">
        <div class="text-red-500 text-xs bg-red-50 px-3 py-2 rounded-lg" x-text="txnError"></div>
      </template>
    </div>
    <div class="px-6 py-4 border-t flex justify-end gap-2">
      <button @click="showTxnModal = false" class="px-4 py-2 text-sm rounded-lg bg-gray-100 hover:bg-gray-200 text-gray-600">Cancel</button>
      <button @click="saveTxn()" :disabled="txnSaving"
              class="px-4 py-2 text-sm rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white font-medium disabled:opacity-50">
        <span x-show="!txnSaving" x-text="editingTxn ? 'Update' : 'Save'"></span>
        <span x-show="txnSaving"><i class="fa-solid fa-spinner fa-spin mr-1"></i>Saving...</span>
      </button>
    </div>
  </div>
</div>

{{-- ═══ DELETE CONFIRMATION MODAL ═══ --}}
<div x-show="showDeleteModal" x-transition.opacity class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
  <div @click.away="showDeleteModal = false" class="bg-white rounded-2xl shadow-xl w-full max-w-sm p-6 text-center">
    <div class="w-12 h-12 rounded-full bg-red-100 flex items-center justify-center mx-auto mb-4">
      <i class="fa-solid fa-trash text-red-500"></i>
    </div>
    <h3 class="font-semibold text-gray-800 mb-2">Delete Transaction?</h3>
    <p class="text-sm text-gray-500 mb-4" x-text="deletingTxn ? deletingTxn.description : ''"></p>
    <div class="flex gap-2 justify-center">
      <button @click="showDeleteModal = false" class="px-4 py-2 text-sm rounded-lg bg-gray-100 hover:bg-gray-200 text-gray-600">Cancel</button>
      <button @click="doDelete()" class="px-4 py-2 text-sm rounded-lg bg-red-500 hover:bg-red-600 text-white font-medium">Delete</button>
    </div>
  </div>
</div>

{{-- ═══ CATEGORY MANAGEMENT MODAL ═══ --}}
<div x-show="showCatModal" x-transition.opacity class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
  <div @click.away="showCatModal = false" class="bg-white rounded-2xl shadow-xl w-full max-w-lg max-h-[85vh] overflow-y-auto">
    <div class="px-6 py-4 border-b flex items-center justify-between sticky top-0 bg-white z-10">
      <h3 class="font-semibold text-gray-800"><i class="fa-solid fa-tags mr-2 text-gray-400"></i>Manage Categories</h3>
      <button @click="showCatModal = false" class="text-gray-400 hover:text-gray-600"><i class="fa-solid fa-xmark"></i></button>
    </div>
    {{-- Add new category --}}
    <div class="px-6 py-4 border-b bg-gray-50">
      <div class="flex gap-2">
        <select x-model="newCat.type" class="border rounded-lg px-3 py-2 text-sm">
          <option value="expense">Expense</option>
          <option value="income">Income</option>
        </select>
        <input type="text" x-model="newCat.name" placeholder="New category name..." class="flex-1 border rounded-lg px-3 py-2 text-sm"/>
        <button @click="addCategory()" class="bg-emerald-600 hover:bg-emerald-700 text-white text-sm px-4 py-2 rounded-lg"><i class="fa-solid fa-plus"></i></button>
      </div>
      <template x-if="catError">
        <div class="text-red-500 text-xs mt-2" x-text="catError"></div>
      </template>
    </div>
    {{-- Category list --}}
    <div class="px-6 py-4">
      <h4 class="text-xs font-semibold text-gray-500 uppercase mb-2">Income</h4>
      <template x-for="c in catList.filter(x => x.type === 'income')" :key="c.id">
        <div class="flex items-center justify-between py-2 border-b last:border-0">
          <div class="flex items-center gap-2">
            <span class="text-sm text-gray-800" x-text="c.name"></span>
            <template x-if="c.is_system">
              <span class="text-[10px] bg-blue-100 text-blue-600 px-1.5 py-0.5 rounded-full">System</span>
            </template>
            <template x-if="c.transactions_count > 0">
              <span class="text-[10px] text-gray-400" x-text="c.transactions_count + ' txns'"></span>
            </template>
          </div>
          <template x-if="!c.is_system">
            <button @click="deleteCategory(c)" class="text-red-400 hover:text-red-600 text-xs"><i class="fa-solid fa-trash"></i></button>
          </template>
        </div>
      </template>

      <h4 class="text-xs font-semibold text-gray-500 uppercase mb-2 mt-4">Expense</h4>
      <template x-for="c in catList.filter(x => x.type === 'expense')" :key="c.id">
        <div class="flex items-center justify-between py-2 border-b last:border-0">
          <div class="flex items-center gap-2">
            <span class="text-sm text-gray-800" x-text="c.name"></span>
            <template x-if="c.is_system">
              <span class="text-[10px] bg-blue-100 text-blue-600 px-1.5 py-0.5 rounded-full">System</span>
            </template>
            <template x-if="c.transactions_count > 0">
              <span class="text-[10px] text-gray-400" x-text="c.transactions_count + ' txns'"></span>
            </template>
          </div>
          <template x-if="!c.is_system">
            <button @click="deleteCategory(c)" class="text-red-400 hover:text-red-600 text-xs"><i class="fa-solid fa-trash"></i></button>
          </template>
        </div>
      </template>
    </div>
  </div>
</div>

<script>
function financeApp() {
  return {
    year: new Date().getFullYear(),
    categories: @json($categories),
    transactions: [],
    dash: { total_income: 0, total_expense: 0, net_income: 0, margin: 0, monthly: [] },
    chart: null,

    // filters
    filterMonth: '',
    filterType: '',
    filterCategory: '',
    months: [
      {v:'1',l:'Jan'},{v:'2',l:'Feb'},{v:'3',l:'Mar'},{v:'4',l:'Apr'},
      {v:'5',l:'May'},{v:'6',l:'Jun'},{v:'7',l:'Jul'},{v:'8',l:'Aug'},
      {v:'9',l:'Sep'},{v:'10',l:'Oct'},{v:'11',l:'Nov'},{v:'12',l:'Dec'}
    ],

    // transaction modal
    showTxnModal: false,
    editingTxn: null,
    txnForm: { date: '', type: 'expense', category_id: '', description: '', amount: '', notes: '' },
    formCats: [],
    txnError: '',
    txnSaving: false,

    // delete modal
    showDeleteModal: false,
    deletingTxn: null,

    // category modal
    showCatModal: false,
    catList: [],
    newCat: { name: '', type: 'expense' },
    catError: '',

    init() {
      this.loadAll();
    },

    async loadAll() {
      await Promise.all([this.loadDashboard(), this.loadTransactions()]);
    },

    async loadDashboard() {
      try {
        const r = await this.api('/finance/dashboard?year=' + this.year);
        this.dash = r;
        this.renderChart();
      } catch(e) { console.error(e); }
    },

    async loadTransactions() {
      try {
        let url = '/finance/transactions?year=' + this.year;
        if (this.filterMonth) url += '&month=' + this.filterMonth;
        if (this.filterType) url += '&type=' + this.filterType;
        if (this.filterCategory) url += '&category_id=' + this.filterCategory;
        const r = await this.api(url);
        this.transactions = r.transactions;
      } catch(e) { console.error(e); }
    },

    renderChart() {
      const ctx = document.getElementById('trendChart');
      if (!ctx) return;
      if (this.chart) this.chart.destroy();
      const m = this.dash.monthly || [];
      this.chart = new Chart(ctx, {
        type: 'bar',
        data: {
          labels: m.map(x => x.label),
          datasets: [
            { label: 'Revenue', data: m.map(x => x.income), backgroundColor: 'rgba(16,185,129,0.7)', borderRadius: 4 },
            { label: 'Expenses', data: m.map(x => x.expense), backgroundColor: 'rgba(248,113,113,0.7)', borderRadius: 4 },
            { label: 'Net', data: m.map(x => x.net), type: 'line', borderColor: '#3b82f6', backgroundColor: 'rgba(59,130,246,0.1)', tension: 0.3, fill: true, pointRadius: 3 }
          ]
        },
        options: {
          responsive: true, maintainAspectRatio: false,
          plugins: { legend: { display: false } },
          scales: {
            y: { beginAtZero: true, ticks: { callback: v => '₱' + (v >= 1000 ? (v/1000).toFixed(0) + 'k' : v) } }
          }
        }
      });
    },

    // ── Transaction CRUD ──
    openAddModal() {
      this.editingTxn = null;
      this.txnForm = { date: new Date().toISOString().slice(0,10), type: 'expense', category_id: '', description: '', amount: '', notes: '' };
      this.txnError = '';
      this.filterCatsForForm();
      this.showTxnModal = true;
    },

    openEditModal(t) {
      this.editingTxn = t;
      this.txnForm = {
        date: t.date,
        type: t.type,
        category_id: t.category_id,
        description: t.description,
        amount: t.amount,
        notes: t.notes || ''
      };
      this.txnError = '';
      this.filterCatsForForm();
      this.showTxnModal = true;
    },

    filterCatsForForm() {
      this.formCats = this.categories.filter(c => c.type === this.txnForm.type);
    },

    async saveTxn() {
      this.txnError = '';
      if (!this.txnForm.date || !this.txnForm.category_id || !this.txnForm.description || !this.txnForm.amount) {
        this.txnError = 'Please fill in all required fields.';
        return;
      }
      this.txnSaving = true;
      try {
        if (this.editingTxn) {
          await this.api('/finance/transactions/' + this.editingTxn.id, 'PUT', this.txnForm);
        } else {
          await this.api('/finance/transactions', 'POST', this.txnForm);
        }
        this.showTxnModal = false;
        await this.loadAll();
      } catch(e) {
        this.txnError = e.message || 'Failed to save.';
      }
      this.txnSaving = false;
    },

    confirmDelete(t) {
      this.deletingTxn = t;
      this.showDeleteModal = true;
    },

    async doDelete() {
      if (!this.deletingTxn) return;
      try {
        await this.api('/finance/transactions/' + this.deletingTxn.id, 'DELETE');
        this.showDeleteModal = false;
        this.deletingTxn = null;
        await this.loadAll();
      } catch(e) { alert('Delete failed: ' + e.message); }
    },

    // ── Category CRUD ──
    async loadCategories() {
      try {
        const r = await this.api('/finance/categories');
        this.catList = r.categories;
      } catch(e) { console.error(e); }
    },

    async addCategory() {
      this.catError = '';
      if (!this.newCat.name.trim()) { this.catError = 'Name is required.'; return; }
      try {
        const r = await this.api('/finance/categories', 'POST', this.newCat);
        this.newCat.name = '';
        this.categories.push(r.category);
        await this.loadCategories();
      } catch(e) { this.catError = e.message || 'Failed.'; }
    },

    async deleteCategory(c) {
      if (!confirm('Delete category "' + c.name + '"?')) return;
      try {
        await this.api('/finance/categories/' + c.id, 'DELETE');
        this.categories = this.categories.filter(x => x.id !== c.id);
        await this.loadCategories();
      } catch(e) { alert(e.message || 'Cannot delete.'); }
    },

    // ── Helpers ──
    fmt(n) {
      if (n === null || n === undefined) return '0.00';
      return Number(n).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    },

    async api(url, method = 'GET', body = null) {
      const opts = {
        method,
        headers: {
          'Accept': 'application/json',
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
        }
      };
      if (body) {
        opts.headers['Content-Type'] = 'application/json';
        opts.body = JSON.stringify(body);
      }
      const res = await fetch(url, opts);
      const json = await res.json();
      if (!res.ok) throw new Error(json.error || json.message || 'Request failed');
      return json;
    }
  };
}
</script>
</body>
</html>
