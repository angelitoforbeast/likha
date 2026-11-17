<?php

/*
|--------------------------------------------------------------------------
| Page access (spec 294)
|--------------------------------------------------------------------------
|
| The open list: paths every signed-in user may open whatever their nav shows. RecordPageAccess never records or
| blocks them and runs no query for them. Patterns are Laravel `Str::is` patterns on the request path without the
| leading slash (the home page is "/"). One reason per entry.
|
*/

return [

    'open' => [
        '/',               // Home: everyone lands here after login
        'profile',         // Own profile and password
        'profile/*',
        'checklist',       // Daily checklist: every role submits; the controller scopes what each role sees
        'checklist/*',
        'astra',           // Astra assistant: for every staff member
        'astra/*',
        'chat',            // Staff chat (not chatblast/chatbot, which are nav apps with their own links)
        'chat/*',
        'order-encode',    // Order encode pages: their own access rule (spec 130), not the nav
        'order-encode/*',
        'login',           // Sign in, sign out and register: before or at the edge of a session
        'logout',
        'register',
        'password/*',      // Password reset links
        'email/*',         // Email verification links
        'api/*',           // API: its own auth, never a page
        'up',              // Health check
        'broadcasting/*',  // Realtime auth handshakes, never a page
        'sanctum/*',       // Sanctum CSRF cookie, never a page
        'storage/*',       // Uploaded files served by Laravel
        'build/*',         // Built assets (Vite)
        'css/*',           // Static assets
        'js/*',
        'images/*',
        'img/*',
        'fonts/*',
        'favicon.ico',
        '*.json',          // JSON polling routes, by their usual suffixes (as NavLink::unregisteredRoutes skips them)
        '*/data',
        '*/status',
        '*/poll',
        '*/refresh',
    ],

];
