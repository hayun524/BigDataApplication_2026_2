<?php
/**
 * header.php : 공통 헤더 (로그인 확인 + 메뉴바)
 * 작성자 : 왕은서 (6. 공통·문서)
 *
 * - config.php 다음에 include 한다. 이 파일보다 먼저 HTML을 출력하면 안 된다.
 *   (로그인 안 했을 때 login.php로 보내는 header() 리다이렉트가 있기 때문)
 * - 페이지 제목을 바꾸려면 include 전에 $page_title = '대여소 검색'; 처럼 지정
 * - 각 페이지 본문은 <main class="container"> ... </main> 으로 감싸고,
 *   맨 아래에서 footer.php 를 include 한다.
 * - 함수 이름 h() 는 각 페이지에서 쓰고 있으므로 여기서는 정의하지 않는다.
 */

require_once __DIR__ . '/config.php';

// ---------------------------------------------------------------------
// 작성자: 왕은서 — 로그인 확인 (세션에 user_id 가 없으면 로그인 화면으로)
//   돌아올 페이지 이름은 세션에 저장해 두고, 로그인 후 그 페이지로 보낸다.
// ---------------------------------------------------------------------
$current_page = basename($_SERVER['PHP_SELF']);

if (empty($_SESSION['user_id'])) {
    $_SESSION['return_to'] = $current_page;
    header('Location: login.php');
    exit;
}

// ---------------------------------------------------------------------
// 작성자: 왕은서 — 메뉴 목록 (파일명 => [메뉴 이름, 짧은 설명])
//   아직 없는 파일은 회색으로 표시만 하고 링크는 걸지 않는다.
//   담당자 이름은 화면에 표시하지 않는다 (팀 합의: 보고서·PPT에만 표기, 코드에는 작성자 주석)
// ---------------------------------------------------------------------
$MENU = [
    'index.php'          => ['홈',          '요약 통계'],
    'station_list.php'   => ['대여소',      '대여소 검색'],
    'weather_manage.php' => ['날씨',        '날씨 조회·수정'],
    'station_form.php'   => ['대여소 관리', '대여소 등록·수정·삭제'],
    'report.php'         => ['고장신고',    '고장 신고 접수·처리'],
    'aggregate.php'      => ['집계',        '조건별 다중 그룹 집계'],
    'rollup.php'         => ['롤업',        '롤업·드릴다운'],
    'ranking.php'        => ['랭킹',        '대여소 순위'],
    'window.php'         => ['추세',        '이동평균·추세'],
];

// ---------------------------------------------------------------------
// 작성자: 왕은서 — 최근 본 페이지 기록 (PHP 세션, 지침 2-(6))
//   메뉴에 있는 페이지를 열 때마다 세션에 [페이지, 시각]을 저장한다.
//   같은 페이지는 맨 앞으로 옮기고 최대 5개까지만 남긴다. 홈(index.php)은 제외
// ---------------------------------------------------------------------
if (isset($MENU[$current_page]) && $current_page !== 'index.php') {
    $recent = [];
    foreach ($_SESSION['recent_pages'] ?? [] as $r) {
        if ($r['page'] !== $current_page) {
            $recent[] = $r;
        }
    }
    array_unshift($recent, ['page' => $current_page, 'at' => date('H:i')]);
    $_SESSION['recent_pages'] = array_slice($recent, 0, 5);
}

$page_title = $page_title ?? ($MENU[$current_page][0] ?? '따릉이 × 날씨');
$user_name  = htmlspecialchars($_SESSION['user_name'] ?? '', ENT_QUOTES, 'UTF-8');
$is_admin   = ($_SESSION['role'] ?? '') === 'admin';
$login_time = isset($_SESSION['login_at']) ? substr($_SESSION['login_at'], 11, 5) : '';
?>
<!DOCTYPE html>
<html lang="ko">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8') ?> · 따릉이 × 날씨</title>
  <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<header class="site-header">
  <div class="bar">
    <a class="brand" href="index.php">따릉이 × 날씨 <small>team06 식스센스</small></a>
    <div class="user">
      <?= $user_name ?>님<?= $is_admin ? ' <span class="badge">관리자</span>' : '' ?>
      <?php if ($login_time !== ''): ?><span class="login-at">로그인 <?= $login_time ?></span><?php endif; ?>
      <a class="logout" href="logout.php">로그아웃</a>
    </div>
  </div>
  <nav class="menu">
    <?php foreach ($MENU as $file => [$label, $desc]): ?>
      <?php if (file_exists(__DIR__ . '/' . $file)): ?>
        <a href="<?= $file ?>" class="<?= $file === $current_page ? 'active' : '' ?>"><?= $label ?></a>
      <?php else: ?>
        <span class="todo" title="준비 중"><?= $label ?></span>
      <?php endif; ?>
    <?php endforeach; ?>
  </nav>
</header>
