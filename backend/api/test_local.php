<?php
require_once __DIR__ . '/../config/functions.php';
header('Content-Type: text/plain; charset=utf-8');

echo "=== TEST LOCAL XAMPP ===\n\n";

echo "BASE_URL: " . BASE_URL . "\n";
echo "DB_HOST:  " . DB_HOST . "\n";
echo "DB_NAME:  " . DB_NAME . "\n";
echo "DB_USER:  " . DB_USER . "\n\n";

try {
    $db = Database::getConnection();
    echo "✅ Kết nối DB thành công!\n\n";
    echo "Database hiện tại: " . $db->query("SELECT DATABASE()")->fetchColumn() . "\n\n";

    /* Đếm số bản ghi từng bảng */
    foreach (['users', 'nhan_vien', 'khoa_phong', 'nghi_phep'] as $tbl) {
        try {
            $c = $db->query("SELECT COUNT(*) FROM `$tbl`")->fetchColumn();
            echo "  $tbl: $c bản ghi\n";
        } catch (Throwable $e) {
            echo "  $tbl: ❌ " . $e->getMessage() . "\n";
        }
    }
} catch (Throwable $e) {
    echo "❌ LỖI: " . $e->getMessage() . "\n";
}