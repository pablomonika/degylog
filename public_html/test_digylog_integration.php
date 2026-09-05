<?php
/**
 * Paraveda CRM — اختبار التكامل مع Digylog
 * ==========================================
 * يُشغّل على السيرفر: php test_digylog_integration.php
 * أو من المتصفح:      test_digylog_integration.php?run=1
 *
 * يختبر: Status Mapping, Regression Prevention, Matching, Audit, Retry
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

// محاكاة بيئة التشغيل
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'TestRunner';

// منع الخروج من crm_out
function crm_out_test_override() {
  // سنختبر الدوال مباشرة بدون المرور بـ crm_out
}

// تحميل api.php مع تجاوز المعالجة الرئيسية
$GLOBALS['CRM_DIGYLOG_MOCK'] = null;

// استخراج الدوال فقط (بدون تنفيذ المعالجة الرئيسية)
$apiCode = file_get_contents(__DIR__ . '/api.php');
// نجد أول try block (المعالجة الرئيسية) ونستبدله بـ return
$apiCode = preg_replace('/try\s*\{/', 'if(false){', $apiCode, 1);

eval('?>' . $apiCode);

$passed = 0;
$failed = 0;
$total  = 0;

function assert_eq($label, $expected, $actual) {
  global $passed, $failed, $total;
  $total++;
  if ($expected === $actual) {
    $passed++;
    echo "  ✅ $label\n";
  } else {
    $failed++;
    echo "  ❌ $label\n     Expected: " . var_export($expected, true) . "\n     Actual:   " . var_export($actual, true) . "\n";
  }
}

function assert_true($label, $actual) { assert_eq($label, true, $actual); }
function assert_false($label, $actual) { assert_eq($label, false, $actual); }

echo "════════════════════════════════════════════\n";
echo "  Paraveda CRM — Digylog Integration Tests\n";
echo "════════════════════════════════════════════\n\n";

// ============================
// 1) Status Mapping Tests
// ============================
echo "📋 1. Status Mapping (Digylog → CRM)\n";
echo "─────────────────────────────────────\n";

// الحالات الأساسية
assert_eq('Livrée → Livrée', 'Livrée', crm_digylog_map_status('Livrée'));
assert_eq('livré → Livrée', 'Livrée', crm_digylog_map_status('livré'));
assert_eq('Livré → Livrée', 'Livrée', crm_digylog_map_status('Livré'));
assert_eq('delivered → Livrée', 'Livrée', crm_digylog_map_status('delivered'));

assert_eq('Retour → Retour', 'Retour', crm_digylog_map_status('Retour'));
assert_eq('retour → Retour', 'Retour', crm_digylog_map_status('retour'));
assert_eq('return → Retour', 'Retour', crm_digylog_map_status('return'));

assert_eq('Refusé → Refusé', 'Refusé', crm_digylog_map_status('Refusé'));
assert_eq('refuse → Refusé', 'Refusé', crm_digylog_map_status('refuse'));
assert_eq('reject → Refusé', 'Refusé', crm_digylog_map_status('reject'));

assert_eq('Annulé → Annulé', 'Annulé', crm_digylog_map_status('Annulé'));
assert_eq('cancel → Annulé', 'Annulé', crm_digylog_map_status('cancel'));
assert_eq('annulé → Annulé', 'Annulé', crm_digylog_map_status('annulé'));

assert_eq('En livraison → En livraison', 'En livraison', crm_digylog_map_status('En livraison'));
assert_eq('en livraison → En livraison', 'En livraison', crm_digylog_map_status('en livraison'));
assert_eq('out for delivery → En livraison', 'En livraison', crm_digylog_map_status('out for delivery'));
assert_eq('transit → En livraison', 'En livraison', crm_digylog_map_status('transit'));

assert_eq('Expédié → Expédié', 'Expédié', crm_digylog_map_status('Expédié'));
assert_eq('expedié → Expédié', 'Expédié', crm_digylog_map_status('expedié'));
assert_eq('shipped → Expédié', 'Expédié', crm_digylog_map_status('shipped'));

assert_eq('Out Of Stock → Out Of Stock', 'Out Of Stock', crm_digylog_map_status('Out Of Stock'));
assert_eq('rupture → Out Of Stock', 'Out Of Stock', crm_digylog_map_status('rupture'));

// حالة غير معروفة
assert_eq('unknown → null', null, crm_digylog_map_status('something_random'));
assert_eq('empty → null', null, crm_digylog_map_status(''));
assert_eq('null → null', null, crm_digylog_map_status(null));

// حالة في خريطة (object/array)
assert_eq('array status.name=Livré → Livrée', 'Livrée', crm_digylog_map_status(array('name' => 'Livré')));
assert_eq('array status.code=shipped → Expédié', 'Expédié', crm_digylog_map_status(array('code' => 'shipped')));

echo "\n";

// ============================
// 2) Status Regression Prevention
// ============================
echo "🛡️ 2. Status Regression Prevention\n";
echo "─────────────────────────────────────\n";

// التقدم مسموح
assert_true('→ Livrée (from empty)', crm_digylog_can_update('', '', 'Livrée'));
assert_true('Expédié → En livraison', crm_digylog_can_update('Expédié', 'Confirmé', 'En livraison'));
assert_true('En livraison → Livrée', crm_digylog_can_update('En livraison', 'Confirmé', 'Livrée'));
assert_true('À préparer → Expédié', crm_digylog_can_update('À préparer', 'Confirmé', 'Expédié'));

// التراجع من حالة نهائية = ممنوع
assert_false('Livrée → En livraison (FINAL)', crm_digylog_can_update('Livrée', 'Confirmé', 'En livraison'));
assert_false('Livrée → Expédié (FINAL)', crm_digylog_can_update('Livrée', 'Confirmé', 'Expédié'));
assert_false('Retour → En livraison (FINAL)', crm_digylog_can_update('Retour', 'Confirmé', 'En livraison'));
assert_false('Refusé → Expédié (FINAL)', crm_digylog_can_update('Refusé', 'Confirmé', 'Expédié'));

// استثناء: Annulé يمكن العودة
assert_true('Livrée+Annulé → En livraison (exception)', crm_digylog_can_update('Livrée', 'Annulé', 'En livraison'));

echo "\n";

// ============================
// 3) Order Matching & Apply
// ============================
echo "🔗 3. Order Matching & Apply\n";
echo "─────────────────────────────────────\n";

$orders = array(
  array('idCmd' => 'WP808208863DL', 'client' => 'Bilal', 'phone' => '0608278001', 'livraison' => '', 'statut' => 'Confirmé'),
  array('idCmd' => 'WP808208864DL', 'client' => 'Ahmed', 'phone' => '0612345678', 'livraison' => 'Expédié', 'statut' => 'Confirmé'),
  array('idCmd' => 'WP808208865DL', 'client' => 'Sara',  'phone' => '0698765432', 'livraison' => 'Livrée', 'statut' => 'Confirmé'),
  array('idCmd' => 'WP808208866DL', 'client' => 'Fatima','phone' => '0655555555', 'livraison' => 'En livraison', 'statut' => 'Confirmé'),
  array('idCmd' => 'WP808208867DL', 'client' => 'Omar',  'phone' => '0666666666', 'livraison' => '', 'statut' => 'Annulé'),
  array('idCmd' => 'WP-TRACK-001', 'client' => 'Youssef', 'phone' => '0677777777', 'livraison' => '', 'statut' => 'Confirmé', 'tracking' => 'TRK-001-XYZ'),
);

$items = array(
  // 1. تحديث عادي: → Livrée
  array('num' => 'WP808208863DL', 'status' => 'Livrée'),
  // 2. تقدم عادي: Expédié → En livraison
  array('num' => 'WP808208864DL', 'status' => 'En livraison'),
  // 3. تراجع ممنوع: Livrée → En livraison (يجب تجاهله)
  array('num' => 'WP808208865DL', 'status' => 'En livraison'),
  // 4. En livraison → Retour (مسموح: ليست حالة نهائية)
  array('num' => 'WP808208866DL', 'status' => 'Retour'),
  // 5. Tracking غير موجود في CRM
  array('num' => 'NONEXISTENT-999', 'status' => 'Livrée'),
  // 6. حالة غير معروفة
  array('num' => 'WP808208863DL', 'status' => 'unknown_status_xyz'),
  // 7. طلبية ملغاة ترجع للحياة
  array('num' => 'WP808208867DL', 'status' => 'En livraison'),
  // 8. Matching by tracking field
  array('num' => 'TRK-001-XYZ', 'status' => 'Expédié'),
);

$log = array();
$n = crm_digylog_apply($orders, $items, $log);

assert_eq('عدد التحديثات', 5, $n); // 1,2,4,7,8

// التحقق من التحديثات
assert_eq('WP808208863DL → Livrée', 'Livrée', $orders[0]['livraison']);
assert_eq('WP808208864DL → En livraison', 'En livraison', $orders[1]['livraison']);
assert_eq('WP808208865DL يبقى Livrée (regression blocked)', 'Livrée', $orders[2]['livraison']);
assert_eq('WP808208866DL → Retour', 'Retour', $orders[3]['livraison']);
assert_eq('WP808208867DL → En livraison (was Annulé)', 'En livraison', $orders[4]['livraison']);
assert_eq('WP808208867DL statut → Confirmé (restored from Annulé)', 'Confirmé', $orders[4]['statut']);
assert_eq('TRK-001-XYZ matching → Expédié', 'Expédié', $orders[5]['livraison']);

// التحقق من الحقول غير المتعلقة بالتوصيل لم تتغير
assert_eq('اسم العميل لم يتغير', 'Bilal', $orders[0]['client']);
assert_eq('الهاتف لم يتغير', '0608278001', $orders[0]['phone']);

// التحقق من الـ skipped
$skipped = isset($log['skipped']) ? $log['skipped'] : array();
assert_eq('no_match = 1 (NONEXISTENT-999)', 1, isset($skipped['no_match']) ? $skipped['no_match'] : 0);
assert_eq('regression = 1 (Livrée → En livraison)', 1, isset($skipped['regression']) ? $skipped['regression'] : 0);

echo "\n";

// ============================
// 4) Item Num Extraction
// ============================
echo "🔍 4. Item Num/Tracking Extraction\n";
echo "─────────────────────────────────────\n";

assert_eq('num field', 'WP123', crm_digylog_item_num(array('num' => 'WP123')));
assert_eq('tracking field', 'TRK456', crm_digylog_item_num(array('tracking' => 'TRK456')));
assert_eq('order_number field', 'ORD789', crm_digylog_item_num(array('order_number' => 'ORD789')));
assert_eq('barcode field', 'BC000', crm_digylog_item_num(array('barcode' => 'BC000')));
assert_eq('empty → null', null, crm_digylog_item_num(array('num' => '')));
assert_eq('no fields → null', null, crm_digylog_item_num(array('name' => 'test')));
assert_eq('null input → null', null, crm_digylog_item_num(null));

echo "\n";

// ============================
// 5) Response Parsing
// ============================
echo "📦 5. Digylog Response Parsing\n";
echo "─────────────────────────────────────\n";

// Array of orders directly
$r1 = crm_digylog_find_orders(json_encode(array(
  array('num' => 'WP001', 'status' => 'Livrée'),
  array('num' => 'WP002', 'status' => 'Retour'),
)));
assert_eq('direct array → 2 orders', 2, is_array($r1) ? count($r1) : 0);

// Nested in 'data' key
$r2 = crm_digylog_find_orders(json_encode(array(
  'data' => array(
    array('num' => 'WP003', 'status' => 'Expédié'),
  ),
)));
assert_eq('data key → 1 order', 1, is_array($r2) ? count($r2) : 0);

// Nested in 'orders' key
$r3 = crm_digylog_find_orders(json_encode(array(
  'orders' => array(
    array('num' => 'WP004', 'status' => 'En livraison'),
    array('num' => 'WP005', 'status' => 'Livrée'),
    array('num' => 'WP006', 'status' => 'Annulé'),
  ),
)));
assert_eq('orders key → 3 orders', 3, is_array($r3) ? count($r3) : 0);

// Invalid JSON
$r4 = crm_digylog_find_orders('not json at all');
assert_eq('invalid JSON → null', null, $r4);

// Nested data.data
$r5 = crm_digylog_find_orders(json_encode(array(
  'data' => array('data' => array(
    array('num' => 'WP007', 'status' => 'Retour'),
  )),
)));
assert_eq('data.data nested → 1 order', 1, is_array($r5) ? count($r5) : 0);

echo "\n";

// ============================
// 6) Concurrent Edit Protection (Partial Update)
// ============================
echo "🔒 6. Concurrent Edit Protection\n";
echo "─────────────────────────────────────\n";

// محاكاة: موظف عدّل بيانات الطلب، ثم Digylog حدّث الحالة
$order = array(
  'idCmd' => 'TEST-001',
  'client' => 'Original Client',
  'phone' => '0600000000',
  'prix' => 200,
  'agent' => 'Agent1',
  'livraison' => 'Expédié',
  'statut' => 'Confirmé',
);

// الموظف يغيّر: phone, agent, prix
$order['phone'] = '0611111111';
$order['agent'] = 'Agent2';
$order['prix'] = 250;

// Digylog sync يطبق فقط livraison
$orders2 = array($order);
$log2 = array();
$n2 = crm_digylog_apply($orders2, array(array('num' => 'TEST-001', 'status' => 'Livrée')), $log2);

assert_eq('update applied', 1, $n2);
assert_eq('livraison updated to Livrée', 'Livrée', $orders2[0]['livraison']);
// بيانات الموظف محفوظة
assert_eq('phone preserved (employee edit)', '0611111111', $orders2[0]['phone']);
assert_eq('agent preserved (employee edit)', 'Agent2', $orders2[0]['agent']);
assert_eq('prix preserved (employee edit)', 250, $orders2[0]['prix']);
assert_eq('client preserved', 'Original Client', $orders2[0]['client']);

echo "\n";

// ============================
// 7) Edge Cases
// ============================
echo "🧪 7. Edge Cases\n";
echo "─────────────────────────────────────\n";

// Tracking فارغ
$orders3 = array(
  array('idCmd' => '', 'client' => 'NoTrack', 'livraison' => '', 'statut' => 'Confirmé'),
);
$log3 = array();
$n3 = crm_digylog_apply($orders3, array(array('num' => '', 'status' => 'Livrée')), $log3);
assert_eq('empty tracking → no update', 0, $n3);

// حالة Annulé → Annulé (نفس الحالة)
$orders4 = array(
  array('idCmd' => 'ANN-001', 'client' => 'X', 'livraison' => 'Retour', 'statut' => 'Annulé'),
);
$log4 = array();
$n4 = crm_digylog_apply($orders4, array(array('num' => 'ANN-001', 'status' => 'Annulé')), $log4);
assert_eq('Annulé on already Annulé → no change', 0, $n4);

// أكثر من 100 طلبية
$bigOrders = array();
for ($i = 0; $i < 150; $i++) {
  $bigOrders[] = array('idCmd' => "BIG-" . str_pad($i, 4, '0', STR_PAD_LEFT), 'client' => "Client$i", 'livraison' => '', 'statut' => 'Confirmé');
}
$bigItems = array();
for ($i = 0; $i < 150; $i++) {
  $bigItems[] = array('num' => "BIG-" . str_pad($i, 4, '0', STR_PAD_LEFT), 'status' => ($i % 3 === 0) ? 'Livrée' : (($i % 3 === 1) ? 'En livraison' : 'Expédié'));
}
$bigLog = array();
$bigN = crm_digylog_apply($bigOrders, $bigItems, $bigLog);
assert_eq('150 orders all updated', 150, $bigN);

// Duplicate tracking (نفس الطلبية مرتين في Digylog)
$orders5 = array(
  array('idCmd' => 'DUP-001', 'client' => 'Dup', 'livraison' => '', 'statut' => 'Confirmé'),
);
$log5 = array();
$n5 = crm_digylog_apply($orders5, array(
  array('num' => 'DUP-001', 'status' => 'Expédié'),
  array('num' => 'DUP-001', 'status' => 'Expédié'),
), $log5);
assert_eq('duplicate items → second is same_status (1 update)', 1, $n5);

echo "\n";

// ============================
// Results
// ============================
echo "════════════════════════════════════════════\n";
echo "  Results: $passed/$total passed";
if ($failed > 0) echo " ($failed FAILED)";
echo "\n════════════════════════════════════════════\n";

exit($failed > 0 ? 1 : 0);
