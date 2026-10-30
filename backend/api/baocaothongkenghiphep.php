<?php
/**
 * ============================================================
 *  API: BÁO CÁO THỐNG KÊ NGHỈ PHÉP
 *  Endpoint: backend/api/baocao.php
 *  Method: GET
 *  Response: JSON
 * ============================================================
 *  ⭐ FIX (2024-10):
 *   - Load config TRƯỚC khi gửi header (session start sớm)
 *   - Xóa 3 helper jsonResponse/jsonSuccess/jsonError trùng
 *     với functions.php (tránh conflict signature `$extra`)
 *   - Sửa `jsonError('...', 401, 'string')` → array
 *   - Exception handler check headers_sent()
 * ============================================================
 */

/* 1. OUTPUT BUFFERING */
if (!ob_get_level()) { ob_start(); }

/* ============================================================
 * 2. GLOBAL ERROR HANDLERS
 * ============================================================ */
set_exception_handler(function (Throwable $ex) {
    while (ob_get_level()) { ob_end_clean(); }
    $debug = defined('IS_DEV_ENV') && IS_DEV_ENV;
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8', true, 500);
    } else {
        http_response_code(500);
    }
    error_log('[BaoCao] Exception: ' . $ex->getMessage()
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
        error_log('[BaoCao] Fatal: ' . $err['message']
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
    error_log('[BaoCao] ' . $errstr . ' @ ' . basename($errfile) . ':' . $errline);
    return true;
});

/* ============================================================
 * 3. LOAD CONFIG TRƯỚC — session start trước khi gửi header
 * ============================================================ */
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

/* ============================================================
 * 4. HEADERS — giờ mới gửi
 * ============================================================ */
@header('Content-Type: application/json; charset=utf-8');
@header('X-Content-Type-Options: nosniff');
@header('Cache-Control: no-store, no-cache, must-revalidate');

/* ============================================================
 * 5. AUTH GUARD
 * ============================================================ */
if (!isLoggedIn()) {
    jsonError('Phiên đăng nhập đã hết hạn. Vui lòng đăng nhập lại!', 401, ['code' => 'unauthorized']);
}

$currentUser = getCurrentUser();
$currentEmp  = getCurrentEmployee();
$userRole    = $currentUser['vai_tro'] ?? '';

/* ============================================================
 * 6. ROLE GUARD — chỉ Admin / Lãnh đạo / Nhân sự
 * ============================================================ */
$allowedRoles = [
    'admin',
    'lanh_dao', 'giam_doc', 'giamdoc', 'gd',
    'pho_giam_doc', 'phogiamdoc', 'pgd',
    'ban_lanh_dao', 'ban_giam_doc', 'banggiamdoc', 'bgd',
    'nhan_su', 'nhansu'
];
if (!in_array($userRole, $allowedRoles, true)) {
    jsonError('Bạn không có quyền truy cập chức năng này!', 403, ['code' => 'forbidden']);
}

$db = Database::getConnection();

/* ============================================================
 * 7. ĐỌC FILTER
 * ============================================================ */
$currentYear = (int)date('Y');
$nam    = (int)($_GET['nam']     ?? $currentYear);
$thang  = (int)($_GET['thang']   ?? 0);
$khoaId = (int)($_GET['khoa_id'] ?? 0);

if ($nam < $currentYear - 5 || $nam > $currentYear + 2) {
    $nam = $currentYear;
}
if ($thang < 0 || $thang > 12) {
    $thang = 0;
}

/* ============================================================
 * 8. QUERY: TỔNG QUAN
 * ============================================================ */
$sqlSummary = '
    SELECT 
        COUNT(*) AS tong_don,
        COALESCE(SUM(np.so_ngay), 0) AS tong_ngay_nghi,
        COUNT(DISTINCT np.nhan_vien_id) AS tong_nhan_vien_nghi
    FROM nghi_phep np
    JOIN nhan_vien nv ON np.nhan_vien_id = nv.id
    WHERE np.trang_thai IN ("da_duyet", "da_tiep_nhan")
      AND YEAR(np.tu_ngay) = ?
';
$paramsSummary = [$nam];

if ($thang > 0) {
    $sqlSummary .= ' AND MONTH(np.tu_ngay) = ?';
    $paramsSummary[] = $thang;
}
if ($khoaId > 0) {
    $sqlSummary .= ' AND nv.khoa_phong_id = ?';
    $paramsSummary[] = $khoaId;
}

$summary = [
    'tong_don'            => 0,
    'tong_ngay_nghi'      => 0,
    'tong_nhan_vien_nghi' => 0,
];

try {
    $stmtSummary = $db->prepare($sqlSummary);
    $stmtSummary->execute($paramsSummary);
    $row = $stmtSummary->fetch();
    if ($row) {
        $summary = [
            'tong_don'            => (int)($row['tong_don'] ?? 0),
            'tong_ngay_nghi'      => (float)($row['tong_ngay_nghi'] ?? 0),
            'tong_nhan_vien_nghi' => (int)($row['tong_nhan_vien_nghi'] ?? 0),
        ];
    }
} catch (Throwable $e) {
    error_log('[BaoCao] Summary error: ' . $e->getMessage());
}

/* ============================================================
 * 9. QUERY: THEO KHOA / PHÒNG
 * ============================================================ */
$sqlByDept = '
    SELECT 
        kp.id, kp.ten_khoa,
        COUNT(np.id) AS so_don,
        COALESCE(SUM(np.so_ngay), 0) AS tong_ngay_nghi,
        COUNT(DISTINCT np.nhan_vien_id) AS so_nguoi_nghi
    FROM khoa_phong kp
    LEFT JOIN nhan_vien nv ON kp.id = nv.khoa_phong_id
    LEFT JOIN nghi_phep np ON nv.id = np.nhan_vien_id 
         AND np.trang_thai IN ("da_duyet", "da_tiep_nhan")
         AND YEAR(np.tu_ngay) = ?
    GROUP BY kp.id, kp.ten_khoa
    ORDER BY tong_ngay_nghi DESC, kp.ten_khoa ASC
';

$deptStats = [];
try {
    $stmtByDept = $db->prepare($sqlByDept);
    $stmtByDept->execute([$nam]);
    $rows = $stmtByDept->fetchAll();

    foreach ($rows as $r) {
        $deptStats[] = [
            'id'             => (int)$r['id'],
            'ten_khoa'       => $r['ten_khoa'] ?? '',
            'so_don'         => (int)$r['so_don'],
            'so_nguoi_nghi'  => (int)$r['so_nguoi_nghi'],
            'tong_ngay_nghi' => (float)$r['tong_ngay_nghi'],
        ];
    }
} catch (Throwable $e) {
    error_log('[BaoCao] byDept error: ' . $e->getMessage());
}

/* ============================================================
 * 10. QUERY: DANH SÁCH KHOA/PHÒNG CHO DROPDOWN
 * ============================================================ */
$departments = [];
try {
    $stmtDept = $db->query('SELECT id, ten_khoa FROM khoa_phong ORDER BY ten_khoa ASC');
    $departments = $stmtDept->fetchAll();
} catch (Throwable $e) {
    error_log('[BaoCao] departments error: ' . $e->getMessage());
}

/* ============================================================
 * 11. USER INFO FOR FRONTEND
 * ============================================================ */
$userForFe = [
    'id'            => (int)($currentUser['id'] ?? 0),
    'username'      => $currentUser['username'] ?? '',
    'vai_tro'       => $userRole,
    'nhan_vien_id'  => (int)($currentUser['nhan_vien_id'] ?? 0) ?: null,
    'ho_ten'        => $currentEmp['ho_ten'] ?? $currentUser['username'] ?? 'Người dùng',
    'ma_nv'         => $currentEmp['ma_nv'] ?? null,
    'email'         => $currentEmp['email'] ?? null,
    'so_dien_thoai' => $currentEmp['so_dien_thoai'] ?? null,
    'ngay_sinh'     => $currentEmp['ngay_sinh'] ?? null,
    'chuc_vu'       => $currentEmp['chuc_vu'] ?? null,
    'ten_khoa'      => $currentEmp['ten_khoa'] ?? null,
];

/* ============================================================
 * 12. RESPONSE
 * ============================================================ */
jsonSuccess([
    'year'        => $nam,
    'user'        => $userForFe,
    'summary'     => $summary,
    'dept_stats'  => $deptStats,
    'departments' => $departments,
    'filters'     => [
        'nam'     => $nam,
        'thang'   => $thang,
        'khoa_id' => $khoaId,
    ],
]);