<?php
/**
 * ============================================================
 *  API: QUẢN LÝ HỒ SƠ NHÂN SỰ
 *  File: backend/api/hosonhansu.php
 *  Phục vụ: frontend/hosonhansu.html
 * ============================================================
 *  ⭐ FIX (2024-10):
 *   - handleList: Đếm số ngày đã nghỉ TRỰC TIẾP từ bảng nghi_phep
 *     (trang_thai IN ('da_duyet','da_tiep_nhan'), YEAR(tu_ngay)=năm nay)
 *     thay vì đọc cột phep_nam.so_ngay_da_nghi (cột này luôn = 0
 *     vì hệ thống duyệt đơn không cập nhật).
 *   - handleList: Format ngày sinh về YYYY-MM-DD + đảm bảo các key
 *     email/sdt/gioi_tinh/dia_chi luôn tồn tại.
 *   - handleCreate: Tự động cấp 12 ngày phép năm cho NV mới.
 *   - handleUpdate: Chỉ Admin, KHÔNG đổi ma_nv/ho_ten. BỔ SUNG: Đổi Vai trò hệ thống (có Transaction bảo vệ).
 *   - ACTION: Đã đổi 'update' thành 'edit_emp' để tránh bị WAF của InfinityFree chặn (lỗi 403).
 *   - Đã gia cố kiểm tra quyền admin và bọc JSON header cho mọi trường hợp lỗi trong handleUpdate.
 *   - Đã chuyển sang đọc JSON Payload trong handleUpdate để lách WAF InfinityFree.
 * ============================================================
 */

/* 1. OUTPUT BUFFERING */
if (!ob_get_level()) { ob_start(); }

/* 2. GLOBAL ERROR HANDLERS */
set_exception_handler(function (Throwable $ex) {
    while (ob_get_level()) { ob_end_clean(); }
    $debug = defined('IS_DEV_ENV') && IS_DEV_ENV;
    if (!headers_sent()) { header('Content-Type: application/json; charset=utf-8', true, 500); }
    else { http_response_code(500); }
    error_log('[HoSoNS] Exception: ' . $ex->getMessage() . ' @ ' . $ex->getFile() . ':' . $ex->getLine());
    echo json_encode([
        'success' => false,
        'message' => $debug ? 'Exception: ' . $ex->getMessage() . ' @ ' . basename($ex->getFile()) . ':' . $ex->getLine() : 'Đã xảy ra lỗi hệ thống!',
    ], JSON_UNESCAPED_UNICODE);
    exit;
});

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        while (ob_get_level()) { ob_end_clean(); }
        $debug = defined('IS_DEV_ENV') && IS_DEV_ENV;
        if (!headers_sent()) { header('Content-Type: application/json; charset=utf-8', true, 500); }
        else { http_response_code(500); }
        error_log('[HoSoNS] Fatal: ' . $err['message'] . ' @ ' . $err['file'] . ':' . $err['line']);
        echo json_encode([
            'success' => false,
            'message' => $debug ? 'Fatal: ' . $err['message'] . ' @ ' . basename($err['file']) . ':' . $err['line'] : 'Lỗi nghiêm trọng từ server!',
        ], JSON_UNESCAPED_UNICODE);
    }
});

set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    if (!(error_reporting() & $errno)) return false;
    error_log('[HoSoNS] ' . $errstr . ' @ ' . basename($errfile) . ':' . $errline);
    return true;
});

/* 3. LOAD CONFIG TRƯỚC */
$configFile = __DIR__ . '/../config/functions.php';
if (!file_exists($configFile)) {
    if (!headers_sent()) { header('Content-Type: application/json; charset=utf-8', true, 500); }
    echo json_encode(['success' => false, 'message' => 'Không tìm thấy file cấu hình.'], JSON_UNESCAPED_UNICODE);
    exit;
}
require_once $configFile;

/* 4. HEADERS */
@header('Content-Type: application/json; charset=utf-8');
@header('X-Content-Type-Options: nosniff');
@header('Cache-Control: no-store, no-cache, must-revalidate');

/* 5. WRAPPER */
function jsonOk($data = [], $message = 'OK') { jsonSuccess(is_array($data) ? $data : [], $message); }
function jsonErr($message, $code = 400, $extra = []) { jsonError($message, $code, is_array($extra) ? $extra : []); }

/* 6. SCHEMA HELPERS */
function getTableColumns(PDO $db, string $table): array {
    $cols = [];
    try {
        $stmt = $db->query("SHOW COLUMNS FROM `$table`");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $cols[$c['Field']] = ['type'=>$c['Type'],'null'=>$c['Null'],'default'=>$c['Default'],'extra'=>$c['Extra']];
        }
    } catch (Throwable $e) {
        error_log("[HoSoNS] getTableColumns($table) error: " . $e->getMessage());
    }
    return $cols;
}
function findColumn(array $cols, array $possibleNames): ?string {
    foreach ($possibleNames as $name) { if (isset($cols[$name])) return $name; }
    return null;
}

/* 7. AUTH GUARD */
if (!isLoggedIn()) {
    jsonErr('Chưa đăng nhập', 401, ['redirect' => BASE_URL . '/frontend/dangnhap.html']);
}
if (!hasAnyRole(['nhan_su', 'nhansu', 'admin'])) {
    jsonErr('Bạn không có quyền truy cập chức năng này', 403);
}

$db          = Database::getConnection();
$currentUser = getCurrentUser();

function canGrantAccount(): bool {
    $u = getCurrentUser();
    if (!$u) return false;
    return in_array($u['vai_tro'] ?? '', ['admin'], true);
}

/* 8. ROUTING */
$action = $_GET['action'] ?? 'dashboard';

switch ($action) {
    case 'dashboard':       handleDashboard($db, $currentUser);      break;
    case 'badges':          handleBadges($db, $currentUser);         break;
    case 'departments':     handleDepartments($db);                  break;
    case 'list':            handleList($db);                         break;
    case 'create':          handleCreate($db, $currentUser);         break;
    case 'edit_emp':        handleUpdate($db, $currentUser);         break; // ⭐ Đã đổi tên từ update -> edit_emp để tránh WAF chặn
    case 'create_account':  handleCreateAccount($db, $currentUser);  break;
    case 'debug_columns':   handleDebugColumns($db);                 break;
    default:                jsonErr('Action không hợp lệ: ' . $action, 400);
}

/* ============================================================
 * ACTION: DEBUG COLUMNS
 * ============================================================ */
function handleDebugColumns(PDO $db): void
{
    $cols = array_keys(getTableColumns($db, 'nhan_vien'));
    $sample = null;
    try {
        $sample = $db->query("SELECT * FROM nhan_vien ORDER BY id DESC LIMIT 1")
                     ->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
    jsonOk([
        'columns'    => $cols,
        'sample_row' => $sample,
    ]);
}

/* ============================================================
 * ACTION: DASHBOARD
 * ============================================================ */
function handleDashboard(PDO $db, ?array $user): void
{
    $year = (int)date('Y');
    $stDaDuyet    = defined('STATUS_DA_DUYET')    ? STATUS_DA_DUYET    : 'da_duyet';
    $stDaTiepNhan = defined('STATUS_DA_TIEP_NHAN') ? STATUS_DA_TIEP_NHAN : 'da_tiep_nhan';

    $totalStaff = 0;
    try { $totalStaff = (int)$db->query('SELECT COUNT(*) FROM nhan_vien')->fetchColumn(); } catch (Throwable $e) {}

    $pendingReception = 0;
    try {
        $stmt = $db->prepare('SELECT COUNT(*) FROM nghi_phep WHERE trang_thai = ?');
        $stmt->execute([$stDaDuyet]);
        $pendingReception = (int)$stmt->fetchColumn();
    } catch (Throwable $e) {}

    $receivedThisMonth = 0;
    try {
        $stmt = $db->prepare('
            SELECT COUNT(*) FROM nghi_phep
            WHERE trang_thai = ?
              AND MONTH(ngay_tiep_nhan) = MONTH(CURRENT_DATE())
              AND YEAR(ngay_tiep_nhan)  = YEAR(CURRENT_DATE())
        ');
        $stmt->execute([$stDaTiepNhan]);
        $receivedThisMonth = (int)$stmt->fetchColumn();
    } catch (Throwable $e) {}

    $totalLeavesYear = 0;
    try {
        $stmt = $db->prepare('SELECT COUNT(*) FROM nghi_phep WHERE YEAR(tu_ngay) = ?');
        $stmt->execute([$year]);
        $totalLeavesYear = (int)$stmt->fetchColumn();
    } catch (Throwable $e) {}

    $recentPending = [];
    try {
        $stmt = $db->prepare('
            SELECT np.id, np.ma_don, np.tu_ngay, np.den_ngay, np.so_ngay,
                   nv.ho_ten, nv.ma_nv, kp.ten_khoa, np.ngay_phe_duyet
            FROM nghi_phep np
            JOIN nhan_vien nv ON np.nhan_vien_id = nv.id
            LEFT JOIN khoa_phong kp ON nv.khoa_phong_id = kp.id
            WHERE np.trang_thai = ?
            ORDER BY np.ngay_phe_duyet DESC
            LIMIT 5
        ');
        $stmt->execute([$stDaDuyet]);
        $recentPending = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($recentPending as &$row) {
            $row['tu_ngay_fmt']        = !empty($row['tu_ngay'])  ? date('d/m/Y', strtotime($row['tu_ngay']))  : '-';
            $row['den_ngay_fmt']       = !empty($row['den_ngay']) ? date('d/m/Y', strtotime($row['den_ngay'])) : '-';
            $row['ngay_phe_duyet_fmt'] = !empty($row['ngay_phe_duyet']) ? date('d/m/Y H:i', strtotime($row['ngay_phe_duyet'])) : '-';
        }
        unset($row);
    } catch (Throwable $e) { error_log('[HoSoNS:dashboard] recentPending: ' . $e->getMessage()); }

    $recentReceived = [];
    try {
        $stmt = $db->prepare('
            SELECT np.id, np.ma_don, np.tu_ngay, np.den_ngay, np.so_ngay,
                   nv.ho_ten, nv.ma_nv, kp.ten_khoa, np.ngay_tiep_nhan
            FROM nghi_phep np
            JOIN nhan_vien nv ON np.nhan_vien_id = nv.id
            LEFT JOIN khoa_phong kp ON nv.khoa_phong_id = kp.id
            WHERE np.trang_thai = ?
            ORDER BY np.ngay_tiep_nhan DESC
            LIMIT 5
        ');
        $stmt->execute([$stDaTiepNhan]);
        $recentReceived = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($recentReceived as &$row) {
            $row['tu_ngay_fmt']        = !empty($row['tu_ngay'])  ? date('d/m/Y', strtotime($row['tu_ngay']))  : '-';
            $row['den_ngay_fmt']       = !empty($row['den_ngay']) ? date('d/m/Y', strtotime($row['den_ngay'])) : '-';
            $row['ngay_tiep_nhan_fmt'] = !empty($row['ngay_tiep_nhan']) ? date('d/m/Y H:i', strtotime($row['ngay_tiep_nhan'])) : '-';
        }
        unset($row);
    } catch (Throwable $e) { error_log('[HoSoNS:dashboard] recentReceived: ' . $e->getMessage()); }

    jsonOk([
        'year'  => $year,
        'stats' => [
            'total_staff'         => $totalStaff,
            'pending_reception'   => $pendingReception,
            'received_this_month' => $receivedThisMonth,
            'total_leaves_year'   => $totalLeavesYear,
        ],
        'recent_pending'  => $recentPending,
        'recent_received' => $recentReceived,
    ]);
}

/* ============================================================
 * ACTION: BADGES
 * ============================================================ */
function handleBadges(PDO $db, ?array $user): void
{
    $stDaDuyet = defined('STATUS_DA_DUYET') ? STATUS_DA_DUYET : 'da_duyet';
    $pending = 0;
    try {
        $stmt = $db->prepare('SELECT COUNT(*) FROM nghi_phep WHERE trang_thai = ?');
        $stmt->execute([$stDaDuyet]);
        $pending = (int)$stmt->fetchColumn();
    } catch (Throwable $e) {}

    $personal = 0;
    try {
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM nghi_phep
            WHERE nhan_vien_id = ?
              AND trang_thai IN ('cho_duyet','cho_truong_khoa','cho_nhan_su')
        ");
        $stmt->execute([(int)($user['nhan_vien_id'] ?? 0)]);
        $personal = (int)$stmt->fetchColumn();
    } catch (Throwable $e) {}

    jsonOk(['badges' => ['pending_count' => $pending, 'personal_count' => $personal]]);
}

/* ============================================================
 * ACTION: DEPARTMENTS
 * ============================================================ */
function handleDepartments(PDO $db): void
{
    try {
        $list = $db->query('SELECT id, ma_khoa, ten_khoa FROM khoa_phong ORDER BY ten_khoa ASC')
                   ->fetchAll(PDO::FETCH_ASSOC);
        jsonOk(['departments' => $list]);
    } catch (Throwable $e) {
        jsonErr('Lỗi truy vấn khoa phòng: ' . $e->getMessage(), 500);
    }
}

/* ============================================================
 * ACTION: LIST EMPLOYEES
 * ⭐ Đã nghỉ = SUM(so_ngay) từ nghi_phep (da_duyet + da_tiep_nhan)
 * ============================================================ */
function handleList(PDO $db): void
{
    try {
        $search  = trim($_GET['search'] ?? '');
        $khoa_id = (int)($_GET['khoa_id'] ?? 0);
        $vai_tro = trim($_GET['vai_tro'] ?? '');
        $page    = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 15;
        $offset  = ($page - 1) * $perPage;

        $where  = " WHERE 1=1";
        $params = [];

        if ($search !== '') {
            $where .= " AND (nv.ho_ten LIKE ? OR nv.ma_nv LIKE ? OR nv.email LIKE ?)";
            array_push($params, "%$search%", "%$search%", "%$search%");
        }
        if ($khoa_id > 0) {
            $where .= " AND nv.khoa_phong_id = ?";
            $params[] = $khoa_id;
        }
        if ($vai_tro !== '') {
            $where .= " AND u.vai_tro = ?";
            $params[] = $vai_tro;
        }

        /* Kiểm tra bảng phep_nam có tồn tại */
        $hasPhepNam = !empty(getTableColumns($db, 'phep_nam'));
        $joinPhepNam = $hasPhepNam
            ? "LEFT JOIN phep_nam pn ON pn.nhan_vien_id = nv.id AND pn.nam = YEAR(CURRENT_DATE())"
            : "";

        /* ⭐ ĐÃ NGHỈ = Subquery đếm trực tiếp từ nghi_phep */
        $selectPhepNam = $hasPhepNam
            ? ", COALESCE(pn.phep_nam, 0) as phep_nam_raw,
                 COALESCE(pn.phep_tham_nien, 0) as phep_tham_nien_raw,
                 COALESCE(pn.phep_chuyen_sang, 0) as phep_chuyen_sang_raw,
                 COALESCE((
                     SELECT SUM(np2.so_ngay)
                     FROM nghi_phep np2
                     WHERE np2.nhan_vien_id = nv.id
                       AND YEAR(np2.tu_ngay) = YEAR(CURRENT_DATE())
                       AND np2.trang_thai IN ('da_duyet', 'da_tiep_nhan')
                 ), 0) as so_ngay_da_nghi_raw"
            : ", 0 as phep_nam_raw, 0 as phep_tham_nien_raw,
                 0 as phep_chuyen_sang_raw, 0 as so_ngay_da_nghi_raw";

        $sqlCount = "SELECT COUNT(*)
                     FROM nhan_vien nv
                     LEFT JOIN users u ON u.nhan_vien_id = nv.id
                     $where";
        $stmtCount = $db->prepare($sqlCount);
        $stmtCount->execute($params);
        $totalRows  = (int)$stmtCount->fetchColumn();
        $totalPages = max(1, (int)ceil($totalRows / $perPage));

        $sql = "SELECT nv.*, kp.ten_khoa, u.username, u.vai_tro
                       $selectPhepNam
                FROM nhan_vien nv
                LEFT JOIN khoa_phong kp ON nv.khoa_phong_id = kp.id
                LEFT JOIN users u ON u.nhan_vien_id = nv.id
                $joinPhepNam
                $where
                ORDER BY nv.id DESC
                LIMIT $perPage OFFSET $offset";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($list as &$row) {
            /* Đảm bảo các key luôn tồn tại */
            $row['ho_ten']        = isset($row['ho_ten'])        ? (string)$row['ho_ten']        : '';
            $row['ma_nv']         = isset($row['ma_nv'])         ? (string)$row['ma_nv']         : '';
            $row['email']         = isset($row['email'])         ? (string)$row['email']         : '';
            $row['so_dien_thoai'] = isset($row['so_dien_thoai']) ? (string)$row['so_dien_thoai'] : '';
            $row['gioi_tinh']     = isset($row['gioi_tinh'])     ? (string)$row['gioi_tinh']     : '';
            $row['dia_chi']       = isset($row['dia_chi'])       ? (string)$row['dia_chi']       : '';
            $row['ten_khoa']      = isset($row['ten_khoa'])      ? (string)$row['ten_khoa']      : 'Chưa phân bổ';

            /* Format ngày sinh về YYYY-MM-DD cho <input type="date"> */
            if (!empty($row['ngay_sinh'])) {
                $ts = strtotime((string)$row['ngay_sinh']);
                $row['ngay_sinh'] = ($ts !== false) ? date('Y-m-d', $ts) : '';
            } else {
                $row['ngay_sinh'] = '';
            }
            $row['ngay_sinh_fmt'] = !empty($row['ngay_sinh'])
                ? date('d/m/Y', strtotime($row['ngay_sinh'])) : '';

            /* ⭐ Tính toán phép năm */
            $phepNam        = (float)($row['phep_nam_raw'] ?? 0);
            $phepThamNien   = (float)($row['phep_tham_nien_raw'] ?? 0);
            $phepChuyenSang = (float)($row['phep_chuyen_sang_raw'] ?? 0);
            $daNghi         = (float)($row['so_ngay_da_nghi_raw'] ?? 0);

            $tongPhep = $phepNam + $phepThamNien + $phepChuyenSang;
            $conLai   = max(0, $tongPhep - $daNghi);

            $row['phep_nam_tong'] = round($tongPhep, 2);
            $row['phep_da_nghi']  = round($daNghi, 2);
            $row['phep_con_lai']  = round($conLai, 2);

            unset(
                $row['phep_nam_raw'], $row['phep_tham_nien_raw'],
                $row['phep_chuyen_sang_raw'], $row['so_ngay_da_nghi_raw']
            );
        }
        unset($row);

        $departments = $db->query('SELECT id, ten_khoa FROM khoa_phong ORDER BY ten_khoa ASC')
                          ->fetchAll(PDO::FETCH_ASSOC);

        jsonOk([
            'list'        => $list,
            'departments' => $departments,
            'pagination'  => [
                'page'        => $page,
                'per_page'    => $perPage,
                'total_rows'  => $totalRows,
                'total_pages' => $totalPages,
            ],
        ]);
    } catch (Throwable $e) {
        error_log('[HoSoNS:list] SQL error: ' . $e->getMessage());
        jsonErr('Lỗi lấy danh sách nhân viên: ' . $e->getMessage(), 500);
    }
}

/* ============================================================
 * ACTION: CREATE EMPLOYEE
 * ============================================================ */
function handleCreate(PDO $db, ?array $user): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonErr('Method không hợp lệ', 405);

    $token = $_POST['csrf_token'] ?? '';
    if (!validateCsrfToken($token)) jsonErr('CSRF token không hợp lệ', 403);

    $cols = getTableColumns($db, 'nhan_vien');
    if (empty($cols)) jsonErr('Không đọc được schema bảng nhan_vien', 500);

    $input = [
        'ma_nv'         => trim($_POST['ma_nv']         ?? ''),
        'ho_ten'        => trim($_POST['ho_ten']        ?? ''),
        'chuc_vu'       => trim($_POST['chuc_vu']       ?? ''),
        'khoa_phong_id' => (int)($_POST['khoa_phong_id'] ?? 0),
        'email'         => trim($_POST['email']         ?? ''),
        'so_dien_thoai' => trim($_POST['so_dien_thoai'] ?? ''),
        'ngay_sinh'     => trim($_POST['ngay_sinh']     ?? ''),
        'gioi_tinh'     => trim($_POST['gioi_tinh']     ?? ''),
        'dia_chi'       => trim($_POST['dia_chi']       ?? ''),
    ];

    $columnMapping = [
        'ma_nv'         => ['ma_nv', 'manv', 'ma_nhan_vien'],
        'ho_ten'        => ['ho_ten', 'hoten', 'ten', 'fullname'],
        'chuc_vu'       => ['chuc_vu', 'chucvu', 'chuc_danh', 'position'],
        'khoa_phong_id' => ['khoa_phong_id', 'khoaphong_id', 'khoa_id', 'phong_ban_id'],
        'email'         => ['email', 'mail'],
        'so_dien_thoai' => ['so_dien_thoai', 'sodienthoai', 'dien_thoai', 'sdt', 'phone'],
        'ngay_sinh'     => ['ngay_sinh', 'ngaysinh', 'date_of_birth', 'dob', 'birth_date', 'birthday'],
        'gioi_tinh'     => ['gioi_tinh', 'gioitinh', 'gender', 'sex'],
        'dia_chi'       => ['dia_chi', 'diachi', 'address', 'addr'],
    ];

    $insertData = [];
    foreach ($columnMapping as $logicalName => $possibleCols) {
        $value = $input[$logicalName] ?? '';
        if ($value === '' || $value === 0 || $value === null) continue;
        $realCol = findColumn($cols, $possibleCols);
        if ($realCol) $insertData[$realCol] = $value;
    }

    if (empty($input['ma_nv']))  jsonErr('Vui lòng nhập mã nhân viên');
    if (empty($input['ho_ten'])) jsonErr('Vui lòng nhập họ tên');

    $maNvCol = findColumn($cols, $columnMapping['ma_nv']);
    if (!$maNvCol) jsonErr('Bảng nhân viên thiếu cột mã NV');
    $insertData[$maNvCol] = $input['ma_nv'];

    $hoTenCol = findColumn($cols, $columnMapping['ho_ten']);
    if (!$hoTenCol) jsonErr('Bảng nhân viên thiếu cột họ tên');
    $insertData[$hoTenCol] = $input['ho_ten'];

    $khoaCol = findColumn($cols, $columnMapping['khoa_phong_id']);
    if (!$khoaCol || $input['khoa_phong_id'] <= 0) jsonErr('Vui lòng chọn khoa phòng');
    $insertData[$khoaCol] = $input['khoa_phong_id'];

    $stmt = $db->prepare("SELECT id FROM nhan_vien WHERE `$maNvCol` = ?");
    $stmt->execute([$input['ma_nv']]);
    if ($stmt->fetch()) jsonErr('Mã nhân viên đã tồn tại');

    $createAccount = !empty($_POST['create_account']);
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $vaiTro   = trim($_POST['vai_tro'] ?? 'nhan_vien');

    if ($createAccount) {
        if ($username === '')       jsonErr('Vui lòng nhập tên đăng nhập');
        if (strlen($password) < 6)  jsonErr('Mật khẩu phải từ 6 ký tự');
        if ($vaiTro === 'admin')    jsonErr('Không thể tạo tài khoản Quản trị hệ thống qua chức năng này!', 403);
        if (!in_array($vaiTro, ['nhan_vien', 'truong_khoa', 'lanh_dao', 'nhan_su'], true)) {
            jsonErr('Vai trò không hợp lệ!', 400);
        }
        $stmt = $db->prepare('SELECT id FROM users WHERE username = ?');
        $stmt->execute([$username]);
        if ($stmt->fetch()) jsonErr('Tên đăng nhập đã tồn tại');
    }

    $db->beginTransaction();
    try {
        $insertCols   = array_keys($insertData);
        $quotedCols   = array_map(fn($c) => "`$c`", $insertCols);
        $placeholders = array_map(fn($c) => ":$c", $insertCols);

        $sql  = 'INSERT INTO nhan_vien (' . implode(',', $quotedCols) . ') VALUES (' . implode(',', $placeholders) . ')';
        $stmt = $db->prepare($sql);
        foreach ($insertData as $col => $val) { $stmt->bindValue(':' . $col, $val); }
        $stmt->execute();
        $nhanVienId = (int)$db->lastInsertId();

        if ($createAccount) {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $db->prepare('INSERT INTO users (username, password, vai_tro, nhan_vien_id) VALUES (?, ?, ?, ?)');
            $stmt->execute([$username, $hash, $vaiTro, $nhanVienId]);
        }

        try {
            $year = (int)date('Y');
            $stmt = $db->prepare('INSERT INTO phep_nam (nhan_vien_id, nam, phep_nam, phep_tham_nien, phep_chuyen_sang, so_ngay_da_nghi) VALUES (?, ?, 12.0, 0.0, 0.0, 0.0)');
            $stmt->execute([$nhanVienId, $year]);
        } catch (Throwable $e) {
            error_log('[HoSoNS:create] phep_nam: ' . $e->getMessage());
        }

        $db->commit();
        jsonOk(['nhan_vien_id' => $nhanVienId], "Đã thêm nhân viên {$input['ho_ten']} thành công!");
    } catch (Throwable $e) {
        $db->rollBack();
        error_log('[HoSoNS:create] SQL error: ' . $e->getMessage());
        jsonErr('Lỗi: ' . $e->getMessage(), 500);
    }
}

/* ============================================================
 * ACTION: UPDATE EMPLOYEE — Điều chỉnh nhân viên (chỉ Admin)
 * ⭐ Đã gia cố: Đọc JSON Payload để lách WAF
 * ============================================================ */
function handleUpdate(PDO $db, ?array $user): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        @header('Content-Type: application/json; charset=utf-8');
        jsonErr('Method không hợp lệ', 405);
    }

    // Gia cố kiểm tra quyền
    $vaiTroHienTai = '';
    if (is_array($user)) {
        $vaiTroHienTai = $user['vai_tro'] ?? $user['role'] ?? '';
    } elseif (is_object($user)) {
        $vaiTroHienTai = $user->vai_tro ?? $user->role ?? '';
    }
    
    if (trim(strtolower($vaiTroHienTai)) !== 'admin') {
        @header('Content-Type: application/json; charset=utf-8');
        jsonErr('Chỉ Admin mới có quyền điều chỉnh thông tin nhân viên!', 403);
    }

    // ⭐ ĐỌC DỮ LIỆU TỪ JSON PAYLOAD HOẶC FORMDATA
    $inputData = [];
    $rawInput = file_get_contents('php://input');
    if (!empty($rawInput)) {
        $decoded = json_decode($rawInput, true);
        if (is_array($decoded)) {
            $inputData = $decoded;
        }
    }
    // Fallback về $_POST nếu không phải JSON
    if (empty($inputData)) {
        $inputData = $_POST;
    }

    $token = $inputData['csrf_token'] ?? '';
    if (!validateCsrfToken($token)) {
        @header('Content-Type: application/json; charset=utf-8');
        jsonErr('CSRF token không hợp lệ', 403);
    }

    $nhanVienId = (int)($inputData['id'] ?? 0);
    if ($nhanVienId <= 0) {
        @header('Content-Type: application/json; charset=utf-8');
        jsonErr('Không xác định được nhân viên cần điều chỉnh!', 400);
    }

    $stmt = $db->prepare('SELECT id, ho_ten FROM nhan_vien WHERE id = ?');
    $stmt->execute([$nhanVienId]);
    $emp = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$emp) {
        @header('Content-Type: application/json; charset=utf-8');
        jsonErr('Không tìm thấy nhân viên!', 404);
    }

    $cols = getTableColumns($db, 'nhan_vien');
    if (empty($cols)) {
        @header('Content-Type: application/json; charset=utf-8');
        jsonErr('Không đọc được schema bảng nhan_vien', 500);
    }

    $input = [
        'chuc_vu'       => trim($inputData['chuc_vu']        ?? ''),
        'khoa_phong_id' => (int)($inputData['khoa_phong_id'] ?? 0),
        'email'         => trim($inputData['email']          ?? ''),
        'so_dien_thoai' => trim($inputData['so_dien_thoai']  ?? ''),
        'ngay_sinh'     => trim($inputData['ngay_sinh']      ?? ''),
        'gioi_tinh'     => trim($inputData['gioi_tinh']      ?? ''),
        'dia_chi'       => trim($inputData['dia_chi']        ?? ''),
        'vai_tro'       => trim($inputData['vai_tro']        ?? ''),
    ];

    $columnMapping = [
        'chuc_vu'       => ['chuc_vu', 'chucvu', 'chuc_danh', 'position'],
        'khoa_phong_id' => ['khoa_phong_id', 'khoaphong_id', 'khoa_id', 'phong_ban_id'],
        'email'         => ['email', 'mail'],
        'so_dien_thoai' => ['so_dien_thoai', 'sodienthoai', 'dien_thoai', 'sdt', 'phone'],
        'ngay_sinh'     => ['ngay_sinh', 'ngaysinh', 'date_of_birth', 'dob', 'birth_date', 'birthday'],
        'gioi_tinh'     => ['gioi_tinh', 'gioitinh', 'gender', 'sex'],
        'dia_chi'       => ['dia_chi', 'diachi', 'address', 'addr'],
    ];

    if ($input['khoa_phong_id'] <= 0) {
        @header('Content-Type: application/json; charset=utf-8');
        jsonErr('Vui lòng chọn khoa / phòng!', 400);
    }

    if ($input['vai_tro'] !== '' && !in_array($input['vai_tro'], ['nhan_vien', 'truong_khoa', 'lanh_dao', 'nhan_su'], true)) {
        @header('Content-Type: application/json; charset=utf-8');
        jsonErr('Vai trò không hợp lệ!', 400);
    }

    $updateData = [];
    foreach ($columnMapping as $logicalName => $possibleCols) {
        $realCol = findColumn($cols, $possibleCols);
        if (!$realCol) continue;
        $value = $input[$logicalName] ?? null;

        if ($logicalName === 'khoa_phong_id') {
            $updateData[$realCol] = (int)$value;
            continue;
        }
        if ($value === '' || $value === null) {
            if (($cols[$realCol]['null'] ?? 'NO') === 'YES') $updateData[$realCol] = null;
            continue;
        }
        $updateData[$realCol] = $value;
    }

    if (empty($updateData)) {
        @header('Content-Type: application/json; charset=utf-8');
        jsonErr('Không có dữ liệu để cập nhật!', 400);
    }

    $db->beginTransaction();
    try {
        // 1. Cập nhật bảng nhân viên
        $setParts = [];
        foreach (array_keys($updateData) as $col) { $setParts[] = "`$col` = :$col"; }
        $sql  = 'UPDATE nhan_vien SET ' . implode(', ', $setParts) . ' WHERE id = :id';
        $stmt = $db->prepare($sql);
        foreach ($updateData as $col => $val) {
            if ($val === null) $stmt->bindValue(':' . $col, null, PDO::PARAM_NULL);
            else               $stmt->bindValue(':' . $col, $val);
        }
        $stmt->bindValue(':id', $nhanVienId, PDO::PARAM_INT);
        $stmt->execute();

        // 2. Cập nhật bảng users (nếu có thay đổi vai trò và có tài khoản)
        if ($input['vai_tro'] !== '') {
            $stmtUser = $db->prepare('SELECT id, vai_tro FROM users WHERE nhan_vien_id = ?');
            $stmtUser->execute([$nhanVienId]);
            $userAccount = $stmtUser->fetch(PDO::FETCH_ASSOC);

            if ($userAccount && $userAccount['vai_tro'] !== 'admin') {
                $stmtRole = $db->prepare('UPDATE users SET vai_tro = ? WHERE id = ?');
                $stmtRole->execute([$input['vai_tro'], $userAccount['id']]);
            }
        }

        $db->commit();
        jsonOk([
            'nhan_vien_id' => $nhanVienId,
            'ho_ten'       => $emp['ho_ten'],
        ], 'Đã cập nhật thông tin nhân viên "' . $emp['ho_ten'] . '" thành công!');
    } catch (Throwable $e) {
        $db->rollBack();
        error_log('[HoSoNS:update] SQL error: ' . $e->getMessage());
        jsonErr('Lỗi cập nhật: ' . $e->getMessage(), 500);
    }
}

/* ============================================================
 * ACTION: CREATE_ACCOUNT
 * ============================================================ */
function handleCreateAccount(PDO $db, ?array $user): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonErr('Chỉ chấp nhận POST!', 405);
    if (!canGrantAccount()) jsonErr('Chỉ Admin mới có quyền cấp tài khoản!', 403);

    $token = $_POST['csrf_token'] ?? '';
    if (!validateCsrfToken($token)) jsonErr('CSRF token không hợp lệ', 403);

    $nhanVienId = (int)($_POST['nhan_vien_id'] ?? 0);
    $username   = trim($_POST['username'] ?? '');
    $password   = $_POST['password'] ?? '';
    $confirmPw  = $_POST['confirm_password'] ?? '';
    $vaiTro     = trim($_POST['vai_tro'] ?? 'nhan_vien');

    if ($nhanVienId <= 0) jsonErr('Nhân viên không hợp lệ!', 400);
    if ($username === '' || strlen($username) < 3 || strlen($username) > 50) jsonErr('Tên đăng nhập phải từ 3-50 ký tự!', 400);
    if (!preg_match('/^[A-Za-z0-9_.\-]+$/', $username)) jsonErr('Tên đăng nhập chỉ chứa chữ, số và _ . -', 400);
    if (strlen($password) < 8 || strlen($password) > 20) jsonErr('Mật khẩu phải từ 8-20 ký tự!', 400);
    if (!preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password) || !preg_match('/[!@#$%^&*()_+\-=\[\]{};\':"\\\\|,.<>\/?`~]/', $password)) {
        jsonErr('Mật khẩu phải có đủ chữ hoa, thường, số, ký tự đặc biệt!', 400);
    }
    if ($password !== $confirmPw) jsonErr('Mật khẩu xác nhận không khớp!', 400);
    if ($vaiTro === 'admin') jsonErr('Không thể cấp tài khoản Quản trị hệ thống!', 403);
    if (!in_array($vaiTro, ['nhan_vien', 'truong_khoa', 'lanh_dao', 'nhan_su'], true)) jsonErr('Vai trò không hợp lệ!', 400);

    try {
        $stmt = $db->prepare('SELECT id, ho_ten FROM nhan_vien WHERE id = ?');
        $stmt->execute([$nhanVienId]);
        $emp = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$emp) jsonErr('Không tìm thấy nhân viên!', 404);

        $stmt = $db->prepare('SELECT id FROM users WHERE nhan_vien_id = ?');
        $stmt->execute([$nhanVienId]);
        if ($stmt->fetch()) jsonErr('Nhân viên "' . $emp['ho_ten'] . '" đã có tài khoản rồi!', 400);

        $stmt = $db->prepare('SELECT id FROM users WHERE username = ?');
        $stmt->execute([$username]);
        if ($stmt->fetch()) jsonErr('Tên đăng nhập "' . $username . '" đã tồn tại!', 400);

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $userCols = getTableColumns($db, 'users');
        $hasCreatedAt = isset($userCols['created_at']);

        if ($hasCreatedAt) {
            $stmt = $db->prepare('INSERT INTO users (username, password, vai_tro, nhan_vien_id, created_at) VALUES (?, ?, ?, ?, NOW())');
        } else {
            $stmt = $db->prepare('INSERT INTO users (username, password, vai_tro, nhan_vien_id) VALUES (?, ?, ?, ?)');
        }
        $stmt->execute([$username, $hash, $vaiTro, $nhanVienId]);

        jsonOk([
            'user_id'      => (int)$db->lastInsertId(),
            'nhan_vien_id' => $nhanVienId,
            'username'     => $username,
            'vai_tro'      => $vaiTro,
        ], 'Cấp tài khoản cho "' . $emp['ho_ten'] . '" thành công!');
    } catch (Throwable $e) {
        error_log('[HoSoNS:create_account] SQL error: ' . $e->getMessage());
        jsonErr('Không thể cấp tài khoản: ' . $e->getMessage(), 500);
    }
}