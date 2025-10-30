<?php

declare(strict_types=1);

namespace Radix\Enums;

/**
 * Class UserActivationContext
 * @package App\Events
 */
enum UserActivationContext: string
{
    case User = 'user';
    case Admin = 'admin';
    case Resend = 'resend';
}