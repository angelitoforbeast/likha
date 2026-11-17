<?php

namespace App\Http\Controllers;

use App\Support\NavMenu;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

/**
 * Sidebar pins (spec 295): the signed-in person's own list of at most six nav link ids, in their order. Ids of links
 * their own role cannot see (not the "view as" role, which is a preview) are dropped silently, as are repeats.
 */
class NavPinsController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'pins' => 'present|array|max:' . NavMenu::MAX_PINS,
            'pins.*' => 'integer',
        ]);

        $user = Auth::user();
        abort_unless(Schema::hasColumn('users', 'nav_pins'), 409, 'Pins are not set up yet.');

        $visible = NavMenu::links($user->employeeProfile?->role)->pluck('id')->map(fn ($id) => (int) $id)->flip();
        $pins = collect($validated['pins'])->map(fn ($id) => (int) $id)->filter(fn ($id) => $visible->has($id))->unique()->values()->all();

        $user->forceFill(['nav_pins' => $pins])->save();

        return response()->json(['pins' => $pins]);
    }
}
