<?php
/**
 * ============================================================
 *  API: Ý KIẾN TRƯỞNG KHOA / PHÒNG
 *  Endpoint: backend/api/truongkhoaduyetdonnghiphep.php
 *  Method: GET (danh sách) | POST (duyệt / từ chối / duyệt hủy)
 * ============================================================
 *  ⭐ CHANGELOG:
 *   - Thêm xử lý action_cancel_approval (duyệt/từ chối yêu cầu hủy)
 *   - Tab pending bao gồm cả đơn cho_huy
 *   - Tab all loại trừ cho_truong_khoa VÀ cho_huy
 *   - Badge pending đếm cả cho_truong_khoa + cho_huy
 * ============================================================
 */

/* 1. OUTPUT BUFFERING */
if (!ob_get_level()) { ob_start(); }

/* 2. GLOBAL ERROR HANDLERS */
set_exception_handler(function (Throwable $ex) {
    while (ob_get_level()) { ob_end_clean(); }
    $debug = defined('IS_DEV_ENV') && IS_DEV_ENV;
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8', true, 500);
    } else {
        http_response_code(500);
    }
    error_log('[TruongKhoa] Exception: ' . $ex->getMessage()
        . ' @ ' . $ex->getFile() . ':' . $ex->getLine());
    echo json_encode([
        'success' => false,
        'message' => $debug
            ? 'Exception: ' . $ex->getMessage() . ' @ ' . basename($ex->getFile()) . ':' . $ex->getLine()
            : 'Đã xảy ra lỗi hệ thống!',
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
        error_log('[TruongKhoa] Fatal: ' . $err['message']
            . ' @ ' . $err['file'] . ':' . $err['line']);
        echo json_encode([
            'success' => false,
            'message' => $debug
                ? 'Fatal: ' . $err['message'] . ' @ ' . basename($err['file']) . ':' . $err['line']
                : 'Lỗi nghiêm trọng từ server!',
        ], JSON_UNESCAPED_UNICODE);
    }
});

set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    if (!(error_reporting() & $errno)) return false;
    error_log('[TruongKhoa] ' . $errstr . ' @ ' . basename($errfile) . ':' . $errline);
    return true;
});

/* 3. LOAD CONFIG TRƯỚC */
$configFile = __DIR__ . '/../config/functions.php';
if (!file_exists($configFile)) {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8', true, 500);
    }
    echo json_encode([
        'success' => false,
        'message' => 'Không tìm thấy file cấu hình.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
require_once $configFile;

/* 4. HEADERS */
@header('Content-Type: application/json; charset=utf-8');
@header('X-Content-Type-Options: nosniff');
@header('Cache-Control: no-store, no-cache, must-revalidate');

/* 5. FALLBACK CONSTANTS */
if (!defined('CURRENT_YEAR')) define('CURRENT_YEAR', (int)date('Y'));

/* 6. AUTH GUARD */
if (!isLoggedIn()) {
    jsonError('Phiên đăng nhập đã hết hạn. Vui lòng đăng nhập lại!', 401, ['code' => 'unauthorized']);
}

$currentUser   = getCurrentUser();
$currentEmp    = getCurrentEmployee();
$userRole      = $currentUser['vai_tro'] ?? '';
$currentUserId = (int)($currentUser['id'] ?? 0);
$currentEmpId  = (int)($currentUser['nhan_vien_id'] ?? 0);
$khoaId        = (int)($currentEmp['khoa_phong_id'] ?? 0);
$db            = Database::getConnection();

/* 7. ROLE GUARD */
if (!in_array($userRole, ['truong_khoa', 'truongkhoa', 'admin'], true)) {
    jsonError('Bạn không có quyền truy cập chức năng này!', 403, ['code' => 'forbidden']);
}

/* ============================================================
   8. HELPERS
   ============================================================ */
if (!function_exists('getTableColumnsTK')) {
    function getTableColumnsTK(PDO $db, string $table): array {
        $cols = [];
        try {
            $stmt = $db->query("SHOW COLUMNS FROM `$table`");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $cols[$c['Field']] = true;
            }
        } catch (Throwable $e) {
            error_log("[TruongKhoa] getTableColumns($table): " . $e->getMessage());
        }
        return $cols;
    }
}

if (!function_exists('saveSignatureFromBase64TK')) {
    function saveSignatureFromBase64TK(string $base64Data, string $prefix, int $userId): ?string
    {
        $base64Data = trim($base64Data);
        if ($base64Data === '') return null;

        if (!preg_match('#^data:image/png;base64,#i', $base64Data)) {
            throw new Exception('Chữ ký vẽ phải ở định dạng PNG.');
        }

        $parts   = explode(',', $base64Data, 2);
        $decoded = isset($parts[1]) ? base64_decode($parts[1], true) : false;

        if ($decoded === false || strlen($decoded) === 0) {
            throw new Exception('Dữ liệu chữ ký không hợp lệ.');
        }
        if (strlen($decoded) > 2 * 1024 * 1024) {
            throw new Exception('Chữ ký vẽ quá lớn. Vui lòng vẽ đơn giản hơn.');
        }
        if (substr($decoded, 0, 8) !== "\x89\x50\x4E\x47\x0D\x0A\x1A\x0A") {
            throw new Exception('Dữ liệu chữ ký không phải ảnh PNG hợp lệ.');
        }

        $sigDir = dirname(__DIR__, 2) . '/frontend/uploads/chuky';
        if (!is_dir($sigDir)) {
            @mkdir($sigDir, 0775, true);
        }
        if (!is_dir($sigDir) || !is_writable($sigDir)) {
            throw new Exception('Không thể lưu chữ ký. Vui lòng liên hệ kỹ thuật!');
        }

        $fileName = $prefix . '_' . $userId . '_' . time() . '_'
                  . bin2hex(random_bytes(3)) . '.png';
        $filePath = rtrim($sigDir, '/\\') . DIRECTORY_SEPARATOR . $fileName;

        if (file_put_contents($filePath, $decoded) === false) {
            throw new Exception('Không thể lưu file chữ ký.');
        }

        return 'frontend/uploads/chuky/' . $fileName;
    }
}

if (!function_exists('saveSignatureFromUploadTK')) {
    function saveSignatureFromUploadTK(array $file, string $prefix, int $userId): ?string
    {
        if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('Lỗi upload file (mã: ' . $file['error'] . ').');
        }
        if ($file['size'] > 2 * 1024 * 1024) {
            throw new Exception('File chữ ký quá lớn (tối đa 2MB).');
        }
        if ($file['size'] < 200) {
            throw new Exception('File chữ ký quá nhỏ, không hợp lệ.');
        }

        $allowed = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
        ];

        $mime = '';
        if (class_exists('finfo')) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime  = (string)$finfo->file($file['tmp_name']);
        } elseif (function_exists('mime_content_type')) {
            $mime = (string)mime_content_type($file['tmp_name']);
        } elseif (function_exists('getimagesize')) {
            $info = @getimagesize($file['tmp_name']);
            if ($info && isset($info['mime'])) $mime = $info['mime'];
        }

        if (!isset($allowed[$mime])) {
            throw new Exception('File chữ ký không đúng định dạng ảnh (chỉ JPG/PNG/WEBP).');
        }
        $ext = $allowed[$mime];

        if (function_exists('getimagesize')) {
            $info = @getimagesize($file['tmp_name']);
            if ($info === false || $info[0] < 30 || $info[1] < 30) {
                throw new Exception('File ảnh không hợp lệ hoặc quá nhỏ.');
            }
        }

        $sigDir = dirname(__DIR__, 2) . '/frontend/uploads/chuky';
        if (!is_dir($sigDir)) {
            @mkdir($sigDir, 0775, true);
        }
        if (!is_dir($sigDir) || !is_writable($sigDir)) {
            throw new Exception('Không thể lưu chữ ký. Vui lòng liên hệ kỹ thuật!');
        }

        $fileName = $prefix . '_' . $userId . '_' . time() . '_'
                  . bin2hex(random_bytes(3)) . '.' . $ext;
        $destPath = rtrim($sigDir, '/\\') . DIRECTORY_SEPARATOR . $fileName;

        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            throw new Exception('Không thể lưu file chữ ký tải lên.');
        }

        return 'frontend/uploads/chuky/' . $fileName;
    }
}

/* ============================================================
   9. XỬ LÝ POST
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /* 9.1 CSRF check */
    $token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (function_exists('validateCsrfToken') && !validateCsrfToken($token)) {
        jsonError('Phiên làm việc hết hạn hoặc CSRF token không hợp lệ. Vui lòng tải lại trang.', 419, ['code' => 'csrf_invalid']);
    }

    /* ============================================================
       9A. ⭐ XỬ LÝ DUYỆT HỦY (action_cancel_approval)
       ============================================================ */
    if (!empty($_POST['action_cancel_approval'])) {
        $cancelAction = $_POST['action_cancel_approval'];
        $donIdCancel  = (int)($_POST['don_id'] ?? 0);
        $ghiChuCancel = trim($_POST['ghi_chu'] ?? '');

        if (!in_array($cancelAction, ['approve', 'reject'], true)) {
            jsonError('Hành động không hợp lệ.', 400);
        }
        if ($donIdCancel <= 0) {
            jsonError('ID đơn không hợp lệ.', 400);
        }

        // Lấy đơn + kiểm tra quyền khoa
        $stmtC = $db->prepare('
            SELECT np.*, nv.ho_ten, nv.khoa_phong_id, nv.id AS nv_id
            FROM nghi_phep np
            JOIN nhan_vien nv ON np.nhan_vien_id = nv.id
            WHERE np.id = ?' . ($userRole !== 'admin' ? ' AND nv.khoa_phong_id = ?' : '')
        );
        $paramsC = [$donIdCancel];
        if ($userRole !== 'admin') $paramsC[] = $khoaId;
        $stmtC->execute($paramsC);
        $donC = $stmtC->fetch();

        if (!$donC) {
            jsonError('Không tìm thấy đơn hoặc bạn không có quyền xử lý!', 404, ['code' => 'not_found']);
        }
        if (($donC['trang_thai'] ?? '') !== 'cho_huy') {
            jsonError('Đơn không ở trạng thái "Chờ hủy".', 400);
        }

        try {
            if ($cancelAction === 'approve') {
                $newStatus   = 'da_huy';
                $message     = 'Đã ĐỒNG Ý HỦY đơn nghỉ phép.';
                $historyNote = 'Trưởng khoa đồng ý hủy đơn';
            } else {
                $newStatus   = 'da_duyet';
                $message     = 'Đã TỪ CHỐI yêu cầu hủy. Đơn quay về trạng thái "Đã duyệt".';
                $historyNote = 'Trưởng khoa từ chối yêu cầu hủy';
            }

            // Cập nhật trạng thái
            $columnsC  = getTableColumnsTK($db, 'nghi_phep');
            $hasGhiChu = isset($columnsC['ghi_chu_truong_khoa']);

            if ($hasGhiChu && $ghiChuCancel !== '') {
                $stmtUpC = $db->prepare('
                    UPDATE nghi_phep
                    SET trang_thai = ?, ghi_chu_truong_khoa = ?
                    WHERE id = ?
                ');
                $stmtUpC->execute([$newStatus, $ghiChuCancel, $donIdCancel]);
            } else {
                $stmtUpC = $db->prepare('UPDATE nghi_phep SET trang_thai = ? WHERE id = ?');
                $stmtUpC->execute([$newStatus, $donIdCancel]);
            }

            // Ghi lịch sử
            if (function_exists('recordLeaveHistory')) {
                try {
                    recordLeaveHistory(
                        $donIdCancel, $currentUserId,
                        $historyNote,
                        $donC['trang_thai'], $newStatus,
                        $ghiChuCancel ?: $historyNote
                    );
                } catch (Throwable $e) {
                    error_log('[TruongKhoa] recordLeaveHistory(cancel): ' . $e->getMessage());
                }
            }

            // Gửi thông báo cho nhân viên
            if (function_exists('createNotification')) {
                try {
                    $stmtUserNV = $db->prepare('SELECT id FROM users WHERE nhan_vien_id = ?');
                    $stmtUserNV->execute([$donC['nv_id']]);
                    $nvUserId = $stmtUserNV->fetchColumn();
                    if ($nvUserId) {
                        $tieuDe = $cancelAction === 'approve'
                            ? 'Đơn nghỉ phép đã được hủy'
                            : 'Yêu cầu hủy đơn bị từ chối';
                        $noiDung = $cancelAction === 'approve'
                            ? "Trưởng khoa đã ĐỒNG Ý HỦY đơn {$donC['ma_don']} của bạn."
                            : "Trưởng khoa đã TỪ CHỐI yêu cầu hủy đơn {$donC['ma_don']}. Đơn quay về trạng thái Đã duyệt.";
                        createNotification(
                            (int)$nvUserId,
                            $tieuDe,
                            $noiDung,
                            'lichsudonnghiphep.html'
                        );
                    }
                } catch (Throwable $e) {
                    error_log('[TruongKhoa] Notify(cancel): ' . $e->getMessage());
                }
            }

            jsonSuccess([
                'id'         => $donIdCancel,
                'trang_thai' => $newStatus,
            ], $message);

        } catch (Throwable $e) {
            $debug = defined('IS_DEV_ENV') && IS_DEV_ENV;
            error_log('[TruongKhoa] cancel approval error: ' . $e->getMessage());
            jsonError(
                $debug ? 'Lỗi xử lý: ' . $e->getMessage() : 'Không thể xử lý. Vui lòng thử lại!',
                500
            );
        }
        exit;
    }

    /* ============================================================
       9B. XỬ LÝ DUYỆT / TỪ CHỐI THÔNG THƯỜNG
       ============================================================ */
    $donId           = (int)($_POST['don_id'] ?? 0);
    $action          = $_POST['action_approval'] ?? '';
    $yKien           = trim($_POST['y_kien_truong_khoa'] ?? '');
    $signatureMethod = $_POST['signature_method'] ?? 'draw';
    $chuKyBase64     = trim($_POST['chu_ky_base64'] ?? '');
    $hoTenNguoiKy    = trim($_POST['ho_ten_nguoi_ky_truong_khoa'] ?? '');

    if ($donId <= 0) jsonError('Mã đơn không hợp lệ!', 400);
    if (!in_array($action, ['approve', 'reject'], true)) jsonError('Hành động không hợp lệ!', 400);
    if (!in_array($signatureMethod, ['draw', 'upload'], true)) $signatureMethod = 'draw';

    if ($action === 'approve') {
        if ($hoTenNguoiKy === '') {
            jsonError('Vui lòng nhập họ tên người ký.', 422);
        }
        if (mb_strlen($hoTenNguoiKy) > 50) {
            jsonError('Họ tên người ký quá dài (tối đa 50 ký tự).', 422);
        }
        if (!preg_match('/^[\p{L}\s]+$/u', $hoTenNguoiKy)) {
            jsonError('Họ tên người ký chỉ được chứa chữ cái và khoảng trắng.', 422);
        }
    }

    $stmtCheck = $db->prepare('
        SELECT np.*, nv.ho_ten, nv.khoa_phong_id, nv.id AS nv_id
        FROM nghi_phep np
        JOIN nhan_vien nv ON np.nhan_vien_id = nv.id
        WHERE np.id = ?' . ($userRole !== 'admin' ? ' AND nv.khoa_phong_id = ?' : '')
    );
    $paramsCheck = [$donId];
    if ($userRole !== 'admin') $paramsCheck[] = $khoaId;
    $stmtCheck->execute($paramsCheck);
    $don = $stmtCheck->fetch();

    if (!$don) {
        jsonError('Không tìm thấy đơn hoặc bạn không có quyền xử lý đơn này!', 404, ['code' => 'not_found']);
    }

    try {
        if ($action === 'approve') {

            $chuKyTkPath = $don['chu_ky_truong_khoa'] ?? null;

            if ($signatureMethod === 'draw' && $chuKyBase64 !== '') {
                $chuKyTkPath = saveSignatureFromBase64TK($chuKyBase64, 'truong_khoa_sign', $currentUserId);
            } elseif ($signatureMethod === 'upload'
                && isset($_FILES['file_chu_ky_truong_khoa'])
                && is_array($_FILES['file_chu_ky_truong_khoa'])) {
                $chuKyTkPath = saveSignatureFromUploadTK($_FILES['file_chu_ky_truong_khoa'], 'truong_khoa_sign', $currentUserId);
            }

            $newStatus = 'cho_phe_duyet';

            $columns = getTableColumnsTK($db, 'nghi_phep');
            $hasHoTenKyTKCol = isset($columns['ho_ten_nguoi_ky_truong_khoa']);

            if ($hasHoTenKyTKCol) {
                $stmtUp = $db->prepare('
                    UPDATE nghi_phep
                    SET y_kien_truong_khoa           = ?,
                        chu_ky_truong_khoa           = ?,
                        ho_ten_nguoi_ky_truong_khoa  = ?,
                        nguoi_cho_y_kien             = ?,
                        ngay_cho_y_kien              = NOW(),
                        trang_thai                   = ?
                    WHERE id = ?
                ');
                $stmtUp->execute([
                    $yKien ?: 'Đồng ý cho nghỉ theo nguyện vọng',
                    $chuKyTkPath,
                    ($hoTenNguoiKy !== '' ? $hoTenNguoiKy : null),
                    $currentUserId,
                    $newStatus,
                    $donId,
                ]);
            } else {
                $stmtUp = $db->prepare('
                    UPDATE nghi_phep
                    SET y_kien_truong_khoa = ?,
                        chu_ky_truong_khoa = ?,
                        nguoi_cho_y_kien   = ?,
                        ngay_cho_y_kien    = NOW(),
                        trang_thai         = ?
                    WHERE id = ?
                ');
                $stmtUp->execute([
                    $yKien ?: 'Đồng ý cho nghỉ theo nguyện vọng',
                    $chuKyTkPath,
                    $currentUserId,
                    $newStatus,
                    $donId,
                ]);
            }

            if (function_exists('recordLeaveHistory')) {
                try {
                    recordLeaveHistory(
                        $donId, $currentUserId,
                        'Trưởng khoa đồng ý & chuyển Lãnh đạo' . ($chuKyTkPath ? ' (Kèm chữ ký)' : ''),
                        $don['trang_thai'], $newStatus,
                        $yKien ?: 'Đồng ý cho nghỉ theo nguyện vọng'
                    );
                } catch (Throwable $e) {
                    error_log('[TruongKhoa] recordLeaveHistory: ' . $e->getMessage());
                }
            }

            if (function_exists('createNotification')) {
                try {
                    $stmtLD = $db->query('
                        SELECT id FROM users
                        WHERE vai_tro IN ("lanh_dao","giam_doc","ban_lanh_dao","giamdoc")
                    ');
                    while ($ld = $stmtLD->fetch()) {
                        createNotification(
                            (int)$ld['id'],
                            'Có giấy nghỉ phép mới cần phê duyệt',
                            "Trưởng khoa đã cho ý kiến đơn của {$don['ho_ten']} ({$don['ma_don']})",
                            'lanhdaoduyetdonnghiphep.html'
                        );
                    }
                } catch (Throwable $e) {
                    error_log('[TruongKhoa] Notify LD: ' . $e->getMessage());
                }
            }

            jsonSuccess([
                'id'             => $donId,
                'status'         => $newStatus,
                'has_signature'  => !empty($chuKyTkPath),
                'ho_ten_nguoi_ky'=> $hoTenNguoiKy,
            ], 'Đã ghi nhận ý kiến và chuyển giấy nghỉ phép lên Lãnh đạo phê duyệt!');

        } else { /* reject */

            $newStatus = 'tu_choi';

            $stmtUp = $db->prepare('
                UPDATE nghi_phep
                SET y_kien_truong_khoa = ?,
                    nguoi_cho_y_kien   = ?,
                    ngay_cho_y_kien    = NOW(),
                    trang_thai         = ?,
                    ly_do_tu_choi      = ?
                WHERE id = ?
            ');
            $stmtUp->execute([
                $yKien ?: 'Trưởng khoa không đồng ý',
                $currentUserId,
                $newStatus,
                $yKien,
                $donId,
            ]);

            if (function_exists('recordLeaveHistory')) {
                try {
                    recordLeaveHistory(
                        $donId, $currentUserId,
                        'Trưởng khoa từ chối đơn',
                        $don['trang_thai'], $newStatus,
                        $yKien ?: 'Không chấp thuận nghỉ phép'
                    );
                } catch (Throwable $e) {
                    error_log('[TruongKhoa] recordLeaveHistory: ' . $e->getMessage());
                }
            }

            if (function_exists('createNotification')) {
                try {
                    $stmtUserNV = $db->prepare('SELECT id FROM users WHERE nhan_vien_id = ?');
                    $stmtUserNV->execute([$don['nv_id']]);
                    $nvUserId = $stmtUserNV->fetchColumn();
                    if ($nvUserId) {
                        createNotification(
                            (int)$nvUserId,
                            'Giấy nghỉ phép của bạn bị từ chối',
                            "Trưởng khoa không chấp thuận đơn {$don['ma_don']}. Lý do: {$yKien}",
                            'lichsudonnghiphep.html'
                        );
                    }
                } catch (Throwable $e) {
                    error_log('[TruongKhoa] Notify NV: ' . $e->getMessage());
                }
            }

            jsonSuccess([
                'id'     => $donId,
                'status' => $newStatus,
            ], 'Đã từ chối giấy nghỉ phép thành công!');
        }

    } catch (Throwable $e) {
        $debug = defined('IS_DEV_ENV') && IS_DEV_ENV;
        error_log('[TruongKhoa] POST error: ' . $e->getMessage());
        jsonError(
            $debug ? 'Lỗi xử lý: ' . $e->getMessage() : 'Không thể xử lý. Vui lòng thử lại!',
            500
        );
    }
}

/* ============================================================
   10. XỬ LÝ GET — DANH SÁCH ĐƠN
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonError('Method không được hỗ trợ!', 405, ['code' => 'method_not_allowed']);
}

$statusView   = $_GET['tab'] ?? 'pending';
$keyword      = trim($_GET['keyword'] ?? '');
$statusFilter = trim($_GET['status_filter'] ?? '');
$dateFrom     = trim($_GET['date_from'] ?? '');
$dateTo       = trim($_GET['date_to'] ?? '');
$currentPage  = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 10;

if (!in_array($statusView, ['pending', 'all'], true)) $statusView = 'pending';

$where  = [];
$params = [];

if ($userRole !== 'admin') {
    $where[]  = 'nv.khoa_phong_id = ?';
    $params[] = $khoaId;
}

/* ⭐ Tab pending: bao gồm cả cho_truong_khoa VÀ cho_huy */
if ($statusView === 'pending') {
    $where[] = "(np.trang_thai = 'cho_truong_khoa' OR np.trang_thai = 'cho_huy')";
} else {
    /* ⭐ Tab all: loại trừ cả cho_truong_khoa VÀ cho_huy */
    $where[]  = 'np.trang_thai NOT IN (?, ?)';
    $params[] = 'cho_truong_khoa';
    $params[] = 'cho_huy';
}

if ($keyword !== '') {
    $where[]  = '(nv.ho_ten LIKE ? OR nv.ma_nv LIKE ? OR np.ma_don LIKE ?)';
    $params[] = "%$keyword%";
    $params[] = "%$keyword%";
    $params[] = "%$keyword%";
}

if ($statusFilter !== '') {
    $where[]  = 'np.trang_thai = ?';
    $params[] = $statusFilter;
}

if ($dateFrom !== '') {
    $where[]  = 'DATE(np.created_at) >= ?';
    $params[] = $dateFrom;
}

if ($dateTo !== '') {
    $where[]  = 'DATE(np.created_at) <= ?';
    $params[] = $dateTo;
}

$whereSql = !empty($where) ? ' WHERE ' . implode(' AND ', $where) : '';

/* Đếm */
$stmtCount = $db->prepare("
    SELECT COUNT(*)
    FROM nghi_phep np
    JOIN nhan_vien nv ON np.nhan_vien_id = nv.id
    $whereSql
");
$stmtCount->execute($params);
$totalRows = (int)$stmtCount->fetchColumn();

$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($currentPage > $totalPages) $currentPage = $totalPages;
$offset = ($currentPage - 1) * $perPage;

/* Lấy dữ liệu */
$sql = "
    SELECT np.*, nv.ho_ten, nv.ma_nv, nv.chuc_vu, kp.ten_khoa
    FROM nghi_phep np
    JOIN nhan_vien nv ON np.nhan_vien_id = nv.id
    LEFT JOIN khoa_phong kp ON nv.khoa_phong_id = kp.id
    $whereSql
    ORDER BY np.created_at DESC
    LIMIT $perPage OFFSET $offset
";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$list = array_map(function ($r) {
    return [
        'id'           => (int)$r['id'],
        'ma_don'       => $r['ma_don'] ?? '',
        'nhan_vien_id' => (int)$r['nhan_vien_id'],
        'ho_ten'       => $r['ho_ten'] ?? '',
        'ma_nv'        => $r['ma_nv'] ?? '',
        'chuc_vu'      => $r['chuc_vu'] ?? '',
        'ten_khoa'     => $r['ten_khoa'] ?? '',
        'tu_ngay'      => $r['tu_ngay'] ?? null,
        'den_ngay'     => $r['den_ngay'] ?? null,
        'buoi_nghi'    => $r['buoi_nghi'] ?? 'ca_ngay',
        'so_ngay'      => (float)($r['so_ngay'] ?? 0),
        'ly_do'        => $r['ly_do'] ?? '',
        'trang_thai'   => $r['trang_thai'] ?? '',
        'created_at'   => $r['created_at'] ?? null,
    ];
}, $rows);

/* ⭐ Badge — đếm cả cho_truong_khoa + cho_huy */
$pendingBadgeCount = 0;
try {
    $stmtP = $db->prepare("
        SELECT COUNT(*) FROM nghi_phep np
        JOIN nhan_vien nv ON np.nhan_vien_id = nv.id
        WHERE np.trang_thai IN ('cho_truong_khoa', 'cho_huy')" .
        ($userRole !== 'admin' ? ' AND nv.khoa_phong_id = ?' : '')
    );
    $paramsP = [];
    if ($userRole !== 'admin') $paramsP[] = $khoaId;
    $stmtP->execute($paramsP);
    $pendingBadgeCount = (int)$stmtP->fetchColumn();
} catch (Throwable $e) {}

$pendingPersonalCount = 0;
try {
    $stmtPP = $db->prepare("
        SELECT COUNT(*) FROM nghi_phep
        WHERE nhan_vien_id = ?
          AND trang_thai IN ('cho_duyet','cho_truong_khoa','cho_nhan_su')
    ");
    $stmtPP->execute([$currentEmpId]);
    $pendingPersonalCount = (int)$stmtPP->fetchColumn();
} catch (Throwable $e) {}

/* User info */
$userForFe = [
    'id'            => $currentUserId,
    'username'      => $currentUser['username'] ?? '',
    'vai_tro'       => $userRole,
    'nhan_vien_id'  => $currentEmpId ?: null,
    'ho_ten'        => $currentEmp['ho_ten'] ?? $currentUser['username'] ?? 'Người dùng',
    'ma_nv'         => $currentEmp['ma_nv'] ?? null,
    'email'         => $currentEmp['email'] ?? null,
    'so_dien_thoai' => $currentEmp['so_dien_thoai'] ?? null,
    'ngay_sinh'     => $currentEmp['ngay_sinh'] ?? null,
    'chuc_vu'       => $currentEmp['chuc_vu'] ?? null,
    'ten_khoa'      => $currentEmp['ten_khoa'] ?? null,
    'khoa_phong_id' => $khoaId ?: null,
];

/* ============================================================
   11. RESPONSE
   ============================================================ */
jsonSuccess([
    'user'  => $userForFe,
    'list'  => $list,
    'pagination' => [
        'page'        => $currentPage,
        'per_page'    => $perPage,
        'total_rows'  => $totalRows,
        'total_pages' => $totalPages,
        'offset'      => $offset,
    ],
    'filters' => [
        'tab'           => $statusView,
        'keyword'       => $keyword,
        'status_filter' => $statusFilter,
        'date_from'     => $dateFrom,
        'date_to'       => $dateTo,
    ],
    'scope' => [
        'is_admin' => ($userRole === 'admin'),
        'ten_khoa' => $currentEmp['ten_khoa'] ?? 'Toàn bệnh viện',
    ],
    'badges' => [
        'pending_count'  => $pendingBadgeCount,
        'personal_count' => $pendingPersonalCount,
    ],
]);