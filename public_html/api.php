<?php
// Simple, working API with merge-based sync
// Secret: c6e04cb5de9088be01a685abc243995a80426eba45de2060

$SECRET = 'c6e04cb5de9088be01a685abc243995a80426eba45de2060';
$DATA   = '/home/degylog/public_html/crm_data.json';

function crm_token() {
  foreach (array('HTTP_X_TOKEN','HTTP_X_AUTH_TOKEN') as $k) {
    if (isset($_SERVER[$k]) && $_SERVER[$k] !== '') return $_SERVER[$k];
  }
  if (function_exists('apache_request_headers')) {
    $h = apache_request_headers();
    foreach (array('X-Token','x-token','X-Auth-Token','x-auth-token') as $k) {
      if (isset($h[$k]) && $h[$k] !== '') return $h[$k];
    }
  }
  if (isset($_GET['token'])) return $_GET['token'];
  return '';
}

function crm_read() {
  global $DATA;
  if (!file_exists($DATA)) return array();
  $r = @file_get_contents($DATA);
  if ($r === false) return array();
  $j = @json_decode($r, true);
  return is_array($j) ? $j : array();
}

function crm_write($data) {
  global $DATA;
  $j = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if ($j === false) return false;
  $tmp = $DATA . '.tmp';
  if (@file_put_contents($tmp, $j, LOCK_EX) === false) return false;
  return @rename($tmp, $DATA);
}

function crm_out($payload, $code = 200) {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

// Merge: combine arrays without losing data
function merge_value($existing, $incoming) {
  if (!is_array($existing) || !is_array($incoming)) return $incoming;
  
  // Both have 'd' (data array) → merge arrays
  if (isset($existing['d']) && is_array($existing['d']) && isset($incoming['d']) && is_array($incoming['d'])) {
    $merged_d = array_merge($existing['d'], $incoming['d']);
    // Remove duplicates based on id field if exists
    $unique = array();
    $seen = array();
    foreach ($merged_d as $item) {
      if (is_array($item) && isset($item['id'])) {
        if (!in_array($item['id'], $seen)) {
          $unique[] = $item;
          $seen[] = $item['id'];
        }
      } else {
        $unique[] = $item;
      }
    }
    return array(
      't' => max(isset($existing['t']) ? $existing['t'] : 0, isset($incoming['t']) ? $incoming['t'] : 0),
      'd' => $unique
    );
  }
  
  // Use newer timestamp
  $et = isset($existing['t']) ? (int)$existing['t'] : 0;
  $it = isset($incoming['t']) ? (int)$incoming['t'] : 0;
  return $it > $et ? $incoming : $existing;
}

// CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Token, X-Auth-Token');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// Secret from header or query
function get_secret() {
  $h = getallheaders();
  if (isset($h['X-Secret'])) return $h['X-Secret'];
  if (isset($_GET['secret'])) return $_GET['secret'];
  return '';
}

// Debug
if (isset($_GET['action']) && $_GET['action'] === 'path') {
  crm_out(array(
    'ok' => true,
    'path' => $DATA,
    'exists' => file_exists($DATA),
    'writable' => is_writable(dirname($DATA)),
    'size' => file_exists($DATA) ? filesize($DATA) : 0
  ));
}

// Test write
if (isset($_GET['action']) && $_GET['action'] === 'test_write') {
  $test_data = array('test' => array('t' => time(), 'd' => array('id' => 'test', 'value' => 'hello')));
  $ok = crm_write($test_data);
  crm_out(array('ok' => $ok, 'path' => $DATA, 'exists_after' => file_exists($DATA)));
}

// Debug
if (isset($_GET['action']) && $_GET['action'] === 'debug') {
  if (crm_token() !== $SECRET) crm_out(array('ok'=>false, 'err'=>'token'), 403);
  crm_out(array('ok' => true, 'keys' => array_keys(crm_read())));
}

$m = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = isset($_GET['action']) ? $_GET['action'] : '';

// SYNC - merge-based
if ($action === 'sync') {
  if (crm_token() !== $SECRET) crm_out(array('ok'=>false, 'err'=>'token'), 403);
  
  // POST: Merge localStorage into crm_data.json
  if ($m === 'POST') {
    $raw = file_get_contents('php://input');
    $b = json_decode($raw, true);
    if (!is_array($b)) crm_out(array('ok'=>false, 'err'=>'bad-json'), 400);
    
    $data = crm_read();
    $updated = 0;
    foreach ($b as $key => $value) {
      if (is_array($value) && isset($value['t'])) {
        if (isset($data[$key])) {
          $data[$key] = merge_value($data[$key], $value);
        } else {
          $data[$key] = $value;
        }
        $updated++;
      }
    }
    
    if (!crm_write($data)) {
      crm_out(array('ok'=>false, 'err'=>'write-failed'), 500);
    }
    
    crm_out(array('ok'=>true, 'synced'=>$updated));
  }
  
  // GET: Return all data from crm_data.json
  if ($m === 'GET') {
    $data = crm_read();
    crm_out($data);
  }
}

// GET
if ($m === 'GET') {
  crm_out(crm_read());
}

// POST
if ($m === 'POST') {
  if (crm_token() !== $SECRET) crm_out(array('ok'=>false, 'err'=>'token'), 403);
  $raw = file_get_contents('php://input');
  $b = @json_decode($raw, true);
  if (!is_array($b)) crm_out(array('ok'=>false, 'err'=>'bad-json'), 400);
  $data = crm_read();
  foreach ($b as $k => $v) {
    if (is_string($k) && $k !== '') {
      $data[$k] = is_array($v) ? $v : array('value'=>$v);
    }
  }
  if (!crm_write($data)) crm_out(array('ok'=>false, 'err'=>'write'), 500);
  crm_out(array('ok'=>true, 'saved_at'=>date('c')));
}

echo '{"ok":false,"err":"method-not-allowed"}';
