<?php
/**
 * ============================================================
 *  API: TẠO ĐƠN NGHỈ PHÉP
 *  File: backend/api/taodonnghiphep.php
 * ============================================================
 */

if (!ob_get_level()) { ob_start(); }

@header('Content-Type: application/json; charset=utf-8');
@header('X-Content-Type-Options: nosniff');
@header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
@header('Pragma: no-cache');

set_exception_handler(function (Throwable $ex) {
    while (ob_get_level()) { ob_end_clean(); }
    $debug = defined('IS_DEV_ENV') && IS_DEV_ENV;
    error_log('[TaoDonNghi] Exception: ' . $ex->getMessage() . ' @ ' . $ex->getFile() . ':' . $ex->getLine());
    echo json_encode([
        'success' => false,
        'message' => $debug ? 'Exception: ' . $ex->getMessage() . ' @ ' . basename($ex->getFile()) . ':' . $ex->getLine()
                            : 'Đã xảy ra lỗi hệ thống.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
});

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        while (ob_get_level()) { ob_end_clean(); }
        $debug = defined('IS_DEV_ENV') && IS_DEV_ENV;
        error_log('[TaoDonNghi] Fatal: ' . $err['message'] . ' @ ' . $err['file'] . ':' . $err['line']);
        echo json_encode([
            'success' => false,
            'message' => $debug ? 'Fatal: ' . $err['message'] . ' @ ' . basename($err['file']) . ':' . $err['line']
                                : 'Lỗi nghiêm trọng.',
        ], JSON_UNESCAPED_UNICODE);
    }
});

$configPath = __DIR__ . '/../config/functions.php';
if (!file_exists($configPath)) {
    echo json_encode(['success' => false, 'message' => 'Không tìm thấy file cấu hình.']);
    exit;
}
require_once $configPath;

if (!function_exists('jsonError')) {
    function jsonError($message, $code = 400, $extra = []) {
        while (ob_get_level() > 0) { ob_end_clean(); }
        if (!headers_sent()) header('Content-Type: application/json; charset=utf-8', true, $code);
        else http_response_code($code);
        echo json_encode(array_merge(['success' => false, 'message' => $message],
            is_array($extra) ? $extra : []), JSON_UNESCAPED_UNICODE);
        exit;
    }
}
if (!function_exists('jsonSuccess')) {
    function jsonSuccess($data = [], $message = 'Thành công') {
        while (ob_get_level() > 0) { ob_end_clean(); }
        if (!headers_sent()) header('Content-Type: application/json; charset=utf-8', true, 200);
        $payload = ['success' => true, 'message' => $message, 'data' => $data];
        if (is_array($data) && isset($data['success'])) {
            $payload = $data;
            if (!isset($payload['message'])) $payload['message'] = $message;
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if (!function_exists('isLoggedIn')) jsonError('Hàm isLoggedIn chưa được định nghĩa.', 500);
if (!isLoggedIn()) jsonError('Phiên đăng nhập đã hết hạn. Vui lòng đăng nhập lại!', 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Phương thức không hợp lệ! Chỉ chấp nhận POST.', 405);

$currentUser = getCurrentUser();
$currentEmp  = getCurrentEmployee();
if (!$currentEmp || empty($currentEmp['id'])) jsonError('Tài khoản chưa liên kết hồ sơ nhân viên!', 403);
$nhanVienId = (int)$currentEmp['id'];
$db = Database::getConnection();

/* ⭐ ĐỌC DỮ LIỆU TỪ JSON BODY THAY VÌ $_POST ĐỂ LÁCH WAF */
$rawInput = file_get_contents('php://input');
$jsonInput = json_decode($rawInput, true) ?: [];

// Xử lý CSRF Token từ JSON
$csrfToken = $jsonInput['csrf_token'] ?? '';
if (function_exists('validateCsrfToken') && !validateCsrfToken($csrfToken)) {
    jsonError('Phiên làm việc hết hạn hoặc CSRF token không hợp lệ.', 403);
}

/* ---------- CONSTANTS ---------- */
if (!defined('MAX_CONSECUTIVE_DAYS'))    define('MAX_CONSECUTIVE_DAYS', 3);
if (!defined('MAX_SIGNATURE_FILE_SIZE')) define('MAX_SIGNATURE_FILE_SIZE', 2 * 1024 * 1024);
if (!defined('CA_NGHI_MAP')) define('CA_NGHI_MAP', [
    'full' => 'ca_ngay', 'morning' => 'sang', 'afternoon' => 'chieu',
]);
if (!defined('CA_NGHI_LABEL')) define('CA_NGHI_LABEL', [
    'ca_ngay' => 'Cả ngày', 'sang' => 'Buổi sáng', 'chieu' => 'Buổi chiều',
]);

/* ---------- HELPERS ---------- */
if (!function_exists('caNghiToDays'))    { function caNghiToDays(string $ca): float { return ($ca === 'full') ? 1.0 : 0.5; } }
if (!function_exists('normalizeCaNghi')) { function normalizeCaNghi(string $raw): string { return array_key_exists($raw, CA_NGHI_MAP) ? $raw : 'full'; } }
if (!function_exists('fmtDays'))         { function fmtDays(float $d): string { return rtrim(rtrim(number_format($d, 2, '.', ''), '0'), '.'); } }
if (!function_exists('moTaKhoangNghi')) {
    function moTaKhoangNghi(string $start, string $end, int $totalRangeDays): string {
        $s = CA_NGHI_LABEL[CA_NGHI_MAP[$start]] ?? $start;
        $e = CA_NGHI_LABEL[CA_NGHI_MAP[$end]]   ?? $end;
        if ($totalRangeDays <= 1 || $start === $end) return "ca: {$s}";
        return "ca: {$s} (đầu) → {$e} (cuối)";
    }
}
if (!function_exists('getTableColumnsTaoDon')) {
    function getTableColumnsTaoDon(PDO $db, string $table): array {
        $cols = [];
        try {
            $stmt = $db->query("SHOW COLUMNS FROM `$table`");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $c) $cols[$c['Field']] = true;
        } catch (Throwable $e) { error_log("[TaoDonNghi] getTableColumns($table): " . $e->getMessage()); }
        return $cols;
    }
}

/* ---------- LẤY DỮ LIỆU TỪ JSON ---------- */
$tuNgay          = trim($jsonInput['tu_ngay']  ?? '');
$denNgay         = trim($jsonInput['den_ngay'] ?? '');
$soNgay          = (float)($jsonInput['so_ngay'] ?? 0);
$lyDo            = trim($jsonInput['ly_do']    ?? '');
$actionType      = $jsonInput['action_type']   ?? 'submit';
$chuKyBase64     = trim($jsonInput['chu_ky_nguoi_nghi'] ?? '');
$signatureMethod = $jsonInput['signature_method'] ?? 'draw';
$hoTenNguoiKy    = trim($jsonInput['ho_ten_nguoi_ky'] ?? '');

$caNghiStartRaw = normalizeCaNghi($jsonInput['ca_nghi_start'] ?? 'full');
$caNghiEndRaw   = normalizeCaNghi($jsonInput['ca_nghi_end']   ?? 'full');

if (!in_array($actionType, ['submit', 'draft'], true)) $actionType = 'submit';
if (!in_array($signatureMethod, ['draw', 'upload'], true)) $signatureMethod = 'draw';

/* ---------- VALIDATE ---------- */
$errors         = [];
$totalRangeDays = 0;

if ($tuNgay === '')  $errors[] = 'Vui lòng chọn ngày bắt đầu nghỉ phép.';
if ($denNgay === '') $errors[] = 'Vui lòng chọn ngày kết thúc nghỉ phép.';

if ($tuNgay !== '' && $denNgay !== '') {
    $t1 = strtotime($tuNgay); $t2 = strtotime($denNgay);
    if ($t1 === false || $t2 === false) $errors[] = 'Định dạng ngày không hợp lệ.';
    elseif ($t2 < $t1) $errors[] = 'Ngày kết thúc không được nhỏ hơn ngày bắt đầu.';
    else {
        $totalRangeDays = (int)floor(($t2 - $t1) / 86400) + 1;
        if ($totalRangeDays > MAX_CONSECUTIVE_DAYS) $errors[] = 'Không thể nghỉ quá ' . MAX_CONSECUTIVE_DAYS . ' ngày liên tục.';
        if ($t1 <= strtotime(date('Y-m-d'))) $errors[] = 'Phải xin nghỉ trước ít nhất 1 ngày (từ ngày mai trở đi).';
    }
}

if ($soNgay <= 0) $errors[] = 'Số ngày nghỉ phải lớn hơn 0.';
elseif ($soNgay > MAX_CONSECUTIVE_DAYS) $errors[] = 'Số ngày nghỉ không được vượt quá ' . MAX_CONSECUTIVE_DAYS . '.';
elseif ((round($soNgay * 2) / 2) !== $soNgay) $errors[] = 'Số ngày phải là bội số của 0.5.';

if ($lyDo === '') $errors[] = 'Vui lòng nhập lý do nghỉ phép.';
elseif (mb_strlen($lyDo) > 1000) $errors[] = 'Lý do nghỉ phép quá dài (tối đa 1000 ký tự).';
elseif (!preg_match('/^[\p{L}\p{N}\s]+$/u', $lyDo)) $errors[] = 'Lý do chỉ được chứa chữ, số và khoảng trắng.';

if ($hoTenNguoiKy === '') {
    $errors[] = 'Vui lòng nhập họ tên người ký.';
} elseif (mb_strlen($hoTenNguoiKy) > 50) {
    $errors[] = 'Họ tên người ký quá dài (tối đa 50 ký tự).';
} elseif (!preg_match('/^[\p{L}\s]+$/u', $hoTenNguoiKy)) {
    $errors[] = 'Họ tên người ký chỉ được chứa chữ cái và khoảng trắng.';
}

if ($totalRangeDays > 0 && empty($errors)) {
    if ($totalRangeDays === 1) {
        if ($caNghiStartRaw !== $caNghiEndRaw) $errors[] = 'Nghỉ 1 ngày chỉ được chọn 1 ca.';
        $expected = caNghiToDays($caNghiStartRaw);
    } else {
        $expected = caNghiToDays($caNghiStartRaw) + ($totalRangeDays - 2) + caNghiToDays($caNghiEndRaw);
    }
    if (abs($soNgay - $expected) > 0.001) {
        $errors[] = sprintf('Số ngày (%s) không khớp với ca nghỉ đã chọn (kỳ vọng %s).', fmtDays($soNgay), fmtDays($expected));
    }
}

if (!empty($errors)) jsonError($errors[0], 422, ['errors' => $errors]);
if ($totalRangeDays <= 1) $caNghiEndRaw = $caNghiStartRaw;

$buoiNghi        = CA_NGHI_MAP[$caNghiStartRaw];
$buoiNghiKetThuc = CA_NGHI_MAP[$caNghiEndRaw];

/* ---------- QUOTA ---------- */
if (function_exists('getPhepConLai')) {
    try {
        $phepConLai = (float)getPhepConLai($nhanVienId);
        if ($soNgay > $phepConLai) jsonError(sprintf('Số ngày nghỉ (%s) vượt quá số phép còn lại (%s ngày).', fmtDays($soNgay), fmtDays($phepConLai)), 422);
    } catch (Throwable $e) { error_log('[TaoDonNghi] getPhepConLai: ' . $e->getMessage()); }
}

/* ============================================================
   ⭐ CHỮ KÝ — LƯU TỪ CHUỖI BASE64 (CẢ VẼ VÀ UPLOAD)
   ============================================================ */
$chuKyPath = null;
$sigDir    = dirname(__DIR__, 2) . '/frontend/uploads/chuky';

if ($chuKyBase64 !== '') {
    // Xác định định dạng ảnh từ chuỗi Base64
    if (!preg_match('#^data:image/(\w+);base64,#i', $chuKyBase64, $matches)) {
        jsonError('Định dạng chữ ký không hợp lệ.', 422);
    }
    
    $ext = strtolower($matches[1]);
    if ($ext === 'jpeg') $ext = 'jpg';
    if (!in_array($ext, ['jpg', 'png', 'webp'])) {
        jsonError('Chỉ hỗ trợ ảnh JPG, PNG, WEBP.', 422);
    }

    $parts = explode(',', $chuKyBase64, 2);
    if (!isset($parts[1])) jsonError('Dữ liệu chữ ký không hợp lệ.', 422);
    
    $decoded = base64_decode($parts[1], true);
    if ($decoded === false || strlen($decoded) === 0) {
        jsonError('Dữ liệu chữ ký không hợp lệ.', 422);
    }
    if (strlen($decoded) > MAX_SIGNATURE_FILE_SIZE) {
        jsonError('Chữ ký quá lớn (tối đa 2MB).', 422);
    }

    if (!is_dir($sigDir)) @mkdir($sigDir, 0775, true);
    if (!is_dir($sigDir) || !is_writable($sigDir)) {
        jsonError('Không thể lưu chữ ký. Liên hệ kỹ thuật!', 500);
    }

    $fileName = 'sign_' . $nhanVienId . '_' . time() . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
    $destPath = rtrim($sigDir, '/\\') . DIRECTORY_SEPARATOR . $fileName;

    if (file_put_contents($destPath, $decoded) === false) {
        jsonError('Không thể lưu file chữ ký.', 500);
    }
    $chuKyPath = 'frontend/uploads/chuky/' . $fileName;
} else {
    jsonError('Vui lòng cung cấp chữ ký (vẽ hoặc upload).', 422);
}

/* ---------- MÃ ĐƠN ---------- */
$maDon = 'NP-' . date('Ymd-His') . '-' . str_shuffle(
    chr(mt_rand(65, 90)) . mt_rand(0, 9) . chr(mt_rand(65, 90)) . mt_rand(0, 9)
);

$trangThai = ($actionType === 'draft') ? 'draft' : 'cho_truong_khoa';

/* ============================================================
   INSERT
   ============================================================ */
try {
    $columns       = getTableColumnsTaoDon($db, 'nghi_phep');
    $hasHoTenKyCol = isset($columns['ho_ten_nguoi_ky']);

    $cols = ['ma_don', 'nhan_vien_id', 'tu_ngay', 'den_ngay',
             'buoi_nghi', 'buoi_nghi_ket_thuc', 'so_ngay', 'ly_do', 'chu_ky_nguoi_nghi'];
    $vals = [$maDon, $nhanVienId, $tuNgay, $denNgay,
             $buoiNghi, $buoiNghiKetThuc, $soNgay, $lyDo, $chuKyPath];

    if ($hasHoTenKyCol) {
        $cols[] = 'ho_ten_nguoi_ky';
        $vals[] = $hoTenNguoiKy;
    }

    $cols[] = 'trang_thai';
    $vals[] = $trangThai;

    $placeholders = implode(',', array_fill(0, count($cols), '?'));
    $colsQuoted   = '`' . implode('`,`', $cols) . '`';

    $sql  = "INSERT INTO nghi_phep ($colsQuoted, created_at) VALUES ($placeholders, NOW())";
    $stmt = $db->prepare($sql);
    $stmt->execute($vals);

    $newId = (int)$db->lastInsertId();
} catch (Throwable $e) {
    error_log('[TaoDonNghi] INSERT error: ' . $e->getMessage());
    jsonError('Không thể tạo đơn. Vui lòng thử lại!', 500);
}

/* ---------- LỊCH SỬ ---------- */
if (function_exists('recordLeaveHistory')) {
    try {
        $moTaCa = moTaKhoangNghi($caNghiStartRaw, $caNghiEndRaw, $totalRangeDays);
        recordLeaveHistory(
            $newId, (int)($currentUser['id'] ?? 0),
            ($actionType === 'draft') ? 'Tạo bản nháp' : 'Tạo và gửi',
            null, $trangThai,
            "Nhân viên tạo đơn nghỉ " . fmtDays($soNgay) . " ngày ({$moTaCa})"
        );
    } catch (Throwable $e) { error_log('[TaoDonNghi] recordLeaveHistory: ' . $e->getMessage()); }
}

/* ---------- NOTIFY TK ---------- */
if ($trangThai === 'cho_truong_khoa' && function_exists('createNotification') && !empty($currentEmp['khoa_phong_id'])) {
    try {
        $stmtTK = $db->prepare("
            SELECT u.id FROM users u
            JOIN nhan_vien nv ON u.nhan_vien_id = nv.id
            WHERE nv.khoa_phong_id = ? AND u.vai_tro = 'truong_khoa'
        ");
        $stmtTK->execute([$currentEmp['khoa_phong_id']]);

        $moTaCa = moTaKhoangNghi($caNghiStartRaw, $caNghiEndRaw, $totalRangeDays);
        $hoTen  = $currentEmp['ho_ten'] ?? 'Nhân viên';

        while ($tk = $stmtTK->fetch()) {
            createNotification(
                (int)$tk['id'],
                'Có giấy nghỉ phép mới',
                "Nhân viên {$hoTen} vừa tạo đơn nghỉ " . fmtDays($soNgay) . " ngày ({$moTaCa}) - Mã đơn: {$maDon}",
                'truongkhoaduyetdonnghiphep.html'
            );
        }
    } catch (Throwable $e) { error_log('[TaoDonNghi] createNotification: ' . $e->getMessage()); }
}

/* ---------- RESPONSE ---------- */
while (ob_get_level() > 0) { ob_end_clean(); }

$redirectUrl = 'lichsudonnghiphep.html';

$response = [
    'success' => true,
    'message' => 'Tạo giấy nghỉ phép thành công! Mã đơn: ' . $maDon,
    'data' => [
        'id'                  => $newId,
        'ma_don'              => $maDon,
        'trang_thai'          => $trangThai,
        'tu_ngay'             => $tuNgay,
        'den_ngay'            => $denNgay,
        'buoi_nghi'           => $buoiNghi,
        'buoi_nghi_ket_thuc'  => $buoiNghiKetThuc,
        'so_ngay'             => $soNgay,
        'ho_ten_nguoi_ky'     => $hoTenNguoiKy,
        'redirect_url'        => $redirectUrl,
    ],
    'id'           => $newId,
    'ma_don'       => $maDon,
    'redirect_url' => $redirectUrl,
];

echo json_encode($response, JSON_UNESCAPED_UNICODE);
exit;