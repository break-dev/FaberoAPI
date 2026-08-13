<?php

namespace App\Services;

use App\Shared\IA\Contracts\AIProviderInterface;
use App\Shared\IA\DTO\AIChatRequest;
use App\Shared\IA\DTO\AIChatResponse;
use App\Shared\IA\Exceptions\AIProviderException;
use App\Shared\IA\Providers\MinimaxProvider;

class IAService
{
    public static function chat(AIChatRequest $request): AIChatResponse
    {
        $provider = self::resolveProvider();
        return $provider->chat($request);
    }

    private static function resolveProvider(): AIProviderInterface
    {
        $name = (string) config('ia.provider', 'minimax');

        return match ($name) {
            'minimax' => new MinimaxProvider(),
            default   => throw new AIProviderException(
                "Proveedor de IA no soportado: {$name}",
                null,
                'unsupported_provider',
                retryable: false,
            ),
        };
    }
}