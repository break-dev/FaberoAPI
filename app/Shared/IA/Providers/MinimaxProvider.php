<?php

namespace App\Shared\IA\Providers;

use App\Shared\IA\Contracts\AIProviderInterface;
use App\Shared\IA\DTO\AIAttachment;
use App\Shared\IA\DTO\AIChatRequest;
use App\Shared\IA\DTO\AIChatResponse;
use App\Shared\IA\Exceptions\AIProviderException;
use App\Shared\IA\Exceptions\AISchemaValidationException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MinimaxProvider implements AIProviderInterface
{
    public function chat(AIChatRequest $request): AIChatResponse
    {
        $apiKey = (string) config('ia.minimax.api_key', '');
        if ($apiKey === '') {
            throw new AIProviderException('IA_API_KEY no configurada.', null, 'missing_api_key', retryable: false);
        }

        $baseUrl = rtrim((string) config('ia.minimax.base_url', 'https://api.minimax.io'), '/');
        $url = $baseUrl.'/v1/chat/completions';
        $model = (string) config('ia.minimax.model', 'MiniMax-M3');
        $timeout = (int) config('ia.minimax.timeout', 60);
        $defaultMaxTokens = (int) config('ia.minimax.max_tokens', 4096);

        $payload = $this->buildPayload($request, $model, $defaultMaxTokens);

        $startedAt = microtime(true);

        try {
            $response = Http::timeout($timeout)
                ->withToken($apiKey)
                ->acceptJson()
                ->asJson()
                ->post($url, $payload);
        } catch (\Throwable $e) {
            $latency = (int) ((microtime(true) - $startedAt) * 1000);
            Log::error('ia.request.failed', [
                'provider'    => 'minimax',
                'model'       => $model,
                'latency_ms'  => $latency,
                'status'      => null,
                'error_code'  => 'network_exception',
                'message'     => $e->getMessage(),
            ]);

            throw new AIProviderException(
                'IA no disponible: error de red con el proveedor.',
                null,
                'network_exception',
                retryable: true,
            );
        }

        $latency = (int) ((microtime(true) - $startedAt) * 1000);

        if (! $response->successful()) {
            $status = $response->status();
            $errorCode = null;
            $message = 'IA no disponible.';

            $body = $response->json();
            if (is_array($body)) {
                $errorCode = $body['base_resp']['status_code'] ?? null;
                $message = $body['base_resp']['status_msg'] ?? $body['message'] ?? $message;
            }

            Log::error('ia.request.failed', [
                'provider'   => 'minimax',
                'model'      => $model,
                'latency_ms' => $latency,
                'status'     => $status,
                'error_code' => $errorCode,
            ]);

            throw new AIProviderException(
                $this->humanizeProviderError($status, $message),
                $status,
                $errorCode !== null ? (string) $errorCode : null,
                retryable: $status >= 500 || $status === 429,
            );
        }

        $body = $response->json();
        $parsed = $this->parseResponseBody($body, $request->schema !== null);

        Log::info('ia.request', [
            'provider'    => 'minimax',
            'model'       => $model,
            'latency_ms'  => $latency,
            'tokens_total' => $body['usage']['total_tokens'] ?? null,
        ]);

        return new AIChatResponse(
            text: $parsed['text'],
            structured: $parsed['structured'],
            usage: $this->extractUsage($body),
            raw: is_array($body) ? $body : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(AIChatRequest $request, string $model, int $defaultMaxTokens): array
    {
        $messages = [];
        foreach ($request->messages as $msg) {
            $messages[] = $this->serializeMessage($msg);
        }

        $payload = [
            'model'    => $model,
            'messages' => $messages,
            'thinking' => ['type' => 'disabled'],
        ];

        if ($request->maxTokens !== null) {
            $payload['max_completion_tokens'] = $request->maxTokens;
        } else {
            $payload['max_completion_tokens'] = $defaultMaxTokens;
        }

        if ($request->temperature !== null) {
            $payload['temperature'] = $request->temperature;
        }

        if ($request->schema !== null) {
            $payload['tools'] = [[
                'type'     => 'function',
                'function' => [
                    'name'        => $request->schema->name,
                    'description' => $request->schema->description ?? '',
                    'parameters'  => $request->schema->parameters,
                ],
            ]];
            $payload['tool_choice'] = 'required';
        }

        return $payload;
    }

    private function serializeMessage(\App\Shared\IA\DTO\AIMessage $msg): array
    {
        // Mensajes de solo texto: enviar `content` como string (formato nativo OpenAI).
        if ($msg->attachments === []) {
            return [
                'role'    => $msg->role,
                'content' => $msg->content,
            ];
        }

        $parts = [];

        if ($msg->content !== '') {
            $parts[] = ['type' => 'text', 'text' => $msg->content];
        }

        foreach ($msg->attachments as $att) {
            $parts[] = $this->serializeAttachment($att);
        }

        if ($parts === []) {
            $parts[] = ['type' => 'text', 'text' => ''];
        }

        return [
            'role'    => $msg->role,
            'content' => $parts,
        ];
    }

    private function serializeAttachment(AIAttachment $att): array
    {
        if ($att->url !== null && $att->url !== '') {
            return [
                'type'      => 'input_image',
                'image_url' => ['url' => $att->url],
            ];
        }

        $dataUri = 'data:'.$att->mime.';base64,'.$att->base64;

        return [
            'type'      => 'input_image',
            'image_url' => ['url' => $dataUri],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @return array{text: ?string, structured: mixed}
     */
    private function parseResponseBody(?array $body, bool $schemaRequired): array
    {
        if ($body === null) {
            throw new AIProviderException('IA devolvió una respuesta vacía.', null, 'empty_body', retryable: true);
        }

        $choice = $body['choices'][0] ?? null;
        if (! is_array($choice)) {
            throw new AIProviderException('IA devolvió una respuesta sin choices.', null, 'no_choices', retryable: true);
        }

        $message = $choice['message'] ?? [];
        $finishReason = $choice['finish_reason'] ?? null;

        // Content filter
        if ($finishReason === 'content_filter' || ! empty($body['output_sensitive'])) {
            throw new AIProviderException(
                'La IA rechazó la solicitud por contenido sensible.',
                null,
                'content_filter',
                retryable: false,
            );
        }

        // Salida estructurada vía tool_calls
        if (isset($message['tool_calls']) && is_array($message['tool_calls']) && $message['tool_calls'] !== []) {
            $first = $message['tool_calls'][0];
            $args = $first['function']['arguments'] ?? null;
            if (is_string($args) && $args !== '') {
                $decoded = json_decode($args, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    return ['text' => null, 'structured' => $decoded];
                }
            }

            if ($schemaRequired) {
                throw new AISchemaValidationException(
                    'La IA no devolvió argumentos estructurados válidos.'
                );
            }
        }

        // Salida de texto
        $text = $message['content'] ?? null;
        if (is_string($text) && $text !== '') {
            return ['text' => $text, 'structured' => null];
        }

        if ($schemaRequired) {
            throw new AISchemaValidationException(
                'La IA no devolvió una respuesta estructurada.'
            );
        }

        return ['text' => null, 'structured' => null];
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @return array<string, int>|null
     */
    private function extractUsage(?array $body): ?array
    {
        $usage = $body['usage'] ?? null;
        if (! is_array($usage)) {
            return null;
        }

        return [
            'input'  => (int) ($usage['prompt_tokens'] ?? 0),
            'output' => (int) ($usage['completion_tokens'] ?? 0),
            'total'  => (int) ($usage['total_tokens'] ?? 0),
        ];
    }

    private function humanizeProviderError(int $status, string $upstreamMessage): string
    {
        return match (true) {
            $status === 401, $status === 403 => 'Error interno de IA.',
            $status === 429                  => 'Demasiadas solicitudes a la IA. Intenta en unos segundos.',
            $status >= 500                   => 'IA no disponible, intenta luego.',
            default                          => 'Error al comunicarse con la IA.',
        };
    }
}