<?php

declare(strict_types=1);

namespace Radix\Controller;

use App\Models\Token;
use Radix\Http\JsonResponse;
use Radix\Support\Validator;

abstract class ApiController extends AbstractController
{
    /**
     * Returnera JSON-svar.
     */
    protected function json(array $data, int $status = 200): JsonResponse
    {
        $response = new JsonResponse();
        $response->setStatusCode($status)
            ->setHeader('Content-Type', 'application/json')
            ->setBody(json_encode($data));

        return $response;
    }

    /**
     * Kontrollera och hämta JSON från förfrågan.
     * Returnerar en korrekt parsad JSON-array eller kastar ett fel.
     */
    protected function getJsonPayload(): array
    {
        // Hoppa över JSON-hantering för GET, HEAD och DELETE
        if (in_array($this->request->method, ['GET', 'HEAD', 'DELETE'], true)) {
            return [];
        }

        $inputData = json_decode(file_get_contents('php://input'), true);

        // Om JSON är ogiltig, skicka ett 400-fel
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($inputData)) {
            $this->respondWithBadRequest('Invalid or missing JSON in the request body.');
        }

        return $inputData;
    }

    /**
     * Validera inkommande förfrågan med regler och API-token.
     */
    protected function validateRequest(array $rules = []): void
    {
        // Validera JSON-data först
        $this->request->post = $this->getJsonPayload();

        // Validera regler om några skickats
        if (!empty($rules)) {
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
        }

        if (!$this->isTokenValid($apiToken)) {
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
        $existingToken = Token::query()->where('value', '=', $token)->first();

        if (!$existingToken || strtotime($existingToken->expires_at) < time()) {
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
     * Returnera fel.
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