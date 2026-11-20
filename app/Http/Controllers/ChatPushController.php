<?php

namespace App\Http\Controllers;

use App\Services\Chat\ChatPush;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * /chat/push — spec 308: ang alerts ng device na ito (web push). Naka-login lang, at sariling mga device lang ang
 * nababasa, nase-save at natatanggal. Ang endpoint ay URL na galing sa browser at pinapadalhan ng server, kaya ang
 * kilalang push service lang ang tinatanggap (422 ang iba). 404 lahat kapag sarado ang push (walang keys).
 */
class ChatPushController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless(ChatPush::enabled(), 404);

        return response()->json(['devices' => ChatPush::devices($request->user())]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless(ChatPush::enabled(), 404);
        $endpoint = $request->input('endpoint');
        $p256dh = $request->input('keys.p256dh');
        $auth = $request->input('keys.auth');
        if (ChatPush::service($endpoint) === null) {
            throw ValidationException::withMessages(['endpoint' => 'This browser’s push service is not supported.']);
        }
        if (! ChatPush::validKey($p256dh, 65) || ! ChatPush::validKey($auth, 16)) {
            throw ValidationException::withMessages(['keys' => 'Invalid subscription keys.']);
        }

        [$device, $created] = ChatPush::save($request->user(), $endpoint, $p256dh, $auth, $request->userAgent());

        return response()->json(['ok' => true, 'device' => $device], $created ? 201 : 200);
    }

    public function destroy(Request $request): JsonResponse
    {
        abort_unless(ChatPush::enabled(), 404);
        $endpoint = $request->input('endpoint');
        if (! is_string($endpoint) || $endpoint === '' || strlen($endpoint) > ChatPush::MAX_ENDPOINT) {
            throw ValidationException::withMessages(['endpoint' => 'Invalid endpoint.']);
        }

        return response()->json(['ok' => true, 'removed' => ChatPush::remove($request->user(), $endpoint)]);
    }
}
