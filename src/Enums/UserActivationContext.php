<?php

declare(strict_types=1);

namespace Radix\Enums;

/**
 * Kontext för användaraktivering (t.ex. vem som initierade den).
 */
enum UserActivationContext: string
{
    case User = 'user';
    case Admin = 'admin';
    case Resend = 'resend';
}
