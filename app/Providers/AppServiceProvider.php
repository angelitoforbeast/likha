<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Auth;
use App\Models\Task;
use Google_Client;
use Google_Service_Sheets;
use Google_Service_Sheets_ClearValuesRequest;


class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Handoff 012, Amendment 012-1 (A3): tahasang rehistro ng re-check ng remembered login, para hindi ito
        // umasa sa event discovery o sa naka-cache na events file. Dito sa register() (hindi sa boot()) para
        // mauna ito sa mga listener na galing sa discovery, na ikinakabit lang kapag nagbu-boot na ang app.
        \Illuminate\Support\Facades\Event::listen(
            \Illuminate\Auth\Events\Login::class,
            [\App\Listeners\RefuseRememberedLoginUnlessCeo::class, 'recheckRememberedLogin']
        );
        \Illuminate\Support\Facades\Event::listen(
            \Illuminate\Auth\Events\Logout::class,
            [\App\Listeners\RefuseRememberedLoginUnlessCeo::class, 'forgetSinceCookie']
        );
    }
    public function deleteAll()
{
    // 1. Truncate local database table
    DB::table('macro_output')->truncate();

    // 2. Get settings (sheet ID + range) from likha_order_settings
    $settings = DB::table('likha_order_settings')->get();

    // 3. Setup Google Client
    $client = new \Google_Client();
    $client->setApplicationName('Laravel GSheet');
    $client->setScopes([\Google_Service_Sheets::SPREADSHEETS]);
    $client->setAuthConfig(storage_path('app/google/credentials.json')); // make sure this file exists
    $client->setAccessType('offline');

    $service = new \Google_Service_Sheets($client);

    // 4. Loop through each sheet and clear Column I
    foreach ($settings as $setting) {
        $sheetId = $setting->sheet_id;
        $rangeToClear = preg_replace('/!.*/', '!I2:I', $setting->range); // keep same tab, clear only I2 down

        try {
            $requestBody = new \Google_Service_Sheets_ClearValuesRequest();
            $service->spreadsheets_values->clear($sheetId, $rangeToClear, $requestBody);
        } catch (\Exception $e) {
            \Log::error("❌ Failed to clear column I for sheet $sheetId: " . $e->getMessage());
        }
    }

    return back()->with('success', '✅ Cleared macro_output table and column I in all linked sheets.');
}

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Force HTTPS in production
        if (env('APP_ENV') === 'production') {
            URL::forceScheme('https');
        }

        // Make per-user pending task count available in all views.
        // Memoized per request (keyed by user id) — a composer('*') otherwise
        // re-runs these 2 count queries for EVERY rendered sub-view/component
        // (dozens of duplicate queries per page load).
        View::composer('*', function ($view) {
            static $cache = [];
            $user = Auth::user();
            $uid  = $user?->id ?? 0;

            if (! array_key_exists($uid, $cache)) {
                $cache[$uid] = $user ? [
                    Task::where('status', 'pending')->where('user_id', $uid)->count(),
                    Task::where('status', 'in_progress')->where('user_id', $uid)->count(),
                ] : [0, 0];
            }

            $view->with('pendingTaskCount', $cache[$uid][0])
                 ->with('inProgressTaskCount', $cache[$uid][1]);
        });

    }
}
