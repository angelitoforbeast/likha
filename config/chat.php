<?php

// Likha Chat (spec 296).
return [
    // Ang pangalan ng bawat AI member (sender_ai key → pangalan sa chat). Ang wala rito ay ipinapakita mula sa key.
    'ai_members' => [
        'mira' => 'Mira',
        'marketing_head' => 'Marketing Head',
    ],

    'max_body' => 4000,
    'max_files' => 5,
    'max_file_kb' => 10240,
    // Pareho sa checklist uploads (ChecklistController::submit, type "any"); walang SVG o HTML.
    'image_types' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
    'file_types' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv'],
    'disk' => 'public',
    'page_size' => 50,
    'poll_ms' => 3000,

    // Spec 300 (gaya ng Messenger).
    'reactions' => ['👍', '❤️', '😆', '😮', '😢', '😡'],
    'edit_minutes' => 15,
    // Voice: ang uri ay mula sa bytes ng file (hindi sa pangalan) → ang extension na itatago at ang Content-Type.
    'voice_types' => [
        'audio/webm' => 'webm', 'video/webm' => 'webm',
        'audio/ogg' => 'ogg', 'application/ogg' => 'ogg',
        'audio/mp4' => 'm4a', 'audio/x-m4a' => 'm4a', 'video/mp4' => 'm4a',
        'audio/mpeg' => 'mp3',
    ],
    'voice_max_kb' => 5120,
    'voice_max_seconds' => 120,
    // Bawat Likha page: ang unread count at mga alert (hinto kapag nakatago ang tab).
    'unread_poll_ms' => 15000,
    'typing_seconds' => 6,
    // "Active": ang huling paggamit ng Likha (cache), ipinapakita hanggang isang oras.
    'active_minutes' => 60,

    // Spec 308: web push kapag sarado ang Likha. Walang keys = sarado (walang button, walang padala).
    // Ang keys ay ginagawa ng `php artisan chat:push-keys` sa .env; hindi kailanman sa database o sa repo.
    'push' => [
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        'subject' => env('VAPID_SUBJECT', 'mailto:' . env('MAIL_FROM_ADDRESS', 'hello@example.com')),
        // Ang mga push service lang na ito ang pinapadalhan (walang SSRF). "*." = anumang subdomain.
        'hosts' => [
            'fcm.googleapis.com' => 'Google',              // Chrome, Android, Edge (Chromium), Samsung Internet, Opera
            '*.push.services.mozilla.com' => 'Mozilla',    // Firefox
            'web.push.apple.com' => 'Apple',               // Safari (Mac) at iPhone na naka-install sa Home Screen
            '*.notify.windows.com' => 'Microsoft',         // Edge na luma (WNS)
        ],
        'ttl' => 43200,       // 12 oras sa push service kapag naka-off ang phone
        'max_devices' => 10,  // bawat tao; ang pinakaluma ang natatanggal
        'retries' => 2,       // ang palya (hindi 404 / 410) ay sinusubukan ulit hanggang dalawang beses
    ],
];
