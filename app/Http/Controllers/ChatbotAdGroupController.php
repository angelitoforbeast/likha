<?php

namespace App\Http\Controllers;

use App\Models\ChatbotFlow;
use App\Services\Chatbot\AdGroups;
use App\Services\Chatbot\ChatbotAiChain;
use App\Services\Chatbot\ChatbotAiProviders;
use App\Services\Chatbot\ChatbotAiSettings;
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
    private const MAX_VALUES = 300; // kada Save ng Edit ng group: higit pa sa lahat ng field ng page (pati 99 Seq at 99 Seq Img)

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
        return $r['ok'] ? response()->json(['ok' => true, 'id' => $r['id']] + (isset($r['note']) ? ['note' => $r['note']] : []))
            : response()->json(['error' => $r['error']] + (isset($r['group']) ? ['group' => $r['group']] : []), 422);
    }

    public function index(Request $request)
    {
        $this->checkAccess();
        $page = $this->page($request);
        $q = mb_substr(trim((string) $request->query('q', '')), 0, 100);
        // Bawat value ng page: ang flow na pinili, ang bawat column ng /chatbot/pages, at ang AI ng page (mapipili).
        $keys = ['flow_id' => ['key' => 'flow_id', 'label' => 'FLOW', 'kind' => 'flow']];
        foreach ($this->fields->labels() as $key => $label) {
            $key = (string) $key;
            if (AdGroups::isValueKey($key)) $keys[$key] = ['key' => $key, 'label' => $label, 'kind' => ChatbotFields::kind($key)];
        }
        $providers = array_map(fn ($p) => [$p, ChatbotAiProviders::label($p)], ChatbotAiProviders::names());
        foreach (AdGroups::AI_KEYS as $key) {
            $keys[$key] = ['key' => $key, 'label' => ChatbotAiSettings::LABELS[$key], 'kind' => 'ai'] + match ($key) {
                'ai_provider' => ['options' => $providers],
                'ai_fallback' => ['options' => [...$providers, [ChatbotAiChain::NONE, 'None']]],
                default => [],
            };
        }
        // Ang bawat ID (sa group at sa "Ads not in a group yet") ay may uri, pangalan, status, ilang customer, huling oras at
        // quiet; ang group ay may hidden. Ang pagtatago ay sa screen lang. Walang tawag sa Meta.
        $o = $this->groups->overview($page, $q);

        return response()->json([
            'mode' => $this->groups->adBased($page) ? AdGroups::MODE_AD : 'page',
            'as_of' => $o['as_of'],
            'groups' => array_values(array_map(fn ($g) => ['id' => $g['id'], 'name' => $g['name'], 'ads' => $g['rows'], 'all_off' => $g['all_off'], 'hidden' => $g['hidden'], 'values' => (object) $g['values']], $o['groups'])),
            'keys' => array_values($keys),
            'flows' => ChatbotFlow::orderBy('name')->get(['id', 'name'])->map(fn ($f) => ['id' => $f->id, 'name' => $f->name])->all(),
            'unassigned' => $o['unassigned'], 'unassigned_total' => $o['unassigned_total'], 'new_unassigned' => $o['new_unassigned'], 'hidden' => $o['hidden'],
        ]);
    }

    /** Bago o palit ng group: pangalan, ang mga ad ID (tina-type o pina-paste) at ang mga value (blangko = page value). */
    public function save(Request $request)
    {
        $this->checkAccess();
        $page = $this->page($request);
        if (!is_scalar($request->input('ads', '')) || !is_scalar($request->input('name', ''))) return response()->json(['error' => 'Name and IDs are text.'], 422);
        $parsed = AdGroups::parseAds(mb_substr((string) $request->input('ads', ''), 0, 40000));
        if ($parsed['bad']) return response()->json(['error' => AdGroups::notAnId($parsed['bad'])], 422);
        $values = [];
        foreach ((array) $request->input('values', []) as $key => $value) {
            if (!is_scalar($value) && $value !== null) return response()->json(['error' => 'Values are text.'], 422);
            $values[(string) $key] = (string) $value;
        }

        return $this->result($this->groups->save($page, $this->id($request), (string) $request->input('name', ''), $parsed['ids'], $values, Auth::id()));
    }

    /**
     * Ang mga tsinek mula sa "Ads not in a group yet" (listahan), o ang tina-type o pina-paste na mga ad ID (text: comma,
     * space o bagong linya): sa group na ito (id) o sa bagong group (name).
     */
    public function add(Request $request)
    {
        $this->checkAccess();
        $page = $this->page($request);
        $input = $request->input('ads', []);
        if (is_string($input)) {
            $parsed = AdGroups::parseAds(mb_substr($input, 0, 40000));
            if ($parsed['bad']) return response()->json(['error' => AdGroups::notAnId($parsed['bad'])], 422);
            $input = $parsed['ids'];
        }
        $ads = array_map(fn ($a) => is_scalar($a) ? (string) $a : '', array_slice((array) $input, 0, AdGroups::MAX_ADS));
        if (!$ads) return response()->json(['error' => is_string($request->input('ads')) ? 'Type at least one ID.' : 'Tick at least one ad.'], 422);
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

    /**
     * Isang value ng group (ang cell ng group sa row ng page), o ilang value nang sabay (values: key => value, ang Save ng
     * Edit ng group): blangko = ang default (page value). History gaya ng Save; lahat o wala.
     */
    public function value(Request $request)
    {
        $this->checkAccess();
        $page = $this->page($request);
        $id = $this->id($request);
        if ($request->has('values')) {
            $in = $request->input('values');
            if ($id === null || !is_array($in) || $in === [] || array_is_list($in)) return response()->json(['error' => 'Pick a group and the values to save.'], 422);
            if (count($in) > self::MAX_VALUES) return response()->json(['error' => 'At most ' . self::MAX_VALUES . ' values per save.'], 422);
            $values = [];
            foreach ($in as $key => $value) {
                if (!is_scalar($value) && $value !== null) return response()->json(['error' => 'Values are text.'], 422);
                $values[mb_substr((string) $key, 0, 100)] = (string) $value;
            }

            return $this->result($this->groups->setValues($page, $id, $values, Auth::id()));
        }
        [$key, $value] = [$request->input('key'), $request->input('value', '')];
        if ($id === null || !is_string($key) || (!is_scalar($value) && $value !== null)) return response()->json(['error' => 'Pick a group, a field and a text value.'], 422);

        return $this->result($this->groups->setValue($page, $id, mb_substr($key, 0, 100), (string) $value, Auth::id()));
    }

    /** Alisin ang isang ad ID sa group (history ng ad list). */
    public function removeAd(Request $request)
    {
        $this->checkAccess();
        $page = $this->page($request);
        $id = $this->id($request);
        $ad = $request->input('ad');
        if ($id === null || !is_scalar($ad) || !preg_match('/^\d{1,64}$/', (string) $ad)) return response()->json(['error' => 'Pick an ad ID of the group.'], 422);

        return $this->result($this->groups->removeAd($page, $id, (string) $ad, Auth::id()));
    }

    /**
     * Ang preview ng isang ad ng page: ang link ng video o post (para i-embed), ang headline at body, mula sa
     * ad_campaign_creatives gaya ng /owner/private. Ang ad lang na nasa group ng page o nakita sa page (404 ang iba).
     */
    public function ad(Request $request)
    {
        $this->checkAccess();
        $page = $this->page($request);
        $ad = $request->query('ad');
        if (!is_string($ad) || !preg_match('/^\d{1,64}$/', $ad)) return response()->json(['error' => 'Not an ad ID (digits only).'], 422);
        if (!$this->groups->hasAd($page, $ad)) abort(404);

        return response()->json($this->groups->creative($ad));
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
