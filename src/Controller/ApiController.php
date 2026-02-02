<?php

declare(strict_types=1);

namespace Radix\Controller;

use Radix\Http\JsonResponse;
use Radix\Support\Validator;
use RuntimeException;

abstract class ApiController extends AbstractController
{
    /**
     * Skapa ett JsonResponse‑objekt från en array.
     *
     * @param array<string, mixed> $data
     */
    protected function json(array $data, int $status = 200): JsonResponse
    {
        $response = new JsonResponse();

        $body = json_encode($data);
        if ($body === false) {
            // Här kan du logga felet om du vill
            throw new RuntimeException('Failed to encode response body to JSON.');
        }

        $response->setStatusCode($status)
            ->setHeader('Content-Type', 'application/json')
            ->setBody($body);

        return $response;
    }

    /**
     * Hämta och dekoda JSON‑body som assoc‑array.
     *
     * Returnerar tom array för GET/HEAD/DELETE.
     *
     * @return array<string, mixed>
     */
    protected function getJsonPayload(): array
    {
        // Hoppa över JSON-hantering för GET, HEAD och DELETE
        if (in_array($this->request->method, ['GET', 'HEAD', 'DELETE'], true)) {
            return [];
        }

        $rawBody = file_get_contents('php://input');

        if ($rawBody === false) {
            $this->respondWithBadRequest('Unable to read request body.');
            return []; // För statisk analys – körs aldrig efter respondWithBadRequest()
        }

        /** @var string $rawBody */
        $inputData = json_decode($rawBody, true);

        // Om JSON är ogiltig, skicka ett 400-fel
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($inputData)) {
            $this->respondWithBadRequest('Invalid or missing JSON in the request body.');
            return []; // når i praktiken inte hit pga exit i respondWithBadRequest()
        }

        /** @var array<string, mixed> $inputData */
        return $inputData;
    }

    /**
     * Validera inkommande request mot givna regler och API-token.
     *
     * @param array<string, array<int, string>|string> $rules
     */
    protected function validateRequest(array $rules = []): void
    {
        $this->request->post = $this->getJsonPayload();
        if (!empty($rules)) {
            $validator = new Validator($this->request->post, $rules);
            if (!$validator->validate()) {
                $this->respondWithErrors($validator->errors(), 422);
            }
        }
        // Strikt token som standard
        $this->validateApiToken();
    }

    /**
     * Validera request där session ELLER Bearer-token accepteras.
     *
     * @param array<string, array<int, string>|string> $rules
     */
    protected function validateRequestAllowingSession(array $rules = []): void
    {
        $this->request->post = $this->getJsonPayload();
        if (!empty($rules)) {
            /** @var array<string, array<int, string>|string> $rules */
            $validator = new Validator($this->request->post, $rules);
            if (!$validator->validate()) {
                $this->respondWithErrors($validator->errors(), 422);
            }
        }
        $this->validateApiTokenOrSession();
    }

    /**
     * Acceptera antingen giltig Bearer-token, eller en aktiv autentiserad session.
     */
    private function validateApiTokenOrSession(): void
    {
        $apiToken = $this->request->header('Authorization');

        // Ta bort "Bearer "
        if (!empty($apiToken) && str_starts_with($apiToken, 'Bearer ')) {
            $apiToken = str_replace('Bearer ', '', $apiToken);
        }

        // Finns header -> kör befintlig tokenvalidering
        if (!empty($apiToken)) {
            $token = (string) $apiToken;
            if (!$this->isTokenValid($token)) {
                $this->respondWithErrors(['API-token' => ['Token är ogiltig eller valideringen misslyckades.']], 401);
            }
            return;
        }

        // Ingen Authorization-header -> tillåt om sessionen är inloggad
        $session = $this->request->session();
        $authId = $session->get(\Radix\Session\Session::AUTH_KEY);

        if (is_int($authId) || is_string($authId)) {
            return; // inloggad session godkänns
        }

        $this->respondWithErrors(['API-token' => ['Token saknas eller är ogiltig.']], 401);
    }

    /**
     * Validera API-token från förfrågan.
     */
    private function validateApiToken(): void
    {
        $apiToken = $this->request->header('Authorization');

        // Ta bort "Bearer "
        if (!empty($apiToken) && str_starts_with($apiToken, 'Bearer ')) {
            $apiToken = str_replace('Bearer ', '', $apiToken);
        }

        if (empty($apiToken)) {
            $this->respondWithErrors(['API-token' => ['Token saknas eller är ogiltig.']], 401);
            return; // för PHPStan: exekveringen fortsätter inte
        }

        // Gör token till ren sträng
        $token = (string) $apiToken;

        if (!$this->isTokenValid($token)) {
            $this->respondWithErrors(['API-token' => ['Token är ogiltig eller valideringen misslyckades.']], 401);
        }
    }

    /**
     * Kontrollera om en token är giltig.
     *
     * OBS: Framework får inte bero på applikationslagret.
     * Appen kan override:a detta i sin egen controller för DB-token.
     */
    protected function isTokenValid(string $token): bool
    {
        $this->cleanupExpiredTokens();

        $validToken = getenv('API_TOKEN');
        return $validToken !== false && $token === $validToken;
    }

    /**
     * Rensa utgångna tokens.
     *
     * Framework-versionen är no-op.
     * Appen override:ar om den vill städa tokens i DB.
     */
    protected function cleanupExpiredTokens(): void {}

    /**
     * Returnera felmeddelande för dålig förfrågan (400).
     */
    protected function respondWithBadRequest(string $message): void
    {
        $this->respondWithErrors(['Request' => [$message]], 400);
    }

    /**
     * @param array<string, string|array<int, string>> $errors
     */
    protected function respondWithErrors(array $errors, int $status = 422): void
    {
        $formattedErrors = [];
        foreach ($errors as $field => $messages) {
            $formattedErrors[] = [
                'field' => $field,
                'messages' => $messages,
            ];
        }

        $body = [
            'success' => false,
            'errors' => $formattedErrors,
        ];

        $this->json($body, $status)->send();
        exit; // Säkerställ att exekveringen avslutas
    }
}
