<?php
/**
 * login.php : 로그인 (PHP 세션, 지침 2-(6))
 * 작성자 : 왕은서 (6. 공통·문서)
 *
 * 입력 컨트롤 : text(아이디), password(비밀번호), checkbox(아이디 기억), button(로그인)
 * user_account 의 password_hash 는 PHP password_hash() 결과 → password_verify() 로 확인
 * 성공 시 세션에 user_id, login_id, user_name, role, login_at 저장
 */

require_once __DIR__ . '/config.php';   // header.php 는 로그인 확인을 하므로 여기서는 include 하지 않음

// 작성자: 왕은서 — 로그인 후 돌아갈 페이지 (같은 폴더의 .php 파일만 허용)
function login_return_page() {
    $page = $_SESSION['return_to'] ?? 'index.php';
    unset($_SESSION['return_to']);
    if (!preg_match('/^[a-z0-9_]+\.php$/', $page) || $page === 'login.php' || !file_exists(__DIR__ . '/' . $page)) {
        $page = 'index.php';
    }
    return $page;
}

// 이미 로그인했다면 바로 이동
if (!empty($_SESSION['user_id'])) {
    header('Location: ' . login_return_page());
    exit;
}

$error    = '';
$notice   = isset($_GET['logout']) ? '로그아웃되었습니다.' : '';
$login_id = $_COOKIE['remember_login_id'] ?? '';

// ---------------------------------------------------------------------
// 작성자: 왕은서 — 로그인 처리 (Prepared Statement)
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login_id = trim($_POST['login_id'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($login_id === '' || $password === '') {
        $error = '아이디와 비밀번호를 입력하세요.';
    } else {
        $stmt = $conn->prepare(
            'SELECT user_id, login_id, password_hash, user_name, role
               FROM user_account
              WHERE login_id = ?'
        );
        $stmt->bind_param('s', $login_id);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($user && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);    // 세션 고정 공격 방지
            $_SESSION['user_id']   = (int)$user['user_id'];
            $_SESSION['login_id']  = $user['login_id'];
            $_SESSION['user_name'] = $user['user_name'];
            $_SESSION['role']      = $user['role'];
            $_SESSION['login_at']  = date('Y-m-d H:i:s');

            // 아이디 기억 체크박스 → 쿠키 30일
            if (!empty($_POST['remember'])) {
                setcookie('remember_login_id', $user['login_id'], time() + 60 * 60 * 24 * 30, '', '', false, true);
            } else {
                setcookie('remember_login_id', '', time() - 3600, '', '', false, true);
            }

            header('Location: ' . login_return_page());
            exit;
        }
        $error = '아이디 또는 비밀번호가 올바르지 않습니다.';
    }
}

function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="ko">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>로그인 · 따릉이 × 날씨</title>
  <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="login-page">
  <main class="login-box">
    <h1>따릉이 × 날씨</h1>
    <p class="sub">2024년 서울 날씨와 따릉이 이용량 분석 · team06 식스센스</p>

    <?php if ($error): ?><p class="msg error"><?= e($error) ?></p><?php endif; ?>
    <?php if ($notice): ?><p class="msg notice"><?= e($notice) ?></p><?php endif; ?>

    <form method="post" action="login.php">
      <label>아이디
        <input type="text" name="login_id" value="<?= e($login_id) ?>" maxlength="30" required autofocus>
      </label>
      <label>비밀번호
        <input type="password" name="password" maxlength="100" required>
      </label>
      <label class="check">
        <input type="checkbox" name="remember" value="1" <?= $login_id !== '' ? 'checked' : '' ?>> 아이디 기억하기
      </label>
      <button type="submit">로그인</button>
    </form>
  </main>
</body>
</html>
