<?php

declare(strict_types=1);

namespace Radix\Session;

interface SessionInterface
{
    public function isStarted(): bool;

    public function start(): bool;

    public function set(string $key, mixed $value): void;

    public function get(string $key, mixed $default = null): mixed;

    public function has(string $key): bool;

    public function remove(string $key): void;

    public function destroy(): void;

    public function clear(): void;

    public function isAuthenticated(): bool;

    public function setCsrfToken(): string;

    public function csrf(): string;

    public function validateCsrfToken(?string $token): void;

    /**
     * @param array<string,mixed> $params
     */
    public function setFlashMessage(string $message, string $type = 'success', array $params = []): void;

    /**
     * @return array<string,mixed>|null
     */
    public function flashMessage(): ?array;

    /**
     * Kontrollera om sessionen är giltig (t.ex. IP, user-agent, timeout etc.).
     */
    public function isValid(): bool;
}
