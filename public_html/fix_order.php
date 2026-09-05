<!DOCTYPE html>
<html dir="rtl">
<head><meta charset="utf-8"><title>إصلاح الطلبية</title>
<style>
body{font-family:Arial;padding:20px;background:#f5f5f5}
.box{background:white;padding:20px;margin:10px 0;border-radius:8px}
.success{background:#d4edda;padding:20px;border-radius:8px;font-size:20px}
.error{background:#f8d7da;padding:20px;border-radius:8px;font-size:20px}
pre{background:#f4f4f4;padding:15px;border-radius:4px;overflow-x:auto}
button{background:#dc3545;color:white;padding:12px 24px;border:none;border-radius:4px;cursor:pointer;font-size:16px;margin:5px}
</style>
</head>
<body>
<h1>🔧 إصلاح الطلبية + Tracking Column</h1>

<?php
// Find storage directory
$candidates = array(
  dirname(__DIR__) . '/crm-paraveda-data',
  __DIR__ . '/crm-paraveda-data-7f3a',
  __DIR__
);
$dataPath = null;
foreach ($candidates as $d) {
  if (file_exists($d . '/crm_data.json')) {
    $dataPath = $d . '/crm_data.json';
    break;
  }
}

if (!$dataPath) {
  echo "<div class='error'>❌ ما لقيناش crm_data.json</div>";
  echo "</body></html>";
  exit;
}

$data = json_decode(file_get_contents($dataPath), true);
$orders = isset($data['afrizon_orders_v5']['d']) ? $data['afrizon_orders_v5']['d'] : array();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = isset($_POST['action']) ? $_POST['action'] : '';
  
  // Fix abde lftah order
  if ($action === 'fix_abde') {
    $found = false;
    foreach ($orders as &$order) {
      $phone = isset($order['telephone']) ? preg_replace('/\D/', '', $order['telephone']) : '';
      if ($phone === '0623442544' || substr($phone, -9) === '623442544') {
        $oldLivraison = isset($order['livraison']) ? $order['livraison'] : '';
        $oldTracking = isset($order['tracking']) ? $order['tracking'] : '';
        $oldNum = isset($order['digylog_num']) ? $order['digylog_num'] : '';
        
        // Reset to original state
        $order['livraison'] = '';
        unset($order['tracking']);
        unset($order['digylog_num']);
        
        echo "<div class='success'>✅ تم إصلاح الطلبية!</div>";
        echo "<div class='box'>";
        echo "<p><strong>الاسم:</strong> " . (isset($order['nom']) ? $order['nom'] : '?') . "</p>";
        echo "<p><strong>الهاتف:</strong> " . (isset($order['telephone']) ? $order['telephone'] : '?') . "</p>";
        echo "<p><strong>الحالة القديمة:</strong> $oldLivraison → <strong>الجديدة:</strong> فارغة ✅</p>";
        echo "<p><strong>Tracking القديم:</strong> $oldTracking → <strong>محذوف</strong> ✅</p>";
        echo "<p><strong>Digylog Num القديم:</strong> $oldNum → <strong>محذوف</strong> ✅</p>";
        echo "</div>";
        
        $found = true;
        break;
      }
    }
    unset($order);
    
    if ($found) {
      $data['afrizon_orders_v5']['d'] = $orders;
      $data['afrizon_orders_v5']['t'] = round(microtime(true) * 1000);
      file_put_contents($dataPath, json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
    } else {
      echo "<div class='error'>❌ ما لقيناش الطلبية بالهاتف 0623442544</div>";
    }
  }
  
  // Show all orders with tracking
  if ($action === 'show') {
    echo "<div class='box'><h2>📋 الطلبات اللي عندهم Tracking:</h2>";
    echo "<table border='1' cellpadding='8' style='border-collapse:collapse;width:100%'>";
    echo "<tr><th>ID</th><th>الاسم</th><th>الهاتف</th><th>المدينة</th><th>الحالة</th><th>Tracking</th><th>Digylog Num</th></tr>";
    foreach ($orders as $order) {
      if (isset($order['tracking']) || isset($order['digylog_num'])) {
        echo "<tr>";
        echo "<td>" . (isset($order['id']) ? $order['id'] : '?') . "</td>";
        echo "<td>" . (isset($order['nom']) ? $order['nom'] : '?') . "</td>";
        echo "<td>" . (isset($order['telephone']) ? $order['telephone'] : '?') . "</td>";
        echo "<td>" . (isset($order['ville']) ? $order['ville'] : '?') . "</td>";
        echo "<td>" . (isset($order['livraison']) ? $order['livraison'] : '—') . "</td>";
        echo "<td><strong>" . (isset($order['tracking']) ? $order['tracking'] : '—') . "</strong></td>";
        echo "<td>" . (isset($order['digylog_num']) ? $order['digylog_num'] : '—') . "</td>";
        echo "</tr>";
      }
    }
    echo "</table></div>";
  }
}

// Show abde lftah current state
echo "<div class='box'><h2>🔍 الطلبية ديال abde lftah (0623442544):</h2>";
$found = false;
foreach ($orders as $order) {
  $phone = isset($order['telephone']) ? preg_replace('/\D/', '', $order['telephone']) : '';
  if ($phone === '0623442544' || substr($phone, -9) === '623442544') {
    echo "<pre>" . json_encode($order, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "</pre>";
    $found = true;
    break;
  }
}
if (!$found) echo "<p>❌ ما لقيناش الطلبية</p>";
echo "</div>";
?>

<div class="box">
<h2>🔧 الإجراءات:</h2>
<form method="POST" style="display:inline">
<input type="hidden" name="action" value="fix_abde">
<button type="submit">🔧 إصلاح طلبية abde lftah (إرجاع للحالة فارغة)</button>
</form>
<form method="POST" style="display:inline">
<input type="hidden" name="action" value="show">
<button type="submit" style="background:#007bff">📋 شوف الطلبات اللي عندهم Tracking</button>
</form>
</div>

</body>
</html>
