<?php

return [
    // Pag-pause ng bot kada customer mula sa /chatbot/inbox. Off: nakatago ang mga control at hindi pinapansin ang
    // pause (ang unpause ay gumagana pa rin). On: gaya ng dati.
    'inbox_bot_pause' => (bool) env('CHATBOT_INBOX_BOT_PAUSE', false),
];
