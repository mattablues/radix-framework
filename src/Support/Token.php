<?php

declare(strict_types=1);

namespace Radix\Support;

class Token
{
   private string $token;

    public function __construct(?string $tokenValue = null)
    {
        if ($tokenValue) {
            $this->token = $tokenValue;
        } else {
            $this->token = bin2hex(random_bytes(16)); // 16 bytes = 128 bits = 32 hex characters
        }
    }

    public function value(): string
    {
        return $this->token;
    }

    public function hashHmac(): string
    {
        $key = getenv('SECURE_TOKEN_HMAC');
        if ($key === false || $key === '') {
            throw new \RuntimeException('SECURE_TOKEN_HMAC env variable is not set.');
        }

        $hashHmac = hash_hmac('sha256', $this->token, $key);

        return $hashHmac;
    }

    public static function hashCrc32(int|string $identifier): string
    {
        return hash('crc32', microtime(true) . mt_rand() . $identifier);
    }
}