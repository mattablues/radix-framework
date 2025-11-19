<?php

declare(strict_types=1);

namespace Radix\Http;

use Closure;
use Radix\Controller\AbstractController;
use Radix\EventDispatcher\EventDispatcher;
use Radix\Http\Event\ResponseEvent;

readonly class RequestHandler implements RequestHandlerInterface
{
    /**
     * @param array<int|string,mixed> $args
     */
    public function __construct(
        private Closure|AbstractController $handler,
        private EventDispatcher $eventDispatcher,
        private array $args,
        private ?string $action = null
    ) {}

    public function handle(Request $request): Response
    {
        // Kontrollera CSRF-validering för standardformulär (ej API-anrop)
        if (in_array($request->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) && !$this->isApiRequest($request)) {
            $session = $request->session();
            $csrfToken = $request->getCsrfToken();
            $session->validateCsrfToken($csrfToken);
        }

        // Hantera API eller vanliga rutter
        if (is_callable($this->handler)) {
            $response = call_user_func_array($this->handler, $this->args);
        } else {
            $this->handler->setRequest($request);
            $response = ($this->handler)->{$this->action}(...$this->args);
        }

        // Kontrollera om vi har en giltig respons
        if ($response instanceof Response) {
            $this->eventDispatcher->dispatch(new ResponseEvent($request, $response));

            return $response;
        }

        // Returnera en tom respons som fallback
        return new Response();
    }

    private function isApiRequest(Request $request): bool
    {
        // Kontrollera om URI har korrekt struktur med /api/v<number>
        return preg_match('#^/api/v\d+(/|$)#', $request->uri) === 1;
    }
}
