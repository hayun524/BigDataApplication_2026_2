<?php
/**
 * window.php : 이용량 추세 — 이동평균·전일 대비·누적합 (지침 2-(4)-4 Windowing)
 * 작성자 : 왕은서 (5. 분석 B 인수 — 랭킹·추세)
 *
 * 무엇을 보여주나
 *   - 선택한 대상(서울 45개 전체 / 자치구 / 대여소)의 날짜별 지표를 만들고
 *     윈도우 함수로 N일 이동평균, 전일 대비 증감(LAG), 기간 누적합을 계산한다.
 *   - 같은 날의 평균기온과 기온 이동평균도 함께 보여주고, 기온·이용량 상관계수를 계산한다.
 *   - 이동평균이 기간 첫날부터 제대로 나오도록 시작일보다 (N-1)일 앞의 데이터까지 읽어서
 *     계산한 뒤, 화면에는 선택한 기간만 보여준다.
 *
 * 입력 컨트롤 (지침 2-(5))
 *   - radio    : 대상(전체 / 자치구 / 대여소), 이동평균 기간(3·7·14·30일)
 *   - select   : 자치구, 대여소, 지표
 *   - date     : 시작일, 종료일
 *   - checkbox : 표에 보일 열(전일 대비, 누적합, 기온)
 *   - button   : 조회, 초기화, 빠른 기간(봄·여름·가을·겨울)
 *
 * 보안 (지침 2-(4) Prepared Statement)
 *   - 날짜, 자치구, 대여소 번호는 ? 로 바인딩한다.
 *   - 이동평균 기간(정수)과 지표 컬럼은 허용 목록에서만 고른다.
 */

$page_title = '추세';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/header.php';

// ---------------------------------------------------------------------
// 작성자: 왕은서 — 공통 도우미
// ---------------------------------------------------------------------
function wd_h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function wd_valid_date($value, $min, $max) {
    $d = DateTime::createFromFormat('Y-m-d', (string)$value);
    return $d && $d->format('Y-m-d') === $value && $value >= $min && $value <= $max;
}

// 작성자: 왕은서 — 피어슨 상관계수 (기온과 이용량이 같이 움직이는 정도, -1 ~ 1)
function wd_corr(array $x, array $y) {
    $n = count($x);
    if ($n < 3) {
        return null;
    }
    $mx = array_sum($x) / $n;
    $my = array_sum($y) / $n;
    $sxy = $sxx = $syy = 0.0;
    for ($i = 0; $i < $n; $i++) {
        $dx = $x[$i] - $mx;
        $dy = $y[$i] - $my;
        $sxy += $dx * $dy;
        $sxx += $dx * $dx;
        $syy += $dy * $dy;
    }
    return ($sxx > 0 && $syy > 0) ? $sxy / sqrt($sxx * $syy) : null;
}

// ---------------------------------------------------------------------
// 작성자: 왕은서 — 허용 목록 (화이트리스트)
// ---------------------------------------------------------------------
$TARGETS = ['all' => '서울 45개 대여소 전체', 'district' => '자치구', 'station' => '대여소'];
$WINDOWS = [3, 7, 14, 30];
$METRICS = [
    'rent'     => ['label' => '이용건수', 'sql' => 'SUM(r.rent_count)',              'unit' => '건', 'dec' => 0],
    'minutes'  => ['label' => '이용시간', 'sql' => 'SUM(r.total_minutes)',           'unit' => '분', 'dec' => 0],
    'distance' => ['label' => '이동거리', 'sql' => 'SUM(r.total_distance_m) / 1000', 'unit' => 'km', 'dec' => 1],
];
$COLUMNS = ['diff' => '전일 대비 (LAG)', 'cum' => '누적합', 'temp' => '기온'];
$SEASONS = ['봄' => ['03-01', '05-31'], '여름' => ['06-01', '08-31'], '가을' => ['09-01', '11-30'], '겨울' => ['12-01', '12-31']];

// ---------------------------------------------------------------------
// 작성자: 왕은서 — 데이터 기간, 자치구·대여소 목록 (선택 상자용)
// ---------------------------------------------------------------------
$range = $conn->query("SELECT MIN(rent_date) AS min_d, MAX(rent_date) AS max_d FROM rental_daily")->fetch_assoc();
$data_min = $range['min_d'] ?: date('Y-m-d');
$data_max = $range['max_d'] ?: $data_min;
$data_year = substr($data_min, 0, 4);

$districts = $conn->query(
    "SELECT d.district_id, d.district_name
       FROM district d
      WHERE EXISTS (SELECT 1 FROM station s WHERE s.district_id = d.district_id)
      ORDER BY d.district_name"
)->fetch_all(MYSQLI_ASSOC);
$stations = $conn->query(
    "SELECT s.station_id, s.station_name, s.district_id, d.district_name
       FROM station s JOIN district d ON d.district_id = s.district_id
      ORDER BY d.district_name, s.station_name"
)->fetch_all(MYSQLI_ASSOC);
$district_ids = array_map('intval', array_column($districts, 'district_id'));
$station_ids  = array_map('intval', array_column($stations, 'station_id'));

// ---------------------------------------------------------------------
// 작성자: 왕은서 — 사용자 입력 읽기 + 검사 (기본값: 전체, 4~6월, 7일 이동평균)
// ---------------------------------------------------------------------
$searched   = isset($_GET['search']);
$target     = isset($TARGETS[$_GET['target'] ?? '']) ? $_GET['target'] : 'all';
$district   = (int)($_GET['district'] ?? 0);
$station    = (int)($_GET['station'] ?? 0);
$metric_key = isset($METRICS[$_GET['metric'] ?? '']) ? $_GET['metric'] : 'rent';
$win        = (int)($_GET['win'] ?? 7);
if (!in_array($win, $WINDOWS, true)) {
    $win = 7;
}
$default_from = max($data_min, $data_year . '-04-01');
$default_to   = min($data_max, $data_year . '-06-30');
$date_from = wd_valid_date($_GET['date_from'] ?? '', $data_min, $data_max) ? $_GET['date_from'] : $default_from;
$date_to   = wd_valid_date($_GET['date_to'] ?? '', $data_min, $data_max) ? $_GET['date_to'] : $default_to;
$show = $searched ? array_values(array_intersect(array_keys($COLUMNS), (array)($_GET['show'] ?? []))) : array_keys($COLUMNS);

$notice = '';
if ($date_from > $date_to) {
    [$date_from, $date_to] = [$date_to, $date_from];
    $notice = '시작일이 종료일보다 늦어 두 날짜를 바꿔서 조회했어요.';
}
if ($target === 'district' && !in_array($district, $district_ids, true)) {
    $district = $district_ids[0] ?? 0;
}
if ($target === 'station' && !in_array($station, $station_ids, true)) {
    $station = $station_ids[0] ?? 0;
}

// 이동평균용으로 시작일보다 (N-1)일 앞부터 읽기 (데이터 첫날보다 앞으로는 가지 않음)
$read_from = (new DateTime($date_from))->modify('-' . ($win - 1) . ' days')->format('Y-m-d');
$read_from = max($read_from, $data_min);

// ---------------------------------------------------------------------
// 작성자: 왕은서 — 추세 쿼리 (지침 2-(4)-4 Windowing)
//   daily : 날짜별 지표 (대상 조건 적용) + 날씨
//   AVG() OVER (ROWS BETWEEN N-1 PRECEDING AND CURRENT ROW) → N일 이동평균
//   LAG() OVER (ORDER BY 날짜)                              → 전일 값 (증감 계산)
//   SUM() OVER (ROWS UNBOUNDED PRECEDING)                    → 선택 기간 누적합
// ---------------------------------------------------------------------
$target_filter = '';
$t_types  = '';
$t_params = [];
if ($target === 'district') {
    $target_filter = ' AND s.district_id = ?';
    $t_types = 'i';
    $t_params[] = $district;
} elseif ($target === 'station') {
    $target_filter = ' AND r.station_id = ?';
    $t_types = 'i';
    $t_params[] = $station;
}
$metric_sql = $METRICS[$metric_key]['sql'];
$frame = 'ROWS BETWEEN ' . ($win - 1) . ' PRECEDING AND CURRENT ROW';   // $win 은 허용 목록의 정수

$sql = "
WITH daily AS (
    SELECT r.rent_date, {$metric_sql} AS val,
           w.avg_temp, w.precipitation, w.weather_type, c.day_name, c.day_type
      FROM rental_daily r
      JOIN station  s ON s.station_id   = r.station_id
      JOIN weather  w ON w.weather_date = r.rent_date
      JOIN calendar c ON c.cal_date     = r.rent_date
     WHERE r.rent_date BETWEEN ? AND ?{$target_filter}
     GROUP BY r.rent_date, w.avg_temp, w.precipitation, w.weather_type, c.day_name, c.day_type
),
windowed AS (
    SELECT rent_date, val, avg_temp, precipitation, weather_type, day_name, day_type,
           AVG(val)      OVER (ORDER BY rent_date {$frame}) AS ma,
           COUNT(*)      OVER (ORDER BY rent_date {$frame}) AS ma_days,
           AVG(avg_temp) OVER (ORDER BY rent_date {$frame}) AS temp_ma,
           LAG(val)      OVER (ORDER BY rent_date)          AS prev_val,
           SUM(CASE WHEN rent_date >= ? THEN val ELSE 0 END)
                         OVER (ORDER BY rent_date ROWS UNBOUNDED PRECEDING) AS cum
      FROM daily
)
SELECT *
  FROM windowed
 WHERE rent_date BETWEEN ? AND ?
 ORDER BY rent_date";

$rows = [];
$error = '';
try {
    $stmt = $conn->prepare($sql);
    $types  = 'ss' . $t_types . 'sss';
    $params = array_merge([$read_from, $date_to], $t_params, [$date_from, $date_from, $date_to]);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} catch (mysqli_sql_exception $e) {
    $error = '추세를 계산하지 못했어요: ' . $e->getMessage();
}

// ---------------------------------------------------------------------
// 작성자: 왕은서 — 요약 (기간 합계·평균, 이동평균 최고·최저, 기온 상관계수)
// ---------------------------------------------------------------------
$metric = $METRICS[$metric_key];
$fmt = function ($n, $dec = 0) { return $n === null ? '-' : number_format((float)$n, $dec); };
$summary = null;
if ($rows) {
    $vals  = array_map(function ($r) { return (float)$r['val']; }, $rows);
    $temps = array_map(function ($r) { return (float)$r['avg_temp']; }, $rows);
    $peak = $low = $rows[0];
    foreach ($rows as $r) {
        if ((float)$r['ma'] > (float)$peak['ma']) { $peak = $r; }
        if ((float)$r['ma'] < (float)$low['ma'])  { $low = $r; }
    }
    $summary = [
        'days'  => count($rows),
        'total' => array_sum($vals),
        'avg'   => array_sum($vals) / count($vals),
        'peak'  => $peak,
        'low'   => $low,
        'corr'  => wd_corr($temps, $vals),
    ];
}

// 대상 이름 (제목용)
$target_name = $TARGETS['all'];
if ($target === 'district') {
    foreach ($districts as $d) { if ((int)$d['district_id'] === $district) { $target_name = $d['district_name']; } }
} elseif ($target === 'station') {
    foreach ($stations as $st) { if ((int)$st['station_id'] === $station) { $target_name = $st['station_name'] . ' (' . $st['district_name'] . ')'; } }
}

// ---------------------------------------------------------------------
// 작성자: 왕은서 — 추세 그래프 (외부 라이브러리 없이 SVG로 직접 그림)
//   회색 선: 날짜별 값 / 초록 선: N일 이동평균 / 주황 점선: 기온 이동평균(오른쪽 축)
// ---------------------------------------------------------------------
function wd_chart(array $rows, $win, $unit) {
    $n = count($rows);
    if ($n < 2) {
        return '';
    }
    $W = 900; $H = 280; $L = 56; $R = 46; $T = 16; $B = 30;
    $pw = $W - $L - $R; $ph = $H - $T - $B;
    $vals = array_map(function ($r) { return (float)$r['val']; }, $rows);
    $vmax = max($vals) ?: 1;
    $temps = array_map(function ($r) { return (float)$r['temp_ma']; }, $rows);
    $tmin = floor(min($temps) / 5) * 5; $tmax = ceil(max($temps) / 5) * 5;
    if ($tmax <= $tmin) { $tmax = $tmin + 5; }
    $x = function ($i) use ($L, $pw, $n) { return $L + $pw * $i / ($n - 1); };
    $y = function ($v) use ($T, $ph, $vmax) { return $T + $ph * (1 - $v / $vmax); };
    $yt = function ($t) use ($T, $ph, $tmin, $tmax) { return $T + $ph * (1 - ($t - $tmin) / ($tmax - $tmin)); };
    $path = function ($key, $fy) use ($rows, $x) {
        $d = '';
        foreach ($rows as $i => $r) {
            $d .= ($i ? 'L' : 'M') . round($x($i), 1) . ' ' . round($fy((float)$r[$key]), 1);
        }
        return $d;
    };
    $svg = '<svg class="wd-chart" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="날짜별 값과 이동평균, 기온 추세">';
    for ($k = 0; $k <= 4; $k++) {                       // 가로 눈금 (왼쪽: 값, 오른쪽: 기온)
        $gy = $T + $ph * $k / 4;
        $svg .= '<line x1="' . $L . '" x2="' . ($W - $R) . '" y1="' . $gy . '" y2="' . $gy . '" class="wd-grid"/>';
        $svg .= '<text x="' . ($L - 6) . '" y="' . ($gy + 4) . '" text-anchor="end" class="wd-axis">' . number_format($vmax * (1 - $k / 4)) . '</text>';
        $svg .= '<text x="' . ($W - $R + 6) . '" y="' . ($gy + 4) . '" class="wd-axis wd-temp-axis">' . round($tmax - ($tmax - $tmin) * $k / 4) . '℃</text>';
    }
    $step = max(1, (int)ceil($n / 8));                  // 아래 날짜 눈금 (최대 8~9개)
    for ($i = 0; $i < $n; $i += $step) {
        $svg .= '<text x="' . round($x($i), 1) . '" y="' . ($H - 8) . '" text-anchor="middle" class="wd-axis">' . substr($rows[$i]['rent_date'], 5) . '</text>';
    }
    $svg .= '<path d="' . $path('val', $y) . '" class="wd-line-raw"/>';
    $svg .= '<path d="' . $path('temp_ma', $yt) . '" class="wd-line-temp"/>';
    $svg .= '<path d="' . $path('ma', $y) . '" class="wd-line-ma"/>';
    $svg .= '</svg>';
    $svg .= '<div class="wd-legend"><span class="wd-k-raw"></span>날짜별 값(' . $unit . ') <span class="wd-k-ma"></span>' . $win
          . '일 이동평균 <span class="wd-k-temp"></span>기온 ' . $win . '일 이동평균(오른쪽 축)</div>';
    return $svg;
}

function wd_season_url($from, $to) {
    $q = $_GET;
    $q['search'] = '1';
    $q['date_from'] = $from;
    $q['date_to'] = $to;
    return 'window.php?' . http_build_query($q);
}
?>
<style>
/* 작성자: 왕은서 — 추세 페이지 전용 스타일 (.wd- 접두어로 다른 페이지와 겹치지 않게) */
.wd-page .wd-form .row { gap: 14px 26px; }
.wd-page .wd-form .row + .row { margin-top: 14px; }
.wd-page .wd-form label { display: inline-flex; align-items: center; gap: 6px; }
.wd-page .wd-radios { display: inline-flex; flex-wrap: wrap; gap: 4px 14px; }
.wd-page .wd-quick button { padding: 4px 10px; font-size: 13px; }
.wd-page .wd-hidden { display: none !important; }
.wd-page .wd-chart { width: 100%; height: auto; display: block; }
.wd-page .wd-grid { stroke: #e5e7eb; stroke-width: 1; }
.wd-page .wd-axis { fill: var(--muted); font-size: 11px; }
.wd-page .wd-temp-axis { fill: #c2410c; }
.wd-page .wd-line-raw { fill: none; stroke: #b0b8c1; stroke-width: 1.2; }
.wd-page .wd-line-ma { fill: none; stroke: var(--green); stroke-width: 2.6; }
.wd-page .wd-line-temp { fill: none; stroke: #ea8a3c; stroke-width: 1.8; stroke-dasharray: 5 4; }
.wd-page .wd-legend { display: flex; flex-wrap: wrap; gap: 6px 16px; align-items: center; font-size: 12px; color: var(--muted); margin-top: 6px; }
.wd-page .wd-legend span { display: inline-block; width: 18px; height: 3px; margin-right: 4px; vertical-align: middle; }
.wd-page .wd-k-raw { background: #b0b8c1; }
.wd-page .wd-k-ma { background: var(--green); }
.wd-page .wd-k-temp { background: #ea8a3c; }
.wd-page .wd-table-wrap { max-height: 520px; overflow: auto; }
.wd-page table.result th { position: sticky; top: 0; }
.wd-page table.result td, .wd-page table.result th { white-space: nowrap; }
.wd-page .wd-up { color: var(--green-dark); }
.wd-page .wd-down { color: var(--warn); }
.wd-page .wd-partial { color: var(--muted); font-size: 11px; }
.wd-page .wd-sub { color: var(--muted); font-size: 12px; font-weight: 400; }
.wd-page .wd-wkend td:first-child { color: #b42318; }
</style>

<main class="container wd-page">
  <h2>이용량 추세</h2>
  <p class="lead">날짜별 값에 N일 이동평균·전일 대비·누적합을 붙여 흐름을 보고, 기온과 함께 비교해요.</p>

  <!-- 작성자: 왕은서 — 조건 입력 폼 (지침 2-(5) 다양한 입력 컨트롤) -->
  <form class="search-form wd-form" method="get" action="window.php" id="wd-form">
    <input type="hidden" name="search" value="1">
    <div class="row">
      <span>대상
        <span class="wd-radios">
          <?php foreach ($TARGETS as $k => $label): ?>
            <label><input type="radio" name="target" value="<?= $k ?>" <?= $k === $target ? 'checked' : '' ?>> <?= wd_h($k === 'all' ? '전체' : $label) ?></label>
          <?php endforeach; ?>
        </span>
      </span>
      <label class="wd-district">자치구
        <select name="district">
          <?php foreach ($districts as $d): ?>
            <option value="<?= (int)$d['district_id'] ?>" <?= (int)$d['district_id'] === $district ? 'selected' : '' ?>><?= wd_h($d['district_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="wd-station">대여소
        <select name="station">
          <?php $prev_d = null; foreach ($stations as $st):
              if ($st['district_name'] !== $prev_d): if ($prev_d !== null) echo '</optgroup>'; ?>
                <optgroup label="<?= wd_h($st['district_name']) ?>">
              <?php $prev_d = $st['district_name']; endif; ?>
              <option value="<?= (int)$st['station_id'] ?>" <?= (int)$st['station_id'] === $station ? 'selected' : '' ?>><?= wd_h($st['station_name']) ?></option>
          <?php endforeach; if ($prev_d !== null) echo '</optgroup>'; ?>
        </select>
      </label>
      <label>지표
        <select name="metric">
          <?php foreach ($METRICS as $k => $m): ?>
            <option value="<?= $k ?>" <?= $k === $metric_key ? 'selected' : '' ?>><?= wd_h($m['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    </div>
    <div class="row">
      <label>기간
        <input type="date" name="date_from" value="<?= wd_h($date_from) ?>" min="<?= wd_h($data_min) ?>" max="<?= wd_h($data_max) ?>">
        ~
        <input type="date" name="date_to" value="<?= wd_h($date_to) ?>" min="<?= wd_h($data_min) ?>" max="<?= wd_h($data_max) ?>">
      </label>
      <span class="wd-quick">빠른 기간
        <?php foreach ($SEASONS as $name => [$f, $t]): ?>
          <button type="button" onclick="location.href='<?= wd_h(wd_season_url($data_year . '-' . $f, $data_year . '-' . $t)) ?>'"><?= $name ?></button>
        <?php endforeach; ?>
        <button type="button" onclick="location.href='<?= wd_h(wd_season_url($data_min, $data_max)) ?>'">1년</button>
      </span>
    </div>
    <div class="row">
      <span>이동평균
        <span class="wd-radios">
          <?php foreach ($WINDOWS as $n): ?>
            <label><input type="radio" name="win" value="<?= $n ?>" <?= $n === $win ? 'checked' : '' ?>> <?= $n ?>일</label>
          <?php endforeach; ?>
        </span>
      </span>
      <span>표에 보일 열
        <span class="wd-radios">
          <?php foreach ($COLUMNS as $k => $label): ?>
            <label><input type="checkbox" name="show[]" value="<?= $k ?>" <?= in_array($k, $show, true) ? 'checked' : '' ?>> <?= wd_h($label) ?></label>
          <?php endforeach; ?>
        </span>
      </span>
      <button type="submit">조회</button>
      <button type="button" onclick="location.href='window.php'">초기화</button>
    </div>
  </form>

  <?php if ($notice): ?><p class="msg notice"><?= wd_h($notice) ?></p><?php endif; ?>
  <?php if ($error): ?><p class="msg error"><?= wd_h($error) ?></p><?php endif; ?>

  <?php if ($summary): $p = $summary['peak']; $lo = $summary['low']; $c = $summary['corr']; ?>
  <!-- 작성자: 왕은서 — 요약 숫자 -->
  <section class="kpis">
    <div class="kpi"><span><?= wd_h($target_name) ?> · 기간 합계</span><strong><?= $fmt($summary['total'], $metric['dec']) ?></strong><small><?= $metric['unit'] ?> · <?= $summary['days'] ?>일</small></div>
    <div class="kpi"><span>하루 평균</span><strong><?= $fmt($summary['avg'], $metric['dec'] ?: 1) ?></strong><small><?= $metric['unit'] ?></small></div>
    <div class="kpi"><span><?= $win ?>일 이동평균 최고</span><strong><?= $fmt($p['ma'], 1) ?></strong><small><?= wd_h(substr($p['rent_date'], 5)) ?> · 최저 <?= $fmt($lo['ma'], 1) ?> (<?= wd_h(substr($lo['rent_date'], 5)) ?>)</small></div>
    <div class="kpi"><span>기온과 상관계수</span><strong><?= $c === null ? '-' : $fmt($c, 2) ?></strong>
      <small><?= $c === null ? '' : ($c >= 0.3 ? '따뜻할수록 많이 탐' : ($c <= -0.3 ? '추울수록 많이 탐' : '뚜렷한 관계 없음')) ?></small></div>
  </section>

  <!-- 작성자: 왕은서 — 추세 그래프 -->
  <section class="panel">
    <h3><?= wd_h($target_name) ?> · <?= wd_h($metric['label']) ?> 추세</h3>
    <?= wd_chart($rows, $win, $metric['unit']) ?>
  </section>

  <!-- 작성자: 왕은서 — 날짜별 표 (윈도우 함수 결과) -->
  <section class="panel">
    <h3>날짜별 값 <small class="wd-sub"><?= $summary['days'] ?>일</small></h3>
    <div class="wd-table-wrap">
      <table class="result">
        <thead>
          <tr>
            <th>날짜</th><th>날씨</th>
            <th class="num"><?= wd_h($metric['label']) ?></th>
            <th class="num"><?= $win ?>일 이동평균</th>
            <?php if (in_array('diff', $show, true)): ?><th class="num">전일 대비</th><?php endif; ?>
            <?php if (in_array('cum', $show, true)): ?><th class="num">누적합</th><?php endif; ?>
            <?php if (in_array('temp', $show, true)): ?><th class="num">평균기온</th><th class="num">기온 <?= $win ?>일 평균</th><?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r):
              $diff = $r['prev_val'] === null ? null : (float)$r['val'] - (float)$r['prev_val'];
              $rate = ($r['prev_val'] === null || (float)$r['prev_val'] == 0) ? null : $diff / (float)$r['prev_val'] * 100; ?>
            <tr class="<?= $r['day_type'] === '휴일' ? 'wd-wkend' : '' ?>">
              <td><?= wd_h($r['rent_date']) ?> (<?= wd_h($r['day_name']) ?>)</td>
              <td><?= wd_h($r['weather_type']) ?><?= (float)$r['precipitation'] > 0 ? ' <span class="wd-sub">' . $fmt($r['precipitation'], 1) . 'mm</span>' : '' ?></td>
              <td class="num"><?= $fmt($r['val'], $metric['dec']) ?></td>
              <td class="num"><?= $fmt($r['ma'], 1) ?><?= (int)$r['ma_days'] < $win ? ' <span class="wd-partial">(' . (int)$r['ma_days'] . '일치)</span>' : '' ?></td>
              <?php if (in_array('diff', $show, true)): ?>
                <td class="num <?= $diff === null ? '' : ($diff >= 0 ? 'wd-up' : 'wd-down') ?>">
                  <?= $diff === null ? '-' : (($diff > 0 ? '+' : '') . $fmt($diff, $metric['dec'])) ?>
                  <?php if ($rate !== null): ?><span class="wd-sub">(<?= ($rate > 0 ? '+' : '') . $fmt($rate, 1) ?>%)</span><?php endif; ?>
                </td>
              <?php endif; ?>
              <?php if (in_array('cum', $show, true)): ?><td class="num"><?= $fmt($r['cum'], $metric['dec']) ?></td><?php endif; ?>
              <?php if (in_array('temp', $show, true)): ?>
                <td class="num"><?= $fmt($r['avg_temp'], 1) ?>℃</td>
                <td class="num"><?= $fmt($r['temp_ma'], 1) ?>℃</td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
  <?php elseif (!$error): ?>
    <p class="msg notice">조건에 맞는 데이터가 없어요. 기간이나 대상을 바꿔 보세요.</p>
  <?php endif; ?>
</main>

<script>
// 작성자: 왕은서 — 대상에 따라 자치구/대여소 선택 상자만 보이게 (화면 편의 기능, 서버에서도 다시 검사함)
(function () {
  var form = document.getElementById('wd-form');
  function sync() {
    var t = form.querySelector('input[name=target]:checked').value;
    form.querySelector('.wd-district').classList.toggle('wd-hidden', t !== 'district');
    form.querySelector('.wd-station').classList.toggle('wd-hidden', t !== 'station');
  }
  form.querySelectorAll('input[name=target]').forEach(function (el) { el.addEventListener('change', sync); });
  sync();
})();
</script>
<?php require_once __DIR__ . '/footer.php'; ?>
