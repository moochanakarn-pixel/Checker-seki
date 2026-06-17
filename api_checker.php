<?php
// ── Bootstrap ────────────────────────────────────────
ob_start();
ini_set('display_errors', 0);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// session ก่อน require เสมอ
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// กัน HTML หลุดเป็น response — ต้องทำก่อน require
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    // log เงียบๆ
    error_log("[$errno] $errstr in $errfile:$errline");
    return true;
});

set_exception_handler(function($e) {
    while (ob_get_level()) ob_end_clean();
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
});

// เช็คไฟล์ก่อน require
if (!file_exists(__DIR__ . '/config.php')) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'config.php missing']);
    exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_check.php';
session_write_close(); // release session file lock — ไม่มีการเขียน session หลังจุดนี้

// timeout
@set_time_limit(30);

// ── Activity Log ──────────────────────────────────────────────
function kdsLogDir()
{
    return __DIR__ . DIRECTORY_SEPARATOR . 'logs';
}

function kdsLogPath($cid, $date = null)
{
    $d = $date ?: date('Y-m-d');
    return kdsLogDir() . DIRECTORY_SEPARATOR . 'kds_cid' . (int)$cid . '_' . $d . '.log';
}

function purgeOldKdsLogs($cid)
{
    $dir = kdsLogDir();
    $pattern = $dir . DIRECTORY_SEPARATOR . 'kds_cid' . (int)$cid . '_*.log';
    $cutoff = strtotime('-7 days');
    foreach (glob($pattern) as $file) {
        if (filemtime($file) < $cutoff) {
            @unlink($file);
        }
    }
}

function writeActivityLog($action, $detail, $staffId = 0)
{
    $cid  = getEffectiveComputerId();
    $dir  = kdsLogDir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $line = date('Y-m-d H:i:s') . ' | ' . str_pad((string)$action, 14) . ' | CID:' . $cid . ' | Staff:' . (int)$staffId . ' | ' . $detail . PHP_EOL;
    $path = kdsLogPath($cid);
    @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
    purgeOldKdsLogs($cid);
}

function handleLogActivity()
{
    $action   = isset($_POST['action_type']) ? strtoupper(trim((string)$_POST['action_type'])) : '';
    $detail   = isset($_POST['detail'])      ? trim((string)$_POST['detail'])                  : '';
    $staffId  = isset($_POST['staff_id'])    ? (int)$_POST['staff_id']                         : 0;
    $allowed  = array('LOGIN','LOGOUT','ZONE_CHANGE','APP_START','SETTINGS_VIEW');
    if (!in_array($action, $allowed, true)) {
        jsonResponse(array('success' => false, 'error' => 'invalid action_type'));
        return;
    }
    writeActivityLog($action, $detail, $staffId);
    jsonResponse(array('success' => true));
}

// กัน barcode scan ซ้ำฝั่ง PHP
function guardDuplicateScan($code) {
    if (empty($code)) return false;
    $cid     = getEffectiveComputerId();
    $key     = 'last_scan_code_' . $cid;
    $timeKey = 'last_scan_time_' . $cid;
    if (isset($_SESSION[$key]) && $_SESSION[$key] === $code) {
        $elapsed = time() - (int)($_SESSION[$timeKey] ?? 0);
        if ($elapsed < 2) return true;
    }
    $_SESSION[$key]     = $code;
    $_SESSION[$timeKey] = time();
    return false;
}

function requestedMethod()
{
    return isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string)$_SERVER['REQUEST_METHOD']) : 'GET';
}

// ถ้า JS ส่ง kds_cid มา ให้ใช้ค่านั้น (รองรับ multi-KDS subfolder)
function getEffectiveComputerId()
{
    $cid = isset($_REQUEST['kds_cid']) ? (int)$_REQUEST['kds_cid'] : 0;
    return $cid > 0 ? $cid : (int)CURRENT_COMPUTER_ID;
}

// หาพาธของ settings.local.php ที่ถูกต้องสำหรับ KDS นั้น
// รับ kds_sub จาก request (เช่น "KDS1") เพื่อเขียนไฟล์ถูกโฟลเดอร์
function resolveKdsSettingsPath()
{
    $sub = isset($_REQUEST['kds_sub']) ? trim((string)$_REQUEST['kds_sub']) : '';
    // validate: อนุญาตเฉพาะ alphanumeric, dash, underscore — ห้าม / และ ..
    if ($sub !== '' && preg_match('/^[A-Za-z0-9_\-]+$/', $sub)) {
        $candidate = __DIR__ . DIRECTORY_SEPARATOR . $sub . DIRECTORY_SEPARATOR . 'settings.local.php';
        // ต้องมีโฟลเดอร์นั้นจริงๆ (checker.php อยู่ที่นั่น)
        if (is_dir(__DIR__ . DIRECTORY_SEPARATOR . $sub)) {
            return $candidate;
        }
    }
    return getSettingsLocalFilePath();
}

function requestedAction()
{
    $act = isset($_GET['action']) ? trim((string)$_GET['action']) : (isset($_POST['action']) ? trim((string)$_POST['action']) : 'list');
    return preg_replace('/[^a-z0-9_]/', '', $act) ?: 'list';
}

// แปลง comma-separated string หรือ array → array ของ int ที่ > 0
function parseIdList($value)
{
    if (is_array($value)) {
        return array_values(array_unique(array_filter(array_map('intval', $value), function($v) { return $v > 0; })));
    }
    $str = trim((string)$value);
    if ($str === '') return array();
    $ids = array();
    foreach (explode(',', $str) as $p) {
        $id = (int)trim($p);
        if ($id > 0) $ids[] = $id;
    }
    return array_values(array_unique($ids));
}

function normalizeSystemSettingsPayload($source)
{
    return array(
        'db_host' => trim((string)($source['db_host'] ?? '')),
        'db_port' => (int)($source['db_port'] ?? 3307),
        'db_name' => trim((string)($source['db_name'] ?? '')),
        'shop_id' => (int)($source['shop_id'] ?? 0),
        'current_computer_id' => (int)($source['current_computer_id'] ?? 0),
        'current_computer_name' => trim((string)($source['current_computer_name'] ?? '')),
        'finish_staff_id' => (int)($source['finish_staff_id'] ?? 0),
        'threshold_yellow' => (int)($source['threshold_yellow'] ?? 10),
        'threshold_red' => (int)($source['threshold_red'] ?? 20),
        'sound_enabled' => !empty($source['sound_enabled']) ? 1 : 0,
        'barcode_camera_enabled' => !empty($source['barcode_camera_enabled']) ? 1 : 0,
        'kds_two_step_checkout' => !empty($source['kds_two_step_checkout']) ? 1 : 0,
        'out_of_stock_enabled' => isset($source['out_of_stock_enabled']) ? (!empty($source['out_of_stock_enabled']) ? 1 : 0) : 1,
        'allowed_sale_mode_ids' => parseIdList($source['allowed_sale_mode_ids'] ?? ''),
        'allowed_zone_ids' => parseIdList($source['allowed_zone_ids'] ?? ''),
        'hide_staff_login' => !empty($source['hide_staff_login']) ? 1 : 0,
    );
}

function validateSystemSettingsPayload($settings)
{
    $errors = array();
    if ($settings['db_host'] === '') {
        $errors[] = 'กรุณากรอก DB Host / IP';
    }
    if ($settings['db_port'] <= 0 || $settings['db_port'] > 65535) {
        $errors[] = 'Port ต้องอยู่ระหว่าง 1-65535';
    }
    if ($settings['db_name'] === '') {
        $errors[] = 'กรุณากรอก Database Name';
    }
    if ($settings['current_computer_id'] <= 0) {
        $errors[] = 'Computer ID ต้องมากกว่า 0';
    }
    if ($settings['finish_staff_id'] < 0) {
        $errors[] = 'Finish Staff ID ไม่ถูกต้อง';
    }
    if ($settings['threshold_yellow'] <= 0) {
        $errors[] = 'เวลาแจ้งเตือนสีเหลืองต้องมากกว่า 0';
    }
    if ($settings['threshold_red'] <= 0) {
        $errors[] = 'เวลาแจ้งเตือนสีแดงต้องมากกว่า 0';
    }
    if ($settings['threshold_red'] < $settings['threshold_yellow']) {
        $errors[] = 'เวลาแจ้งเตือนสีแดงต้องมากกว่าหรือเท่ากับสีเหลือง';
    }
    return $errors;
}

function systemSettingsSnapshot()
{
    $settingsPath = resolveKdsSettingsPath();
    $local = is_file($settingsPath) ? (require $settingsPath) : array();
    if (!is_array($local)) $local = array();
    $db = getDbConfig();
    return array(
        'db_host' => (string)localSetting($local, 'db_host', $db['host']),
        'db_port' => (int)localSetting($local, 'db_port', $db['port']),
        'db_name' => (string)localSetting($local, 'db_name', $db['name']),
        'current_computer_id' => (int)localSetting($local, 'current_computer_id', defined('CURRENT_COMPUTER_ID') ? CURRENT_COMPUTER_ID : 0),
        'current_computer_name' => (string)localSetting($local, 'current_computer_name', defined('CURRENT_COMPUTER_NAME') ? CURRENT_COMPUTER_NAME : ''),
        'shop_id' => (int)localSetting($local, 'shop_id', defined('SHOP_ID') ? SHOP_ID : 0),
        'finish_staff_id' => (int)localSetting($local, 'finish_staff_id', defined('DEFAULT_FINISH_STAFF_ID') ? DEFAULT_FINISH_STAFF_ID : 0),
        'threshold_yellow' => (int)localSetting($local, 'threshold_yellow', defined('ALERT_THRESHOLD_YELLOW_DEFAULT') ? ALERT_THRESHOLD_YELLOW_DEFAULT : 10),
        'threshold_red' => (int)localSetting($local, 'threshold_red', defined('ALERT_THRESHOLD_RED_DEFAULT') ? ALERT_THRESHOLD_RED_DEFAULT : 20),
        'sound_enabled' => !empty(localSetting($local, 'sound_enabled', defined('SOUND_ALERT_ENABLED_DEFAULT') ? SOUND_ALERT_ENABLED_DEFAULT : false)) ? 1 : 0,
        'barcode_camera_enabled' => !empty(localSetting($local, 'barcode_camera_enabled', defined('BARCODE_CAMERA_ENABLED_DEFAULT') ? BARCODE_CAMERA_ENABLED_DEFAULT : true)) ? 1 : 0,
        'kds_two_step_checkout' => !empty(localSetting($local, 'kds_two_step_checkout', defined('KDS_TWO_STEP_CHECKOUT_DEFAULT') ? KDS_TWO_STEP_CHECKOUT_DEFAULT : false)) ? 1 : 0,
        'out_of_stock_enabled' => (localSetting($local, 'out_of_stock_enabled', 1) !== 0) ? 1 : 0,
        'allowed_sale_mode_ids' => parseIdList(localSetting($local, 'allowed_sale_mode_ids', array())),
        'allowed_zone_ids' => parseIdList(localSetting($local, 'allowed_zone_ids', array())),
        'hide_staff_login' => !empty(localSetting($local, 'hide_staff_login', false)) ? 1 : 0,
    );
}

function connectWithSystemSettings($settings)
{
    $currentDb = getDbConfig();
    $db = normalizeDbConfig(array(
        'host' => $settings['db_host'],
        'port' => (int)$settings['db_port'],
        'name' => $settings['db_name'],
        'user' => $currentDb['user'],
        'pass' => $currentDb['pass'],
    ));

    mysqli_report(MYSQLI_REPORT_OFF);
    $conn = @new mysqli($db['host'], $db['user'], $db['pass'], $db['name'], (int)$db['port']);
    if ($conn->connect_error) {
        throw new Exception('เชื่อมต่อไม่ผ่าน: ' . $conn->connect_error);
    }
    if (!$conn->set_charset('utf8')) {
        $error = $conn->error;
        $conn->close();
        throw new Exception('เชื่อมต่อผ่าน แต่ตั้งค่า charset ไม่สำเร็จ: ' . $error);
    }
    return $conn;
}

function lookupStaffDisplayNameByConnection($conn, $staffId)
{
    $staffId = (int)$staffId;
    if ($staffId <= 0) {
        return '';
    }

    $sql = "
        SELECT
            StaffID,
            COALESCE(NULLIF(TRIM(StaffCode), ''), '') AS StaffCode,
            COALESCE(NULLIF(TRIM(CONCAT(COALESCE(StaffFirstName, ''), ' ', COALESCE(StaffLastName, ''))), ''), '') AS StaffName
        FROM staffs
        WHERE StaffID = ?
          AND Deleted = 0
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return '';
    }
    $stmt->bind_param('i', $staffId);
    $stmt->execute();
    $result = $stmt->get_result();
    $name = '';
    if ($result && ($row = $result->fetch_assoc())) {
        $parts = array();
        if (isset($row['StaffCode']) && trim((string)$row['StaffCode']) !== '') {
            $parts[] = trim((string)$row['StaffCode']);
        }
        if (isset($row['StaffName']) && trim((string)$row['StaffName']) !== '') {
            $parts[] = trim((string)$row['StaffName']);
        }
        if (!$parts) {
            $parts[] = 'Staff #' . $staffId;
        }
        $name = implode(' - ', $parts);
    }
    $stmt->close();
    return $name;
}

function writeSystemSettingsFile($settings)
{
    $settingsPath = resolveKdsSettingsPath();
    $existing = is_file($settingsPath) ? (require $settingsPath) : array();
    if (!is_array($existing)) {
        $existing = array();
    }
    $next = array_merge($existing, array(
        'db_host' => (string)$settings['db_host'],
        'db_port' => (int)$settings['db_port'],
        'db_name' => (string)$settings['db_name'],
        'current_computer_id' => (int)$settings['current_computer_id'],
        'current_computer_name' => (string)$settings['current_computer_name'],
        'shop_id'         => (int)($settings['shop_id'] ?? 0),
        'finish_staff_id' => (int)$settings['finish_staff_id'],
        'threshold_yellow' => (int)$settings['threshold_yellow'],
        'threshold_red' => (int)$settings['threshold_red'],
        'sound_enabled' => !empty($settings['sound_enabled']) ? 1 : 0,
        'barcode_camera_enabled' => !empty($settings['barcode_camera_enabled']) ? 1 : 0,
        'kds_two_step_checkout' => !empty($settings['kds_two_step_checkout']) ? 1 : 0,
        'out_of_stock_enabled' => isset($settings['out_of_stock_enabled']) ? (!empty($settings['out_of_stock_enabled']) ? 1 : 0) : 1,
        'allowed_sale_mode_ids' => parseIdList($settings['allowed_sale_mode_ids'] ?? ''),
        'allowed_zone_ids' => parseIdList($settings['allowed_zone_ids'] ?? ''),
        'hide_staff_login' => !empty($settings['hide_staff_login']) ? 1 : 0,
    ));

    $content = "<?php\nreturn " . var_export($next, true) . ";\n";
    $path = resolveKdsSettingsPath();
    if (@file_put_contents($path, $content, LOCK_EX) === false) {
        throw new Exception('ไม่สามารถบันทึกไฟล์ settings.local.php ได้');
    }
}

function handleGetSystemSettings()
{
    $settings = systemSettingsSnapshot();
    $staffName = '';
    $connectionMessage = '';
    try {
        $conn = connectWithSystemSettings($settings);
        $staffName = lookupStaffDisplayNameByConnection($conn, $settings['finish_staff_id']);
        $connectionMessage = 'เชื่อมต่อฐานข้อมูลปัจจุบันได้';
        $conn->close();
    } catch (Throwable $e) {
        $connectionMessage = $e->getMessage();
    }

    jsonResponse(array(
        'success' => true,
        'settings' => $settings,
        'staff_name' => $staffName,
        'connection_message' => $connectionMessage,
        'machine_display_name' => function_exists('getMachineDisplayName') ? getMachineDisplayName() : '',
    ));
}

function lookupStaffByCode($conn, $staffCode)
{
    $staffCode = trim((string)$staffCode);
    if ($staffCode === '') return array('success' => false, 'error' => 'กรุณากรอก Staff Code');

    $sql = "SELECT StaffID,
                   COALESCE(NULLIF(TRIM(StaffCode),''),'') AS StaffCode,
                   COALESCE(NULLIF(TRIM(CONCAT(COALESCE(StaffFirstName,''),' ',COALESCE(StaffLastName,''),'')),''),'') AS StaffName
            FROM staffs
            WHERE TRIM(StaffCode) = ? AND Deleted = 0 LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return array('success' => false, 'error' => 'Query error');
    $stmt->bind_param('s', $staffCode);
    $stmt->execute();
    $result = $stmt->get_result();
    if (!$result || !($row = $result->fetch_assoc())) {
        $stmt->close();
        return array('success' => false, 'error' => 'ไม่พบรหัสพนักงาน: ' . $staffCode);
    }
    $stmt->close();
    $name = trim((string)($row['StaffName'] ?? ''));
    if ($name === '') $name = 'Staff #' . $row['StaffID'];
    return array(
        'success'    => true,
        'staff_id'   => (int)$row['StaffID'],
        'staff_code' => trim((string)$row['StaffCode']),
        'staff_name' => $name,
    );
}

function handleLookupStaffByCode()
{
    $staffCode = isset($_GET['staff_code']) ? trim((string)$_GET['staff_code']) : '';
    if ($staffCode === '') {
        jsonResponse(array('success' => false, 'error' => 'กรุณากรอก Staff Code'));
        return;
    }
    try {
        $snapshot = systemSettingsSnapshot();
        $conn = connectWithSystemSettings($snapshot);
        $result = lookupStaffByCode($conn, $staffCode);
        $conn->close();
        jsonResponse($result);
    } catch (Throwable $e) {
        jsonResponse(array('success' => false, 'error' => $e->getMessage()), 500);
    }
}

function handleListTablesInZone()
{
    $zoneId = isset($_GET['zoneid']) ? (int)$_GET['zoneid'] : 0;
    if ($zoneId <= 0) {
        jsonResponse(array('success' => true, 'table_ids' => array()));
        return;
    }
    try {
        $snapshot = systemSettingsSnapshot();
        $conn = connectWithSystemSettings($snapshot);
        $sql = "SELECT TableID FROM tableno WHERE ZoneID = ? AND Deleted = 0";
        $stmt = $conn->prepare($sql);
        $tableIds = array();
        if ($stmt) {
            $stmt->bind_param('i', $zoneId);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $tableIds[] = (int)$row['TableID'];
            }
            $stmt->close();
        }
        $conn->close();
        jsonResponse(array('success' => true, 'table_ids' => $tableIds));
    } catch (Throwable $e) {
        jsonResponse(array('success' => false, 'error' => $e->getMessage()), 500);
    }
}

function handleListShops()
{
    try {
        $snapshot = systemSettingsSnapshot();
        $conn     = connectWithSystemSettings($snapshot);
        $sql      = "SELECT ProductLevelID AS shop_id, ProductLevelName AS shop_name
                     FROM productlevel
                     WHERE ProductLevelName IS NOT NULL AND TRIM(ProductLevelName) != ''
                     ORDER BY ProductLevelID ASC";
        $result = $conn->query($sql);
        $shops  = array();
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $shops[] = array(
                    'shop_id'   => (int)$row['shop_id'],
                    'shop_name' => (string)$row['shop_name'],
                );
            }
        }
        $conn->close();
        jsonResponse(array('success' => true, 'shops' => $shops));
    } catch (Throwable $e) {
        jsonResponse(array('success' => false, 'error' => $e->getMessage()), 500);
    }
}

function handleListSaleModes()
{
    try {
        $snapshot = systemSettingsSnapshot();
        $conn = connectWithSystemSettings($snapshot);
        // เฉพาะ SaleMode ที่ยังไม่ถูกลบ (Deleted = 0) = เปิดใช้งานอยู่
        $sql = "SELECT SaleModeID, SaleModeName FROM salemode WHERE Deleted = 0 ORDER BY SaleModeID ASC";
        $result = $conn->query($sql);
        $modes = array();
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $modes[] = array(
                    'sale_mode_id' => (int)$row['SaleModeID'],
                    'sale_mode_name' => (string)$row['SaleModeName'],
                );
            }
        }
        $conn->close();
        jsonResponse(array('success' => true, 'sale_modes' => $modes));
    } catch (Throwable $e) {
        jsonResponse(array('success' => false, 'error' => $e->getMessage()), 500);
    }
}

function handleListZones()
{
    try {
        $snapshot = systemSettingsSnapshot();
        $conn = connectWithSystemSettings($snapshot);
        $shopId = defined('SHOP_ID') ? (int)SHOP_ID : 0;
        $sql = "SELECT zoneid, zonename FROM tablezone WHERE shopid = ? AND Deleted = 0 ORDER BY zonename";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            // ถ้า shopid ไม่มี column Deleted ลอง fallback
            $sql2 = "SELECT zoneid, zonename FROM tablezone ORDER BY zonename";
            $result2 = $conn->query($sql2);
            $zones = array();
            if ($result2) {
                while ($row = $result2->fetch_assoc()) {
                    $zones[] = array('zoneid' => (int)$row['zoneid'], 'zonename' => (string)$row['zonename']);
                }
            }
            $conn->close();
            jsonResponse(array('success' => true, 'zones' => $zones));
            return;
        }
        $stmt->bind_param('i', $shopId);
        $stmt->execute();
        $result = $stmt->get_result();
        $zones = array();
        while ($row = $result->fetch_assoc()) {
            $zones[] = array('zoneid' => (int)$row['zoneid'], 'zonename' => (string)$row['zonename']);
        }
        $stmt->close();
        $conn->close();
        jsonResponse(array('success' => true, 'zones' => $zones));
    } catch (Throwable $e) {
        jsonResponse(array('success' => false, 'error' => $e->getMessage()), 500);
    }
}

function handleLookupComputerName()
{
    $snapshot = systemSettingsSnapshot();
    $computerId = isset($_REQUEST['computer_id']) ? (int)$_REQUEST['computer_id'] : 0;
    if ($computerId <= 0) {
        jsonResponse(array('success' => true, 'computer_name' => ''));
        return;
    }
    try {
        $conn = connectWithSystemSettings($snapshot);
        $stmt = $conn->prepare('SELECT ComputerName FROM computername WHERE ComputerID = ? AND Deleted = 0 LIMIT 1');
        if (!$stmt) {
            throw new Exception('Prepare failed: ' . $conn->error);
        }
        $stmt->bind_param('i', $computerId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        $conn->close();
        $name = ($row && isset($row['ComputerName'])) ? trim((string)$row['ComputerName']) : '';
        jsonResponse(array('success' => true, 'computer_name' => $name));
    } catch (Throwable $e) {
        jsonResponse(array('success' => false, 'error' => $e->getMessage()), 500);
    }
}

function handleLookupStaffName()
{
    $snapshot = systemSettingsSnapshot();
    $staffId = isset($_REQUEST['staff_id']) ? (int)$_REQUEST['staff_id'] : 0;
    if ($staffId <= 0) {
        jsonResponse(array('success' => true, 'staff_name' => ''));
        return;
    }
    try {
        $conn = connectWithSystemSettings($snapshot);
        $staffName = lookupStaffDisplayNameByConnection($conn, $staffId);
        $conn->close();
        jsonResponse(array('success' => true, 'staff_name' => $staffName));
    } catch (Throwable $e) {
        jsonResponse(array('success' => false, 'error' => $e->getMessage()), 500);
    }
}

function handleTestSystemSettingsConnection()
{
    $settings = normalizeSystemSettingsPayload($_POST);
    $errors = validateSystemSettingsPayload($settings);
    if ($errors) {
        jsonResponse(array('success' => false, 'error' => implode(' | ', $errors)), 422);
    }

    $staffName = '';
    try {
        $conn = connectWithSystemSettings($settings);
        $staffName = lookupStaffDisplayNameByConnection($conn, $settings['finish_staff_id']);
        $conn->close();
    } catch (Throwable $e) {
        jsonResponse(array('success' => false, 'error' => $e->getMessage()), 422);
    }

    jsonResponse(array(
        'success' => true,
        'message' => 'เชื่อมต่อสำเร็จ',
        'staff_name' => $staffName,
    ));
}

function handleSaveSystemSettings()
{
    $settings = normalizeSystemSettingsPayload($_POST);
    $errors = validateSystemSettingsPayload($settings);
    if ($errors) {
        jsonResponse(array('success' => false, 'error' => implode(' | ', $errors)), 422);
    }

    $staffName = '';
    try {
        $conn = connectWithSystemSettings($settings);
        $staffName = lookupStaffDisplayNameByConnection($conn, $settings['finish_staff_id']);
        $conn->close();
        writeSystemSettingsFile($settings);
        writeActivityLog('SETTINGS_SAVE', 'ComputerID:' . $settings['current_computer_id'] . ' StaffID:' . $settings['finish_staff_id'], $settings['finish_staff_id']);
    } catch (Throwable $e) {
        jsonResponse(array('success' => false, 'error' => $e->getMessage()), 422);
    }

    jsonResponse(array(
        'success' => true,
        'message' => 'บันทึกค่าระบบเรียบร้อยแล้ว',
        'staff_name' => $staffName,
        'machine_display_name' => trim((string)$settings['current_computer_name']) !== '' ? trim((string)$settings['current_computer_name']) : ('Computer #' . (int)$settings['current_computer_id']),
        'requires_reload' => true,
    ));
}

try {
    $method = requestedMethod();
    $action = requestedAction();

    if ($method === 'GET' && $action === 'get_system_settings') {
        handleGetSystemSettings();
        exit;
    }

    if ($method === 'GET' && $action === 'lookup_computer_name') {
        handleLookupComputerName();
        exit;
    }

    if ($method === 'GET' && $action === 'lookup_staff_name') {
        handleLookupStaffName();
        exit;
    }

    if ($method === 'GET' && $action === 'lookup_staff_by_code') {
        handleLookupStaffByCode();
        exit;
    }

    if ($method === 'GET' && $action === 'list_shops') {
        handleListShops();
        exit;
    }

    if ($method === 'GET' && $action === 'list_sale_modes') {
        handleListSaleModes();
        exit;
    }

    if ($method === 'GET' && $action === 'list_zones') {
        handleListZones();
        exit;
    }

    if ($method === 'GET' && $action === 'list_tables_in_zone') {
        handleListTablesInZone();
        exit;
    }

    if ($method === 'POST' && $action === 'test_system_settings_connection') {
        handleTestSystemSettingsConnection();
        exit;
    }

    if ($method === 'POST' && $action === 'save_system_settings') {
        handleSaveSystemSettings();
        exit;
    }

    $conn = getDbConnection();

    if ($method === 'POST' && $action === 'confirm_one') {
        confirmOne($conn);
    }

    if ($method === 'POST' && $action === 'checkout_one') {
        checkoutOne($conn);
    }

    if ($method === 'POST' && $action === 'checkout_barcode') {
        checkoutBarcode($conn);
    }

    if ($method === 'POST' && $action === 'undo_one') {
        undoOne($conn);
    }

    if ($method === 'POST' && $action === 'resolve_status') {
        resolveStatus($conn);
    }

    if ($method === 'GET' && $action === 'list_active') {
        listActiveData($conn);
    }

    if ($method === 'GET' && $action === 'list_finished') {
        listFinishedData($conn);
    }

    if ($method === 'GET' && $action === 'list_print_server_printers') {
        listPrintServerPrinters();
    }

    if ($method === 'GET' && $action === 'list_out_of_stock_products') {
        listOutOfStockProducts($conn);
    }

    if ($method === 'POST' && $action === 'set_product_out_of_stock') {
        setProductOutOfStock($conn);
    }

    if ($method === 'POST' && $action === 'log_activity') {
        handleLogActivity();
    }

    if ($method === 'GET' && ($action === 'list' || $action === '')) {
        listData($conn);
    }

    jsonResponse(array(
        'success' => false,
        'error' => 'Unknown action',
    ), 400);
} catch (Throwable $e) {
    jsonResponse(array(
        'success' => false,
        'error' => $e->getMessage(),
    ), 500);
}

function listData($conn)
{
    autoConfirmVoids($conn);  // Auto mode: UPDATE ProcessStatus 98 → 99 ทันที
    $overridePrintServerUrl = requestString('print_server_url', '');
    $activeRows = fetchActiveRows($conn);
    $finishedRows = fetchFinishedRows($conn);

    jsonResponse(array(
        'success' => true,
        'generated_at' => date('Y-m-d H:i:s'),
        'stats' => buildStats($activeRows, $finishedRows),
        'active_rows' => $activeRows,
        'recent_finished_rows' => $finishedRows,
        'filters' => buildFilterInfo($conn, $overridePrintServerUrl),
    ));
}

function listActiveData($conn)
{
    autoConfirmVoids($conn);  // Auto mode: UPDATE ProcessStatus 98 → 99 ทันที
    $overridePrintServerUrl = requestString('print_server_url', '');
    $activeRows = fetchActiveRows($conn);

    jsonResponse(array(
        'success' => true,
        'generated_at' => date('Y-m-d H:i:s'),
        'stats' => buildStats($activeRows, array()),
        'active_rows' => $activeRows,
        'filters' => buildFilterInfo($conn, $overridePrintServerUrl),
    ));
}

function listFinishedData($conn)
{
    $overridePrintServerUrl = requestString('print_server_url', '');
    $finishedRows = fetchFinishedRows($conn);

    jsonResponse(array(
        'success' => true,
        'generated_at' => date('Y-m-d H:i:s'),
        'recent_finished_rows' => $finishedRows,
        'filters' => buildFilterInfo($conn, $overridePrintServerUrl),
    ));
}

function buildFilterInfo($conn = null, $overridePrintServerUrl = '')
{
    $displayPrinters = array();
    if ($conn instanceof mysqli) {
        $displayPrinters = fetchAvailablePrinters($conn, getEffectiveComputerId());
    }
    $normalizedPrintServerUrl = normalizePrintServerBaseUrl($overridePrintServerUrl);
    $checkoutPrinters = resolveCheckoutPrinterOptions($conn, $normalizedPrintServerUrl);

    return array(
        'active_today_only' => (bool)ACTIVE_ROWS_TODAY_ONLY,
        'finished_today_only' => (bool)FINISHED_ROWS_TODAY_ONLY,
        'current_computer_id' => (int)CURRENT_COMPUTER_ID,
        'allowed_printer_ids' => array_values(array_map('intval', array_column($displayPrinters, 'printer_id'))),
        'available_printers' => $checkoutPrinters,
        'display_printers' => $displayPrinters,
        'default_checkout_printer_name' => (string)DEFAULT_CHECKOUT_PRINTER_NAME,
        'allow_checkout_printer_selection' => (bool)ALLOW_CHECKOUT_PRINTER_SELECTION,
        'checkout_print_provider' => (string)CHECKOUT_PRINT_PROVIDER,
        'print_server_url' => $normalizedPrintServerUrl,
    );
}

function resolveCheckoutPrinterOptions($conn = null, $overridePrintServerUrl = '')
{
    $provider = defined('CHECKOUT_PRINT_PROVIDER') ? strtolower(trim((string)CHECKOUT_PRINT_PROVIDER)) : 'none';

    if ($provider === 'print_server') {
        return fetchPrintServerPrinters($overridePrintServerUrl, true);
    }

    if ($provider === 'queue' && $conn instanceof mysqli) {
        return fetchAvailablePrinters($conn, getEffectiveComputerId());
    }

    return array();
}

function listPrintServerPrinters()
{
    $overridePrintServerUrl = requestString('print_server_url', '');
    $normalizedPrintServerUrl = normalizePrintServerBaseUrl($overridePrintServerUrl);
    $printers = fetchPrintServerPrinters($normalizedPrintServerUrl, false);

    jsonResponse(array(
        'success' => true,
        'print_server_url' => $normalizedPrintServerUrl,
        'printers' => $printers,
    ));
}

function fetchPrintServerPrinters($overrideBase = '', $silent = true)
{
    static $cache = array();

    $normalizedBase = normalizePrintServerBaseUrl($overrideBase);
    if ($normalizedBase === '') {
        return array();
    }

    $cacheKey = $normalizedBase;
    if ($silent && isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $printers = array();
    $url = buildPrintServerEndpoint('printers', $normalizedBase);
    if ($url === '') {
        return $printers;
    }

    try {
        $response = performJsonHttpRequest($url, 'GET');
        $items = array();
        if (isset($response['printers']) && is_array($response['printers'])) {
            $items = $response['printers'];
        } elseif (isset($response[0])) {
            $items = $response;
        }

        foreach ($items as $item) {
            $printerName = isset($item['printer_name']) ? trim((string)$item['printer_name']) : '';
            if ($printerName === '' && isset($item['name'])) {
                $printerName = trim((string)$item['name']);
            }
            if ($printerName === '') {
                continue;
            }

            $printers[] = array(
                'printer_name' => $printerName,
                'printer_label' => isset($item['printer_label']) && trim((string)$item['printer_label']) !== ''
                    ? trim((string)$item['printer_label'])
                    : $printerName,
                'is_default' => !empty($item['is_default']) ? 1 : 0,
                'driver_name' => isset($item['driver_name']) ? trim((string)$item['driver_name']) : '',
                'port_name' => isset($item['port_name']) ? trim((string)$item['port_name']) : '',
                'source' => 'print_server',
            );
        }

        $cache[$cacheKey] = $printers;
    } catch (Throwable $e) {
        if ($silent) {
            $cache[$cacheKey] = array();
            return array();
        }
        throw $e;
    }

    return $printers;
}

function normalizePrintServerBaseUrl($overrideBase = '')
{
    $base = trim((string)$overrideBase);
    if ($base === '') {
        $base = defined('PRINT_SERVER_URL') ? trim((string)PRINT_SERVER_URL) : '';
    }
    if ($base === '') {
        return '';
    }

    if (!preg_match('#^https?://#i', $base)) {
        $base = 'http://' . $base;
    }

    $parts = @parse_url($base);
    if (!is_array($parts) || empty($parts['host'])) {
        return $base;
    }

    $scheme = isset($parts['scheme']) ? strtolower((string)$parts['scheme']) : 'http';
    $host = (string)$parts['host'];
    $port = isset($parts['port']) ? (int)$parts['port'] : 0;
    $path = isset($parts['path']) ? (string)$parts['path'] : '';
    $query = isset($parts['query']) ? (string)$parts['query'] : '';

    if ($path === '' || $path === '/') {
        if ($port <= 0) {
            $port = 5001;
        }
        $path = '/print_server.php';
    }

    $normalized = $scheme . '://' . $host;
    if ($port > 0) {
        $normalized .= ':' . $port;
    }
    $normalized .= $path;
    if ($query !== '') {
        $normalized .= '?' . $query;
    }

    return $normalized;
}

function buildPrintServerEndpoint($action, $overrideBase = '')
{
    $base = normalizePrintServerBaseUrl($overrideBase);
    if ($base === '') {
        return '';
    }

    $separator = (strpos($base, '?') === false) ? '?' : '&';
    return $base . $separator . 'action=' . rawurlencode((string)$action);
}

function performJsonHttpRequest($url, $method, $payload = null)
{
    $method = strtoupper((string)$method);
    $headers = array('Accept: application/json');
    $token = defined('PRINT_SERVER_SHARED_TOKEN') ? trim((string)PRINT_SERVER_SHARED_TOKEN) : '';
    if ($token !== '') {
        $headers[] = 'X-Print-Server-Token: ' . $token;
    }

    $body = null;
    if ($payload !== null) {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new Exception('ไม่สามารถสร้าง JSON สำหรับ Print Server ได้');
        }
        $headers[] = 'Content-Type: application/json; charset=utf-8';
    }

    $timeout = defined('PRINT_SERVER_TIMEOUT_SECONDS') ? max(1, (int)PRINT_SERVER_TIMEOUT_SECONDS) : 2;
    $responseBody = '';
    $statusCode = 0;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $responseBody = curl_exec($ch);
        if ($responseBody === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new Exception('ติดต่อ Print Server ไม่สำเร็จ: ' . $err);
        }
        $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    } else {
        $context = stream_context_create(array(
            'http' => array(
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => $body !== null ? $body : '',
                'timeout' => $timeout,
                'ignore_errors' => true,
            ),
        ));
        $responseBody = @file_get_contents($url, false, $context);
        if ($responseBody === false) {
            throw new Exception('ติดต่อ Print Server ไม่สำเร็จ');
        }
        global $http_response_header;
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
            $statusCode = (int)$m[1];
        }
    }

    $data = json_decode((string)$responseBody, true);
    if (!is_array($data)) {
        throw new Exception('Print Server ส่งข้อมูลไม่ใช่ JSON');
    }

    if ($statusCode >= 400 || (isset($data['success']) && !$data['success'])) {
        $message = isset($data['error']) ? (string)$data['error'] : ('Print Server ตอบกลับไม่สำเร็จ (' . $statusCode . ')');
        throw new Exception($message);
    }

    return $data;
}

function fetchAvailablePrinters($conn, $computerId)
{
    static $cache = array();

    $computerId = (int)$computerId;
    if ($computerId <= 0) {
        return array();
    }

    if (isset($cache[$computerId])) {
        return $cache[$computerId];
    }

    $sql = "
        SELECT DISTINCT
            cap.PrinterID,
            COALESCE(NULLIF(TRIM(p.PrinterName), ''), CONCAT('Printer #', cap.PrinterID)) AS PrinterName,
            COALESCE(p.PrinterDeviceName, '') AS PrinterDeviceName
        FROM checkeraccessprinter cap
        LEFT JOIN printers p
            ON p.PrinterID = cap.PrinterID
           AND p.Deleted = 0
        WHERE cap.ComputerID = ?
        ORDER BY cap.PrinterID ASC
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $conn->error);
    }

    $stmt->bind_param('i', $computerId);
    $stmt->execute();
    $result = $stmt->get_result();

    $printers = array();
    while ($result && ($row = $result->fetch_assoc())) {
        $printerId = isset($row['PrinterID']) ? (int)$row['PrinterID'] : 0;
        if ($printerId <= 0) {
            continue;
        }

        $printers[] = array(
            'printer_id' => $printerId,
            'printer_name' => isset($row['PrinterName']) ? trim((string)$row['PrinterName']) : ('Printer #' . $printerId),
            'printer_device_name' => isset($row['PrinterDeviceName']) ? trim((string)$row['PrinterDeviceName']) : '',
        );
    }
    $stmt->close();

    $cache[$computerId] = $printers;
    return $printers;
}

function fetchAllowedPrinterIds($conn, $computerId)
{
    $printers = fetchAvailablePrinters($conn, $computerId);
    $ids = array();
    foreach ($printers as $printer) {
        $printerId = isset($printer['printer_id']) ? (int)$printer['printer_id'] : 0;
        if ($printerId > 0) {
            $ids[$printerId] = $printerId;
        }
    }

    return array_values($ids);
}

function findAvailablePrinterById($conn, $computerId, $printerId)
{
    $printerId = (int)$printerId;
    if ($printerId <= 0) {
        return null;
    }

    foreach (fetchAvailablePrinters($conn, $computerId) as $printer) {
        if ((int)$printer['printer_id'] === $printerId) {
            return $printer;
        }
    }

    return null;
}

function appendAllowedPrinterFilter(array &$where, array $allowedPrinterIds, $alias)
{
    if (!$allowedPrinterIds) {
        $where[] = '1 = 0';
        return;
    }

    $safeIds = array();
    foreach ($allowedPrinterIds as $printerId) {
        $printerId = (int)$printerId;
        if ($printerId > 0) {
            $safeIds[] = $printerId;
        }
    }

    if (!$safeIds) {
        $where[] = '1 = 0';
        return;
    }

    $where[] = $alias . '.PrinterID IN (' . implode(', ', $safeIds) . ')';
}

// โหลด filter SaleMode + Zone จาก settings.local.php ของสถานีนี้
function getStationFilter()
{
    $settingsPath = resolveKdsSettingsPath();
    $local = is_file($settingsPath) ? (require $settingsPath) : array();
    if (!is_array($local)) $local = array();
    return array(
        'allowed_sale_mode_ids' => parseIdList(isset($local['allowed_sale_mode_ids']) ? $local['allowed_sale_mode_ids'] : array()),
        'allowed_zone_ids' => parseIdList(isset($local['allowed_zone_ids']) ? $local['allowed_zone_ids'] : array()),
    );
}

// เพิ่ม WHERE clause สำหรับ SaleMode และ Zone filter
// logic: TableID > 0 = มีโต๊ะ → โซนตัดสิน (ผ่าน SaleMode filter เสมอ)
//        TableID = 0  = ไม่มีโต๊ะ → SaleMode ตัดสิน
function appendSaleModeZoneFilter(array &$where, array $saleModeIds, array $zoneIds, &$needZoneJoin)
{
    $hasSaleMode = !empty($saleModeIds);
    $hasZone     = !empty($zoneIds);

    if ($hasZone && $hasSaleMode) {
        // ทั้งคู่: โต๊ะในโซน = ผ่านเสมอ, TableID=0 = กรองด้วย SaleMode
        $needZoneJoin = true;
        $safeZones = implode(',', array_map('intval', $zoneIds));
        $safeModes = implode(',', array_map('intval', $saleModeIds));
        $where[] = "(" .
            "(opf.TableID > 0 AND tn.ZoneID IN ({$safeZones}))" .
            " OR " .
            "(opf.TableID = 0 AND opf.SaleModeID IN ({$safeModes}))" .
        ")";
    } elseif ($hasZone) {
        // แค่ zone: TableID=0 ผ่านเสมอ
        $needZoneJoin = true;
        $safeZones = implode(',', array_map('intval', $zoneIds));
        $where[] = "(opf.TableID = 0 OR tn.ZoneID IN ({$safeZones}))";
    } elseif ($hasSaleMode) {
        // แค่ SaleMode: กรองทุก order ไม่ว่ามีโต๊ะหรือไม่
        $safeModes = implode(',', array_map('intval', $saleModeIds));
        $where[] = "opf.SaleModeID IN ({$safeModes})";
    }
}

function buildStats($activeRows, $finishedRows)
{
    $activeCount = count($activeRows);
    $activeQty = 0;
    foreach ($activeRows as $row) {
        $activeQty += (float)$row['ProductAmount'];
    }

    return array(
        'active_rows' => $activeCount,
        'active_qty' => $activeQty,
        'recent_finished_rows' => count($finishedRows),
    );
}

function fetchActiveRows($conn)
{
    $allowedPrinterIds = fetchAllowedPrinterIds($conn, getEffectiveComputerId());
    $stationFilter = getStationFilter();

    $statusList = implode(', ', array(
        (int)PROCESS_STATUS_ACTIVE,
        (int)PROCESS_STATUS_IN_PROCESS,
    ));
    $where = array('opf.ProcessStatus IN (' . $statusList . ')');
    appendAllowedPrinterFilter($where, $allowedPrinterIds, 'opf');
    if (ACTIVE_ROWS_TODAY_ONLY) {
        $where[] = "opf.OrderDate = '" . date('Y-m-d') . "'";
    }
    $needZoneJoin = false;
    appendSaleModeZoneFilter($where, $stationFilter['allowed_sale_mode_ids'], $stationFilter['allowed_zone_ids'], $needZoneJoin);

    $zoneJoinSql = $needZoneJoin
        ? "\n        LEFT JOIN tableno tn ON tn.TableID = opf.TableID AND tn.Deleted = 0"
        : '';

    $activeSql = "
        SELECT
            opf.ProductLevelID,
            opf.ProcessID,
            opf.SubProcessID,
            opf.PrinterID,
            opf.TransactionID,
            opf.ComputerID,
            opf.OrderDetailID,
            opf.ProductID,
            opf.ProductName,
            opf.ProductAmount,
            opf.ProductSetType,
            opf.ParentProcessID,
            opf.SubmitOrderDateTime,
            opf.FinishDateTime,
            opf.OrderNo,
            opf.OrderDate,
            opf.TableID,
            opf.DisplayTableName,
            opf.ProcessStatus,
            opf.IsMoveOrder,
            opf.SaleModeID,
            COALESCE(sm.SaleModeName, '-') AS SaleModeName,
            COALESCE(
                (SELECT otf2.TransactionStatusID
                 FROM ordertransactionfront otf2
                 WHERE otf2.TableID = opf.TableID
                   AND otf2.ComputerID = opf.ComputerID
                   AND otf2.TransactionStatusID = 7
                   AND DATE(otf2.OpenTime) = opf.OrderDate
                 LIMIT 1),
            0) AS TransactionStatusID
        FROM orderprocessdetailfront opf
        LEFT JOIN salemode sm
            ON sm.SaleModeID = opf.SaleModeID
           AND sm.Deleted = 0" . $zoneJoinSql . "
        WHERE " . implode(' AND ', $where) . "
        ORDER BY
            opf.SubmitOrderDateTime ASC,
            opf.ProcessID ASC,
            opf.SubProcessID ASC,
            opf.PrinterID ASC
    ";

    $rows = fetchAllRows($conn, $activeSql);
    return attachCommentsToRows($conn, $rows);
}

function fetchFinishedRows($conn)
{
    $allowedPrinterIds = fetchAllowedPrinterIds($conn, getEffectiveComputerId());
    $stationFilter = getStationFilter();

    $where = array('opf.ProcessStatus = ' . (int)PROCESS_STATUS_FINISHED);
    appendAllowedPrinterFilter($where, $allowedPrinterIds, 'opf');
    if (FINISHED_ROWS_TODAY_ONLY) {
        $today = date('Y-m-d');
        $where[] = "(opf.OrderDate = '{$today}' OR (opf.FinishDateTime >= '{$today}' AND opf.FinishDateTime < DATE_ADD('{$today}', INTERVAL 1 DAY)))";
    }
    $needZoneJoin = false;
    appendSaleModeZoneFilter($where, $stationFilter['allowed_sale_mode_ids'], $stationFilter['allowed_zone_ids'], $needZoneJoin);

    $zoneJoinSql = $needZoneJoin
        ? "\n        LEFT JOIN tableno tn ON tn.TableID = opf.TableID AND tn.Deleted = 0"
        : '';

    $finishedSql = "
        SELECT
            opf.ProductLevelID,
            opf.ProcessID,
            opf.SubProcessID,
            opf.PrinterID,
            opf.TransactionID,
            opf.ComputerID,
            opf.OrderDetailID,
            opf.ProductID,
            opf.ProductName,
            opf.ProductAmount,
            opf.ProductSetType,
            opf.ParentProcessID,
            opf.SubmitOrderDateTime,
            opf.FinishDateTime,
            opf.OrderNo,
            opf.OrderDate,
            opf.TableID,
            opf.DisplayTableName,
            opf.ProcessStatus,
            opf.SaleModeID,
            opf.FinishStaffID,
            COALESCE(sm.SaleModeName, '-') AS SaleModeName
        FROM orderprocessdetailfront opf
        LEFT JOIN salemode sm
            ON sm.SaleModeID = opf.SaleModeID
           AND sm.Deleted = 0" . $zoneJoinSql . "
        WHERE " . implode(' AND ', $where) . "
        ORDER BY
            opf.FinishDateTime DESC,
            opf.ProcessID DESC,
            opf.SubProcessID DESC
    ";

    if ((int)RECENT_FINISHED_LIMIT > 0) {
        $finishedSql .= " LIMIT " . (int)RECENT_FINISHED_LIMIT;
    }

    $rows = fetchAllRows($conn, $finishedSql);
    return attachCommentsToRows($conn, $rows);
}

function attachCommentsToRows($conn, $rows)
{
    if (!$rows) {
        return array();
    }

    // คำนวณ flag สถานะพิเศษแต่ละ row
    foreach ($rows as &$row) {
        $status   = isset($row['ProcessStatus'])      ? (int)$row['ProcessStatus']      : 0;
        $isMoved  = isset($row['IsMoveOrder'])         ? (int)$row['IsMoveOrder']         : 0;
        $txStatus = isset($row['TransactionStatusID']) ? (int)$row['TransactionStatusID'] : 0;
        $dispName = isset($row['DisplayTableName'])    ? trim((string)$row['DisplayTableName']) : '';

        $row['is_voided']   = ($status === (int)PROCESS_STATUS_VOIDED);
        $row['is_moved']    = ($isMoved === 1 && strpos($dispName, '->') !== false);
        $row['is_combined'] = (!$row['is_voided'] && !$row['is_moved'] && $txStatus === 7);

        // ปลายทางของ move: '2->4' → '4'
        $row['moved_to'] = '';
        if ($row['is_moved'] && strpos($dispName, '->') !== false) {
            $parts = explode('->', $dispName);
            $row['moved_to'] = trim(end($parts));
        }

        $row['comments'] = array();
    }
    unset($row);

    // ดึง comment โดยอิง ProcessID เป็นหลัก (ตรงกับ kds_allcomment)
    $processIds = array();
    foreach ($rows as $row) {
        $processId = isset($row['ProcessID']) ? (int)$row['ProcessID'] : 0;
        $parentProcessId = isset($row['ParentProcessID']) ? (int)$row['ParentProcessID'] : 0;
        if ($processId > 0) {
            $processIds[$processId] = true;
        }
        if ($parentProcessId > 0) {
            $processIds[$parentProcessId] = true;
        }
    }
    $commentsMap = $processIds ? fetchCommentsByProcessIds($conn, array_keys($processIds)) : array();

    foreach ($rows as &$row) {
        $processId = isset($row['ProcessID']) ? (int)$row['ProcessID'] : 0;
        $parentProcessId = isset($row['ParentProcessID']) ? (int)$row['ParentProcessID'] : 0;

        if ($processId > 0 && isset($commentsMap[$processId])) {
            $row['comments'] = array_values($commentsMap[$processId]);
        } elseif ($parentProcessId > 0 && isset($commentsMap[$parentProcessId])) {
            $row['comments'] = array_values($commentsMap[$parentProcessId]);
        } else {
            $row['comments'] = array();
        }
    }
    unset($row);

    return mergeChildProcessRowsIntoParents($rows);
}

function mergeChildProcessRowsIntoParents($rows)
{
    if (!$rows) {
        return array();
    }

    $parentIndexMap = array();
    foreach ($rows as $index => $row) {
        $parentIndexMap[makeProcessRowMapKey($row)] = $index;
        if (!isset($rows[$index]['comments']) || !is_array($rows[$index]['comments'])) {
            $rows[$index]['comments'] = array();
        }
        if (!isset($rows[$index]['parent_name'])) {
            $rows[$index]['parent_name'] = null;
        }
    }

    $hiddenParents   = array();
    $hiddenChildren  = array();
    $insertsByParent = array();

    // Pass 1: merge comments (SetType 14/15) เข้า parent ก่อน
    foreach ($rows as $index => $row) {
        $parentProcessId = isset($row['ParentProcessID']) ? (int)$row['ParentProcessID'] : 0;
        $productSetType  = isset($row['ProductSetType'])  ? (int)$row['ProductSetType']  : 0;

        if ($parentProcessId <= 0) continue;
        if (!in_array($productSetType, array(14, 15), true)) continue;

        $parentKey = makeParentLookupKey($row, $parentProcessId);
        if (!isset($parentIndexMap[$parentKey])) continue;

        $parentIndex = $parentIndexMap[$parentKey];
        $rows[$parentIndex]['comments'] = appendProcessRowAsComment($rows[$parentIndex]['comments'], $row);
        $hiddenChildren[$index] = true;
    }

    // Pass 2: สร้างการ์ด child (SetType -6 ฯลฯ) พร้อม comments ที่ครบแล้วจาก Pass 1
    foreach ($rows as $index => $row) {
        $parentProcessId = isset($row['ParentProcessID']) ? (int)$row['ParentProcessID'] : 0;
        $productSetType  = isset($row['ProductSetType'])  ? (int)$row['ProductSetType']  : 0;

        if ($parentProcessId <= 0) continue;
        if (in_array($productSetType, array(14, 15), true)) continue;

        $parentKey = makeParentLookupKey($row, $parentProcessId);
        if (!isset($parentIndexMap[$parentKey])) continue;

        $parentIndex = $parentIndexMap[$parentKey];
        $parentRow   = $rows[$parentIndex];

        {
            // สินค้าชุด (SETA) → การ์ดแยกพร้อม parent_name + inherit status จาก parent
            $newCard                        = $row;
            $newCard['parent_name']         = trim((string)(isset($parentRow['ProductName']) ? $parentRow['ProductName'] : ''));
            $newCard['comments']            = isset($row['comments']) ? $row['comments'] : array();
            $newCard['TableID']             = $parentRow['TableID'];
            $newCard['DisplayTableName']    = $parentRow['DisplayTableName'];
            $newCard['OrderNo']             = $parentRow['OrderNo'];
            $newCard['SaleModeID']          = $parentRow['SaleModeID'];
            $newCard['SaleModeName']        = isset($parentRow['SaleModeName']) ? $parentRow['SaleModeName'] : '-';
            $newCard['SubmitOrderDateTime'] = $parentRow['SubmitOrderDateTime'];
            // inherit flags พิเศษจาก parent
            if (!empty($parentRow['is_voided']))   $newCard['is_voided']   = true;
            if (!empty($parentRow['is_moved']))    { $newCard['is_moved']  = true;  $newCard['moved_to'] = $parentRow['moved_to']; }
            if (!empty($parentRow['is_combined'])) $newCard['is_combined'] = true;

            $insertsByParent[$parentIndex][] = $newCard;
            $hiddenParents[$parentIndex]     = true;
            $hiddenChildren[$index]          = true;
        }
    }

    $visibleRows = array();
    foreach ($rows as $index => $row) {
        if (isset($hiddenChildren[$index])) continue;
        if (isset($hiddenParents[$index])) {
            if (isset($insertsByParent[$index])) {
                foreach ($insertsByParent[$index] as $card) {
                    $visibleRows[] = $card;
                }
            }
            continue;
        }
        $visibleRows[] = $row;
    }

    return $visibleRows;
}

function makeProcessRowMapKey($row)
{
    return (int)(isset($row['ProductLevelID']) ? $row['ProductLevelID'] : 0)
        . '|' . (int)(isset($row['ProcessID']) ? $row['ProcessID'] : 0)
        . '|' . (int)(isset($row['PrinterID']) ? $row['PrinterID'] : 0);
}

function makeParentLookupKey($row, $parentProcessId)
{
    return (int)(isset($row['ProductLevelID']) ? $row['ProductLevelID'] : 0)
        . '|' . (int)$parentProcessId
        . '|' . (int)(isset($row['PrinterID']) ? $row['PrinterID'] : 0);
}

function appendProcessRowAsComment($comments, $row)
{
    $comments = is_array($comments) ? array_values($comments) : array();
    $comment = array(
        'text' => trim((string)(isset($row['ProductName']) ? $row['ProductName'] : '')),
        'amount' => isset($row['ProductAmount']) ? (float)$row['ProductAmount'] : 0,
        'type' => isset($row['ProductSetType']) ? (int)$row['ProductSetType'] : 0,
        'label' => commentTypeLabel(isset($row['ProductSetType']) ? (int)$row['ProductSetType'] : 0),
        'is_priced' => ((int)(isset($row['ProductSetType']) ? $row['ProductSetType'] : 0) === 15),
        'is_free_text' => false,
    );

    if ($comment['text'] === '') {
        return $comments;
    }

    $dedupeKey = $comment['type'] . '|' . $comment['text'] . '|' . toDecimalString($comment['amount'], 2);
    $existing = array();
    foreach ($comments as $item) {
        $existingKey = (int)(isset($item['type']) ? $item['type'] : 0)
            . '|' . trim((string)(isset($item['text']) ? $item['text'] : ''))
            . '|' . toDecimalString(isset($item['amount']) ? (float)$item['amount'] : 0, 2);
        $existing[$existingKey] = true;
    }

    if (!isset($existing[$dedupeKey])) {
        $comments[] = $comment;
    }

    return $comments;
}

function fetchCommentsByProcessIds($conn, $processIds)
{
    if (!$processIds) {
        return array();
    }

    $processIds = array_values(array_unique(array_map('intval', $processIds)));
    $processIds = array_values(array_filter($processIds, function ($value) {
        return $value > 0;
    }));
    if (!$processIds) {
        return array();
    }

    $placeholders = implode(', ', array_fill(0, count($processIds), '?'));
    $sql = "
        SELECT
            c.ProcessID AS ProcessID,
            c.OrderComment,
            c.CommentAmount,
            c.CommentSetType
        FROM (" . getKdsAllCommentSql() . ") c
        WHERE c.ProcessID IN (" . $placeholders . ")
          AND c.ProcessID <> 0
          AND c.OrderComment IS NOT NULL
          AND c.OrderComment <> ''
        ORDER BY
            c.ProcessID ASC,
            CASE
                WHEN c.CommentSetType = 15 THEN 2
                ELSE 1
            END ASC,
            c.OrderComment ASC
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return array();
    }

    $types = str_repeat('i', count($processIds));
    $bindValues = $processIds;
    $bindParams = array($types);
    foreach ($bindValues as $index => $value) {
        $bindParams[] = &$bindValues[$index];
    }
    call_user_func_array(array($stmt, 'bind_param'), $bindParams);

    $stmt->execute();
    $result = $stmt->get_result();
    if (!$result) {
        $stmt->close();
        return array();
    }

    $map = array();
    while ($dbRow = $result->fetch_assoc()) {
        $comment = normalizeCommentRow(array(
            'OrderComment'   => $dbRow['OrderComment'],
            'CommentAmount'  => $dbRow['CommentAmount'],
            'CommentSetType' => $dbRow['CommentSetType'],
        ));
        if (!$comment) {
            continue;
        }

        $processId = (int)$dbRow['ProcessID'];
        $dedupeKey = $comment['type'] . '|' . $comment['text'] . '|' . toDecimalString($comment['amount'], 2);

        if (!isset($map[$processId])) {
            $map[$processId] = array();
        }
        if (!isset($map[$processId][$dedupeKey])) {
            $map[$processId][$dedupeKey] = $comment;
        }
    }
    $stmt->close();

    return $map;
}

function fetchCommentsByRowKeys($conn, $rowKeys)
{
    if (!$rowKeys) {
        return array();
    }

    $conditions = array();
    foreach ($rowKeys as $rowKey) {
        $transactionId = isset($rowKey['TransactionID']) ? (int)$rowKey['TransactionID'] : 0;
        $computerId = isset($rowKey['ComputerID']) ? (int)$rowKey['ComputerID'] : 0;
        $orderDetailId = isset($rowKey['OrderDetailID']) ? (int)$rowKey['OrderDetailID'] : 0;
        if ($transactionId > 0 && $computerId > 0 && $orderDetailId > 0) {
            $conditions[] = sprintf('(c.TransactionID = %d AND c.ComputerID = %d AND c.OrderDetailID = %d)', $transactionId, $computerId, $orderDetailId);
        }
    }

    if (!$conditions) {
        return array();
    }

    $sql = "
        SELECT
            c.TransactionID,
            c.ComputerID,
            c.OrderDetailID,
            c.OrderComment,
            c.CommentAmount,
            c.CommentSetType
        FROM (" . getKdsAllCommentSql() . " ) c
        WHERE (" . implode(' OR ', $conditions) . ")
          AND c.OrderComment IS NOT NULL
          AND c.OrderComment <> ''
        ORDER BY
            c.TransactionID ASC,
            c.ComputerID ASC,
            c.OrderDetailID ASC,
            CASE
                WHEN c.CommentSetType = 15 THEN 2
                ELSE 1
            END ASC,
            c.OrderComment ASC
    ";

    $result = $conn->query($sql);
    if (!$result) {
        return array();
    }

    $map = array();
    while ($row = $result->fetch_assoc()) {
        $comment = normalizeCommentRow($row);
        if (!$comment) {
            continue;
        }

        $key = (int)$row['TransactionID'] . '|' . (int)$row['ComputerID'] . '|' . (int)$row['OrderDetailID'];
        $dedupeKey = $comment['type'] . '|' . $comment['text'] . '|' . toDecimalString($comment['amount'], 2);

        if (!isset($map[$key])) {
            $map[$key] = array();
        }
        $map[$key][$dedupeKey] = $comment;
    }

    return $map;
}

function normalizeCommentRow($row)
{
    $text = trim((string)(isset($row['OrderComment']) ? $row['OrderComment'] : ''));
    if ($text === '') {
        return null;
    }

    $type = isset($row['CommentSetType']) ? (int)$row['CommentSetType'] : 0;
    $amount = isset($row['CommentAmount']) ? (float)$row['CommentAmount'] : 1;

    return array(
        'text' => $text,
        'amount' => $amount,
        'type' => $type,
        'label' => commentTypeLabel($type),
        'is_priced' => ($type === 15),
        'is_free_text' => ($type === 0),
    );
}

function commentTypeLabel($type)
{
    return ((int)$type === 15) ? 'คอมเมนต์เพิ่มราคา' : 'คอมเมนต์';
}

function getKdsAllCommentSql()
{
    return "
        SELECT
            od.TransactionID AS TransactionID,
            od.ComputerID AS ComputerID,
            od.OrderDetailID AS OrderDetailID,
            od.ProcessID AS ProcessID,
            p.ProductName AS OrderComment,
            oc.Amount AS CommentAmount,
            oc.ProductSetType AS CommentSetType
        FROM orderdetailfront od
        INNER JOIN ordercommentlinkfront oc
            ON od.TransactionID = oc.TransactionID
           AND od.ComputerID = oc.ComputerID
           AND od.OrderDetailID = oc.CommentForOrderID
        INNER JOIN products p
            ON oc.ProductID = p.ProductID
        WHERE od.ProcessID <> 0

        UNION ALL

        SELECT
            od.TransactionID AS TransactionID,
            od.ComputerID AS ComputerID,
            od.OrderDetailID AS OrderDetailID,
            od.ProcessID AS ProcessID,
            od.Comment AS OrderComment,
            1 AS CommentAmount,
            0 AS CommentSetType
        FROM orderdetailfront od
        WHERE od.Comment IS NOT NULL
          AND od.Comment <> ''
          AND od.ProcessID <> 0

        UNION ALL

        SELECT
            op.TransactionID AS TransactionID,
            op.ComputerID AS ComputerID,
            op.OrderDetailID AS OrderDetailID,
            od.ProcessID AS ProcessID,
            od.Comment AS OrderComment,
            1 AS CommentAmount,
            0 AS CommentSetType
        FROM orderprocessdetailfront op
        INNER JOIN orderdetail od
            ON od.TransactionID = op.TransactionID
           AND od.ComputerID = op.ComputerID
           AND od.OrderDetailID = op.OrderDetailID
        WHERE od.Comment IS NOT NULL
          AND od.Comment <> ''
          AND op.TransactionID <> 0
          AND op.ComputerID <> 0
          AND op.OrderDetailID <> 0
          AND od.ProcessID <> 0

        UNION ALL

        SELECT
            op.TransactionID AS TransactionID,
            op.ComputerID AS ComputerID,
            op.OrderDetailID AS OrderDetailID,
            op.ProcessID AS ProcessID,
            p.ProductName AS OrderComment,
            oc.Amount AS CommentAmount,
            14 AS CommentSetType
        FROM orderprocessdetailfront op
        INNER JOIN ordercommentdetail oc
            ON op.OrderDetailID = oc.OrderDetailID
           AND op.TransactionID = oc.TransactionID
           AND op.ComputerID = oc.ComputerID
        INNER JOIN products p
            ON oc.CommentID = p.ProductID
        WHERE op.TransactionID <> 0
          AND op.ComputerID <> 0
          AND op.OrderDetailID <> 0

        UNION ALL

        SELECT
            op.TransactionID AS TransactionID,
            op.ComputerID AS ComputerID,
            op.OrderDetailID AS OrderDetailID,
            op.ProcessID AS ProcessID,
            p.ProductName AS OrderComment,
            od.Amount AS CommentAmount,
            15 AS CommentSetType
        FROM orderprocessdetailfront op
        INNER JOIN ordercommentwithpricedetail oc
            ON op.OrderDetailID = oc.OrderLinkID
           AND op.TransactionID = oc.TransactionID
           AND op.ComputerID = oc.ComputerID
        INNER JOIN orderdetail od
            ON od.TransactionID = oc.TransactionID
           AND od.ComputerID = oc.ComputerID
           AND od.OrderDetailID = oc.OrderDetailID
        INNER JOIN products p
            ON oc.ProductID = p.ProductID
        WHERE op.TransactionID <> 0
          AND op.ComputerID <> 0
          AND op.OrderDetailID <> 0
    ";
}

function fetchAllRows($conn, $sql)
{
    $result = $conn->query($sql);
    if (!$result) {
        throw new Exception('Query failed: ' . $conn->error);
    }

    $rows = array();
    while ($row = $result->fetch_assoc()) {
        $row['ProductAmount'] = isset($row['ProductAmount']) ? (float)$row['ProductAmount'] : 0;
        $rows[] = $row;
    }

    return $rows;
}

function confirmOne($conn)
{
    $productLevelId = requestInt('ProductLevelID');
    $processId = requestInt('ProcessID');
    $subProcessId = requestInt('SubProcessID');
    $printerId = requestInt('PrinterID');

    $conn->begin_transaction();

    try {
        $row = fetchLockedProcessRow($conn, $productLevelId, $processId, $subProcessId, $printerId, array(PROCESS_STATUS_ACTIVE));
        if (!$row) {
            throw new Exception('ไม่พบรายการที่รอยืนยัน หรือรายการนี้ถูกยืนยันไปแล้ว');
        }

        $sql = "
            UPDATE orderprocessdetailfront
            SET ProcessStatus = ?
            WHERE ProductLevelID = ?
              AND ProcessID = ?
              AND SubProcessID = ?
              AND PrinterID = ?
              AND ProcessStatus = ?
        ";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception('Prepare failed: ' . $conn->error);
        }

        $nextStatus = (int)PROCESS_STATUS_IN_PROCESS;
        $currentStatus = (int)PROCESS_STATUS_ACTIVE;
        $stmt->bind_param('iiiiii', $nextStatus, $productLevelId, $processId, $subProcessId, $printerId, $currentStatus);
        $stmt->execute();
        if ($stmt->affected_rows < 1) {
            $stmt->close();
            throw new Exception('ไม่สามารถยืนยันรายการนี้ได้');
        }
        $stmt->close();

        $conn->commit();

        jsonResponse(array(
            'success' => true,
            'message' => 'ยืนยันรายการแล้ว',
            'process_status' => (int)PROCESS_STATUS_IN_PROCESS,
        ));
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}


function checkoutOne($conn)
{
    $productLevelId = requestInt('ProductLevelID');
    $processId = requestInt('ProcessID');
    $subProcessId = requestInt('SubProcessID');
    $printerId = requestInt('PrinterID');
    $finishStaffId = requestInt('finish_staff_id', DEFAULT_FINISH_STAFF_ID);

    $conn->begin_transaction();

    try {
        $row = fetchLockedProcessRow($conn, $productLevelId, $processId, $subProcessId, $printerId, array(PROCESS_STATUS_ACTIVE, PROCESS_STATUS_IN_PROCESS));
        if (!$row) {
            throw new Exception('ไม่พบรายการค้างในคิว หรือรายการนี้ถูก checkout ไปแล้ว');
        }

        $now = date('Y-m-d H:i:s');
        $parentCurrentQty = isset($row['ProductAmount']) ? (float)$row['ProductAmount'] : 0;
        if ($parentCurrentQty <= 0) {
            throw new Exception('จำนวนคงเหลือไม่ถูกต้อง');
        }

        applyCheckoutSplit($conn, $row, 1, $finishStaffId, $now);

        $childRows = fetchLockedChildRows($conn, (int)$row['ProductLevelID'], (int)$row['ProcessID'], (int)$row['PrinterID'], array(PROCESS_STATUS_ACTIVE, PROCESS_STATUS_IN_PROCESS));
        foreach ($childRows as $childRow) {
            $childQty = isset($childRow['ProductAmount']) ? (float)$childRow['ProductAmount'] : 0;
            if ($childQty <= 0) {
                continue;
            }

            $childQtyToFinish = calculateChildCheckoutQty($parentCurrentQty, $childQty);
            if ($childQtyToFinish <= 0) {
                continue;
            }

            applyCheckoutSplit($conn, $childRow, $childQtyToFinish, $finishStaffId, $now);
        }
        $conn->commit();

        $menuName = isset($row['ProductName']) ? (string)$row['ProductName'] : '-';
        $tableName = isset($row['DisplayTableName']) ? (string)$row['DisplayTableName'] : '-';
        writeActivityLog('CHECKOUT', 'Table:' . $tableName . ' Menu:' . $menuName . ' Process:' . $processId, $finishStaffId);

        jsonResponse(array(
            'success' => true,
            'message' => 'checkout 1 รายการเรียบร้อย',
            'refresh_finished' => true,
        ));
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}


function checkoutBarcode($conn)
{
    $barcodeRaw = requestString('barcode', '');
    $barcodeInfo = parseCheckoutBarcode($barcodeRaw);
    if (!$barcodeInfo['valid']) {
        throw new Exception('Barcode not found');
    }

    $finishStaffId = requestInt('finish_staff_id', DEFAULT_FINISH_STAFF_ID);

    $conn->begin_transaction();

    try {
        $row = fetchLockedProcessRowByBarcode($conn, (int)$barcodeInfo['process_id'], array(PROCESS_STATUS_ACTIVE, PROCESS_STATUS_IN_PROCESS));
        if (!$row) {
            throw new Exception('Barcode not found');
        }

        $now = date('Y-m-d H:i:s');
        $parentCurrentQty = isset($row['ProductAmount']) ? (float)$row['ProductAmount'] : 0;
        if ($parentCurrentQty <= 0) {
            throw new Exception('จำนวนคงเหลือไม่ถูกต้อง');
        }

        applyCheckoutSplit($conn, $row, 1, $finishStaffId, $now);

        $childRows = fetchLockedChildRows(
            $conn,
            (int)$row['ProductLevelID'],
            (int)$row['ProcessID'],
            (int)$row['PrinterID'],
            array(PROCESS_STATUS_ACTIVE, PROCESS_STATUS_IN_PROCESS)
        );
        foreach ($childRows as $childRow) {
            $childQty = isset($childRow['ProductAmount']) ? (float)$childRow['ProductAmount'] : 0;
            if ($childQty <= 0) {
                continue;
            }

            $childQtyToFinish = calculateChildCheckoutQty($parentCurrentQty, $childQty);
            if ($childQtyToFinish <= 0) {
                continue;
            }

            applyCheckoutSplit($conn, $childRow, $childQtyToFinish, $finishStaffId, $now);
        }
        $conn->commit();

        jsonResponse(array(
            'success' => true,
            'message' => 'Barcode ' . $barcodeInfo['display'] . ' checkout เรียบร้อย',
            'refresh_finished' => true,
            'barcode' => $barcodeInfo['display'],
            'process_id' => (int)$row['ProcessID'],
            'matched_row' => array(
                'ProductLevelID' => (int)$row['ProductLevelID'],
                'ProcessID' => (int)$row['ProcessID'],
                'SubProcessID' => (int)$row['SubProcessID'],
                'PrinterID' => (int)$row['PrinterID'],
                'ProductName' => isset($row['ProductName']) ? (string)$row['ProductName'] : '',
                'DisplayTableName' => isset($row['DisplayTableName']) ? (string)$row['DisplayTableName'] : '',
                'ProductAmount' => 1,
            ),
        ));
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

function parseCheckoutBarcode($barcodeRaw)
{
    $barcodeRaw = trim((string)$barcodeRaw);
    $digitsOnly = preg_replace('/\D+/', '', $barcodeRaw);
    $minLength = defined('BARCODE_MIN_LENGTH') ? (int)BARCODE_MIN_LENGTH : 1;
    $displayDigits = defined('BARCODE_DIGITS_DISPLAY') ? (int)BARCODE_DIGITS_DISPLAY : 6;

    if ($digitsOnly === '' || strlen($digitsOnly) < max(1, $minLength)) {
        return array(
            'valid' => false,
            'raw' => $barcodeRaw,
            'digits' => '',
            'display' => $barcodeRaw,
            'process_id' => 0,
        );
    }

    $processId = (int)$digitsOnly;
    if ($processId <= 0) {
        return array(
            'valid' => false,
            'raw' => $barcodeRaw,
            'digits' => $digitsOnly,
            'display' => $digitsOnly,
            'process_id' => 0,
        );
    }

    return array(
        'valid' => true,
        'raw' => $barcodeRaw,
        'digits' => $digitsOnly,
        'display' => str_pad((string)$processId, max(1, $displayDigits), '0', STR_PAD_LEFT),
        'process_id' => $processId,
    );
}

function fetchLockedProcessRowByBarcode($conn, $processId, array $statuses)
{
    $processId = (int)$processId;
    if ($processId <= 0) {
        return null;
    }

    $allowedPrinterIds = fetchAllowedPrinterIds($conn, getEffectiveComputerId());
    if (!$allowedPrinterIds) {
        return null;
    }

    $statusList = array();
    foreach ($statuses as $status) {
        $status = (int)$status;
        $statusList[$status] = $status;
    }
    if (!$statusList) {
        return null;
    }

    $where = array(
        'opf.ProcessID = ?',
        'opf.ProcessStatus IN (' . implode(', ', $statusList) . ')',
        'COALESCE(opf.ProductSetType, 0) NOT IN (14, 15)'
    );
    appendAllowedPrinterFilter($where, $allowedPrinterIds, 'opf');
    if (ACTIVE_ROWS_TODAY_ONLY) {
        $where[] = "opf.OrderDate = '" . date('Y-m-d') . "'";
    }

    $sql = "
        SELECT opf.*
        FROM orderprocessdetailfront opf
        WHERE " . implode(' AND ', $where) . "
        ORDER BY
            opf.SubmitOrderDateTime ASC,
            opf.ProductLevelID ASC,
            opf.SubProcessID ASC,
            opf.PrinterID ASC
        LIMIT 1
        FOR UPDATE
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $conn->error);
    }

    $stmt->bind_param('i', $processId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return $row ?: null;
}

function undoOne($conn)
{
    $productLevelId = requestInt('ProductLevelID');
    $processId = requestInt('ProcessID');
    $subProcessId = requestInt('SubProcessID');
    $printerId = requestInt('PrinterID');

    $conn->begin_transaction();

    try {
        $finishedRow = fetchLockedProcessRow($conn, $productLevelId, $processId, $subProcessId, $printerId, array(PROCESS_STATUS_FINISHED));
        if (!$finishedRow) {
            throw new Exception('ไม่พบรายการเสร็จล่าสุดที่ต้องการย้อนกลับ');
        }

        $finishedChildRows = fetchLockedFinishedChildRowsForUndo($conn, $finishedRow);
        foreach ($finishedChildRows as $childRow) {
            undoFinishedProcessRow($conn, $childRow);
        }

        undoFinishedProcessRow($conn, $finishedRow);

        $conn->commit();

        $menuName  = isset($finishedRow['ProductName'])      ? (string)$finishedRow['ProductName']      : '-';
        $tableName = isset($finishedRow['DisplayTableName']) ? (string)$finishedRow['DisplayTableName'] : '-';
        $undoStaff = requestInt('finish_staff_id', DEFAULT_FINISH_STAFF_ID);
        writeActivityLog('UNDO', 'Table:' . $tableName . ' Menu:' . $menuName . ' Process:' . $processId, $undoStaff);

        jsonResponse(array(
            'success' => true,
            'message' => 'ย้อนกลับ 1 รายการเรียบร้อย',
            'refresh_finished' => true,
        ));
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}


function resolveStatus($conn)
{
    $productLevelId = requestInt('ProductLevelID');
    $processId = requestInt('ProcessID');
    $subProcessId = requestInt('SubProcessID');
    $printerId = requestInt('PrinterID');
    $finishStaffId = requestInt('finish_staff_id', DEFAULT_FINISH_STAFF_ID);

    $conn->begin_transaction();

    try {
        $row = fetchLockedProcessRow($conn, $productLevelId, $processId, $subProcessId, $printerId, array(PROCESS_STATUS_VOIDED));
        if (!$row) {
            throw new Exception('ไม่พบรายการยกเลิกที่ต้องการจบสถานะ');
        }

        $now = date('Y-m-d H:i:s');
        resolveProcessRow($conn, $row, $finishStaffId, $now);

        $childStatuses = array(PROCESS_STATUS_VOIDED, PROCESS_STATUS_ACTIVE, PROCESS_STATUS_IN_PROCESS);
        $childRows = fetchLockedChildRows($conn, (int)$row['ProductLevelID'], (int)$row['ProcessID'], (int)$row['PrinterID'], $childStatuses);
        foreach ($childRows as $childRow) {
            resolveProcessRow($conn, $childRow, $finishStaffId, $now);
        }

        $conn->commit();

        jsonResponse(array(
            'success' => true,
            'message' => 'จบสถานะรายการยกเลิกเรียบร้อย',
            'refresh_finished' => false,
        ));
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

function autoConfirmVoids($conn)
{
    $allowedPrinterIds = fetchAllowedPrinterIds($conn, getEffectiveComputerId());
    if (!$allowedPrinterIds) {
        return;
    }

    $printerIn      = implode(', ', array_map('intval', $allowedPrinterIds));
    $voidedStatus   = (int)PROCESS_STATUS_VOIDED;    // 98
    $resolvedStatus = (int)PROCESS_STATUS_RESOLVED;  // 99
    $now            = date('Y-m-d H:i:s');

    $sql = "
        UPDATE orderprocessdetailfront
        SET ProcessStatus  = ?,
            FinishDateTime = ?
        WHERE ProcessStatus = ?
          AND PrinterID IN ({$printerIn})
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return; // silent — ไม่ให้ poll crash
    }
    $stmt->bind_param('isi', $resolvedStatus, $now, $voidedStatus);
    $stmt->execute();
    $stmt->close();
}

function resolveProcessRow($conn, $row, $finishStaffId, $now)
{
    $resolvedStatus = (int)PROCESS_STATUS_RESOLVED;
    $effectiveFinishStaffId = isset($row['FinishStaffID']) && (int)$row['FinishStaffID'] > 0
        ? (int)$row['FinishStaffID']
        : (int)$finishStaffId;
    $effectiveFinishDateTime = isset($row['FinishDateTime']) && trim((string)$row['FinishDateTime']) !== ''
        ? trim((string)$row['FinishDateTime'])
        : $now;

    $sql = "
        UPDATE orderprocessdetailfront
        SET FinishStaffID = ?,
            FinishDateTime = ?,
            ProcessStatus = ?
        WHERE ProductLevelID = ?
          AND ProcessID = ?
          AND SubProcessID = ?
          AND PrinterID = ?
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $conn->error);
    }

    $productLevelId = (int)$row['ProductLevelID'];
    $processId = (int)$row['ProcessID'];
    $subProcessId = (int)$row['SubProcessID'];
    $printerId = (int)$row['PrinterID'];
    $stmt->bind_param('isiiiii', $effectiveFinishStaffId, $effectiveFinishDateTime, $resolvedStatus, $productLevelId, $processId, $subProcessId, $printerId);
    $stmt->execute();
    if ($stmt->affected_rows < 1) {
        $stmt->close();
        throw new Exception('ไม่สามารถจบสถานะรายการนี้ได้');
    }
    $stmt->close();
}

function sendCheckoutPrintToPrintServer($conn, $sourceRow, $printerName, $finishStaffId, $finishedAt, $overridePrintServerUrl = '')
{
    $printerName = trim((string)$printerName);
    if ($printerName === '') {
        throw new Exception('ยังไม่ได้เลือกเครื่องปริ๊นสำหรับ Checkout');
    }

    $printRow = decorateCheckoutPrintRow($conn, $sourceRow);
    $payload = buildCheckoutPrintServerPayload($printRow, $printerName, $finishStaffId, $finishedAt);
    $url = buildPrintServerEndpoint('print', $overridePrintServerUrl);
    if ($url === '') {
        throw new Exception('ยังไม่ได้ตั้งค่า Print Server URL');
    }

    $response = performJsonHttpRequest($url, 'POST', $payload);

    return array(
        'printer_name' => $printerName,
        'print_server_job_id' => isset($response['job_id']) ? (string)$response['job_id'] : '',
    );
}

function buildCheckoutPrintServerPayload($row, $printerName, $finishStaffId, $finishedAt)
{
    $tableName = isset($row['DisplayTableName']) && trim((string)$row['DisplayTableName']) !== ''
        ? trim((string)$row['DisplayTableName'])
        : 'ไม่ระบุโต๊ะ';
    $productName = isset($row['ProductName']) && trim((string)$row['ProductName']) !== ''
        ? trim((string)$row['ProductName'])
        : 'รายการอาหาร';
    $qty = '1';
    $saleMode = isset($row['SaleModeName']) && trim((string)$row['SaleModeName']) !== ''
        ? trim((string)$row['SaleModeName'])
        : '-';
    $orderNo = isset($row['OrderNo']) ? (int)$row['OrderNo'] : 0;
    $submitTime = !empty($row['SubmitOrderDateTime']) ? strtotime((string)$row['SubmitOrderDateTime']) : false;
    $finishedTime = !empty($finishedAt) ? strtotime((string)$finishedAt) : false;

    $lines = array();
    $lines[] = 'CHECKOUT';
    $lines[] = 'โต๊ะ: ' . $tableName;
    if ($saleMode !== '-' && $saleMode !== '') {
        $lines[] = 'ประเภท: ' . $saleMode;
    }
    if ($orderNo > 0) {
        $lines[] = 'Order No: ' . $orderNo;
    }
    $lines[] = str_repeat('-', 32);
    $lines[] = $productName . ' x' . $qty;

    $comments = isset($row['comments']) && is_array($row['comments']) ? $row['comments'] : array();
    foreach ($comments as $comment) {
        $rawText = isset($comment['text']) ? trim((string)$comment['text']) : '';
        if ($rawText === '') {
            continue;
        }
        $type = isset($comment['type']) ? (int)$comment['type'] : 0;
        $label = ($type === 15) ? 'คอมเมนต์เพิ่มราคา' : 'คอมเมนต์';
        $lines[] = ' - ' . $label . ': ' . $rawText;
    }

    $lines[] = str_repeat('-', 32);
    $lines[] = 'Checkout โดย: Checker #' . (int)$finishStaffId;
    $lines[] = 'เวลา: ' . date('d/m/Y H:i:s', $finishedTime ?: time());
    if ($submitTime && $finishedTime && $finishedTime > $submitTime) {
        $lines[] = 'เวลารอ: ' . max(0, floor(($finishedTime - $submitTime) / 60)) . ' นาที';
    }

    return array(
        'printer_name' => $printerName,
        'title' => 'Checkout ' . $tableName,
        'content' => implode("\n", $lines),
        'meta' => array(
            'table_name' => $tableName,
            'product_name' => $productName,
            'process_id' => isset($row['ProcessID']) ? (int)$row['ProcessID'] : 0,
            'sub_process_id' => isset($row['SubProcessID']) ? (int)$row['SubProcessID'] : 0,
            'source' => 'web_checker',
            'finished_at' => $finishedAt,
        ),
    );
}

function enqueueCheckoutPrintJob($conn, $sourceRow, $checkoutPrinterId, $finishStaffId, $finishedAt)
{
    $printer = findAvailablePrinterById($conn, getEffectiveComputerId(), (int)$checkoutPrinterId);
    if (!$printer) {
        throw new Exception('ไม่สามารถพิมพ์ไปยังเครื่องปริ๊นที่เลือกได้');
    }

    $queueTable = resolvePrintJobTable($conn);
    if ($queueTable === '') {
        throw new Exception('ไม่พบตารางคิวพิมพ์สำหรับ Checkout');
    }

    $printRow = decorateCheckoutPrintRow($conn, $sourceRow);

    $transactionId = isset($printRow['TransactionID']) ? (int)$printRow['TransactionID'] : 0;
    $computerId = isset($printRow['ComputerID']) ? (int)$printRow['ComputerID'] : 0;
    $orderDetailId = isset($printRow['OrderDetailID']) ? (int)$printRow['OrderDetailID'] : 0;
    $processId = isset($printRow['ProcessID']) ? (int)$printRow['ProcessID'] : 0;
    $kdsStep = 0;
    $kdsId = 0;
    $printNo = findNextCheckoutPrintNo($conn, $queueTable, $transactionId, $computerId, $orderDetailId, $processId, $kdsStep, $kdsId);

    $saleModeId = isset($printRow['SaleModeID']) ? (int)$printRow['SaleModeID'] : 0;
    $saleModeName = isset($printRow['SaleModeName']) ? trim((string)$printRow['SaleModeName']) : '-';
    $productName = isset($printRow['ProductName']) ? trim((string)$printRow['ProductName']) : 'รายการอาหาร';
    $productHeader = buildCheckoutPrintHeader($printRow);
    $productComment = buildCheckoutPrintComment($printRow);
    $productSetType = isset($printRow['ProductSetType']) ? (int)$printRow['ProductSetType'] : 0;
    $orderLinkId = isset($printRow['ParentProcessID']) ? (int)$printRow['ParentProcessID'] : 0;
    $amount = '1.0000';
    $kdsDate = date('Y-m-d', strtotime($finishedAt));
    $submitTime = !empty($printRow['SubmitOrderDateTime']) ? (string)$printRow['SubmitOrderDateTime'] : $finishedAt;
    $processMinute = calculateProcessMinutes($submitTime, $finishedAt);
    $displayTableName = isset($printRow['DisplayTableName']) ? trim((string)$printRow['DisplayTableName']) : '';
    $seatNo = '';
    $printKdsOrderNo = isset($printRow['OrderNo']) ? (int)$printRow['OrderNo'] : 0;
    $kdsStatus = 2;
    $printStaffName = 'Checker #' . (int)$finishStaffId;
    $printerId = (int)$printer['printer_id'];
    $printerName = isset($printer['printer_name']) ? (string)$printer['printer_name'] : ('Printer #' . $printerId);
    $printerProperty = isset($printer['printer_device_name']) ? (string)$printer['printer_device_name'] : '';
    $jobOrderFromComputerId = getEffectiveComputerId();
    $jobOrderStatus = 0;

    $sql = "
        INSERT INTO `" . $queueTable . "` (
            TransactionID, ComputerID, OrderDetailID, ProcessID, KDSStep, KDSID, PrintNo,
            IsPrintSummary, SaleMode, SaleModeName, ProductHeader, ProductName, ProductComment,
            ProductSetType, OrderLinkID, Amount, KDSDate, KDSStartTime, KDSFinishTime,
            ProcessStartTime, ProcessFinishTime, ProcessMinute, DisplayTableName, SeatNo,
            PrintKDSOrderNo, KDSStatus, PrintStaffName, InsertDateTime, PrintDateTime,
            FinishPrintDateTime, PrinterID, PrinterName, PrinterProperty,
            JobOrderFromComputerID, JobOrderStatus
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?,
            0, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?, NULL,
            NULL, ?, ?, ?,
            ?, ?
        )
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $conn->error);
    }

    $stmt->bind_param(
        'iiiiiiiissssiissssssissiississii',
        $transactionId,
        $computerId,
        $orderDetailId,
        $processId,
        $kdsStep,
        $kdsId,
        $printNo,
        $saleModeId,
        $saleModeName,
        $productHeader,
        $productName,
        $productComment,
        $productSetType,
        $orderLinkId,
        $amount,
        $kdsDate,
        $submitTime,
        $finishedAt,
        $submitTime,
        $finishedAt,
        $processMinute,
        $displayTableName,
        $seatNo,
        $printKdsOrderNo,
        $kdsStatus,
        $printStaffName,
        $finishedAt,
        $printerId,
        $printerName,
        $printerProperty,
        $jobOrderFromComputerId,
        $jobOrderStatus
    );

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new Exception('สร้างคิวพิมพ์ไม่สำเร็จ: ' . $error);
    }
    $stmt->close();

    return array(
        'printer_id' => $printerId,
        'printer_name' => $printerName,
        'queue_table' => $queueTable,
        'print_no' => $printNo,
    );
}

function decorateCheckoutPrintRow($conn, $row)
{
    $rows = attachCommentsToRows($conn, array($row));
    if ($rows && isset($rows[0]) && is_array($rows[0])) {
        return $rows[0];
    }

    $row['comments'] = array();
    if (!isset($row['SaleModeName'])) {
        $row['SaleModeName'] = fetchSaleModeName($conn, isset($row['SaleModeID']) ? (int)$row['SaleModeID'] : 0);
    }
    return $row;
}

function fetchSaleModeName($conn, $saleModeId)
{
    $saleModeId = (int)$saleModeId;
    if ($saleModeId <= 0) {
        return '-';
    }

    $stmt = $conn->prepare("SELECT COALESCE(SaleModeName, '-') AS SaleModeName FROM salemode WHERE SaleModeID = ? LIMIT 1");
    if (!$stmt) {
        return '-';
    }
    $stmt->bind_param('i', $saleModeId);
    $stmt->execute();
    $result = $stmt->get_result();
    $name = '-';
    if ($result && ($row = $result->fetch_assoc())) {
        $name = isset($row['SaleModeName']) ? trim((string)$row['SaleModeName']) : '-';
    }
    $stmt->close();

    return $name !== '' ? $name : '-';
}

function buildCheckoutPrintHeader($row)
{
    $tableName = isset($row['DisplayTableName']) ? trim((string)$row['DisplayTableName']) : '';
    $saleModeName = isset($row['SaleModeName']) ? trim((string)$row['SaleModeName']) : '';
    $parts = array();
    if ($tableName !== '') {
        $parts[] = $tableName;
    }
    if ($saleModeName !== '' && $saleModeName !== '-') {
        $parts[] = $saleModeName;
    }
    return $parts ? implode(' · ', $parts) : 'Checkout';
}

function buildCheckoutPrintComment($row)
{
    $comments = array();
    $list = isset($row['comments']) && is_array($row['comments']) ? $row['comments'] : array();
    foreach ($list as $comment) {
        $rawText = isset($comment['text']) ? trim((string)$comment['text']) : '';
        if ($rawText === '') {
            continue;
        }
        $type = isset($comment['type']) ? (int)$comment['type'] : 0;
        $amount = isset($comment['amount']) ? (float)$comment['amount'] : 0;
        $label = ($type === 15) ? 'คอมเมนต์เพิ่มราคา' : 'คอมเมนต์';
        $suffix = ($amount > 1) ? ' x' . toDecimalString($amount, floor($amount) == $amount ? 0 : 2) : '';
        $comments[] = $label . ': ' . $rawText . $suffix;
    }

    return implode(' | ', $comments);
}

function calculateProcessMinutes($startDateTime, $finishDateTime)
{
    $start = strtotime((string)$startDateTime);
    $finish = strtotime((string)$finishDateTime);
    if (!$start || !$finish || $finish <= $start) {
        return 0;
    }

    return (int)max(0, floor(($finish - $start) / 60));
}

function resolvePrintJobTable($conn)
{
    static $resolved = null;
    if ($resolved !== null) {
        return $resolved;
    }

    foreach (array('kds_printjoborderdetailfront', 'kds_printjoborderdetail') as $tableName) {
        if (tableExists($conn, $tableName)) {
            $resolved = $tableName;
            return $resolved;
        }
    }

    $resolved = '';
    return $resolved;
}

function tableExists($conn, $tableName)
{
    static $cache = array();
    if (isset($cache[$tableName])) {
        return $cache[$tableName];
    }

    $dbRes = $conn->query("SELECT DATABASE()");
    $dbName = ($dbRes && ($dbRow = $dbRes->fetch_row())) ? (string)$dbRow[0] : '';
    $stmt = $conn->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?");
    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $conn->error);
    }
    $stmt->bind_param('ss', $dbName, $tableName);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();
    $exists = $count > 0;
    $cache[$tableName] = $exists;
    return $exists;
}

function findNextCheckoutPrintNo($conn, $queueTable, $transactionId, $computerId, $orderDetailId, $processId, $kdsStep, $kdsId)
{
    $sql = "
        SELECT COALESCE(MAX(PrintNo), 0) + 1 AS NextPrintNo
        FROM `" . $queueTable . "`
        WHERE TransactionID = ?
          AND ComputerID = ?
          AND OrderDetailID = ?
          AND ProcessID = ?
          AND KDSStep = ?
          AND KDSID = ?
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $conn->error);
    }
    $stmt->bind_param('iiiiii', $transactionId, $computerId, $orderDetailId, $processId, $kdsStep, $kdsId);
    $stmt->execute();
    $result = $stmt->get_result();
    $nextPrintNo = 1;
    if ($result && ($row = $result->fetch_assoc())) {
        $nextPrintNo = isset($row['NextPrintNo']) ? (int)$row['NextPrintNo'] : 1;
    }
    $stmt->close();

    return $nextPrintNo > 0 ? $nextPrintNo : 1;
}

function fetchLockedProcessRow($conn, $productLevelId, $processId, $subProcessId, $printerId, $statuses)
{
    $statusSql = implode(', ', array_map('intval', $statuses));
    $sql = "
        SELECT *
        FROM orderprocessdetailfront
        WHERE ProductLevelID = ?
          AND ProcessID = ?
          AND SubProcessID = ?
          AND PrinterID = ?
          AND ProcessStatus IN (" . $statusSql . ")
        FOR UPDATE
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $conn->error);
    }
    $stmt->bind_param('iiii', $productLevelId, $processId, $subProcessId, $printerId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return $row;
}

function fetchLockedChildRows($conn, $productLevelId, $parentProcessId, $printerId, $statuses)
{
    $statusSql = implode(', ', array_map('intval', $statuses));
    $sql = "
        SELECT *
        FROM orderprocessdetailfront
        WHERE ProductLevelID = ?
          AND ParentProcessID = ?
          AND PrinterID = ?
          AND ProcessStatus IN (" . $statusSql . ")
        ORDER BY ProcessID ASC, SubProcessID ASC
        FOR UPDATE
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $conn->error);
    }
    $stmt->bind_param('iii', $productLevelId, $parentProcessId, $printerId);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = array();
    while ($result && ($row = $result->fetch_assoc())) {
        $rows[] = $row;
    }
    $stmt->close();

    return $rows;
}

function fetchLockedFinishedChildRowsForUndo($conn, $finishedParentRow)
{
    $productLevelId = (int)$finishedParentRow['ProductLevelID'];
    $parentProcessId = (int)$finishedParentRow['ProcessID'];
    $printerId = (int)$finishedParentRow['PrinterID'];
    $finishDateTime = isset($finishedParentRow['FinishDateTime']) ? (string)$finishedParentRow['FinishDateTime'] : '';

    if ($finishDateTime === '') {
        return array();
    }

    $sql = "
        SELECT *
        FROM orderprocessdetailfront
        WHERE ProductLevelID = ?
          AND ParentProcessID = ?
          AND PrinterID = ?
          AND ProcessStatus = " . (int)PROCESS_STATUS_FINISHED . "
          AND FinishDateTime = ?
        ORDER BY ProcessID ASC, SubProcessID DESC
        FOR UPDATE
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $conn->error);
    }
    $stmt->bind_param('iiis', $productLevelId, $parentProcessId, $printerId, $finishDateTime);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = array();
    while ($result && ($row = $result->fetch_assoc())) {
        $rows[] = $row;
    }
    $stmt->close();

    return $rows;
}

function calculateChildCheckoutQty($parentQty, $childQty)
{
    $parentQty = (float)$parentQty;
    $childQty = (float)$childQty;

    if ($childQty <= 0) {
        return 0;
    }
    if ($parentQty <= 1) {
        return $childQty;
    }

    $perUnit = $childQty / $parentQty;
    if ($perUnit <= 0) {
        return 0;
    }

    if ($perUnit > $childQty) {
        $perUnit = $childQty;
    }

    return (float)toDecimalString($perUnit, 2);
}

function applyCheckoutSplit($conn, $row, $qtyToFinish, $finishStaffId, $now)
{
    $currentQty = isset($row['ProductAmount']) ? (float)$row['ProductAmount'] : 0;
    $qtyToFinish = (float)$qtyToFinish;
    if ($currentQty <= 0 || $qtyToFinish <= 0) {
        return;
    }

    if ($qtyToFinish >= $currentQty) {
        $updateSql = "
            UPDATE orderprocessdetailfront
            SET FinishStaffID = ?,
                FinishDateTime = ?,
                ProcessStatus = ?
            WHERE ProductLevelID = ?
              AND ProcessID = ?
              AND SubProcessID = ?
              AND PrinterID = ?
              AND ProcessStatus IN (" . (int)PROCESS_STATUS_ACTIVE . ", " . (int)PROCESS_STATUS_IN_PROCESS . ")
        ";
        $finishedStatus = (int)PROCESS_STATUS_FINISHED;
        $stmt = $conn->prepare($updateSql);
        if (!$stmt) {
            throw new Exception('Prepare failed: ' . $conn->error);
        }
        $productLevelId = (int)$row['ProductLevelID'];
        $processId = (int)$row['ProcessID'];
        $subProcessId = (int)$row['SubProcessID'];
        $printerId = (int)$row['PrinterID'];
        $stmt->bind_param('isiiiii', $finishStaffId, $now, $finishedStatus, $productLevelId, $processId, $subProcessId, $printerId);
        $stmt->execute();
        if ($stmt->affected_rows < 1) {
            $stmt->close();
            throw new Exception('ไม่สามารถ checkout รายการนี้ได้');
        }
        $stmt->close();
        return;
    }

    $nextSubProcessId = findNextSubProcessId($conn, (int)$row['ProductLevelID'], (int)$row['ProcessID'], (int)$row['PrinterID']);
    $remainingQty = toDecimalString($currentQty - $qtyToFinish, 2);
    $finishQty = toDecimalString($qtyToFinish, 2);

    $updateSql = "
        UPDATE orderprocessdetailfront
        SET ProductAmount = ?
        WHERE ProductLevelID = ?
          AND ProcessID = ?
          AND SubProcessID = ?
          AND PrinterID = ?
          AND ProcessStatus IN (" . (int)PROCESS_STATUS_ACTIVE . ", " . (int)PROCESS_STATUS_IN_PROCESS . ")
    ";
    $stmt = $conn->prepare($updateSql);
    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $conn->error);
    }
    $productLevelId = (int)$row['ProductLevelID'];
    $processId = (int)$row['ProcessID'];
    $subProcessId = (int)$row['SubProcessID'];
    $printerId = (int)$row['PrinterID'];
    $stmt->bind_param('siiii', $remainingQty, $productLevelId, $processId, $subProcessId, $printerId);
    $stmt->execute();
    if ($stmt->affected_rows < 1) {
        $stmt->close();
        throw new Exception('ไม่สามารถลดจำนวนคงเหลือได้');
    }
    $stmt->close();

    $insertSql = "
        INSERT INTO orderprocessdetailfront (
            ProductLevelID,
            ProcessID,
            SubProcessID,
            TransactionID,
            ComputerID,
            OrderDetailID,
            ProductID,
            ProductName,
            ProductAmount,
            ProductSetType,
            SubmitOrderStaffID,
            SubmitOrderDateTime,
            FinishStaffID,
            FinishDateTime,
            PrinterID,
            OrderNo,
            OrderDate,
            TableID,
            DisplayTableName,
            IsMoveOrder,
            ProcessStatus,
            ParentProcessID,
            SaleModeID
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
        )
    ";
    $stmt = $conn->prepare($insertSql);
    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $conn->error);
    }

    $insertProductLevelId = (int)$row['ProductLevelID'];
    $insertProcessId = (int)$row['ProcessID'];
    $insertSubProcessId = (int)$nextSubProcessId;
    $insertTransactionId = (int)$row['TransactionID'];
    $insertComputerId = (int)$row['ComputerID'];
    $insertOrderDetailId = (int)$row['OrderDetailID'];
    $insertProductId = (int)$row['ProductID'];
    $insertProductName = (string)$row['ProductName'];
    $insertProductAmount = $finishQty;
    $insertProductSetType = (int)$row['ProductSetType'];
    $insertSubmitOrderStaffId = (int)$row['SubmitOrderStaffID'];
    $insertSubmitOrderDateTime = isset($row['SubmitOrderDateTime']) && $row['SubmitOrderDateTime'] !== null ? (string)$row['SubmitOrderDateTime'] : null;
    $insertFinishStaffId = (int)$finishStaffId;
    $insertFinishDateTime = $now;
    $insertPrinterId = (int)$row['PrinterID'];
    $insertOrderNo = (int)$row['OrderNo'];
    $insertOrderDate = isset($row['OrderDate']) && $row['OrderDate'] !== null ? (string)$row['OrderDate'] : null;
    $insertTableId = (int)$row['TableID'];
    $insertDisplayTableName = $row['DisplayTableName'] !== null ? (string)$row['DisplayTableName'] : '';
    $insertIsMoveOrder = (int)$row['IsMoveOrder'];
    $insertProcessStatus = (int)PROCESS_STATUS_FINISHED;
    $insertParentProcessId = (int)$row['ParentProcessID'];
    $insertSaleModeId = (int)$row['SaleModeID'];

    $stmt->bind_param(
        'iiiiiiissiisisiisisiiii',
        $insertProductLevelId,
        $insertProcessId,
        $insertSubProcessId,
        $insertTransactionId,
        $insertComputerId,
        $insertOrderDetailId,
        $insertProductId,
        $insertProductName,
        $insertProductAmount,
        $insertProductSetType,
        $insertSubmitOrderStaffId,
        $insertSubmitOrderDateTime,
        $insertFinishStaffId,
        $insertFinishDateTime,
        $insertPrinterId,
        $insertOrderNo,
        $insertOrderDate,
        $insertTableId,
        $insertDisplayTableName,
        $insertIsMoveOrder,
        $insertProcessStatus,
        $insertParentProcessId,
        $insertSaleModeId
    );
    $stmt->execute();
    if ($stmt->affected_rows < 1) {
        $stmt->close();
        throw new Exception('ไม่สามารถสร้างรายการ checkout ใหม่ได้');
    }
    $stmt->close();
}

function undoFinishedProcessRow($conn, $finishedRow)
{
    $productLevelId = (int)$finishedRow['ProductLevelID'];
    $processId = (int)$finishedRow['ProcessID'];
    $printerId = (int)$finishedRow['PrinterID'];
    $subProcessId = (int)$finishedRow['SubProcessID'];

    $findActiveSql = "
        SELECT *
        FROM orderprocessdetailfront
        WHERE ProductLevelID = ?
          AND ProcessID = ?
          AND PrinterID = ?
          AND ProcessStatus IN (" . (int)PROCESS_STATUS_ACTIVE . ", " . (int)PROCESS_STATUS_IN_PROCESS . ")
        ORDER BY SubProcessID ASC
        LIMIT 1
        FOR UPDATE
    ";
    $stmt = $conn->prepare($findActiveSql);
    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $conn->error);
    }
    $stmt->bind_param('iii', $productLevelId, $processId, $printerId);
    $stmt->execute();
    $result = $stmt->get_result();
    $activeRow = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if ($activeRow) {
        $newQty = toDecimalString(((float)$activeRow['ProductAmount']) + ((float)$finishedRow['ProductAmount']), 2);
        $updateActiveSql = "
            UPDATE orderprocessdetailfront
            SET ProductAmount = ?
            WHERE ProductLevelID = ?
              AND ProcessID = ?
              AND SubProcessID = ?
              AND PrinterID = ?
        ";
        $stmt = $conn->prepare($updateActiveSql);
        if (!$stmt) {
            throw new Exception('Prepare failed: ' . $conn->error);
        }
        $activeProductLevelId = (int)$activeRow['ProductLevelID'];
        $activeProcessId = (int)$activeRow['ProcessID'];
        $activeSubProcessId = (int)$activeRow['SubProcessID'];
        $activePrinterId = (int)$activeRow['PrinterID'];
        $stmt->bind_param('siiii', $newQty, $activeProductLevelId, $activeProcessId, $activeSubProcessId, $activePrinterId);
        $stmt->execute();
        $stmt->close();

        $deleteSql = "
            DELETE FROM orderprocessdetailfront
            WHERE ProductLevelID = ?
              AND ProcessID = ?
              AND SubProcessID = ?
              AND PrinterID = ?
              AND ProcessStatus = " . (int)PROCESS_STATUS_FINISHED . "
        ";
        $stmt = $conn->prepare($deleteSql);
        if (!$stmt) {
            throw new Exception('Prepare failed: ' . $conn->error);
        }
        $stmt->bind_param('iiii', $productLevelId, $processId, $subProcessId, $printerId);
        $stmt->execute();
        if ($stmt->affected_rows < 1) {
            $stmt->close();
            throw new Exception('ไม่สามารถลบรายการเสร็จเพื่อย้อนกลับได้');
        }
        $stmt->close();
    } else {
        $resetSql = "
            UPDATE orderprocessdetailfront
            SET FinishStaffID = 0,
                FinishDateTime = NULL,
                ProcessStatus = " . (int)PROCESS_STATUS_ACTIVE . "
            WHERE ProductLevelID = ?
              AND ProcessID = ?
              AND SubProcessID = ?
              AND PrinterID = ?
              AND ProcessStatus = " . (int)PROCESS_STATUS_FINISHED;
        $stmt = $conn->prepare($resetSql);
        if (!$stmt) {
            throw new Exception('Prepare failed: ' . $conn->error);
        }
        $stmt->bind_param('iiii', $productLevelId, $processId, $subProcessId, $printerId);
        $stmt->execute();
        if ($stmt->affected_rows < 1) {
            $stmt->close();
            throw new Exception('ไม่สามารถย้อนกลับรายการนี้ได้');
        }
        $stmt->close();
    }
}

function findNextSubProcessId($conn, $productLevelId, $processId, $printerId)
{
    $sql = "
        SELECT COALESCE(MAX(SubProcessID), 0) + 1 AS next_id
        FROM orderprocessdetailfront
        WHERE ProductLevelID = ?
          AND ProcessID = ?
          AND PrinterID = ?
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $conn->error);
    }
    $stmt->bind_param('iii', $productLevelId, $processId, $printerId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return $row ? (int)$row['next_id'] : 1;
}

function listOutOfStockProducts($conn)
{
    $keyword = requestString('q', '');
    $rows = fetchOutOfStockProducts($conn, $keyword);

    jsonResponse(array(
        'success' => true,
        'rows' => $rows,
        'count' => count($rows),
    ));
}

function fetchOutOfStockProducts($conn, $keyword = '')
{
    $where = array(
        'p.Deleted = 0',
        'p.ProductActivate = 1',
        'COALESCE(pg.IsComment,0) = 0'
    );

    $types = '';
    $params = array();
    if ($keyword !== '') {
        $where[] = '(p.ProductCode LIKE ? OR p.ProductName LIKE ? OR p.ProductName1 LIKE ? OR pd.ProductDeptName LIKE ? OR pg.ProductGroupName LIKE ?)';
        $like = '%' . $keyword . '%';
        $types = 'sssss';
        $params = array($like, $like, $like, $like, $like);
    }

    $sql = "
        SELECT
            p.ProductID,
            p.ProductCode,
            p.ProductName,
            p.ProductName1,
            p.IsOutOfStock,
            p.UpdateDate,
            p.UpdateBy,
            COALESCE(pd.ProductDeptName, '-') AS ProductDeptName,
            COALESCE(pg.ProductGroupName, '-') AS ProductGroupName
        FROM products p
        LEFT JOIN productdept pd
            ON pd.ProductDeptID = p.ProductDeptID
           AND pd.Deleted = 0
        LEFT JOIN productgroup pg
            ON pg.ProductGroupID = pd.ProductGroupID
           AND pg.Deleted = 0
        WHERE " . implode(' AND ', $where) . "
        ORDER BY
            p.IsOutOfStock ASC,
            pg.ProductGroupName ASC,
            pd.ProductDeptName ASC,
            p.ProductOrdering ASC,
            p.ProductCode ASC,
            p.ProductID ASC";

    if ((int)OUT_OF_STOCK_SHOW_LIMIT > 0) {
        $sql .= ' LIMIT ' . (int)OUT_OF_STOCK_SHOW_LIMIT;
    }

    if ($types === '') {
        return fetchAllRows($conn, $sql);
    }

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $conn->error);
    }
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = array();
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
    }
    $stmt->close();
    return $rows;
}

function setProductOutOfStock($conn)
{
    $productId = requestInt('product_id');
    $isOutOfStock = requestInt('is_out_of_stock', 1) ? 1 : 0;
    $updateBy = requestInt('update_by', 0);

    $checkSql = "SELECT ProductID, ProductName, ProductCode, IsOutOfStock FROM products WHERE ProductID = ? AND Deleted = 0 LIMIT 1";
    $stmt = $conn->prepare($checkSql);
    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $conn->error);
    }
    $stmt->bind_param('i', $productId);
    $stmt->execute();
    $result = $stmt->get_result();
    $product = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if (!$product) {
        throw new Exception('ไม่พบสินค้า');
    }

    $conn->begin_transaction();
    try {
        $updateSql = "
            UPDATE products
            SET IsOutOfStock = ?,
                UpdateDate = NOW(),
                UpdateBy = ?
            WHERE ProductID = ?
              AND Deleted = 0
        ";
        $stmt = $conn->prepare($updateSql);
        if (!$stmt) {
            throw new Exception('Prepare failed: ' . $conn->error);
        }
        $stmt->bind_param('iii', $isOutOfStock, $updateBy, $productId);
        $stmt->execute();
        $stmt->close();
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }

    $actionText = $isOutOfStock ? 'ปิดสินค้าหมดแล้ว' : 'เปิดขายสินค้าแล้ว';
    $productName = isset($product['ProductName']) ? (string)$product['ProductName'] : '-';
    writeActivityLog('OUT_OF_STOCK', ($isOutOfStock ? 'CLOSE' : 'OPEN') . ' ProductID:' . $productId . ' ' . $productName, $updateBy);
    jsonResponse(array(
        'success' => true,
        'message' => $actionText,
        'product' => array(
            'ProductID' => (int)$product['ProductID'],
            'ProductName' => (string)$product['ProductName'],
            'ProductCode' => (string)$product['ProductCode'],
            'IsOutOfStock' => $isOutOfStock,
        ),
    ));
}

function requestString($key, $default = null)
{
    if (!isset($_REQUEST[$key])) {
        return $default !== null ? (string)$default : '';
    }

    return trim((string)$_REQUEST[$key]);
}

function requestInt($key, $default = null)
{
    if (!isset($_REQUEST[$key]) || $_REQUEST[$key] === '') {
        if ($default !== null) {
            return (int)$default;
        }
        throw new Exception('ข้อมูลไม่ครบ: ' . $key);
    }

    return (int)$_REQUEST[$key];
}
