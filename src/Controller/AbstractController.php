<?php

declare(strict_types=1);

namespace Radix\Controller;

use Exception;
use Radix\Http\Request;
use Radix\Http\Response;
use Radix\Session\Exception\CsrfTokenInvalidException;
use Radix\Viewer\TemplateViewerInterface;

abstract class AbstractController
{
    protected Request $request;
    protected TemplateViewerInterface $viewer;

    protected Response $response;

    public function setResponse(Response $response): void
    {
        $this->response = $response;
    }

    public function setRequest(Request $request): void
    {
        $this->request = $request;
    }

    public function setViewer(TemplateViewerInterface $viewer): void
    {
        $this->viewer = $viewer;
    }

    /**
     * Rendera en vy och returnera ett Response-objekt.
     *
     * @param array<string, mixed> $data
     */
    protected function view(string $template, array $data = []): Response
    {
        // Kontrollera om det finns en `filters()`-metod i den aktuella kontrollern
        if (method_exists($this, 'filters')) {
            /** @var array<string, array{callback: callable(mixed): mixed, type?: string}> $filters */
            $filters = $this->filters();
            $this->registerFilters($filters);
        }

        $defaultData = [
            'errors' => [], // Standardvärde för errors
        ];

        // Slå samman standarddata och indata
        $data = array_merge($defaultData, $data);

        // Rendera templatet med filtrerad data
        $this->response->setBody($this->viewer->render($template, $data));
        $this->response->setStatusCode(200);

        return $this->response;
    }

    /**
     * Registrera template-filters i viewern.
     *
     * @param array<string, array{
     *     callback: callable(mixed): mixed,
     *     type?: string
     * }> $filters
     */
    protected function registerFilters(array $filters): void
    {
        foreach ($filters as $name => $callback) {
            // Använd fallback-typen "string" om ingen specifik typ anges
            $type = $callback['type'] ?? 'string';
            // Registrera själva filtret
            $this->viewer->registerFilter($name, $callback['callback'], $type);
        }
    }

    /**
     * Körs innan alla actions i en controller
     */
    protected function before(): void
    {
        // Validera CSRF-token (görs för alla POST, PUT, DELETE)
        if (in_array($this->request->method, ['POST', 'PUT', 'DELETE'], true)) {
            $this->validateRequest();
        }

        // Ytterligare valideringar kan placeras här, t.ex. Autentisering
    }

    /**
     * Validera inkommande request mot givna regler och API-token.
     *
     * @param array<string, mixed> $rules  Valideringsregler per fält.
     */
    protected function validateRequest(array $rules = []): void
    {
        $formToken = $this->request->getCsrfToken();
        $sessionToken = $this->request->session()->get('csrf_token');

        if (!$sessionToken) {
            $this->request->session()->setCsrfToken(); // Generera en ny token om ingen existerar
            throw new CsrfTokenInvalidException("CSRF-token saknas, ladda om sidan.");
        }

        try {
            $this->request->session()->validateCsrfToken($formToken);
        } catch (Exception $e) {
            throw $e;
        }
    }
}
