<?php

namespace App\Support;

/**
 * The roles an account can be created with (spec 298), highest first, in their canonical spelling. The new-account
 * page builds its dropdown from here and the server checks every post against the same list, so the two never drift.
 *
 * Roles are compared trimmed, with inner spaces collapsed and case-insensitively, as the other role checks do.
 */
class AccountRoles
{
    public const CEO = 'CEO';
    public const MARKETING_OIC = 'Marketing - OIC';
    public const DATA_ENCODER_OIC = 'Data Encoder - OIC';
    public const MARKETING = 'Marketing';

    /** Highest first. */
    public const ALL = [self::CEO, self::MARKETING_OIC, self::DATA_ENCODER_OIC, self::MARKETING];

    /** The canonical spelling of a role in the list, or null when it is not one of them. */
    public static function canonical(?string $role): ?string
    {
        $key = self::key($role);
        foreach (self::ALL as $name) {
            if (self::key($name) === $key) {
                return $name;
            }
        }

        return null;
    }

    /** Only the top two roles create accounts. */
    public static function mayCreateAccounts(?string $actorRole): bool
    {
        return self::creatableBy($actorRole) !== [];
    }

    /**
     * The roles the actor may give a new account, highest first: a CEO any of them, a Marketing - OIC every one but
     * CEO (only a CEO makes another CEO), anyone else none.
     *
     * @return string[]
     */
    public static function creatableBy(?string $actorRole): array
    {
        return match (self::canonical($actorRole)) {
            self::CEO => self::ALL,
            self::MARKETING_OIC => array_values(array_diff(self::ALL, [self::CEO])),
            default => [],
        };
    }

    private static function key(?string $role): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) $role)));
    }
}
