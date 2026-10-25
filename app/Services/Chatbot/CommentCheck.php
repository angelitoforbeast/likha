<?php

namespace App\Services\Chatbot;

use App\Services\ChatbotFields;
use App\Services\PageSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ang Check ng mga comment column (Comment PM at Comment Reply 1-3), walang AI: ang format ng public reply (2-3 variant,
 * walang link o numero, maikli, walang ibang page), ang order form o tawag na mag-reply sa Comment PM, at ang comment
 * permission ng Forbeast token. Ang item at presyo ng Comment PM ay sa PageContentCheck (AI). Bawat finding ay kind
 * "comment"; ang level ay x, warn o info. Ang value ay pinupunan muna ng {{Botfield}} ng page, gaya ng padala.
 */
class CommentCheck
{
    public const MAX_REPLY = 300; // ang public reply ay maikli; ang detalye ay nasa PM
    /** Ang mga scope na kailangan ng Forbeast token para sumagot sa comment. */
    public const SCOPES = ['pages_manage_engagement', 'pages_read_user_content'];
    public const NO_PERMISSION = 'Forbeast: walang comment permission — i-connect ulit ang page';
    private const META_PERMISSION = [10, 200, 230]; // ang code ng Meta na permission error, sa "(Meta #N)" ng pm_error / reply_error
    private const SPAM = 'the same text on every comment can be flagged as spam';
    private const SHORT = 'keep the public reply short; the details go in the PM';
    // Ang domain na walang http/www: maliliit na titik at may 3+ letra bago ang tuldok, para ang "po.Online" (walang espasyo) ay hindi link.
    private const LINK = '~https?://|\bwww\.~i';
    private const DOMAIN = '~\b[a-z0-9-]{3,}\.(com|ph|net|org|shop|store|me|ly|link|site|online|co)\b~';
    private const PHONE = '/(?:\+?63|\b0)9\d{2}[\s-]?\d{3}[\s-]?\d{4}\b|(?<!\d)\d{7,}(?!\d)/';
    private const REPLY_CUE = '/(?<![\p{L}])(?:paki-?|pa-?|mag-?|i-?)?(reply|sagot|sumagot|sagutan|type|message|pm|send)(?![\p{L}])/iu';
    private const PM_TEMPLATE = "Hi po! 😊 {{%s}} — ₱{{%s}} lang po, FREE shipping at Cash on Delivery.\n\nPara po ma-reserve, i-fill up lang po:\n✅ Name:\n✅ Mobile Number:\n✅ Complete Address (House#/Purok/Street, Brgy, Municipality, Province):\n✅ Landmark:";
    private const REPLY_TEMPLATES = ['Salamat po! Nag-message po kami sa inyo 😊', 'Hi po! Check your inbox po, may message kami 😊', 'Thank you po! Na-send na po namin ang details sa inyo 😊'];

    public function __construct(private PageSettings $settings, private ChatbotFields $fields, private ChatbotPage $page)
    {
    }

    /**
     * Ang "Use template" ng Edit at ng cell editor: ang draft ng bawat comment column. Ang item at presyo ay {{Botfield}}
     * ng mga column ng page (ITEM NAME kung mayroon, kung wala ay ITEM NAME IN JNT DASHBOARD; PRICE), kaya ang padala ang
     * pumupuno. Walang isinusulat.
     * @return array<string, string>
     */
    public static function templates(): array
    {
        $labels = array_column(app(ChatbotFields::class)->columns(), 'label', 'key');
        $item = $labels['item_name'] ?? $labels['item_name_in_jnt_dashboard'] ?? 'ITEM NAME';

        return [CommentReplies::PM => sprintf(self::PM_TEMPLATE, $item, $labels['price'] ?? 'PRICE')]
            + array_combine(CommentReplies::VARIANTS, self::REPLY_TEMPLATES);
    }

    /** @return array<int, array{kind: string, field: string, level: string, reason: string}> */
    public function rules(string $page): array
    {
        $labels = ChatbotFields::defaults();
        $raw = [];
        foreach ([CommentReplies::PM, ...CommentReplies::VARIANTS] as $key) $raw[$key] = trim((string) $this->settings->get($page, ChatbotFields::PROVIDER, $key));
        if (implode('', $raw) === '') return [];

        $vars = ChatbotVars::merge($this->page->botfields($page));
        $value = array_map(fn ($v) => PageContentCheck::fill($v, $vars), $raw);
        $out = [];
        $finding = function (string $field, string $level, string $reason) use (&$out) {
            $out[] = ['kind' => 'comment', 'field' => $field, 'level' => $level, 'reason' => $reason];
        };
        $others = $this->otherPages($page);

        $pm = $value[CommentReplies::PM];
        if ($pm !== '') {
            $label = $labels[CommentReplies::PM];
            if (($other = self::named($pm, $others)) !== null) $finding($label, 'warn', $label . ': names another page (' . $other . ')');
            $form = !array_filter(PageContentCheck::FORM_FIELDS, fn ($re) => !preg_match($re, $pm));
            if (!$form && !preg_match(self::REPLY_CUE, $pm)) {
                $finding($label, 'warn', $label . ': no order form or call to reply: add Name, Mobile Number, Complete Address and Landmark, or ask the customer to reply for the form');
            }
        }

        $variants = array_filter(array_intersect_key($value, array_flip(CommentReplies::VARIANTS)), fn ($v) => $v !== '');
        if (count($variants) === 1) $finding('Comment Reply', 'warn', 'Comment Reply: only one variant: add 2–3 variants: ' . self::SPAM);
        $norm = fn (string $v) => mb_strtolower(preg_replace('/\s+/u', '', $v));
        // Isang babala kada grupo ng magkaparehong variant.
        $groups = [];
        foreach ($variants as $key => $v) $groups[$norm($v)][] = $labels[$key];
        foreach ($groups as $same) {
            if (count($same) < 2) continue;
            $last = array_pop($same);
            $finding($same[0], 'warn', implode(', ', $same) . ' and ' . $last . ' are the same: ' . self::SPAM);
        }
        foreach ($variants as $key => $v) {
            $label = $labels[$key];
            if (preg_match(self::LINK, $v) || preg_match(self::DOMAIN, $v)) $finding($label, 'warn', $label . ': has a link: ' . self::SHORT);
            if (preg_match(self::PHONE, $v)) $finding($label, 'warn', $label . ': has a phone number: ' . self::SHORT);
            if (($n = mb_strlen($v)) > self::MAX_REPLY) $finding($label, 'warn', $label . ': ' . $n . ' characters: ' . self::SHORT);
            if (($other = self::named($v, $others)) !== null) $finding($label, 'x', $label . ': names another page (' . $other . ')');
        }

        if (ChatbotProvider::name($page) !== ChatbotProvider::GATEWAY) {
            $finding('Comment', 'info', 'Comment columns: comments are answered only on Forbeast pages');
        } elseif ($this->noPermission($page)) {
            $finding('Forbeast', 'x', self::NO_PERMISSION);
        } elseif ($this->settings->get($page, ForbeastPageSetup::PROVIDER, 'permissions') === null) {
            $finding('Forbeast', 'info', 'Forbeast: permissions unknown');
        }

        return $out;
    }

    /**
     * Walang comment permission: ang scopes ng huling page check ng Forbeast ay kulang, o may comment na pumalya sa
     * permission error ng Meta sa huling 24 oras. Walang naitalang scopes = hindi alam (hindi X).
     */
    private function noPermission(string $page): bool
    {
        $scopes = json_decode((string) $this->settings->get($page, ForbeastPageSetup::PROVIDER, 'permissions'), true);
        if (is_array($scopes) && array_diff(self::SCOPES, $scopes)) return true;

        $pageId = trim((string) $this->settings->get($page, 'botcake', 'page_id'));
        if (!ctype_digit($pageId) || !Schema::hasTable('chatbot_comment_events')) return false;

        return DB::table('chatbot_comment_events')->where('page_id', (int) $pageId)->where('received_at', '>=', now()->subDay())
            ->where(function ($q) {
                foreach (['pm_error', 'reply_error'] as $col) {
                    foreach (self::META_PERMISSION as $code) $q->orWhere($col, 'like', '%(Meta #' . $code . ')%');
                }
            })->exists();
    }

    /** Ang ibang page ng chatbot (may Botcake ID), maliban sa page na ito at sa pangalang bahagi ng pangalan nito. */
    private function otherPages(string $page): array
    {
        $own = $this->settings->pageKey($page);

        return array_values(array_filter($this->settings->pagesWith('botcake', ['page_id']),
            fn ($p) => ($k = $this->settings->pageKey($p)) !== $own && mb_strlen($k) >= 3 && !str_contains($own, $k)));
    }

    /** Ang unang ibang page na binabanggit ng text (buong salita, kahit anong laki ng titik o dami ng espasyo), o null. */
    private static function named(string $text, array $pages): ?string
    {
        foreach ($pages as $p) {
            $words = array_map(fn ($w) => preg_quote($w, '/'), preg_split('/\s+/u', trim($p)));
            if (preg_match('/(?<![\p{L}\p{N}])' . implode('\s+', $words) . '(?![\p{L}\p{N}])/iu', $text)) return $p;
        }

        return null;
    }
}
