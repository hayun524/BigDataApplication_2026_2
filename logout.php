<?php
/**
 * logout.php : 로그아웃 (세션 삭제)
 * 작성자 : 왕은서 (6. 공통·문서)
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 작성자: 왕은서 — 세션 변수, 세션 쿠키, 세션 파일 모두 삭제
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();

header('Location: login.php?logout=1');
exit;
