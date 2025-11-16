<?php

declare(strict_types=1);

namespace Radix\Controller;

use App\Models\Token;
use Radix\Http\JsonResponse;
use Radix\Support\Validator;

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
            throw new \RuntimeException('Failed to encode response body to JSON.');
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
        // Validera JSON-data först
        $this->request->post = $this->getJsonPayload();

        // Validera regler om några skickats
        if (!empty($rules)) {
            /** @var array<string, array<int, string>|string> $rules */
            $validator = new Validator($this->request->post, $rules);

            if (!$validator->validate()) {
                $this->respondWithErrors($validator->errors(), 422);
            }
        }

        // Kontrollera API-token
        $this->validateApiToken();
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
        $token = (string)$apiToken;

        if (!$this->isTokenValid($token)) {
            $this->respondWithErrors(['API-token' => ['Token är ogiltig eller valideringen misslyckades.']], 401);
        }
    }

    /**
     * Kontrollera om en token är giltig.
     */
    private function isTokenValid(string $token): bool
    {
        // Rensa utgångna tokens
        $this->cleanupExpiredTokens();

        // Kontrollera mot miljövariabel eller databasen
        $validToken = getenv('API_TOKEN');
        if ($token === $validToken) {
            return true;
        }

        // Kontrollera token i databasen
        /** @var \App\Models\Token|null $existingToken */
        $existingToken = Token::query()->where('value', '=', $token)->first();

        if (!$existingToken) {
            return false;
        }

        // Säkerställ att vi skickar en ren sträng till strtotime
        $expiresAt = (string)$existingToken->expires_at;
        if ($expiresAt === '' || strtotime($expiresAt) < time()) {
            return false;
        }

        return true;
    }

    /**
     * Rensa utgångna tokens från databasen.
     */
    private function cleanupExpiredTokens(): void
    {
        Token::query()->where('expires_at', '<', date('Y-m-d H:i:s'))->delete()->execute();
    }

    /**
     * Returnera felmeddelande för dålig förfrågan (400).
     */
    protected function respondWithBadRequest(string $message): void
    {
        $this->respondWithErrors(['Request' => [$message]], 400);
    }

    /**
     * Skicka validerings-/API-fel som standardiserat JSON-svar.
     *
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