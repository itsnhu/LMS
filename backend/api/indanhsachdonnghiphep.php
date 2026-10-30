<?php
/**
 * ============================================================
 *  API: DỮ LIỆU IN GIẤY NGHỈ PHÉP KHỔ A4
 *  Endpoint: backend/api/indanhsachdonnghiphep.php
 * ============================================================
 */

if (!ob_get_level()) { ob_start(); }

set_exception_handler(function (Throwable $ex) {
    while (ob_get_level()) { ob_end_clean(); }
    $debug = defined('IS_DEV_ENV') && IS_DEV_ENV;
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8', true, 500);
    } else {
        http_response_code(500);
    }
    error_log('[InDon] Exception: ' . $ex->getMessage()
        . ' @ ' . $ex->getFile() . ':' . $ex->getLine());
    echo json_encode([
        'success' => false,
        'error'   => 'exception',
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
        error_log('[InDon] Fatal: ' . $err['message']
            . ' @ ' . $err['file'] . ':' . $err['line']);
        echo json_encode([
            'success' => false,
            'error'   => 'fatal',
            'message' => $debug
                ? 'Fatal: ' . $err['message'] . ' @ ' . basename($err['file']) . ':' . $err['line']
                : 'Lỗi nghiêm trọng từ server!',
        ], JSON_UNESCAPED_UNICODE);
    }
});

set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    if (!(error_reporting() & $errno)) return false;
    error_log('[InDon] ' . $errstr . ' @ ' . basename($errfile) . ':' . $errline);
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
        'error'   => 'config_missing',
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
if (!defined('ROLE_ADMIN'))       define('ROLE_ADMIN',       'admin');
if (!defined('ROLE_NHAN_VIEN'))   define('ROLE_NHAN_VIEN',   'nhan_vien');
if (!defined('ROLE_TRUONG_KHOA')) define('ROLE_TRUONG_KHOA', 'truong_khoa');
if (!defined('ROLE_LANH_DAO'))    define('ROLE_LANH_DAO',    'lanh_dao');
if (!defined('ROLE_NHAN_SU'))     define('ROLE_NHAN_SU',     'nhan_su');
if (!defined('CURRENT_YEAR'))     define('CURRENT_YEAR', (int)date('Y'));
if (!defined('HOSPITAL_NAME'))    define('HOSPITAL_NAME', 'BỆNH VIỆN TÂM TRÍ ĐỒNG THÁP');
if (!defined('HOSPITAL_DIRECTOR')) define('HOSPITAL_DIRECTOR', 'ThS. BS. Đinh Tấn Tài');

if (!defined('STATUS_IN_PRINTABLE')) {
    define('STATUS_IN_PRINTABLE', ['da_duyet', 'da_tiep_nhan']);
}
if (!defined('MAX_ROWS_PER_PAGE')) define('MAX_ROWS_PER_PAGE', 10);

/* ============================================================
   6. HELPERS
   ============================================================ */
if (!function_exists('normalizeCaNghiDbIn')) {
    function normalizeCaNghiDbIn(?string $ca): string {
        $valid = ['ca_ngay', 'sang', 'chieu'];
        if ($ca === null || $ca === '') return 'ca_ngay';
        return in_array($ca, $valid, true) ? $ca : 'ca_ngay';
    }
}
if (!function_exists('caNghiLabelIn')) {
    function caNghiLabelIn(?string $ca): string {
        $map = ['ca_ngay' => 'Cả ngày', 'sang' => 'Buổi sáng', 'chieu' => 'Buổi chiều'];
        return $map[normalizeCaNghiDbIn($ca)] ?? 'Cả ngày';
    }
}
if (!function_exists('isCaNghiSingleIn')) {
    function isCaNghiSingleIn(?string $start, ?string $end): bool {
        return normalizeCaNghiDbIn($start) === normalizeCaNghiDbIn($end);
    }
}
if (!function_exists('diffDaysInclusiveIn')) {
    function diffDaysInclusiveIn(?string $tuNgay, ?string $denNgay): int {
        if (empty($tuNgay) || empty($denNgay)) return 1;
        $t1 = strtotime($tuNgay);
        $t2 = strtotime($denNgay);
        if ($t1 === false || $t2 === false) return 1;
        return (int)floor(($t2 - $t1) / 86400) + 1;
    }
}
if (!function_exists('getBuoiNghiTextIn')) {
    function getBuoiNghiTextIn(?string $buoiStart, ?string $buoiEnd = null): string {
        $s = normalizeCaNghiDbIn($buoiStart);
        $e = normalizeCaNghiDbIn($buoiEnd !== null ? $buoiEnd : $s);
        if ($s === $e) {
            if ($s === 'ca_ngay') return '';
            return caNghiLabelIn($s) . ' (0.5 ngày)';
        }
        return caNghiLabelIn($s) . ' ngày đầu → ' . caNghiLabelIn($e) . ' ngày cuối';
    }
}
if (!function_exists('caNghiShortLabelIn')) {
    function caNghiShortLabelIn(?string $buoiStart, ?string $buoiEnd = null): string {
        $s = normalizeCaNghiDbIn($buoiStart);
        $e = normalizeCaNghiDbIn($buoiEnd !== null ? $buoiEnd : $s);
        if ($s === $e) return caNghiLabelIn($s);
        return caNghiLabelIn($s) . ' → ' . caNghiLabelIn($e);
    }
}
if (!function_exists('getTableColumnsIn')) {
    function getTableColumnsIn(PDO $db, string $table): array {
        $cols = [];
        try {
            $stmt = $db->query("SHOW COLUMNS FROM `$table`");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $cols[$c['Field']] = true;
            }
        } catch (Throwable $e) {
            error_log("[InDon] getTableColumns($table): " . $e->getMessage());
        }
        return $cols;
    }
}
if (!function_exists('mapChucVuLabel')) {
    function mapChucVuLabel(?string $code): string {
        $map = [
            'CEO' => 'Giám đốc Điều hành', 'CMO' => 'Giám đốc Chuyên môn',
            'PGD' => 'Phó Giám đốc', 'TRUONG_KHOA' => 'Trưởng Khoa',
            'PHO_KHOA' => 'Phó Khoa', 'TRUONG_PHONG' => 'Trưởng Phòng',
            'PHO_PHONG' => 'Phó Phòng', 'DIEU_DUONG_TRUONG_KHOA' => 'Điều dưỡng trưởng Khoa',
            'DIEU_DUONG_TRUONG_BV' => 'Điều dưỡng trưởng Bệnh viện',
            'BAC_SI_CHUYEN_KHOA' => 'Bác sĩ Chuyên khoa', 'BAC_SI_DA_KHOA' => 'Bác sĩ Đa khoa',
            'BAC_SI_DIEU_TRI' => 'Bác sĩ Điều trị', 'DIEU_DUONG' => 'Điều dưỡng',
            'NU_HO_SINH' => 'Nữ hộ sinh', 'HO_LY' => 'Hộ lý', 'KY_THUAT_VIEN' => 'Kỹ thuật viên',
            'DUOC_SI' => 'Dược sĩ', 'DUOC_SI_LAM_SANG' => 'Dược sĩ Lâm sàng',
            'NHAN_VIEN_KE_TOAN' => 'Nhân viên Kế toán',
            'NHAN_VIEN_HANH_CHINH' => 'Nhân viên Hành chính - Nhân sự',
            'NHAN_VIEN_IT' => 'Nhân viên Công nghệ thông tin',
            'NHAN_VIEN_CSKH' => 'Nhân viên Chăm sóc khách hàng',
            'NHAN_VIEN_THU_NGAN' => 'Nhân viên Thu ngân / Viện phí',
            'Khác' => 'Khác',
        ];
        if (!$code) return '';
        return $map[$code] ?? $code;
    }
}

/* ============================================================
 * 7. AUTH GUARD
 * ============================================================ */
if (!isLoggedIn()) {
    jsonError('Vui lòng đăng nhập để tiếp tục!', 401, ['code' => 'unauthorized']);
}

$currentUser   = getCurrentUser();
$currentEmp    = getCurrentEmployee();
$userRole      = $currentUser['vai_tro']            ?? '';
$currentEmpId  = (int)($currentUser['nhan_vien_id'] ?? 0);
$currentUserId = (int)($currentUser['id']           ?? 0);

/* ============================================================
 * 8. ĐỌC INPUT
 * ============================================================ */
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    jsonError('Mã giấy nghỉ phép không hợp lệ!', 400, ['code' => 'invalid_id']);
}

$db = Database::getConnection();

/* ============================================================
 * 9. CHECK SCHEMA
 * ============================================================ */
$columns          = getTableColumnsIn($db, 'nghi_phep');
$hasHoTenKyCol    = isset($columns['ho_ten_nguoi_ky']);
$hasHoTenKyTKCol  = isset($columns['ho_ten_nguoi_ky_truong_khoa']);
$hasHoTenKyLDCol  = isset($columns['ho_ten_nguoi_ky_lanh_dao']);

/* ============================================================
 * 10. LẤY ĐƠN HIỆN TẠI
 * ============================================================ */
$stmt = $db->prepare('
    SELECT np.*, nv.id AS nhan_vien_id, nv.ho_ten, nv.ma_nv, nv.chuc_vu,
           nv.khoa_phong_id, kp.ten_khoa
    FROM nghi_phep np
    JOIN nhan_vien nv ON np.nhan_vien_id = nv.id
    LEFT JOIN khoa_phong kp ON nv.khoa_phong_id = kp.id
    WHERE np.id = ?
');
$stmt->execute([$id]);
$currentLeave = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$currentLeave) {
    jsonError('Không tìm thấy dữ liệu giấy nghỉ phép!', 404, ['code' => 'not_found']);
}

/* ============================================================
 * 11. PHÂN QUYỀN
 * ============================================================ */
$isNhanVienOnly = in_array($userRole, ['nhan_vien', 'nhanvien'], true);
if ($isNhanVienOnly && (int)$currentLeave['nhan_vien_id'] !== $currentEmpId) {
    jsonError('Bạn không có quyền in giấy nghỉ phép của nhân viên khác!', 403, ['code' => 'forbidden']);
}

$nhanVienId = (int)$currentLeave['nhan_vien_id'];
$khoaId     = (int)($currentLeave['khoa_phong_id'] ?? 0);
$year       = (int)date('Y', strtotime($currentLeave['tu_ngay']));
$prevYear   = $year - 1;

/* ============================================================
 * 12. ⭐ CẤP CHO = TRƯỞNG KHOA của khoa nhân viên tạo đơn
 * ============================================================ */
$capChoHoTen  = '';
$capChoMaNV   = '';
$capChoChucVu = '';

if ($khoaId > 0) {
    try {
        $stmtTK = $db->prepare("
            SELECT nv.ho_ten, nv.ma_nv, nv.chuc_vu
            FROM users u
            INNER JOIN nhan_vien nv ON u.nhan_vien_id = nv.id
            WHERE nv.khoa_phong_id = ?
              AND u.vai_tro IN ('truong_khoa', 'truongkhoa')
            ORDER BY u.id ASC
            LIMIT 1
        ");
        $stmtTK->execute([$khoaId]);
        $tk = $stmtTK->fetch(PDO::FETCH_ASSOC);
        if ($tk) {
            $capChoHoTen  = $tk['ho_ten']  ?? '';
            $capChoMaNV   = $tk['ma_nv']   ?? '';
            $capChoChucVu = mapChucVuLabel($tk['chuc_vu'] ?? '');
        }
    } catch (Throwable $e) {
        error_log('[InDon] capCho TK user: ' . $e->getMessage());
    }

    if ($capChoHoTen === '') {
        try {
            $stmtTK2 = $db->prepare("
                SELECT ho_ten, ma_nv, chuc_vu
                FROM nhan_vien
                WHERE khoa_phong_id = ?
                  AND chuc_vu IN ('TRUONG_KHOA', 'TRUONG_PHONG')
                ORDER BY id ASC
                LIMIT 1
            ");
            $stmtTK2->execute([$khoaId]);
            $tk2 = $stmtTK2->fetch(PDO::FETCH_ASSOC);
            if ($tk2) {
                $capChoHoTen  = $tk2['ho_ten']  ?? '';
                $capChoMaNV   = $tk2['ma_nv']   ?? '';
                $capChoChucVu = mapChucVuLabel($tk2['chuc_vu'] ?? '');
            }
        } catch (Throwable $e) {
            error_log('[InDon] capCho fallback: ' . $e->getMessage());
        }
    }
}

if ($capChoHoTen === '' && !empty($currentLeave['ten_khoa'])) {
    $capChoHoTen  = 'Trưởng khoa ' . $currentLeave['ten_khoa'];
    $capChoChucVu = 'Trưởng Khoa';
}

/* ============================================================
 * 13. ⭐ LẤY NGƯỜI DUYỆT CUỐI CÙNG (Đồng bộ với Cột 4)
 * ============================================================ */
$approverTitle = 'GIÁM ĐỐC';
$approverName  = HOSPITAL_DIRECTOR;
$foundApprover = false;

// Ưu tiên 1: Đọc từ cột nhập tay (giống Cột 4)
$hoTenLD_raw = $hasHoTenKyLDCol ? ($currentLeave['ho_ten_nguoi_ky_lanh_dao'] ?? null) : null;

if (!empty(trim((string)$hoTenLD_raw))) {
    $approverName = trim($hoTenLD_raw);
    $foundApprover = true;
}

// Ưu tiên 2: Đọc từ nguoi_phe_duyet (nếu cột nhập tay rỗng)
if (!$foundApprover && !empty($currentLeave['nguoi_phe_duyet'])) {
    $rawApprover = $currentLeave['nguoi_phe_duyet'];
    
    if (is_numeric($rawApprover)) {
        $approverId = (int)$rawApprover;
        $stmtAppr = $db->prepare("SELECT nv.ho_ten, nv.chuc_vu FROM users u JOIN nhan_vien nv ON u.nhan_vien_id = nv.id WHERE u.id = ?");
        $stmtAppr->execute([$approverId]);
        $appr = $stmtAppr->fetch(PDO::FETCH_ASSOC);
        
        if ($appr) {
            $approverName = $appr['ho_ten'];
            $foundApprover = true;
        } else {
            $stmtAppr2 = $db->prepare("SELECT ho_ten, chuc_vu FROM nhan_vien WHERE id = ?");
            $stmtAppr2->execute([$approverId]);
            $appr2 = $stmtAppr2->fetch(PDO::FETCH_ASSOC);
            if ($appr2) {
                $approverName = $appr2['ho_ten'];
                $foundApprover = true;
            }
        }
    } else {
        $approverName = $rawApprover;
        $foundApprover = true;
    }
}

// Ưu tiên 3: Nếu đã tìm thấy tên, dò lại chức vụ (Title) cho chính xác
if ($foundApprover) {
    if (trim($approverName) === trim(HOSPITAL_DIRECTOR)) {
        $approverTitle = 'GIÁM ĐỐC';
    } else {
        $stmtCV = $db->prepare("SELECT chuc_vu FROM nhan_vien WHERE ho_ten = ? LIMIT 1");
        $stmtCV->execute([$approverName]);
        $cvCode = $stmtCV->fetchColumn();
        if ($cvCode) {
            $cvLabel = mapChucVuLabel($cvCode);
            if ($cvLabel) $approverTitle = mb_strtoupper($cvLabel, 'UTF-8');
        } else {
             $approverTitle = 'GIÁM ĐỐC'; 
        }
    }
}

// Ưu tiên 4: Nếu đơn chưa có ai duyệt (dữ liệu rỗng), lấy GĐ mặc định
if (!$foundApprover) {
    try {
        $stmtGD = $db->query("
            SELECT nv.ho_ten
            FROM users u
            INNER JOIN nhan_vien nv ON u.nhan_vien_id = nv.id
            WHERE u.vai_tro IN ('giam_doc', 'giamdoc')
            ORDER BY u.id ASC
            LIMIT 1
        ");
        $gdName = $stmtGD ? $stmtGD->fetchColumn() : null;
        if ($gdName) {
            $approverName = $gdName;
        }
    } catch (Throwable $e) {
        error_log('[InDon] giam doc query: ' . $e->getMessage());
    }
}

/* ============================================================
 * 14. QUOTA NĂM
 * ============================================================ */
$quota = function_exists('getEmployeeLeaveQuota')
    ? getEmployeeLeaveQuota($nhanVienId, $year)
    : [
        'phep_nam' => 12, 'phep_tham_nien' => 0,
        'phep_chuyen_sang' => 0, 'tong_phep' => 12,
        'so_ngay_da_nghi' => 0, 'phep_con_lai' => 12,
      ];

/* ============================================================
 * 15. ĐẾM TỔNG ĐƠN + LẤY 10 ĐƠN MỚI NHẤT
 * ============================================================ */
$placeholders = implode(',', array_fill(0, count(STATUS_IN_PRINTABLE), '?'));

$stmtCount = $db->prepare("
    SELECT COUNT(*) FROM nghi_phep
    WHERE nhan_vien_id = ?
      AND YEAR(tu_ngay) = ?
      AND trang_thai IN ($placeholders)
");
$stmtCount->execute(array_merge([$nhanVienId, $year], STATUS_IN_PRINTABLE));
$totalConfirmed = (int)$stmtCount->fetchColumn();

$limitRows = MAX_ROWS_PER_PAGE;

$stmtAllLeaves = $db->prepare("
    SELECT * FROM nghi_phep
    WHERE nhan_vien_id = ?
      AND YEAR(tu_ngay) = ?
      AND trang_thai IN ($placeholders)
    ORDER BY tu_ngay DESC, id DESC
    LIMIT $limitRows
");
$stmtAllLeaves->execute(array_merge([$nhanVienId, $year], STATUS_IN_PRINTABLE));
$rawLeaves = $stmtAllLeaves->fetchAll(PDO::FETCH_ASSOC);

$allLeaves  = array_reverse($rawLeaves);
$totalSlots = MAX_ROWS_PER_PAGE;

$wasCut         = $totalConfirmed > MAX_ROWS_PER_PAGE;
$printedCount   = count($allLeaves);
$totalDaysPrint = 0.0;
foreach ($allLeaves as $lv) {
    $totalDaysPrint += (float)($lv['so_ngay'] ?? 0);
}

/* ============================================================
 * 16. BUILD MAP user_id → họ tên (chỉ để fallback)
 * ============================================================ */
$userIds = [];
foreach ($allLeaves as $it) {
    if (!empty($it['nguoi_cho_y_kien'])) $userIds[] = (int)$it['nguoi_cho_y_kien'];
    if (!empty($it['nguoi_phe_duyet']))  $userIds[] = (int)$it['nguoi_phe_duyet'];
}
$userIds = array_values(array_unique($userIds));

$userNameMap = [];
if (!empty($userIds)) {
    $ph = implode(',', array_fill(0, count($userIds), '?'));
    try {
        $stmtU = $db->prepare("
            SELECT u.id, nv.ho_ten
            FROM users u
            LEFT JOIN nhan_vien nv ON u.nhan_vien_id = nv.id
            WHERE u.id IN ($ph)
        ");
        $stmtU->execute($userIds);
        while ($r = $stmtU->fetch(PDO::FETCH_ASSOC)) {
            $userNameMap[(int)$r['id']] = $r['ho_ten'] ?? '';
        }
    } catch (Throwable $e) {
        error_log('[InDon] nameMap error: ' . $e->getMessage());
    }
}

/* ============================================================
 * 17. CHUẨN HÓA ĐƠN HIỆN TẠI
 * ============================================================ */
$currentCaStart = normalizeCaNghiDbIn($currentLeave['buoi_nghi']          ?? 'ca_ngay');
$currentCaEnd   = normalizeCaNghiDbIn($currentLeave['buoi_nghi_ket_thuc'] ?? $currentCaStart);

$hoTenNguoiNghi      = $hasHoTenKyCol   ? ($currentLeave['ho_ten_nguoi_ky']             ?? null) : null;
$hoTenNguoiKyTK      = $hasHoTenKyTKCol ? ($currentLeave['ho_ten_nguoi_ky_truong_khoa']  ?? null) : null;
$hoTenNguoiKyLD      = $hasHoTenKyLDCol ? ($currentLeave['ho_ten_nguoi_ky_lanh_dao']     ?? null) : null;

if (empty(trim((string)$hoTenNguoiKyTK))) $hoTenNguoiKyTK = $capChoHoTen;
if (empty(trim((string)$hoTenNguoiKyLD))) $hoTenNguoiKyLD = $approverName;

$leaveData = [
    'id'                 => (int)$currentLeave['id'],
    'ma_don'             => $currentLeave['ma_don']   ?? '',
    'ho_ten'             => $currentLeave['ho_ten']   ?? '',
    'ma_nv'              => $currentLeave['ma_nv']    ?? '',
    'chuc_vu'            => $currentLeave['chuc_vu']  ?? '',
    'ten_khoa'           => $currentLeave['ten_khoa'] ?? '',
    'tu_ngay'            => $currentLeave['tu_ngay']  ?? null,
    'den_ngay'           => $currentLeave['den_ngay'] ?? null,
    'trang_thai'         => $currentLeave['trang_thai'] ?? '',
    'is_printable'       => in_array($currentLeave['trang_thai'] ?? '', STATUS_IN_PRINTABLE, true),

    'buoi_nghi'          => $currentCaStart,
    'buoi_nghi_ket_thuc' => $currentCaEnd,
    'buoi_nghi_text'     => getBuoiNghiTextIn($currentCaStart, $currentCaEnd),
    'ca_nghi_label'      => caNghiShortLabelIn($currentCaStart, $currentCaEnd),

    'chu_ky_nguoi_nghi'           => $currentLeave['chu_ky_nguoi_nghi']   ?? null,
    'ho_ten_nguoi_ky'             => $hoTenNguoiNghi,

    'chu_ky_truong_khoa'          => $currentLeave['chu_ky_truong_khoa']  ?? null,
    'ho_ten_nguoi_ky_truong_khoa' => $hoTenNguoiKyTK,

    'chu_ky_lanh_dao'             => $currentLeave['chu_ky_lanh_dao']     ?? null,
    'ho_ten_nguoi_ky_lanh_dao'    => $hoTenNguoiKyLD,
];

/* ============================================================
 * 18. CHUẨN HÓA TẤT CẢ ĐƠN TRONG BẢNG
 * ============================================================ */
$allLeavesData = array_map(function ($it) use (
    $currentLeave,
    $userNameMap,
    $hasHoTenKyCol,
    $hasHoTenKyTKCol,
    $hasHoTenKyLDCol,
    $capChoHoTen,
    $approverName
) {
    $caStart = normalizeCaNghiDbIn($it['buoi_nghi']          ?? 'ca_ngay');
    $caEnd   = normalizeCaNghiDbIn($it['buoi_nghi_ket_thuc'] ?? $caStart);

    $hoTenNguoiKy = $hasHoTenKyCol   ? ($it['ho_ten_nguoi_ky'] ?? null) : null;
    if (empty(trim((string)$hoTenNguoiKy))) $hoTenNguoiKy = null;

    $hoTenTK = $hasHoTenKyTKCol ? ($it['ho_ten_nguoi_ky_truong_khoa'] ?? null) : null;
    if (empty(trim((string)$hoTenTK))) {
        $hoTenTK = $userNameMap[(int)($it['nguoi_cho_y_kien'] ?? 0)] ?? '';
    }
    if (empty(trim((string)$hoTenTK))) {
        $hoTenTK = $capChoHoTen;
    }

    $hoTenLD = $hasHoTenKyLDCol ? ($it['ho_ten_nguoi_ky_lanh_dao'] ?? null) : null;
    if (empty(trim((string)$hoTenLD))) {
        $hoTenLD = $userNameMap[(int)($it['nguoi_phe_duyet'] ?? 0)] ?? '';
    }
    if (empty(trim((string)$hoTenLD))) {
        $hoTenLD = $approverName;
    }

    return [
        'id'                       => (int)$it['id'],
        'ma_don'                   => $it['ma_don']   ?? '',
        'tu_ngay'                  => $it['tu_ngay']  ?? null,
        'den_ngay'                 => $it['den_ngay'] ?? null,
        'trang_thai'               => $it['trang_thai'] ?? '',

        'buoi_nghi'                => $caStart,
        'buoi_nghi_ket_thuc'       => $caEnd,
        'buoi_nghi_text'           => getBuoiNghiTextIn($caStart, $caEnd),
        'ca_nghi_label'            => caNghiShortLabelIn($caStart, $caEnd),
        'total_days_range'         => diffDaysInclusiveIn($it['tu_ngay'] ?? null, $it['den_ngay'] ?? null),
        'is_nghi_nua_buoi'         => isCaNghiSingleIn($caStart, $caEnd) && $caStart !== 'ca_ngay',

        'ly_do'                    => $it['ly_do'] ?? '',
        'so_ngay'                  => (float)($it['so_ngay'] ?? 0),
        'so_phep_con_lai_luc_nghi' => $it['so_phep_con_lai_luc_nghi'] !== null
                                        ? (float)$it['so_phep_con_lai_luc_nghi'] : null,
        'ngay_nop_nhan_su'         => $it['ngay_nop_nhan_su'] ?? null,
        'ngay_nop'                 => $it['ngay_nop']         ?? null,

        'chu_ky_nguoi_nghi'        => $it['chu_ky_nguoi_nghi']  ?? null,
        'ho_ten_nguoi_ky'          => $hoTenNguoiKy,
        'ten_nguoi_nghi'           => $currentLeave['ho_ten'] ?? '',

        'chu_ky_truong_khoa'       => $it['chu_ky_truong_khoa'] ?? null,
        'ten_truong_khoa'          => $hoTenTK,

        'chu_ky_lanh_dao'          => $it['chu_ky_lanh_dao']    ?? null,
        'ten_lanh_dao'             => $hoTenLD,

        'chuc_vu_label'            => mapChucVuLabel($currentLeave['chuc_vu'] ?? ''),
    ];
}, $allLeaves);

/* ============================================================
 * 19. RESPONSE
 * ============================================================ */
jsonSuccess([
    'leave'   => $leaveData,
    'cap_cho' => [
        'ho_ten'  => $capChoHoTen,
        'ma_nv'   => $capChoMaNV,
        'chuc_vu' => $capChoChucVu,
    ],
    'quota'      => $quota,
    'all_leaves' => $allLeavesData,

    'stats' => [
        'total_confirmed_leaves' => $totalConfirmed,
        'total_printed_leaves'   => $printedCount,
        'total_days_printed'     => round($totalDaysPrint, 1),
        'max_rows_per_page'      => MAX_ROWS_PER_PAGE,
        'max_days_quota'         => $quota['tong_phep'] ?? 12,
        'was_cut'                => $wasCut,
    ],

    'year'              => $year,
    'prev_year'         => $prevYear,
    'hospital_name'     => HOSPITAL_NAME,
    
    // ⭐ Các biến mới để HTML nhận diện
    'approver_title'    => $approverTitle, 
    'approver_name'     => $approverName,  
    
    // Vẫn giữ tương thích ngược
    'hospital_director' => $approverName, 
    'total_slots'       => $totalSlots,
]);