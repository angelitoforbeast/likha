<?php

namespace App\Services\Chatbot;

use RuntimeException;

/**
 * Ang listahan ng /chatbot/inbox ay lumampas sa Inbox::LIST_SECONDS (o pinutol ng MySQL ang query nito). Ang endpoint
 * ay sumasagot agad ng "narrow the window" sa halip na hawakan ang worker nang isang minuto.
 */
class InboxTooSlow extends RuntimeException
{
}
