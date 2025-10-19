<?php

declare(strict_types=1);

namespace Radix\Enums;

enum Role: string
{
    case User = 'user';
    case Support = 'support';
    case Editor = 'editor';
    case Moderator = 'moderator';
    case Admin = 'admin';

    public function level(): int
    {
        return match ($this) {
            self::User => 10,
            self::Support => 20,
            self::Editor => 30,
            self::Moderator => 40,
            self::Admin => 50,
        };
    }

    public static function tryFromName(string $role): ?self
    {
        return self::tryFrom($role);
    }
}
