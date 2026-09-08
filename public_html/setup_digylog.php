<?php
/**
 * Digylog Auto-Setup Script
 * =========================
 * يُشغّل مرة واحدة على السيرفر لإعداد Digylog تلقائياً
 * 
 * الاستخدام:
 *   php setup_digylog.php
 *   أو من المتصفح: https://dkhol.xyz/setup_digylog.php?run=1
 */

// إعدادات Digylog
$config = array(
  'action' => 'digylog_setup',
  'token' => '3b59372d9ae891113a75e7947f70c790a9b504aabbe05932f04495e19e114196',
  'network' => 29784,
  'stores' => array(
    'Slimminge Patch',
    'Slim Bio',
    'Slim-Bio-As',
    'Slim-Bio-IM',
    'Slim-Bio-MR',
    'Slim-Bio-Ay'
  ),
  'ordersUrl' => 'https://api.digylog.com/api/v2/seller/orders',
  'testUrl' => 'https://api.digylog.com/api/v2/seller/networks',
  'sync_interval' => 300 // 5 دقائق
);

$secret = 'c6e04cb5de9088be01a685abc243995a80426eba45de2060';

// CLI mode
if (php_sapi_name() === 'cli') {
  echo "🚚 Digylog Auto-Setup\n";
  echo "====================\n\n";
  
  echo "⚙️  الإعدادات:\n";
  echo "  - Token: " . substr($config['token'], 0, 8) . "...\n";
  echo "  - Network: " . $config['network'] . "\n";
  echo "  - Stores: " . count($config['stores']) . " متاجر\n";
  foreach ($config['stores'] as $i => $store) {
    echo "    " . ($i + 1) . ". $store\n";
  }
  echo "\n";
  
  // Call api.php directly
  $_SERVER['REQUEST_METHOD'] = 'POST';
  $_SERVER['HTTP_X_SYNC_TOKEN'] = $secret;
  $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
  
  ob_start();
  $GLOBALS['CRM_RAW_INPUT'] = json_encode($config);
  require __DIR__ . '/api.php';
  $response = ob_get_clean();
  
  $result = json_decode($response, true);
  
  if ($result && $result['ok']) {
    echo "✅ تم إعداد Digylog بنجاح!\n\n";
    echo "📊 النتيجة:\n";
    print_r($result['config']);
    echo "\n🎯 الخطوات التالية:\n";
    echo "  1. اختبر الاتصال: php -r \"file_get_contents('https://dkhol.xyz/api.php?action=digylog_cron&token=$secret');\"\n";
    echo "  2. أو من الواجهة: LIVRAISON → 🔄 مزامنة Suivie\n";
    echo "  3. (اختياري) أضف Cron: */5 * * * * curl -s \"https://dkhol.xyz/api.php?action=digylog_cron&token=$secret\"\n";
  } else {
    echo "❌ فشل الإعداد:\n";
    print_r($result);
  }
  exit;
}

// Web mode
if (!isset($_GET['run'])) {
  echo "<!DOCTYPE html><html><head><meta charset='utf-8'><title>Digylog Setup</title>";
  echo "<style>body{font-family:sans-serif;max-width:600px;margin:50px auto;padding:20px;background:#f5f5f5}";
  echo ".box{background:white;padding:20px;border-radius:8px;box-shadow:0 2px 4px rgba(0,0,0,0.1)}";
  echo "h1{color:#333}ul{line-height:1.8}button{background:#4f46e5;color:white;border:none;padding:12px 24px;";
  echo "border-radius:6px;font-size:16px;cursor:pointer}button:hover{background:#4338ca}</style></head><body>";
  echo "<div class='box'><h1>🚚 Digylog Auto-Setup</h1>";
  echo "<p>هذا السكربت سيعدّ Digylog تلقائياً بالمعلومات التالية:</p>";
  echo "<ul>";
  echo "<li><strong>Token:</strong> " . substr($config['token'], 0, 8) . "...</li>";
  echo "<li><strong>Network:</strong> " . $config['network'] . "</li>";
  echo "<li><strong>Stores:</strong> " . count($config['stores']) . " متاجر</li>";
  echo "<ul>";
  foreach ($config['stores'] as $store) {
    echo "<li>$store</li>";
  }
  echo "</ul></ul>";
  echo "<form method='get'><input type='hidden' name='run' value='1'>";
  echo "<button type='submit'>⚙️ بدء الإعداد</button></form>";
  echo "<p style='color:#666;font-size:14px;margin-top:20px'>⚠️ احذف هذا الملف بعد الإعداد</p>";
  echo "</div></body></html>";
  exit;
}

// Run setup
header('Content-Type: text/plain; charset=utf-8');
echo "🚚 Digylog Auto-Setup\n";
echo "====================\n\n";

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_X_SYNC_TOKEN'] = $secret;
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

ob_start();
$GLOBALS['CRM_RAW_INPUT'] = json_encode($config);
require __DIR__ . '/api.php';
$response = ob_get_clean();

$result = json_decode($response, true);

if ($result && $result['ok']) {
  echo "✅ تم إعداد Digylog بنجاح!\n\n";
  echo "📊 الإعدادات المحفوظة:\n";
  echo "  - Token: محفوظ\n";
  echo "  - Network: " . $result['config']['network'] . "\n";
  echo "  - Stores: " . count($result['config']['stores']) . " متاجر\n";
  foreach ($result['config']['stores'] as $i => $store) {
    echo "    " . ($i + 1) . ". $store\n";
  }
  echo "\n🎯 الخطوات التالية:\n";
  echo "  1. اختبر: https://dkhol.xyz/api.php?action=digylog_cron&token=$secret\n";
  echo "  2. أو من الواجهة: LIVRAISON → 🔄 مزامنة Suivie\n";
  echo "  3. (اختياري) أضف Cron Job\n";
  echo "\n⚠️  احذف هذا الملف (setup_digylog.php) الآن!\n";
} else {
  echo "❌ فشل الإعداد:\n";
  print_r($result);
}
