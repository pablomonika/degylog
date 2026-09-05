<?php
/* =====================================================================
 * Paraveda CRM Sync — v2.0 (Hardened / نسخة محصّنة)
 * ---------------------------------------------------------------------
 * بديل مباشر (drop-in) للنسخة القديمة: نفس الواجهة تماماً
 *   GET  api.php                     → بيانات المزامنة (كما كان)
 *   POST api.php  {key,t,d} + token  → حفظ بيانات مفتاح (كما كان)
 *
 * الجديد في هذه النسخة:
 *  1) كتابة ذرّية (tmp + rename) + قفل flock → لا ملف فاسد/فارغ أبداً
 *  2) نسخ احتياطية تلقائية (لكل تعديل + لقطة يومية) + تدوير
 *  3) استرجاع تلقائي: إذا فُقد/تلف الملف الرئيسي يُسترجع من آخر نسخة سليمة
 *  4) حماية "الأشباح" (Ghost Guard): يرفض إرجاع نسخة قديمة مطابقة
 *     (مثل بيانات المصنع/الديمو) فوق بيانات أحدث — وهو سبب اختفاء
 *     اليوزرات ورجوع الطلبيات القديمة
 *  5) سجل تدقيق audit.log: من كتب ماذا ومتى ومن أي IP
 *  6) نقاط نهاية للتشخيص والاسترجاع (محمية بنفس التوكن):
 *       GET  ?action=status    → تقرير صحة التخزين
 *       GET  ?action=backups   → قائمة النسخ الاحتياطية
 *       POST {"action":"restore","file":"..."} → استرجاع نسخة
 *  7) تخزين البيانات خارج مجلد الموقع إن أمكن (لا تُمس عند إعادة الرفع)
 * ---------------------------------------------------------------------
 * متوافق مع PHP 7.2+ (لا يتطلب أي إضافة)
 * ===================================================================== */

error_reporting(E_ALL);
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('X-Crm-Sync: v2');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, X-Sync-Token');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { exit; }

$SECRET = 'c6e04cb5de9088be01a685abc243995a80426eba45de2060';

$KEYS = array(
  "afrizon_users_v1","afrizon_orders_v5","afrizon_agent_names_v1",
  "afrizon_chat_v1","afrizon_worktimes_v1","afrizon_remarques_v1",
  "afrizon_avances_v1","afrizon_adspend_v1","afrizon_perfrows_v1","afrizon_livraison_v1",
  "afrizon_history_v1","afrizon_villes_v2","afrizon_catalog_v1",
  "sheet_pièce","afrizon_team_photos_v1","tabs_list_v1","custom_sheets_v1",
  "afrizon_digylog_v1",
);

/* ---- إعدادات (يمكن تعديلها) ---- */
define('CRM_GHOST_GRACE',      1800);  // ثانية: يُسمح بإرجاع نسخة قديمة "مطابقة" فقط خلال هذه المدة
define('CRM_BACKUPS_KEEP',     60);    // عدد النسخ اللحظية المحفوظة
define('CRM_DAILY_KEEP',       45);    // عدد اللقطات اليومية المحفوظة
define('CRM_HIST_PER_KEY',     30);    // حجم سجل بصمات كل مفتاح (لمكافحة الأشباح)
define('CRM_AUDIT_MAX_BYTES',  2097152); // 2MB ثم يدور السجل
define('CRM_FUTURE_MS',        60000); // تسامح ساعة الجهاز (دقيقة للمستقبل)
// سقف إجمالي حجم مجلد النسخ الاحتياطية (256MB) — بعده تُحذف الأقدم أولاً
$GLOBALS['CRM_BACKUPS_MAX_BYTES'] = isset($GLOBALS['CRM_BACKUPS_MAX_BYTES']) ? $GLOBALS['CRM_BACKUPS_MAX_BYTES'] : 268435456;

/* بصمات بيانات المصنع المضمّنة في التطبيق (sha256 لمحتوى JSON كما يرسله المتصفح).
 * إذا وصل "بالضبط" هذا المحتوى فوق بيانات أحدث → رفض (ghost_seed).
 * أُخذت من حزمة الواجهة: users الافتراضي [admin@paraveda.ma]،
 * الطلبيات التجريبية dp()، وأسماء الوكلاء الافتراضية.
 * ملاحظة: نسختان من users seed — القديمة (admin123) لكاش المتصفحات
 * القديمة، والجديدة (كلمة السر الحالية) للنسخة المحدثة. */
$GLOBALS['CRM_SEED_HASHES'] = array(
  "afrizon_users_v1"       => array(
    "49419fd53fcd0287ba747af929f5439faa6c20c423a9f954bd933abcd52719d6", // seed قديم: admin123
    "108061314dc7d0ccd39eaea1f404b0b54b55198f5b9cb6040d7a2ee87e9abd52", // seed جديد
  ),
  "afrizon_orders_v5"      => array("c7df94df270625f03e60ab7d3b706e476a6ce464c12e76e44a32e8a253cf7929"),
  "afrizon_agent_names_v1" => array("c6019f6b4c6eb01da6f877f05771ebfb5fedc40cb52aff3556e5beb9e044ef36"),
);

/* ===================================================================== */
/* أدوات مساعدة                                                          */
/* ===================================================================== */

function crm_now_ms() { return (int)round(microtime(true) * 1000); }
function crm_ip() {
  $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'cli';
  return $ip;
}
function crm_ua() {
  $ua = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
  return substr($ua, 0, 120);
}
function crm_token() {
  if (isset($_SERVER['HTTP_X_SYNC_TOKEN'])) return $_SERVER['HTTP_X_SYNC_TOKEN'];
  if (isset($_GET['token'])) return $_GET['token'];
  return '';
}
function crm_raw_input() {
  if (isset($GLOBALS['CRM_RAW_INPUT'])) return $GLOBALS['CRM_RAW_INPUT']; // للاختبار الآلي فقط
  return file_get_contents('php://input');
}
function crm_out($arr, $code = 200) {
  if ($code !== 200) http_response_code($code);
  echo json_encode($arr, JSON_UNESCAPED_UNICODE);
  exit;
}

/* ---- اختيار مجلد التخزين ---- */
function crm_storage_dir() {
  static $dir = null; static $mode = null;
  if ($dir !== null) return array($dir, $mode);

  // 0) إعداد صريح عبر crm_config.php (اختياري): define('CRM_DATA_DIR', '/abs/path');
  $cfg = __DIR__ . '/crm_config.php';
  if (file_exists($cfg)) { include_once $cfg; }
  $candidates = array();
  if (defined('CRM_DATA_DIR')) $candidates[] = array(rtrim(CRM_DATA_DIR, '/'), 'custom');
  // 1) خارج جذر الويب (الأفضل: إعادة رفع الموقع لا تمسه)
  $candidates[] = array(dirname(__DIR__) . '/crm-paraveda-data', 'outside-webroot');
  // 2) مجلد غامض داخل الموقع محمي بـ .htaccess
  $candidates[] = array(__DIR__ . '/crm-paraveda-data-7f3a', 'webroot-protected');
  // 3) آخر احتمال: بجانب api.php (سلوك النسخة القديمة)
  $candidates[] = array(__DIR__, 'legacy');

  foreach ($candidates as $c) {
    $d = $c[0];
    $hadData = file_exists($d . '/crm_data.json');
    if (!is_dir($d)) { @mkdir($d, 0755, true); }
    if (is_dir($d) && is_writable($d)) {
      crm_protect_dir($d);
      $dir = $d; $mode = $c[1];
      crm_migrate_legacy($d);
      return array($d, $mode);
    }
    // المجلد فيه بياناتنا لكنه فقد قابلية الكتابة (تغيير صلاحيات من الهوست؟)
    // → خطأ صريح أفضل من التبديل الصامت لمكان آخر (يبدو كأن البيانات اختفت)
    if ($hadData && !is_writable($d)) {
      if (!headers_sent()) {
        http_response_code(500);
        echo json_encode(array('ok'=>false, 'err'=>'storage-readonly', 'dir'=>basename($d)), JSON_UNESCAPED_UNICODE);
      }
      exit;
    }
  }
  $dir = false; $mode = 'nowhere';
  return array($dir, $mode);
}
function crm_protect_dir($d) {
  $ht = $d . '/.htaccess';
  if (!file_exists($ht)) {
    @file_put_contents($ht,
      "# Paraveda CRM data - deny all\n" .
      "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n" .
      "<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n");
  }
  $ix = $d . '/index.html';
  if (!file_exists($ix)) { @file_put_contents($ix, "<!-- denied -->"); }
  $bk = $d . '/backups';
  if (!is_dir($bk)) { @mkdir($bk, 0755, true); }
}
/* ترحيل تلقائي للبيانات القديمة crm_data.json إن وُجدت بجانب api.php */
function crm_migrate_legacy($d) {
  $legacy = __DIR__ . '/crm_data.json';
  $target = $d . '/crm_data.json';
  if ($d !== __DIR__ && file_exists($legacy) && !file_exists($target)) {
    $s = file_get_contents($legacy);
    if (is_string($s) && $s !== '' && trim($s) !== '[]') {
      @file_put_contents($target, $s, LOCK_EX);
      crm_audit('migrate', 'legacy crm_data.json copied to new storage');
    } else {
      crm_audit('migrate', 'legacy file empty, skipped');
    }
  }
}

function crm_data_path()    { list($d) = crm_storage_dir(); return $d === false ? false : $d . '/crm_data.json'; }
function crm_backups_dir()  { list($d) = crm_storage_dir(); return $d === false ? false : $d . '/backups'; }
function crm_meta_path()    { list($d) = crm_storage_dir(); return $d === false ? false : $d . '/meta.json'; }
function crm_audit_path()   { list($d) = crm_storage_dir(); return $d === false ? false : $d . '/audit.log'; }
function crm_lock_path()    { list($d) = crm_storage_dir(); return $d === false ? false : $d . '/.crm.lock'; }

function crm_lock() {
  $f = @fopen(crm_lock_path(), 'c');
  if ($f) { @flock($f, LOCK_EX); }
  return $f;
}
function crm_unlock($f) {
  if ($f) { @flock($f, LOCK_UN); @fclose($f); }
}

function crm_atomic_write($path, $content) {
  $tmp = $path . '.tmp.' . getmypid();
  if (@file_put_contents($tmp, $content, LOCK_EX) === false) return false;
  if (!@rename($tmp, $path)) { @unlink($tmp); return false; }
  return true;
}

function crm_audit($event, $detail = '') {
  $p = crm_audit_path();
  if ($p === false) return;
  $line = date('Y-m-d H:i:s') . ' | ' . crm_ip() . ' | ' . $event . ' | ' . $detail . "\n";
  @file_put_contents($p, $line, FILE_APPEND | LOCK_EX);
  if (@filesize($p) > CRM_AUDIT_MAX_BYTES) {
    @rename($p, $p . '.1'); // تدوير: نحتفظ بنسخة واحدة سابقة
  }
}

function crm_read_meta() {
  $p = crm_meta_path();
  if ($p === false || !file_exists($p)) return crm_fresh_meta();
  $m = json_decode((string)file_get_contents($p), true);
  if (!is_array($m)) return crm_fresh_meta();
  $fresh = crm_fresh_meta();
  foreach ($fresh as $k => $v) { if (!isset($m[$k])) $m[$k] = $v; }
  return $m;
}
function crm_fresh_meta() {
  return array(
    'install_id'   => bin2hex(random_bytes(6)),
    'install_time' => time(),
    'counts'       => array('write'=>0,'noop'=>0,'stale'=>0,'ghost'=>0,'ghost_seed'=>0,'recover'=>0,'restore'=>0,'reject'=>0),
    'last_write'   => null,
    'last_write_ip'=> null,
    'keys'         => array(),
  );
}
function crm_write_meta($m) { crm_atomic_write(crm_meta_path(), json_encode($m, JSON_UNESCAPED_UNICODE)); }

function crm_valid_data_str($s) {
  if (!is_string($s) || $s === '' ) return false;
  $j = json_decode($s, true);
  return is_array($j) ? $j : false;
}

/* قراءة البيانات الحالية: المصدر الرئيسي، وإن فسد/فُقد → آخر نسخة سليمة
 * $alreadyLocked: المستدعي يحمل القفل بالفعل (لا تقفل داخلياً) */
function crm_read_data($autoRecover = true, $alreadyLocked = false) {
  $p = crm_data_path();
  if ($p !== false && file_exists($p)) {
    $s = (string)file_get_contents($p);
    $j = crm_valid_data_str($s);
    if ($j !== false) return $j;
    // (السقوط إلى الاسترجاع أدناه)
  } else {
    // الملف غير موجود أصلاً (تثبيت جديد، أو مسحه الهوست) → جرّب النسخ الاحتياطية
    if (!$autoRecover) return array();
  }

  // الملف فاسد أو مفقود → حاول آخر نسخة احتياطية سليمة
  if ($autoRecover && $p !== false) {
    $lock = $alreadyLocked ? null : crm_lock();
    $bk = crm_newest_valid_backup();
    $jj = ($bk !== null) ? crm_valid_data_str((string)file_get_contents($bk)) : false;
    if ($jj !== false) {
      crm_atomic_write($p, json_encode($jj, JSON_UNESCAPED_UNICODE));
      $m = crm_read_meta(); $m['counts']['recover']++; crm_write_meta($m);
      crm_audit('recover', 'main file missing/corrupt, restored from ' . basename($bk));
    } else {
      $jj = false;
    }
    if (!$alreadyLocked) crm_unlock($lock);
    if ($jj !== false) return $jj;
    return array();
  }
  return array();
}

function crm_backup_files($prefix = 'b-') {
  $dir = crm_backups_dir();
  $out = array();
  if ($dir === false || !is_dir($dir)) return $out;
  foreach (scandir($dir) as $f) {
    if ($f === '.' || $f === '..') continue;
    if (strpos($f, $prefix) === 0 && substr($f, -5) === '.json') $out[] = $dir . '/' . $f;
  }
  sort($out); // التسمية بالوقت تصعّدها
  return $out;
}

function crm_newest_valid_backup($prefix = 'b-') {
  $files = array_reverse(crm_backup_files($prefix));
  foreach ($files as $f) {
    if (crm_valid_data_str((string)file_get_contents($f)) !== false) return $f;
  }
  // جرّب اليومية أيضاً
  $files = array_reverse(crm_backup_files('d-'));
  foreach ($files as $f) {
    if (crm_valid_data_str((string)file_get_contents($f)) !== false) return $f;
  }
  return null;
}

/* نسخة احتياطية قبل استبدال المحتوى الحالي + لقطة يومية بعد الكتابة */
function crm_backup_current($reason) {
  $p = crm_data_path();
  if ($p === false || !file_exists($p)) return;
  $s = (string)file_get_contents($p);
  if (!crm_valid_data_str($s)) return;
  $dir = crm_backups_dir();
  if ($dir === false) return;

  // لا تكرّر نسخاً متطابقة متتالية
  $prev = crm_backup_files('b-');
  if ($prev) {
    $last = end($prev);
    if (md5((string)file_get_contents($last)) === md5($s)) { /* نفس المحتوى: تخطَّ النسخة اللحظية */ }
    else { @copy($p, $dir . '/b-' . date('Ymd-His') . '-' . sprintf('%03d', mt_rand(0,999)) . '.json'); }
  } else {
    @copy($p, $dir . '/b-' . date('Ymd-His') . '-000.json');
  }
  crm_rotate('b-', CRM_BACKUPS_KEEP);

  // لقطة يومية (محتوى "قبل" التغيير — نقطة استرجاع لليوم)
  $daily = $dir . '/d-' . date('Ymd') . '.json';
  if (!file_exists($daily)) {
    @copy($p, $daily);
    crm_rotate('d-', CRM_DAILY_KEEP);
  }

  // سقف الحجم الكلي: احذف الأقدم أولاً (مع إبقاء آخر نسختين على الأقل)
  crm_backups_trim_size();
}
function crm_backups_trim_size() {
  $dir = crm_backups_dir();
  if ($dir === false || !is_dir($dir)) return;
  $max = isset($GLOBALS['CRM_BACKUPS_MAX_BYTES']) ? (int)$GLOBALS['CRM_BACKUPS_MAX_BYTES'] : 268435456;
  $files = array_merge(crm_backup_files('b-'), crm_backup_files('d-'));
  $total = 0;
  foreach ($files as $f) { $total += @filesize($f) ?: 0; }
  $n = count($files);
  $i = 0;
  while ($total > $max && $i < $n - 2) {
    $sz = @filesize($files[$i]) ?: 0;
    if (@unlink($files[$i])) { $total -= $sz; }
    $i++;
  }
}
function crm_rotate($prefix, $keep) {
  $files = crm_backup_files($prefix);
  $extra = count($files) - $keep;
  for ($i = 0; $i < $extra; $i++) { @unlink($files[$i]); }
}

function crm_canon_hash($d) {
  return hash('sha256', json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/* مسح تلقائي لبيانات الديمو (v2.4): إذا كانت الطلبيات المخزنة تطابق "بالكامل"
 * الـ seed التجريبي (168 طلبية) تُستبدل بقائمة فارغة — مرة واحدة فقط (علم في meta).
 * آمن 100%: أي بيانات حقيقية مختلطة لا تطابق → لا يُمَس أي شيء.
 * ترجع: 'wipe' (تم المسح) | 'checked' (دُوّن العلم) | 'none' (لا طلبيات بعد —
 * لا تدوّن العلم حتى لا يُستهلك قبل وصول أي داتا) */
function crm_maybe_wipe_demo(&$data) {
  if (isset($data['afrizon_orders_v5']['d'])) {
    $h = crm_canon_hash($data['afrizon_orders_v5']['d']);
    if (in_array($h, $GLOBALS['CRM_SEED_HASHES']['afrizon_orders_v5'], true)) {
      $data['afrizon_orders_v5'] = array('t' => crm_now_ms(), 'd' => array());
      return 'wipe';
    }
    return 'checked';
  }
  return 'none';
}

/* ===================================================================== */
/* دمج Digylog (شركة التوصيل) — تغيير Suivie فقط                         */
/* ===================================================================== */

/* ترتيب الحالات (من الأقل تقدماً للأكثر) — لمنع التراجع غير المنطقي.
 * قيمة أعلى = حالة أكثر تقدماً. الحالات النهائية (Livrée/Retour/Annulé/Refusé)
 * لا يمكن التراجع عنها إلا بـ force=1 من الويبهوك أو تدخل يدوي. */
$GLOBALS['CRM_LIVRAISON_ORDER'] = array(
  ''                => 0,
  'À préparer'     => 1,
  'Préparé'        => 2,
  'Expédié'        => 3,
  'En livraison'   => 4,
  'Out Of Stock'   => 5,
  'Problème livraison' => 5,
  'Livrée'         => 10,
  'Refusé'         => 10,
  'Retour'         => 10,
  'Annulé'         => 10,
);
/* الحالات النهائية التي لا تتراجع */
$GLOBALS['CRM_LIVRAISON_FINAL'] = array('Livrée' => true, 'Retour' => true, 'Refusé' => true);

/* نداء HTTP خارجي (curl إن وُجد، وإلا streams) — PHP 7.2+ */
function crm_http_json($url, $method = 'GET', $body = null, $headers = array()) {
  global $CRM_DIGYLOG_MOCK;
  if (is_array($CRM_DIGYLOG_MOCK) && array_key_exists('code', $CRM_DIGYLOG_MOCK)) {
    $mk = $CRM_DIGYLOG_MOCK['body'];
    return array((int)$CRM_DIGYLOG_MOCK['code'], is_string($mk) ? $mk : json_encode($mk), '');
  }
  $h = array_merge(array('Accept: application/json', 'Content-Type: application/json', 'Referer: https://apiseller.digylog.com'), $headers);
  $timeout = 25;
  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT => $timeout,
      CURLOPT_CONNECTTIMEOUT => 15,
      CURLOPT_HTTPHEADER => $h,
      CURLOPT_SSL_VERIFYPEER => true,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_MAXREDIRS => 3,
    ));
    if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $body === null ? '' : $body); }
    $out = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($out === false) return array(0, '', $err);
    return array($code, (string)$out, '');
  }
  $opts = array('http' => array('method' => $method, 'header' => implode("\r\n", $h) . "\r\n", 'timeout' => $timeout, 'ignore_errors' => true));
  if ($method === 'POST' && $body !== null) { $opts['http']['content'] = $body; }
  $fp = @fopen($url, 'rb', false, stream_context_create($opts));
  if ($fp === false) return array(0, '', 'stream-http-failed');
  $out = stream_get_contents($fp);
  $md = stream_get_meta_data($fp);
  @fclose($fp);
  $code = 0;
  $wh = (isset($md['wrapper_data']) && is_array($md['wrapper_data'])) ? $md['wrapper_data'] : array();
  foreach ($wh as $ln) { if (is_string($ln) && preg_match('#^HTTP/\S+\s+(\d+)#', $ln, $m)) { $code = (int)$m[1]; break; } }
  return array($code, (string)$out, '');
}

/* نداء HTTP مع Retry (3 محاولات مع انتظار تصاعدي) — لمكاملة Digylog */
function crm_http_json_retry($url, $method, $body, $headers, $maxAttempts = 3) {
  $lastCode = 0; $lastBody = ''; $lastErr = '';
  for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
    list($code, $resp, $err) = crm_http_json($url, $method, $body, $headers);
    $lastCode = $code; $lastBody = $resp; $lastErr = $err;
    // نجاح أو خطأ من العميل (4xx) → لا نعيد المحاولة
    if ($code >= 200 && $code < 300) return array($code, $resp, '');
    if ($code >= 400 && $code < 500 && $code !== 429) return array($code, $resp, '');
    // خطأ خادم (5xx) أو timeout (0) أو rate limit (429) → نعيد المحاولة
    if ($attempt < $maxAttempts) {
      $wait = $attempt * 2; // 2s, 4s
      crm_audit('digylog_retry', "attempt $attempt failed (HTTP $code, err=$err), waiting ${wait}s before retry");
      @sleep($wait);
    }
  }
  crm_audit('digylog_retry_exhausted', "all $maxAttempts attempts failed (HTTP $lastCode, err=$lastErr)");
  return array($lastCode, $lastBody, $lastErr ?: "exhausted after $maxAttempts attempts");
}

function crm_is_list($a) { return is_array($a) && (count($a) === 0 || array_keys($a) === range(0, count($a) - 1)); }

/* تحويل حالة Digylog → قيمة Suivie في الـ CRM (أو null = ما نبدلوش) */
function crm_digylog_map_status($raw) {
  if ($raw === null || $raw === '') return null;
  if (is_array($raw)) {
    foreach (array('name','label','title','code','status','state') as $k) {
      if (isset($raw[$k]) && is_scalar($raw[$k])) { $m = crm_digylog_map_status($raw[$k]); if ($m !== null) return $m; }
    }
    return null;
  }
  $s = strtolower((string)$raw);
  $s = strtr($s, array('é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','à'=>'a','â'=>'a','ç'=>'c','ï'=>'i','î'=>'i','ô'=>'o','û'=>'u','ù'=>'u'));
  if (strpos($s, 'livree') !== false || strpos($s, 'livre ') !== false || strpos($s, 'deliver') !== false || $s === 'livre') return 'Livrée';
  if (strpos($s, 'retour') !== false || strpos($s, 'return') !== false || strpos($s, 'rejet') !== false) return 'Retour';
  if (strpos($s, 'refus') !== false || strpos($s, 'reject') !== false || strpos($s, 'refuse') !== false) return 'Refusé';
  if (strpos($s, 'annul') !== false || strpos($s, 'cancel') !== false) return 'Annulé';
  if (strpos($s, 'stock') !== false || strpos($s, 'rupture') !== false) return 'Out Of Stock';
  if (strpos($s, 'probleme') !== false || strpos($s, 'problem') !== false || strpos($s, 'issue') !== false) return 'Problème livraison';
  if (strpos($s, 'prepar') !== false || strpos($s, 'preparing') !== false || strpos($s, 'pending') !== false) return 'À préparer';
  if (strpos($s, 'en cours de livraison') !== false || strpos($s, 'en livraison') !== false || strpos($s, 'out for delivery') !== false || strpos($s, 'en route') !== false || strpos($s, 'transit') !== false) return 'En livraison';
  if (strpos($s, 'expedi') !== false || strpos($s, 'shipped') !== false || strpos($s, 'enleve') !== false || strpos($s, 'ramass') !== false || strpos($s, 'envoye') !== false) return 'Expédié';
  return null;
}

/* رقم الطلبية داخل عنصر Digylog (num = المفتاح الأساسي للمطابقة) */
function crm_digylog_item_num($it) {
  if (!is_array($it)) return null;
  foreach (array('num','numero','order_num','order_number','tracking','tracking_num','tracking_number','barcode','reference','ref','colis','id') as $k) {
    if (isset($it[$k]) && is_scalar($it[$k]) && (string)$it[$k] !== '') return trim((string)$it[$k]);
  }
  return null;
}

/* حالة عنصر Digylog (خريطة أول مفتاح معروف) */
function crm_digylog_item_status($it) {
  if (!is_array($it)) return null;
  foreach (array('status','state','etat','statut','situation','order_status','delivery_status','last_status','statut_livraison','statut_colis') as $k) {
    if (isset($it[$k])) { $m = crm_digylog_map_status($it[$k]); if ($m !== null) return $m; }
  }
  foreach (array('tracking','delivery','shipment','last_event','event') as $k) {
    if (isset($it[$k]) && is_array($it[$k])) { $m = crm_digylog_map_status($it[$k]); if ($m !== null) return $m; }
  }
  return null;
}

/* استخراج لائحة الطلبيات من رد Digylog (أي شكل شائع) */
function crm_digylog_find_orders($resp) {
  $j = json_decode($resp, true);
  if (!is_array($j)) return null;
  $cands = array();
  if (crm_is_list($j)) $cands[] = $j;
  foreach (array('data','orders','items','list','result','results','rows','parcels','colis') as $k) {
    if (isset($j[$k]) && is_array($j[$k])) {
      if (crm_is_list($j[$k])) $cands[] = $j[$k];
      elseif (isset($j[$k]['data']) && crm_is_list($j[$k]['data'])) $cands[] = $j[$k]['data'];
    }
  }
  $best = null;
  foreach ($cands as $c) { if ($best === null || count($c) > count($best)) $best = $c; }
  return $best;
}

/* منع Status Regression: الحالات النهائية لا تتراجع.
 * يرجع true إذا كان التحديث مقبول، false إذا كان تراجع غير مسموح. */
function crm_digylog_can_update($currentLivraison, $currentStatut, $newStatus) {
  $order = isset($GLOBALS['CRM_LIVRAISON_ORDER']) ? $GLOBALS['CRM_LIVRAISON_ORDER'] : array();
  $final = isset($GLOBALS['CRM_LIVRAISON_FINAL']) ? $GLOBALS['CRM_LIVRAISON_FINAL'] : array();

  // إذا كانت الحالة الحالية نهائية (Livrée/Retour/Refusé) → لا نسمح بالتراجع
  if (isset($final[$currentLivraison]) && $final[$currentLivraison]) {
    // الاستثناء: Annulé يمكنه العودة لأي حالة (طلب ملغى يرجع للحياة)
    if ($currentStatut === 'Annulé') return true;
    return false;
  }

  // مقارنة الترتيب: لا نقبل تراجع كبير
  $curRank = isset($order[$currentLivraison]) ? $order[$currentLivraison] : 0;
  $newRank = isset($order[$newStatus]) ? $order[$newStatus] : 0;
  // نسمح بالتقدم أو بنفس المستوى (تحديث جانبي) لكن ليس التراجع الكبير
  if ($newRank < $curRank && ($curRank - $newRank) > 2) {
    crm_audit('digylog_regression_blocked', "blocked: $currentLivraison(rank $curRank) -> $newStatus(rank $newRank)");
    return false;
  }
  return true;
}

/* تطبيق حالات Digylog على طلبيات الـ CRM — مع حماية من التراجع + Audit Log */
function crm_digylog_apply(&$orders, $items, &$log) {
  if (!is_array($orders)) $orders = array();
  if (!is_array($items)) $items = array();

  // بناء index: idCmd + tracking → indices
  $byNum = array();
  foreach ($orders as $idx => $o) {
    if (!is_array($o)) continue;
    // المفتاح الأساسي: idCmd (رقم الطلبية في CRM)
    $n = isset($o['idCmd']) ? strtolower(trim((string)$o['idCmd'])) : '';
    if ($n !== '') { if (!isset($byNum[$n])) $byNum[$n] = array(); $byNum[$n][] = $idx; }
    // مفتاح ثانوي: tracking (نفس القيمة في العادة، لكن قد تكون مختلفة)
    $tr = isset($o['tracking']) ? strtolower(trim((string)$o['tracking'])) : '';
    if ($tr !== '' && $tr !== $n) { if (!isset($byNum[$tr])) $byNum[$tr] = array(); $byNum[$tr][] = $idx; }
  }

  $updated = array();
  $skipped = array('no_match' => 0, 'same_status' => 0, 'regression' => 0, 'no_tracking' => 0);

  foreach ($items as $it) {
    $num = crm_digylog_item_num($it);
    $st  = crm_digylog_item_status($it);

    if ($num === null || $num === '') { $skipped['no_tracking']++; continue; }
    if ($st === null) { continue; } // حالة غير معروفة → تجاهل

    $nk = strtolower(trim($num));
    if (!isset($byNum[$nk])) {
      // Tracking غير موجود في CRM → تسجيل فقط، لا ننشئ طلبية جديدة
      $skipped['no_match']++;
      crm_audit('digylog_no_match', "tracking=$num status=$st — not found in CRM, skipped");
      continue;
    }

    foreach ($byNum[$nk] as $idx) {
      $o   = $orders[$idx];
      $curLiv = isset($o['livraison']) ? (string)$o['livraison'] : '';
      $curStt = isset($o['statut']) ? (string)$o['statut'] : '';
      $idCmd = isset($o['idCmd']) ? $o['idCmd'] : $num;

      // لا تغيير → تخطي
      if ($curLiv === $st && $curStt !== 'Annulé' && $st !== 'Annulé') {
        $skipped['same_status']++;
        continue;
      }

      // منع التراجع (Status Regression Prevention)
      if ($st !== 'Annulé' && !crm_digylog_can_update($curLiv, $curStt, $st)) {
        $skipped['regression']++;
        continue;
      }

      // تطبيق التحديث الجزئي (حالة التوصيل فقط — لا نعدل أي حقل آخر)
      if ($st === 'Annulé') {
        if ($curStt === 'Annulé') { $skipped['same_status']++; continue; }
        $o['statut'] = 'Annulé';
      } else {
        $o['livraison'] = $st;
        // إذا كانت الطلبية ملغاة وأصبحت الآن حية → استعادة حالة Confirmé
        if ($curStt === 'Annulé') $o['statut'] = 'Confirmé';
      }

      $orders[$idx] = $o;
      $updated[] = array(
        'idCmd'  => $idCmd,
        'old_livraison' => $curLiv,
        'new_livraison' => $st,
        'old_statut'    => $curStt,
        'new_statut'    => isset($o['statut']) ? $o['statut'] : $curStt,
      );

      // Audit Log فردي لكل تغيير
      crm_audit('digylog_status_change', "order=$idCmd | livraison: '$curLiv' -> '$st' | statut: '$curStt' -> '" . (isset($o['statut']) ? $o['statut'] : $curStt) . "' | source=Digylog");
    }
  }

  $log = array('updated' => $updated, 'skipped' => $skipped);
  return count($updated);
}

/* كتابة الطلبيات بعد التحديث (نفس آليات النسخة المحصّنة) */
function crm_digylog_write_orders(&$data, $orders, $event) {
  if (crm_atomic_write(crm_data_path(), json_encode($data, JSON_UNESCAPED_UNICODE)) === false) return false;
  $meta = crm_read_meta();
  $meta['counts']['write']++;
  $meta['last_write'] = time();
  $meta['last_write_ip'] = crm_ip();
  crm_write_meta($meta);
  crm_audit($event, count($orders) . ' orders written');
  return true;
}

/* Rate Limiting: لا أكثر من مزامنة واحدة كل N ثانية */
function crm_digylog_rate_ok($cfg) {
  $lastSync = isset($cfg['last_sync']) ? (int)$cfg['last_sync'] : 0;
  $interval = isset($cfg['sync_interval']) ? max(60, (int)$cfg['sync_interval']) : 300; // 5 دقائق افتراضياً
  return (time() - $lastSync) >= $interval;
}

/* مستقبل الـ webhook ديال Digylog (POST أو GET) — محمي بـ secret */
function crm_digylog_webhook_handle($in) {
  $lock = crm_lock();
  $data = crm_read_data(true, true);
  $cfg = (isset($data['afrizon_digylog_v1']['d']) && is_array($data['afrizon_digylog_v1']['d'])) ? $data['afrizon_digylog_v1']['d'] : array();
  $sec = isset($cfg['webhook']) ? (string)$cfg['webhook'] : '';
  $secret = isset($in['secret']) ? (string)$in['secret'] : '';
  if ($sec === '' || $secret === '' || !hash_equals($sec, $secret)) { crm_unlock($lock); return array('ok'=>false, 'err'=>'secret'); }
  $num = isset($in['num']) && is_scalar($in['num']) ? (string)$in['num'] : '';
  $rawSt = isset($in['status']) ? $in['status'] : (isset($in['state']) ? $in['state'] : (isset($in['statut']) ? $in['statut'] : null));
  $st = crm_digylog_map_status($rawSt);
  if ($num === '' || $st === null) { crm_unlock($lock); return array('ok'=>false, 'err'=>'bad-params', 'num'=>$num, 'status'=>$rawSt); }
  $orders = (isset($data['afrizon_orders_v5']['d']) && is_array($data['afrizon_orders_v5']['d'])) ? $data['afrizon_orders_v5']['d'] : array();
  $log = array();
  $n = crm_digylog_apply($orders, array(array('num'=>$num, 'status'=>$st)), $log);
  if ($n > 0) {
    crm_backup_current('digylog-webhook');
    $data['afrizon_orders_v5'] = array('t' => crm_now_ms(), 'd' => $orders);
    if (!crm_digylog_write_orders($data, $orders, 'digylog_webhook ' . $num . ' -> ' . $st)) { crm_unlock($lock); return array('ok'=>false, 'err'=>'write'); }
  }
  crm_unlock($lock);
  $updates = isset($log['updated']) ? $log['updated'] : array();
  return array('ok'=>true, 'num'=>$num, 'suivie'=>$st, 'updated'=>$n, 'changes'=>$updates);
}

/* ============================================ */
/* Digylog Cron: نقطة نهاية للمزامنة التلقائية  */
/* (تُستدعى من server cron كل 5-15 دقيقة)       */
/* ============================================ */
/* جلب قائمة المتاجر من الإعدادات (يدعم store واحد أو stores متعددة) */
function crm_digylog_get_stores($cfg) {
  // Multi-store mode: array of stores
  if (isset($cfg['stores']) && is_array($cfg['stores']) && count($cfg['stores']) > 0) {
    return $cfg['stores'];
  }
  // Single-store mode (backward compatible)
  if (isset($cfg['store']) && is_string($cfg['store']) && $cfg['store'] !== '') {
    return array($cfg['store']);
  }
  return array();
}

/* تشخيص Digylog API — المرحلة 10: GET webhook + OPTIONS + HEAD */
function crm_digylog_diagnose($ordersUrl, $token, $network, $storeName) {
  $results = array();
  $base = preg_replace('#/seller/.*$#', '/seller', $ordersUrl);
  $authHeaders = array('Authorization: Bearer ' . $token);

  // Test 1: GET /seller/webhook
  $webhookUrl = $base . '/webhook';
  list($c, $b, $e) = crm_http_json($webhookUrl, 'GET', null, $authHeaders);
  $results[] = array('test'=>'GET /seller/webhook', 'http'=>$c, 'response'=>substr((string)$b,0,500), 'err'=>$e);

  // Test 2: GET /seller/webhook with store_id query param
  $webhookUrl2 = $base . '/webhook?store_id=32739';
  list($c, $b, $e) = crm_http_json($webhookUrl2, 'GET', null, $authHeaders);
  $results[] = array('test'=>'GET /seller/webhook?store_id=32739', 'http'=>$c, 'response'=>substr((string)$b,0,500), 'err'=>$e);

  // Test 3: OPTIONS /seller/orders (show allowed methods)
  if (function_exists('curl_init')) {
    $ch = curl_init($ordersUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'OPTIONS');
    curl_setopt($ch, CURLOPT_HTTPHEADER, $authHeaders);
    $b3 = curl_exec($ch);
    $c3 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $h3 = curl_getinfo($ch, CURLINFO_HEADER_OUT);
    $allow = '';
    // Get response headers
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_close($ch);
    $results[] = array('test'=>'OPTIONS /seller/orders', 'http'=>$c3, 'response'=>substr((string)$b3,0,500), 'err'=>'');
  }

  // Test 4: GET /seller/webhook/32739 (store_id in path)
  $webhookUrl4 = $base . '/webhook/32739';
  list($c, $b, $e) = crm_http_json($webhookUrl4, 'GET', null, $authHeaders);
  $results[] = array('test'=>'GET /seller/webhook/{store_id}', 'http'=>$c, 'response'=>substr((string)$b,0,500), 'err'=>$e);

  // Test 5: GET /seller/orders?store=32739 (store as ID not name)
  $ordersUrl5 = $ordersUrl . '?store=32739';
  list($c, $b, $e) = crm_http_json($ordersUrl5, 'GET', null, $authHeaders);
  $results[] = array('test'=>'GET /seller/orders?store=32739 (ID)', 'http'=>$c, 'response'=>substr((string)$b,0,500), 'err'=>$e);

  // Test 6: POST /seller/orders with Mode as form field (multipart)
  if (function_exists('curl_init')) {
    $ch = curl_init($ordersUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, array('Mode' => 'status', 'orders' => '[]'));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $authHeaders);
    $b6 = curl_exec($ch);
    $c6 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $results[] = array('test'=>'POST /orders with multipart form-data', 'http'=>$c6, 'response'=>substr((string)$b6,0,500), 'err'=>'');
  }

  // Test 7: GET /seller/profile (account info)
  $profileUrl = $base . '/profile';
  list($c, $b, $e) = crm_http_json($profileUrl, 'GET', null, $authHeaders);
  $results[] = array('test'=>'GET /seller/profile', 'http'=>$c, 'response'=>substr((string)$b,0,500), 'err'=>$e);

  // Test 8: GET /seller/account
  $accountUrl = $base . '/account';
  list($c, $b, $e) = crm_http_json($accountUrl, 'GET', null, $authHeaders);
  $results[] = array('test'=>'GET /seller/account', 'http'=>$c, 'response'=>substr((string)$b,0,500), 'err'=>$e);

  // Test 9: GET /seller/dashboard
  $dashUrl = $base . '/dashboard';
  list($c, $b, $e) = crm_http_json($dashUrl, 'GET', null, $authHeaders);
  $results[] = array('test'=>'GET /seller/dashboard', 'http'=>$c, 'response'=>substr((string)$b,0,500), 'err'=>$e);

  // Test 10: GET /seller/api (API docs or info)
  $apiUrl = $base . '/api';
  list($c, $b, $e) = crm_http_json($apiUrl, 'GET', null, $authHeaders);
  $results[] = array('test'=>'GET /seller/api', 'http'=>$c, 'response'=>substr((string)$b,0,500), 'err'=>$e);

  return $results;
}

/* جلب الطلبات من متجر واحد في Digylog */
function crm_digylog_fetch_store($ordersUrl, $token, $network, $storeName) {
  $authHeaders = array('Authorization: Bearer ' . $token);

  // نجرب عدة طرق:
  $attempts = array();

  // 1) POST /orders مع body (network + store)
  $pl = json_encode(array('network' => (int)$network, 'store' => $storeName));
  list($code, $body, $err) = crm_http_json($ordersUrl, 'POST', $pl, $authHeaders);
  if ($code >= 200 && $code < 300) {
    return crm_digylog_parse_response($body, $storeName);
  }
  $attempts[] = "POST $ordersUrl => HTTP $code";

  // 2) GET /orders?store=X&network=Y
  $url2 = $ordersUrl . '?' . http_build_query(array('store' => $storeName, 'network' => (int)$network));
  list($code, $body, $err) = crm_http_json($url2, 'GET', null, $authHeaders);
  if ($code >= 200 && $code < 300) {
    return crm_digylog_parse_response($body, $storeName);
  }
  $attempts[] = "GET $url2 => HTTP $code";

  // 3) GET /orders (no params)
  list($code, $body, $err) = crm_http_json($ordersUrl, 'GET', null, $authHeaders);
  if ($code >= 200 && $code < 300) {
    return crm_digylog_parse_response($body, $storeName);
  }
  $attempts[] = "GET $ordersUrl => HTTP $code";

  // 4) POST /orders/standard
  $altUrl = preg_replace('#/orders$#', '/orders/standard', $ordersUrl);
  if ($altUrl !== $ordersUrl) {
    list($code, $body, $err) = crm_http_json($altUrl, 'POST', $pl, $authHeaders);
    if ($code >= 200 && $code < 300) {
      return crm_digylog_parse_response($body, $storeName);
    }
    $attempts[] = "POST $altUrl => HTTP $code";
  }

  // 5) GET /orders/standard
  if ($altUrl !== $ordersUrl) {
    list($code, $body, $err) = crm_http_json($altUrl, 'GET', null, $authHeaders);
    if ($code >= 200 && $code < 300) {
      return crm_digylog_parse_response($body, $storeName);
    }
    $attempts[] = "GET $altUrl => HTTP $code";
  }

  // فشل كامل — نرجع التفاصيل للتشخيص
  $sampleBody = substr((string)$body, 0, 300);
  crm_audit('digylog_fetch_fail', "store=$storeName all attempts failed: " . implode(' | ', $attempts));
  return array('items' => array(), 'err' => 'all-failed: ' . implode(', ', $attempts), 'store' => $storeName, 'sample' => $sampleBody);
}

function crm_digylog_parse_response($body, $storeName) {
  $items = crm_digylog_find_orders($body);
  if ($items === null) {
    crm_audit('digylog_fetch_fail', "store=$storeName could not parse response body=" . substr($body, 0, 300));
    return array('items' => array(), 'err' => 'parse error', 'store' => $storeName, 'sample' => substr($body, 0, 300));
  }
  return array('items' => $items, 'err' => null, 'store' => $storeName, 'count' => count($items));
}

/* ============================================ */
/* Digylog Cron: نقطة نهاية للمزامنة التلقائية  */
/* يدعم Multiple Stores (6 متاجر)               */
/* ============================================ */
function crm_digylog_cron_run() {
  $lock = crm_lock();
  $data = crm_read_data(true, true);
  $cfg = (isset($data['afrizon_digylog_v1']['d']) && is_array($data['afrizon_digylog_v1']['d'])) ? $data['afrizon_digylog_v1']['d'] : array();
  $token = isset($cfg['token']) ? (string)$cfg['token'] : '';

  if ($token === '') {
    crm_unlock($lock);
    return array('ok' => false, 'err' => 'no-token', 'msg' => 'لم يتم إعداد التوكن بعد');
  }

  // Rate Limiting
  if (!crm_digylog_rate_ok($cfg)) {
    $wait = (isset($cfg['sync_interval']) ? (int)$cfg['sync_interval'] : 300) - (time() - (isset($cfg['last_sync']) ? (int)$cfg['last_sync'] : 0));
    crm_unlock($lock);
    return array('ok' => true, 'synced' => 0, 'msg' => 'Rate limited — next sync in ' . max(0, $wait) . 's');
  }

  $ordersUrl = (isset($cfg['ordersUrl']) && is_string($cfg['ordersUrl']) && $cfg['ordersUrl'] !== '')
    ? $cfg['ordersUrl']
    : 'https://api.digylog.com/api/v2/seller/orders';
  $network = isset($cfg['network']) ? $cfg['network'] : 29784;
  $stores = crm_digylog_get_stores($cfg);

  if (count($stores) === 0) {
    crm_unlock($lock);
    return array('ok' => false, 'err' => 'no-stores', 'msg' => 'لم يتم إعداد أي متجر');
  }

  // جلب الطلبات من كل المتاجر
  $allItems = array();
  $storeResults = array();
  foreach ($stores as $storeName) {
    $result = crm_digylog_fetch_store($ordersUrl, $token, $network, $storeName);
    $storeResults[] = array(
      'store' => $storeName,
      'items' => isset($result['count']) ? $result['count'] : 0,
      'err'   => $result['err'],
    );
    if (!empty($result['items'])) {
      $allItems = array_merge($allItems, $result['items']);
    }
    // تأخير قصير بين المتاجر (rate limiting)
    if (count($stores) > 1) @usleep(500000); // 0.5 ثانية
  }

  if (count($allItems) === 0) {
    $hasErrors = false;
    foreach ($storeResults as $sr) { if ($sr['err'] !== null) $hasErrors = true; }
    if ($hasErrors) {
      crm_unlock($lock);
      return array('ok' => false, 'err' => 'all-stores-failed', 'stores' => $storeResults);
    }
  }

  // تطبيق التحديثات
  $orders = (isset($data['afrizon_orders_v5']['d']) && is_array($data['afrizon_orders_v5']['d']))
    ? $data['afrizon_orders_v5']['d'] : array();
  $log = array();
  $n = crm_digylog_apply($orders, $allItems, $log);

  if ($n > 0) {
    crm_backup_current('digylog-cron');
    $data['afrizon_orders_v5'] = array('t' => crm_now_ms(), 'd' => $orders);
    if (!crm_digylog_write_orders($data, $orders, 'digylog_cron updated ' . $n . ' from ' . count($stores) . ' stores')) {
      crm_unlock($lock);
      return array('ok' => false, 'err' => 'write');
    }
  }

  // تحديث وقت آخر مزامنة
  $cfg['last_sync'] = time();
  $cfg['last_sync_result'] = array('synced' => $n, 'checked' => count($allItems), 'stores' => $storeResults, 'time' => date('Y-m-d H:i:s'));
  $data['afrizon_digylog_v1'] = array('t' => crm_now_ms(), 'd' => $cfg);
  crm_atomic_write(crm_data_path(), json_encode($data, JSON_UNESCAPED_UNICODE));

  crm_unlock($lock);
  $updates = isset($log['updated']) ? $log['updated'] : array();
  $skipped = isset($log['skipped']) ? $log['skipped'] : array();
  return array(
    'ok'      => true,
    'synced'  => $n,
    'checked' => count($allItems),
    'stores'  => $storeResults,
    'updated' => $updates,
    'skipped' => $skipped,
    'time'    => date('Y-m-d H:i:s'),
  );
}

/* ===================================================================== */
/* المعالجة الرئيسية                                                     */
/* ===================================================================== */

try {
  $m = $_SERVER['REQUEST_METHOD'] ?? 'GET';

  /* ---------- Digylog Webhook Handler (Tracking only — no auto status changes) ---------- */
  if (isset($_GET['action']) && $_GET['action'] === 'digylog_webhook') {
    header('Content-Type: application/json');
    $m = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    
    // PUT: Verification handshake
    if ($m === 'PUT') {
      $raw = file_get_contents('php://input');
      $b = json_decode($raw, true);
      crm_audit('digylog_webhook_put', $raw);
      
      if (is_array($b) && isset($b['type']) && $b['type'] === 'subscribe' && isset($b['key'])) {
        $lock = crm_lock();
        $data = crm_read_data();
        if (!isset($data['afrizon_digylog_v1'])) $data['afrizon_digylog_v1'] = array('t' => crm_now_ms(), 'd' => array());
        $cfg = is_array($data['afrizon_digylog_v1']['d']) ? $data['afrizon_digylog_v1']['d'] : array();
        $cfg['webhook_key'] = $b['key'];
        $cfg['webhook_verified'] = time();
        $data['afrizon_digylog_v1'] = array('t' => crm_now_ms(), 'd' => $cfg);
        crm_atomic_write(crm_data_path(), json_encode($data, JSON_UNESCAPED_UNICODE));
        crm_unlock($lock);
        echo json_encode(array('key' => $b['key']));
        exit;
      }
      
      // PUT with eventType = webhook event
      if (is_array($b) && (isset($b['eventType']) || isset($b['type']))) {
        $GLOBALS['CRM_WEBHOOK_PUT_DATA'] = $b;
      } else {
        http_response_code(400);
        echo json_encode(array('error' => 'Invalid PUT'));
        exit;
      }
    }
    
    // POST or PUT with event: Save tracking number + update status
    if ($m === 'POST' || ($m === 'PUT' && isset($GLOBALS['CRM_WEBHOOK_PUT_DATA']))) {
      $b = isset($GLOBALS['CRM_WEBHOOK_PUT_DATA']) ? $GLOBALS['CRM_WEBHOOK_PUT_DATA'] : json_decode(file_get_contents('php://input'), true);
      crm_audit('digylog_webhook_post', json_encode($b));
      
      if (!is_array($b)) { http_response_code(400); echo json_encode(array('error' => 'Invalid JSON')); exit; }
      
      $payload = isset($b['payload']) && is_array($b['payload']) ? $b['payload'] : $b;
      $digylogTracking = isset($payload['traking']) ? (string)$payload['traking'] : (isset($payload['tracking']) ? (string)$payload['tracking'] : '');
      $digylogNum = isset($payload['num']) ? (string)$payload['num'] : '';
      $status = isset($payload['status']) ? (string)$payload['status'] : '';
      $suivie = crm_digylog_map_status($status);
      
      $lock = crm_lock();
      $data = crm_read_data();
      $orders = isset($data['afrizon_orders_v5']['d']) ? $data['afrizon_orders_v5']['d'] : array();
      $updated = false;
      $updatedOrders = array();
      
      // Match by tracking or digylog_num ONLY (safe — no auto-link)
      foreach ($orders as &$order) {
        $matched = false;
        if ($digylogTracking !== '' && isset($order['tracking']) && $order['tracking'] !== '' && strtolower((string)$order['tracking']) === strtolower($digylogTracking)) {
          $matched = true;
        } elseif ($digylogNum !== '' && isset($order['digylog_num']) && (string)$order['digylog_num'] === $digylogNum) {
          $matched = true;
        }
        
        if ($matched) {
          $oldLivraison = isset($order['livraison']) ? $order['livraison'] : '';
          if ($digylogTracking !== '') $order['tracking'] = $digylogTracking;
          if ($digylogNum !== '') $order['digylog_num'] = $digylogNum;
          if ($suivie !== null) $order['livraison'] = $suivie;
          $updated = true;
          $updatedOrders[] = array('id' => isset($order['id']) ? $order['id'] : '?', 'tracking' => $digylogTracking, 'old' => $oldLivraison, 'new' => $suivie);
          crm_audit('digylog_webhook_updated', "Order " . (isset($order['id']) ? $order['id'] : '?') . " tracking=$digylogTracking: $oldLivraison -> $suivie");
        }
      }
      unset($order);
      
      if ($updated) {
        $data['afrizon_orders_v5']['d'] = $orders;
        $data['afrizon_orders_v5']['t'] = crm_now_ms();
        if (!isset($data['afrizon_digylog_v1'])) $data['afrizon_digylog_v1'] = array('t' => crm_now_ms(), 'd' => array());
        $cfg = is_array($data['afrizon_digylog_v1']['d']) ? $data['afrizon_digylog_v1']['d'] : array();
        if (!isset($cfg['webhook_updates'])) $cfg['webhook_updates'] = array();
        $cfg['webhook_updates'][] = array('time' => date('Y-m-d H:i:s'), 'tracking' => $digylogTracking, 'status' => $suivie, 'orders' => array_map(function($o) { return $o['id']; }, $updatedOrders));
        if (count($cfg['webhook_updates']) > 50) $cfg['webhook_updates'] = array_slice($cfg['webhook_updates'], -50);
        $data['afrizon_digylog_v1'] = array('t' => crm_now_ms(), 'd' => $cfg);
        crm_atomic_write(crm_data_path(), json_encode($data, JSON_UNESCAPED_UNICODE));
      } else {
        crm_audit('digylog_webhook_no_match', "tracking=$digylogTracking num=$digylogNum status=$status");
      }
      
      crm_unlock($lock);
      echo json_encode(array('ok' => true, 'updated' => $updated, 'tracking' => $digylogTracking, 'orders' => $updatedOrders));
      exit;
    }
    
    // GET: Status
    if ($m === 'GET') {
      $lock = crm_lock();
      $data = crm_read_data();
      crm_unlock($lock);
      $digylog = isset($data['afrizon_digylog_v1']['d']) ? $data['afrizon_digylog_v1']['d'] : array();
      echo json_encode(array(
        'ok' => true,
        'webhook_key' => isset($digylog['webhook_key']) ? substr($digylog['webhook_key'], 0, 16) . '...' : 'not set',
        'webhook_verified' => isset($digylog['webhook_verified']) ? date('Y-m-d H:i:s', $digylog['webhook_verified']) : 'never',
        'total_updates' => isset($digylog['webhook_updates']) ? count($digylog['webhook_updates']) : 0,
        'recent_updates' => isset($digylog['webhook_updates']) ? array_slice($digylog['webhook_updates'], -5) : array()
      ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
      exit;
    }
  }

  /* ---------- GET ---------- */
  if ($m === 'GET') {
    $action = isset($_GET['action']) ? $_GET['action'] : '';

    if ($action === 'status' || $action === 'backups') {
      if (crm_token() !== $SECRET) crm_out(array('ok'=>false, 'err'=>'token'), 403);
      list($dir, $mode) = crm_storage_dir();
      if ($dir === false) crm_out(array('ok'=>false, 'err'=>'storage-not-writable'), 500);
      crm_boot_meta();
      $meta = crm_read_meta();

      if ($action === 'backups') {
        $list = array();
        foreach (array_merge(array_reverse(crm_backup_files('b-')), array_reverse(crm_backup_files('d-'))) as $f) {
          $list[] = array('file'=>basename($f), 'bytes'=>filesize($f), 'mtime'=>date('Y-m-d H:i:s', filemtime($f)));
        }
        crm_out(array('ok'=>true, 'backups'=>$list));
      }

      $data = crm_read_data();
      $keysInfo = array();
      foreach ($KEYS as $k) {
        if (isset($data[$k]) && is_array($data[$k])) {
          $t = isset($data[$k]['t']) ? (int)$data[$k]['t'] : 0;
          $keysInfo[] = array(
            'key'=>$k, 't'=>$t,
            'age_h'=> $t ? round((crm_now_ms()-$t)/3600000, 1) : null,
            'bytes'=>strlen(json_encode($data[$k]['d'] ?? null, JSON_UNESCAPED_UNICODE)),
          );
        }
      }
      $backups = array();
      foreach (array_slice(array_reverse(crm_backup_files('b-')), 0, 10) as $f) {
        $backups[] = array('file'=>basename($f), 'bytes'=>filesize($f), 'mtime'=>date('Y-m-d H:i:s', filemtime($f)));
      }
      $auditTail = array();
      $ap = crm_audit_path();
      if ($ap && file_exists($ap)) {
        $lines = array_slice(file($ap, FILE_IGNORE_NEW_LINES), -25);
        $auditTail = $lines;
      }
      crm_out(array(
        'ok'=>true,
        'server_time'=>date('Y-m-d H:i:s'),
        'php'=>PHP_VERSION,
        'storage'=>array('mode'=>$mode, 'dir'=>basename($dir), 'writable'=>is_writable($dir)),
        'install'=>array('id'=>$meta['install_id'], 'since'=>date('Y-m-d H:i:s', $meta['install_time'])),
        'data'=>array('exists'=>file_exists(crm_data_path()), 'bytes'=>@filesize(crm_data_path()) ?: 0, 'keys'=>$keysInfo),
        'backups_count'=>count(crm_backup_files('b-')) + count(crm_backup_files('d-')),
        'backups_latest'=>$backups,
        'counters'=>$meta['counts'],
        'last_write'=> $meta['last_write'] ? date('Y-m-d H:i:s', $meta['last_write']) : null,
        'last_write_ip'=>$meta['last_write_ip'],
        'audit_tail'=>$auditTail,
        'note'=>'status/secure endpoint — v2 hardened sync',
      ));
    }

    /* Webhook ديال Digylog عبر GET (للشركات اللي كتبعتو بـ GET) — محمي بـ secret */
    // Digylog webhook is now handled at the top of the file (PUT verification + POST updates)

    /* Digylog Cron عبر GET — يُستدعى من server crontab أو خدمة cron خارجية.
     * محمي بالتوكن الإداري (X-Sync-Token أو ?token=).
     * مثال crontab: every 5 minutes curl -s "https://DOMAIN/api.php?action=digylog_cron&token=SECRET" */
    if ($action === 'digylog_cron') {
      if (crm_token() !== $SECRET) crm_out(array('ok'=>false, 'err'=>'token'), 403);
      crm_out(crm_digylog_cron_run());
    }



    /* Digylog Diagnose: تشخيص API — يجرب كل الطرق ويرجع النتائج */
    if ($action === 'digylog_diagnose') {
      if (crm_token() !== $SECRET) crm_out(array('ok'=>false, 'err'=>'token'), 403);
      $data = crm_read_data();
      $cfg = (isset($data['afrizon_digylog_v1']['d']) && is_array($data['afrizon_digylog_v1']['d'])) ? $data['afrizon_digylog_v1']['d'] : array();
      $token = isset($cfg['token']) ? (string)$cfg['token'] : '';
      if ($token === '') crm_out(array('ok'=>false, 'err'=>'no-token'), 400);
      $ordersUrl = isset($cfg['ordersUrl']) ? $cfg['ordersUrl'] : 'https://api.digylog.com/api/v2/seller/orders';
      $network = 29784;
      $testStore = 'Slim-Bio-Ay';
      $results = crm_digylog_diagnose($ordersUrl, $token, $network, $testStore);
      crm_out(array('ok'=>true, 'store_tested'=>$testStore, 'network_used'=>$network, 'tests'=>$results));
    }

    // القراءة العادية (كما في النسخة القديمة)
    list($dir, $mode) = crm_storage_dir();
    if ($dir === false) crm_out(array(), 500);
    crm_boot_meta();
    $data = crm_read_data();

    // مسح تلقائي لبيانات الديمو (مرة واحدة) — إلا كانت مطابقة تماماً للـ seed
    $meta = crm_read_meta();
    if (empty($meta['demo_wipe_done'])) {
      $lock = crm_lock();
      $wr = crm_maybe_wipe_demo($data);
      if ($wr === 'wipe') {
        crm_atomic_write(crm_data_path(), json_encode($data, JSON_UNESCAPED_UNICODE));
        crm_audit('demo_autowipe', 'exact factory demo orders replaced with an empty list');
      }
      if ($wr !== 'none') {
        $meta['demo_wipe_done'] = 1;
        crm_write_meta($meta);
      }
      crm_unlock($lock);
    }

    if (count($data) === 0) { echo '{}'; exit; }
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
  }

  /* ---------- POST ---------- */
  if ($m === 'POST') {
    if (crm_token() !== $SECRET) { crm_audit('reject', 'bad token'); crm_out(array('ok'=>false), 403); }

    list($dir, $mode) = crm_storage_dir();
    if ($dir === false) crm_out(array('ok'=>false, 'err'=>'storage-not-writable'), 500);
    crm_boot_meta();

    $raw = crm_raw_input();
    if (strlen($raw) > 64 * 1024 * 1024) crm_out(array('ok'=>false, 'err'=>'too-large'), 413);
    $b = json_decode($raw, true);
    if (!is_array($b)) crm_out(array('ok'=>false, 'err'=>'bad-json'), 400);

    /* استرجاع نسخة احتياطية (أداة إدارية) */
    if (isset($b['action']) && $b['action'] === 'restore') {
      $file = isset($b['file']) ? basename((string)$b['file']) : '';
      if (!preg_match('/^[A-Za-z0-9._-]+\.json$/', $file)) crm_out(array('ok'=>false, 'err'=>'bad-file'), 400);
      $src = crm_backups_dir() . '/' . $file;
      if (!file_exists($src)) crm_out(array('ok'=>false, 'err'=>'not-found'), 404);
      $jj = crm_valid_data_str((string)file_get_contents($src));
      if ($jj === false) crm_out(array('ok'=>false, 'err'=>'backup-invalid'), 500);

      $lock = crm_lock();
      crm_backup_current('pre-restore');
      // رفع t لكل المفاتيح إلى "الآن" حتى تتبناها كل الأجهزة فأقرب poll
      $nowRestore = crm_now_ms();
      foreach ($jj as $rk => $rv) {
        if (is_array($rv) && isset($rv['t'])) $jj[$rk]['t'] = max($nowRestore, (int)$rv['t']);
      }
      crm_atomic_write(crm_data_path(), json_encode($jj, JSON_UNESCAPED_UNICODE));
      $meta = crm_read_meta();
      $meta['counts']['restore']++;
      crm_write_meta($meta);
      crm_audit('restore', 'restored ' . $file);
      crm_unlock($lock);
      crm_out(array('ok'=>true, 'restored'=>$file));
    }

    /* ===== دمج Digylog: اختبار + مزامنة Suivie + webhook + cron + setup ===== */
    // Digylog webhook is now handled at the top of the file (PUT verification + POST updates)

    /* Digylog Setup: إعداد مسبق للتوكن والمتاجر (من Frontend أو API) */
    if (isset($b['action']) && $b['action'] === 'digylog_setup') {
      $lock = crm_lock();
      $data = crm_read_data(true, true);
      $cfg = (isset($data['afrizon_digylog_v1']['d']) && is_array($data['afrizon_digylog_v1']['d'])) ? $data['afrizon_digylog_v1']['d'] : array();

      // Token
      if (isset($b['token']) && is_string($b['token']) && $b['token'] !== '') $cfg['token'] = trim($b['token']);
      // Network
      if (isset($b['network'])) $cfg['network'] = is_numeric($b['network']) ? (int)$b['network'] : $b['network'];
      // Multiple Stores (array)
      if (isset($b['stores']) && is_array($b['stores']) && count($b['stores']) > 0) {
        $cfg['stores'] = array_values(array_filter(array_map('trim', $b['stores']), function($s) { return $s !== ''; }));
      }
      // Single store (backward compatible)
      if (isset($b['store']) && is_string($b['store']) && $b['store'] !== '') $cfg['store'] = trim($b['store']);
      // URLs
      if (isset($b['ordersUrl']) && is_string($b['ordersUrl'])) $cfg['ordersUrl'] = $b['ordersUrl'];
      if (isset($b['testUrl']) && is_string($b['testUrl'])) $cfg['testUrl'] = $b['testUrl'];
      // Webhook secret
      if (isset($b['webhook']) && is_string($b['webhook'])) $cfg['webhook'] = $b['webhook'];
      // Sync interval
      if (isset($b['sync_interval']) && is_numeric($b['sync_interval'])) $cfg['sync_interval'] = max(60, (int)$b['sync_interval']);

      $data['afrizon_digylog_v1'] = array('t' => crm_now_ms(), 'd' => $cfg);
      crm_atomic_write(crm_data_path(), json_encode($data, JSON_UNESCAPED_UNICODE));
      crm_audit('digylog_setup', 'config updated: token=' . (isset($cfg['token']) ? substr($cfg['token'], 0, 8) . '...' : 'none') . ' network=' . (isset($cfg['network']) ? $cfg['network'] : '?') . ' stores=' . (isset($cfg['stores']) ? count($cfg['stores']) : (isset($cfg['store']) ? 1 : 0)));
      crm_unlock($lock);

      crm_out(array(
        'ok' => true,
        'msg' => 'تم حفظ إعدادات Digylog',
        'config' => array(
          'has_token' => !empty($cfg['token']),
          'network' => isset($cfg['network']) ? $cfg['network'] : null,
          'stores' => crm_digylog_get_stores($cfg),
          'ordersUrl' => isset($cfg['ordersUrl']) ? $cfg['ordersUrl'] : 'https://api.digylog.com/api/v2/seller/orders',
          'has_webhook' => !empty($cfg['webhook']),
        ),
      ));
    }

    /* Digylog Cron: نقطة نهاية للمزامنة التلقائية — تُستدعى من server cron أو frontend timer */
    if (isset($b['action']) && $b['action'] === 'digylog_cron') {
      crm_out(crm_digylog_cron_run());
    }

    if (isset($b['action']) && ($b['action'] === 'digylog_test' || $b['action'] === 'digylog_sync')) {
      $lock = crm_lock();
      $data = crm_read_data(true, true);
      $cfg = (isset($data['afrizon_digylog_v1']['d']) && is_array($data['afrizon_digylog_v1']['d'])) ? $data['afrizon_digylog_v1']['d'] : array();
      $token = isset($cfg['token']) ? (string)$cfg['token'] : '';
      if ($token === '') { crm_unlock($lock); crm_out(array('ok'=>false, 'err'=>'no-token', 'msg'=>'حط توكن Digylog فالإعدادات أولاً'), 400); }

      if ($b['action'] === 'digylog_test') {
        $testUrl = (isset($b['testUrl']) && is_string($b['testUrl']) && $b['testUrl'] !== '') ? $b['testUrl'] : (isset($cfg['testUrl']) && $cfg['testUrl'] !== '' ? $cfg['testUrl'] : 'https://api.digylog.com/api/v2/seller/networks');
        list($code, $body, $err) = crm_http_json_retry($testUrl, 'GET', null, array('Authorization: Bearer ' . $token));
        crm_unlock($lock);
        if ($code === 0) crm_out(array('ok'=>false, 'err'=>'http', 'msg'=>'ما قدرناش نوصلو لـ Digylog: ' . $err));
        $j = json_decode($body, true);
        $networks = array();
        if (is_array($j)) {
          foreach (array('data','networks','items','result','results') as $k) { if (isset($j[$k]) && is_array($j[$k])) { $networks = $j[$k]; break; } }
          if (count($networks) === 0 && crm_is_list($j)) $networks = $j;
        }
        crm_out(array('ok'=>($code >= 200 && $code < 300), 'http'=>$code, 'networks'=>$networks, 'sample'=>substr($body, 0, 400)));
      }

      /* digylog_sync — مع Retry + Rate Limiting + Status Regression Prevention + Audit */
      // Rate Limiting: لا أكثر من مزامنة كل 60 ثانية (حماية يدوية متكررة)
      if (!isset($b['force']) || !$b['force']) {
        $lastSync = isset($cfg['last_sync']) ? (int)$cfg['last_sync'] : 0;
        if ((time() - $lastSync) < 60) {
          $wait = 60 - (time() - $lastSync);
          crm_unlock($lock);
          crm_out(array('ok'=>true, 'synced'=>0, 'msg'=>"آخر مزامنة قبل أقل من دقيقة — انتظر ${wait}s"));
        }
      }

      $ordersUrl = (isset($cfg['ordersUrl']) && is_string($cfg['ordersUrl']) && $cfg['ordersUrl'] !== '') ? $cfg['ordersUrl'] : 'https://api.digylog.com/api/v2/seller/orders';
      $network = isset($cfg['network']) ? $cfg['network'] : 29784;
      $stores = crm_digylog_get_stores($cfg);
      if (count($stores) === 0) { crm_unlock($lock); crm_out(array('ok'=>false, 'err'=>'no-stores', 'msg'=>'لم يتم إعداد أي متجر — دخل للمتاجر فالإعدادات')); }

      // جلب الطلبات من كل المتاجر
      $allItems = array();
      $storeResults = array();
      foreach ($stores as $storeName) {
        $result = crm_digylog_fetch_store($ordersUrl, $token, $network, $storeName);
        $storeResults[] = array('store' => $storeName, 'items' => isset($result['count']) ? $result['count'] : 0, 'err' => $result['err']);
        if (!empty($result['items'])) { $allItems = array_merge($allItems, $result['items']); }
        if (count($stores) > 1) @usleep(500000); // 0.5s بين المتاجر
      }

      $orders = (isset($data['afrizon_orders_v5']['d']) && is_array($data['afrizon_orders_v5']['d'])) ? $data['afrizon_orders_v5']['d'] : array();
      $log = array();
      $n = crm_digylog_apply($orders, $allItems, $log);
      if ($n > 0) {
        crm_backup_current('digylog-sync');
        $data['afrizon_orders_v5'] = array('t' => crm_now_ms(), 'd' => $orders);
        if (!crm_digylog_write_orders($data, $orders, 'digylog_sync updated ' . $n)) { crm_unlock($lock); crm_out(array('ok'=>false, 'err'=>'write'), 500); }
      }

      // تحديث وقت آخر مزامنة
      $cfg['last_sync'] = time();
      $cfg['last_sync_result'] = array('synced' => $n, 'checked' => count($allItems), 'stores' => $storeResults, 'time' => date('Y-m-d H:i:s'));
      $data['afrizon_digylog_v1'] = array('t' => crm_now_ms(), 'd' => $cfg);
      crm_atomic_write(crm_data_path(), json_encode($data, JSON_UNESCAPED_UNICODE));

      crm_unlock($lock);
      $updates = isset($log['updated']) ? $log['updated'] : array();
      $skipped = isset($log['skipped']) ? $log['skipped'] : array();
      crm_out(array('ok'=>true, 'synced'=>$n, 'updated'=>$updates, 'skipped'=>$skipped, 'checked'=>count($allItems), 'stores'=>$storeResults));
    }

    if (!isset($b['key']) || !isset($b['t']) || !isset($b['d'])) {
      crm_out(array('ok'=>false, 'err'=>'bad-body'), 400);
    }
    $k = (string)$b['key'];
    if (!in_array($k, $KEYS, true)) {
      crm_audit('reject', 'unknown key ' . $k);
      crm_out(array('ok'=>false, 'err'=>'bad-key'), 403);
    }
    $tIn = (int)$b['t'];
    $dIn = $b['d'];

    $lock = crm_lock();
    $data = crm_read_data(true, true); // داخل القفل + استرجاع تلقائي إن لزم
    $meta = crm_read_meta();

    // مسح تلقائي لبيانات الديمو (مرة واحدة) — إلا كانت مطابقة تماماً للـ seed
    if (empty($meta['demo_wipe_done'])) {
      $wr = crm_maybe_wipe_demo($data);
      if ($wr === 'wipe') {
        crm_atomic_write(crm_data_path(), json_encode($data, JSON_UNESCAPED_UNICODE));
        crm_audit('demo_autowipe', '(post) exact factory demo orders replaced with an empty list');
        $meta['demo_wipe_done'] = 1;
      } elseif ($wr === 'checked') {
        $meta['demo_wipe_done'] = 1;
      }
      // 'none': لا علم بعد — أول فحص حقيقي يبقى قادماً
    }

    $nowMs = crm_now_ms();
    $t = min($tIn, $nowMs + CRM_FUTURE_MS); // تثبيت ساعة الجهاز

    $oldEntry = (isset($data[$k]) && is_array($data[$k])) ? $data[$k] : null;
    $oldD   = $oldEntry ? (isset($oldEntry['d']) ? $oldEntry['d'] : null) : null;
    $oldT   = $oldEntry ? (int)(isset($oldEntry['t']) ? $oldEntry['t'] : 0) : 0;
    $oldH   = ($oldD !== null) ? crm_canon_hash($oldD) : null;
    $newH   = crm_canon_hash($dIn);

    /* 1) لا تغيير فعلي → نجاح صامت */
    if ($oldH !== null && $oldH === $newH) {
      $meta['counts']['noop']++;
      crm_write_meta($meta);
      crm_unlock($lock);
      crm_out(array('ok'=>true, 'noop'=>true));
    }

    /* 2) حارس بيانات المصنع: إرجاع الـ seed الافتراضي فوق بيانات موجودة → رفض */
    if ($oldD !== null && isset($GLOBALS['CRM_SEED_HASHES'][$k]) && in_array($newH, $GLOBALS['CRM_SEED_HASHES'][$k], true)) {
      $meta['counts']['ghost_seed']++;
      crm_write_meta($meta);
      crm_audit('ghost_seed', $k . ' | factory seed pushed over existing data | ua=' . crm_ua());
      crm_unlock($lock);
      // HTTP 200 + ok:false كيمنع إعادة المحاولة اللانهائية من الواجهة
      crm_out(array('ok'=>false, 'rejected'=>'ghost_seed'));
    }

    /* 3) حارس الأشباح العام: نسخة قديمة "مطابقة" عادت بعد مهلة السماح → رفض */
    $hist = array();
    if (isset($meta['keys'][$k]['hist']) && is_array($meta['keys'][$k]['hist'])) $hist = $meta['keys'][$k]['hist'];
    foreach ($hist as $i => $e) {
      if (isset($e['h']) && $e['h'] === $newH) {
        $seen = isset($e['seen']) ? (int)$e['seen'] : 0;
        if ((time() - $seen) > CRM_GHOST_GRACE) {
          $meta['counts']['ghost']++;
          crm_write_meta($meta);
          crm_audit('ghost', $k . ' | old identical version pushed after ' . round((time()-$seen)/60) . ' min | ua=' . crm_ua());
          crm_unlock($lock);
          crm_out(array('ok'=>false, 'rejected'=>'ghost'));
        }
        // ضمن مهلة السماح → إزالة من السجل وسيمرّ
        unset($hist[$i]);
        break;
      }
    }

    /* 4) كتابة قديمة زمنياً (ساعة خاطئة جداً) → تجاهُل بدون فشل */
    if ($oldEntry !== null && $t < $oldT) {
      $meta['counts']['stale']++;
      crm_write_meta($meta);
      crm_audit('stale', $k . ' | t=' . $tIn . ' < stored ' . $oldT);
      crm_unlock($lock);
      crm_out(array('ok'=>true, 'stale'=>true));
    }

    /* 5) احتياط ثم كتابة ذرّية */
    if ($oldEntry !== null) crm_backup_current('write');
    $data[$k] = array('t' => $t, 'd' => $dIn);
    $payload = count($data) === 0 ? '{}' : json_encode($data, JSON_UNESCAPED_UNICODE);
    if (crm_atomic_write(crm_data_path(), $payload) === false) {
      crm_audit('error', 'write failed for ' . $k);
      crm_unlock($lock);
      crm_out(array('ok'=>false, 'err'=>'write'), 500);
    }

    /* تحديث سجل البصمات: النسخة القديمة تصبح "معروفة" لرفض عودتها لاحقاً */
    if ($oldH !== null && $oldH !== $newH) {
      $hist = array_values(array_filter($hist, function($e) use ($oldH) { return !isset($e['h']) || $e['h'] !== $oldH; }));
      $hist[] = array('h'=>$oldH, 't'=>$oldT, 'seen'=>time());
      if (count($hist) > CRM_HIST_PER_KEY) $hist = array_slice($hist, -CRM_HIST_PER_KEY);
    }
    $mk = $meta['keys']; if (!is_array($mk)) $mk = array();
    $mk[$k] = array('hist'=>array_values($hist));
    $meta['keys'] = $mk;
    $meta['counts']['write']++;
    $meta['last_write'] = time();
    $meta['last_write_ip'] = crm_ip();
    crm_write_meta($meta);

    crm_audit('write', $k . ' | t=' . $t . ' | bytes=' . strlen($payload));
    crm_unlock($lock);
    crm_out(array('ok'=>true));
  }

  http_response_code(405);
  echo '{"ok":false}';
  exit;

} catch (Throwable $e) {
  @crm_audit('exception', substr($e->getMessage(), 0, 200));
  http_response_code(500);
  echo '{"ok":false,"err":"exception"}';
  exit;
}

/* تهيئة meta عند أول تشغيل (install_id ثابت — إذا تغيّر فهذا يعني مسح التخزين!) */
function crm_boot_meta() {
  $p = crm_meta_path();
  if ($p === false) return;
  if (!file_exists($p)) {
    $m = crm_fresh_meta();
    crm_atomic_write($p, json_encode($m, JSON_UNESCAPED_UNICODE));
    crm_audit('install', 'storage initialized');
  }
}
