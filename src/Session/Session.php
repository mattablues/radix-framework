<?php

declare(strict_types=1);

namespace Radix\Session;

use InvalidArgumentException;
use Radix\Session\Exception\CsrfTokenInvalidException;
use Radix\Support\Token;

class Session implements SessionInterface
{
    public const string AUTH_KEY = 'auth_id';
    private bool $isStarted = false;
    private string $csrfToken = '';


    private const array FLASH_TYPES = [
        'success', 'info', 'warning', 'error', 'enlightenment',
    ];

    public function isStarted(): bool
    {
        $this->isStarted = session_status() === PHP_SESSION_ACTIVE;

        return $this->isStarted;
    }

    public function start(): bool
    {
        if ($this->isStarted) {
            return true;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            $this->isStarted = true;

            return true;
        }

        session_start();
        $this->isStarted = true;

        return true;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if ($this->has($key)) {
            return $_SESSION[$key];
        }

        return $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $_SESSION);
    }

    public function remove(string $key): void
    {
        if ($this->has($key)) {
            unset($_SESSION[$key]);
        }
    }

    public function isAuthenticated(): bool
    {
        return $this->has(self::AUTH_KEY);
    }


    public function destroy(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();

            $name = session_name();
            if ($name === false) {
                throw new \RuntimeException('Unable to determine session name for cookie destruction.');
            }

            setcookie(
                $name,
                '',
                time() - 86400,
                $params['path'],
                $params['domain'],
                (bool) $params['secure'],
                (bool) $params['httponly']
            );
        }

        session_destroy();
    }

    public function clear(): void
    {
        $_SESSION = [];
    }

    public function isValid(): bool
    {
        if (!$this->ipMatches()) {
            return false;
        }

        if (!$this->userAgentMatches()) {
            return false;
        }

        if (!$this->loginIsRecent()) {
            return false;
        }

        return true;
    }

    public function setCsrfToken(): string
    {
        $token = new Token();
        $this->set('csrf_token', $token->value());
        $this->set('csrf_time', time());

        return $token->value();
    }

    public function csrf(): string
    {
        $token = $this->get('csrf_token');

        if (is_string($token) && $token !== '') {
            return $token;
        }

        return $this->setCsrfToken();
    }

    public function validateCsrfToken(?string $token): void
    {
        if ($token) {
            $this->csrfToken = $token;
        }

        if (!$this->csrfTokenIsValid()) {
            throw new CsrfTokenInvalidException("CSRF-token är ogiltig.");
        }

        if (!$this->csrfTokenIsRecent()) {
            throw new CsrfTokenInvalidException("CSRF-token har löpt ut.");
        }
    }

    public function setFlashMessage(string $message, string $type = 'success', array $params = []): void
    {
        if (!in_array(mb_strtolower($type), self::FLASH_TYPES)) {
            throw new InvalidArgumentException("Flash message type $type is not allowed.");
        }

        $this->set('flash_notification', [
            'body' => $message,
            'type' => $type,
        ]);
    }

    public function flashMessage(): ?array
    {
        $flash = $this->get('flash_notification');

        if (!$flash) {
            return null;
        }

        // Om flash är en sträng istället för en array, konvertera den
        if (!is_array($flash)) {
            // Se till att vi har en sträng utan att kasta mixed direkt till string
            if (!is_string($flash)) {
                $encoded = json_encode($flash);
                if ($encoded === false) {
                    $encoded = '';
                }
                $flash = $encoded;
            }

            $flash = ['type' => 'info', 'body' => $flash];
        }

        // Ta bort flash-data för att den bara ska visas en gång
        /** @var array<string,mixed> $flash */
        $this->remove('flash_notification');

        return $flash;
    }

    private function csrfTokenIsValid(): bool
    {
        $storedToken = $this->get('csrf_token');

        return $this->csrfToken === $storedToken;
    }

    private function csrfTokenIsRecent(): bool
    {
        $maxElapsed = 60 * 60 * 24; // 1 day
        $csrfTime = $this->get('csrf_time');

        if (!is_int($csrfTime)) {
            $this->destroyCsrfToken();
            return false;
        }

        $storedTime = $csrfTime;
        return ($storedTime + $maxElapsed) >= time();
    }

    private function destroyCsrfToken(): void
    {
        $this->remove('csrf_token');
        $this->remove('csrf_time');
    }

    private function loginIsRecent(): bool
    {
        $timeout = 15 * 60; // 15 minuter, samma som i Auth-middleware
        $sessionLastLogin = $this->get('last_login');

        if (!is_int($sessionLastLogin)) {
            return false; // Om ingen eller ogiltig "last_login" är satt, sessionen är inte giltig
        }

        return (($sessionLastLogin + $timeout) >= time()); // Kolla om sessionen är inom timeout-gränsen
    }

    private function ipMatches(): bool
    {
        $sessionIp = $this->get('ip');

        if (!isset($sessionIp) || !isset($_SERVER['REMOTE_ADDR'])) {
            return false;
        }

        if ($sessionIp === $_SERVER['REMOTE_ADDR']) {
            return true;
        }

        return false;
    }

    private function userAgentMatches(): bool
    {
        $sessionUserAgent = $this->get('user_agent');

        if (!isset($sessionUserAgent) && !isset($_SERVER['HTTP_USER_AGENT'])) {
            return false;
        }

        if ($sessionUserAgent === $_SERVER['HTTP_USER_AGENT']) {
            return true;
        }

        return false;
    }
}