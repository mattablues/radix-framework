<?php

declare(strict_types=1);

namespace Radix\Mailer;

interface MailerInterface
{
    /**
     * @param array<string,mixed> $options
     */
    public function send(string $to, string $subject, string $body, array $options = []): bool;

}
