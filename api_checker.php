<?php
// ── Bootstrap ────────────────────────────────────────
ob_start();
ini_set('display_errors', 0);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// session ก่อน require เสมอ
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// กัน HTML หลุดเป็น response — ต้องทำก่อน require
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    // log เงียบๆ
    error_log("[$errno] $errstr in $errfile:$errline");
    return true;
});

set_exception_handler(function($e) {
    while (ob_get_level()) ob_end_clean();
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
});
