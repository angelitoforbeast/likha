<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshots ng /owner/private rendered state. CEO-only — both viewing at saving.
 *
 * Flow:
 *   1. CEO clicks "📸 Save Snapshot" sa /owner/private → POST save()
 *   2. /owner/private/snapshots → list of all snapshots, sorted by recent first
 *   3. /owner/private/snapshots/{id} → frozen detail view ng saved data
 */
class OwnerPrivateSnapshotsController extends Controller
{
    /** CEO-only gate. */
    private function checkAccess(): void
    {
        $raw  = Auth::user()?->employeeProfile?->role ?? '';
        $norm = preg_replace('/\s+/u', ' ', trim((string) $raw));
        if (!preg_match('/^ceo$/iu', $norm)) abort(404);
    }

    /** POST /owner/private/snapshots — save current view's data as a snapshot. */
    public function save(Request $request)
    {
        $this->checkAccess();

        $validated = $request->validate([
            'start_date'    => 'required|date_format:Y-m-d',
            'end_date'      => 'required|date_format:Y-m-d',
            'partial_date'  => 'nullable|date_format:Y-m-d',
            'view_as'       => 'nullable|string|in:ceo,marketing',
            'rows_count'    => 'nullable|integer|min:0',
            'skipped_count' => 'nullable|integer|min:0',
            // The full data payload as captured by the frontend (rows[], etc).
            // Validated as array — Laravel auto-encodes to JSON sa MySQL.
            'payload'       => 'required|array',
        ]);

        // partial_date column may be missing kung hindi pa na-migrate yung db.
        // Detect at insert conditionally para hindi mag-break ang save() flow.
        $hasPartialCol = Schema::hasColumn('owner_private_snapshots', 'partial_date');
        $partialDate   = $validated['partial_date']
            ?? ($validated['payload']['partial_date'] ?? null);
        $partialDate   = $partialDate ?: null;

        $row = [
            'user_id'       => Auth::id(),
            'user_email'    => Auth::user()?->email,
            'snapshot_at'   => now(),
            'start_date'    => $validated['start_date'],
            'end_date'      => $validated['end_date'],
            'view_as'       => $validated['view_as'] ?? null,
            'rows_count'    => (int) ($validated['rows_count'] ?? 0),
            'skipped_count' => (int) ($validated['skipped_count'] ?? 0),
            'payload'       => json_encode($validated['payload']),
            'created_at'    => now(),
            'updated_at'    => now(),
        ];
        if ($hasPartialCol) {
            $row['partial_date'] = $partialDate;
        }

        $id = DB::table('owner_private_snapshots')->insertGetId($row);

        return response()->json([
            'ok'           => true,
            'id'           => $id,
            'snapshot_at'  => now()->toIso8601String(),
            'view_url'     => route('owner.private.snapshots.show', $id),
        ]);
    }

    /** GET /owner/private/snapshots — paginated list of saved snapshots. */
    public function index(Request $request)
    {
        $this->checkAccess();

        // partial_date column may or may not exist (added by migration
        // 2026_05_27_140000). Skip if not present para backwards-compatible.
        $cols = ['id', 'user_email', 'snapshot_at', 'start_date', 'end_date',
                 'view_as', 'rows_count', 'skipped_count'];
        if (Schema::hasColumn('owner_private_snapshots', 'partial_date')) {
            $cols[] = 'partial_date';
        }

        $snapshots = DB::table('owner_private_snapshots')
            ->select($cols)
            ->orderByDesc('snapshot_at')
            ->paginate(50);

        return view('owner.private-snapshots-index', [
            'snapshots' => $snapshots,
        ]);
    }

    /** GET /owner/private/snapshots/{id} — frozen detail view. */
    public function show(int $id)
    {
        $this->checkAccess();

        $snapshot = DB::table('owner_private_snapshots')->where('id', $id)->first();
        if (!$snapshot) abort(404, 'Snapshot not found');

        $payload = json_decode($snapshot->payload ?? '{}', true) ?: [];

        return view('owner.private-snapshot-show', [
            'snapshot' => $snapshot,
            'payload'  => $payload,
        ]);
    }

    /** DELETE /owner/private/snapshots/{id} — CEO can prune old snapshots. */
    public function destroy(int $id)
    {
        $this->checkAccess();

        $deleted = DB::table('owner_private_snapshots')->where('id', $id)->delete();

        return response()->json([
            'ok'      => true,
            'deleted' => $deleted,
        ]);
    }
}
