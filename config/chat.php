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
];
