<?php
header('Content-Type: application/json');

// Configuration
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

// Test 1: Mode inside each order object
$data1 = [
    'orders' => [
        ['Mode' => 'status', 'tracking' => 'CMD001']
    ]
];
$results[] = array_merge(['test' => 'Mode inside order object'], digylogRequest($baseUrl, $data1, $token));

// Test 2: Mode as numeric value (1)
$data2 = ['Mode' => 1, 'orders' => []];
$results[] = array_merge(['test' => 'Mode = 1 (numeric)'], digylogRequest($baseUrl, $data2, $token));

// Test 3: Mode as numeric value (2)
$data3 = ['Mode' => 2, 'orders' => []];
$results[] = array_merge(['test' => 'Mode = 2 (numeric)'], digylogRequest($baseUrl, $data3, $token));

// Test 4: Mode as numeric value (0)
$data4 = ['Mode' => 0, 'orders' => []];
$results[] = array_merge(['test' => 'Mode = 0 (numeric)'], digylogRequest($baseUrl, $data4, $token));

// Test 5: Mode with network_id
$data5 = ['Mode' => 'status', 'network_id' => 29784, 'orders' => []];
$results[] = array_merge(['test' => 'Mode + network_id'], digylogRequest($baseUrl, $data5, $token));

// Test 6: Mode with store_id
$data6 = ['Mode' => 'status', 'store_id' => 32739, 'orders' => []];
$results[] = array_merge(['test' => 'Mode + store_id'], digylogRequest($baseUrl, $data6, $token));

// Test 7: Mode with real tracking number from CRM
$data7 = ['Mode' => 'status', 'orders' => ['1743938475']];
$results[] = array_merge(['test' => 'Mode + real tracking number'], digylogRequest($baseUrl, $data7, $token));

// Test 8: Empty Mode (just to see if it accepts empty string)
$data8 = ['Mode' => '', 'orders' => []];
$results[] = array_merge(['test' => 'Mode = "" (empty string)'], digylogRequest($baseUrl, $data8, $token));

// Test 9: Mode with network and store
$data9 = ['Mode' => 'status', 'network' => 29784, 'store' => 32739, 'orders' => []];
$results[] = array_merge(['test' => 'Mode + network + store'], digylogRequest($baseUrl, $data9, $token));

// Test 10: All fields together
$data10 = [
    'Mode' => 'status',
    'network' => 29784,
    'store' => 32739,
    'orders' => ['1743938475'],
    'limit' => 100
];
$results[] = array_merge(['test' => 'All fields together'], digylogRequest($baseUrl, $data10, $token));

echo json_encode(['ok' => true, 'tests' => $results], JSON_PRETTY_PRINT);
