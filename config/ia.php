<?php

return [

    'provider' => env('IA_PROVIDER', 'minimax'),

    'minimax' => [
        'api_key'    => env('IA_API_KEY'),
        'base_url'   => env('IA_MINIMAX_BASE_URL', 'https://api.minimax.io'),
        'model'      => env('IA_MINIMAX_MODEL', 'MiniMax-M3'),
        'timeout'    => (int) env('IA_TIMEOUT', 60),
        'max_tokens' => (int) env('IA_MAX_TOKENS', 4096),
    ],

];