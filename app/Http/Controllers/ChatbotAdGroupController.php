<?php

namespace App\Http\Controllers;

use App\Models\ChatbotFlow;
use App\Services\Chatbot\AdGroups;
use App\Services\ChatbotFields;
use App\Services\PageSettings;
use App\Support\NavAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Ang Ad groups ng isang page sa /chatbot/pages (sa ilalim ng mga value sa Edit ng page): ang mga group, ang "Ads not
 * in a group yet", at ang save, add, delete, history at restore. Parehong access ng pag-edit ng page values
 * (NavAccess /chatblast) at parehong throttle ng mga sulat ng page values.
 */
class ChatbotAdGroupController extends Controller
{
    public function __construct(private AdGroups $groups, private PageSettings $settings, private ChatbotFields $fields)
    {
    }

    private function checkAccess(): void
    {
        $raw = Auth::user()?->employeeProfile?->role ?? Auth::user()?->role ?? '';
        if (!NavAccess::allows(preg_replace('/\s+/u', ' ', trim((string) $raw)), '/chatblast')) abort(403);
    }

    /** Ang page na may settings (hindi lang group): ang ibang pangalan ay 404, walang naisusulat. */
    private function page(Request $request): string
    {
        $page = $this->settings->normPage(mb_substr((string) $request->input('page', ''), 0, 255));
        $exists = $page !== '' && $this->settings->pageQuery($page)->where('active', true)->where('provider', '!=', AdGroups::PROVIDER)->exists();
        if (!$exists) abort(404);

        return $page;
    }

    private function id(Request $request): ?int
    {
        $id = $request->input('id');

        return is_scalar($id) && ctype_digit((string) $id) ? (int) $id : null;
    }

    private function result(array $r)
    {
        return $r['ok'] ? response()->json(['ok' => true, 'id' => $r['id']]) : response()->json(['error' => $r['error']], 422);
    }

    public function index(Request $request)
    {
        $this->checkAccess();
        $page = $this->page($request);
        $q = mb_substr(trim((string) $request->query('q', '')), 0, 100);
        $labels = $this->fields->labels();
        $keys = [];
        foreach ([...AdGroups::VALUE_KEYS, ...array_keys($labels)] as $key) {
            if (AdGroups::isValueKey($key) && !isset($keys[$key])) {
                $keys[$key] = ['key' => $key, 'label' => $key === 'flow_id' ? 'FLOW' : ($labels[$key] ?? $key), 'kind' => $key === 'flow_id' ? 'flow' : ChatbotFields::kind($key)];
            }
        }
        $unassigned = $this->groups->unassigned($page, $q);

        return response()->json([
            'mode' => $this->groups->adBased($page) ? AdGroups::MODE_AD : 'page',
            'groups' => array_values(array_map(fn ($g) => ['id' => $g['id'], 'name' => $g['name'], 'ads' => $g['ads'], 'values' => (object) $g['values']], $this->groups->groups($page))),
            'keys' => array_values($keys),
            'flows' => ChatbotFlow::orderBy('name')->get(['id', 'name'])->map(fn ($f) => ['id' => $f->id, 'name' => $f->name])->all(),
            'unassigned' => $unassigned['rows'], 'unassigned_total' => $unassigned['total'],
        ]);
    }

    /** Bago o palit ng group: pangalan, ang mga ad ID (tina-type o pina-paste) at ang mga value (blangko = page value). */
    public function save(Request $request)
    {
        $this->checkAccess();
        $page = $this->page($request);
        if (!is_scalar($request->input('ads', '')) || !is_scalar($request->input('name', ''))) return response()->json(['error' => 'Name and ad IDs are text.'], 422);
        $parsed = AdGroups::parseAds(mb_substr((string) $request->input('ads', ''), 0, 40000));
        if ($parsed['bad']) return response()->json(['error' => 'Not an ad ID (digits only): ' . implode(', ', array_slice($parsed['bad'], 0, 5)) . '.'], 422);
        $values = [];
        foreach ((array) $request->input('values', []) as $key => $value) {
            if (!is_scalar($value) && $value !== null) return response()->json(['error' => 'Values are text.'], 422);
            $values[(string) $key] = (string) $value;
        }

        return $this->result($this->groups->save($page, $this->id($request), (string) $request->input('name', ''), $parsed['ids'], $values, Auth::id()));
    }

    /** Ang mga tsinek mula sa "Ads not in a group yet": sa group na ito (id) o sa bagong group (name). */
    public function add(Request $request)
    {
        $this->checkAccess();
        $page = $this->page($request);
        $ads = array_map(fn ($a) => is_scalar($a) ? (string) $a : '', array_slice((array) $request->input('ads', []), 0, AdGroups::MAX_ADS));
        if (!$ads) return response()->json(['error' => 'Tick at least one ad.'], 422);
        $id = $this->id($request);

        return $this->result($this->groups->addAds($page, $id, $id === null ? (is_scalar($n = $request->input('name', '')) ? (string) $n : '') : null, $ads, Auth::id()));
    }

    public function delete(Request $request)
    {
        $this->checkAccess();
        $page = $this->page($request);
        $id = $this->id($request);
        if ($id === null || !$this->groups->delete($page, $id, Auth::id())) return response()->json(['error' => 'This group was already deleted.'], 422);

        return response()->json(['ok' => true]);
    }

    public function history(Request $request)
    {
        $this->checkAccess();
        $page = $this->page($request);
        $id = $this->id($request);

        return response()->json(['rows' => $id === null ? [] : $this->groups->history($page, $id)]);
    }

    /** Ang Reply values ng page: "page" (Page-based, ang default) o "ad" (Ad-based: ang mga group ang ginagamit). */
    public function mode(Request $request)
    {
        $this->checkAccess();
        $page = $this->page($request);
        $mode = $request->input('mode');
        if (!in_array($mode, ['page', AdGroups::MODE_AD], true)) return response()->json(['error' => 'Pick Page-based or Ad-based.'], 422);
        $this->groups->setMode($page, $mode === AdGroups::MODE_AD, Auth::id());

        return response()->json(['ok' => true, 'mode' => $this->groups->adBased($page) ? AdGroups::MODE_AD : 'page']);
    }

    public function restore(Request $request)
    {
        $this->checkAccess();
        $page = $this->page($request);
        $setting = $request->input('setting');
        if (!is_scalar($setting) || !ctype_digit((string) $setting)) return response()->json(['error' => 'Pick a version.'], 422);

        return $this->result($this->groups->restore($page, (int) $setting, Auth::id()));
    }
}
