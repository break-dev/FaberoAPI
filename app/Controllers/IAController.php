<?php

namespace App\Controllers;

use App\Services\IAService;
use App\Shared\IA\DTO\AIChatRequest;
use App\Shared\IA\Exceptions\AIProviderException;
use App\Shared\IA\Exceptions\AISchemaValidationException;
use App\Shared\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class IAController extends Controller
{
    public function chat(Request $request): JsonResponse
    {
        try {
            $data = $this->validatePayload($request, requireSchema: false);
        } catch (ValidationException $e) {
            return response()->json(ApiResponse::error('Datos inválidos.', $e->errors()), 422);
        }

        try {
            $response = IAService::chat(AIChatRequest::fromArray($data));
        } catch (AISchemaValidationException $e) {
            return response()->json(ApiResponse::error($e->getMessage()), 422);
        } catch (AIProviderException $e) {
            return $this->providerErrorResponse($e);
        }

        return response()->json(ApiResponse::success($this->normalizeResponse($response), 'OK'));
    }

    public function chatStructured(Request $request): JsonResponse
    {
        try {
            $data = $this->validatePayload($request, requireSchema: true);
        } catch (ValidationException $e) {
            return response()->json(ApiResponse::error('Datos inválidos.', $e->errors()), 422);
        }

        try {
            $response = IAService::chat(AIChatRequest::fromArray($data));
        } catch (AISchemaValidationException $e) {
            return response()->json(ApiResponse::error($e->getMessage()), 422);
        } catch (AIProviderException $e) {
            return $this->providerErrorResponse($e);
        }

        return response()->json(ApiResponse::success($this->normalizeResponse($response), 'OK'));
    }

    public function analyzeFile(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'archivos' => 'required|array|min:1|max:10',
                'archivos.*' => 'file|mimes:jpg,jpeg,png,webp|max:8192',
                'prompt' => 'required|string|max:8000',
                'schema' => 'sometimes',
                'temperature' => 'sometimes|numeric|min:0|max:2',
            ]);
        } catch (ValidationException $e) {
            return response()->json(ApiResponse::error('Datos inválidos.', $e->errors()), 422);
        }

        $schemaRaw = $request->input('schema');
        $schema = null;
        if (is_string($schemaRaw) && $schemaRaw !== '') {
            $decoded = json_decode($schemaRaw, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                return response()->json(ApiResponse::error('Schema inválido.'), 422);
            }
            $schema = $decoded;
        }

        $attachments = [];
        foreach ($request->file('archivos') as $file) {
            $attachments[] = [
                'mime' => $file->getMimeType() ?: 'image/jpeg',
                'base64' => base64_encode($file->get()),
            ];
        }

        $payload = [
            'messages' => [
                [
                    'role' => 'user',
                    'content' => (string) $request->input('prompt'),
                    'attachments' => $attachments,
                ]
            ],
        ];

        if ($schema !== null) {
            $payload['schema'] = $schema;
        }
        if ($request->filled('temperature')) {
            $payload['temperature'] = (float) $request->input('temperature');
        }

        try {
            $response = IAService::chat(AIChatRequest::fromArray($payload));
        } catch (AISchemaValidationException $e) {
            return response()->json(ApiResponse::error($e->getMessage()), 422);
        } catch (AIProviderException $e) {
            return $this->providerErrorResponse($e);
        }

        return response()->json(ApiResponse::success($this->normalizeResponse($response), 'OK'));
    }

    public function health(Request $request): JsonResponse
    {
        $payload = [
            'messages' => [
                [
                    'role' => 'user',
                    'content' => 'Responde únicamente con la palabra: pong',
                ]
            ],
            'maxTokens' => 16,
        ];

        try {
            $response = IAService::chat(AIChatRequest::fromArray($payload));
        } catch (AIProviderException $e) {
            return response()->json(ApiResponse::error('IA no disponible.', [
                'status' => $e->statusCode,
            ]), 503);
        }

        return response()->json(ApiResponse::success([
            'ok' => true,
        ], 'IA disponible'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request, bool $requireSchema): array
    {
        $rules = [
            'messages' => 'required|array|min:1',
            'messages.*.role' => 'required|in:system,user,assistant',
            'messages.*.content' => 'required|string',
            'messages.*.attachments' => 'sometimes|array',
            'messages.*.attachments.*.mime' => 'required_with:messages.*.attachments|string',
            'messages.*.attachments.*.base64' => 'required_without:messages.*.attachments.*.url|string',
            'messages.*.attachments.*.url' => 'required_without:messages.*.attachments.*.base64|string',
            'temperature' => 'sometimes|numeric|min:0|max:2',
            'maxTokens' => 'sometimes|integer|min:1|max:524288',
        ];

        if ($requireSchema) {
            $rules['schema'] = 'required|array';
            $rules['schema.name'] = 'required|string|max:64';
            $rules['schema.description'] = 'sometimes|string|max:1000';
            $rules['schema.parameters'] = 'required|array';
            $rules['schema.parameters.type'] = 'required|in:object';
        } else {
            $rules['schema'] = 'sometimes|array';
        }

        return $request->validate($rules);
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeResponse(\App\Shared\IA\DTO\AIChatResponse $response): array
    {
        return [
            'text' => $response->text,
            'structured' => $response->structured,
            'usage' => $response->usage,
        ];
    }

    private function providerErrorResponse(AIProviderException $e): JsonResponse
    {
        Log::warning('ia.endpoint.error', [
            'message' => $e->getMessage(),
            'status' => $e->statusCode,
            'error_code' => $e->errorCode,
            'retryable' => $e->retryable,
        ]);

        return response()->json(ApiResponse::error($e->getMessage()), 500);
    }
}