<?php
/**
 * ============================================================
 *  API: Ý KIẾN LÃNH ĐẠO / BAN GIÁM ĐỐC
 *  Endpoint: backend/api/lanhdaoduyetdonnghiphep.php
 *  Method: GET (danh sách) | POST (phê duyệt / từ chối)
 * ============================================================
 *  ⭐ FIX (2024-11) — SIGNATURE CLEAN:
 *   - XÓA HOÀN TOÀN: embed_name_in_sig_ld + mọi logic nhúng tên vào ảnh
 *   - Ảnh chữ ký = PNG/JPEG/WEBP thuần, KHÔNG vẽ tên lên ảnh
 *   - ho_ten_nguoi_ky_lanh_dao = CHỈ LẤY TỪ POST, KHÔNG fallback account
 *   - Bắt buộc có họ tên khi approve (validate 422)
 *   - Detect cột optional: ho_ten_nguoi_ky_lanh_dao
 *
 *  ⭐ Schema nghi_phep (cột lãnh đạo):
 *   - Ý kiến lãnh đạo  → `phe_duyet`
 *   - Chữ ký lãnh đạo  → `chu_ky_lanh_dao`
 *   - Người phê duyệt  → `nguoi_phe_duyet`
 *   - Ngày phê duyệt   → `ngay_phe_duyet`
 *   - Lý do từ chối    → `ly_do_tu_choi`
 *   - Tên người ký (optional) → `ho_ten_nguoi_ky_lanh_dao`
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
    error_log('[LanhDao] Exception: ' . $ex->getMessage()
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
        error_log('[LanhDao] Fatal: ' . $err['message']
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
    error_log('[LanhDao] ' . $errstr . ' @ ' . basename($errfile) . ':' . $errline);
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

/* 3b. FALLBACK jsonError / jsonSuccess */
if (!function_exists('jsonError')) {
    function jsonError($message, $code = 400, $extra = []) {
        while (ob_get_level() > 0) { ob_end_clean(); }
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8', true, $code);
            header('X-Content-Type-Options: nosniff');
        } else {
            http_response_code($code);
        }
        echo json_encode(array_merge([
            'success' => false,
            'message' => $message,
        ], is_array($extra) ? $extra : []), JSON_UNESCAPED_UNICODE);
        exit;
    }
}
if (!function_exists('jsonSuccess')) {
    function jsonSuccess($data = [], $message = 'Thành công') {
        while (ob_get_level() > 0) { ob_end_clean(); }
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8', true, 200);
            header('X-Content-Type-Options: nosniff');
        }
        $payload = [
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ];
        if (is_array($data) && isset($data['success'])) {
            $payload = $data;
            if (!isset($payload['message'])) $payload['message'] = $message;
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

/* 4. HEADERS */
@header('Content-Type: application/json; charset=utf-8');
@header('X-Content-Type-Options: nosniff');
@header('Cache-Control: no-store, no-cache, must-revalidate');

/* 5. FALLBACK CONSTANTS */
if (!defined('CURRENT_YEAR')) define('CURRENT_YEAR', (int)date('Y'));

/* 6. AUTH GUARD */
if (!function_exists('isLoggedIn')) {
    jsonError('Hàm isLoggedIn chưa được định nghĩa.', 500);
}
if (!isLoggedIn()) {
    jsonError('Phiên đăng nhập đã hết hạn. Vui lòng đăng nhập lại!', 401, ['code' => 'unauthorized']);
}

$currentUser   = getCurrentUser();
$currentEmp    = getCurrentEmployee();
$userRole      = $currentUser['vai_tro'] ?? '';
$currentUserId = (int)($currentUser['id'] ?? 0);
$currentEmpId  = (int)($currentUser['nhan_vien_id'] ?? 0);
$db            = Database::getConnection();

/* 7. ROLE GUARD — LÃNH ĐẠO / BAN GIÁM ĐỐC */
$allowedRoles = ['lanh_dao', 'giam_doc', 'giamdoc', 'ban_lanh_dao', 'admin'];
if (!in_array($userRole, $allowedRoles, true)) {
    jsonError('Bạn không có quyền truy cập chức năng này!', 403, ['code' => 'forbidden']);
}

/* ============================================================
   8. HELPERS
   ============================================================ */
if (!function_exists('getTableColumnsLD')) {
    function getTableColumnsLD(PDO $db, string $table): array {
        $cols = [];
        try {
            $stmt = $db->query("SHOW COLUMNS FROM `$table`");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $cols[$c['Field']] = true;
            }
        } catch (Throwable $e) {
            error_log("[LanhDao] getTableColumns($table): " . $e->getMessage());
        }
        return $cols;
    }
}

if (!function_exists('saveSignatureFromBase64LD')) {
    function saveSignatureFromBase64LD(string $base64Data, string $prefix, int $userId): ?string
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
        if (!is_dir($sigDir)) { @mkdir($sigDir, 0775, true); }
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

if (!function_exists('saveSignatureFromUploadLD')) {
    function saveSignatureFromUploadLD(array $file, string $prefix, int $userId): ?string
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

        $allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];

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
        if (!is_dir($sigDir)) { @mkdir($sigDir, 0775, true); }
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
   9. XỬ LÝ POST — PHÊ DUYỆT / TỪ CHỐI
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /* 9.1 CSRF check */
    $token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (function_exists('validateCsrfToken') && !validateCsrfToken($token)) {
        jsonError('Phiên làm việc hết hạn hoặc CSRF token không hợp lệ. Vui lòng tải lại trang.',
            419, ['code' => 'csrf_invalid']);
    }

    /* 9.2 Đọc input */
    $donId           = (int)($_POST['don_id'] ?? 0);
    $action          = $_POST['action_approval'] ?? '';
    $yKien           = trim($_POST['y_kien_lanh_dao'] ?? '');
    $signatureMethod = $_POST['signature_method'] ?? 'draw';
    $chuKyBase64     = trim($_POST['chu_ky_base64'] ?? '');

    /* ⭐ HỌ TÊN NGƯỜI KÝ — CHỈ LẤY TỪ INPUT POST, KHÔNG FALLBACK ACCOUNT */
    $hoTenNguoiKy = trim($_POST['ho_ten_nguoi_ky_lanh_dao'] ?? '');

    /* ⭐ ĐÃ LOẠI BỎ HOÀN TOÀN: $embedNameInSig / embed_name_in_sig_ld */

    if ($donId <= 0) jsonError('Mã đơn không hợp lệ!', 400);
    if (!in_array($action, ['approve', 'reject'], true)) jsonError('Hành động không hợp lệ!', 400);
    if (!in_array($signatureMethod, ['draw', 'upload'], true)) $signatureMethod = 'draw';

    /* ⭐ Validate họ tên người ký — bắt buộc khi approve */
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

    /* 9.3 Kiểm tra đơn — KHÔNG filter khoa (lãnh đạo duyệt toàn viện) */
    $stmtCheck = $db->prepare('
        SELECT np.*, nv.ho_ten, nv.khoa_phong_id, nv.id AS nv_id
        FROM nghi_phep np
        JOIN nhan_vien nv ON np.nhan_vien_id = nv.id
        WHERE np.id = ?
    ');
    $stmtCheck->execute([$donId]);
    $don = $stmtCheck->fetch();

    if (!$don) jsonError('Không tìm thấy đơn!', 404, ['code' => 'not_found']);
    if ($don['trang_thai'] !== 'cho_phe_duyet') {
        jsonError('Đơn này không ở trạng thái chờ lãnh đạo phê duyệt.', 409, ['code' => 'invalid_state']);
    }

    try {
        if ($action === 'approve') {

            /* 9.4 Xử lý chữ ký — ẢNH THUẦN, KHÔNG NHÚNG TÊN */
            $chuKyLdPath = $don['chu_ky_lanh_dao'] ?? null;

            if ($signatureMethod === 'draw' && $chuKyBase64 !== '') {
                $chuKyLdPath = saveSignatureFromBase64LD($chuKyBase64, 'lanh_dao_sign', $currentUserId);
            } elseif ($signatureMethod === 'upload'
                && isset($_FILES['file_chu_ky_lanh_dao'])
                && is_array($_FILES['file_chu_ky_lanh_dao'])) {
                $chuKyLdPath = saveSignatureFromUploadLD($_FILES['file_chu_ky_lanh_dao'], 'lanh_dao_sign', $currentUserId);
            }

            $newStatus = 'da_duyet';

            /* 9.5 Detect cột optional — CHỈ còn ho_ten_nguoi_ky_lanh_dao */
            $columns       = getTableColumnsLD($db, 'nghi_phep');
            $hasHoTenKyCol = isset($columns['ho_ten_nguoi_ky_lanh_dao']);
            /* ⭐ ĐÃ LOẠI BỎ HOÀN TOÀN: $hasEmbedCol (embed_name_in_sig_ld) */

            /* 9.6 Build UPDATE */
            $set    = [
                'phe_duyet        = ?',
                'chu_ky_lanh_dao  = ?',
                'nguoi_phe_duyet  = ?',
                'ngay_phe_duyet   = NOW()',
                'trang_thai       = ?',
            ];
            $params = [
                $yKien ?: 'Lãnh đạo đồng ý phê duyệt',
                $chuKyLdPath,
                $currentUserId,
                $newStatus,
            ];

            /* ⭐ Lưu họ tên người ký riêng biệt (KHÔNG nhúng vào ảnh) */
            if ($hasHoTenKyCol) {
                $set[]    = 'ho_ten_nguoi_ky_lanh_dao = ?';
                $params[] = $hoTenNguoiKy;
            }
            /* ⭐ ĐÃ XÓA: embed_name_in_sig_ld = ? */

            $params[] = $donId;

            $sql    = 'UPDATE nghi_phep SET ' . implode(', ', $set) . ' WHERE id = ?';
            $stmtUp = $db->prepare($sql);
            $stmtUp->execute($params);

            /* 9.7 Ghi lịch sử */
            if (function_exists('recordLeaveHistory')) {
                try {
                    recordLeaveHistory(
                        $donId, $currentUserId,
                        'Lãnh đạo phê duyệt' . ($chuKyLdPath ? ' (Kèm chữ ký)' : ''),
                        $don['trang_thai'], $newStatus,
                        $yKien ?: 'Đồng ý phê duyệt đơn nghỉ phép'
                    );
                } catch (Throwable $e) {
                    error_log('[LanhDao] recordLeaveHistory: ' . $e->getMessage());
                }
            }

            /* 9.8 Notify nhân viên */
            if (function_exists('createNotification')) {
                try {
                    $stmtUserNV = $db->prepare('SELECT id FROM users WHERE nhan_vien_id = ?');
                    $stmtUserNV->execute([$don['nv_id']]);
                    $nvUserId = $stmtUserNV->fetchColumn();
                    if ($nvUserId) {
                        createNotification(
                            (int)$nvUserId,
                            'Giấy nghỉ phép đã được phê duyệt',
                            "Lãnh đạo đã phê duyệt đơn {$don['ma_don']} của bạn.",
                            'lichsudonnghiphep.html'
                        );
                    }
                } catch (Throwable $e) {
                    error_log('[LanhDao] Notify NV: ' . $e->getMessage());
                }
            }

            jsonSuccess([
                'id'              => $donId,
                'status'          => $newStatus,
                'has_signature'   => !empty($chuKyLdPath),
                'ho_ten_nguoi_ky' => $hoTenNguoiKy,
            ], 'Đã phê duyệt giấy nghỉ phép thành công!');

        } else { /* reject */

            $newStatus = 'tu_choi';

            $stmtUp = $db->prepare('
                UPDATE nghi_phep
                SET phe_duyet        = ?,
                    nguoi_phe_duyet  = ?,
                    ngay_phe_duyet   = NOW(),
                    trang_thai       = ?,
                    ly_do_tu_choi    = ?
                WHERE id = ?
            ');
            $stmtUp->execute([
                $yKien ?: 'Lãnh đạo không đồng ý',
                $currentUserId,
                $newStatus,
                $yKien,
                $donId,
            ]);

            if (function_exists('recordLeaveHistory')) {
                try {
                    recordLeaveHistory(
                        $donId, $currentUserId,
                        'Lãnh đạo từ chối đơn',
                        $don['trang_thai'], $newStatus,
                        $yKien ?: 'Không chấp thuận nghỉ phép'
                    );
                } catch (Throwable $e) {
                    error_log('[LanhDao] recordLeaveHistory: ' . $e->getMessage());
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
                            "Lãnh đạo không chấp thuận đơn {$don['ma_don']}. Lý do: {$yKien}",
                            'lichsudonnghiphep.html'
                        );
                    }
                } catch (Throwable $e) {
                    error_log('[LanhDao] Notify NV: ' . $e->getMessage());
                }
            }

            jsonSuccess([
                'id'     => $donId,
                'status' => $newStatus,
            ], 'Đã từ chối giấy nghỉ phép thành công!');
        }

    } catch (Throwable $e) {
        $debug = defined('IS_DEV_ENV') && IS_DEV_ENV;
        error_log('[LanhDao] POST error: ' . $e->getMessage());
        jsonError(
            $debug ? 'Lỗi xử lý: ' . $e->getMessage() : 'Không thể xử lý. Vui lòng thử lại!',
            500
        );
    }
}

/* ============================================================
   10. XỬ LÝ GET — DANH SÁCH ĐƠN (TOÀN BỆNH VIỆN)
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

/* ⭐ KHÔNG filter theo khoa — lãnh đạo xem toàn viện */

if ($statusView === 'pending') {
    $where[]  = 'np.trang_thai = ?';
    $params[] = 'cho_phe_duyet';
} else {
    $where[]  = 'np.trang_thai IN (?, ?, ?)';
    $params[] = 'cho_phe_duyet';
    $params[] = 'da_duyet';
    $params[] = 'tu_choi';
}

if ($keyword !== '') {
    $where[]  = '(nv.ho_ten LIKE ? OR nv.ma_nv LIKE ? OR np.ma_don LIKE ?)';
    $params[] = "%$keyword%";
    $params[] = "%$keyword%";
    $params[] = "%$keyword%";
}
if ($statusFilter !== '') { $where[] = 'np.trang_thai = ?'; $params[] = $statusFilter; }
if ($dateFrom !== '')     { $where[] = 'DATE(np.created_at) >= ?'; $params[] = $dateFrom; }
if ($dateTo !== '')       { $where[] = 'DATE(np.created_at) <= ?'; $params[] = $dateTo; }

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

/* Detect cột optional */
$cols       = getTableColumnsLD($db, 'nghi_phep');
$hasYkienTk = isset($cols['y_kien_truong_khoa']);
$hasChuKyTk = isset($cols['chu_ky_truong_khoa']);
$hasHoTenTk = isset($cols['ho_ten_nguoi_ky_truong_khoa']);
$hasHoTenLd = isset($cols['ho_ten_nguoi_ky_lanh_dao']);

$extra = [
    $hasYkienTk ? 'np.y_kien_truong_khoa' : 'NULL AS y_kien_truong_khoa',
    $hasChuKyTk ? 'np.chu_ky_truong_khoa' : 'NULL AS chu_ky_truong_khoa',
    $hasHoTenTk ? 'np.ho_ten_nguoi_ky_truong_khoa' : 'NULL AS ho_ten_nguoi_ky_truong_khoa',

    /* ⭐ Ý kiến lãnh đạo lấy từ cột `phe_duyet` → alias `y_kien_lanh_dao` cho JS */
    'np.phe_duyet AS y_kien_lanh_dao',

    'np.chu_ky_lanh_dao',
    $hasHoTenLd
        ? 'np.ho_ten_nguoi_ky_lanh_dao AS ho_ten_lanh_dao'
        : 'NULL AS ho_ten_lanh_dao',
];
$extraSql = implode(",\n        ", $extra);

$sql = "
    SELECT np.*, nv.ho_ten, nv.ma_nv, nv.chuc_vu, kp.ten_khoa,
           $extraSql
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

        'y_kien_truong_khoa' => $r['y_kien_truong_khoa'] ?? null,
        'chu_ky_truong_khoa' => $r['chu_ky_truong_khoa'] ?? null,
        'ho_ten_truong_khoa' => $r['ho_ten_nguoi_ky_truong_khoa'] ?? null,

        'y_kien_lanh_dao'    => $r['y_kien_lanh_dao'] ?? null,
        'chu_ky_lanh_dao'    => $r['chu_ky_lanh_dao'] ?? null,
        'ho_ten_lanh_dao'    => $r['ho_ten_lanh_dao'] ?? null,
    ];
}, $rows);

/* Badge pending — toàn viện */
$pendingBadgeCount = 0;
try {
    $pendingBadgeCount = (int)$db->query("
        SELECT COUNT(*) FROM nghi_phep
        WHERE trang_thai = 'cho_phe_duyet'
    ")->fetchColumn();
} catch (Throwable $e) {}

/* Badge personal — đơn của chính user đang login */
$pendingPersonalCount = 0;
try {
    $stmtPP = $db->prepare("
        SELECT COUNT(*) FROM nghi_phep
        WHERE nhan_vien_id = ?
          AND trang_thai IN ('cho_truong_khoa','cho_phe_duyet')
    ");
    $stmtPP->execute([$currentEmpId]);
    $pendingPersonalCount = (int)$stmtPP->fetchColumn();
} catch (Throwable $e) {}

/* User info cho FE */
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
    'khoa_phong_id' => $currentEmp['khoa_phong_id'] ?? null,
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
        'is_admin'    => ($userRole === 'admin'),
        'is_lanh_dao' => true,
        'ten_khoa'    => 'Toàn bệnh viện',
    ],
    'badges' => [
        'pending_count'  => $pendingBadgeCount,
        'personal_count' => $pendingPersonalCount,
    ],
]);