<?php
/**
 * ============================================================
 *  API: THÔNG BÁO + ĐÁNH DẤU ĐÃ ĐỌC + ĐIỀU HƯỚNG
 *  Bệnh viện Đa khoa Tâm Trí Đồng Tháp
 *  Endpoint: backend/api/thongbao.php
 * ============================================================
 *  Routing (qua $_GET['action']):
 *    list            → JSON danh sách (GET) ?filter=all|unread|read
 *    count           → JSON đếm số chưa đọc (GET)
 *    mark_read       → Đánh dấu 1 đã đọc (POST, cần id)
 *    mark_all_read   → Đánh dấu tất cả đã đọc (POST)
 *    open            → Đánh dấu + quay lại trang cũ (GET, ?id=)
 * ============================================================
 *  ⭐ FIX (2024-10):
 *   - Load config TRƯỚC khi gửi header (session start sớm)
 *   - Bỏ 3 helper jsonResponse/jsonSuccess/jsonError trùng với
 *     functions.php (tránh conflict signature `$extra`)
 *   - Sửa jsonError('...', 401, 'unauthorized') → dùng array
 *   - Cập nhật mapping link + HOME_PAGE/LOGIN_PAGE theo cấu
 *     trúc FLATTEN
 *   - Action mark_read / mark_all_read trả thêm `unread_count`
 *     để tương thích với trangchu.html
 * ============================================================
 */

/* 1. OUTPUT BUFFERING */
if (!ob_get_level()) { ob_start(); }

/* ============================================================
 * 2. GLOBAL ERROR HANDLERS (chạy TRƯỚC khi header gửi)
 * ============================================================ */
set_exception_handler(function (Throwable $ex) {
    while (ob_get_level()) { ob_end_clean(); }
    $debug = defined('IS_DEV_ENV') && IS_DEV_ENV;
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8', true, 500);
    } else {
        http_response_code(500);
    }
    error_log('[ThongBao] Exception: ' . $ex->getMessage()
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
        error_log('[ThongBao] Fatal: ' . $err['message']
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
    error_log('[ThongBao] ' . $errstr . ' @ ' . basename($errfile) . ':' . $errline);
    return true;
});

/* ============================================================
 * 3. LOAD CONFIG TRƯỚC — session phải start trước khi gửi header
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
@header('X-Content-Type-Options: nosniff');
@header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
@header('Pragma: no-cache');

/* ============================================================
 * 5. URL TRANG CHỦ / ĐĂNG NHẬP (cấu trúc FLATTEN)
 * ============================================================ */
if (!defined('HOME_PAGE')) {
    define('HOME_PAGE', BASE_URL . '/frontend/trangchu.html');
}
if (!defined('LOGIN_PAGE')) {
    define('LOGIN_PAGE', BASE_URL . '/frontend/dangnhap.html');
}

/* Đường dẫn vật lý tới gốc project (chứa frontend/, backend/) */
if (!defined('PROJECT_ROOT')) {
    define('PROJECT_ROOT', realpath(__DIR__ . '/../..'));
}

/* ============================================================
 * 6. HELPER: CHUẨN HOÁ LINK THÔNG BÁO (FLATTEN STRUCTURE)
 * ------------------------------------------------------------
 * Chấp nhận 2 dạng input:
 *   - Link cũ (phe-duyet/lanh-dao.php, duyetlanhdao.html, ...)
 *   - Link mới đã đúng (frontend/trangchu.html)
 * ============================================================ */
if (!function_exists('normalizeNotificationLink')) {
    function normalizeNotificationLink(string $rawLink): string
    {
        $rawLink = trim($rawLink);
        if ($rawLink === '') {
            return '';
        }

        /* Link tuyệt đối → giữ nguyên */
        if (preg_match('#^https?://#i', $rawLink)) {
            return $rawLink;
        }

        /* Tách query */
        $query = '';
        $path  = $rawLink;
        $qPos  = strpos($rawLink, '?');
        if ($qPos !== false) {
            $path  = substr($rawLink, 0, $qPos);
            $query = substr($rawLink, $qPos);
        }

        /* Bỏ prefix */
        $path = ltrim($path, '/');
        $path = preg_replace('#^ql_nghiphep/#', '', $path);
        $path = preg_replace('#^quanlygiayphep/#', '', $path);

        /* Bảng mapping link cũ → mới */
        $mapping = [
            /* ===== Link cũ dạng phe-duyet/*.php ===== */
            'phe-duyet/lanh-dao.php'      => 'frontend/lanhdaoduyetdonnghiphep.html',
            'phe-duyet/lanhdao.php'       => 'frontend/lanhdaoduyetdonnghiphep.html',
            'phe-duyet/truong-khoa.php'   => 'frontend/truongkhoaduyetdonnghiphep.html',
            'phe-duyet/truongkhoa.php'    => 'frontend/truongkhoaduyetdonnghiphep.html',
            'phe-duyet/nhan-su.php'       => 'frontend/tiepnhangiaynghiphep.html',
            'phe-duyet/nhansu.php'        => 'frontend/tiepnhangiaynghiphep.html',
            'phe-duyet/admin.php'         => 'frontend/baocaothongkenghiphep.html',

            /* ===== Link cũ dạng tên file trần ===== */
            'duyetlanhdao.html'           => 'frontend/lanhdaoduyetdonnghiphep.html',
            'duyettruongkhoa.html'        => 'frontend/truongkhoaduyetdonnghiphep.html',
            'tiepnhan.html'               => 'frontend/tiepnhangiaynghiphep.html',
            'lichsu.html'                 => 'frontend/lichsudonnghiphep.html',
            'xemdon.html'                 => 'frontend/xemdonnghiphep.html',
            'taodon.html'                 => 'frontend/taodonnghiphep.html',
            'phepnam.html'                => 'frontend/quanlysophepduocnghi.html',
            'hoso.html'                   => 'frontend/hosonhansu.html',
            'baocao.html'                 => 'frontend/baocaothongkenghiphep.html',
            'trangchu.html'               => 'frontend/trangchu.html',
            'dangnhap.html'               => 'frontend/dangnhap.html',

            /* ===== Link cũ đã có prefix folder cũ ===== */
            'banlanhdao/duyetlanhdao.html'    => 'frontend/lanhdaoduyetdonnghiphep.html',
            'banlanhdao/duyettruongkhoa.html' => 'frontend/truongkhoaduyetdonnghiphep.html',
            'nhansutiepnhan/tiepnhan.html'    => 'frontend/tiepnhangiaynghiphep.html',
            'home/lichsu.html'                => 'frontend/lichsudonnghiphep.html',
            'home/trangchu.html'              => 'frontend/trangchu.html',
            'home/taodon.html'                => 'frontend/taodonnghiphep.html',
            'home/xemdon.html'                => 'frontend/xemdonnghiphep.html',
            'nhanvien/in.html'                => 'frontend/indanhsachdonnghiphep.html',
            'admin/baocao.html'               => 'frontend/baocaothongkenghiphep.html',
            'dangnhap_quenmk/dangnhap.html'   => 'frontend/dangnhap.html',
        ];

        if (isset($mapping[$path])) {
            $path = $mapping[$path];
        } elseif (strpos($path, 'frontend/') === 0) {
            /* Đã đúng chuẩn → giữ nguyên */
        } else {
            /* Đoán theo keyword */
            $low = strtolower($path);
            if (strpos($low, 'lanh') !== false && strpos($low, 'dao') !== false) {
                $path = 'frontend/lanhdaoduyetdonnghiphep.html';
            } elseif (strpos($low, 'truong') !== false && strpos($low, 'khoa') !== false) {
                $path = 'frontend/truongkhoaduyetdonnghiphep.html';
            } elseif (strpos($low, 'tiep') !== false && strpos($low, 'nhan') !== false) {
                $path = 'frontend/tiepnhangiaynghiphep.html';
            } elseif (strpos($low, 'lich') !== false) {
                $path = 'frontend/lichsudonnghiphep.html';
            } else {
                return '';
            }
        }

        return $path . $query;
    }
}

/* ============================================================
 * 7. HELPER: KIỂM TRA FILE ĐÍCH TỒN TẠI
 * ============================================================ */
if (!function_exists('checkNotificationTargetExists')) {
    function checkNotificationTargetExists(string $link): bool
    {
        if (preg_match('#^https?://#i', $link)) {
            return true;
        }

        $path = $link;
        $qPos = strpos($link, '?');
        if ($qPos !== false) {
            $path = substr($link, 0, $qPos);
        }

        if (!defined('PROJECT_ROOT') || PROJECT_ROOT === false) {
            return true;   /* Không xác định được root → cho qua */
        }

        $fullPath = PROJECT_ROOT . DIRECTORY_SEPARATOR
                  . str_replace('/', DIRECTORY_SEPARATOR, $path);

        return is_file($fullPath);
    }
}

/* ============================================================
 * 8. AUTH GUARD
 * ============================================================ */
$action = $_GET['action'] ?? 'list';

/* action=open + chưa login → redirect login */
if ($action === 'open' && !isLoggedIn()) {
    while (ob_get_level()) { ob_end_clean(); }
    header('Location: ' . LOGIN_PAGE);
    exit;
}

/* Các action khác: chưa login → JSON 401 */
if (!isLoggedIn()) {
    jsonError('Phiên đăng nhập đã hết hạn. Vui lòng đăng nhập lại!', 401, ['code' => 'unauthorized']);
}

$currentUser = getCurrentUser();
$userId      = (int)($currentUser['id'] ?? 0);

if ($userId <= 0) {
    jsonError('Không xác định được tài khoản!', 401, ['code' => 'unauthorized']);
}

/* ============================================================
 * 9. KẾT NỐI DB
 * ============================================================ */
if (!class_exists('Database')) {
    jsonError('Không thể kết nối cơ sở dữ liệu!', 500, ['code' => 'db_error']);
}
$db = Database::getConnection();

/* ============================================================
 * 10. ACTION: OPEN — Đánh dấu + quay lại trang cũ
 * ============================================================ */
if ($action === 'open') {
    $notifId     = (int)($_GET['id'] ?? 0);
    $redirectUrl = HOME_PAGE;

    /* Ưu tiên quay lại referer nội bộ */
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    if ($referer !== '') {
        $refHost = parse_url($referer, PHP_URL_HOST);
        $myHost  = $_SERVER['HTTP_HOST'] ?? '';
        if ($refHost && $myHost && strcasecmp($refHost, $myHost) === 0) {
            $redirectUrl = $referer;
        }
    }

    if ($notifId > 0) {
        try {
            $stmtRead = $db->prepare('
                UPDATE notifications SET da_doc = 1 
                WHERE id = ? AND user_id = ?
            ');
            $stmtRead->execute([$notifId, $userId]);
        } catch (Throwable $e) {
            error_log('[ThongBao] open error: ' . $e->getMessage());
        }
    }

    while (ob_get_level()) { ob_end_clean(); }
    header('Location: ' . $redirectUrl, true, 302);
    exit;
}

/* ============================================================
 * 11. JSON HEADER cho các action còn lại
 * ============================================================ */
@header('Content-Type: application/json; charset=utf-8');

/* ============================================================
 * 12. HELPER: RENDER 1 DÒNG THÔNG BÁO
 * ============================================================ */
if (!function_exists('renderNotificationItem')) {
    function renderNotificationItem(array $n): array {
        $rawLink = (string)($n['link'] ?? '');
        $clean   = normalizeNotificationLink($rawLink);

        /* Nếu mapping không nhận diện được, thử dùng raw */
        if ($clean === '') {
            $clean = $rawLink;
        }

        /* Nếu file đích không tồn tại → fallback HOME */
        if ($clean !== '' && !checkNotificationTargetExists($clean)) {
            $clean = 'frontend/trangchu.html';
        }

        return [
            'id'         => (int)($n['id'] ?? 0),
            'tieu_de'    => $n['tieu_de']    ?? '',
            'noi_dung'   => $n['noi_dung']   ?? '',
            'link'       => $clean,
            'loai'       => $n['loai']       ?? '',
            'da_doc'     => (int)($n['da_doc'] ?? 0),
            'created_at' => isset($n['created_at']) && function_exists('formatDateTime')
                ? formatDateTime($n['created_at'])
                : ($n['created_at'] ?? ''),
        ];
    }
}

/* ============================================================
 * 13. ROUTING
 * ============================================================ */
switch ($action) {

    /* ========================================================
       LIST
       ======================================================== */
    case 'list':
        $filter = $_GET['filter'] ?? 'all';
        if (!in_array($filter, ['all', 'unread', 'read'], true)) {
            $filter = 'all';
        }

        $where = 'user_id = :uid';
        if ($filter === 'unread') {
            $where .= ' AND (da_doc = 0 OR da_doc IS NULL)';
        } elseif ($filter === 'read') {
            $where .= ' AND da_doc = 1';
        }

        try {
            $stmt = $db->prepare("
                SELECT id, tieu_de, noi_dung, link, loai, da_doc, created_at
                FROM notifications
                WHERE $where
                ORDER BY da_doc ASC, created_at DESC
                LIMIT 100
            ");
            $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $list = array_map('renderNotificationItem', $rows);

            $stmtCount = $db->prepare('
                SELECT COUNT(*) FROM notifications 
                WHERE user_id = ? AND (da_doc = 0 OR da_doc IS NULL)
            ');
            $stmtCount->execute([$userId]);
            $unreadCount = (int)$stmtCount->fetchColumn();

            jsonResponse([
                'success'       => true,
                'message'       => '',
                'unread_count'  => $unreadCount,
                'notifications' => $list,
                'data'          => [
                    'unread_count'  => $unreadCount,
                    'notifications' => $list,
                    'filter'        => $filter,
                ],
            ]);

        } catch (Throwable $e) {
            error_log('[ThongBao] list error: ' . $e->getMessage());
            $debug = defined('IS_DEV_ENV') && IS_DEV_ENV;
            jsonError(
                $debug ? 'Lỗi truy vấn: ' . $e->getMessage() : 'Không thể tải thông báo!',
                500
            );
        }
        break;

    /* ========================================================
       COUNT
       ======================================================== */
    case 'count':
        try {
            $stmt = $db->prepare('
                SELECT COUNT(*) FROM notifications 
                WHERE user_id = ? AND (da_doc = 0 OR da_doc IS NULL)
            ');
            $stmt->execute([$userId]);
            $unreadCount = (int)$stmt->fetchColumn();

            jsonResponse([
                'success'      => true,
                'unread_count' => $unreadCount,
                'data'         => ['unread_count' => $unreadCount],
            ]);

        } catch (Throwable $e) {
            error_log('[ThongBao] count error: ' . $e->getMessage());
            jsonError('Không thể đếm thông báo!', 500);
        }
        break;

    /* ========================================================
       MARK_READ
       ======================================================== */
    case 'mark_read':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonError('Phương thức không hợp lệ! Chỉ chấp nhận POST.', 405);
        }

        $token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (function_exists('validateCsrfToken') && !validateCsrfToken($token)) {
            jsonError('Phiên làm việc hết hạn. Vui lòng tải lại trang.', 419, ['code' => 'csrf_invalid']);
        }

        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            jsonError('ID thông báo không hợp lệ!', 400);
        }

        try {
            $stmt = $db->prepare('
                UPDATE notifications SET da_doc = 1 
                WHERE id = ? AND user_id = ?
            ');
            $stmt->execute([$id, $userId]);
            $changed = $stmt->rowCount();

            /* Đếm lại unread để tương thích với trangchu.html */
            $unreadCount = function_exists('countUnreadNotifications')
                ? countUnreadNotifications($userId)
                : 0;

            jsonResponse([
                'success'      => true,
                'message'      => $changed > 0 ? 'Đã đánh dấu đã đọc.' : 'Thông báo không tồn tại hoặc đã đọc.',
                'changed'      => $changed,
                'id'           => $id,
                'unread_count' => $unreadCount,
                'data'         => [
                    'changed'      => $changed,
                    'id'           => $id,
                    'unread_count' => $unreadCount,
                ],
            ]);

        } catch (Throwable $e) {
            error_log('[ThongBao] mark_read error: ' . $e->getMessage());
            jsonError('Không thể cập nhật thông báo!', 500);
        }
        break;

    /* ========================================================
       MARK_ALL_READ
       ======================================================== */
    case 'mark_all_read':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonError('Phương thức không hợp lệ! Chỉ chấp nhận POST.', 405);
        }

        $token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (function_exists('validateCsrfToken') && !validateCsrfToken($token)) {
            jsonError('Phiên làm việc hết hạn. Vui lòng tải lại trang.', 419, ['code' => 'csrf_invalid']);
        }

        try {
            $stmt = $db->prepare('
                UPDATE notifications SET da_doc = 1 
                WHERE user_id = ? AND (da_doc = 0 OR da_doc IS NULL)
            ');
            $stmt->execute([$userId]);
            $changed = $stmt->rowCount();

            jsonResponse([
                'success'      => true,
                'message'      => "Đã đánh dấu {$changed} thông báo đã đọc.",
                'changed'      => $changed,
                'unread_count' => 0,
                'data'         => [
                    'changed'      => $changed,
                    'unread_count' => 0,
                ],
            ]);

        } catch (Throwable $e) {
            error_log('[ThongBao] mark_all_read error: ' . $e->getMessage());
            jsonError('Không thể đánh dấu thông báo!', 500);
        }
        break;

    /* ========================================================
       DEFAULT
       ======================================================== */
    default:
        jsonError('Action không hợp lệ!', 400);
}