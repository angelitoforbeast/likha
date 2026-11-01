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

    private function getNormalizedRole(): string
    {
        $raw = Auth::user()?->employeeProfile?->role ?? Auth::user()?->role ?? '';

        return preg_replace('/\s+/u', ' ', trim((string) $raw));
    }

    /** CEO lang, sa parehong check ng ibang CEO-only na screen (role === 'CEO'). */
    private function checkAccess(): void
    {
        if ($this->getNormalizedRole() !== 'CEO' ||!Schema::hasTable('order_encodes') || !Schema::hasColumn('order_encodes', 'forbeast_state')) abort(404);
    }

    public function index()
    {
        $this->checkAccess();
        $rows = OrderEncode::where(fn ($q) => $q->whereIn('forbeast_state', [ForbeastOrders::FAILED, ForbeastOrders::PAUSED])
                ->orWhere(fn ($s) => $s->where('forbeast_state', ForbeastOrders::QUEUED)->where('updated_at', '<', self::stuckBefore())))
            ->orderByDesc('id')->limit(self::LIMIT)
            ->get(['id', 'page', 'macro_output_id', 'forbeast_state', 'forbeast', 'updated_at'])
            ->map(function (OrderEncode $o) {
                $f = (array) $o->forbeast;

                return [
                    'id' => (int) $o->id, 'ref' => (string) ($f['ref'] ?? ''), 'row' => (int) ($f['row'] ?? $o->macro_output_id), 'page' => (string) $o->page,
                    'state' => $o->forbeast_state === ForbeastOrders::QUEUED ? 'stuck (queued, never answered)' : (string) $o->forbeast_state, 'reason' => (string) ($f['reason'] ?? ''), 'gateway' => (string) ($f['gateway'] ?? ''),
                    'tries' => (int) ($f['tries'] ?? 0), 'at' => (string) ($f['failed_at'] ?? $f['paused_at'] ?? $f['tried_at'] ?? $o->updated_at?->toIso8601String() ?? ''),
                ];
            });

        return view('order-encode.forbeast', ['rows' => $rows, 'live' => ForbeastOrders::since() !== null]);
    }

    /** Ang naka-queue na hindi nagalaw nang lampas sa oras ng retry (nawala ang job, hal. walang worker): makikita bilang stuck. */
    private static function stuckBefore(): \Carbon\Carbon
    {
        return now()->subHours(max(1, (int) config('order_encode.forbeast.retry_hours', 24)) + 2);
    }

    public function resend(OrderEncode $orderEncode)
    {
        $this->checkAccess();
        $stuck = $orderEncode->forbeast_state === ForbeastOrders::QUEUED && $orderEncode->updated_at?->lt(self::stuckBefore());
        if (!$stuck && !in_array($orderEncode->forbeast_state, [ForbeastOrders::FAILED, ForbeastOrders::PAUSED], true)) {
            return redirect()->route('order-encode.forbeast')->with('status', 'That order is not failed or paused any more.');
        }
        if (ForbeastOrders::newer($orderEncode)) {
            app(ForbeastOrders::class)->again($orderEncode); // minamarkahang superseded

            return redirect()->route('order-encode.forbeast')->with('status', 'A newer send of this order already went to Forbeast, so this older one was not sent.');
        }
        $ok = app(ForbeastOrders::class)->again($orderEncode);

        return redirect()->route('order-encode.forbeast')->with($ok ? 'status' : 'error', $ok
            ? 'Order ' . ((array) $orderEncode->forbeast)['ref'] . ' was queued to send again.'
            : 'Not queued: the row is gone, sending is off, or the page is not on a linked gateway now.');
    }
}
