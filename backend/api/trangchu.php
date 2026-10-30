<?php
/**
 * ============================================================
 *  API XỬ LÝ DỮ LIỆU TRANG CHỦ (HOME / DASHBOARD)
 *  BỆNH VIỆN ĐA KHOA TÂM TRÍ ĐỒNG THÁP
 * ------------------------------------------------------------
 *  Routing (qua $_GET['action'] hoặc $_POST['action'] hoặc JSON):
 *    check           → Kiểm tra đăng nhập + lấy user/employee
 *    dashboard       → Stats + danh sách đơn nghỉ phép (mặc định)
 *    cancel          → HỦY ĐƠN trực tiếp (POST, cần id)
 *    request_cancel  → GỬI YÊU CẦU HỦY đơn (POST, cần id)
 *    approve_cancel  → ⭐ TK DUYỆT HỦY đơn (POST, cần id)
 *    reject_cancel   → ⭐ TK TỪ CHỐI HỦY đơn (POST, cần id)
 *    notifications   → Danh sách thông báo CHƯA ĐỌC
 *    mark_read       → Đánh dấu 1 thông báo đã đọc (POST)
 *    mark_all_read   → Đánh dấu tất cả thông báo đã đọc (POST)
 * ============================================================
 */

@header('Content-Type: application/json; charset=utf-8');
@header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../config/functions.php';

// ⭐ Lấy dữ liệu từ JSON Body để lách WAF
$rawInput = file_get_contents('php://input');
$jsonInput = json_decode($rawInput, true) ?: [];

$action = $_GET['action'] ?? $_POST['action'] ?? $jsonInput['action'] ?? 'dashboard';

switch ($action) {

    case 'check':
        handleCheck();
        break;

    case 'dashboard':
        handleDashboard();
        break;

    case 'cancel':
        handleCancel();
        break;

    // ⭐ GỬI YÊU CẦU HỦY (chuyển trạng thái sang cho_huy)
    case 'request_cancel':
        handleRequestCancel();
        break;

    // ⭐ TK DUYỆT HỦY ĐƠN
    case 'approve_cancel':
        handleApproveCancel();
        break;

    // ⭐ TK TỪ CHỐI HỦY ĐƠN
    case 'reject_cancel':
        handleRejectCancel();
        break;

    default:
        jsonError('Hành động không hợp lệ!', 400);
}


/* ============================================================
 * ACTION: CHECK
 * ============================================================ */
function handleCheck(): void
{
    if (!isLoggedIn()) {
        jsonResponse([
            'success'   => false,
            'logged_in' => false,
        ], 200);
    }

    $user = getCurrentUser();
    $emp  = getCurrentEmployee();

    jsonSuccess([
        'logged_in' => true,
        'user'      => [
            'id'           => $user['id']           ?? null,
            'username'     => $user['username']     ?? null,
            'vai_tro'      => $user['vai_tro']      ?? 'nhan_vien',
            'nhan_vien_id' => $user['nhan_vien_id'] ?? null,
            'ho_ten'       => $user['ho_ten']       ?? null,
        ],
        'employee'  => $emp ? [
            'id'            => $emp['id']            ?? null,
            'ho_ten'        => $emp['ho_ten']        ?? null,
            'ma_nv'         => $emp['ma_nv']         ?? null,
            'email'         => $emp['email']         ?? null,
            'so_dien_thoai' => $emp['so_dien_thoai'] ?? null,
            'ngay_sinh'     => $emp['ngay_sinh']     ?? null,
            'chuc_vu'       => $emp['chuc_vu']       ?? null,
            'ten_khoa'      => $emp['ten_khoa']      ?? null,
            'ma_khoa'       => $emp['ma_khoa']       ?? null,
        ] : null,
    ]);
}


/* ============================================================
 * ACTION: DASHBOARD
 * ============================================================ */
function handleDashboard(): void
{
    if (!isLoggedIn()) {
        jsonError('Vui lòng đăng nhập!', 401);
    }

    $user  = getCurrentUser();
    $emp   = getCurrentEmployee();

    $userId      = (int)($user['id']           ?? 0);
    $nhanVienId  = (int)($user['nhan_vien_id'] ?? 0);
    $role        = (string)($user['vai_tro']   ?? 'nhan_vien');
    $currentYear = defined('CURRENT_YEAR') ? CURRENT_YEAR : (int)date('Y');

    $db = Database::getConnection();

    if (in_array($role, ['truong_khoa', 'truongkhoa'], true)) {
        if (empty($emp['khoa_phong_id']) && $nhanVienId > 0) {
            try {
                $stmtKhoa = $db->prepare("SELECT khoa_phong_id FROM nhan_vien WHERE id = ?");
                $stmtKhoa->execute([$nhanVienId]);
                $emp['khoa_phong_id'] = (int)$stmtKhoa->fetchColumn();
            } catch (Throwable $e) {
                error_log('[TrangChu] Fetch khoa_phong_id error: ' . $e->getMessage());
            }
        }
    }

    $page    = max(1, (int)($_GET['page'] ?? 1));
    $perPage = 10;
    $offset  = ($page - 1) * $perPage;

    [$whereSql, $params] = buildLeavesWhere($role, $nhanVienId, $emp);

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
        error_log('[TrangChu] Count error: ' . $e->getMessage());
    }

    $totalPages = max(1, (int)ceil($totalRows / $perPage));
    if ($page > $totalPages) {
        $page   = $totalPages;
        $offset = ($page - 1) * $perPage;
    }

    $recentLeaves = [];
    try {
        $sql = "
            SELECT np.id, np.ma_don, np.tu_ngay, np.den_ngay, np.so_ngay,
                   np.trang_thai, np.created_at,
                   nv.ho_ten, nv.ma_nv, nv.chuc_vu,
                   kp.ten_khoa
            FROM nghi_phep np
            JOIN nhan_vien nv ON np.nhan_vien_id = nv.id
            LEFT JOIN khoa_phong kp ON nv.khoa_phong_id = kp.id
            $whereSql
            ORDER BY np.created_at DESC
            LIMIT $perPage OFFSET $offset
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $recentLeaves = $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log('[TrangChu] Fetch leaves error: ' . $e->getMessage());
    }

    $stats = buildStats($nhanVienId, $currentYear, $role, $emp);

    jsonSuccess([
        'year'     => $currentYear,
        'user'     => [
            'id'           => $userId,
            'username'     => $user['username']     ?? null,
            'vai_tro'      => $role,
            'nhan_vien_id' => $nhanVienId ?: null,
        ],
        'employee' => $emp ? [
            'id'            => $emp['id']            ?? null,
            'ho_ten'        => $emp['ho_ten']        ?? null,
            'ma_nv'         => $emp['ma_nv']         ?? null,
            'email'         => $emp['email']         ?? null,
            'so_dien_thoai' => $emp['so_dien_thoai'] ?? null,
            'ngay_sinh'     => $emp['ngay_sinh']     ?? null,
            'chuc_vu'       => $emp['chuc_vu']       ?? null,
            'ten_khoa'      => $emp['ten_khoa']      ?? null,
            'ma_khoa'       => $emp['ma_khoa']       ?? null,
        ] : null,
        'stats'         => $stats,
        'recent_leaves' => array_map('normalizeLeaveRow', $recentLeaves),
        'pagination'    => [
            'page'        => $page,
            'per_page'    => $perPage,
            'total_rows'  => $totalRows,
            'total_pages' => $totalPages,
        ],
    ]);
}


/* ============================================================
 * ACTION: CANCEL — Hủy trực tiếp
 * ============================================================ */
function handleCancel(): void
{
    global $jsonInput;
    
    if (!isLoggedIn()) {
        jsonError('Vui lòng đăng nhập!', 401);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonError('Phương thức không hợp lệ! Chỉ chấp nhận POST.', 405);
    }

    $user       = getCurrentUser();
    $nhanVienId = (int)($user['nhan_vien_id'] ?? 0);

    if ($nhanVienId <= 0) {
        jsonError('Không xác định được nhân viên!', 401);
    }

    $id = (int)($jsonInput['id'] ?? $_POST['id'] ?? 0);
    if ($id <= 0) {
        jsonError('ID đơn không hợp lệ!', 400);
    }

    $db = Database::getConnection();

    try {
        $stmt = $db->prepare("
            SELECT trang_thai 
            FROM nghi_phep 
            WHERE id = ? AND nhan_vien_id = ?
        ");
        $stmt->execute([$id, $nhanVienId]);
        $row = $stmt->fetch();

        if (!$row) {
            jsonError('Không tìm thấy đơn hoặc bạn không có quyền hủy.', 404);
        }

        $currentStatus = $row['trang_thai'];

        $allowedStatuses = [
            defined('STATUS_CHO_TRUONG_KHOA') ? STATUS_CHO_TRUONG_KHOA : 'cho_truong_khoa',
            defined('STATUS_CHO_PHE_DUYET')   ? STATUS_CHO_PHE_DUYET   : 'cho_phe_duyet',
            defined('STATUS_DA_DUYET')        ? STATUS_DA_DUYET        : 'da_duyet',
        ];

        if (!in_array($currentStatus, $allowedStatuses, true)) {
            jsonError('Trạng thái đơn không cho phép hủy.', 400);
        }

        $newStatus = defined('STATUS_DA_HUY') ? STATUS_DA_HUY : 'da_huy';

        $stmtUpdate = $db->prepare("
            UPDATE nghi_phep 
            SET trang_thai = ? 
            WHERE id = ?
        ");
        $stmtUpdate->execute([$newStatus, $id]);

        jsonSuccess([
            'id'         => $id,
            'trang_thai' => $newStatus,
        ], 'Hủy đơn thành công.');

    } catch (Throwable $e) {
        error_log('[TrangChu] cancel error: ' . $e->getMessage());
        jsonError('Không thể hủy đơn. Vui lòng thử lại sau.', 500);
    }
}


/* ============================================================
 * ACTION: REQUEST_CANCEL — Nhân viên gửi YÊU CẦU HỦY
 * ============================================================ */
function handleRequestCancel(): void
{
    global $jsonInput;

    if (!isLoggedIn()) {
        jsonError('Vui lòng đăng nhập!', 401);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonError('Phương thức không hợp lệ! Chỉ chấp nhận POST.', 405);
    }

    $user       = getCurrentUser();
    $nhanVienId = (int)($user['nhan_vien_id'] ?? 0);

    if ($nhanVienId <= 0) {
        jsonError('Không xác định được nhân viên!', 401);
    }

    $id = (int)($jsonInput['id'] ?? $_POST['id'] ?? 0);
    if ($id <= 0) {
        jsonError('ID đơn không hợp lệ!', 400);
    }

    $db = Database::getConnection();

    try {
        $stmt = $db->prepare("SELECT trang_thai FROM nghi_phep WHERE id = ? AND nhan_vien_id = ?");
        $stmt->execute([$id, $nhanVienId]);
        $row = $stmt->fetch();

        if (!$row) {
            jsonError('Không tìm thấy đơn hoặc bạn không có quyền thao tác.', 404);
        }

        $currentStatus   = $row['trang_thai'];
        $allowedStatuses = ['cho_truong_khoa', 'cho_phe_duyet', 'da_duyet'];

        if (!in_array($currentStatus, $allowedStatuses, true)) {
            jsonError('Trạng thái đơn không cho phép gửi yêu cầu hủy.', 400);
        }

        $newStatus = 'cho_huy';
        $stmtUpdate = $db->prepare("UPDATE nghi_phep SET trang_thai = ? WHERE id = ?");
        $stmtUpdate->execute([$newStatus, $id]);

        jsonSuccess([
            'id'         => $id,
            'trang_thai' => $newStatus,
        ], 'Đã gửi yêu cầu hủy đơn. Vui lòng chờ Trưởng khoa phê duyệt.');

    } catch (Throwable $e) {
        error_log('[TrangChu] request_cancel error: ' . $e->getMessage());
        jsonError('Không thể gửi yêu cầu hủy. Vui lòng thử lại sau.', 500);
    }
}


/* ============================================================
 * ⭐ ACTION: APPROVE_CANCEL — Trưởng khoa duyệt hủy đơn
 *    → Đơn chuyển sang 'da_huy'
 * ============================================================ */
function handleApproveCancel(): void
{
    global $jsonInput;

    if (!isLoggedIn()) {
        jsonError('Vui lòng đăng nhập!', 401);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonError('Phương thức không hợp lệ! Chỉ chấp nhận POST.', 405);
    }

    $user = getCurrentUser();
    $role = (string)($user['vai_tro'] ?? '');

    if (!in_array($role, ['truong_khoa', 'truongkhoa', 'admin'], true)) {
        jsonError('Chỉ Trưởng khoa mới có quyền duyệt hủy đơn!', 403);
    }

    $id = (int)($jsonInput['id'] ?? $_POST['id'] ?? 0);
    if ($id <= 0) {
        jsonError('ID đơn không hợp lệ!', 400);
    }

    $db = Database::getConnection();

    try {
        $stmt = $db->prepare("
            SELECT np.trang_thai, nv.khoa_phong_id
            FROM nghi_phep np
            JOIN nhan_vien nv ON np.nhan_vien_id = nv.id
            WHERE np.id = ?
        ");
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        if (!$row) {
            jsonError('Không tìm thấy đơn!', 404);
        }

        if ($row['trang_thai'] !== 'cho_huy') {
            jsonError('Đơn không ở trạng thái "Chờ hủy"!', 400);
        }

        if ($role !== 'admin') {
            $emp = getCurrentEmployee();
            $khoaId = (int)($emp['khoa_phong_id'] ?? 0);
            if ($khoaId > 0 && (int)$row['khoa_phong_id'] !== $khoaId) {
                jsonError('Bạn không có quyền duyệt đơn của khoa khác!', 403);
            }
        }

        $stmtUpdate = $db->prepare("
            UPDATE nghi_phep 
            SET trang_thai = 'da_huy' 
            WHERE id = ?
        ");
        $stmtUpdate->execute([$id]);

        jsonSuccess([
            'id'         => $id,
            'trang_thai' => 'da_huy',
        ], 'Đã duyệt hủy đơn thành công.');

    } catch (Throwable $e) {
        error_log('[TrangChu] approve_cancel error: ' . $e->getMessage());
        jsonError('Không thể duyệt hủy. Vui lòng thử lại sau.', 500);
    }
}


/* ============================================================
 * ⭐ ACTION: REJECT_CANCEL — Trưởng khoa từ chối hủy đơn
 *    → Đơn quay về 'cho_truong_khoa'
 * ============================================================ */
function handleRejectCancel(): void
{
    global $jsonInput;

    if (!isLoggedIn()) {
        jsonError('Vui lòng đăng nhập!', 401);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonError('Phương thức không hợp lệ! Chỉ chấp nhận POST.', 405);
    }

    $user = getCurrentUser();
    $role = (string)($user['vai_tro'] ?? '');

    if (!in_array($role, ['truong_khoa', 'truongkhoa', 'admin'], true)) {
        jsonError('Chỉ Trưởng khoa mới có quyền từ chối hủy đơn!', 403);
    }

    $id = (int)($jsonInput['id'] ?? $_POST['id'] ?? 0);
    if ($id <= 0) {
        jsonError('ID đơn không hợp lệ!', 400);
    }

    $db = Database::getConnection();

    try {
        $stmt = $db->prepare("
            SELECT np.trang_thai, nv.khoa_phong_id
            FROM nghi_phep np
            JOIN nhan_vien nv ON np.nhan_vien_id = nv.id
            WHERE np.id = ?
        ");
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        if (!$row) {
            jsonError('Không tìm thấy đơn!', 404);
        }

        if ($row['trang_thai'] !== 'cho_huy') {
            jsonError('Đơn không ở trạng thái "Chờ hủy"!', 400);
        }

        if ($role !== 'admin') {
            $emp = getCurrentEmployee();
            $khoaId = (int)($emp['khoa_phong_id'] ?? 0);
            if ($khoaId > 0 && (int)$row['khoa_phong_id'] !== $khoaId) {
                jsonError('Bạn không có quyền từ chối đơn của khoa khác!', 403);
            }
        }

        $stChoTK = defined('STATUS_CHO_TRUONG_KHOA') ? STATUS_CHO_TRUONG_KHOA : 'cho_truong_khoa';
        $stmtUpdate = $db->prepare("
            UPDATE nghi_phep 
            SET trang_thai = ? 
            WHERE id = ?
        ");
        $stmtUpdate->execute([$stChoTK, $id]);

        jsonSuccess([
            'id'         => $id,
            'trang_thai' => $stChoTK,
        ], 'Đã từ chối hủy. Đơn quay về trạng thái chờ duyệt.');

    } catch (Throwable $e) {
        error_log('[TrangChu] reject_cancel error: ' . $e->getMessage());
        jsonError('Không thể từ chối hủy. Vui lòng thử lại sau.', 500);
    }
}


/* ============================================================
 * ACTION: NOTIFICATIONS
 * ============================================================ */
function handleNotifications(): void
{
    if (!isLoggedIn()) {
        jsonError('Vui lòng đăng nhập!', 401);
    }

    $user   = getCurrentUser();
    $userId = (int)($user['id'] ?? 0);

    if ($userId <= 0) {
        jsonError('Không xác định được tài khoản!', 401);
    }

    $list        = getUnreadNotifications($userId, 20);
    $unreadCount = countUnreadNotifications($userId);

    jsonSuccess([
        'unread_count'  => $unreadCount,
        'notifications' => array_map(function ($n) {
            return [
                'id'         => (int)($n['id'] ?? 0),
                'tieu_de'    => $n['tieu_de']    ?? '',
                'noi_dung'   => $n['noi_dung']   ?? '',
                'link'       => $n['link']       ?? null,
                'da_doc'     => 0,
                'created_at' => isset($n['created_at'])
                    ? formatDateTime($n['created_at'])
                    : '',
            ];
        }, $list),
    ]);
}


/* ============================================================
 * ACTION: MARK_READ
 * ============================================================ */
function handleMarkRead(): void
{
    if (!isLoggedIn()) {
        jsonError('Vui lòng đăng nhập!', 401);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonError('Phương thức không hợp lệ! Chỉ chấp nhận POST.', 405);
    }

    $user   = getCurrentUser();
    $userId = (int)($user['id'] ?? 0);

    if ($userId <= 0) {
        jsonError('Không xác định được tài khoản!', 401);
    }

    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        jsonError('ID thông báo không hợp lệ!', 400);
    }

    try {
        $db = Database::getConnection();
        $stmt = $db->prepare('
            UPDATE notifications SET da_doc = 1 
            WHERE id = ? AND user_id = ?
        ');
        $stmt->execute([$id, $userId]);
        $changed = $stmt->rowCount();

        jsonSuccess([
            'changed'      => $changed,
            'id'           => $id,
            'unread_count' => countUnreadNotifications($userId),
        ], $changed > 0 ? 'Đã đánh dấu đã đọc.' : 'Thông báo không tồn tại hoặc đã đọc.');

    } catch (Throwable $e) {
        error_log('[TrangChu] mark_read error: ' . $e->getMessage());
        jsonError('Không thể cập nhật thông báo!', 500);
    }
}


/* ============================================================
 * ACTION: MARK_ALL_READ
 * ============================================================ */
function handleMarkAllRead(): void
{
    if (!isLoggedIn()) {
        jsonError('Vui lòng đăng nhập!', 401);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonError('Phương thức không hợp lệ!', 405);
    }

    $user   = getCurrentUser();
    $userId = (int)($user['id'] ?? 0);

    if ($userId <= 0) {
        jsonError('Không xác định được tài khoản!', 401);
    }

    markAllNotificationsAsRead($userId);

    jsonSuccess([
        'unread_count' => 0,
    ], 'Đã đánh dấu tất cả thông báo đã đọc.');
}


/* ============================================================
 * HELPERS
 * ============================================================ */

function buildLeavesWhere(string $role, int $nhanVienId, ?array $emp): array
{
    if (in_array($role, ['admin'], true)) {
        return ['', []];
    }

    if (in_array($role, ['truong_khoa', 'truongkhoa'], true)) {
        $khoaId = (int)($emp['khoa_phong_id'] ?? 0);
        if ($khoaId > 0) {
            $stChoTK  = defined('STATUS_CHO_TRUONG_KHOA') ? STATUS_CHO_TRUONG_KHOA : 'cho_truong_khoa';
            $stChoHuy = 'cho_huy';
            return [
                'WHERE np.trang_thai IN (?, ?) AND nv.khoa_phong_id = ?',
                [$stChoTK, $stChoHuy, $khoaId]
            ];
        }
        return ['WHERE np.nhan_vien_id = ?', [$nhanVienId]];
    }

    if (in_array($role, ['giam_doc', 'giamdoc', 'ban_lanh_dao', 'lanh_dao'], true)) {
        $stChoLD = defined('STATUS_CHO_PHE_DUYET') ? STATUS_CHO_PHE_DUYET : 'cho_phe_duyet';
        return ['WHERE np.trang_thai = ?', [$stChoLD]];
    }

    if (in_array($role, ['nhan_su', 'nhansu'], true)) {
        $stDaDuyet = defined('STATUS_DA_DUYET') ? STATUS_DA_DUYET : 'da_duyet';
        return ['WHERE np.trang_thai = ?', [$stDaDuyet]];
    }

    return ['WHERE np.nhan_vien_id = ?', [$nhanVienId]];
}

function buildStats(int $nhanVienId, int $year, string $role = 'nhan_vien', ?array $emp = null): array
{
    $db = Database::getConnection();
    $stats = [];

    $stChoTruongKhoa = defined('STATUS_CHO_TRUONG_KHOA') ? STATUS_CHO_TRUONG_KHOA : 'cho_truong_khoa';
    $stChoPheDuyet   = defined('STATUS_CHO_PHE_DUYET')   ? STATUS_CHO_PHE_DUYET   : 'cho_phe_duyet';
    $stDaDuyet       = defined('STATUS_DA_DUYET')        ? STATUS_DA_DUYET        : 'da_duyet';
    $stDaTiepNhan    = defined('STATUS_DA_TIEP_NHAN')    ? STATUS_DA_TIEP_NHAN    : 'da_tiep_nhan';
    $stTuChoi        = defined('STATUS_TU_CHOI')         ? STATUS_TU_CHOI         : 'tu_choi';

    /* ADMIN / LÃNH ĐẠO / NHÂN SỰ */
    if (in_array($role, ['admin', 'giam_doc', 'giamdoc', 'ban_lanh_dao', 'lanh_dao', 'nhan_su', 'nhansu'], true)) {
        try {
            $stmt = $db->query("SELECT COUNT(*) FROM nhan_vien");
            $stats['tong_nv'] = (int)$stmt->fetchColumn();

            $stmt = $db->prepare("SELECT COUNT(*) FROM nghi_phep WHERE YEAR(tu_ngay) = ?");
            $stmt->execute([$year]);
            $stats['tong_don_nam'] = (int)$stmt->fetchColumn();

            $stmt = $db->prepare("
                SELECT
                    SUM(CASE WHEN trang_thai = ? THEN 1 ELSE 0 END) AS cho_tk,
                    SUM(CASE WHEN trang_thai = ? THEN 1 ELSE 0 END) AS cho_ld,
                    SUM(CASE WHEN trang_thai = ? THEN 1 ELSE 0 END) AS cho_ns,
                    SUM(CASE WHEN trang_thai = ? THEN 1 ELSE 0 END) AS da_duyet,
                    SUM(CASE WHEN trang_thai = ? THEN 1 ELSE 0 END) AS tu_choi
                FROM nghi_phep
                WHERE YEAR(tu_ngay) = ?
            ");
            $stmt->execute([$stChoTruongKhoa, $stChoPheDuyet, $stDaTiepNhan, $stDaDuyet, $stTuChoi, $year]);
            $row = $stmt->fetch();

            $stats['cho_truong_khoa'] = (int)($row['cho_tk'] ?? 0);
            $stats['cho_lanh_dao']    = (int)($row['cho_ld'] ?? 0);
            $stats['cho_nhan_su']     = (int)($row['cho_ns'] ?? 0);
            $stats['da_duyet']        = (int)($row['da_duyet'] ?? 0);
            $stats['tu_choi']         = (int)($row['tu_choi'] ?? 0);

            $stats['choTK'] = $stats['cho_truong_khoa'];
            $stats['choLD'] = $stats['cho_lanh_dao'];
            $stats['choNS'] = $stats['cho_nhan_su'];

            $stmt = $db->prepare("
                SELECT
                    SUM(CASE WHEN trang_thai IN (?, ?) AND MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE()) THEN 1 ELSE 0 END) AS da_duyet_thang,
                    SUM(CASE WHEN trang_thai = ? AND MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE()) THEN 1 ELSE 0 END) AS da_tiep_nhan_thang
                FROM nghi_phep
            ");
            $stmt->execute([$stDaDuyet, $stDaTiepNhan, $stDaTiepNhan]);
            $rowThang = $stmt->fetch();
            $stats['da_duyet_thang']     = (int)($rowThang['da_duyet_thang'] ?? 0);
            $stats['da_tiep_nhan_thang'] = (int)($rowThang['da_tiep_nhan_thang'] ?? 0);

        } catch (Throwable $e) {
            error_log('[TrangChu] Admin/Leader stats error: ' . $e->getMessage());
        }
        return $stats;
    }

    /* TRƯỞNG KHOA */
    if (in_array($role, ['truong_khoa', 'truongkhoa'], true)) {
        $khoaId = (int)($emp['khoa_phong_id'] ?? 0);
        if ($khoaId > 0) {
            try {
                $stmt = $db->prepare("SELECT COUNT(*) FROM nhan_vien WHERE khoa_phong_id = ?");
                $stmt->execute([$khoaId]);
                $stats['so_nv_khoa'] = (int)$stmt->fetchColumn();

                $stmt = $db->prepare("
                    SELECT
                        COUNT(*) AS tong_don,
                        SUM(CASE WHEN np.trang_thai = ? THEN 1 ELSE 0 END) AS cho_tk,
                        SUM(CASE WHEN np.trang_thai = 'cho_huy' THEN 1 ELSE 0 END) AS cho_huy,
                        SUM(CASE WHEN np.trang_thai NOT IN (?, ?, 'cho_huy') THEN 1 ELSE 0 END) AS da_xu_ly
                    FROM nghi_phep np
                    JOIN nhan_vien nv ON np.nhan_vien_id = nv.id
                    WHERE nv.khoa_phong_id = ? AND YEAR(np.tu_ngay) = ?
                ");
                $stmt->execute([$stChoTruongKhoa, $stChoTruongKhoa, $stChoPheDuyet, $khoaId, $year]);
                $row = $stmt->fetch();

                $stats['cho_truong_khoa'] = (int)($row['cho_tk'] ?? 0);
                $stats['cho_huy']         = (int)($row['cho_huy'] ?? 0);
                $stats['tong_don_khoa']   = (int)($row['tong_don'] ?? 0);
                $stats['da_xu_ly']        = (int)($row['da_xu_ly'] ?? 0);
                $stats['choTK'] = $stats['cho_truong_khoa'] + $stats['cho_huy'];
            } catch (Throwable $e) {
                error_log('[TrangChu] TruongKhoa stats error: ' . $e->getMessage());
            }
        }
        return $stats;
    }

    /* NHÂN VIÊN */
    $stats = [
        'tong_phep'    => 12,
        'da_su_dung'   => 0,
        'phep_con_lai' => 12,
        'cho_duyet'    => 0,
        'da_duyet'     => 0,
        'tu_choi'      => 0,
    ];

    if ($nhanVienId <= 0) {
        return $stats;
    }

    try {
        if (function_exists('getEmployeeLeaveQuota')) {
            $quota = getEmployeeLeaveQuota($nhanVienId, $year);
            if (!empty($quota)) {
                $stats['tong_phep']    = (int)($quota['tong_phep']       ?? 12);
                $stats['da_su_dung']   = (int)($quota['so_ngay_da_nghi'] ?? 0);
                $stats['phep_con_lai'] = (int)($quota['phep_con_lai']    ?? 0);
            }
        }
    } catch (Throwable $e) {
        error_log('[TrangChu] Quota error: ' . $e->getMessage());
    }

    try {
        $stmt = $db->prepare("
            SELECT
                SUM(CASE WHEN trang_thai IN (?, ?) THEN 1 ELSE 0 END) AS cho_duyet,
                SUM(CASE WHEN trang_thai IN (?, ?) THEN 1 ELSE 0 END) AS da_duyet,
                SUM(CASE WHEN trang_thai = ?       THEN 1 ELSE 0 END) AS tu_choi
            FROM nghi_phep
            WHERE nhan_vien_id = ? AND YEAR(tu_ngay) = ?
        ");
        $stmt->execute([
            $stChoTruongKhoa, $stChoPheDuyet,
            $stDaDuyet,       $stDaTiepNhan,
            $stTuChoi,
            $nhanVienId, $year,
        ]);
        $row = $stmt->fetch();

        if ($row) {
            $stats['cho_duyet'] = (int)($row['cho_duyet'] ?? 0);
            $stats['da_duyet']  = (int)($row['da_duyet']  ?? 0);
            $stats['tu_choi']   = (int)($row['tu_choi']   ?? 0);
        }
    } catch (Throwable $e) {
        error_log('[TrangChu] Stats error: ' . $e->getMessage());
    }

    return $stats;
}

function normalizeLeaveRow(array $row): array
{
    return [
        'id'         => (int)($row['id'] ?? 0),
        'ma_don'     => $row['ma_don']     ?? '',
        'ho_ten'     => $row['ho_ten']     ?? '',
        'chuc_vu'    => $row['chuc_vu']    ?? '',
        'ma_nv'      => $row['ma_nv']      ?? '',
        'ten_khoa'   => $row['ten_khoa']   ?? '',
        'tu_ngay'    => $row['tu_ngay']    ?? null,
        'den_ngay'   => $row['den_ngay']   ?? null,
        'so_ngay'    => (float)($row['so_ngay'] ?? 0),
        'trang_thai' => $row['trang_thai'] ?? 'draft',
        'created_at' => $row['created_at'] ?? null,
    ];
}