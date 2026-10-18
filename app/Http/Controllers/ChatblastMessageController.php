<?php

namespace App\Http\Controllers;

use App\Models\BotcakeMessage;
use App\Models\ChatblastCampaign;
use App\Models\ChatblastMessage;
use App\Services\Chatblast;
use App\Services\PageSettings;
use App\Support\NavAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * /chatblast/campaigns, "Mga mensahe" (gaya ng mga flow sa Botcake): bawat mensahe ay may pangalan at mga bersyon (A,
 * B, …) na ine-edit sa lugar, kung saan ito ginagamit, at ilan ang naipadala. Ang sequence (/chatblast/sequence) ang
 * pumipili ng mensahe ng bawat step. Access gaya ng /chatblast (NavAccess; CEO laging pasok, 404 sa iba).
 */
class ChatblastMessageController extends Controller
{
    private const MAX_NAME = 140;
    private const STALE = 'Nabago na ang bersyong ito (ibang tab o tao); i-reload ang page.';

    public function __construct(private Chatblast $chatblast, private PageSettings $settings)
    {
    }

    private function getNormalizedRole(): string
    {
        $raw = Auth::user()?->employeeProfile?->role ?? Auth::user()?->role ?? '';
        return preg_replace('/\s+/u', ' ', trim((string) $raw));
    }

    private function checkAccess(): void
    {
        if (!NavAccess::allows($this->getNormalizedRole(), '/chatblast')) abort(404);
    }

    public function index()
    {
        $this->checkAccess();

        return view('chatblast.messages', ['placeholders' => Chatblast::PLACEHOLDERS]);
    }

    /** Lahat ng mensahe: mga bersyon, kung saan ginagamit (sequence at step), at ilan ang naipadala kada bersyon. */
    public function data()
    {
        $this->checkAccess();

        $campaigns = ChatblastCampaign::orderBy('id')->get(['id', 'kind', 'name', 'step_messages', 'active']);
        $used = [];
        foreach ($campaigns as $c) {
            foreach ((array) $c->step_messages as $i => $id) $used[(int) $id][] = ['campaign_id' => $c->id, 'name' => $c->name, 'kind' => $c->kind, 'day' => $i + 1];
        }
        // Ang naipadala ng bawat (sequence, step, bersyon) ay bilang ng mensaheng nasa step na iyon ngayon.
        $sent = [];
        BotcakeMessage::where('is_test', false)->where('status', 'sent')->selectRaw('kind, day, version, COUNT(*) AS n')->groupBy('kind', 'day', 'version')->get()
            ->each(function ($r) use (&$sent) { $sent[$r->kind . '|' . $r->day][(string) $r->version] = (int) $r->n; });

        $messages = ChatblastMessage::orderBy('name')->get(['id', 'name', 'versions'])->map(function ($m) use ($used, $sent) {
            $counts = [];
            foreach ($used[$m->id] ?? [] as $u) {
                foreach ($sent[$u['kind'] . '|' . $u['day']] ?? [] as $v => $n) $counts[$v] = ($counts[$v] ?? 0) + $n;
            }
            return ['id' => $m->id, 'name' => $m->name, 'versions' => $m->versions,
                'used' => array_map(fn ($u) => array_diff_key($u, ['kind' => 1]), $used[$m->id] ?? []), 'sent' => $counts, 'sent_total' => array_sum($counts)];
        })->values()->all();

        return response()->json([
            'messages'  => $messages,
            'campaigns' => $campaigns->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->all(),
            'pages'     => $this->settings->pagesWith('botcake', Chatblast::REQUIRED),
        ]);
    }

    /** Bagong mensahe na may isang bersyon. */
    public function store(Request $request)
    {
        $this->checkAccess();
        $name = $this->name($request->input('name'));
        if ($name === null) return response()->json(['error' => 'Pangalan: 1 hanggang ' . self::MAX_NAME . ' na character.'], 422);
        if (ChatblastMessage::where('name', $name)->exists()) return response()->json(['error' => 'May mensahe nang ganitong pangalan.'], 422);
        if (($error = $this->chatblast->invalid([[$request->input('text')]])) !== null) return response()->json(['error' => str_replace('Araw 1', 'Mensahe', $error)], 422);

        $m = new ChatblastMessage(['name' => $name, 'versions' => [trim((string) $request->input('text'))]]);
        Chatblast::log($m, Auth::id(), ['what' => 'create']);
        $m->save();

        return response()->json(['message' => ['id' => $m->id, 'name' => $m->name, 'versions' => $m->versions]], 201);
    }

    /** Palitan ang pangalan (hindi nagbabago ang mga sequence: ayon sa id ang step). */
    public function rename(Request $request, int $id)
    {
        $this->checkAccess();
        $m = ChatblastMessage::findOrFail($id);
        $name = $this->name($request->input('name'));
        if ($name === null) return response()->json(['error' => 'Pangalan: 1 hanggang ' . self::MAX_NAME . ' na character.'], 422);
        if (ChatblastMessage::where('name', $name)->whereKeyNot($id)->exists()) return response()->json(['error' => 'May mensahe nang ganitong pangalan.'], 422);

        Chatblast::log($m, Auth::id(), ['what' => 'rename', 'old' => $m->name, 'new' => $name]);
        $m->name = $name;
        $m->save();

        return response()->json(['name' => $m->name]);
    }

    /** Kopya ng mensahe ("<pangalan> (kopya)"), para baguhin nang hindi ginagalaw ang ginagamit. */
    public function duplicate(int $id)
    {
        $this->checkAccess();
        $src = ChatblastMessage::findOrFail($id);
        $m = new ChatblastMessage(['name' => $this->chatblast->freeName($src->name . ' (kopya)'), 'versions' => $src->versions]);
        Chatblast::log($m, Auth::id(), ['what' => 'duplicate', 'of' => $src->id]);
        $m->save();

        return response()->json(['message' => ['id' => $m->id, 'name' => $m->name, 'versions' => $m->versions]], 201);
    }

    /** Alisin ang mensahe na hindi ginagamit ng anumang sequence. */
    public function destroy(int $id)
    {
        $this->checkAccess();

        return DB::transaction(function () use ($id) {
            $m = ChatblastMessage::whereKey($id)->lockForUpdate()->firstOrFail();
            $users = $this->chatblast->usersOf([$m->id]);
            if ($users->isNotEmpty()) {
                return response()->json(['error' => 'Ginagamit pa ng ' . $users->pluck('name')->implode(', ') . '; palitan muna ang step.'], 422);
            }
            $m->delete();

            return response()->json(['deleted' => true]);
        });
    }

    /** Ang text ng isang bersyon (old: ang nakita ng user; kapag iba na, hindi pinapalitan). */
    public function text(Request $request, int $id)
    {
        $this->checkAccess();
        $v = $this->version($request);
        $expect = $this->expected($request);
        $text = $request->input('text');
        if (!is_string($text)) return response()->json(['error' => 'Walang text.'], 422);

        return $this->edit($id, function (array $versions) use ($v, $text, $expect, &$old) {
            if (!isset($versions[$v])) throw new InvalidArgumentException('Walang ganitong bersyon.');
            if ($expect !== null && $versions[$v] !== $expect) throw new InvalidArgumentException(self::STALE);
            $old = $versions[$v];
            $versions[$v] = $text;
            return $versions;
        }, ['what' => 'text', 'version' => Chatblast::label(max(0, $v)), 'old' => &$old, 'new' => trim($text)]);
    }

    /** Bagong bersyon: kopya ng A. */
    public function addVersion(int $id)
    {
        $this->checkAccess();

        return $this->edit($id, function (array $versions) {
            $versions[] = $versions[0];
            return $versions;
        }, ['what' => 'add_version']);
    }

    /** Alisin ang isang bersyon (kahit isa ang natitira). */
    public function removeVersion(Request $request, int $id)
    {
        $this->checkAccess();
        $v = $this->version($request);
        $expect = $this->expected($request);

        return $this->edit($id, function (array $versions) use ($v, $expect, &$old) {
            if (!isset($versions[$v])) throw new InvalidArgumentException('Walang ganitong bersyon.');
            if ($expect !== null && $versions[$v] !== $expect) throw new InvalidArgumentException(self::STALE);
            $old = $versions[$v];
            array_splice($versions, $v, 1);
            return $versions;
        }, ['what' => 'remove_version', 'version' => Chatblast::label(max(0, $v)), 'old' => &$old]);
    }

    private function edit(int $id, callable $change, array $entry)
    {
        ChatblastMessage::findOrFail($id);
        try {
            $changed = $this->chatblast->editMessage($id, $change, Auth::id(), $entry);
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['changed' => $changed, 'versions' => ChatblastMessage::findOrFail($id)->versions]);
    }

    private function name(mixed $name): ?string
    {
        return is_string($name) && trim($name) !== '' && mb_strlen(trim($name)) <= self::MAX_NAME ? trim($name) : null;
    }

    private function version(Request $request): int
    {
        $v = $request->input('version');
        return is_string($v) && preg_match('/^[A-Z]$/', $v) ? ord($v) - 65 : -1;
    }

    private function expected(Request $request): ?string
    {
        $old = $request->input('old');
        return is_string($old) ? $old : null;
    }
}
