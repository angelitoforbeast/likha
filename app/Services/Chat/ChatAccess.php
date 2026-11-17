<?php

namespace App\Services\Chat;

use App\Models\ChatConversation;
use App\Models\ChatMember;
use App\Models\User;

/**
 * Likha Chat (spec 296): sino ang puwedeng gumawa ng ano. Lahat ng chat route ay dumadaan dito sa server.
 *  - basa: miyembro (pati ang umalis, hanggang sa pag-alis niya) o ang CEO (lahat ng conversation);
 *  - post: aktibong miyembro lang (ang CEO ay kailangang mag-join muna);
 *  - gumawa ng group: CEO o OIC; ayusin ang group (pangalan, miyembro): admin na miyembro o ang CEO.
 */
class ChatAccess
{
    public static function role(?User $user): string
    {
        return trim((string) $user?->employeeProfile?->role);
    }

    public static function isCeo(?User $user): bool
    {
        return self::role($user) === 'CEO';
    }

    public static function isOic(?User $user): bool
    {
        return str_contains(self::role($user), 'OIC');
    }

    public static function mayCreateGroup(User $user): bool
    {
        return self::isCeo($user) || self::isOic($user);
    }

    public static function membership(ChatConversation $c, User $user): ?ChatMember
    {
        return ChatMember::where('conversation_id', $c->id)->where('user_id', $user->id)->first();
    }

    public static function mayRead(User $user, ?ChatMember $member): bool
    {
        return $member !== null || self::isCeo($user);
    }

    public static function mayPost(?ChatMember $member): bool
    {
        return $member !== null && $member->isActive();
    }

    public static function mayManage(ChatConversation $c, User $user, ?ChatMember $member): bool
    {
        return $c->isGroup() && (self::isCeo($user) || ($member?->isActive() && $member->role === ChatMember::ADMIN));
    }

    /** Hanggang saan ang nakikita: null = lahat; kung umalis na (at hindi CEO), hanggang sa oras ng pag-alis. */
    public static function visibleUntil(User $user, ?ChatMember $member): ?\DateTimeInterface
    {
        if (self::isCeo($user) || $member === null || $member->isActive()) {
            return null;
        }

        return $member->left_at;
    }
}
