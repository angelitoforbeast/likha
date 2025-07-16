<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GPTAdGeneratorController extends Controller
{
    public function generate(Request $request)
    {
        $request->validate([
            'prompt' => 'required|string',
        ]);

        $prompt = $request->input('prompt');

        try {
            $response = Http::withToken(env('OPENAI_API_KEY'))->post('https://api.openai.com/v1/chat/completions', [
                'model' => 'gpt-4',
                'messages' => [
                    ['role' => 'user', 'content' => $prompt]
                ],
                'temperature' => 0.7,
            ]);

            Log::info('GPT Response', $response->json());

            if ($response->successful()) {
                return response()->json([
                    'output' => $response['choices'][0]['message']['content'] ?? 'No output from GPT.',
                ]);
            } else {
                return response()->json([
                    'output' => '❌ GPT request failed.',
                    'error' => $response->body(),
                ], 500);
            }
        } catch (\Exception $e) {
            Log::error('GPT Exception: ' . $e->getMessage());
            return response()->json([
                'output' => '❌ Server error occurred.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function showGeneratorForm()
    {
        $promptText = file_get_contents(resource_path('views/gpt/gpt_ad_prompts.txt'));
        return view('gpt.gpt_ad_generator', compact('promptText'));
    }
}
