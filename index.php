<?php
/**
 * index.php : 메인 화면 (요약 통계 + 메뉴)
 * 작성자 : 왕은서 (6. 공통·문서)
 *
 * - 사용자 입력이 없는 고정 조회만 있으므로 일반 query() 사용
 *   (사용자 입력이 들어가는 쿼리는 각 페이지에서 Prepared Statement 사용)
 * - 일별 합계 = 45개 대여소의 rent_count 를 날짜별로 더한 값
 */

$page_title = '홈';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/header.php';

// 작성자: 왕은서 — 화면 출력용 이스케이프 (다른 페이지의 h()와 이름이 겹치지 않게 별도 이름)
function idx_h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

// 작성자: 왕은서 — 한 행만 돌려주는 조회
function idx_row(mysqli $conn, $sql) {
    $res = $conn->query($sql);
    $row = $res->fetch_assoc();
    $res->free();
    return $row;
}

// 작성자: 왕은서 — 여러 행 조회
function idx_rows(mysqli $conn, $sql) {
    $res  = $conn->query($sql);
    $rows = $res->fetch_all(MYSQLI_ASSOC);
    $res->free();
    return $rows;
}

// ---------------------------------------------------------------------
// 1) 전체 요약 (기간, 대여소 수, 총 이용건수, 일평균, 1건당 평균 이용시간)
// ---------------------------------------------------------------------
$summary = idx_row($conn,
    "SELECT MIN(rent_date)                         AS first_date,
            MAX(rent_date)                         AS last_date,
            COUNT(DISTINCT station_id)             AS stations,
            COUNT(DISTINCT rent_date)              AS days,
            SUM(rent_count)                        AS total_rent,
            SUM(total_minutes) / NULLIF(SUM(rent_count), 0) AS avg_minutes
       FROM rental_daily");

$days       = (int)$summary['days'];
$total_rent = (int)$summary['total_rent'];
$daily_avg  = $days > 0 ? $total_rent / $days : 0;

// ---------------------------------------------------------------------
// 2) 가장 많이 탄 날 / 가장 적게 탄 날 (날씨 함께)
// ---------------------------------------------------------------------
$DAY_SQL =
    "SELECT r.rent_date, r.total, w.weather_type, w.avg_temp, w.precipitation, c.day_name
       FROM (SELECT rent_date, SUM(rent_count) AS total
               FROM rental_daily
              GROUP BY rent_date) r
       JOIN weather  w ON w.weather_date = r.rent_date
       JOIN calendar c ON c.cal_date     = r.rent_date
      ORDER BY r.total %s, r.rent_date
      LIMIT 1";
$best  = idx_row($conn, sprintf($DAY_SQL, 'DESC'));
$worst = idx_row($conn, sprintf($DAY_SQL, 'ASC'));

// ---------------------------------------------------------------------
// 3) 날씨별 하루 평균 이용건수 (맑음 기준 비교)
// ---------------------------------------------------------------------
$by_weather = idx_rows($conn,
    "SELECT w.weather_type,
            COUNT(*)     AS days,
            AVG(r.total) AS avg_total
       FROM (SELECT rent_date, SUM(rent_count) AS total
               FROM rental_daily
              GROUP BY rent_date) r
       JOIN weather w ON w.weather_date = r.rent_date
      GROUP BY w.weather_type
      ORDER BY FIELD(w.weather_type, '맑음', '흐림', '비', '눈')");

$weather_max = 0;
$sunny_avg   = null;
foreach ($by_weather as $w) {
    $weather_max = max($weather_max, (float)$w['avg_total']);
    if ($w['weather_type'] === '맑음') {
        $sunny_avg = (float)$w['avg_total'];
    }
}

// ---------------------------------------------------------------------
// 4) 월별 총 이용건수 + 평균기온
// ---------------------------------------------------------------------
$by_month = idx_rows($conn,
    "SELECT c.month,
            SUM(r.rent_count)          AS total,
            (SELECT AVG(w.avg_temp)
               FROM weather w
              WHERE MONTH(w.weather_date) = c.month) AS avg_temp
       FROM rental_daily r
       JOIN calendar c ON c.cal_date = r.rent_date
      GROUP BY c.month
      ORDER BY c.month");

$month_max = 0;
foreach ($by_month as $m) {
    $month_max = max($month_max, (int)$m['total']);
}

// ---------------------------------------------------------------------
// 5) 테이블별 레코드 수 (지침: 전체 1,000건 이상)
// ---------------------------------------------------------------------
$TABLES = ['district', 'station', 'calendar', 'weather', 'rental_daily', 'user_account', 'fault_report'];
$counts = [];
foreach ($TABLES as $t) {      // 테이블 이름은 위 고정 목록에서만 사용
    $counts[$t] = (int)idx_row($conn, "SELECT COUNT(*) AS n FROM `$t`")['n'];
}
$count_total = array_sum($counts);

$fmt = function ($n, $dec = 0) { return number_format((float)$n, $dec); };
?>
<main class="container">
  <h2>날씨와 따릉이 이용량 <span class="owner-tag">메인 대시보드 · 왕은서</span></h2>
  <p class="lead">
    <?= idx_h($summary['first_date']) ?> ~ <?= idx_h($summary['last_date']) ?> 서울 ASOS 일별 날씨와
    9개 자치구 <?= (int)$summary['stations'] ?>개 대여소의 일별 따릉이 이용 기록을 분석합니다.
  </p>

  <!-- 요약 숫자 -->
  <section class="kpis">
    <div class="kpi"><span>총 이용건수</span><strong><?= $fmt($total_rent) ?></strong><small>건</small></div>
    <div class="kpi"><span>하루 평균</span><strong><?= $fmt($daily_avg) ?></strong><small>건 / 일</small></div>
    <div class="kpi"><span>1건당 평균 이용시간</span><strong><?= $fmt($summary['avg_minutes'], 1) ?></strong><small>분</small></div>
    <div class="kpi"><span>분석 기간</span><strong><?= $days ?></strong><small>일 · 대여소 <?= (int)$summary['stations'] ?>개</small></div>
  </section>

  <div class="panels">
    <!-- 가장 많이/적게 탄 날 -->
    <section class="panel">
      <h3>가장 많이 · 적게 탄 날</h3>
      <table class="result">
        <thead><tr><th></th><th>날짜</th><th class="num">이용건수</th><th>날씨</th><th class="num">평균기온</th><th class="num">강수량</th></tr></thead>
        <tbody>
          <?php foreach (['최다' => $best, '최소' => $worst] as $label => $d): if (!$d) continue; ?>
            <tr>
              <td><strong><?= $label ?></strong></td>
              <td><?= idx_h($d['rent_date']) ?> (<?= idx_h($d['day_name']) ?>)</td>
              <td class="num"><?= $fmt($d['total']) ?></td>
              <td><?= idx_h($d['weather_type']) ?></td>
              <td class="num"><?= $fmt($d['avg_temp'], 1) ?>℃</td>
              <td class="num"><?= $fmt($d['precipitation'], 1) ?>mm</td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </section>

    <!-- 날씨별 하루 평균 -->
    <section class="panel">
      <h3>날씨별 하루 평균 이용건수</h3>
      <?php foreach ($by_weather as $w):
          $avg  = (float)$w['avg_total'];
          $diff = ($sunny_avg && $w['weather_type'] !== '맑음') ? ($avg / $sunny_avg - 1) * 100 : null; ?>
        <div class="bar-row">
          <span class="bar-label"><?= idx_h($w['weather_type']) ?> <small><?= (int)$w['days'] ?>일</small></span>
          <span class="bar-track"><span class="w-bar" style="width: <?= $weather_max > 0 ? round($avg / $weather_max * 100, 1) : 0 ?>%"></span></span>
          <span class="bar-value"><?= $fmt($avg) ?>건
            <?php if ($diff !== null): ?><small class="<?= $diff < 0 ? 'down' : 'up' ?>">(맑음 대비 <?= $diff > 0 ? '+' : '' ?><?= $fmt($diff, 1) ?>%)</small><?php endif; ?>
          </span>
        </div>
      <?php endforeach; ?>
    </section>
  </div>

  <!-- 월별 -->
  <section class="panel">
    <h3>월별 총 이용건수와 평균기온</h3>
    <div class="month-chart">
      <?php foreach ($by_month as $m): ?>
        <div class="month-col" title="<?= (int)$m['month'] ?>월 · <?= $fmt($m['total']) ?>건 · <?= $fmt($m['avg_temp'], 1) ?>℃">
          <span class="month-value"><?= $fmt($m['total'] / 1000, 1) ?>천</span>
          <span class="month-bar" style="height: <?= $month_max > 0 ? round($m['total'] / $month_max * 100, 1) : 0 ?>%"></span>
          <span class="month-label"><?= (int)$m['month'] ?>월</span>
          <span class="month-temp"><?= $fmt($m['avg_temp'], 1) ?>℃</span>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- 내 세션 정보 + 최근 본 페이지 (PHP 세션, 지침 2-(6)) — 작성자: 왕은서 -->
  <section class="panel session-panel">
    <h3>내 세션 <small>PHP 세션에 저장된 정보</small></h3>
    <div class="session-grid">
      <dl class="session-info">
        <dt>사용자</dt><dd><?= idx_h($_SESSION['user_name'] ?? '') ?> (<?= idx_h($_SESSION['login_id'] ?? '') ?>)</dd>
        <dt>권한</dt><dd><?= ($_SESSION['role'] ?? '') === 'admin' ? '관리자 · 데이터 수정 가능' : '일반 사용자 · 조회만 가능' ?></dd>
        <dt>로그인 시각</dt><dd><?= idx_h($_SESSION['login_at'] ?? '-') ?></dd>
      </dl>
      <div class="recent">
        <strong>최근 본 페이지</strong>
        <?php if (empty($_SESSION['recent_pages'])): ?>
          <p class="empty-note">아직 없어요. 메뉴에서 페이지를 열면 여기에 기록돼요.</p>
        <?php else: ?>
          <ol>
            <?php foreach ($_SESSION['recent_pages'] as $r):
                if (!isset($MENU[$r['page']])) continue; ?>
              <li><a href="<?= idx_h($r['page']) ?>"><?= idx_h($MENU[$r['page']][0]) ?></a> <small><?= idx_h($r['at']) ?></small></li>
            <?php endforeach; ?>
          </ol>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <!-- 메뉴 -->
  <h3>메뉴</h3>
  <div class="cards">
    <?php foreach ($MENU as $file => [$label, $owner]): if ($file === 'index.php') continue; ?>
      <?php if (file_exists(__DIR__ . '/' . $file)): ?>
        <a class="card" href="<?= $file ?>"><strong><?= $label ?></strong><span><?= $owner ?></span></a>
      <?php else: ?>
        <div class="card todo"><strong><?= $label ?></strong><span>준비 중 · <?= $owner ?></span></div>
      <?php endif; ?>
    <?php endforeach; ?>
  </div>

  <!-- 데이터 현황 -->
  <details class="data-status">
    <summary>데이터 현황 · 테이블 <?= count($counts) ?>개, 레코드 <?= $fmt($count_total) ?>건</summary>
    <table class="result">
      <thead><tr><th>테이블</th><th class="num">레코드 수</th></tr></thead>
      <tbody>
        <?php foreach ($counts as $t => $n): ?>
          <tr><td><?= idx_h($t) ?></td><td class="num"><?= $fmt($n) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </details>
</main>
<?php require_once __DIR__ . '/footer.php'; ?>
