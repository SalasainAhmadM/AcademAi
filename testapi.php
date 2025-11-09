<?php
$urls = [
    'https://academia25.pythonanywhere.com/analyze',
    'https://academia25.pythonanywhere.com/check_plagiarism',
    'https://academia25.pythonanywhere.com/compare',
    'https://academia25.pythonanywhere.com/benchmark'
];

foreach ($urls as $url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    // Use POST instead of GET
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['test' => 'ping']));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json'
    ]);

    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    echo "$url - Status: $httpCode\n";
    curl_close($ch);
}
?>