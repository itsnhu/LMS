<?php
/**
 * ============================================================
 *  API: XEM CHI TIẾT GIẤY NGHỈ PHÉP
 *  BỆNH VIỆN ĐA KHOA TÂM TRÍ ĐỒNG THÁP
 *  File: backend/api/xemdonnghiphep.php
 *  Method: GET
 * ============================================================
 *  ⭐ FIX (2024-10):
 *   - Load config TRƯỚC khi gửi header (session start sớm)
 *   - $__API_DEBUG = false
 *   - set_error_handler chỉ log, KHÔNG throw
 *   - Helper functions có function_exists() guard
 *   - jsonError('...', 401, ['code' => 'unauthorized'])
 *   - Base64 decode strict mode
 *   - Trả ho_ten_nguoi_ky (nếu có cột trong DB)
 *   - Giữ nguyên logic POST duyệt/từ chối/tiếp nhận
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
    error_log('[XemDon] Exception: ' . $ex->getMessage()
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
        error_log('[XemDon] Fatal: ' . $err['message']
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
    /* ⭐ Log, KHÔNG throw — tránh warning nhỏ biến thành 500 */
    error_log('[XemDon] ' . $errstr . ' @ ' . basename($errfile) . ':' . $errline);
    return true;
});

/* ============================================================
 * 3. LOAD CONFIG TRƯỚC
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
 * 4. HEADERS
 * ============================================================ */
@header('Content-Type: application/json; charset=utf-8');
@header('X-Content-Type-Options: nosniff');
@header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
@header('Pragma: no-cache');

/* ============================================================
 * 5. FALLBACK CONSTANTS
 * ============================================================ */
if (!defined('ROLE_ADMIN'))       define('ROLE_ADMIN',       'admin');
if (!defined('ROLE_NHAN_VIEN'))   define('ROLE_NHAN_VIEN',   'nhan_vien');
if (!defined('ROLE_TRUONG_KHOA')) define('ROLE_TRUONG_KHOA', 'truong_khoa');
if (!defined('ROLE_LANH_DAO'))    define('ROLE_LANH_DAO',    'lanh_dao');
if (!defined('ROLE_NHAN_SU'))     define('ROLE_NHAN_SU',     'nhan_su');

if (!defined('STATUS_DRAFT'))           define('STATUS_DRAFT',           'draft');
if (!defined('STATUS_CHO_TRUONG_KHOA')) define('STATUS_CHO_TRUONG_KHOA', 'cho_truong_khoa');
if (!defined('STATUS_CHO_PHE_DUYET'))   define('STATUS_CHO_PHE_DUYET',   'cho_phe_duyet');
if (!defined('STATUS_DA_DUYET'))        define('STATUS_DA_DUYET',        'da_duyet');
if (!defined('STATUS_TU_CHOI'))         define('STATUS_TU_CHOI',         'tu_choi');
if (!defined('STATUS_DA_TIEP_NHAN'))    define('STATUS_DA_TIEP_NHAN',    'da_tiep_nhan');
if (!defined('CURRENT_YEAR'))           define('CURRENT_YEAR',           (int)date('Y'));

/* ============================================================
 * 6. HELPER FUNCTIONS (có guard)
 * ============================================================ */
if (!function_exists('hasAnyRole')) {
    /* Đã có trong functions.php — chỉ fallback nếu thiếu */
    function hasAnyRole(array $roles): bool {
        $u = $_SESSION['user'] ?? null;
        if (!$u) return false;
        $r = $u['vai_tro'] ?? '';
        if ($r === 'admin') return true;
        return in_array($r, $roles, true);
    }
}

if (!function_exists('normalizeCaNghiDb')) {
    function normalizeCaNghiDb(?string $ca): string {
        $valid = ['ca_ngay', 'sang', 'chieu'];
        if ($ca === null || $ca === '') return 'ca_ngay';
        return in_array($ca, $valid, true) ? $ca : 'ca_ngay';
    }
}
if (!function_exists('caNghiLabel')) {
    function caNghiLabel(?string $ca): string {
        $map = [
            'ca_ngay' => 'Cả ngày',
            'sang'    => 'Buổi sáng',
            'chieu'   => 'Buổi chiều',
        ];
        return $map[normalizeCaNghiDb($ca)] ?? 'Cả ngày';
    }
}
if (!function_exists('isCaNghiSingle')) {
    function isCaNghiSingle(?string $start, ?string $end): bool {
        return normalizeCaNghiDb($start) === normalizeCaNghiDb($end);
    }
}
if (!function_exists('diffDaysInclusive')) {
    function diffDaysInclusive(?string $tuNgay, ?string $denNgay): int {
        if (empty($tuNgay) || empty($denNgay)) return 1;
        $t1 = strtotime($tuNgay);
        $t2 = strtotime($denNgay);
        if ($t1 === false || $t2 === false) return 1;
        return (int)floor(($t2 - $t1) / 86400) + 1;
    }
}
if (!function_exists('getTableColumnsXemDon')) {
    function getTableColumnsXemDon(PDO $db, string $table): array {
        $cols = [];
        try {
            $stmt = $db->query("SHOW COLUMNS FROM `$table`");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $cols[$c['Field']] = true;
            }
        } catch (Throwable $e) {
            error_log("[XemDon] getTableColumns($table): " . $e->getMessage());
        }
        return $cols;
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
$userRole      = $currentUser['vai_tro']           ?? 'nhan_vien';
$currentEmpId  = (int)($currentUser['nhan_vien_id'] ?? 0);
$currentUserId = (int)($currentUser['id']            ?? 0);
$khoaPhongId   = (int)($currentEmp['khoa_phong_id']  ?? 0);

$db = Database::getConnection();

/* ============================================================
 * 8. ĐỌC INPUT (id)
 * ============================================================ */
$id = 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
} else {
    $id = (int)($_GET['id'] ?? 0);
}

/* ============================================================
 * 9. XỬ LÝ POST — DUYỆT / TỪ CHỐI / TIẾP NHẬN
 * ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id > 0) {

    verifyCsrfRequest();

    $actionType = $_POST['action_type'] ?? '';

    /* ---------- LÃNH ĐẠO / ADMIN ---------- */
    if ($actionType === 'lanh_dao_decision' && hasAnyRole([ROLE_LANH_DAO, ROLE_ADMIN])) {
        $decision = $_POST['decision'] ?? '';
        $yKien    = trim($_POST['phe_duyet_note'] ?? '');

        $stmtCheck = $db->prepare('
            SELECT np.*, nv.ho_ten, nv.ma_nv, nv.id AS nv_id
            FROM nghi_phep np
            JOIN nhan_vien nv ON np.nhan_vien_id = nv.id
            WHERE np.id = ? AND np.trang_thai IN (?, ?)
        ');
        $stmtCheck->execute([$id, STATUS_CHO_PHE_DUYET, STATUS_CHO_TRUONG_KHOA]);
        $don = $stmtCheck->fetch();

        if (!$don) {
            jsonError('Không tìm thấy đơn hoặc đơn không ở trạng thái chờ phê duyệt.', 404);
        }

        $caStart = normalizeCaNghiDb($don['buoi_nghi'] ?? 'ca_ngay');
        $caEnd   = normalizeCaNghiDb($don['buoi_nghi_ket_thuc'] ?? $caStart);
        $moTaCa  = isCaNghiSingle($caStart, $caEnd)
            ? caNghiLabel($caStart)
            : caNghiLabel($caStart) . ' (đầu) → ' . caNghiLabel($caEnd) . ' (cuối)';

        $year       = (int)date('Y', strtotime($don['tu_ngay']));
        $nvId       = (int)$don['nv_id'];
        $soNgayNghi = (float)$don['so_ngay'];

        if ($decision === 'approve') {
            $signatureMethod  = $_POST['signature_method_ld'] ?? 'draw';
            $chuKyBase64      = $_POST['chu_ky_lanh_dao_base64'] ?? '';
            $chuKyLanhDaoPath = $don['chu_ky_lanh_dao'] ?? null;

            $dungChuKyMacDinh = !empty($_POST['use_default_signature']) && !empty($currentEmp['chu_ky_mac_dinh']);

            if ($dungChuKyMacDinh) {
                $chuKyLanhDaoPath = $currentEmp['chu_ky_mac_dinh'];
            } else {
                /* ⭐ Đồng bộ folder với taodonnghiphep.php — dùng chuky/ */
                $uploadDir = dirname(__DIR__, 2) . '/frontend/uploads/chuky/';
                if (!is_dir($uploadDir)) { @mkdir($uploadDir, 0775, true); }

                if ($signatureMethod === 'upload') {
                    if (isset($_FILES['file_chu_ky_lanh_dao']) && $_FILES['file_chu_ky_lanh_dao']['error'] === UPLOAD_ERR_OK) {
                        $fileTmp = $_FILES['file_chu_ky_lanh_dao']['tmp_name'];

                        if (!empty($chuKyBase64) && str_starts_with($chuKyBase64, 'data:image')) {
                            $imageData = explode(',', $chuKyBase64, 2);
                            if (isset($imageData[1])) {
                                $decoded = base64_decode($imageData[1], true);
                                if ($decoded !== false) {
                                    $pngFileName = 'lanh_dao_sign_' . $currentUserId . '_' . time() . '_' . bin2hex(random_bytes(3)) . '.png';
                                    if (file_put_contents($uploadDir . $pngFileName, $decoded)) {
                                        $chuKyLanhDaoPath = 'frontend/uploads/chuky/' . $pngFileName;
                                    }
                                }
                            }
                        }
                        if (!$chuKyLanhDaoPath) {
                            $fileName = 'lanh_dao_sign_' . $currentUserId . '_' . time() . '_'
                                      . bin2hex(random_bytes(3)) . '.'
                                      . pathinfo($_FILES['file_chu_ky_lanh_dao']['name'], PATHINFO_EXTENSION);
                            if (move_uploaded_file($fileTmp, $uploadDir . $fileName)) {
                                $chuKyLanhDaoPath = 'frontend/uploads/chuky/' . $fileName;
                            }
                        }
                    }
                } else {
                    if (!empty($chuKyBase64) && str_starts_with($chuKyBase64, 'data:image')) {
                        $imageData = explode(',', $chuKyBase64, 2);
                        if (isset($imageData[1])) {
                            $decoded = base64_decode($imageData[1], true);
                            if ($decoded !== false) {
                                $pngFileName = 'lanh_dao_canvas_' . $currentUserId . '_' . time() . '_' . bin2hex(random_bytes(3)) . '.png';
                                if (file_put_contents($uploadDir . $pngFileName, $decoded)) {
                                    $chuKyLanhDaoPath = 'frontend/uploads/chuky/' . $pngFileName;
                                }
                            }
                        }
                    }
                }
            }

            if (!empty($_POST['save_as_default_sign']) && $currentEmpId > 0 && !empty($chuKyLanhDaoPath)) {
                $stmtSaveDefault = $db->prepare('UPDATE nhan_vien SET chu_ky_mac_dinh = ? WHERE id = ?');
                $stmtSaveDefault->execute([$chuKyLanhDaoPath, $currentEmpId]);
            }

            $db->beginTransaction();
            try {
                $newStatus = STATUS_DA_DUYET;
                $stmtUp = $db->prepare('
                    UPDATE nghi_phep
                    SET phe_duyet = ?, chu_ky_lanh_dao = ?,
                        nguoi_phe_duyet = ?, ngay_phe_duyet = NOW(),
                        trang_thai = ?
                    WHERE id = ?
                ');
                $stmtUp->execute([$yKien ?: 'Đồng ý phê duyệt', $chuKyLanhDaoPath, $currentUserId, $newStatus, $id]);

                recordLeaveHistory(
                    $id, $currentUserId,
                    'Lãnh đạo phê duyệt nghỉ phép' . ($chuKyLanhDaoPath ? ' (Kèm chữ ký số)' : ''),
                    $don['trang_thai'], $newStatus,
                    ($yKien ?: 'Phê duyệt cho nghỉ ' . $soNgayNghi . ' ngày') . ' — Ca nghỉ: ' . $moTaCa
                );

                $db->commit();

                $stmtUserNV = $db->prepare('SELECT id FROM users WHERE nhan_vien_id = ?');
                $stmtUserNV->execute([$nvId]);
                $nvUserId = $stmtUserNV->fetchColumn();
                if ($nvUserId) {
                    createNotification(
                        (int)$nvUserId,
                        'Giấy nghỉ phép đã được Ban Giám Đốc phê duyệt',
                        "Đơn nghỉ phép {$don['ma_don']} của bạn đã được Lãnh đạo phê duyệt thành công ({$moTaCa}).",
                        'xemdonnghiphep.html?id=' . $id
                    );
                }

                $stmtNS = $db->query('SELECT id FROM users WHERE vai_tro = "nhan_su"');
                while ($ns = $stmtNS->fetch()) {
                    createNotification(
                        $ns['id'],
                        'Có giấy nghỉ phép mới cần tiếp nhận',
                        "Đơn {$don['ma_don']} của {$don['ho_ten']} đã được Ban Giám Đốc duyệt ({$moTaCa}).",
                        'tiepnhangiaynghiphep.html'
                    );
                }

                jsonSuccess([
                    'id'      => $id,
                    'status'  => $newStatus,
                    'message' => "Đã phê duyệt giấy nghỉ phép {$don['ma_don']} thành công!"
                ], "Đã phê duyệt giấy nghỉ phép {$don['ma_don']} thành công!");

            } catch (Throwable $e) {
                $db->rollBack();
                error_log('[XemDon] Approve error: ' . $e->getMessage());
                jsonError('Lỗi trong quá trình phê duyệt. Vui lòng thử lại!', 500);
            }
        } elseif ($decision === 'reject') {
            $newStatus = STATUS_TU_CHOI;
            $stmtUp = $db->prepare('
                UPDATE nghi_phep
                SET phe_duyet = ?, nguoi_phe_duyet = ?, ngay_phe_duyet = NOW(),
                    trang_thai = ?, ly_do_tu_choi = ?
                WHERE id = ?
            ');
            $stmtUp->execute([$yKien ?: 'Không đồng ý phê duyệt', $currentUserId, $newStatus, $yKien, $id]);

            recordLeaveHistory(
                $id, $currentUserId, 'Lãnh đạo từ chối đơn',
                $don['trang_thai'], $newStatus,
                ($yKien ?: 'Lãnh đạo không chấp thuận nghỉ phép') . ' — Ca nghỉ: ' . $moTaCa
            );

            $stmtUserNV = $db->prepare('SELECT id FROM users WHERE nhan_vien_id = ?');
            $stmtUserNV->execute([$nvId]);
            $nvUserId = $stmtUserNV->fetchColumn();
            if ($nvUserId) {
                createNotification(
                    (int)$nvUserId,
                    'Giấy nghỉ phép của bạn bị từ chối',
                    "Ban Giám Đốc không chấp thuận đơn {$don['ma_don']}. Lý do: {$yKien}",
                    'xemdonnghiphep.html?id=' . $id
                );
            }

            jsonSuccess([
                'id'      => $id,
                'status'  => $newStatus,
                'message' => "Đã từ chối giấy nghỉ phép {$don['ma_don']}!"
            ], "Đã từ chối giấy nghỉ phép {$don['ma_don']}!");
        } else {
            jsonError('Hành động không hợp lệ.', 400);
        }
    }

    /* ---------- TRƯỞNG KHOA / ADMIN ---------- */
    if ($actionType === 'truong_khoa_decision' && hasAnyRole([ROLE_TRUONG_KHOA, ROLE_ADMIN])) {
        $decision = $_POST['decision'] ?? '';
        $yKien    = trim($_POST['y_kien_note'] ?? '');

        $stmtCheck = $db->prepare('
            SELECT np.*, nv.ho_ten, nv.khoa_phong_id, nv.id AS nv_id
            FROM nghi_phep np
            JOIN nhan_vien nv ON np.nhan_vien_id = nv.id
            WHERE np.id = ? AND np.trang_thai = ?
        ');
        $stmtCheck->execute([$id, STATUS_CHO_TRUONG_KHOA]);
        $don = $stmtCheck->fetch();

        if (!$don) {
            jsonError('Không tìm thấy đơn hoặc đơn không ở trạng thái chờ trưởng khoa.', 404);
        }

        $caStart = normalizeCaNghiDb($don['buoi_nghi'] ?? 'ca_ngay');
        $caEnd   = normalizeCaNghiDb($don['buoi_nghi_ket_thuc'] ?? $caStart);
        $moTaCa  = isCaNghiSingle($caStart, $caEnd)
            ? caNghiLabel($caStart)
            : caNghiLabel($caStart) . ' (đầu) → ' . caNghiLabel($caEnd) . ' (cuối)';

        if ($decision === 'approve') {
            $signatureMethodTk = $_POST['signature_method_tk'] ?? 'draw';
            $chuKyBase64Tk     = $_POST['chu_ky_truong_khoa_base64'] ?? '';
            $chuKyTkPath       = $don['chu_ky_truong_khoa'] ?? null;

            $dungChuKyMacDinhTk = !empty($_POST['use_default_signature_tk']) && !empty($currentEmp['chu_ky_mac_dinh']);

            if ($dungChuKyMacDinhTk) {
                $chuKyTkPath = $currentEmp['chu_ky_mac_dinh'];
            } else {
                $uploadDir = dirname(__DIR__, 2) . '/frontend/uploads/chuky/';
                if (!is_dir($uploadDir)) { @mkdir($uploadDir, 0775, true); }

                if ($signatureMethodTk === 'upload') {
                    if (isset($_FILES['file_chu_ky_truong_khoa']) && $_FILES['file_chu_ky_truong_khoa']['error'] === UPLOAD_ERR_OK) {
                        $fileTmp = $_FILES['file_chu_ky_truong_khoa']['tmp_name'];

                        if (!empty($chuKyBase64Tk) && str_starts_with($chuKyBase64Tk, 'data:image')) {
                            $imageData = explode(',', $chuKyBase64Tk, 2);
                            if (isset($imageData[1])) {
                                $decoded = base64_decode($imageData[1], true);
                                if ($decoded !== false) {
                                    $pngFileName = 'truong_khoa_sign_' . $currentUserId . '_' . time() . '_' . bin2hex(random_bytes(3)) . '.png';
                                    if (file_put_contents($uploadDir . $pngFileName, $decoded)) {
                                        $chuKyTkPath = 'frontend/uploads/chuky/' . $pngFileName;
                                    }
                                }
                            }
                        }
                        if (!$chuKyTkPath) {
                            $fileName = 'truong_khoa_sign_' . $currentUserId . '_' . time() . '_'
                                      . bin2hex(random_bytes(3)) . '.'
                                      . pathinfo($_FILES['file_chu_ky_truong_khoa']['name'], PATHINFO_EXTENSION);
                            if (move_uploaded_file($fileTmp, $uploadDir . $fileName)) {
                                $chuKyTkPath = 'frontend/uploads/chuky/' . $fileName;
                            }
                        }
                    }
                } else {
                    if (!empty($chuKyBase64Tk) && str_starts_with($chuKyBase64Tk, 'data:image')) {
                        $imageData = explode(',', $chuKyBase64Tk, 2);
                        if (isset($imageData[1])) {
                            $decoded = base64_decode($imageData[1], true);
                            if ($decoded !== false) {
                                $pngFileName = 'truong_khoa_canvas_' . $currentUserId . '_' . time() . '_' . bin2hex(random_bytes(3)) . '.png';
                                if (file_put_contents($uploadDir . $pngFileName, $decoded)) {
                                    $chuKyTkPath = 'frontend/uploads/chuky/' . $pngFileName;
                                }
                            }
                        }
                    }
                }
            }

            if (!empty($_POST['save_as_default_sign_tk']) && $currentEmpId > 0 && !empty($chuKyTkPath)) {
                $stmtSaveDefault = $db->prepare('UPDATE nhan_vien SET chu_ky_mac_dinh = ? WHERE id = ?');
                $stmtSaveDefault->execute([$chuKyTkPath, $currentEmpId]);
            }

            $newStatus = STATUS_CHO_PHE_DUYET;
            $stmtUp = $db->prepare('
                UPDATE nghi_phep
                SET y_kien_truong_khoa = ?, chu_ky_truong_khoa = ?,
                    nguoi_cho_y_kien = ?, ngay_cho_y_kien = NOW(),
                    trang_thai = ?
                WHERE id = ?
            ');
            $stmtUp->execute([$yKien ?: 'Đồng ý cho nghỉ theo nguyện vọng', $chuKyTkPath, $currentUserId, $newStatus, $id]);

            recordLeaveHistory(
                $id, $currentUserId,
                'Trưởng khoa đồng ý & chuyển Lãnh đạo' . ($chuKyTkPath ? ' (Kèm chữ ký số)' : ''),
                $don['trang_thai'], $newStatus,
                ($yKien ?: 'Đồng ý cho nghỉ theo nguyện vọng') . ' — Ca nghỉ: ' . $moTaCa
            );

            $stmtLD = $db->query('SELECT id FROM users WHERE vai_tro = "lanh_dao"');
            while ($ld = $stmtLD->fetch()) {
                createNotification(
                    $ld['id'],
                    'Có giấy nghỉ phép mới cần phê duyệt',
                    "Trưởng khoa đã cho ý kiến đơn của {$don['ho_ten']} ({$don['ma_don']}) — Ca nghỉ: {$moTaCa}",
                    'lanhdaoduyetdonnghiphep.html'
                );
            }

            jsonSuccess([
                'id'      => $id,
                'status'  => $newStatus,
                'message' => 'Đã ghi nhận ý kiến và chuyển giấy nghỉ phép lên Lãnh đạo phê duyệt!'
            ], 'Đã ghi nhận ý kiến và chuyển giấy nghỉ phép lên Lãnh đạo phê duyệt!');
        } elseif ($decision === 'reject') {
            $newStatus = STATUS_TU_CHOI;
            $stmtUp = $db->prepare('
                UPDATE nghi_phep
                SET y_kien_truong_khoa = ?, nguoi_cho_y_kien = ?, ngay_cho_y_kien = NOW(),
                    trang_thai = ?, ly_do_tu_choi = ?
                WHERE id = ?
            ');
            $stmtUp->execute([$yKien ?: 'Trưởng khoa không đồng ý', $currentUserId, $newStatus, $yKien, $id]);

            recordLeaveHistory(
                $id, $currentUserId, 'Trưởng khoa từ chối đơn',
                $don['trang_thai'], $newStatus,
                ($yKien ?: 'Không chấp thuận nghỉ phép') . ' — Ca nghỉ: ' . $moTaCa
            );

            $stmtUserNV = $db->prepare('SELECT id FROM users WHERE nhan_vien_id = ?');
            $stmtUserNV->execute([$don['nv_id']]);
            $nvUserId = $stmtUserNV->fetchColumn();
            if ($nvUserId) {
                createNotification(
                    (int)$nvUserId,
                    'Giấy nghỉ phép của bạn bị từ chối',
                    "Trưởng khoa không chấp thuận đơn {$don['ma_don']}. Lý do: {$yKien}",
                    'xemdonnghiphep.html?id=' . $id
                );
            }

            jsonSuccess([
                'id'      => $id,
                'status'  => $newStatus,
                'message' => 'Đã từ chối giấy nghỉ phép thành công!'
            ], 'Đã từ chối giấy nghỉ phép thành công!');
        } else {
            jsonError('Hành động không hợp lệ.', 400);
        }
    }

    /* ---------- NHÂN SỰ TIẾP NHẬN ---------- */
    if ($actionType === 'nhan_su_receive' && hasAnyRole([ROLE_NHAN_SU, ROLE_ADMIN])) {
        $ngayNopInput = trim($_POST['ngay_nop_nhan_su'] ?? '');

        $stmtCheck = $db->prepare('
            SELECT np.*, nv.ho_ten, nv.id AS nv_id
            FROM nghi_phep np
            JOIN nhan_vien nv ON np.nhan_vien_id = nv.id
            WHERE np.id = ? AND np.trang_thai = ?
        ');
        $stmtCheck->execute([$id, STATUS_DA_DUYET]);
        $don = $stmtCheck->fetch();

        if (!$don) {
            jsonError('Không tìm thấy đơn hoặc đơn chưa được duyệt.', 404);
        }

        $caStart = normalizeCaNghiDb($don['buoi_nghi'] ?? 'ca_ngay');
        $caEnd   = normalizeCaNghiDb($don['buoi_nghi_ket_thuc'] ?? $caStart);
        $moTaCa  = isCaNghiSingle($caStart, $caEnd)
            ? caNghiLabel($caStart)
            : caNghiLabel($caStart) . ' (đầu) → ' . caNghiLabel($caEnd) . ' (cuối)';

        $ngayNop = !empty($ngayNopInput) ? $ngayNopInput : date('Y-m-d', strtotime($don['created_at']));

        $db->beginTransaction();
        try {
            $nvId   = (int)$don['nv_id'];
            $soNgay = (float)$don['so_ngay'];
            $year   = (int)date('Y', strtotime($don['tu_ngay']));

            if (empty($don['da_tru_phep'])) {
                deductLeaveDays($id, $nvId, $soNgay, $year);
            }

            $stmtUpdate = $db->prepare('
                UPDATE nghi_phep
                SET ngay_nop_nhan_su = ?, nguoi_tiep_nhan = ?, ngay_tiep_nhan = NOW(),
                    trang_thai = ?
                WHERE id = ?
            ');
            $stmtUpdate->execute([$ngayNop, $currentUserId, STATUS_DA_TIEP_NHAN, $id]);

            recordLeaveHistory(
                $id, $currentUserId,
                'Phòng Nhân sự đã tiếp nhận giấy',
                STATUS_DA_DUYET, STATUS_DA_TIEP_NHAN,
                "Ghi nhận Ngày nộp giấy: " . formatDate($ngayNop)
                    . " (Thời gian tiếp nhận hệ thống: " . date('d/m/Y H:i') . ")"
                    . " — Ca nghỉ: " . $moTaCa
                    . " — Số ngày trừ: " . $soNgay
            );

            $db->commit();

            $stmtUserNV = $db->prepare('SELECT id FROM users WHERE nhan_vien_id = ?');
            $stmtUserNV->execute([$nvId]);
            $nvUserId = $stmtUserNV->fetchColumn();
            if ($nvUserId) {
                createNotification(
                    (int)$nvUserId,
                    'Phòng Nhân sự đã tiếp nhận giấy nghỉ phép',
                    "Đơn {$don['ma_don']} của bạn đã được Phòng Nhân sự tiếp nhận (Ngày nộp: " . formatDate($ngayNop) . ") — Ca nghỉ: {$moTaCa}.",
                    'xemdonnghiphep.html?id=' . $id
                );
            }

            jsonSuccess([
                'id'       => $id,
                'status'   => STATUS_DA_TIEP_NHAN,
                'ngay_nop' => $ngayNop,
                'message'  => "Đã xác nhận tiếp nhận giấy nghỉ phép {$don['ma_don']} (Ngày nộp: " . formatDate($ngayNop) . ") thành công!"
            ], "Đã xác nhận tiếp nhận giấy nghỉ phép {$don['ma_don']} thành công!");

        } catch (Throwable $e) {
            $db->rollBack();
            error_log('[XemDon] Receive error: ' . $e->getMessage());
            jsonError('Lỗi khi tiếp nhận. Vui lòng thử lại!', 500);
        }
    }

    jsonError('Hành động không được hỗ trợ.', 400);
}

/* ============================================================
 * 10. XỬ LÝ GET — CHI TIẾT ĐƠN
 * ============================================================ */
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonError('Method không được hỗ trợ.', 405, ['code' => 'method_not_allowed']);
}
if ($id <= 0) {
    jsonError('Mã giấy nghỉ phép không hợp lệ!', 400, ['code' => 'invalid_id']);
}

/* ⭐ Check schema để biết có cột ho_ten_nguoi_ky không */
$columns        = getTableColumnsXemDon($db, 'nghi_phep');
$hasHoTenKyCol  = isset($columns['ho_ten_nguoi_ky']);
$selectHoTenKy  = $hasHoTenKyCol ? ', np.ho_ten_nguoi_ky' : '';

$stmt = $db->prepare("
    SELECT np.*,
           nv.ho_ten, nv.ma_nv, nv.chuc_vu, nv.khoa_phong_id,
           kp.ten_khoa,
           u_tk.username AS truong_khoa_user,
           u_ld.username AS lanh_dao_user,
           u_ns.username AS nhan_su_user,
           nv_ns.ho_ten AS nhan_su_ten
           $selectHoTenKy
    FROM nghi_phep np
    JOIN nhan_vien nv ON np.nhan_vien_id = nv.id
    LEFT JOIN khoa_phong kp ON nv.khoa_phong_id = kp.id
    LEFT JOIN users u_tk ON np.nguoi_cho_y_kien = u_tk.id
    LEFT JOIN users u_ld ON np.nguoi_phe_duyet = u_ld.id
    LEFT JOIN users u_ns ON np.nguoi_tiep_nhan = u_ns.id
    LEFT JOIN nhan_vien nv_ns ON u_ns.nhan_vien_id = nv_ns.id
    WHERE np.id = ?
");
$stmt->execute([$id]);
$leave = $stmt->fetch();

if (!$leave) {
    jsonError('Không tìm thấy thông tin giấy nghỉ phép này!', 404, ['code' => 'not_found']);
}

/* Kiểm tra quyền xem */
$ownerId = (int)($leave['nhan_vien_id'] ?? 0);
$isNhanVienOnly = in_array($userRole, ['nhan_vien', 'nhanvien'], true);
if ($isNhanVienOnly && $ownerId !== $currentEmpId) {
    jsonError('Bạn không có quyền xem giấy nghỉ phép của nhân viên khác!', 403, ['code' => 'forbidden']);
}

/* Lịch sử */
$stmtHistory = $db->prepare('
    SELECT ls.*, u.username, nv.ho_ten AS nguoi_thuc_hien_ten, u.vai_tro
    FROM nghi_phep_lich_su ls
    JOIN users u ON ls.user_id = u.id
    LEFT JOIN nhan_vien nv ON u.nhan_vien_id = nv.id
    WHERE ls.nghi_phep_id = ?
    ORDER BY ls.created_at ASC
');
$stmtHistory->execute([$id]);
$historyList = $stmtHistory->fetchAll();

/* Quota */
$leaveYear = (int)date('Y', strtotime($leave['tu_ngay']));
$quota     = getEmployeeLeaveQuota($ownerId, $leaveYear);

/* Chuẩn hoá ca nghỉ */
$caNghiStart    = normalizeCaNghiDb($leave['buoi_nghi']          ?? 'ca_ngay');
$caNghiEnd      = normalizeCaNghiDb($leave['buoi_nghi_ket_thuc'] ?? $caNghiStart);
$totalDaysRange = diffDaysInclusive($leave['tu_ngay'] ?? null, $leave['den_ngay'] ?? null);
$caNghiLabelStr = isCaNghiSingle($caNghiStart, $caNghiEnd)
    ? caNghiLabel($caNghiStart)
    : caNghiLabel($caNghiStart) . ' → ' . caNghiLabel($caNghiEnd);

/* Chuẩn hoá leave data */
$leaveData = [
    'id'                        => (int)$leave['id'],
    'ma_don'                    => $leave['ma_don'] ?? '',
    'nhan_vien_id'              => $ownerId,
    'ho_ten'                    => $leave['ho_ten'] ?? '',
    'ma_nv'                     => $leave['ma_nv'] ?? '',
    'chuc_vu'                   => $leave['chuc_vu'] ?? '',
    'ten_khoa'                  => $leave['ten_khoa'] ?? '',
    'tu_ngay'                   => $leave['tu_ngay'] ?? null,
    'den_ngay'                  => $leave['den_ngay'] ?? null,
    'so_ngay'                   => (float)($leave['so_ngay'] ?? 0),
    'buoi_nghi'                 => $caNghiStart,
    'buoi_nghi_ket_thuc'        => $caNghiEnd,
    'ca_nghi_label'             => $caNghiLabelStr,
    'is_nghi_nua_buoi'          => isCaNghiSingle($caNghiStart, $caNghiEnd) && $caNghiStart !== 'ca_ngay',
    'total_days_range'          => $totalDaysRange,
    'ly_do'                     => $leave['ly_do'] ?? '',
    'trang_thai'                => $leave['trang_thai'] ?? '',
    'created_at'                => $leave['created_at'] ?? null,

    /* Chữ ký người nghỉ + họ tên (tách riêng) */
    'chu_ky_nguoi_nghi'         => $leave['chu_ky_nguoi_nghi'] ?? null,
    'ho_ten_nguoi_ky'           => $hasHoTenKyCol ? ($leave['ho_ten_nguoi_ky'] ?? null) : null,

    'y_kien_truong_khoa'        => $leave['y_kien_truong_khoa'] ?? null,
    'chu_ky_truong_khoa'        => $leave['chu_ky_truong_khoa'] ?? null,
    'ngay_cho_y_kien'           => $leave['ngay_cho_y_kien'] ?? null,
    'phe_duyet'                 => $leave['phe_duyet'] ?? null,
    'chu_ky_lanh_dao'           => $leave['chu_ky_lanh_dao'] ?? null,
    'ngay_phe_duyet'            => $leave['ngay_phe_duyet'] ?? null,
    'ngay_nop_nhan_su'          => $leave['ngay_nop_nhan_su'] ?? null,
    'ngay_tiep_nhan'            => $leave['ngay_tiep_nhan'] ?? null,
    'nhan_su_ten'               => $leave['nhan_su_ten'] ?? null,
    'nhan_su_user'              => $leave['nhan_su_user'] ?? null,
    'so_phep_con_lai_luc_nghi'  => $leave['so_phep_con_lai_luc_nghi'] !== null
                                    ? (float)$leave['so_phep_con_lai_luc_nghi']
                                    : null,
    'ly_do_tu_choi'             => $leave['ly_do_tu_choi'] ?? null,
];

$historyData = array_map(function ($h) {
    return [
        'id'                  => (int)$h['id'],
        'thao_tac'            => $h['thao_tac'] ?? '',
        'nguoi_thuc_hien_ten' => $h['nguoi_thuc_hien_ten'] ?? '',
        'username'            => $h['username'] ?? '',
        'vai_tro'             => $h['vai_tro'] ?? '',
        'trang_thai_cu'       => $h['trang_thai_cu'] ?? null,
        'trang_thai_moi'      => $h['trang_thai_moi'] ?? null,
        'ghi_chu'             => $h['ghi_chu'] ?? null,
        'created_at'          => $h['created_at'] ?? null,
    ];
}, $historyList);

/* User info */
$userForFe = [
    'id'            => $currentUserId,
    'username'      => $currentUser['username']      ?? '',
    'vai_tro'       => $userRole,
    'nhan_vien_id'  => $currentEmpId ?: null,
    'ho_ten'        => $currentEmp['ho_ten']         ?? $currentUser['username'] ?? 'Người dùng',
    'ma_nv'         => $currentEmp['ma_nv']          ?? null,
    'email'         => $currentEmp['email']          ?? null,
    'so_dien_thoai' => $currentEmp['so_dien_thoai']  ?? null,
    'ngay_sinh'     => $currentEmp['ngay_sinh']      ?? null,
    'chuc_vu'       => $currentEmp['chuc_vu']        ?? null,
    'ten_khoa'      => $currentEmp['ten_khoa']       ?? null,
    'ma_khoa'       => $currentEmp['ma_khoa']        ?? null,
    'anh_dai_dien'  => $currentEmp['anh_dai_dien']   ?? null,
];

/* Response */
jsonSuccess([
    'user'          => $userForFe,
    'role'          => $userRole,
    'nhan_vien_id'  => $currentEmpId,
    'current_year'  => CURRENT_YEAR,

    'leave'         => $leaveData,
    'history'       => $historyData,
    'quota'         => [
        'tong_phep'       => (float)$quota['tong_phep'],
        'so_ngay_da_nghi' => (float)$quota['so_ngay_da_nghi'],
        'phep_con_lai'    => (float)$quota['phep_con_lai'],
    ],
    'leave_year'    => $leaveYear,
]);