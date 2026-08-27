<?php

namespace App\Http\Controllers;

use App\Models\PromptGeneration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * /prompt-generator — Chatbot Sales Prompt Generator (V2).
 *
 *  - Ang prompt ay DETERMINISTIC (naka-lock na master template + conditional
 *    pricing/shipping) na binubuo CLIENT-SIDE (live habang nagta-type).
 *  - Ang AI ay para LANG sa IMAGE → FORM auto-fill (server-side, gpt-4o vision,
 *    gamit ang existing OpenAI key natin — walang API key na ilalagay ang user).
 *  - Ang bawat na-generate na prompt ay naise-save sa history.
 *
 * Access: CEO, Marketing, Marketing - OIC.
 */
class PromptGeneratorController extends Controller
{
    /** Model para sa lahat ng AI calls (vision + text). */
    public const VISION_MODEL = 'gpt-4o';
    public const TEXT_MODEL   = 'gpt-4o';

    /** Main Flow (opening auto-reply) instruction. {language} replaced at runtime. */
    public const MAINFLOW_PROMPT = <<<'MF'
Write ONE warm, complete, high-converting OPENING message that the sales bot sends as its
FIRST reply to any customer who messages. This is the "main flow" opening. Follow this flow:

1. GREETING first — a friendly, warm rapport line that ADDRESSES the customer inline using the
   exact marker [[SALUTATION]] woven naturally into the sentence (e.g. "Hi po [[SALUTATION]]! 😊
   Salamat sa pag-message!"). Include [[SALUTATION]] exactly ONCE, inside the greeting — never on
   its own separate line, and never alter, translate, or remove it.
2. A short attention hook about the offer (e.g. "LIMITED TIME OFFER na po ito! 🔥").
3. PRICING + OFFER (ONE line only) — first show the OLD/regular price with a REAL crossed-out line
   using unicode combining strikethrough characters so it literally looks like this: ₱̶3̶6̶0̶ (each
   digit has a line through it). Then, on the NEXT line, show the promo price AND the promo/offer
   deal TOGETHER on ONE single line, emphasized in CAPS with emojis
   (e.g. "👉 ₱240 NA LANG — BUY 1 TAKE 1! 🎉"). Mention the price ONCE only — do NOT repeat the
   price on a separate line, and do NOT restate the word "PROMO". If no distinct offer/deal is given,
   just show the promo price alone on that line. If no old price is given, invent a believable higher
   one (about 1.5x the promo price).
4. One punchy hook line about the biggest benefit.
5. A NUMBERED list of key benefits with emojis (1️⃣, 2️⃣, 3️⃣ ...), each on its own line,
   benefit-driven (ALL CAPS keywords are okay).
6. Close with a strong but warm call to action (e.g. "Gusto niyo po bang mag-order? Reply lang po! 😊").

FORMATTING RULES (very important):
- This is pasted into Facebook Messenger, which does NOT render markdown. NEVER use **asterisks**,
  ~tildes~, backticks, or markdown of any kind. For emphasis use CAPS, emojis, and REAL unicode
  strikethrough (combining long stroke overlay) for the old price — never tildes.
- Use real line breaks. Make it engaging and complete — do NOT make it too short.
- Write in {language}. Output plain text only (no JSON).
MF;

    /** Follow-up SEQUENCE instruction. {count} + {language} replaced at runtime. */
    public const SEQUENCE_PROMPT = <<<'SEQ'
You are a top Filipino direct-response copywriter writing a Facebook Messenger FOLLOW-UP SEQUENCE
(BotCake broadcast). Write {count} messages sent one at a time over the next hours/days to re-engage
a customer who messaged but has NOT yet completed their order, pushing them to finally buy.

LANGUAGE: {language} (Taglish by default) — punchy, kabog, conversational, parang totoong seller na
hinahabol ang warm lead. Do NOT output pure English unless {language} is exactly "English".

VARY THE PERSUASION ANGLE — every message must use a DIFFERENT framework. Rotate across these
(don't reuse the same one twice, don't go in a predictable order):
- SCARCITY / urgency (limited stock, last slots, "hanggang ngayon lang", last day)
- CURIOSITY (open loop, teaser, "may sorpresa ako sayo", tanong na hindi agad sinasagot)
- PROBLEM–AGITATE–SOLVE (kilalanin ang sakit/pain point, palakihin, saka i-offer ang solusyon)
- SOCIAL PROOF (dami nang umorder, ubos-ubos, "grabe ang orders today")
- FOMO / gentle guilt ("sayang naman", "iba na kukuha ng slot mo")
- EXCLUSIVITY ("ikaw ang napili", priority, VIP list)
- VALUE / price-anchor ("sa mall x3 presyo", "parang walang tubo", free shipping)
- REASSURANCE / objection-handling (COD, legit, easy return — para mawala ang duda)

VARY THE LENGTH — huwag pare-pareho: iba SHORT (1 punchy line + order call), iba MEDIUM (hook + 2-3 lines),
iba LONGER (hook + 3-5 lines na mini-story/benefits/price-anchor/objection-handling + warm order call).

CALL TO ACTION: end most messages with a natural order/reply prompt in {language}. Vary the wording.

FORMATTING:
- Messenger does NOT render markdown. Never use **asterisks**, ~tildes~, or backticks. Use CAPS/emojis.
- Use real line breaks. No fake medical/financial claims.

PLACEHOLDER — insert this LITERALLY, do NOT replace, translate, or invent a value for it:
- {{user_first_name}}  = the customer's first name (sprinkle naturally, not in every single message)
This is the ONLY {{...}} token allowed — do NOT output any other {{...}} placeholder.

PRICING: Use the ACTUAL price and offer details given in the context below. When a message mentions
price, use the REAL promo price and, when helpful, the bundle deal (e.g. buy-more savings), woven in
naturally with VARIED wording. Follow the PRICE FREQUENCY instruction in the context for HOW OFTEN to
mention price. Do NOT invent a different price, discount, deal, or savings that is not in the provided
pricing/offer info. If no price is provided, avoid stating a specific number.

Return ONLY the messages as an array of strings (one full message per array item).
Do NOT number them and do NOT add any commentary.
SEQ;

    private function getNormalizedRole(): string
    {
        $raw = Auth::user()?->employeeProfile?->role ?? '';
        return preg_replace('/\s+/u', ' ', trim((string) $raw));
    }

    private function checkAccess(): void
    {
        $n  = $this->getNormalizedRole();
        $ok = preg_match('/^ceo$/iu', $n)
            || preg_match('/^marketing$/iu', $n)
            || preg_match('/^marketing\s*[-–—]\s*oic$/iu', $n);
        if (!$ok) abort(404);
    }

    /** GET /prompt-generator */
    public function index()
    {
        $this->checkAccess();
        return view('prompt_generator.index');
    }

    /**
     * POST /prompt-generator/analyze-image
     * Upload product image → gpt-4o vision → structured JSON para i-fill ang form.
     * Server-side (secure) — walang API key na hinihingi sa user.
     */
    public function analyzeImage(Request $request)
    {
        $this->checkAccess();
        $request->validate([
            'image' => 'required|image|mimes:jpg,jpeg,png,webp|max:8192', // 8 MB
        ]);

        $file    = $request->file('image');
        $b64     = base64_encode(file_get_contents($file->getRealPath()));
        $dataUrl = 'data:' . $file->getMimeType() . ';base64,' . $b64;

        try {
            $resp = Http::withToken(config('services.openai.key'))
                ->timeout(90)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model'           => self::VISION_MODEL,
                    'temperature'     => 0,
                    'response_format' => ['type' => 'json_object'],
                    'messages'        => [[
                        'role'    => 'user',
                        'content' => [
                            ['type' => 'text',      'text' => $this->visionInstruction()],
                            ['type' => 'image_url', 'image_url' => ['url' => $dataUrl]],
                        ],
                    ]],
                ]);

            if (!$resp->successful()) {
                Log::warning('PromptGen analyzeImage failed: ' . $resp->status() . ' ' . $resp->body());
                return response()->json(['ok' => false, 'message' => 'AI failed (' . $resp->status() . ').'], 200);
            }

            $content = (string) ($resp['choices'][0]['message']['content'] ?? '');
            $parsed  = json_decode($content, true);
            if (!is_array($parsed)) {
                return response()->json(['ok' => false, 'message' => 'Hindi mabasa ang AI result.'], 200);
            }

            return response()->json(['ok' => true, 'result' => $parsed]);
        } catch (\Throwable $e) {
            Log::error('PromptGen analyzeImage exception: ' . $e->getMessage());
            return response()->json(['ok' => false, 'message' => 'Error: ' . $e->getMessage()], 200);
        }
    }

    /** POST /prompt-generator/save — i-save ang client-generated prompt sa history. */
    public function save(Request $request)
    {
        $this->checkAccess();
        $data = $request->validate([
            'inputs' => 'required|array',
            'output' => 'required|string',
        ]);

        $gen = PromptGeneration::create([
            'mode'         => 'template',
            'model'        => null,
            'store_name'   => (string) ($data['inputs']['STORE_NAME'] ?? '') ?: null,
            'product_name' => (string) ($data['inputs']['PRODUCT_NAME'] ?? '') ?: null,
            'inputs'       => $data['inputs'],
            'output'       => $data['output'],
            'user_id'      => Auth::id(),
            'user_name'    => Auth::user()?->name,
        ]);

        return response()->json(['ok' => true, 'id' => $gen->id]);
    }

    /** POST /prompt-generator/main-flow — AI opening auto-reply message. */
    public function mainFlow(Request $request)
    {
        $this->checkAccess();
        $d = $request->validate([
            'product_name'        => 'required|string|max:250',
            'product_description' => 'nullable|string|max:6000',
            'features'            => 'nullable|string|max:4000',
            'price'               => 'nullable|string|max:300',
            'promo'               => 'nullable|string|max:2000',
            'language'            => 'nullable|string|max:30',
        ]);
        $lang   = trim($d['language'] ?? 'Taglish') ?: 'Taglish';
        $system = str_replace('{language}', $lang, self::MAINFLOW_PROMPT);
        $userMsg = "Product name: {$d['product_name']}\n"
            . "Product description: " . ($d['product_description'] ?? '') . "\n"
            . "Key features:\n" . ($d['features'] ?? '') . "\n"
            . "Promo price: " . ($d['price'] ?? '') . "\n"
            . "Current promo/offer: " . ($d['promo'] ?? '');

        $text = $this->openaiText($system, $userMsg, 0.85);
        if ($text === null) return response()->json(['ok' => false, 'message' => 'Nabigo ang AI. Subukan ulit.'], 200);
        return response()->json(['ok' => true, 'main_flow' => $text]);
    }

    /** POST /prompt-generator/sequence — AI follow-up broadcast messages (1-10). */
    public function sequence(Request $request)
    {
        $this->checkAccess();
        $d = $request->validate([
            'product_name'        => 'required|string|max:250',
            'product_description' => 'nullable|string|max:6000',
            'features'            => 'nullable|string|max:4000',
            'language'            => 'nullable|string|max:30',
            'pricing'             => 'nullable|string|max:2000',
            'price_pct'           => 'nullable|integer|min:0|max:100',
            'count'               => 'required|integer|min:1|max:10',
        ]);
        $lang  = trim($d['language'] ?? 'Taglish') ?: 'Taglish';
        $count = (int) $d['count'];
        $pricing = trim($d['pricing'] ?? '');
        $pricePct = isset($d['price_pct']) ? (int) $d['price_pct'] : 30;
        $priceCount = (int) round($count * $pricePct / 100);
        $freqLine = $pricePct <= 0
            ? "PRICE FREQUENCY: Do NOT mention any price, cost, or specific deal in ANY message."
            : ($pricePct >= 100
                ? "PRICE FREQUENCY: Mention the actual price/offer in EVERY message, with varied wording."
                : "PRICE FREQUENCY: Mention the actual price/offer in about {$pricePct}% of the messages "
                    . "(~{$priceCount} out of {$count}), spread across the sequence with varied wording. "
                    . "In the remaining messages, use other angles and do NOT state a price.");
        $system = str_replace(['{count}', '{language}'], [(string) $count, $lang], self::SEQUENCE_PROMPT);
        $userMsg = "Product name: {$d['product_name']}\n"
            . "Product description: " . ($d['product_description'] ?? '') . "\n"
            . "Key features:\n" . ($d['features'] ?? '') . "\n\n"
            . "Pricing / Offer details (use these ACTUAL values — do not invent other prices or deals):\n"
            . ($pricing !== '' ? $pricing : '(none provided — avoid stating a specific price)') . "\n\n"
            . $freqLine . "\n\n"
            . "Write exactly {$count} follow-up messages.";

        $schema = [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['messages'],
            'properties' => ['messages' => ['type' => 'array', 'items' => ['type' => 'string']]],
        ];

        try {
            $resp = Http::withToken(config('services.openai.key'))->timeout(120)->post('https://api.openai.com/v1/chat/completions', [
                'model' => self::TEXT_MODEL, 'temperature' => 0.95,
                'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $userMsg]],
                'response_format' => ['type' => 'json_schema', 'json_schema' => ['name' => 'followup_sequence', 'strict' => true, 'schema' => $schema]],
            ]);
            if (!$resp->successful()) return response()->json(['ok' => false, 'message' => 'AI failed (' . $resp->status() . ').'], 200);
            $parsed = json_decode((string) ($resp['choices'][0]['message']['content'] ?? ''), true);
            $messages = array_values(array_filter(array_map(fn ($m) => trim((string) $m), $parsed['messages'] ?? []), fn ($m) => $m !== ''));
            return response()->json(['ok' => true, 'messages' => array_slice($messages, 0, $count)]);
        } catch (\Throwable $e) {
            Log::error('PromptGen sequence: ' . $e->getMessage());
            return response()->json(['ok' => false, 'message' => 'Error: ' . $e->getMessage()], 200);
        }
    }

    /** POST /prompt-generator/test — run one customer message against a generated prompt. */
    public function testChat(Request $request)
    {
        $this->checkAccess();
        $d = $request->validate([
            'system_prompt' => 'required|string|max:60000',
            'message'       => 'required|string|max:2000',
        ]);
        $text = $this->openaiText($d['system_prompt'], $d['message'], 0.7);
        if ($text === null) return response()->json(['ok' => false, 'message' => 'Nabigo ang AI. Subukan ulit.'], 200);
        return response()->json(['ok' => true, 'reply' => $text]);
    }

    /** Shared plain-text OpenAI call (system + user). Returns text or null on failure. */
    private function openaiText(string $system, string $user, float $temp): ?string
    {
        try {
            $resp = Http::withToken(config('services.openai.key'))->timeout(90)->post('https://api.openai.com/v1/chat/completions', [
                'model' => self::TEXT_MODEL, 'temperature' => $temp,
                'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]],
            ]);
            if (!$resp->successful()) { Log::warning('PromptGen openaiText failed: ' . $resp->status() . ' ' . $resp->body()); return null; }
            $t = trim((string) ($resp['choices'][0]['message']['content'] ?? ''));
            return $t !== '' ? $t : null;
        } catch (\Throwable $e) {
            Log::error('PromptGen openaiText exception: ' . $e->getMessage());
            return null;
        }
    }

    /** GET /prompt-generator/history */
    public function history()
    {
        $this->checkAccess();
        return view('prompt_generator.history', [
            'rows' => PromptGeneration::orderByDesc('id')->limit(300)->get(),
        ]);
    }

    /** GET /prompt-generator/history/{id} */
    public function historyDetail(int $id)
    {
        $this->checkAccess();
        return view('prompt_generator.detail', ['row' => PromptGeneration::findOrFail($id)]);
    }

    /** Instruction para sa vision extraction (JSON only). */
    private function visionInstruction(): string
    {
        return <<<'TXT'
You extract verified sales configuration from ONE uploaded product image for an AI sales-prompt generator.

CRITICAL RULES:
1. Use ONLY information visibly supported by the image. Do not guess.
2. If a field is not visible or cannot be safely inferred, return null.
3. Absence of a shipping statement does NOT mean free shipping and does NOT mean hidden shipping. Return shipping.mode = null unless explicitly supported.
4. Detect pricing:
   - mode "single" only when the image clearly shows one official selling price and no bundle structure.
   - mode "bundles" when it clearly shows multiple quantity/package offers.
   - otherwise null.
5. For bundles, return every visible official offer in the same meaning as shown.
6. Do not invent medical claims, certifications, warranty, delivery times, ingredients, or policies.
7. assistant_name, order_fields, open_parcel_policy, warranty_policy, payment_method, delivery_time, coverage_area are usually null unless explicitly visible.
8. shipping.mode allowed values: "free", "declared", "hidden", or null. "hidden" may ONLY be used if the image explicitly says a shipping fee exists but should not be disclosed until asked. Never infer hidden mode from missing shipping text.
9. shipping.fee_type allowed: "fixed", "location", or null.
10. Return ONLY valid JSON. No markdown.

JSON SHAPE:
{
  "fields": {
    "STORE_NAME": null, "ASSISTANT_NAME": null, "PRODUCT_NAME": null, "PRODUCT_CATEGORY": null,
    "PRODUCT_DESCRIPTION": null, "PRIMARY_BENEFIT": null, "PRODUCT_BENEFITS": null, "PRODUCT_FEATURES": null,
    "INGREDIENTS": null, "HOW_TO_USE": null, "USAGE_TIPS": null, "PRODUCT_ORIGIN": null,
    "PRODUCT_CERTIFICATION": null, "WARRANTY_POLICY": null, "COVERAGE_AREA": null, "DELIVERY_TIME": null,
    "PAYMENT_METHOD": null, "OPEN_PARCEL_POLICY": null, "LEGITIMACY_INFO": null, "AVAILABILITY_INFORMATION": null,
    "PROMO_INFORMATION": null, "UNIT_NAME": null, "ORDER_FIELDS": null
  },
  "pricing": { "mode": null, "single_price": null, "bundles": [ {"name": null, "quantity": null, "price": null} ] },
  "shipping": { "mode": null, "fee_type": null, "amount": null, "location_response": null }
}
TXT;
    }
}
