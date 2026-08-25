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
    /** Vision-capable model para sa image auto-fill. */
    public const VISION_MODEL = 'gpt-4o';

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
