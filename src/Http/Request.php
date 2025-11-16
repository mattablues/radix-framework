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
    ) {
    }

    public static function createFromGlobals(): self
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        if (!is_string($uri) || $uri === '') {
            $uri = '/';
        }

        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if (!is_string($method) || $method === '') {
            $method = 'GET';
        }

        /** @var array<string,mixed> $get */
        $get = $_GET;
        /** @var array<string,mixed> $post */
        $post = $_POST;
        /** @var array<string,mixed> $files */
        $files = $_FILES;
        /** @var array<string,mixed> $cookie */
        $cookie = $_COOKIE;
        /** @var array<string,mixed> $server */
        $server = $_SERVER;

        return new self(
            $uri,
            $method,
            $get,
            $post,
            $files,
            $cookie,
            $server,
        );
    }

    public function fullUrl(): string
    {
        $scheme = (!empty($this->server['HTTPS']) && $this->server['HTTPS'] !== 'off') ? 'https' : 'http';

        $host = $this->server['HTTP_HOST'] ?? ($this->server['SERVER_NAME'] ?? 'localhost');
        if (!is_string($host) || $host === '') {
            $host = 'localhost';
        }

        $uri = $this->server['REQUEST_URI'] ?? '/';
        if (!is_string($uri) || $uri === '') {
            $uri = '/';
        }

        return $scheme . '://' . $host . $uri;
    }

    public function ip(): string
    {
        // Lista över betrodda proxy-klienter
        $trustedProxies = ['192.168.0.1', '10.0.0.1'];

        // Kontrollera om klientens IP finns i X_FORWARDED_FOR
        $forwardedFor = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;
        if (is_string($forwardedFor) && $forwardedFor !== '') {
            $ipList = explode(',', $forwardedFor);
            $ip = trim($ipList[0]); // Returnerar den första IP-adressen i listan

            $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? null;
            if (
                is_string($remoteAddr)
                && in_array($remoteAddr, $trustedProxies, true)
                && filter_var($ip, FILTER_VALIDATE_IP) !== false
            ) {
                return $ip;
            }
        }

        // Kontrollera om en proxy-klient skickade klientens IP
        $clientIp = $_SERVER['HTTP_CLIENT_IP'] ?? null;
        if (
            is_string($clientIp)
            && $clientIp !== ''
            && filter_var($clientIp, FILTER_VALIDATE_IP) !== false
        ) {
            return $clientIp;
        }

        // Direkt IP från klienten
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? null;
        if (
            is_string($remoteAddr)
            && $remoteAddr !== ''
            && filter_var($remoteAddr, FILTER_VALIDATE_IP) !== false
        ) {
            return $remoteAddr;
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
        $token = $this->post['csrf_token'] ?? null;

        if (!is_string($token)) {
            return null;
        }

        return $token;
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
        $viewer = app(RadixTemplateViewer::class);

        if (!$viewer instanceof TemplateViewerInterface) {
            throw new \RuntimeException('Viewer resolver returned invalid instance.');
        }

        return $viewer;
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
            $value = $this->server[$headerKey];
            if (is_string($value)) {
                return $value;
            }
        }

        // Sök i vanliga rubriker utan prefix
        $standardKey = ucfirst(strtolower(str_replace('-', '_', $key)));
        if (array_key_exists($standardKey, $this->server)) {
            $value = $this->server[$standardKey];
            if (is_string($value)) {
                return $value;
            }
        }

        // Sista fallback för att hantera vissa CGI-miljöer eller headers som skickas konstigt
        if ($key === 'Authorization' && function_exists('getallheaders')) {
            $headers = getallheaders();
            if (array_key_exists('Authorization', $headers)) {
                $auth = $headers['Authorization'];
                if (is_string($auth)) {
                    return $auth;
                }
            }
        }

        return $default;
    }
}