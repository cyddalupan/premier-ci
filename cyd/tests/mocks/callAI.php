<?php
function callXAI(array $messages, bool $web_search, bool $high_reasoning): array
{
    return [
        'choices' => [
            [
                'message' => [
                    'content' => 'This is a test response.'
                ]
            ]
        ]
    ];
}
