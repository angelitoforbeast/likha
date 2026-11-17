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
];
