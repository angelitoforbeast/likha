<?php

namespace App\Http\Controllers;

use App\Models\PageSetting;
use App\Services\PageSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * /page-settings: settings kada page bilang rows (Page | Provider | Key | Value). CEO lang (404 sa iba).
 * Ang secret ay hindi kailanman ibinabalik: huling 4 na character lang ang nasa page.
 */
class PageSettingController extends Controller
{
    public function __construct(private PageSettings $settings)
    {
    }

    private function getNormalizedRole(): string
    {
        $raw = Auth::user()?->employeeProfile?->role ?? Auth::user()?->role ?? '';
        return preg_replace('/\s+/u', ' ', trim((string) $raw));
    }

    private function checkAccess(): void
    {
        if ($this->getNormalizedRole() !== 'CEO') abort(404);
    }

    public function index(Request $request)
    {
        $this->checkAccess();

        $filters = [
            'page'     => trim((string) $request->query('page', '')),
            'provider' => strtolower(trim((string) $request->query('provider', ''))),
            'key'      => strtolower(trim((string) $request->query('key', ''))),
        ];

        $q = PageSetting::query();
        if ($filters['page'] !== '') $q->whereRaw('LOWER(page_name) LIKE ?', ['%' . mb_strtolower($filters['page'], 'UTF-8') . '%']);
        if ($filters['provider'] !== '') $q->where('provider', $filters['provider']);
        if ($filters['key'] !== '') $q->where('key', 'like', '%' . $filters['key'] . '%');

        $rows = $q->orderBy('page_name')->orderBy('provider')->orderBy('key')->orderByDesc('id')->get();

        // Kada page: ang active rows, at ang history (pinalitan / naka-deactivate) kada provider + key.
        $pages = $rows->groupBy(fn ($r) => $this->settings->pageKey($r->page_name))->map(function ($g) {
            return [
                'name'    => $g->sortBy('id')->first()->page_name,
                'active'  => $g->where('active', true)->values(),
                'history' => $g->where('active', false)->groupBy(fn ($r) => $r->fullKey()),
            ];
        })->sortBy('name');

        return view('page_settings.index', [
            'pages'     => $pages,
            'filters'   => $filters,
            'providers' => PageSetting::distinct()->orderBy('provider')->pluck('provider'),
        ]);
    }

    /** Bago o kapalit: kapag may active na ang page + provider + key, ito ang pumapalit (ang luma = history). */
    public function store(Request $request)
    {
        $this->checkAccess();

        $page     = (string) $request->input('page_name', '');
        $provider = (string) $request->input('provider', '');
        $key      = (string) $request->input('key', '');
        $value    = (string) $request->input('value', '');

        $errors = $this->settings->validate($page, $provider, $key, $value);
        if ($errors) {
            return back()->withErrors($errors)->withInput($request->except('value'));
        }

        $res = $this->settings->set($page, $provider, $key, $value, $request->boolean('is_secret'), Auth::id());

        $label = ['created' => 'Naidagdag', 'replaced' => 'Napalitan', 'unchanged' => 'Walang binago (parehong value)'][$res['status']];
        return redirect()->route('page-settings.index')->with('success', $label . ': ' . $res['setting']->page_name . ' · ' . $res['setting']->fullKey());
    }

    public function deactivate(int $id)
    {
        $this->checkAccess();

        $setting = PageSetting::findOrFail($id);
        $this->settings->deactivate($setting, Auth::id());

        return redirect()->route('page-settings.index')->with('success', 'Na-deactivate: ' . $setting->page_name . ' · ' . $setting->fullKey());
    }
}
