<?php

namespace App\Http\Controllers;

use App\Services\Chatbot\ForbeastSite;
use App\Services\Chatbot\PageTransfers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/gateway/site/pages/{meta_page_id}/{adopt|export|import|release|activate}: ang Forbeast ang naglilipat ng page sa
 * pagitan ng dalawang partner site (PageTransfers). Pirma: X-Gateway-Signature = sha256=<HMAC-SHA256 ng
 * "<X-Gateway-Timestamp>.<raw body>"> (GET: walang laman ang body) gamit ang FORBEAST_SITE_SECRET, at ang timestamp ay
 * hanggang 300 s ang layo. Walang key o secret = 503 not_linked; anumang mali sa pirma = 401 bad_signature. Ang
 * X-Gateway-Transfer ay ang id ng paglipat (nasa history ng page). Lahat ng galing sa request ay untrusted: text lang.
 */
class GatewaySiteController extends Controller
{
    private const MAX_SKEW = 300;
    /** 500 customer field na may mahabang value ay kasya. */
    private const MAX_BODY = 16777216;

    public function __construct(private PageTransfers $transfers)
    {
    }

    public function adopt(Request $request, string $metaPageId): JsonResponse
    {
        if ($refused = $this->refused($request, $metaPageId, $raw)) return $refused;
        $data = json_decode($raw, true);
        $name = is_array($data) && is_string($data['name'] ?? null) ? ForbeastSite::squash($data['name']) : '';
        $token = is_array($data) && is_string($data['token'] ?? null) ? trim($data['token']) : '';
        $mode = is_array($data) ? ($data['mode'] ?? null) : null;
        $actor = is_array($data) && is_string($data['actor'] ?? null) && trim($data['actor']) !== '' ? mb_substr(ForbeastSite::squash($data['actor']), 0, 150) : null;
        $errors = array_filter([
            'name' => $name === '' || mb_strlen($name) > 255 ? 'The page name is required (at most 255 characters).' : null,
            'token' => $token === '' || strlen($token) > 500 ? 'The page token is required.' : null,
            'mode' => !in_array($mode, ['take', 'transfer'], true) ? 'The mode is take or transfer.' : null,
        ]);
        if ($errors) return self::json(PageTransfers::invalid($errors));

        return self::json($this->transfers->adopt($metaPageId, $name, $token, $mode, $this->transfer($request), $actor));
    }

    public function export(Request $request, string $metaPageId): JsonResponse
    {
        if ($refused = $this->refused($request, $metaPageId, $raw)) return $refused;
        $page = $this->transfers->page($metaPageId);
        if ($page === null) return self::json(PageTransfers::error(404, 'unknown_page', 'This page is not on this site.'));
        $part = (string) $request->query('part');
        $cursor = (string) $request->query('cursor', '');
        $since = $request->query('since');
        $local = $since === null ? null : PageTransfers::localTime($since);
        $errors = array_filter([
            'part' => !in_array($part, PageTransfers::PARTS, true) ? 'The part is values, fields or sequences.' : null,
            'cursor' => $cursor !== '' && !preg_match('/^\d{1,19}$/', $cursor) ? 'Use the "next" of the previous answer.' : null,
            'since' => $since !== null && $local === null ? 'Use an ISO 8601 time.' : null,
        ]);
        if ($errors) return self::json(PageTransfers::invalid($errors));

        return self::json($this->transfers->export($page, $metaPageId, $part, (int) $cursor, $local));
    }

    public function import(Request $request, string $metaPageId): JsonResponse
    {
        if ($refused = $this->refused($request, $metaPageId, $raw)) return $refused;
        $part = (string) $request->query('part');
        $data = json_decode($raw, true);
        $items = is_array($data) ? ($data['items'] ?? null) : null;
        if (!in_array($part, PageTransfers::PARTS, true)) return self::json(PageTransfers::invalid(['part' => 'The part is values, fields or sequences.']));
        if (!is_array($items) || !array_is_list($items) || count($items) > PageTransfers::MAX_ITEMS) {
            return self::json(PageTransfers::invalid(['items' => 'Send {"items": [...]} with at most ' . PageTransfers::MAX_ITEMS . ' items.']));
        }

        return self::json($this->transfers->import($metaPageId, $part, $items, $this->transfer($request)));
    }

    public function release(Request $request, string $metaPageId): JsonResponse
    {
        if ($refused = $this->refused($request, $metaPageId, $raw)) return $refused;
        $page = $this->transfers->page($metaPageId);
        if ($page === null) return self::json(PageTransfers::error(404, 'unknown_page', 'This page is not on this site.'));
        $data = json_decode($raw, true);
        $to = is_array($data) ? ($data['to'] ?? null) : null;
        if (!is_string($to) || !isset(ForbeastSite::SITES[$to])) return self::json(PageTransfers::invalid(['to' => 'The site is ' . implode(' or ', array_keys(ForbeastSite::SITES)) . '.']));

        return self::json($this->transfers->release($page, $metaPageId, $to));
    }

    public function activate(Request $request, string $metaPageId): JsonResponse
    {
        if ($refused = $this->refused($request, $metaPageId, $raw)) return $refused;

        return self::json($this->transfers->activate((int) $metaPageId));
    }

    /** Ang sagot kapag hindi tatanggapin ang request (hindi naka-link, malaki, maling page id o pirma), o null. */
    private function refused(Request $request, string $metaPageId, ?string &$raw): ?JsonResponse
    {
        $secret = ForbeastSite::secret();
        if ($secret === '') return self::json(PageTransfers::error(503, 'not_linked', 'This site is not linked to Forbeast.'));
        if ((int) $request->server('CONTENT_LENGTH') > self::MAX_BODY || strlen($raw = (string) $request->getContent()) > self::MAX_BODY) {
            return self::json(PageTransfers::error(413, 'too_large', 'The request body is too large.'));
        }
        $ts = (string) $request->header('X-Gateway-Timestamp');
        $given = strtolower((string) $request->header('X-Gateway-Signature'));
        if (str_starts_with($given, 'sha256=')) $given = substr($given, 7);
        $body = $request->isMethod('GET') ? '' : $raw;
        if ($given === '' || !preg_match('/^\d{1,12}$/', $ts) || abs(time() - (int) $ts) > self::MAX_SKEW
            || !hash_equals(hash_hmac('sha256', $ts . '.' . $body, $secret), $given)) {
            return self::json(PageTransfers::error(401, 'bad_signature', 'The signature does not match.'));
        }
        if (!preg_match('/^\d{1,20}$/', $metaPageId)) return self::json(PageTransfers::error(404, 'unknown_page', 'This page is not on this site.'));

        return null;
    }

    /** Ang id ng paglipat (X-Gateway-Transfer), para sa history; "?" kapag wala o kakaiba. */
    private function transfer(Request $request): string
    {
        $id = trim((string) $request->header('X-Gateway-Transfer'));

        return preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id) ? $id : '?';
    }

    /** @param array{http: int, body: array} $res */
    private static function json(array $res): JsonResponse
    {
        return response()->json($res['body'], $res['http']);
    }
}
