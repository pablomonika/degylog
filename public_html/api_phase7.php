<?php
header('Content-Type: application/json');

$baseUrl = 'https://api.digylog.com/api/v2/seller/orders';
$token = '3b59372d9ae891113a75e7947f70c790a9b504aabbe05932f04495e19e114196';

function digylogRequest($url, $data, $token) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
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

// Test 1: Mode as array
$data1 = ['Mode' => ['status'], 'orders' => []];
$results[] = array_merge(['test' => 'Mode as array'], digylogRequest($baseUrl, $data1, $token));

// Test 2: Mode as object
$data2 = ['Mode' => ['action' => 'status'], 'orders' => []];
$results[] = array_merge(['test' => 'Mode as object'], digylogRequest($baseUrl, $data2, $token));

// Test 3: RequestMode instead of Mode
$data3 = ['RequestMode' => 'status', 'orders' => []];
$results[] = array_merge(['test' => 'RequestMode'], digylogRequest($baseUrl, $data3, $token));

// Test 4: OperationMode
$data4 = ['OperationMode' => 'status', 'orders' => []];
$results[] = array_merge(['test' => 'OperationMode'], digylogRequest($baseUrl, $data4, $token));

// Test 5: RequestType
$data5 = ['RequestType' => 'status', 'orders' => []];
$results[] = array_merge(['test' => 'RequestType'], digylogRequest($baseUrl, $data5, $token));

// Test 6: Mode with timestamp
$data6 = ['Mode' => 'status', 'timestamp' => time(), 'orders' => []];
$results[] = array_merge(['test' => 'Mode + timestamp'], digylogRequest($baseUrl, $data6, $token));

// Test 7: Mode with nonce
$data7 = ['Mode' => 'status', 'nonce' => uniqid(), 'orders' => []];
$results[] = array_merge(['test' => 'Mode + nonce'], digylogRequest($baseUrl, $data7, $token));

// Test 8: Mode with signature (MD5 hash)
$signature = md5($token . time());
$data8 = ['Mode' => 'status', 'signature' => $signature, 'orders' => []];
$results[] = array_merge(['test' => 'Mode + signature'], digylogRequest($baseUrl, $data8, $token));

// Test 9: Try webhook endpoint
$webhookUrl = str_replace('/seller/orders', '/seller/webhook', $baseUrl);
$data9 = ['event' => 'order.status', 'tracking' => '1743938475', 'status' => 'delivered'];
$results[] = array_merge(['test' => 'POST to /seller/webhook'], digylogRequest($webhookUrl, $data9, $token));

// Test 10: Try /seller/notifications endpoint
$notifUrl = str_replace('/seller/orders', '/seller/notifications', $baseUrl);
$results[] = array_merge(['test' => 'GET /seller/notifications'], digylogRequest($notifUrl, [], $token));

echo json_encode(['ok' => true, 'tests' => $results], JSON_PRETTY_PRINT);
