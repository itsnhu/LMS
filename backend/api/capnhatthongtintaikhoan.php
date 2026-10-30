<?php
/**
 * ============================================================
 *  API: CẬP NHẬT THÔNG TIN CÁ NHÂN
 *  BỆNH VIỆN ĐA KHOA TÂM TRÍ ĐỒNG THÁP
 *  File: backend/api/capnhatthongtintaikhoan.php
 * ------------------------------------------------------------
 *  POST (FormData):
 *   - csrf_token     : CSRF token (bắt buộc)
 *   - ho_ten         : Họ tên (bắt buộc, 2-100 ký tự)
 *   - email          : Email (tùy chọn)
 *   - ngay_sinh      : YYYY-MM-DD (tùy chọn, tuổi 23-65)
 *   - so_dien_thoai  : SĐT (tùy chọn, format VN)
 *
 *  Chỉ cập nhật bảng `nhan_vien` của chính user đang login.
 * ============================================================
 */

/* 1. OUTPUT BUFFERING */
if (!ob_get_level()) { ob_start(); }

/* 2. GLOBAL ERROR HANDLER */
set_exception_handler(function (Throwable $ex) {
    while (ob_get_level()) { ob_end_clean(); }
    $debug = defined('IS_DEV_ENV') && IS_DEV_ENV;
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8', true, 500);
    } else {
        http_response_code(500);
    }
    error_log('[CapNhatTK] Exception: ' . $ex->getMessage() . ' @ ' . $ex->getFile() . ':' . $ex->getLine());
    echo json_encode([
        'success' => false,
        'message' => $debug ? 'Exception: ' . $ex->getMessage() : 'Đã xảy ra lỗi hệ thống.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
});

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        while (ob_get_level()) { ob_end_clean(); }
        $debug = defined('IS_DEV_ENV') && IS_DEV_ENV;
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8', true, 500);
        } else {
            http_response_code(500);
        }
        error_log('[CapNhatTK] Fatal: ' . $err['message'] . ' @ ' . $err['file'] . ':' . $err['line']);
        echo json_encode([
            'success' => false,
            'message' => $debug ? 'Fatal: ' . $err['message'] : 'Lỗi nghiêm trọng từ server!',
        ], JSON_UNESCAPED_UNICODE);
    }
});

set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    if (!(error_reporting() & $errno)) return false;
    error_log('[CapNhatTK] ' . $errstr . ' @ ' . basename($errfile) . ':' . $errline);
    return true;
});

/* 3. LOAD CONFIG TRƯỚC */
$configFile = __DIR__ . '/../config/functions.php';
if (!file_exists($configFile)) {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8', true, 500);
    }
    echo json_encode(['success' => false, 'message' => 'Không tìm thấy file cấu hình.'], JSON_UNESCAPED_UNICODE);
    exit;
}
require_once $configFile;

/* 4. HEADERS */
@header('Content-Type: application/json; charset=utf-8');
@header('X-Content-Type-Options: nosniff');
@header('Cache-Control: no-store, no-cache, must-revalidate');

/* 5. AUTH GUARD */
if (!isLoggedIn()) {
    jsonError('Phiên đăng nhập đã hết hạn. Vui lòng đăng nhập lại!', 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Phương thức không hợp lệ! Chỉ chấp nhận POST.', 405);
}

/* 6. CSRF */
verifyCsrfRequest();

/* 7. LẤY USER + NHÂN VIÊN ID */
$user = getCurrentUser();
$userId = (int)($user['id'] ?? 0);
$nhanVienId = (int)($user['nhan_vien_id'] ?? 0);

if ($userId <= 0) {
    jsonError('Không xác định được người dùng!', 401);
}
if ($nhanVienId <= 0) {
    jsonError('Tài khoản của bạn chưa liên kết hồ sơ nhân viên nên không thể cập nhật!', 403);
}

/* 8. ĐỌC INPUT (FormData) */
$hoTen    = trim((string)($_POST['ho_ten'] ?? ''));
$email    = trim((string)($_POST['email'] ?? ''));
$ngaySinh = trim((string)($_POST['ngay_sinh'] ?? ''));
$sdt      = trim((string)($_POST['so_dien_thoai'] ?? ''));

/* 9. VALIDATE */
$errors = [];

/* 9.1 Họ tên */
if ($hoTen === '') {
    $errors['ho_ten'] = 'Họ và tên không được để trống.';
} elseif (mb_strlen($hoTen) < 2) {
    $errors['ho_ten'] = 'Họ và tên phải có ít nhất 2 ký tự.';
} elseif (mb_strlen($hoTen) > 100) {
    $errors['ho_ten'] = 'Họ và tên quá dài (tối đa 100 ký tự).';
} elseif (!preg_match('/^[\p{L}\s]+$/u', $hoTen)) {
    $errors['ho_ten'] = 'Họ và tên chỉ được chứa chữ cái và khoảng trắng.';
}

/* 9.2 Email (optional) */
if ($email !== '') {
    if (mb_strlen($email) > 100) {
        $errors['email'] = 'Email quá dài (tối đa 100 ký tự).';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Email không đúng định dạng.';
    }
}

/* 9.3 Ngày sinh (optional) */
if ($ngaySinh !== '') {
    $d = DateTime::createFromFormat('Y-m-d', $ngaySinh);
    if (!$d || $d->format('Y-m-d') !== $ngaySinh) {
        $errors['ngay_sinh'] = 'Ngày sinh không đúng định dạng (YYYY-MM-DD).';
    } else {
        $today = new DateTime();
        if ($d > $today) {
            $errors['ngay_sinh'] = 'Ngày sinh không thể ở tương lai.';
        } else {
            $age = $today->diff($d)->y;
            if ($age < 23) {
                $errors['ngay_sinh'] = "Tuổi phải từ 23 trở lên (hiện tại: {$age}).";
            } elseif ($age > 65) {
                $errors['ngay_sinh'] = "Tuổi không vượt quá 65 (hiện tại: {$age}).";
            }
        }
    }
}

/* 9.4 SĐT (optional) */
if ($sdt !== '') {
    $sdtClean = preg_replace('/[\s\.\-]/', '', $sdt);
    if (mb_strlen($sdtClean) > 15) {
        $errors['so_dien_thoai'] = 'Số điện thoại quá dài.';
    } elseif (!preg_match('/^(0|\+84)(3|5|7|8|9)[0-9]{8}$/', $sdtClean)) {
        $errors['so_dien_thoai'] = 'Số điện thoại không hợp lệ.';
    }
}

if (!empty($errors)) {
    jsonError('Vui lòng kiểm tra lại thông tin.', 422, ['errors' => $errors]);
}

/* 10. CHECK SCHEMA — cột nào tồn tại trong bảng nhan_vien */
try {
    $db = Database::getConnection();
    $stmt = $db->prepare("
        SELECT COLUMN_NAME
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'nhan_vien'
    ");
    $stmt->execute();
    $cols = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $cols[$row['COLUMN_NAME']] = true;
    }
} catch (Throwable $e) {
    error_log('[CapNhatTK] schema error: ' . $e->getMessage());
    $cols = [];
}

/* 11. KIỂM TRA NHÂN VIÊN TỒN TẠI */
try {
    $row = Database::fetchOne(
        'SELECT id, ho_ten FROM nhan_vien WHERE id = ? LIMIT 1',
        [$nhanVienId]
    );
} catch (Throwable $e) {
    error_log('[CapNhatTK] DB error: ' . $e->getMessage());
    jsonError('Lỗi kết nối cơ sở dữ liệu!', 500);
}

if (!$row) {
    jsonError('Không tìm thấy hồ sơ nhân viên!', 404);
}

/* 12. BUILD DATA UPDATE — chỉ cột tồn tại */
$updateData = [];

if (isset($cols['ho_ten']))         $updateData['ho_ten'] = $hoTen;
if (isset($cols['email']))          $updateData['email'] = $email !== '' ? $email : null;
if (isset($cols['ngay_sinh']))      $updateData['ngay_sinh'] = $ngaySinh !== '' ? $ngaySinh : null;
if (isset($cols['so_dien_thoai']))  $updateData['so_dien_thoai'] = $sdt !== '' ? $sdt : null;

if (empty($updateData)) {
    jsonError('Không có trường nào có thể cập nhật!', 400);
}

/* 13. UPDATE */
try {
    Database::update('nhan_vien', $updateData, 'id = :id', ['id' => $nhanVienId]);
} catch (PDOException $ex) {
    error_log('[CapNhatTK] SQL error: ' . $ex->getMessage());
    $debug = defined('IS_DEV_ENV') && IS_DEV_ENV;
    jsonError(
        $debug ? 'SQL Error: ' . $ex->getMessage() : 'Không thể cập nhật thông tin. Vui lòng thử lại!',
        500
    );
} catch (Throwable $ex) {
    error_log('[CapNhatTK] Error: ' . $ex->getMessage());
    jsonError('Đã xảy ra lỗi. Vui lòng thử lại!', 500);
}

/* 14. CẬP NHẬT SESSION (nếu có field ho_ten) */
if (isset($updateData['ho_ten']) && isset($_SESSION['user'])) {
    $_SESSION['user']['ho_ten'] = $updateData['ho_ten'];
}

/* 15. TRẢ RESPONSE */
jsonSuccess([
    'nhan_vien_id' => $nhanVienId,
    'ho_ten'       => $updateData['ho_ten'] ?? $row['ho_ten'],
], 'Cập nhật thông tin thành công!');