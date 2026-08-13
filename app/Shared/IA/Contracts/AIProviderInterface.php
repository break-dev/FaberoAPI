<?php

namespace App\Shared\IA\Contracts;

use App\Shared\IA\DTO\AIChatRequest;
use App\Shared\IA\DTO\AIChatResponse;

interface AIProviderInterface
{
    public function chat(AIChatRequest $request): AIChatResponse;
}