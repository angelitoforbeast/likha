<?php

/**
 * Keyword rules ng `php artisan items:suggest-categories` — category name => keywords.
 * FIRST MATCH WINS (ayon sa pagkakasunod dito), case-insensitive, substring sa pangalan ng item.
 * Ang walang tugma ay mananatiling unassigned — hindi kailanman awtomatikong "Iba pa".
 * Ang mga specific na phrase (hal. "sleep patch") ay nasa category na nauuna sa generic ("patch").
 */
return [
    'rules' => [
        'Health at Beauty'       => ['sleep patch', 'nail', 'pain', 'relief', 'oil', 'massage', 'beauty', 'skin', 'hair'],
        'Repair at DIY'          => ['leather patch', 'patch', 'tape', 'glue', 'sealant', 'repair', 'screw', 'tool'],
        'Office at School'       => ['ballpen', 'ball pen', 'notebook', 'marker', 'stapler'],
        'Ilaw at Kuryente'       => ['bulb', 'socket', 'led', 'lamp', 'light', 'flashlight', 'extension', 'plug', 'charger', 'usb'],
        'Sasakyan at Motor'      => ['reflective', 'air pump', 'tire', 'car ', 'motor', 'helmet', 'bike'],
        'Bahay at Paglilinis'    => ['brush', 'freshener', 'mop', 'sponge', 'cleaner', 'bathroom', 'rack', 'hook', 'organizer', 'kitchen'],
        'Fashion at Accessories' => ['belt', 'eyeglass', 'glasses', 'bag', 'wallet', 'watch', 'cap'],
    ],
];
