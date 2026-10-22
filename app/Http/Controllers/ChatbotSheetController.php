<?php

namespace App\Http\Controllers;

use App\Models\BotcakeChat;
use App\Models\ChatbotFlow;
use App\Services\Chatbot\ChatbotPage;
use App\Services\Chatbot\ChatbotSheet;
use App\Services\Chatbot\ChatbotVars;
use App\Services\Chatbot\CustomerFields;
use App\Services\Chatbot\FlowGraph;
use App\Services\Chatbot\FlowRun;
use App\Support\NavAccess;
use Google\Service\Exception as GoogleException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Ang Google Sheet step sa editor: ang pangalan, mga tab at header ng sheet (ID lang ang tinatanggap, hindi URL na
 * kinukuha), at ang preview ng row para sa isang totoong customer (walang isinusulat). Access gaya ng editor.
 */
class ChatbotSheetController extends Controller
{
    private const CUSTOMERS = 20;       // sa picker ng preview
    private const RECENT_CHATS = 500;   // ang binabasa para makuha ang 20 na customer

    public function __construct(private ChatbotSheet $sheet, private ChatbotPage $page)
    {
    }

    private function checkAccess(): void
    {
        $raw = Auth::user()?->employeeProfile?->role ?? Auth::user()?->role ?? '';
        if (!NavAccess::allows(preg_replace('/\s+/u', ' ', trim((string) $raw)), '/chatblast')) abort(403);
    }

    /** Ang pangalan at mga tab; kapag may tab, ang row 1 nito. 422 kapag hindi Google Sheet, o hindi naka-share. */
    public function info(Request $request)
    {
        $this->checkAccess();
        $id = self::spreadsheetId($request->input('sheet'));
        $tab = $request->input('tab');
        $tab = is_string($tab) ? trim($tab) : '';
        if ($id === null) return response()->json(['error' => 'Paste the link of a Google Sheet (docs.google.com/spreadsheets/…) or its ID.'], 422);
        if (mb_strlen($tab) > 100) return response()->json(['error' => 'The tab name is too long.'], 422);
        $refresh = $request->boolean('refresh');

        try {
            $info = $this->sheet->info($id, $refresh);
            $found = $tab === '' ? null : collect($info['tabs'])->firstWhere('title', $tab);
            $picked = $tab === '' ? null : ['title' => $tab, 'gid' => $found['gid'] ?? null, 'missing' => $found === null,
                'headers' => $found ? $this->sheet->headers($id, $tab, $refresh) : []];
        } catch (GoogleException $e) {
            if (in_array($e->getCode(), [403, 404], true)) {
                return response()->json(['share' => true, 'email' => ChatbotSheet::SHARE_EMAIL,
                    'error' => 'Share this sheet with ' . ChatbotSheet::SHARE_EMAIL . ' as Editor, then press Check again.'], 422);
            }
            report($e);
            return response()->json(['error' => 'Google did not answer. Try again in a minute.'], 502);
        } catch (Throwable $e) {
            report($e);
            return response()->json(['error' => 'Google did not answer. Try again in a minute.'], 502);
        }

        return response()->json(['id' => $id, 'title' => $info['title'], 'tabs' => $info['tabs'], 'tab' => $picked]);
    }

    /** Ang mga huling customer ng mga page na gumagamit ng flow (isa kada customer, pinakabago muna). */
    public function customers(int $id)
    {
        $this->checkAccess();
        $flow = ChatbotFlow::findOrFail($id);
        $ids = $this->pageIds($flow);
        $chats = $ids ? BotcakeChat::active()->whereIn('page_id', $ids)->orderByDesc('received_at')->orderByDesc('id')->limit(self::RECENT_CHATS)
            ->get(['id', 'page_id', 'page_name', 'psid', 'name', 'received_at'])->unique(fn ($c) => $c->page_id . ':' . $c->psid)->take(self::CUSTOMERS) : collect();

        return response()->json(['customers' => $chats->map(fn ($c) => ['chat_id' => $c->id,
            'label' => trim(($c->name ?: 'PSID ' . $c->psid) . ' · ' . $c->page_name . ' · ' . $c->received_at?->timezone('Asia/Manila')->format('M j H:i'))])->values()]);
    }

    /** Ang mga cell na isusulat para sa chat na ito, gaya ng takbo (ChatbotVars::sheetCell). Walang isinusulat. */
    public function preview(Request $request, int $id)
    {
        $this->checkAccess();
        $flow = ChatbotFlow::findOrFail($id);
        $chat = BotcakeChat::active()->whereKey((int) $request->input('chat_id'))->whereIn('page_id', $this->pageIds($flow))->first();
        if (!$chat) abort(404);

        $columns = FlowGraph::cleanConfig('sheet', ['columns' => $request->input('columns')])['columns'];
        $run = new FlowRun($flow->id, [], (string) $chat->page_name, (string) $chat->page_id, $this->page->botfields((string) $chat->page_name),
            new CustomerFields((int) $chat->page_id, (int) $chat->psid), $chat->id, (string) $chat->message, (string) $chat->name,
            $chat->received_at?->timezone('Asia/Manila')->format('Y-m-d H:i') ?? '');
        $vars = $run->vars();

        return response()->json(['cells' => array_map(fn ($t) => ChatbotVars::sheetCell((string) $t, $vars), $columns)]);
    }

    /** @return array<int, string> ang botcake page_id ng mga page na gumagamit ng flow */
    private function pageIds(ChatbotFlow $flow): array
    {
        $names = $this->page->pagesUsing($flow->id);

        return array_map('strval', array_keys(array_filter(BotcakeChat::pageMap(), fn ($name) => in_array($name, $names, true))));
    }

    /**
     * Ang ID mula sa link ng docs.google.com (may https:// man o wala) o ang ID mismo, ayon sa FlowGraph::spreadsheetId
     * (iisang patakaran sa takbo). Ang link sa ibang host ay tinatanggihan: ang ID lang ang ipinapasa sa Google API,
     * hindi kailanman ang URL.
     */
    private static function spreadsheetId(mixed $v): ?string
    {
        $v = is_string($v) ? trim($v) : '';
        if ($v === '' || mb_strlen($v) > 300) return null;
        if (str_contains($v, '/')) {
            $url = preg_match('#^[a-z][a-z0-9+.-]*://#i', $v) ? $v : 'https://' . $v;
            if (strtolower((string) parse_url($url, PHP_URL_HOST)) !== 'docs.google.com' || !in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) return null;
        }

        return FlowGraph::spreadsheetId($v);
    }
}
