<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, X-Sync-Token');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { exit; }

$SECRET = 'c6e04cb5de9088be01a685abc243995a80426eba45de2060';
$DATA_FILE = __DIR__ . '/crm_data.json';

function crm_token() {
  if (isset($_SERVER['HTTP_X_SYNC_TOKEN'])) return $_SERVER['HTTP_X_SYNC_TOKEN'];
  if (isset($_GET['token'])) return $_GET['token'];
  return '';
}

function crm_out($arr, $code = 200) {
  if ($code !== 200) http_response_code($code);
  echo json_encode($arr, JSON_UNESCAPED_UNICODE);
  exit;
}

function crm_read() {
  global $DATA_FILE;
  if (!file_exists($DATA_FILE)) return array();
  $s = file_get_contents($DATA_FILE);
  $j = json_decode($s, true);
  return is_array($j) ? $j : array();
}

function crm_write($data) {
  global $DATA_FILE;
  $tmp = $DATA_FILE . '.tmp.' . getmypid();
  if (file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX) === false) return false;
  if (!rename($tmp, $DATA_FILE)) { unlink($tmp); return false; }
  return true;
}

$m = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/* ---------- GET ---------- */
if ($m === 'GET') {
  $data = crm_read();
  echo json_encode($data, JSON_UNESCAPED_UNICODE);
  exit;
}

/* ---------- POST ---------- */
if ($m === 'POST') {
  if (crm_token() !== $SECRET) crm_out(array('ok'=>false, 'err'=>'token'), 403);

  $raw = file_get_contents('php://input');
  $b = json_decode($raw, true);
  if (!is_array($b)) crm_out(array('ok'=>false, 'err'=>'bad-json'), 400);

  // Restore from backup
  if (isset($b['action']) && $b['action'] === 'restore') {
    crm_out(array('ok'=>false, 'err'=>'restore-not-implemented'), 501);
  }

  // Normal write
  if (!isset($b['key']) || !isset($b['d'])) {
    crm_out(array('ok'=>false, 'err'=>'bad-body'), 400);
  }

  $k = (string)$b['key'];
  $d = $b['d'];
  $t = isset($b['t']) ? (int)$b['t'] : (int)(microtime(true) * 1000);

  // Read current data
  $data = crm_read();

  // Backup before write
  if (file_exists($DATA_FILE)) {
    $backup = $DATA_FILE . '.backup';
    copy($DATA_FILE, $backup);
  }

  // Write new data
  $data[$k] = array('t' => $t, 'd' => $d);
  if (!crm_write($data)) {
    crm_out(array('ok'=>false, 'err'=>'write-failed'), 500);
  }

  crm_out(array('ok'=>true));
}

http_response_code(405);
echo '{"ok":false,"err":"method-not-allowed"}';
exit;
