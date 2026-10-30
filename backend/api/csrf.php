<?php
/**
 * ============================================================
 *  API: TRẢ CSRF TOKEN CHO FRONTEND
 *  BỆNH VIỆN ĐA KHOA TÂM TRÍ ĐỒNG THÁP
 * ============================================================
 *  ⭐ FIX: Load config (start session) TRƯỚC khi gửi header.
 *     Trước đây header được gửi trước → session không set được
 *     cookie → CSRF luôn fail ở request tiếp theo.
 * ============================================================
 */

/* 1. Output buffering sớm */
if (!ob_get_level()) {
    ob_start();
}

/* 2. LOAD CONFIG TRƯỚC — session phải start trước khi gửi header */
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

/* 3. HEADERS — giờ mới gửi, an toàn vì session đã start xong */
if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
}

/* 4. OPTIONS preflight (nếu cross-origin) */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/* 5. Chỉ chấp nhận GET */
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Phương thức không hợp lệ. Chỉ chấp nhận GET.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* 6. Sinh token (nếu chưa có) */
$token = generateCsrfToken();

/* 7. Trả JSON */
echo json_encode([
    'success'    => true,
    'csrf_token' => $token,
], JSON_UNESCAPED_UNICODE);
exit;