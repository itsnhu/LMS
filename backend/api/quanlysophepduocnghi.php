<?php
/**
 * ============================================================
 *  API: QUẢN LÝ ĐỊNH MỨC PHÉP NĂM (PHÒNG NHÂN SỰ)
 *  Endpoint: backend/api/quanlysophepduocnghi.php
 * ------------------------------------------------------------
 *  Routing (qua $_GET['action'] hoặc $_POST['action']):
 *    list          → Danh sách nhân viên + quota (mặc định) — GET
 *    departments   → Danh sách khoa phòng                    — GET
 *    update_quota  → Cập nhật định mức phép năm              — POST
 *    badges        → Đếm badge cho sidebar                   — GET
 * ============================================================
 */

require_once __DIR__ . '/../config/functions.php';

$action = $_GET['action'] ?? 'list';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
}

switch ($action) {

    case 'list':
        handleList();
        break;

    case 'departments':
        handleDepartments();
        break;

    case 'update_quota':
        handleUpdateQuota();
        break;

    case 'badges':
        handleBadges();
        break;

    default:
        jsonError('Action không hợp lệ!', 400);
}


/* ============================================================
 * HELPERS
 * ============================================================ */
function checkNhanSu(): array
{
    if (!isLoggedIn()) {
        jsonError('Vui lòng đăng nhập!', 401);
    }
    $user = getCurrentUser();
    $role = (string)($user['vai_tro'] ?? '');
    if (!in_array($role, ['nhan_su', 'nhansu', 'admin'], true)) {
        jsonError('Bạn không có quyền truy cập chức năng này!', 403);
    }
    return [$user, $role];
}


/* ============================================================
 * ACTION: LIST — Danh sách nhân viên + quota năm
 * ============================================================ */
function handleList(): void
{
    [$user, $role] = checkNhanSu();

    $db         = Database::getConnection();
    $yearFilter = (int)($_GET['nam'] ?? date('Y'));
    $khoaFilter = (int)($_GET['khoa_id'] ?? 0);
    $search     = trim($_GET['search'] ?? '');

    $sql = '
        SELECT nv.id AS nv_id, nv.ma_nv, nv.ho_ten, nv.chuc_vu, kp.ten_khoa,
               COALESCE(pn.id, 0) AS phep_id,
               COALESCE(pn.nam, ?) AS nam,
               COALESCE(pn.phep_nam, 12.0) AS phep_nam,
               COALESCE(pn.phep_tham_nien, 0.0) AS phep_tham_nien,
               COALESCE(pn.phep_chuyen_sang, 0.0) AS phep_chuyen_sang,
               COALESCE(pn.so_ngay_da_nghi, 0.0) AS so_ngay_da_nghi,
               pn.ghi_chu
        FROM nhan_vien nv
        JOIN khoa_phong kp ON nv.khoa_phong_id = kp.id
        LEFT JOIN phep_nam pn ON nv.id = pn.nhan_vien_id AND pn.nam = ?
        WHERE 1=1
    ';
    $params = [$yearFilter, $yearFilter];

    if ($khoaFilter > 0) {
        $sql .= ' AND nv.khoa_phong_id = ?';
        $params[] = $khoaFilter;
    }
    if ($search !== '') {
        $sql .= ' AND (nv.ho_ten LIKE ? OR nv.ma_nv LIKE ?)';
        $params[] = "%{$search}%";
        $params[] = "%{$search}%";
    }
    $sql .= ' ORDER BY kp.id ASC, nv.ho_ten ASC';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $list = $stmt->fetchAll();

    /* Tính tổng phép + còn lại */
    foreach ($list as &$row) {
        $tongPhep = (float)$row['phep_nam']
                  + (float)$row['phep_tham_nien']
                  + (float)$row['phep_chuyen_sang'];
        $daNghi = (float)$row['so_ngay_da_nghi'];
        $row['tong_phep']    = $tongPhep;
        $row['phep_con_lai'] = max(0, $tongPhep - $daNghi);
    }
    unset($row);

    /* Danh sách khoa phòng cho filter */
    $departments = $db->query('SELECT id, ten_khoa FROM khoa_phong ORDER BY ten_khoa ASC')->fetchAll();

    jsonSuccess([
        'list'         => $list,
        'departments'  => $departments,
        'filters'      => [
            'nam'     => $yearFilter,
            'khoa_id' => $khoaFilter,
            'search'  => $search,
        ],
        'current_year' => (int)date('Y'),
    ]);
}


/* ============================================================
 * ACTION: DEPARTMENTS
 * ============================================================ */
function handleDepartments(): void
{
    checkNhanSu();
    $db = Database::getConnection();
    $departments = $db->query('SELECT id, ten_khoa FROM khoa_phong ORDER BY ten_khoa ASC')->fetchAll();
    jsonSuccess(['departments' => $departments]);
}


/* ============================================================
 * ACTION: UPDATE QUOTA
 * ============================================================ */
function handleUpdateQuota(): void
{
    [$user, $role] = checkNhanSu();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonError('Method không hợp lệ!', 405);
    }

    $token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (function_exists('validateCsrfToken') && !validateCsrfToken($token)) {
        jsonError('CSRF token không hợp lệ!', 403);
    }

    $nhanVienId     = (int)($_POST['nhan_vien_id'] ?? 0);
    $nam            = (int)($_POST['nam'] ?? date('Y'));
    $phepNam        = (float)($_POST['phep_nam'] ?? 12.0);
    $phepThamNien   = (float)($_POST['phep_tham_nien'] ?? 0.0);
    $phepChuyenSang = (float)($_POST['phep_chuyen_sang'] ?? 0.0);
    $ghiChu         = trim($_POST['ghi_chu'] ?? '');

    if ($nhanVienId <= 0) jsonError('Thiếu ID nhân viên!', 400);
    if ($phepNam < 0 || $phepThamNien < 0 || $phepChuyenSang < 0) {
        jsonError('Định mức không được âm!', 422);
    }

    $db = Database::getConnection();

    /* Check employee tồn tại */
    $stmtChk = $db->prepare('SELECT id, ho_ten FROM nhan_vien WHERE id = ?');
    $stmtChk->execute([$nhanVienId]);
    $nv = $stmtChk->fetch();
    if (!$nv) jsonError('Không tìm thấy nhân viên!', 404);

    /* Check quota tồn tại */
    $stmtCheck = $db->prepare('SELECT id FROM phep_nam WHERE nhan_vien_id = ? AND nam = ?');
    $stmtCheck->execute([$nhanVienId, $nam]);
    $existing = $stmtCheck->fetch();

    try {
        if ($existing) {
            $stmtUp = $db->prepare('
                UPDATE phep_nam
                SET phep_nam = ?, phep_tham_nien = ?, phep_chuyen_sang = ?, ghi_chu = ?, updated_at = NOW()
                WHERE id = ?
            ');
            $stmtUp->execute([$phepNam, $phepThamNien, $phepChuyenSang, $ghiChu, $existing['id']]);
        } else {
            $stmtIns = $db->prepare('
                INSERT INTO phep_nam (nhan_vien_id, nam, phep_nam, phep_tham_nien, phep_chuyen_sang, so_ngay_da_nghi, ghi_chu)
                VALUES (?, ?, ?, ?, ?, 0.0, ?)
            ');
            $stmtIns->execute([$nhanVienId, $nam, $phepNam, $phepThamNien, $phepChuyenSang, $ghiChu]);
        }

        jsonSuccess([
            'nhan_vien_id' => $nhanVienId,
            'nam'          => $nam,
            'ho_ten'       => $nv['ho_ten'],
        ], "Cập nhật định mức phép năm {$nam} cho {$nv['ho_ten']} thành công!");

    } catch (Throwable $e) {
        error_log('[QuanLyPhep] update_quota error: ' . $e->getMessage());
        jsonError('Lỗi cập nhật: ' . $e->getMessage(), 500);
    }
}


/* ============================================================
 * ACTION: BADGES — Đếm badge cho sidebar
 * ============================================================ */
function handleBadges(): void
{
    [$user, $role] = checkNhanSu();
    $db = Database::getConnection();

    /* Đơn chờ nhân sự tiếp nhận */
    $pending = 0;
    try {
        $stmt = $db->prepare('SELECT COUNT(*) FROM nghi_phep WHERE trang_thai = ?');
        $stmt->execute(['da_duyet']);
        $pending = (int)$stmt->fetchColumn();
    } catch (Throwable $e) {}

    /* Đơn cá nhân của user */
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

    jsonSuccess([
        'badges' => [
            'pending_count'  => $pending,
            'personal_count' => $personal,
        ]
    ]);
}