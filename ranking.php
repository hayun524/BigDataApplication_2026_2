<?php
/**
 * ranking.php : 대여소 랭킹 (지침 2-(4)-3 Ranking)
 * 작성자 : 왕은서 (5. 분석 B 인수 — 랭킹·추세)
 *
 * 무엇을 보여주나
 *   - 날씨·기간·지역 조건에 맞는 날만 모아서 대여소별 지표를 계산하고 순위를 매긴다.
 *   - 같은 결과에 "전체 순위"와 "자치구 안 순위"를 함께 계산해 PARTITION BY 차이를 보여준다.
 *   - "날씨 유지율" = 선택한 날씨의 하루 평균 ÷ 같은 기간 전체 날의 하루 평균 × 100
 *     (비 오는 날에도 평소만큼 쓰이는 대여소를 찾는 지표)
 *
 * 입력 컨트롤 (지침 2-(5))
 *   - select   : 날씨, 순위 기준 지표, 자치구
 *   - radio    : 순위 범위(전체 / 자치구별), 순위 함수(RANK / DENSE_RANK / ROW_NUMBER)
 *   - date     : 시작일, 종료일
 *   - number   : 상위 N개
 *   - checkbox : 한강공원 대여소만
 *   - button   : 조회, 초기화
 *
 * 보안 (지침 2-(4) Prepared Statement)
 *   - 날짜, 날씨, 자치구, 상위 N 은 ? 로 바인딩한다.
 *   - 컬럼·정렬식·순위 함수처럼 바인딩할 수 없는 값은 아래 허용 목록에서만 고른다.
 */

$page_title = '랭킹';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/header.php';

// ---------------------------------------------------------------------
// 작성자: 왕은서 — 공통 도우미
// ---------------------------------------------------------------------
function rk_h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function rk_valid_date($value, $min, $max) {
    if (!is_string($value) || !preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $value)) {
        return false;
    }
    $d = DateTime::createFromFormat('!Y-m-d', $value);
    return $d && $d->format('Y-m-d') === $value && $value >= $min && $value <= $max;
}

// ---------------------------------------------------------------------
// 작성자: 왕은서 — 허용 목록 (화이트리스트)
//   사용자는 키만 고르고, 실제 SQL 조각은 서버에 있는 값만 쓴다.
// ---------------------------------------------------------------------
$WEATHERS = ['all' => '전체 날씨', '맑음' => '맑음', '흐림' => '흐림', '비' => '비', '눈' => '눈'];

$METRICS = [
    'avg_rent'  => ['label' => '하루 평균 이용건수',  'unit' => '건',  'dec' => 1],
    'total_rent'=> ['label' => '총 이용건수',          'unit' => '건',  'dec' => 0],
    'avg_min'   => ['label' => '1건당 평균 이용시간',  'unit' => '분',  'dec' => 1],
    'avg_km'    => ['label' => '1건당 평균 이동거리',  'unit' => 'km', 'dec' => 2],
    'retention' => ['label' => '날씨 유지율',          'unit' => '%',   'dec' => 1],
];

$RANK_FUNCS = [
    'rank'       => ['sql' => 'RANK()',       'label' => 'RANK',       'help' => '동점이면 같은 순위, 다음 순위는 건너뜀 (1,1,3)'],
    'dense_rank' => ['sql' => 'DENSE_RANK()', 'label' => 'DENSE_RANK', 'help' => '동점이면 같은 순위, 다음 순위는 이어짐 (1,1,2)'],
    'row_number' => ['sql' => 'ROW_NUMBER()', 'label' => 'ROW_NUMBER', 'help' => '동점이어도 1,2,3 순서대로'],
];

$SCOPES = ['all' => '전체 순위', 'district' => '자치구별 순위'];

// ---------------------------------------------------------------------
// 작성자: 왕은서 — 데이터 기간, 자치구 목록 (선택 상자용)
// ---------------------------------------------------------------------
$range = $conn->query("SELECT MIN(rent_date) AS min_d, MAX(rent_date) AS max_d FROM rental_daily")->fetch_assoc();
$data_min = $range['min_d'] ?: date('Y-m-d');
$data_max = $range['max_d'] ?: $data_min;

$districts = $conn->query(
    "SELECT d.district_id, d.district_name, d.life_zone, d.district_type
       FROM district d
      WHERE EXISTS (SELECT 1 FROM station s WHERE s.district_id = d.district_id)
      ORDER BY FIELD(d.life_zone, '도심권','동북권','서북권','서남권','동남권'), d.district_name"
)->fetch_all(MYSQLI_ASSOC);
$district_ids = array_map('intval', array_column($districts, 'district_id'));

// ---------------------------------------------------------------------
// 작성자: 왕은서 — 사용자 입력 읽기 + 검사 (기본값: 비 오는 날 유지율, 자치구별 상위 3)
// ---------------------------------------------------------------------
$searched   = isset($_GET['search']);
$weather    = is_string($_GET['weather'] ?? null) && isset($WEATHERS[$_GET['weather']]) ? $_GET['weather'] : '비';
$metric_key = is_string($_GET['metric'] ?? null) && isset($METRICS[$_GET['metric']]) ? $_GET['metric'] : 'retention';
$func_key   = is_string($_GET['func'] ?? null) && isset($RANK_FUNCS[$_GET['func']]) ? $_GET['func'] : 'rank';
$scope      = is_string($_GET['scope'] ?? null) && isset($SCOPES[$_GET['scope']]) ? $_GET['scope'] : 'district';
$district   = filter_var($_GET['district'] ?? 0, FILTER_VALIDATE_INT);
if (!in_array($district, $district_ids, true)) {
    $district = 0;                                   // 0 = 전체 자치구
}
$top_n = filter_var($_GET['top_n'] ?? 3, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 45]]);
if ($top_n === false) {
    $top_n = 3;
}
$hangang_only = ($_GET['hangang'] ?? null) === '1';
$date_from = rk_valid_date($_GET['date_from'] ?? '', $data_min, $data_max) ? $_GET['date_from'] : $data_min;
$date_to   = rk_valid_date($_GET['date_to'] ?? '', $data_min, $data_max) ? $_GET['date_to'] : $data_max;

$notice = '';
if ($date_from > $date_to) {
    [$date_from, $date_to] = [$date_to, $date_from];
    $notice = '시작일이 종료일보다 늦어 두 날짜를 바꿔서 조회했어요.';
}
if ($district > 0 && $scope === 'all') {
    $scope = 'district';
    $notice = trim($notice . ' 자치구를 고르면 그 구 안 순위로 보여줘요. (전체 순위도 옆 칸에 같이 나와요)');
}
if ($metric_key === 'retention' && $weather === 'all') {
    $notice = trim($notice . ' 날씨 유지율은 날씨를 하나 골라야 의미가 있어서 "하루 평균 이용건수"로 바꿔 조회했어요.');
    $metric_key = 'avg_rent';
}

// ---------------------------------------------------------------------
// 작성자: 왕은서 — 순위 쿼리 (지침 2-(4)-3 Ranking, 윈도우 순위 함수)
//   1) picked  : 조건에 맞는 날만 모아 대여소별 지표 계산
//   2) baseline: 같은 기간 전체 날의 대여소별 하루 평균 (유지율 분모)
//   3) ranked  : 전체 순위 + 자치구 안 순위(PARTITION BY district_id)를 함께 계산
//   4) 선택한 범위의 순위로 상위 N 만 남김
// ---------------------------------------------------------------------
$METRIC_SQL = [
    'avg_rent'   => 'p.avg_rent',
    'total_rent' => 'p.total_rent',
    'avg_min'    => 'p.avg_min',
    'avg_km'     => 'p.avg_km',
    'retention'  => 'p.avg_rent / NULLIF(b.base_avg, 0) * 100',
];
$metric_sql = $METRIC_SQL[$metric_key];
$rank_fn    = $RANK_FUNCS[$func_key]['sql'];
$rank_col   = $scope === 'district' ? 'district_rank' : 'overall_rank';

$where  = 'r.rent_date BETWEEN ? AND ?';
$types  = 'ss';
$params = [$date_from, $date_to];
$weather_join = '';
if ($weather !== 'all') {
    $weather_join = 'JOIN weather w ON w.weather_date = r.rent_date';
    $where   .= ' AND w.weather_type = ?';
    $types   .= 's';
    $params[] = $weather;
}
// 한강공원만: 순위를 매길 대상 자체를 한강공원 대여소로 좁힘 (안쪽 WHERE)
$station_filter = $hangang_only ? ' AND s.hangang_park IS NOT NULL' : '';
// 자치구 선택: 순위는 전체 대여소 기준으로 매긴 뒤, 결과에서 그 구만 보여줌 (바깥 WHERE)
$outer_filter = $district > 0 ? ' AND district_id = ?' : '';

$sql = "
WITH picked AS (
    SELECT r.station_id,
           COUNT(*)                                            AS days,
           AVG(r.rent_count)                                   AS avg_rent,
           SUM(r.rent_count)                                   AS total_rent,
           SUM(r.total_minutes) / NULLIF(SUM(r.rent_count), 0)          AS avg_min,
           SUM(r.total_distance_m) / NULLIF(SUM(r.rent_count), 0) / 1000 AS avg_km
      FROM rental_daily r
      {$weather_join}
     WHERE {$where}
     GROUP BY r.station_id
),
baseline AS (
    SELECT r.station_id, AVG(r.rent_count) AS base_avg
      FROM rental_daily r
     WHERE r.rent_date BETWEEN ? AND ?
     GROUP BY r.station_id
),
ranked AS (
    SELECT s.station_id, s.station_name, s.hangang_park, s.status,
           s.district_id, d.district_name, d.district_type,
           p.days, p.avg_rent, p.total_rent, p.avg_min, p.avg_km,
           b.base_avg,
           {$metric_sql} AS metric_value,
           {$rank_fn} OVER (ORDER BY {$metric_sql} DESC)                          AS overall_rank,
           {$rank_fn} OVER (PARTITION BY s.district_id ORDER BY {$metric_sql} DESC) AS district_rank
      FROM picked p
      JOIN baseline b ON b.station_id = p.station_id
      JOIN station  s ON s.station_id = p.station_id
      JOIN district d ON d.district_id = s.district_id
     WHERE 1 = 1 {$station_filter}
)
SELECT *
  FROM ranked
 WHERE {$rank_col} <= ?{$outer_filter}
 ORDER BY " . ($scope === 'district' ? 'district_name, district_rank, station_name' : 'overall_rank, station_name');

$rows = [];
$error = '';
try {
    $stmt = $conn->prepare($sql);
    $all_types  = $types . 'ss' . 'i' . ($district > 0 ? 'i' : '');
    $all_params = array_merge($params, [$date_from, $date_to], [$top_n], $district > 0 ? [$district] : []);
    $stmt->bind_param($all_types, ...$all_params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} catch (mysqli_sql_exception $e) {
    $error = '순위를 계산하지 못했어요: ' . $e->getMessage();
}

// 작성자: 왕은서 — 자치구별 보기일 때 구 단위로 묶기
$groups = [];
foreach ($rows as $row) {
    $key = $scope === 'district' ? $row['district_name'] : '전체';
    $groups[$key][] = $row;
}

$metric = $METRICS[$metric_key];
$fmt = function ($n, $dec = 0) { return $n === null ? '-' : number_format((float)$n, $dec); };
$weather_label = $WEATHERS[$weather];
?>
<style>
/* 작성자: 왕은서 — 랭킹 페이지 전용 스타일 (.rk- 접두어로 다른 페이지와 겹치지 않게) */
.rk-page .rk-form .row { gap: 14px 26px; }
.rk-page .rk-form .row + .row { margin-top: 14px; }
.rk-page .rk-form label { display: inline-flex; align-items: center; gap: 6px; }
.rk-page .rk-form .rk-radios { display: inline-flex; flex-wrap: wrap; gap: 4px 14px; }
.rk-page .rk-form input[type=number] { width: 70px; }
.rk-page .rk-help { color: var(--muted); font-size: 12px; margin-top: 8px; }
.rk-page .rk-summary { color: var(--muted); margin: 0 0 12px; font-size: 14px; }
.rk-page .rk-summary strong { color: var(--green-dark); }
.rk-page .rk-groups { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(480px, 100%), 1fr)); gap: 14px; }
.rk-page .rk-groups.rk-single { grid-template-columns: 1fr; }
.rk-page .rk-group h3 { display: flex; justify-content: space-between; align-items: baseline; }
.rk-page .rk-group h3 small { color: var(--muted); font-weight: 400; font-size: 12px; }
.rk-page .rk-medal { display: inline-block; min-width: 26px; text-align: center; font-weight: 700; border-radius: 6px; padding: 1px 4px; }
.rk-page .rk-m1 { background: #fde68a; }
.rk-page .rk-m2 { background: #e5e7eb; }
.rk-page .rk-m3 { background: #fcd9bd; }
.rk-page .rk-bar-track { display: inline-block; width: 60px; height: 8px; background: #eef2ef; border-radius: 4px; vertical-align: middle; margin-right: 6px; overflow: hidden; }
.rk-page .rk-bar-fill { display: block; height: 100%; background: var(--green); }
.rk-page .rk-tag { font-size: 11px; color: var(--green-dark); background: #e8f5ec; border-radius: 999px; padding: 1px 6px; margin-left: 4px; }
.rk-page .rk-sub { color: var(--muted); font-size: 12px; }
.rk-page .rk-table-wrap { overflow-x: auto; }
.rk-page table.result td, .rk-page table.result th { white-space: nowrap; }
.rk-page table.result td:nth-child(2) { white-space: normal; min-width: 140px; }
</style>

<main class="container rk-page">
  <h2>대여소 랭킹</h2>
  <p class="lead">날씨·기간 조건에 맞는 날만 모아 대여소 순위를 매겨요. 전체 순위와 자치구 안 순위를 함께 계산해요.</p>

  <!-- 작성자: 왕은서 — 조건 입력 폼 (지침 2-(5) 다양한 입력 컨트롤) -->
  <form class="search-form rk-form" method="get" action="ranking.php">
    <input type="hidden" name="search" value="1">
    <div class="row">
      <label>날씨
        <select name="weather">
          <?php foreach ($WEATHERS as $k => $label): ?>
            <option value="<?= rk_h($k) ?>" <?= $k === $weather ? 'selected' : '' ?>><?= rk_h($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>순위 기준
        <select name="metric">
          <?php foreach ($METRICS as $k => $m): ?>
            <option value="<?= $k ?>" <?= $k === $metric_key ? 'selected' : '' ?>><?= rk_h($m['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>기간
        <input type="date" name="date_from" value="<?= rk_h($date_from) ?>" min="<?= rk_h($data_min) ?>" max="<?= rk_h($data_max) ?>">
        ~
        <input type="date" name="date_to" value="<?= rk_h($date_to) ?>" min="<?= rk_h($data_min) ?>" max="<?= rk_h($data_max) ?>">
      </label>
    </div>
    <div class="row">
      <span>범위
        <span class="rk-radios">
          <?php foreach ($SCOPES as $k => $label): ?>
            <label><input type="radio" name="scope" value="<?= $k ?>" <?= $k === $scope ? 'checked' : '' ?>> <?= rk_h($label) ?></label>
          <?php endforeach; ?>
        </span>
      </span>
      <label>자치구
        <select name="district">
          <option value="0">전체 자치구</option>
          <?php foreach ($districts as $d): ?>
            <option value="<?= (int)$d['district_id'] ?>" <?= (int)$d['district_id'] === $district ? 'selected' : '' ?>>
              <?= rk_h($d['district_name']) ?> (<?= rk_h($d['life_zone']) ?> · <?= rk_h($d['district_type']) ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>상위 <input type="number" name="top_n" value="<?= (int)$top_n ?>" min="1" max="45"> 개</label>
      <label><input type="checkbox" name="hangang" value="1" <?= $hangang_only ? 'checked' : '' ?>> 한강공원 대여소만</label>
    </div>
    <div class="row">
      <span>순위 함수
        <span class="rk-radios">
          <?php foreach ($RANK_FUNCS as $k => $f): ?>
            <label title="<?= rk_h($f['help']) ?>"><input type="radio" name="func" value="<?= $k ?>" <?= $k === $func_key ? 'checked' : '' ?>> <?= $f['label'] ?></label>
          <?php endforeach; ?>
        </span>
      </span>
      <button type="submit">조회</button>
      <button type="button" onclick="location.href='ranking.php'">초기화</button>
    </div>
    <p class="rk-help"><?= rk_h($RANK_FUNCS[$func_key]['label']) ?>: <?= rk_h($RANK_FUNCS[$func_key]['help']) ?>
      <?php if ($metric_key === 'retention'): ?> · 날씨 유지율 = <?= rk_h($weather_label) ?> 날 하루 평균 ÷ 같은 기간 전체 하루 평균 × 100<?php endif; ?></p>
  </form>

  <?php if ($notice): ?><p class="msg notice"><?= rk_h($notice) ?></p><?php endif; ?>
  <?php if ($error): ?><p class="msg error"><?= rk_h($error) ?></p><?php endif; ?>

  <!-- 작성자: 왕은서 — 순위 결과 -->
  <p class="rk-summary">
    <strong><?= rk_h($weather_label) ?></strong> · <?= rk_h($date_from) ?> ~ <?= rk_h($date_to) ?> ·
    <strong><?= rk_h($metric['label']) ?></strong> 기준 <?= rk_h($SCOPES[$scope]) ?> 상위 <?= (int)$top_n ?>개
    <?= $hangang_only ? '· 한강공원 대여소만' : '' ?> · <?= rk_h($RANK_FUNCS[$func_key]['label']) ?>
  </p>

  <?php if (!$rows && !$error): ?>
    <p class="msg notice">조건에 맞는 대여소가 없어요. 기간이나 날씨를 바꿔 보세요.</p>
  <?php endif; ?>

  <div class="rk-groups <?= $scope === 'all' ? 'rk-single' : '' ?>">
    <?php foreach ($groups as $group_name => $items):
        $max_val = max(array_map(function ($r) { return (float)$r['metric_value']; }, $items)) ?: 1; ?>
      <section class="panel rk-group">
        <h3><?= rk_h($group_name) ?> <small><?= count($items) ?>곳</small></h3>
        <div class="rk-table-wrap">
          <table class="result">
            <thead>
              <tr>
                <th>순위</th><th>대여소</th>
                <?php if ($scope === 'all'): ?><th>자치구</th><?php endif; ?>
                <th class="num"><?= rk_h($metric['label']) ?></th>
                <th class="num"><?= $scope === 'district' ? '전체 순위' : '구 안 순위' ?></th>
                <th class="num">해당 일수</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($items as $r):
                  $rank = (int)$r[$rank_col];
                  $medal = $rank <= 3 ? ' rk-m' . $rank : ''; ?>
                <tr>
                  <td><span class="rk-medal<?= $medal ?>"><?= $rank ?></span></td>
                  <td><?= rk_h($r['station_name']) ?>
                    <?php if ($r['hangang_park']): ?><span class="rk-tag"><?= rk_h($r['hangang_park']) ?> 한강공원</span><?php endif; ?>
                    <?php if ($r['status'] === '점검중'): ?><span class="rk-tag st-warn">점검중</span><?php endif; ?>
                  </td>
                  <?php if ($scope === 'all'): ?><td><?= rk_h($r['district_name']) ?></td><?php endif; ?>
                  <td class="num">
                    <span class="rk-bar-track"><span class="rk-bar-fill" style="width: <?= round((float)$r['metric_value'] / $max_val * 100, 1) ?>%"></span></span>
                    <?= $fmt($r['metric_value'], $metric['dec']) ?><?= rk_h($metric['unit']) ?>
                  </td>
                  <td class="num rk-sub"><?= (int)($scope === 'district' ? $r['overall_rank'] : $r['district_rank']) ?>위</td>
                  <td class="num rk-sub"><?= (int)$r['days'] ?>일</td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>
    <?php endforeach; ?>
  </div>
</main>
<?php require_once __DIR__ . '/footer.php'; ?>
