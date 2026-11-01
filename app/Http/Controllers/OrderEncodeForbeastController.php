<?php

namespace App\Http\Controllers;

use App\Models\OrderEncode;
use App\Services\OrderEncode\ForbeastOrders;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

/**
 * /order-encode/forbeast (spec 211): ang mga padala ng order sa Forbeast na pumalya nang tuluyan o naka-pause (order, page,
 * dahilan, oras), na may "Send again". CEO lang.
 */
class OrderEncodeForbeastController extends Controller
{
    private const LIMIT = 200;

    private function checkAccess(): void
    {
        $role = preg_replace('/\s+/u', ' ', trim((string) (Auth::user()?->employeeProfile?->role ?? Auth::user()?->role ?? '')));
        if ($role !== 'CEO' || !Schema::hasTable('order_encodes') || !Schema::hasColumn('order_encodes', 'forbeast_state')) abort(404);
    }

    public function index()
    {
        $this->checkAccess();
        $rows = OrderEncode::whereIn('forbeast_state', [ForbeastOrders::FAILED, ForbeastOrders::PAUSED])->orderByDesc('id')->limit(self::LIMIT)
            ->get(['id', 'page', 'macro_output_id', 'forbeast_state', 'forbeast', 'updated_at'])
            ->map(function (OrderEncode $o) {
                $f = (array) $o->forbeast;

                return [
                    'id' => (int) $o->id, 'ref' => (string) ($f['ref'] ?? ''), 'row' => (int) ($f['row'] ?? $o->macro_output_id), 'page' => (string) $o->page,
                    'state' => (string) $o->forbeast_state, 'reason' => (string) ($f['reason'] ?? ''), 'gateway' => (string) ($f['gateway'] ?? ''),
                    'tries' => (int) ($f['tries'] ?? 0), 'at' => (string) ($f['failed_at'] ?? $f['paused_at'] ?? $f['tried_at'] ?? $o->updated_at?->toIso8601String() ?? ''),
                ];
            });

        return view('order-encode.forbeast', ['rows' => $rows, 'live' => ForbeastOrders::since() !== null]);
    }

    public function resend(OrderEncode $orderEncode)
    {
        $this->checkAccess();
        if (!in_array($orderEncode->forbeast_state, [ForbeastOrders::FAILED, ForbeastOrders::PAUSED], true)) {
            return redirect()->route('order-encode.forbeast')->with('status', 'That order is not failed or paused any more.');
        }
        $ok = app(ForbeastOrders::class)->again($orderEncode);

        return redirect()->route('order-encode.forbeast')->with($ok ? 'status' : 'error', $ok
            ? 'Order ' . ((array) $orderEncode->forbeast)['ref'] . ' was queued to send again.'
            : 'Not queued: the row is gone, sending is off, or the page is not on a linked gateway now.');
    }
}
