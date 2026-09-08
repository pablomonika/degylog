<!DOCTYPE html>
<html dir="rtl">
<head>
<meta charset="utf-8">
<title>تسجيل Webhook + اختبار</title>
<style>
body{font-family:Arial;padding:20px;background:#f5f5f5}
.box{background:white;padding:20px;margin:10px 0;border-radius:8px;box-shadow:0 2px 4px rgba(0,0,0,0.1)}
.success{background:#d4edda;border:1px solid #c3e6cb;padding:20px;border-radius:8px;font-size:20px;text-align:center}
.error{background:#f8d7da;border:1px solid #f5c6cb;padding:20px;border-radius:8px;font-size:20px;text-align:center}
pre{background:#f4f4f4;padding:15px;border-radius:4px;overflow-x:auto}
button{background:#007bff;color:white;padding:12px 24px;border:none;border-radius:4px;cursor:pointer;font-size:16px;margin:5px}
button:hover{background:#0056b3}
.step{font-size:24px;margin:10px 0}
</style>
</head>
<body>
<h1>🚀 تسجيل Webhook + اختبار تلقائي</h1>

<?php
$token = '3b59372d9ae891113a75e7947f70c790a9b504aabbe05932f04495e19e114196';
$webhookUrl = 'https://dkhol.xyz/api.php?action=digylog_webhook';
$base = 'https://api.digylog.com/api/v2/seller';

function digylogRequest($url, $method, $body, $token) {
  $ch = curl_init($url);
  if ($method === 'PUT') curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
  if ($body !== null) {
    curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($body) ? $body : json_encode($body));
  }
  curl_setopt($ch, CURLOPT_HTTPHEADER, array(
    'Authorization: Bearer ' . $token,
    'Content-Type: application/json',
    'Accept: application/json',
    'Referer: https://apiseller.digylog.com'
  ));
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_TIMEOUT, 30);
  $response = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err = curl_error($ch);
  curl_close($ch);
  return array('code' => $code, 'body' => $response, 'err' => $err);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = isset($_POST['action']) ? $_POST['action'] : '';
  
  if ($action === 'register') {
    echo "<div class='box'>";
    echo "<p class='step'>📝 Step 1: تسجيل الـ Webhook عبر API</p>";
    echo "<p>PUT <code>$base/webhook</code></p>";
    echo "<p>Body: <code>" . htmlspecialchars(json_encode(array('url' => $webhookUrl))) . "</code></p>";
    $result = digylogRequest("$base/webhook", 'PUT', array('url' => $webhookUrl), $token);
    echo "<p>HTTP: <strong>{$result['code']}</strong></p>";
    echo "<pre>" . htmlspecialchars($result['body']) . "</pre>";
    if ($result['code'] >= 200 && $result['code'] < 300) {
      echo "<div class='success'>✅ تم تسجيل الـ Webhook بنجاح!</div>";
    } else {
      echo "<div class='error'>❌ فشل التسجيل (HTTP {$result['code']})</div>";
    }
    echo "</div>";
  }
  
  if ($action === 'test') {
    echo "<div class='box'>";
    echo "<p class='step'>🧪 Step 2: اختبار الـ Webhook</p>";
    $testPayload = array(
      'eventType' => 'order-status-changed',
      'payload' => array(
        'num' => '30',
        'traking' => 'S3F3D280A',
        'status' => 'Livrée',
        'idStatus' => '6',
        'motif' => '',
        'postponedTo' => null,
        'updatedAt' => date('Y-m-d H:i'),
        'driverPhone' => '0641203190'
      )
    );
    echo "<p>PUT <code>$base/webhook/test</code></p>";
    echo "<p>Body:</p><pre>" . json_encode($testPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "</pre>";
    $result = digylogRequest("$base/webhook/test", 'PUT', $testPayload, $token);
    echo "<p>HTTP: <strong>{$result['code']}</strong></p>";
    echo "<pre>" . htmlspecialchars($result['body']) . "</pre>";
    if ($result['code'] >= 200 && $result['code'] < 300) {
      echo "<div class='success'>✅ تم إرسال الـ Test!<br>شوف الـ CRM!</div>";
    } else {
      echo "<div class='error'>❌ فشل (HTTP {$result['code']}): " . htmlspecialchars($result['body']) . "</div>";
    }
    echo "</div>";
  }
  
  if ($action === 'events') {
    echo "<div class='box'>";
    echo "<p class='step'>📋 أحداث Webhook المتاحة:</p>";
    $result = digylogRequest("$base/webhook/events", 'GET', null, $token);
    echo "<p>HTTP: <strong>{$result['code']}</strong></p>";
    echo "<pre>" . htmlspecialchars($result['body']) . "</pre>";
    echo "</div>";
  }
  
  if ($action === 'statuses') {
    echo "<div class='box'>";
    echo "<p class='step'>📋 حالات Digylog:</p>";
    $result = digylogRequest("$base/statuses", 'GET', null, $token);
    echo "<p>HTTP: <strong>{$result['code']}</strong></p>";
    echo "<pre>" . htmlspecialchars(substr($result['body'], 0, 3000)) . "</pre>";
    echo "</div>";
  }
  
  if ($action === 'check') {
    echo "<div class='box'>";
    echo "<p class='step'>📊 حالة الـ Webhook Endpoint:</p>";
    $ch = curl_init($webhookUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);
    echo "<pre>" . htmlspecialchars($response) . "</pre>";
    echo "</div>";
  }
}
?>

<div class="box">
<h2>🎯 الخطوات بالترتيب:</h2>
<form method="POST" style="display:inline"><input type="hidden" name="action" value="register"><button type="submit" style="background:#28a745">📝 Step 1: تسجيل Webhook</button></form>
<form method="POST" style="display:inline"><input type="hidden" name="action" value="test"><button type="submit" style="background:#ffc107">🧪 Step 2: اختبار Webhook</button></form>
<form method="POST" style="display:inline"><input type="hidden" name="action" value="check"><button type="submit" style="background:#17a2b8">📊 Step 3: شوف الحالة</button></form>
<hr>
<form method="POST" style="display:inline"><input type="hidden" name="action" value="events"><button type="submit">📋 أحداث Webhook</button></form>
<form method="POST" style="display:inline"><input type="hidden" name="action" value="statuses"><button type="submit">📋 حالات Digylog</button></form>
</div>

<div class="box">
<h2>💡 التعليمات:</h2>
<ol>
<li>اضغط <strong>Step 1</strong> — تسجيل الـ webhook عبر Digylog API</li>
<li>اضغط <strong>Step 2</strong> — إرسال test event</li>
<li>اضغط <strong>Step 3</strong> — شوف total_updates</li>
<li>شوف الـ CRM — الطلبية خاصها تبدلات!</li>
</ol>
</div>

</body>
</html>
