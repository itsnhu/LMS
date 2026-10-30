<?php
/**
 * ============================================================
 *  API: ĐĂNG NHẬP / ĐĂNG XUẤT
 *  BỆNH VIỆN ĐA KHOA TÂM TRÍ ĐỒNG THÁP
 *  File: backend/api/dangnhap.php
 * ============================================================
 *  POST (login):
 *   - username   : tên đăng nhập HOẶC mã nhân viên
 *   - password   : mật khẩu
 *   - csrf_token : CSRF token
 *
 *  GET/POST (logout):
 *   - action=logout
 *
 *  ⭐ FIX: Load config (start session) TRƯỚC khi gửi header.
 *  ⭐ WORKAROUND: Nhận payload base64 qua field "data" (bypass WAF InfinityFree).
 * ============================================================
 */

/* 1. OUTPUT BUFFERING */
if (!ob_get_level()) { ob_start(); }

$__API_DEBUG = false;   // Bật true khi debug local

/* ============================================================
   2. GLOBAL ERROR HANDLERS
   ============================================================ */
set_exception_handler(function (Throwable $ex) use ($__API_DEBUG) {
    while (ob_get_level()) { ob_end_clean(); }
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8', true, 500);
    } else {
        http_response_code(500);
    }
    echo json_encode([
        'success' => false,
        'message' => $__API_DEBUG
            ? 'Exception: ' . $ex->getMessage() . ' @ ' . basename($ex->getFile()) . ':' . $ex->getLine()
            : 'Đã xảy ra lỗi hệ thống. Vui lòng thử lại sau!',
    ], JSON_UNESCAPED_UNICODE);
    exit;
});

register_shutdown_function(function () use ($__API_DEBUG) {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        while (ob_get_level()) { ob_end_clean(); }
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8', true, 500);
        } else {
            http_response_code(500);
        }
        echo json_encode([
            'success' => false,
            'message' => $__API_DEBUG
                ? 'Fatal: ' . $err['message'] . ' @ ' . basename($err['file']) . ':' . $err['line']
                : 'Lỗi nghiêm trọng từ server!',
        ], JSON_UNESCAPED_UNICODE);
    }
});

set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    if (!(error_reporting() & $errno)) return false;
    error_log('[DangNhap] ' . $errstr . ' @ ' . basename($errfile) . ':' . $errline);
    return true;
});

/* ============================================================
   3. LOAD CONFIG TRƯỚC — session phải start trước khi gửi header
   ============================================================ */
$configFile = __DIR__ . '/../config/functions.php';

if (!file_exists($configFile)) {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8', true, 500);
    }
    echo json_encode([
        'success' => false,
        'message' => 'Không tìm thấy file cấu hình: ' . $configFile,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once $configFile;

/* ============================================================
   4. HEADERS
   ============================================================ */
@header('Content-Type: application/json; charset=utf-8');
@header('X-Content-Type-Options: nosniff');
@header('Cache-Control: no-store, no-cache, must-revalidate');
@header('Pragma: no-cache');

/* ============================================================
   5. ĐỌC INPUT
   ============================================================ */
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/* ============================================================
   6. XỬ LÝ LOGOUT
   ============================================================ */
if ($action === 'logout') {
    $_SESSION = [];

    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }

    session_destroy();

    jsonResponse([
        'success' => true,
        'message' => 'Đã đăng xuất thành công!',
    ]);
}

/* ============================================================
   7. XỬ LÝ LOGIN
   ============================================================ */
if ($method !== 'POST') {
    jsonError('Phương thức không hợp lệ! Chỉ chấp nhận POST.', 405);
}

/* ============================================================
   7.0 WORKAROUND InfinityFree WAF
   ------------------------------------------------------------
   Client gửi field "data" = base64(JSON{username, password, csrf_token}).
   → WAF không thấy pattern "password=..." → cho qua.
   → Backend decode, ghi đè $_POST để các block sau chạy như cũ.
   Backward-compatible: nếu không có "data", flow cũ chạy bình thường.
   ============================================================ */
$rawData = $_POST['data'] ?? '';
if ($rawData !== '') {
    $decoded = base64_decode($rawData, true);
    $payload = ($decoded !== false) ? json_decode($decoded, true) : null;

    if (!is_array($payload)) {
        jsonError('Dữ liệu gửi lên không hợp lệ!', 422);
    }

    $_POST = array_merge($_POST, [
        'username'   => $payload['username']   ?? '',
        'password'   => $payload['password']   ?? '',
        'csrf_token' => $payload['csrf_token'] ?? '',
    ]);
}

/* ============================================================
   7.1 CSRF check
   ============================================================ */
$token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!validateCsrfToken($token)) {
    jsonError('Phiên làm việc hết hạn hoặc CSRF token không hợp lệ. Vui lòng tải lại trang.', 401);
}

/* ============================================================
   7.2 Đọc input
   ============================================================ */
$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';

/* ============================================================
   7.3 Validate input
   ============================================================ */
if ($username === '' || $password === '') {
    jsonError('Vui lòng nhập đầy đủ tên đăng nhập và mật khẩu!', 422);
}

if (mb_strlen($username) > 100) {
    jsonError('Tên đăng nhập quá dài!', 422);
}

/* ============================================================
   7.4 Kết nối DB
   ============================================================ */
if (!class_exists('Database')) {
    jsonError('Không thể kết nối cơ sở dữ liệu!', 500);
}
$db = Database::getConnection();

/* ============================================================
   7.5 TÌM USER THEO USERNAME HOẶC MÃ NHÂN VIÊN
   ============================================================ */
try {
    $stmt = $db->prepare('
        SELECT u.*,
               nv.ho_ten,
               nv.ma_nv,
               nv.chuc_vu,
               nv.khoa_phong_id,
               kp.ten_khoa
        FROM users u
        LEFT JOIN nhan_vien nv ON u.nhan_vien_id = nv.id
        LEFT JOIN khoa_phong kp ON nv.khoa_phong_id = kp.id
        WHERE u.username = ?
           OR nv.ma_nv = ?
        LIMIT 1
    ');
    $stmt->execute([$username, $username]);
    $user = $stmt->fetch();

} catch (Throwable $e) {
    error_log('[DangNhap] Query error: ' . $e->getMessage());
    jsonError(
        $__API_DEBUG ? 'Lỗi truy vấn: ' . $e->getMessage() : 'Lỗi hệ thống. Vui lòng thử lại!',
        500
    );
}

/* ============================================================
   7.6 KIỂM TRA USER + MẬT KHẨU
   ============================================================ */
if (!$user) {
    jsonError('Tài khoản hoặc mật khẩu không chính xác!', 401);
}

$trangThai = $user['trang_thai'] ?? $user['kich_hoat'] ?? 1;
if ((int)$trangThai === 0) {
    jsonError('Tài khoản đã bị khóa. Vui lòng liên hệ quản trị viên!', 403);
}

$hash = $user['password'] ?? $user['mat_khau'] ?? '';

$passwordOk = false;
if ($hash !== '') {
    if (password_verify($password, $hash)) {
        $passwordOk = true;
    }
    /* Hỗ trợ hash cũ md5/sha1 — nên nâng cấp dần lên password_hash() */
    elseif (strlen($hash) === 32 && hash_equals($hash, md5($password))) {
        $passwordOk = true;
    }
    elseif (strlen($hash) === 40 && hash_equals($hash, sha1($password))) {
        $passwordOk = true;
    }
    /* ĐÃ XÓA nhánh plaintext debug — lỗ hổng bảo mật */
}

if (!$passwordOk) {
    jsonError('Tài khoản hoặc mật khẩu không chính xác!', 401);
}

/* ============================================================
   7.7 ĐĂNG NHẬP THÀNH CÔNG — TẠO SESSION
   ============================================================ */
$vaiTro = $user['vai_tro'] ?? 'nhan_vien';

/* Regenerate session ID TRƯỚC khi gán dữ liệu nhạy cảm (chống session fixation) */
if (function_exists('session_regenerate_id')) {
    @session_regenerate_id(true);
}

$_SESSION['user'] = [
    'id'            => (int)$user['id'],
    'username'      => $user['username'] ?? '',
    'vai_tro'       => $vaiTro,
    'nhan_vien_id'  => $user['nhan_vien_id'] ?? null,
    'ho_ten'        => $user['ho_ten'] ?? $user['username'] ?? 'Người dùng',
    'login_time'    => time(),
    'ip'            => $_SERVER['REMOTE_ADDR'] ?? '',
];

/* Cập nhật lần đăng nhập cuối (nếu có cột) */
try {
    $stmtUp = $db->prepare('UPDATE users SET last_login = NOW() WHERE id = ?');
    $stmtUp->execute([(int)$user['id']]);
} catch (Throwable $e) {
    /* Cột không tồn tại → bỏ qua */
}

/* ============================================================
   7.8 TRẢ RESPONSE
   ============================================================ */
jsonResponse([
    'success' => true,
    'message' => 'Đăng nhập thành công!',

    'user' => [
        'id'            => (int)$user['id'],
        'username'      => $user['username'] ?? '',
        'vai_tro'       => $vaiTro,
        'nhan_vien_id'  => $user['nhan_vien_id'] ?? null,
        'ho_ten'        => $user['ho_ten'] ?? '',
        'ma_nv'         => $user['ma_nv'] ?? null,
        'chuc_vu'       => $user['chuc_vu'] ?? null,
        'ten_khoa'      => $user['ten_khoa'] ?? null,
    ],

    'vai_tro' => $vaiTro,
]);