<?php

declare(strict_types=1);

namespace Radix\Http;

use Radix\Session\SessionInterface;
use Radix\Viewer\RadixTemplateViewer;
use Radix\Viewer\TemplateViewerInterface;

class Request implements RequestInterface
{
    private SessionInterface $session;

    /**
     * @param array<string,mixed> $get
     * @param array<string,mixed> $post
     * @param array<string,mixed> $files
     * @param array<string,mixed> $cookie
     * @param array<string,mixed> $server
     */
    public function __construct(
        public string $uri,
        public string $method,
        public array $get,
        public array $post,
        public array $files,
        public array $cookie,
        public array $server,
    )
    {
    }

    public static function createFromGlobals(): self
    {
        return new self(
            $_SERVER['REQUEST_URI'],
            $_SERVER['REQUEST_METHOD'],
            $_GET,
            $_POST,
            $_FILES,
            $_COOKIE,
            $_SERVER,
        );
    }

    public function fullUrl(): string
    {
        $scheme = (!empty($this->server['HTTPS']) && $this->server['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $this->server['HTTP_HOST'] ?? ($this->server['SERVER_NAME'] ?? 'localhost');
        $uri    = $this->server['REQUEST_URI'] ?? '/';

        return $scheme . '://' . $host . $uri;
    }

    public function ip(): string
    {
        // Lista över betrodda proxy-klienter
        $trustedProxies = ['192.168.0.1', '10.0.0.1'];

        // Kontrollera om klientens IP finns i X_FORWARDED_FOR
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ipList = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ip = trim($ipList[0]); // Returnerar den första IP-adressen i listan

            // Kontrollera om proxyn är betrodd och IP-adressen är giltig
            if (in_array($_SERVER['REMOTE_ADDR'], $trustedProxies) && filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }

        // Kontrollera om en proxy-klient skickade klientens IP
        if (!empty($_SERVER['HTTP_CLIENT_IP']) && filter_var($_SERVER['HTTP_CLIENT_IP'], FILTER_VALIDATE_IP)) {
            return $_SERVER['HTTP_CLIENT_IP'];
        }

        // Direkt IP från klienten
        if (!empty($_SERVER['REMOTE_ADDR']) && filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP)) {
            return $_SERVER['REMOTE_ADDR'];
        }

        // Om ingen giltig IP hittas, returnera en standard-IP
        return '0.0.0.0';
    }

    /** @param  SessionInterface  $session */
    public function setSession(SessionInterface $session): void
    {
        $this->session = $session;
    }

    public function getCsrfToken(): ?string
    {
        return $this->post['csrf_token'] ?? null;
    }


    public function session(): SessionInterface
    {
        if (!isset($this->session)) {
            throw new \RuntimeException('Session has not been initialized.');
        }

        return $this->session;
    }

    /**
     * @return TemplateViewerInterface
     */
    public function viewer(): TemplateViewerInterface
    {
        return app(RadixTemplateViewer::class);
    }

    /**
     * Filtrera bort vissa fält (t.ex. CSRF, honeypot) ur en data‑array.
     *
     * @param array<string,mixed> $data
     * @param array<int,string>   $excludeKeys
     * @return array<string,mixed>
     */
    public function filterFields(array $data, array $excludeKeys = ['csrf_token', 'password_confirmation', 'honeypot']): array
    {
        // Använd array_diff_key för att ta bort specificerade nycklar
        return array_diff_key($data, array_flip($excludeKeys));
    }

    public function header(string $key, ?string $default = null): ?string
    {
        // Standardsätt att leta efter header
        $headerKey = 'HTTP_' . strtoupper(str_replace('-', '_', $key));
        if (array_key_exists($headerKey, $this->server)) {
            return $this->server[$headerKey];
        }

        // Sök i vanliga rubriker utan prefix
        $standardKey = ucfirst(strtolower(str_replace('-', '_', $key)));
        if (array_key_exists($standardKey, $this->server)) {
            return $this->server[$standardKey];
        }

        // Sista fallback för att hantera vissa CGI-miljöer eller headers som skickas konstigt
        if ($key === 'Authorization' && function_exists('getallheaders')) {
            $headers = getallheaders();
            if (!empty($headers['Authorization'])) {
                return $headers['Authorization'];
            }
        }

        return $default;
    }
}