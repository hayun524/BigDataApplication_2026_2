<?php
/**
 * config.php : DB 접속 설정 + 세션 시작 (모든 페이지 공통)
 * 작성자 : 왕은서 (6. 공통·문서)
 *
 * 사용법 : 각 페이지 맨 위에서
 *     require_once __DIR__ . '/config.php';   // $conn (mysqli) 생성, 세션 시작
 *     require_once __DIR__ . '/header.php';   // 메뉴바, 로그인 확인
 *
 * 평가 서버 제출 시 아래 DB_* 네 줄만 확인하면 된다. (지침 3-(1): teamXX)
 * 다른 파일에는 DB 접속 정보를 적지 않는다.
 */

// ---------------------------------------------------------------------
// 작성자: 왕은서 — DB 접속 정보 (지침: DB명·ID·비밀번호 모두 team06)
// ---------------------------------------------------------------------
define('DB_HOST', 'localhost');
define('DB_USER', 'team06');
define('DB_PASS', 'team06');
define('DB_NAME', 'team06');

// ---------------------------------------------------------------------
// 작성자: 왕은서 — DB 연결 (mysqli)
//   - 오류는 예외로 던지게 해서 트랜잭션 rollback 처리를 쉽게 한다.
//   - utf8mb4 : DB(dbcreate.sql)·PHP·HTML 인코딩 통일 (한글 대여소명)
// ---------------------------------------------------------------------
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $conn->set_charset('utf8mb4');
} catch (mysqli_sql_exception $e) {
    http_response_code(500);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<p style="font-family:sans-serif;padding:24px">DB에 연결할 수 없습니다. '
       . 'XAMPP에서 MySQL이 켜져 있는지, config.php의 계정 정보가 맞는지 확인하세요.</p>';
    exit;
}

// ---------------------------------------------------------------------
// 작성자: 왕은서 — PHP 세션 시작 (지침 2-(6))
//   세션 키 : user_id, login_id, user_name, role, login_at
// ---------------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
