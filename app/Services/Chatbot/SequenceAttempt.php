<?php

namespace App\Services\Chatbot;

/**
 * Ang tanda ng padalang naghihintay (inline) ng isang sequence step, sa mismong sandali bago lumabas ang HTTP request (ang
 * BotcakeClient at ang GatewayProvider ang tumatawag sa beforeCall). Ang job na namatay bago ang tawag (hal. habang
 * naghihintay ng puwesto sa rate ng page) ay hindi itinuturing na nagpadala.
 */
class SequenceAttempt
{
    private static ?\Closure $before = null;

    /** Patakbuhin ang $send; ang $mark ay tinatawag nang isang beses, bago ang unang HTTP request nito (kung mayroon). */
    public static function around(?\Closure $mark, \Closure $send): mixed
    {
        self::$before = $mark;
        try {
            return $send();
        } finally {
            self::$before = null;
        }
    }

    public static function beforeCall(): void
    {
        if (self::$before === null) return;
        $mark = self::$before;
        self::$before = null;
        $mark();
    }
}
