<?php

namespace App\Http\Controllers;

use App\Services\MetaAds\AppConfig;
use App\Services\MetaAds\DataDeletion;
use Illuminate\Http\Request;

/**
 * Ang mga public na page na hinihingi ng Meta App Review (spec 282), walang login: ang Privacy Policy (/privacy), ang
 * Data Deletion Callback (POST /meta/data-deletion, walang CSRF, signed_request) at ang status ng deletion.
 * Walang token, App Secret o Facebook ID na lumalabas sa alinman.
 */
class MetaPrivacyController extends Controller
{
    public function privacy()
    {
        return view('meta.privacy', [
            'company' => AppConfig::companyName() ?? (string) config('app.name'),
            'email'   => AppConfig::contactEmail(),
        ]);
    }

    /** Ang kontrata ng Meta: {"url": <status page>, "confirmation_code": <code>}. Anumang mali: 400, walang detalye. */
    public function deletion(Request $request, DataDeletion $deletion)
    {
        $fbUserId = $deletion->userId($request->input('signed_request'));
        if ($fbUserId === null) return response()->json(['error' => 'invalid request'], 400);

        $code = $deletion->delete($fbUserId);

        return response()->json(['url' => DataDeletion::statusUrl($code), 'confirmation_code' => $code]);
    }

    public function status(string $code, DataDeletion $deletion)
    {
        $found = $deletion->find($code);

        return response()->view('meta.data-deletion', [
            'company' => AppConfig::companyName() ?? (string) config('app.name'),
            'found'   => $found,
        ], $found ? 200 : 404);
    }
}
