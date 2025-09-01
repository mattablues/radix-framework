<?php

declare(strict_types=1);

namespace Radix\Http;

use Radix\Session\SessionInterface;
use Radix\Viewer\RadixTemplateViewer;

class Request implements RequestInterface
{
    private SessionInterface $session;

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

    public static function createFromGlobals(): static
    {
        $request = new static(
            $_SERVER['REQUEST_URI'],
            $_SERVER['REQUEST_METHOD'],
            $_GET,
            $_POST,
            $_FILES,
            $_COOKIE,
            $_SERVER,
        );

        // Tilldela en session
        $session = app(\Radix\Session\SessionInterface::class); // Hämta session via DI
        $request->setSession($session);

        return $request;
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

    public function setSession($session): void
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

    public function viewer()
    {
        return app(RadixTemplateViewer::class);
    }

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