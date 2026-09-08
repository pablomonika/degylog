<?php
header('Content-Type: application/json');

$baseUrl = 'https://api.digylog.com/api/v2/seller/orders';
$token = '3b59372d9ae891113a75e7947f70c790a9b504aabbe05932f04495e19e114196';

function digylogRequest($url, $method, $data, $token) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    if ($data && $method !== 'GET') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json',
        'Accept: application/json'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return ['http' => $httpCode, 'response' => $response];
}

$results = [];

// Test 1: Mode in URL path - /orders/status
$url1 = $baseUrl . '/status';
$results[] = array_merge(['test' => 'POST /orders/status (Mode in path)'], digylogRequest($url1, 'POST', ['orders' => []], $token));

// Test 2: Mode in URL path - /orders/get
$url2 = $baseUrl . '/get';
$results[] = array_merge(['test' => 'POST /orders/get (Mode in path)'], digylogRequest($url2, 'POST', ['orders' => []], $token));

// Test 3: Mode in URL path - /orders/list
$url3 = $baseUrl . '/list';
$results[] = array_merge(['test' => 'POST /orders/list (Mode in path)'], digylogRequest($url3, 'POST', ['orders' => []], $token));

// Test 4: GET /seller/webhook (because POST returned 405)
$webhookUrl = str_replace('/seller/orders', '/seller/webhook', $baseUrl);
$results[] = array_merge(['test' => 'GET /seller/webhook'], digylogRequest($webhookUrl, 'GET', null, $token));

// Test 5: POST /seller/callback
$callbackUrl = str_replace('/seller/orders', '/seller/callback', $baseUrl);
$results[] = array_merge(['test' => 'POST /seller/callback'], digylogRequest($callbackUrl, 'POST', ['event' => 'test'], $token));

// Test 6: GET /seller/events
$eventsUrl = str_replace('/seller/orders', '/seller/events', $baseUrl);
$results[] = array_merge(['test' => 'GET /seller/events'], digylogRequest($eventsUrl, 'GET', null, $token));

// Test 7: POST /orders with Mode in URL path AND body
$url7 = $baseUrl . '/status';
$data7 = ['Mode' => 'status', 'orders' => []];
$results[] = array_merge(['test' => 'POST /orders/status with Mode in body'], digylogRequest($url7, 'POST', $data7, $token));

// Test 8: Try /seller/orders/status with GET
$results[] = array_merge(['test' => 'GET /orders/status'], digylogRequest($url1, 'GET', null, $token));

// Test 9: Try /seller/orders with tracking number in path
$url9 = $baseUrl . '/1743938475';
$results[] = array_merge(['test' => 'GET /orders/{tracking}'], digylogRequest($url9, 'GET', null, $token));

// Test 10: Try /seller/orders/status/{tracking}
$url10 = $baseUrl . '/status/1743938475';
$results[] = array_merge(['test' => 'GET /orders/status/{tracking}'], digylogRequest($url10, 'GET', null, $token));

echo json_encode(['ok' => true, 'tests' => $results], JSON_PRETTY_PRINT);
