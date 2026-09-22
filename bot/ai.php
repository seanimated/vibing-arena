<?php

function analyzeTender($text){

    $apiKey = "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";

    $data = [
        "model" => "gpt-4.1-mini",
        "messages" => [
            [
                "role" => "system",
                "content" => "Extract tender information and return only valid JSON."
            ],
            [
                "role" => "user",
                "content" => $text
            ]
        ]
    ];

    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, "https://api.openai.com/v1/chat/completions");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json",
        "Authorization: Bearer " . $apiKey
    ]);

    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

    $response = curl_exec($ch);

    curl_close($ch);

    return json_decode($response, true);
}
?>
