<?php
/**
 * ============================================================
 *  API TIẾP NHẬN GIẤY NGHỈ PHÉP (PHÒNG NHÂN SỰ)
 *  Endpoint: backend/api/tiepnhangiaynghiphep.php
 * ------------------------------------------------------------
 *  ⭐ FIX (2024-11): 
 *   - Sửa lỗi Routing: Đọc được `action` từ JSON Payload (dùng cho Bulk Receive)
 *   - Đảm bảo an toàn dữ liệu: Rollback nếu trừ phép thất bại
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
    error_log('[TiepNhan] Exception: ' . $ex->getMessage()
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
        error_log('[TiepNhan] Fatal: ' . $err['message']
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
    error_log('[TiepNhan] ' . $errstr . ' @ ' . basename($errfile) . ':' . $errline);
    return true;
});

/* 3. LOAD CONFIG */
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

/* ============================================================
   5. FALLBACK FUNCTIONS
   ============================================================ */
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

if (!function_exists('formatDateVN')) {
    function formatDateVN(?string $s): string {
        if (empty($s) || $s === '0000-00-00' || $s === '0000-00-00 00:00:00') return '-';
        $t = strtotime($s);
        return $t ? date('d/m/Y', $t) : '-';
    }
}

if (!function_exists('getTableColumnsTN')) {
    function getTableColumnsTN(string $table): array {
        $cols = [];
        try {
            $db = Database::getConnection();
            $stmt = $db->query("SHOW COLUMNS FROM `$table`");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $cols[$c['Field']] = true;
            }
        } catch (Throwable $e) {
            error_log("[TiepNhan] getTableColumnsTN($table): " . $e->getMessage());
        }
        return $cols;
    }
}

if (!function_exists('normalizeTiepNhanRow')) {
    function normalizeTiepNhanRow(array $row): array {
        return [
            'id'                       => (int)($row['id'] ?? 0),
            'ma_don'                   => $row['ma_don'] ?? '',
            'nhan_vien_id'             => (int)($row['nhan_vien_id'] ?? 0),
            'ho_ten'                   => $row['ho_ten'] ?? '',
            'ma_nv'                    => $row['ma_nv'] ?? '',
            'chuc_vu'                  => $row['chuc_vu'] ?? '',
            'ten_khoa'                 => $row['ten_khoa'] ?? '',
            'tu_ngay'                  => $row['tu_ngay'] ?? null,
            'den_ngay'                 => $row['den_ngay'] ?? null,
            'buoi_nghi'                => $row['buoi_nghi'] ?? 'ca_ngay',
            'buoi_nghi_ket_thuc'       => $row['buoi_nghi_ket_thuc'] ?? null,
            'so_ngay'                  => (float)($row['so_ngay'] ?? 0),
            'so_phep_con_lai_luc_nghi' => isset($row['so_phep_con_lai_luc_nghi']) && $row['so_phep_con_lai_luc_nghi'] !== null
                                            ? (float)$row['so_phep_con_lai_luc_nghi'] : null,
            'ngay_phe_duyet'           => $row['ngay_phe_duyet']    ?? null,
            'ngay_nop_nhan_su'         => $row['ngay_nop_nhan_su']  ?? null,
            'ngay_tiep_nhan'           => $row['ngay_tiep_nhan']    ?? null,
            'nhan_su_ten'              => $row['nhan_su_ten']       ?? null,
            'nhan_su_user'             => $row['nhan_su_user']      ?? null,
            'trang_thai'               => $row['trang_thai'] ?? '',
            'created_at'               => $row['created_at'] ?? null,
            'updated_at'               => $row['updated_at'] ?? null,
        ];
    }
}

/* ============================================================
   6. ⭐ ROUTING (ĐÃ FIX LỖI JSON PAYLOAD)
   ============================================================ */
// Đọc JSON Payload 1 lần duy nhất ở đầu file
$rawInput  = file_get_contents('php://input');
$jsonInput = json_decode($rawInput, true) ?: [];

$action = $_GET['action'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = $_POST['action']; // Dành cho Form-Data
    } elseif (isset($jsonInput['action'])) {
        $action = $jsonInput['action']; // Dành cho JSON Payload
    }
}

if (!$action) { $action = 'list'; }

switch ($action) {
    case 'list':
        handleList();
        break;

    case 'receive':
        handleReceive();
        break;

    case 'receive_bulk':
        handleReceiveBulk($jsonInput); // ⭐ Truyền dữ liệu JSON vào hàm
        break;

    default:
        jsonError('Hành động không hợp lệ!', 400);
}


/* ============================================================
 * ACTION: LIST — Danh sách đơn chờ / đã tiếp nhận
 * ============================================================ */
function handleList(): void
{
    if (!isLoggedIn()) {
        jsonError('Vui lòng đăng nhập!', 401);
    }

    $user = getCurrentUser();
    $emp  = getCurrentEmployee();
    $role = (string)($user['vai_tro'] ?? '');

    if (!in_array($role, ['nhan_su', 'nhansu', 'admin'], true)) {
        jsonError('Bạn không có quyền truy cập chức năng này!', 403);
    }

    $db = Database::getConnection();

    $tab     = $_GET['tab'] ?? 'pending';
    $keyword = trim($_GET['keyword'] ?? '');
    $page    = max(1, (int)($_GET['page'] ?? 1));
    $perPage = 10;

    if (!in_array($tab, ['pending', 'received'], true)) {
        $tab = 'pending';
    }

    $stDaDuyet    = defined('STATUS_DA_DUYET')     ? STATUS_DA_DUYET     : 'da_duyet';
    $stDaTiepNhan = defined('STATUS_DA_TIEP_NHAN') ? STATUS_DA_TIEP_NHAN : 'da_tiep_nhan';

    $where  = [];
    $params = [];

    $where[]  = 'np.trang_thai = ?';
    $params[] = ($tab === 'pending') ? $stDaDuyet : $stDaTiepNhan;

    if ($keyword !== '') {
        $where[]  = '(nv.ho_ten LIKE ? OR nv.ma_nv LIKE ? OR np.ma_don LIKE ?)';
        $params[] = "%$keyword%";
        $params[] = "%$keyword%";
        $params[] = "%$keyword%";
    }

    $whereSql = ' WHERE ' . implode(' AND ', $where);

    $totalRows = 0;
    try {
        $stmtCount = $db->prepare("
            SELECT COUNT(*)
            FROM nghi_phep np
            JOIN nhan_vien nv ON np.nhan_vien_id = nv.id
            $whereSql
        ");
        $stmtCount->execute($params);
        $totalRows = (int)$stmtCount->fetchColumn();
    } catch (Throwable $e) {
        error_log('[TiepNhan] Count error: ' . $e->getMessage());
        jsonError('Không thể đếm dữ liệu: ' . $e->getMessage(), 500);
    }

    $totalPages = max(1, (int)ceil($totalRows / $perPage));
    if ($page > $totalPages) $page = $totalPages;
    $offset = ($page - 1) * $perPage;

    $npCols               = getTableColumnsTN('nghi_phep');
    $hasNguoiTiepNhan     = isset($npCols['nguoi_tiep_nhan']);
    $hasNgayNopNhanSu     = isset($npCols['ngay_nop_nhan_su']);
    $hasNgayTiepNhan      = isset($npCols['ngay_tiep_nhan']);
    $hasUpdatedAt         = isset($npCols['updated_at']);

    $extraCols = [];
    if (!$hasNguoiTiepNhan) $extraCols[] = 'NULL AS nguoi_tiep_nhan';
    if (!$hasNgayNopNhanSu) $extraCols[] = 'NULL AS ngay_nop_nhan_su';
    if (!$hasNgayTiepNhan)  $extraCols[] = 'NULL AS ngay_tiep_nhan';
    $extraSql = !empty($extraCols) ? ', ' . implode(', ', $extraCols) : '';

    $orderCol = $hasUpdatedAt ? 'COALESCE(np.updated_at, np.created_at)' : 'np.created_at';

    $rows = [];
    try {
        $sql = "
            SELECT np.*,
                   nv.ho_ten, nv.ma_nv, nv.chuc_vu,
                   kp.ten_khoa,
                   u_ns.username AS nhan_su_user,
                   nv_ns.ho_ten AS nhan_su_ten
                   $extraSql
            FROM nghi_phep np
            JOIN nhan_vien nv ON np.nhan_vien_id = nv.id
            LEFT JOIN khoa_phong kp ON nv.khoa_phong_id = kp.id
            " . ($hasNguoiTiepNhan ? "LEFT JOIN users u_ns ON np.nguoi_tiep_nhan = u_ns.id" : "LEFT JOIN users u_ns ON 1=0") . "
            LEFT JOIN nhan_vien nv_ns ON u_ns.nhan_vien_id = nv_ns.id
            $whereSql
            ORDER BY $orderCol DESC
            LIMIT $perPage OFFSET $offset
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('[TiepNhan] Fetch error: ' . $e->getMessage());
        jsonError('Không thể tải danh sách: ' . $e->getMessage(), 500);
    }

    $pendingBadgeCount = 0;
    try {
        $stmtP = $db->prepare('SELECT COUNT(*) FROM nghi_phep WHERE trang_thai = ?');
        $stmtP->execute([$stDaDuyet]);
        $pendingBadgeCount = (int)$stmtP->fetchColumn();
    } catch (Throwable $e) {
        error_log('[TiepNhan] Badge error: ' . $e->getMessage());
    }

    jsonSuccess([
        'tab'  => $tab,
        'user' => [
            'id'            => (int)($user['id'] ?? 0),
            'username'      => $user['username'] ?? null,
            'vai_tro'       => $role,
            'nhan_vien_id'  => (int)($user['nhan_vien_id'] ?? 0) ?: null,
            'ho_ten'        => $emp['ho_ten']         ?? $user['username'] ?? 'Người dùng',
            'ma_nv'         => $emp['ma_nv']          ?? null,
            'email'         => $emp['email']          ?? null,
            'so_dien_thoai' => $emp['so_dien_thoai']  ?? null,
            'ngay_sinh'     => $emp['ngay_sinh']      ?? null,
            'chuc_vu'       => $emp['chuc_vu']        ?? null,
            'ten_khoa'      => $emp['ten_khoa']       ?? null,
        ],
        'list' => array_map('normalizeTiepNhanRow', $rows),
        'pagination' => [
            'page'        => $page,
            'per_page'    => $perPage,
            'total_rows'  => $totalRows,
            'total_pages' => $totalPages,
            'offset'      => $offset,
        ],
        'filters' => [
            'keyword' => $keyword,
        ],
        'badges' => [
            'pending_count' => $pendingBadgeCount,
        ],
    ]);
}


/* ============================================================
 * ACTION: RECEIVE — Tiếp nhận & trừ phép (Đơn lẻ)
 * ============================================================ */
function handleReceive(): void
{
    if (!isLoggedIn()) {
        jsonError('Vui lòng đăng nhập!', 401);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonError('Phương thức không hợp lệ! Chỉ chấp nhận POST.', 405);
    }

    $token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (function_exists('validateCsrfToken') && !validateCsrfToken($token)) {
        jsonError('Phiên làm việc hết hạn hoặc CSRF token không hợp lệ.', 419, ['code' => 'csrf_invalid']);
    }

    $user   = getCurrentUser();
    $role   = (string)($user['vai_tro'] ?? '');
    $userId = (int)($user['id'] ?? 0);

    if (!in_array($role, ['nhan_su', 'nhansu', 'admin'], true)) {
        jsonError('Bạn không có quyền truy cập chức năng này!', 403);
    }

    $donId        = (int)($_POST['don_id'] ?? 0);
    $ngayNopInput = trim($_POST['ngay_nop_nhan_su'] ?? '');

    if ($donId <= 0) {
        jsonError('Mã đơn không hợp lệ!', 400);
    }

    $db = Database::getConnection();
    $stDaDuyet    = defined('STATUS_DA_DUYET')     ? STATUS_DA_DUYET     : 'da_duyet';
    $stDaTiepNhan = defined('STATUS_DA_TIEP_NHAN') ? STATUS_DA_TIEP_NHAN : 'da_tiep_nhan';

    $donCols          = getTableColumnsTN('nghi_phep');
    $hasNgayNopNhanSu = isset($donCols['ngay_nop_nhan_su']);
    $hasNguoiTiepNhan = isset($donCols['nguoi_tiep_nhan']);
    $hasNgayTiepNhan  = isset($donCols['ngay_tiep_nhan']);
    $hasUpdatedAt     = isset($donCols['updated_at']);

    $db->beginTransaction();
    try {
        $stmtCheck = $db->prepare('
            SELECT np.*, nv.ho_ten, nv.id AS nv_id
            FROM nghi_phep np
            JOIN nhan_vien nv ON np.nhan_vien_id = nv.id
            WHERE np.id = ? AND np.trang_thai = ?
            FOR UPDATE
        ');
        $stmtCheck->execute([$donId, $stDaDuyet]);
        $don = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        if (!$don) {
            throw new Exception('Không tìm thấy đơn hoặc đơn chưa được Lãnh đạo phê duyệt!');
        }

        $ngayNop    = !empty($ngayNopInput) ? $ngayNopInput : date('Y-m-d', strtotime($don['created_at']));
        $nhanVienId = (int)$don['nv_id'];
        $soNgay     = (float)$don['so_ngay'];
        $year       = (int)date('Y', strtotime($don['tu_ngay']));

        $hasDaTruPhep = isset($donCols['da_tru_phep']);
        if (!$hasDaTruPhep || empty($don['da_tru_phep'])) {
            if (!deductLeaveDaysTN($donId, $nhanVienId, $soNgay, $year)) {
                throw new Exception('Không thể trừ ngày phép cho nhân viên này!');
            }
        }

        $set    = ['trang_thai = ?'];
        $params = [$stDaTiepNhan];

        if ($hasNgayNopNhanSu) { $set[] = 'ngay_nop_nhan_su = ?'; $params[] = $ngayNop; }
        if ($hasNguoiTiepNhan) { $set[] = 'nguoi_tiep_nhan = ?';  $params[] = $userId; }
        if ($hasNgayTiepNhan)  { $set[] = 'ngay_tiep_nhan = NOW()'; }
        if ($hasUpdatedAt)     { $set[] = 'updated_at = NOW()'; }

        $params[] = $donId;

        $sql = 'UPDATE nghi_phep SET ' . implode(', ', $set) . ' WHERE id = ?';
        $stmtUpdate = $db->prepare($sql);
        $stmtUpdate->execute($params);

        if (function_exists('recordLeaveHistory')) {
            try {
                recordLeaveHistory(
                    $donId, $userId,
                    'Phòng Nhân sự tiếp nhận giấy & trừ phép',
                    $stDaDuyet, $stDaTiepNhan,
                    'Xác nhận Ngày nộp: ' . formatDateVN($ngayNop) . ' — trừ ' . $soNgay . ' ngày phép.'
                );
            } catch (Throwable $e) {
                error_log('[TiepNhan] recordLeaveHistory: ' . $e->getMessage());
            }
        }

        $db->commit();

        if (function_exists('createNotification')) {
            try {
                $stmtUserNV = $db->prepare('SELECT id FROM users WHERE nhan_vien_id = ?');
                $stmtUserNV->execute([$nhanVienId]);
                $nvUserId = $stmtUserNV->fetchColumn();
                if ($nvUserId) {
                    createNotification(
                        (int)$nvUserId,
                        'Phòng Nhân sự đã tiếp nhận giấy nghỉ phép',
                        "Giấy nghỉ phép {$don['ma_don']} đã được tiếp nhận (Ngày nộp: " . formatDateVN($ngayNop) . ")",
                        'lichsudonnghiphep.html'
                    );
                }
            } catch (Throwable $e) {
                error_log('[TiepNhan] notify: ' . $e->getMessage());
            }
        }

        jsonSuccess([
            'id'       => $donId,
            'status'   => $stDaTiepNhan,
            'ngay_nop' => $ngayNop,
            'ma_don'   => $don['ma_don'],
        ], "Đã tiếp nhận giấy nghỉ phép {$don['ma_don']} (Ngày nộp: " . formatDateVN($ngayNop) . ")!");

    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('[TiepNhan] receive error: ' . $e->getMessage());
        $debug = defined('IS_DEV_ENV') && IS_DEV_ENV;
        jsonError(
            $debug ? 'Lỗi: ' . $e->getMessage() : 'Không thể tiếp nhận. Vui lòng thử lại!',
            500
        );
    }
}

/* ============================================================
 * ⭐ ACTION: RECEIVE_BULK — Tiếp nhận hàng loạt (ĐÃ FIX)
 * ============================================================ */
function handleReceiveBulk(array $jsonInput): void
{
    if (!isLoggedIn()) {
        jsonError('Vui lòng đăng nhập!', 401);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonError('Phương thức không hợp lệ! Chỉ chấp nhận POST.', 405);
    }

    $user   = getCurrentUser();
    $role   = (string)($user['vai_tro'] ?? '');
    $userId = (int)($user['id'] ?? 0);

    if (!in_array($role, ['nhan_su', 'nhansu', 'admin'], true)) {
        jsonError('Bạn không có quyền truy cập chức năng này!', 403);
    }

    $token = $jsonInput['csrf_token'] ?? '';
    if (function_exists('validateCsrfToken') && !validateCsrfToken($token)) {
        jsonError('CSRF token không hợp lệ.', 403);
    }

    $ids = $jsonInput['ids'] ?? [];
    if (empty($ids) || !is_array($ids)) {
        jsonError('Danh sách đơn không hợp lệ.', 400);
    }

    $db = Database::getConnection();
    $stDaDuyet    = defined('STATUS_DA_DUYET')     ? STATUS_DA_DUYET     : 'da_duyet';
    $stDaTiepNhan = defined('STATUS_DA_TIEP_NHAN') ? STATUS_DA_TIEP_NHAN : 'da_tiep_nhan';

    $donCols          = getTableColumnsTN('nghi_phep');
    $hasNgayNopNhanSu = isset($donCols['ngay_nop_nhan_su']);
    $hasNguoiTiepNhan = isset($donCols['nguoi_tiep_nhan']);
    $hasNgayTiepNhan  = isset($donCols['ngay_tiep_nhan']);
    $hasUpdatedAt     = isset($donCols['updated_at']);
    $hasDaTruPhep     = isset($donCols['da_tru_phep']);

    $successCount = 0;
    $failCount = 0;

    $db->beginTransaction();
    try {
        foreach ($ids as $id) {
            $donId = (int)$id;
            if ($donId <= 0) continue;

            $stmtCheck = $db->prepare('
                SELECT np.*, nv.id AS nv_id
                FROM nghi_phep np
                JOIN nhan_vien nv ON np.nhan_vien_id = nv.id
                WHERE np.id = ? AND np.trang_thai = ?
                FOR UPDATE
            ');
            $stmtCheck->execute([$donId, $stDaDuyet]);
            $don = $stmtCheck->fetch(PDO::FETCH_ASSOC);

            if (!$don) {
                $failCount++;
                continue;
            }

            $ngayNop    = date('Y-m-d', strtotime($don['created_at']));
            $nhanVienId = (int)$don['nv_id'];
            $soNgay     = (float)$don['so_ngay'];
            $year       = (int)date('Y', strtotime($don['tu_ngay']));

            if (!$hasDaTruPhep || empty($don['da_tru_phep'])) {
                if (!deductLeaveDaysTN($donId, $nhanVienId, $soNgay, $year)) {
                    throw new Exception("Không thể trừ ngày phép cho đơn ID $donId (Nhân viên ID $nhanVienId).");
                }
            }

            $set    = ['trang_thai = ?'];
            $params = [$stDaTiepNhan];

            if ($hasNgayNopNhanSu) { $set[] = 'ngay_nop_nhan_su = ?'; $params[] = $ngayNop; }
            if ($hasNguoiTiepNhan) { $set[] = 'nguoi_tiep_nhan = ?';  $params[] = $userId; }
            if ($hasNgayTiepNhan)  { $set[] = 'ngay_tiep_nhan = NOW()'; }
            if ($hasUpdatedAt)     { $set[] = 'updated_at = NOW()'; }

            $params[] = $donId;

            $sql = 'UPDATE nghi_phep SET ' . implode(', ', $set) . ' WHERE id = ?';
            $db->prepare($sql)->execute($params);

            if (function_exists('recordLeaveHistory')) {
                try {
                    recordLeaveHistory(
                        $donId, $userId,
                        'Phòng Nhân sự tiếp nhận giấy & trừ phép (Hàng loạt)',
                        $stDaDuyet, $stDaTiepNhan,
                        'Xác nhận Ngày nộp: ' . formatDateVN($ngayNop) . ' — trừ ' . $soNgay . ' ngày phép.'
                    );
                } catch (Throwable $e) { error_log('[TiepNhan] recordLeaveHistory: ' . $e->getMessage()); }
            }

            if (function_exists('createNotification')) {
                try {
                    $stmtUserNV = $db->prepare('SELECT id FROM users WHERE nhan_vien_id = ?');
                    $stmtUserNV->execute([$nhanVienId]);
                    $nvUserId = $stmtUserNV->fetchColumn();
                    if ($nvUserId) {
                        createNotification(
                            (int)$nvUserId,
                            'Phòng Nhân sự đã tiếp nhận giấy nghỉ phép',
                            "Giấy nghỉ phép {$don['ma_don']} đã được tiếp nhận (Ngày nộp: " . formatDateVN($ngayNop) . ")",
                            'lichsudonnghiphep.html'
                        );
                    }
                } catch (Throwable $e) { error_log('[TiepNhan] notify: ' . $e->getMessage()); }
            }

            $successCount++;
        }

        $db->commit();
        
        $msg = "Đã tiếp nhận thành công $successCount đơn.";
        if ($failCount > 0) $msg .= " Có $failCount đơn không hợp lệ hoặc đã được xử lý.";
        
        jsonSuccess([], $msg);

    } catch (Throwable $e) {
        $db->rollBack();
        error_log('[TiepNhan] bulk receive error: ' . $e->getMessage());
        $debug = defined('IS_DEV_ENV') && IS_DEV_ENV;
        jsonError(
            $debug ? 'Lỗi: ' . $e->getMessage() : 'Không thể tiếp nhận hàng loạt. Vui lòng thử lại!',
            500
        );
    }
}


/* ============================================================
 * HELPER — Trừ phép an toàn
 * ============================================================ */
function deductLeaveDaysTN(int $nghiPhepId, int $nhanVienId, float $soNgay, int $year): bool
{
    $db = Database::getConnection();

    $cols = getTableColumnsTN('phep_nam');
    $hasNam        = isset($cols['phep_nam']);
    $hasThamNien   = isset($cols['phep_tham_nien']);
    $hasChuyenSang = isset($cols['phep_chuyen_sang']);
    $hasDaNghi     = isset($cols['so_ngay_da_nghi']);

    if (!$hasNam || !$hasDaNghi) {
        error_log('[TiepNhan] phep_nam thiếu cột phep_nam hoặc so_ngay_da_nghi.');
        return false;
    }

    try {
        $stmtLock = $db->prepare('SELECT * FROM phep_nam WHERE nhan_vien_id = ? AND nam = ? FOR UPDATE');
        $stmtLock->execute([$nhanVienId, $year]);
        $quota = $stmtLock->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('[TiepNhan] Lock phep_nam: ' . $e->getMessage());
        $quota = false;
    }

    if (!$quota) {
        try {
            $insCols = ['nhan_vien_id', 'nam', 'phep_nam', 'so_ngay_da_nghi'];
            $insVals = [$nhanVienId, $year, 12.0, 0.0];
            if ($hasThamNien)   { $insCols[] = 'phep_tham_nien';   $insVals[] = 0.0; }
            if ($hasChuyenSang) { $insCols[] = 'phep_chuyen_sang'; $insVals[] = 0.0; }
            $placeholders = implode(',', array_fill(0, count($insCols), '?'));
            $colsQuoted   = '`' . implode('`,`', $insCols) . '`';
            $db->prepare("INSERT INTO phep_nam ($colsQuoted) VALUES ($placeholders)")->execute($insVals);
            $quota = ['phep_nam' => 12.0, 'phep_tham_nien' => 0.0, 'phep_chuyen_sang' => 0.0, 'so_ngay_da_nghi' => 0.0];
        } catch (Throwable $e) {
            error_log('[TiepNhan] Insert phep_nam: ' . $e->getMessage());
            return false;
        }
    }

    $tongPhep = (float)$quota['phep_nam'];
    if ($hasThamNien)   $tongPhep += (float)($quota['phep_tham_nien']   ?? 0);
    if ($hasChuyenSang) $tongPhep += (float)($quota['phep_chuyen_sang'] ?? 0);

    $newDaNghi = (float)($quota['so_ngay_da_nghi'] ?? 0) + $soNgay;
    $newConLai = max(0, $tongPhep - $newDaNghi);

    try {
        $db->prepare('UPDATE phep_nam SET so_ngay_da_nghi = ? WHERE nhan_vien_id = ? AND nam = ?')
           ->execute([$newDaNghi, $nhanVienId, $year]);

        $donCols = getTableColumnsTN('nghi_phep');
        $hasDaTruPhep = isset($donCols['da_tru_phep']);
        $hasSoPhepConLai = isset($donCols['so_phep_con_lai_luc_nghi']);

        if ($hasDaTruPhep && $hasSoPhepConLai) {
            $db->prepare('UPDATE nghi_phep SET da_tru_phep = 1, so_phep_con_lai_luc_nghi = ? WHERE id = ?')
               ->execute([$newConLai, $nghiPhepId]);
        } elseif ($hasDaTruPhep) {
            $db->prepare('UPDATE nghi_phep SET da_tru_phep = 1 WHERE id = ?')->execute([$nghiPhepId]);
        }
    } catch (Throwable $e) {
        error_log('[TiepNhan] Update phep_nam/nghi_phep: ' . $e->getMessage());
        return false;
    }

    return true;
}