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

    public function isAuthenticated(): bool;

    public function clear();

    public function destroy(): void;

    public function isValid(): bool;

    public function setCsrfToken(): string;

    public function validateCsrfToken(?string $token): void;

    public function setFlashMessage(string $message, string $type = 'success', array $params = []): void;

    public function flashMessage(): ?array;
}