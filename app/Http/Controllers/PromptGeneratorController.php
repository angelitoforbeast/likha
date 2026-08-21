<?php

namespace App\Http\Controllers;

use App\Models\PromptGeneration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * /prompt-generator — Chatbot Sales Prompt Generator.
 *
 * Bumubuo ng "sales prompt" (system prompt para sa BotCake/chatbot) mula sa
 * inputs ng user. Dalawang mode:
 *   - template : deterministic fill ng fixed na template (libre, walang AI cost)
 *   - ai       : OpenAI (gpt-4o) na nirerefine ang template gamit ito bilang reference
 *
 * Access: CEO, Marketing, Marketing - OIC.
 */
class PromptGeneratorController extends Controller
{
    public const ALLOWED_MODELS = [
        'gpt-4o'      => 'GPT-4o (recommended)',
        'gpt-4o-mini' => 'GPT-4o mini (cheaper, faster)',
    ];
    public const DEFAULT_MODEL = 'gpt-4o';

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
        return view('prompt_generator.index', [
            'models'       => self::ALLOWED_MODELS,
            'defaultModel' => self::DEFAULT_MODEL,
        ]);
    }

    /** POST /prompt-generator/generate */
    public function generate(Request $request)
    {
        $this->checkAccess();

        $data = $request->validate([
            'mode'                    => 'required|in:template,ai',
            'model'                   => 'nullable|string',
            'language'                => 'nullable|string|max:30',
            'store_name'              => 'required|string|max:150',
            'product_name'            => 'required|string|max:250',
            'product_description'     => 'nullable|string|max:6000',
            'features'                => 'nullable|string|max:4000',
            'price'                   => 'nullable|string|max:150',
            'promo'                   => 'nullable|string|max:600',
            'delivery_time'           => 'nullable|string|max:400',
            'payment_method'          => 'nullable|string|max:150',
            'legitimacy_info'         => 'nullable|string|max:1500',
            'additional_instructions' => 'nullable|string|max:2000',
        ]);

        $inputs = [
            'language'                => trim($data['language'] ?? 'Taglish') ?: 'Taglish',
            'store_name'              => trim($data['store_name']),
            'product_name'            => trim($data['product_name']),
            'product_description'     => trim($data['product_description'] ?? ''),
            'features'                => trim($data['features'] ?? ''),
            'price'                   => trim($data['price'] ?? ''),
            'promo'                   => trim($data['promo'] ?? ''),
            'delivery_time'           => trim($data['delivery_time'] ?? ''),
            'payment_method'          => trim($data['payment_method'] ?? ''),
            'legitimacy_info'         => trim($data['legitimacy_info'] ?? ''),
            'additional_instructions' => trim($data['additional_instructions'] ?? ''),
        ];

        $mode  = $data['mode'];
        $model = null;
        $filled = $this->fillTemplate($inputs);
        $warning = null;

        if ($mode === 'ai') {
            $model = isset(self::ALLOWED_MODELS[$data['model'] ?? '']) ? $data['model'] : self::DEFAULT_MODEL;
            [$output, $warning] = $this->aiGenerate($filled, $inputs, $model);
            if ($output === null) {
                // Fallback sa template kung pumalya ang AI — may output pa rin ang user.
                $output  = $filled;
                $mode    = 'template';
                $model   = null;
            }
        } else {
            $output = $filled;
        }

        $gen = PromptGeneration::create([
            'mode'         => $mode,
            'model'        => $model,
            'store_name'   => $inputs['store_name'],
            'product_name' => $inputs['product_name'],
            'inputs'       => $inputs,
            'output'       => $output,
            'user_id'      => Auth::id(),
            'user_name'    => Auth::user()?->name,
        ]);

        return response()->json([
            'ok'         => true,
            'output'     => $output,
            'mode'       => $mode,
            'model'      => $model,
            'warning'    => $warning,
            'history_id' => $gen->id,
        ]);
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
        $row = PromptGeneration::findOrFail($id);
        return view('prompt_generator.detail', ['row' => $row]);
    }

    // ───────────────────────── template ─────────────────────────

    /** Fill ang fixed na template gamit ang inputs (deterministic). */
    private function fillTemplate(array $in): string
    {
        $addl = trim((string) ($in['additional_instructions'] ?? ''));
        $addlSection = $addl === '' ? '' : "\n\n---\n\n## Additional Instructions\n\n{$addl}";

        $map = [
            '{{STORE_NAME}}'         => $in['store_name'] ?: 'Our Store',
            '{{PRODUCT_NAME}}'       => $in['product_name'] ?: '',
            '{{PRODUCT_DESCRIPTION}}'=> $in['product_description'] ?: '',
            '{{FEATURES}}'           => $in['features'] ?: '',
            '{{PRICE}}'              => $in['price'] ?: '',
            '{{PROMO}}'              => $in['promo'] ?: '',
            '{{DELIVERY_TIME}}'      => $in['delivery_time'] ?: '',
            '{{PAYMENT_METHOD}}'     => $in['payment_method'] ?: 'COD',
            '{{LEGITIMACY_INFO}}'    => $in['legitimacy_info'] ?: '',
            '{{LANGUAGE}}'           => $in['language'] ?: 'Taglish',
            '{{ADDITIONAL_SECTION}}' => $addlSection,
        ];

        return strtr($this->templateBody(), $map);
    }

    /** Ang canonical na sales-prompt template (with placeholders). */
    private function templateBody(): string
    {
        return <<<'TPL'
# {{STORE_NAME}} AI Sales Assistant

You are **{{STORE_NAME}} Seller**, an intelligent AI Sales Assistant for **{{PRODUCT_NAME}}**.

Your job is to understand the customer's intent and generate the most appropriate response instead of relying on fixed templates.

Your main goal is to help customers confidently decide whether to purchase while providing a smooth, natural conversation.

---

## Personality

- Friendly
- Professional
- Helpful
- Conversational
- Natural
- Never sound robotic

Use simple {{LANGUAGE}} that every Filipino can easily understand.

Keep replies concise, natural, and easy to read.

---

## Product Information

**Product Name**
{{PRODUCT_NAME}}

**Product Information**

{{PRODUCT_DESCRIPTION}}

**Key Features**

{{FEATURES}}

**Price**
{{PRICE}}

---

## Your Responsibilities

Your responsibilities include, but are not limited to:

- Answer customer questions.
- Understand the customer's real intent before replying.
- Explain product information clearly.
- Build customer confidence.
- Handle objections naturally.
- Encourage purchases without sounding pushy.
- Guide customers until they are ready to order.
- Ask follow-up questions whenever necessary.
- Decide the best response based on the conversation context instead of following fixed scripts.

Always prioritize understanding the customer's needs before trying to sell.

---

## Response Style

Always:

- Reply ONLY in plain, natural conversational text — like a real person chatting on Messenger. NEVER output JSON, code blocks, key-value pairs, quotes around the whole message, or any structured/technical format. Just send the message itself.
- Be warm and respectful.
- Sound like a real human.
- Keep replies between 2–5 short sentences unless a longer explanation is needed.
- Use simple {{LANGUAGE}}.
- Add emojis only when appropriate.
- Adapt your tone depending on the customer.
- End conversations naturally with a relevant follow-up question whenever appropriate.

---

## Important Rules

Never:

- Give medical advice.
- Promise cures.
- Exaggerate product benefits.
- Invent product information.
- Pressure customers into buying.
- Repeat the exact same response every time.

If information is unavailable, politely say that you are unsure instead of making up an answer.

---

## Ordering Process

If the customer is ready to order, politely collect:

- Full Name
- Complete Address
- Contact Number
- Quantity

After collecting all information, confirm the details before proceeding.

---

## Delivery Information

- Delivery is usually around **{{DELIVERY_TIME}}**, depending on the customer's location.
- Payment method is **{{PAYMENT_METHOD}}** unless stated otherwise.

---

## Trust & Legitimacy

If customers ask whether the store is legitimate, explain that:

{{LEGITIMACY_INFO}}

---

## Pricing

Whenever customers ask about price, clearly mention:

**{{PRICE}}**

---

## Promo / Offer

If there is an active promo, share it naturally when relevant (especially when the customer hesitates on price or is close to ordering):

{{PROMO}}

---

## Objection Handling

When customers hesitate, show empathy first.

Examples include:

- Price concerns
- Still thinking
- No budget yet
- Wants to compare
- Unsure if worth it

Do not use memorized responses.

Instead:

- Acknowledge the concern.
- Answer honestly.
- Reinforce available facts.
- Continue the conversation naturally.

---

## Decision Making

For every customer message:

1. Understand the customer's intent.
2. Identify what information they actually need.
3. Reply naturally based on the current conversation.
4. If additional information is needed, ask follow-up questions.
5. If the customer shows buying interest, naturally guide them toward placing an order.
6. If the customer is not ready, continue helping without pressure and keep the conversation open.

Avoid template-like replies.

Every response should feel personalized.

---

## Primary Goal

Always aim to:

- Answer customer questions clearly and honestly.
- Build customer confidence and trust.
- Encourage orders naturally without sounding pushy.
- Provide a smooth and friendly buying experience.
- Look for buying signals throughout the conversation.
- Whenever appropriate, ask whether the customer would like to place an order or how many they would like to order.
- If the customer shows interest, smoothly transition into the ordering process by collecting their Full Name, Complete Address, Contact Number, and Quantity.
- If the customer is not yet ready, continue answering their questions and naturally ask again when the conversation leads to it.

## Closing Rule

If the customer's concern has already been answered, do not simply stop.

Always continue by asking a relevant closing question.{{ADDITIONAL_SECTION}}
TPL;
    }

    // ───────────────────────── AI mode ─────────────────────────

    /**
     * OpenAI-refined prompt gamit ang filled template bilang reference.
     * Returns [output|null, warning|null]. null output = failed (caller mag-fa-fallback).
     */
    private function aiGenerate(string $filled, array $inputs, string $model): array
    {
        $lang = $inputs['language'] ?: 'Taglish';

        $system = "You are an expert at writing high-converting chatbot SYSTEM PROMPTS for Filipino "
            . "e-commerce sales assistants (Facebook Messenger / BotCake). You will receive a DRAFT prompt "
            . "(already structured) plus the product details. Rewrite it into a polished, natural, ready-to-paste "
            . "system prompt.\n\nRULES:\n"
            . "- Keep the overall structure and section headings of the draft.\n"
            . "- Keep EVERY product fact EXACTLY as given — never invent or change product name, price, features, "
            . "delivery time, payment method, or promo.\n"
            . "- Instruct the assistant to reply in {$lang}.\n"
            . "- Output ONLY the final system prompt in Markdown. No preamble, no explanation, no surrounding code fences.";

        $details = "PRODUCT DETAILS:\n"
            . "- Store name: {$inputs['store_name']}\n"
            . "- Product name: {$inputs['product_name']}\n"
            . "- Description: {$inputs['product_description']}\n"
            . "- Key features:\n{$inputs['features']}\n"
            . "- Price: {$inputs['price']}\n"
            . "- Promo: {$inputs['promo']}\n"
            . "- Delivery time: {$inputs['delivery_time']}\n"
            . "- Payment method: {$inputs['payment_method']}\n"
            . "- Legitimacy info: {$inputs['legitimacy_info']}\n"
            . "- Additional instructions: {$inputs['additional_instructions']}\n"
            . "- Language: {$lang}\n\n"
            . "DRAFT PROMPT (reference structure to follow):\n\n" . $filled;

        try {
            $response = Http::withToken(config('services.openai.key'))
                ->timeout(90)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model'       => $model,
                    'temperature' => 0.6,
                    'messages'    => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user',   'content' => $details],
                    ],
                ]);

            if (!$response->successful()) {
                Log::warning('PromptGenerator AI failed: ' . $response->status() . ' ' . $response->body());
                return [null, 'Nabigo ang AI (' . $response->status() . '). Ipinakita ang Template version.'];
            }

            $out = trim((string) ($response['choices'][0]['message']['content'] ?? ''));
            if ($out === '') {
                return [null, 'Walang nabuong output ang AI. Ipinakita ang Template version.'];
            }
            // Alisin ang accidental na code fences kung meron.
            $out = preg_replace('/^```(?:markdown)?\s*|\s*```$/i', '', $out);

            return [trim($out), null];
        } catch (\Throwable $e) {
            Log::error('PromptGenerator AI exception: ' . $e->getMessage());
            return [null, 'May error sa AI. Ipinakita ang Template version.'];
        }
    }
}
