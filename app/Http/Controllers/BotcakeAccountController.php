<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\BotcakeAccounts;
use App\Support\NavAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * /chatbot/botcake-accounts (spec 272): marketing adds their Botcake account Access Token, sees the account's pages with
 * their Likha status, and updates Likha's page tokens from it with a button. The page and every action open only for the
 * roles checked on its nav link in /owner/nav-settings (NavAccess; CEO always), 403 for the rest. The account token is
 * never sent back: not in the page, not in a flash message, not in old input.
 */
class BotcakeAccountController extends Controller
{
    public const NAV_URL = '/chatbot/botcake-accounts';

    public function __construct(private BotcakeAccounts $accounts) {}

    private static function role(): string
    {
        $raw = Auth::user()?->employeeProfile?->role ?? Auth::user()?->role ?? '';

        return preg_replace('/\s+/u', ' ', trim((string) $raw));
    }

    public static function allowed(?string $role = null): bool
    {
        return NavAccess::allows($role ?? self::role(), self::NAV_URL);
    }

    private function checkAccess(): void
    {
        if (! self::allowed()) {
            abort(403);
        }
    }

    private static function time(mixed $v): ?string
    {
        if (! is_string($v) || $v === '') {
            return null;
        }
        try {
            return Carbon::parse($v)->setTimezone(config('app.timezone'))->format('Y-m-d H:i');
        } catch (\Throwable) {
            return null;
        }
    }

    /** The accounts for the list, with names and times ready for the screen. */
    private function listed(): array
    {
        $all = $this->accounts->all();
        $ids = collect($all)->flatMap(fn ($a) => [$a['added_by'], $a['updated_by']])->filter()->unique();
        $names = $ids->isEmpty() ? collect() : User::whereIn('id', $ids)->pluck('name', 'id');

        return array_values(array_map(fn ($a) => [
            'id' => $a['id'], 'label' => $a['label'],
            'added_by' => $a['added_by'] ? ($names[$a['added_by']] ?? 'unknown') : 'unknown', 'added_at' => self::time($a['added_at']),
            'updated_by' => $a['updated_by'] ? ($names[$a['updated_by']] ?? 'unknown') : null, 'updated_at' => self::time($a['updated_at']),
            'pages' => $a['check']['pages'] ?? null, 'check_ok' => $a['check']['ok'] ?? null, 'check_at' => self::time($a['check']['at'] ?? null),
            'check_reason' => $a['readable'] ? ($a['check']['reason'] ?? null) : 'The token cannot be decrypted (the app key changed). Remove the account and add it again.',
            'readable' => $a['readable'],
        ], $all));
    }

    public function index()
    {
        $this->checkAccess();

        return view('chatbot.botcake-accounts', ['accounts' => $this->listed(), 'viewing' => null, 'pageList' => null]);
    }

    public function store(Request $request)
    {
        $this->checkAccess();
        $res = $this->accounts->add($request->input('label'), $request->input('access_token'), Auth::id());
        if ($res['error'] !== null) {
            // Only the label goes back to the form: the token is never flashed.
            return redirect()->route('chatbot.botcake-accounts.index')->withInput($request->only('label'))->withErrors(['access_token' => $res['error']]);
        }
        $label = trim((string) $request->input('label'));

        return redirect()->route('chatbot.botcake-accounts.index')
            ->with('status', ($res['status'] === 'updated' ? 'Updated: ' : 'Saved: ').$label.' ('.$res['pages'].' pages). The token is stored and will not be shown again.');
    }

    private static function id(string $id): string
    {
        return preg_match('/^[a-f0-9]{8}$/', $id) ? $id : abort(404);
    }

    public function pages(string $id)
    {
        $this->checkAccess();
        $account = $this->accounts->find(self::id($id)) ?? abort(404);

        return view('chatbot.botcake-accounts', ['accounts' => $this->listed(), 'viewing' => $account, 'pageList' => $this->accounts->pageList($account['id'], Auth::id())]);
    }

    public function updateTokens(string $id)
    {
        $this->checkAccess();
        $account = $this->accounts->find(self::id($id)) ?? abort(404);
        $res = $this->accounts->updateTokens($account['id'], Auth::id());
        $back = redirect()->route('chatbot.botcake-accounts.index');
        if ($res['error'] !== null) {
            return $back->with('error', $account['label'].': nothing was updated. '.$res['error']);
        }

        return $back->with('status', $account['label'].': '.($res['updated'] ? 'Updated '.count($res['updated']).' page'.(count($res['updated']) === 1 ? '' : 's').': '.implode(', ', $res['updated']) : 'Nothing to update: every page in Likha matches.'));
    }

    public function destroy(string $id)
    {
        $this->checkAccess();
        $account = $this->accounts->find(self::id($id)) ?? abort(404);
        $error = $this->accounts->remove($account['id'], Auth::id());
        $back = redirect()->route('chatbot.botcake-accounts.index');

        return $error !== null ? $back->with('error', $error) : $back->with('status', 'Removed: '.$account['label'].'. Its token is deleted.');
    }
}
