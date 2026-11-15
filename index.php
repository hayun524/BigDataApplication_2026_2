<?php
/**
 * index.php : 메인 화면
 * 작성자 : 왕은서 (6. 공통·문서)
 *
 * 지금은 로그인 후 첫 화면 + 메뉴 안내만 있는 임시 버전.
 * 데이터 확정 후(11/17 1년치 dbinsert) 요약 통계(총 대여 건수, 가장 많이 탄 날 등)를 추가한다.
 */

$page_title = '홈';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/header.php';
?>
<main class="container">
  <h2>날씨와 따릉이 이용량</h2>
  <p>2024년 서울 ASOS 일별 날씨와 9개 자치구 45개 대여소의 일별 따릉이 이용 기록을 분석합니다.</p>

  <div class="cards">
    <?php foreach ($MENU as $file => [$label, $owner]): if ($file === 'index.php') continue; ?>
      <?php if (file_exists(__DIR__ . '/' . $file)): ?>
        <a class="card" href="<?= $file ?>"><strong><?= $label ?></strong><span><?= $owner ?></span></a>
      <?php else: ?>
        <div class="card todo"><strong><?= $label ?></strong><span>준비 중 · <?= $owner ?></span></div>
      <?php endif; ?>
    <?php endforeach; ?>
  </div>
</main>
<?php require_once __DIR__ . '/footer.php'; ?>
