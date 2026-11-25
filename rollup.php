<?php
/**
 * rollup.php : 롤업·드릴다운 집계 (SELECT + GROUP BY WITH ROLLUP)
 * 작성자 : 유지민
 *
 * 입력 컨트롤
 *   - button : 기간별/지역별 집계, 조회, 초기화, 상위로, 처음으로
 *   - select : 조회 연도, 생활권, 자치구
 *   - row    : 계절 → 월 → 일, 자치구 → 대여소 드릴다운
 *
 * 집계 기준과 단계는 서버의 화이트리스트에서만 SQL 표현식을 선택한다.
 * 조회 조건 값은 Prepared Statement로 바인딩한다.
 * MariaDB에는 GROUPING()이 없으므로 GROUP BY 키의 NULL 패턴으로
 * 상세·소계·전체 합계 행을 구분하고, 현재 탐색 위치는 세션에 저장한다.
 */

$page_title = '롤업';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/header.php';

function roll_h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function roll_int($value, $default = 0) {
    return ctype_digit((string)$value) ? (int)$value : $default;
}

function roll_query_url(array $base, array $changes = []) {
    $query = array_merge($base, $changes);
    foreach ($query as $key => $value) {
        if ($value === null || $value === '') {
            unset($query[$key]);
        }
    }
    return 'rollup.php?' . http_build_query($query);
}

function roll_bind_execute(mysqli $conn, string $sql, string $types, array $params) {
    $stmt = $conn->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    return $stmt;
}

$MODES = ['time', 'space'];
$SEASONS = ['봄', '여름', '가을', '겨울'];
$LIFE_ZONES = ['도심권', '동북권', '서북권', '서남권', '동남권'];

$years = [];
$year_result = $conn->query(
    "SELECT DISTINCT c.year
       FROM calendar c
       JOIN rental_daily r ON r.rent_date = c.cal_date
      ORDER BY c.year DESC"
);
while ($year_row = $year_result->fetch_assoc()) {
    $years[] = (int)$year_row['year'];
}
if (!$years) {
    $years[] = (int)date('Y');
}

$districts = [];
$district_map = [];
$district_result = $conn->query(
    "SELECT district_id, district_name, life_zone
       FROM district
      ORDER BY FIELD(life_zone, '도심권','동북권','서북권','서남권','동남권'), district_name"
);
while ($district_row = $district_result->fetch_assoc()) {
    $district_row['district_id'] = (int)$district_row['district_id'];
    $districts[] = $district_row;
    $district_map[$district_row['district_id']] = $district_row;
}

$saved = isset($_SESSION['rollup_state']) && is_array($_SESSION['rollup_state'])
    ? $_SESSION['rollup_state'] : [];

if (isset($_GET['reset_all'])) {
    $saved = [];
    unset($_SESSION['rollup_state']);
}

$mode_input = $_GET['mode'] ?? ($saved['mode'] ?? 'time');
$mode = in_array($mode_input, $MODES, true) ? $mode_input : 'time';

$year_input = roll_int($_GET['year'] ?? ($saved['year'] ?? $years[0]), $years[0]);
$year = in_array($year_input, $years, true) ? $year_input : $years[0];

$life_zone_input = (string)($_GET['life_zone'] ?? ($saved['life_zone'] ?? ''));
$life_zone = in_array($life_zone_input, $LIFE_ZONES, true) ? $life_zone_input : '';

$filter_district_id = roll_int(
    $_GET['filter_district_id'] ?? ($saved['filter_district_id'] ?? 0),
    0
);
if (!isset($district_map[$filter_district_id])) {
    $filter_district_id = 0;
}
if ($filter_district_id > 0 && $life_zone !== ''
    && $district_map[$filter_district_id]['life_zone'] !== $life_zone) {
    $filter_district_id = 0;
}

$depth = roll_int($_GET['depth'] ?? ($saved['depth'] ?? 0), 0);
$season = (string)($_GET['season'] ?? ($saved['season'] ?? ''));
$month = roll_int($_GET['month'] ?? ($saved['month'] ?? 0), 0);
$path_district_id = roll_int(
    $_GET['path_district_id'] ?? ($saved['path_district_id'] ?? 0),
    0
);

if (isset($_GET['apply']) || isset($_GET['reset_path'])) {
    $depth = 0;
    $season = '';
    $month = 0;
    $path_district_id = 0;
}

if ($mode === 'time') {
    $depth = min($depth, 2);
    if (!in_array($season, $SEASONS, true)) {
        $season = '';
        $depth = 0;
    }
    if ($depth >= 2 && ($month < 1 || $month > 12)) {
        $month = 0;
        $depth = 1;
    }
    $path_district_id = 0;
} else {
    $depth = min($depth, 1);
    if (!isset($district_map[$path_district_id])) {
        $path_district_id = 0;
        $depth = 0;
    }
    $season = '';
    $month = 0;
}

$LEVELS = [
    'time' => [
        0 => [
            'groups' => ['c.season'],
            'selects' => ['c.season AS key_0'],
            'unit' => '계절',
            'title' => '계절별 대여 현황',
            'graph_title' => '계절별 이용 비교',
            'next' => '월별 보기',
        ],
        1 => [
            'groups' => ['c.season', 'c.month'],
            'selects' => ['c.season AS key_0', 'c.month AS key_1'],
            'unit' => '월',
            'title' => '월별 대여 현황',
            'graph_title' => '월별 이용 비교',
            'next' => '일별 보기',
        ],
        2 => [
            'groups' => ['c.season', 'c.month', 'r.rent_date'],
            'selects' => ['c.season AS key_0', 'c.month AS key_1', 'r.rent_date AS key_2'],
            'unit' => '일',
            'title' => '일별 대여 현황',
            'graph_title' => '일별 이용 비교',
            'next' => '',
        ],
    ],
    'space' => [
        0 => [
            'groups' => ['d.district_id'],
            'selects' => ['d.district_id AS key_0', 'MIN(d.district_name) AS label_0'],
            'unit' => '자치구',
            'title' => '자치구별 대여 현황',
            'graph_title' => '자치구별 이용 비교',
            'next' => '대여소 보기',
        ],
        1 => [
            'groups' => ['d.district_id', 's.station_id'],
            'selects' => [
                'd.district_id AS key_0',
                's.station_id AS key_1',
                'MIN(d.district_name) AS label_0',
                'MIN(s.station_name) AS label_1',
            ],
            'unit' => '대여소',
            'title' => '대여소별 대여 현황',
            'graph_title' => '대여소별 이용 비교',
            'next' => '',
        ],
    ],
];

$level = $LEVELS[$mode][$depth];
$where = ['c.year = ?'];
$types = 'i';
$params = [$year];

if ($life_zone !== '') {
    $where[] = 'd.life_zone = ?';
    $types .= 's';
    $params[] = $life_zone;
}
if ($filter_district_id > 0) {
    $where[] = 'd.district_id = ?';
    $types .= 'i';
    $params[] = $filter_district_id;
}
if ($mode === 'time' && $depth >= 1) {
    $where[] = 'c.season = ?';
    $types .= 's';
    $params[] = $season;
}
if ($mode === 'time' && $depth >= 2) {
    $where[] = 'c.month = ?';
    $types .= 'i';
    $params[] = $month;
}
if ($mode === 'space' && $depth >= 1) {
    $where[] = 'd.district_id = ?';
    $types .= 'i';
    $params[] = $path_district_id;
}

$select_sql = implode(', ', $level['selects']);
$group_sql = implode(', ', $level['groups']);
$sql = "SELECT {$select_sql},
               COUNT(DISTINCT r.rent_date) AS day_count,
               COUNT(DISTINCT r.station_id) AS station_count,
               SUM(r.rent_count) AS rent_total
          FROM rental_daily r
          JOIN station s ON s.station_id = r.station_id
          JOIN district d ON d.district_id = s.district_id
          JOIN calendar c ON c.cal_date = r.rent_date
         WHERE " . implode(' AND ', $where) . "
         GROUP BY {$group_sql} WITH ROLLUP";

$rows = [];
$detail_rows = [];
$rollup_rows = [];
$grand_total = 0;
$query_error = '';

try {
    $stmt = roll_bind_execute($conn, $sql, $types, $params);
    $raw_rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $key_count = count($level['groups']);
    foreach ($raw_rows as $raw) {
        $all_null = true;
        for ($i = 0; $i < $key_count; $i++) {
            if ($raw['key_' . $i] !== null) {
                $all_null = false;
                break;
            }
        }

        $is_detail = $raw['key_' . ($key_count - 1)] !== null;
        if ($all_null) {
            $row_type = 'grand';
            $label = '전체';
            $grand_total = (int)$raw['rent_total'];
        } elseif (!$is_detail) {
            $row_type = 'subtotal';
            if ($mode === 'time') {
                if ($depth === 1) {
                    $label = $raw['key_0'] . ' 소계';
                } elseif ($raw['key_1'] !== null) {
                    $label = (int)$raw['key_1'] . '월 소계';
                } else {
                    $label = $raw['key_0'] . ' 소계';
                }
            } else {
                $label = ($raw['label_0'] ?? '선택 자치구') . ' 소계';
            }
        } else {
            $row_type = 'detail';
            if ($mode === 'time') {
                if ($depth === 0) {
                    $label = $raw['key_0'];
                } elseif ($depth === 1) {
                    $label = (int)$raw['key_1'] . '월';
                } else {
                    $label = date('Y-m-d', strtotime($raw['key_2']));
                }
            } else {
                $label = $depth === 0 ? $raw['label_0'] : $raw['label_1'];
            }
        }

        if ($mode === 'time' && $depth === 2 && $row_type === 'subtotal'
            && $raw['key_0'] !== null && $raw['key_1'] === null) {
            continue;
        }

        $row = $raw;
        $row['row_type'] = $row_type;
        $row['label'] = $label;
        $row['rent_total'] = (int)$raw['rent_total'];
        $row['day_count'] = (int)$raw['day_count'];
        $row['station_count'] = (int)$raw['station_count'];

        if ($row_type === 'detail') {
            $detail_rows[] = $row;
        } else {
            $rollup_rows[] = $row;
        }
    }

    if ($mode === 'time' && $depth === 0) {
        $season_order = array_flip($SEASONS);
        usort($detail_rows, function ($a, $b) use ($season_order) {
            return ($season_order[$a['label']] ?? 99) <=> ($season_order[$b['label']] ?? 99);
        });
    } elseif ($mode === 'time' && $depth === 1) {
        usort($detail_rows, function ($a, $b) {
            return (int)$a['key_1'] <=> (int)$b['key_1'];
        });
    } elseif ($mode === 'time' && $depth === 2) {
        usort($detail_rows, function ($a, $b) {
            return strcmp($a['key_2'], $b['key_2']);
        });
    } else {
        usort($detail_rows, function ($a, $b) {
            return strcmp($a['label'], $b['label']);
        });
    }
    $rows = array_merge($detail_rows, $rollup_rows);
} catch (mysqli_sql_exception $e) {
    $query_error = '데이터를 불러오지 못했습니다. 다시 조회해 주세요.';
}

$detail_count = count($detail_rows);
$max_value = 0;
$top_row = null;
foreach ($detail_rows as $detail_row) {
    if ($detail_row['rent_total'] > $max_value) {
        $max_value = $detail_row['rent_total'];
        $top_row = $detail_row;
    }
}

$line_chart_points = [];
$line_chart_width = 1080;
$line_chart_height = 220;
$line_chart_left = 54;
$line_chart_right = 18;
$line_chart_top = 18;
$line_chart_bottom = 36;
$line_plot_width = $line_chart_width - $line_chart_left - $line_chart_right;
$line_plot_height = $line_chart_height - $line_chart_top - $line_chart_bottom;
$line_count = count($detail_rows);
foreach ($detail_rows as $index => $detail_row) {
    $x = $line_chart_left + ($line_count > 1 ? $index / ($line_count - 1) * $line_plot_width : $line_plot_width / 2);
    $ratio = $max_value > 0 ? $detail_row['rent_total'] / $max_value : 0;
    $y = $line_chart_top + (1 - $ratio) * $line_plot_height;
    $line_chart_points[] = [
        'x' => $x,
        'y' => $y,
        'label' => $detail_row['label'],
        'value' => $detail_row['rent_total'],
    ];
}
$line_polyline = implode(' ', array_map(function ($point) {
    return number_format($point['x'], 2, '.', '') . ',' . number_format($point['y'], 2, '.', '');
}, $line_chart_points));

if ($grand_total === 0 && $detail_rows) {
    $grand_total = array_sum(array_column($detail_rows, 'rent_total'));
}

$path_parts = [$mode === 'time' ? '기간별' : '지역별', '전체'];
if ($mode === 'time' && $depth >= 1) {
    $path_parts[] = $season;
}
if ($mode === 'time' && $depth >= 2) {
    $path_parts[] = $month . '월';
}
if ($mode === 'space' && $depth >= 1) {
    $path_parts[] = $district_map[$path_district_id]['district_name'];
}

$base_query = [
    'mode' => $mode,
    'year' => $year,
    'life_zone' => $life_zone,
    'filter_district_id' => $filter_district_id ?: null,
    'depth' => $depth,
    'season' => $season,
    'month' => $month ?: null,
    'path_district_id' => $path_district_id ?: null,
];

$up_changes = ['depth' => max(0, $depth - 1)];
if ($mode === 'time') {
    if ($depth <= 2) {
        $up_changes['month'] = null;
    }
    if ($depth <= 1) {
        $up_changes['season'] = null;
    }
} else {
    $up_changes['path_district_id'] = null;
}

$_SESSION['rollup_state'] = [
    'mode' => $mode,
    'year' => $year,
    'life_zone' => $life_zone,
    'filter_district_id' => $filter_district_id,
    'depth' => $depth,
    'season' => $season,
    'month' => $month,
    'path_district_id' => $path_district_id,
];

$filter_caption = $year . '년';
if ($life_zone !== '') {
    $filter_caption .= ' · ' . $life_zone;
}
if ($filter_district_id > 0) {
    $filter_caption .= ' · ' . $district_map[$filter_district_id]['district_name'];
}
?>

<style>
.rollup-page .rollup-title { display:flex; align-items:center; justify-content:space-between; margin-bottom:20px; }
.rollup-page .rollup-title p { margin:0; color:var(--muted); }
.rollup-page .rollup-kicker { color:var(--green); font-size:11px; font-weight:700; letter-spacing:.03em; }
.rollup-page .rollup-mode { display:flex; align-items:center; gap:12px; margin-bottom:16px; }
.rollup-page .rollup-mode a { padding:10px 16px; border:1px solid var(--line); border-radius:6px; background:#fff; color:var(--green-dark); text-decoration:none; font-size:14px; }
.rollup-page .rollup-mode a.active { border-color:var(--green); background:var(--green); color:#fff; font-weight:700; }
.rollup-page .rollup-mode span { color:var(--muted); font-size:13px; }
.rollup-page .rollup-filters { display:flex; flex-wrap:wrap; align-items:flex-end; gap:16px; }
.rollup-page .rollup-filters label { display:flex; flex-direction:column; gap:7px; min-width:220px; color:var(--muted); font-size:13px; }
.rollup-page .rollup-filters select { width:220px; height:40px; background:#fff; color:var(--ink); }
.rollup-page .rollup-filters button { height:40px; }
.rollup-page .rollup-filter-note { margin:13px 0 0; color:var(--muted); font-size:12px; }
.rollup-page .rollup-location { display:flex; flex-direction:column; gap:14px; margin-bottom:20px; background:#e8f5ec; border-radius:8px; padding:20px; }
.rollup-page .rollup-location-main { display:flex; justify-content:space-between; align-items:center; gap:20px; }
.rollup-page .rollup-location-label { color:var(--muted); font-size:12px; }
.rollup-page .rollup-path { margin-top:5px; color:var(--green-dark); font-size:17px; font-weight:700; }
.rollup-page .rollup-path-actions { display:flex; gap:10px; }
.rollup-page .rollup-path-actions a { padding:9px 15px; border:1px solid var(--line); border-radius:6px; background:#fff; color:var(--green-dark); text-decoration:none; font-size:14px; }
.rollup-page .rollup-path-actions a.disabled { opacity:.4; pointer-events:none; }
.rollup-page .rollup-session { color:var(--muted); font-size:12px; }
.rollup-page .rollup-session strong { color:var(--green-dark); margin-right:10px; }
.rollup-page .rollup-chart-head, .rollup-page .rollup-result-head { display:flex; justify-content:space-between; align-items:flex-start; gap:20px; }
.rollup-page .rollup-chart h3, .rollup-page .rollup-result-head h3 { margin:0; font-size:18px; }
.rollup-page .rollup-chart-head p, .rollup-page .rollup-result-head p { margin:5px 0 0; color:var(--muted); font-size:12px; }
.rollup-page .rollup-chart-meta { color:var(--muted); font-size:12px; }
.rollup-page .rollup-axis { display:grid; grid-template-columns:140px 1fr 140px; gap:20px; margin:18px 0 6px; color:var(--muted); font-size:11px; }
.rollup-page .rollup-axis-scale { display:flex; justify-content:space-between; }
.rollup-page .rollup-axis span:last-child { text-align:right; }
.rollup-page .rollup-bar-row { display:grid; grid-template-columns:140px 1fr 140px; align-items:center; gap:20px; min-height:40px; }
.rollup-page .rollup-bar-label { color:var(--ink); text-decoration:none; font-size:14px; }
.rollup-page .rollup-bar-track { height:16px; background:var(--bg); border-radius:4px; overflow:hidden; }
.rollup-page .rollup-bar { display:block; height:100%; min-width:2px; background:var(--green); border-radius:4px; }
.rollup-page .rollup-bar-row.is-max .rollup-bar { background:var(--green-dark); }
.rollup-page .rollup-bar-value { text-align:right; color:var(--green-dark); font-size:14px; font-weight:700; font-variant-numeric:tabular-nums; }
.rollup-page .rollup-chart-note { margin:10px 0 0; color:var(--muted); font-size:12px; }
.rollup-page .rollup-chart-action { color:var(--green-dark); }
.rollup-page .rollup-line-chart { position:relative; margin-top:16px; overflow-x:auto; }
.rollup-page .rollup-line-chart svg { display:block; width:100%; min-width:760px; height:auto; }
.rollup-page .rollup-line-grid { stroke:var(--line); stroke-width:1; stroke-dasharray:4 5; }
.rollup-page .rollup-line-axis { stroke:#cfd4da; stroke-width:1; }
.rollup-page .rollup-line-path {
  fill:none;
  stroke:var(--green-dark);
  stroke-width:3;
  stroke-linecap:round;
  stroke-linejoin:round;
}
.rollup-page .rollup-line-point { fill:#fff; stroke:var(--green); stroke-width:2; }
.rollup-page .rollup-line-point:hover { fill:var(--green); }
.rollup-page .rollup-line-label { fill:var(--muted); font-size:11px; }
.rollup-page .rollup-line-tooltip {
  position:absolute;
  z-index:2;
  display:flex;
  gap:6px;
  padding:7px 10px;
  border-radius:6px;
  background:var(--ink);
  color:#fff;
  font-size:12px;
  white-space:nowrap;
  pointer-events:none;
  opacity:0;
  transform:translate(-50%, calc(-100% - 10px));
}
.rollup-page .rollup-line-tooltip.visible { opacity:1; }
.rollup-page .rollup-line-tooltip strong { color:#fff; }
.rollup-page .rollup-result-head { align-items:center; margin:2px 0 14px; }
.rollup-page .rollup-result-guide { margin:0 0 16px; color:var(--muted); font-size:13px; }
.rollup-page .rollup-table-wrap { overflow-x:auto; border:1px solid var(--line); border-radius:8px; }
.rollup-page table.rollup-table { min-width:820px; border:0; border-radius:0; }
.rollup-page table.rollup-table th, .rollup-page table.rollup-table td { height:54px; padding:10px 16px; }
.rollup-page table.rollup-table th { height:46px; color:var(--muted); }
.rollup-page table.rollup-table tr.detail-row { cursor:pointer; }
.rollup-page table.rollup-table tr.subtotal-row td { background:#e8f5ec; color:var(--green-dark); font-weight:700; }
.rollup-page table.rollup-table tr.grand-row td { background:var(--green-dark); color:#fff; font-weight:700; }
.rollup-page .rollup-row-link { color:var(--green-dark); text-decoration:none; }
.rollup-page .rollup-row-link::after { content:' →'; }
.rollup-page .rollup-legend { display:flex; flex-wrap:wrap; gap:8px 12px; margin-top:14px; color:var(--muted); font-size:12px; }
.rollup-page .rollup-legend strong { color:var(--green-dark); }
.rollup-page .rollup-error { margin-bottom:20px; }
@media (max-width:800px) {
  .rollup-page .rollup-location-main, .rollup-page .rollup-title, .rollup-page .rollup-result-head { align-items:flex-start; flex-direction:column; }
  .rollup-page .rollup-axis, .rollup-page .rollup-bar-row { grid-template-columns:90px 1fr 90px; gap:10px; }
}
</style>

<main class="container rollup-page">
  <div class="rollup-title">
    <div>
      <h2>롤업·드릴다운</h2>
    </div>
  </div>

  <section class="search-form rollup-panel">
    <div class="rollup-mode">
      <a href="<?= roll_h(roll_query_url($base_query, ['mode' => 'time', 'reset_path' => 1])) ?>"
         class="<?= $mode === 'time' ? 'active' : '' ?>">기간별 집계</a>
      <a href="<?= roll_h(roll_query_url($base_query, ['mode' => 'space', 'reset_path' => 1])) ?>"
         class="<?= $mode === 'space' ? 'active' : '' ?>">지역별 집계</a>
      <span><?= $mode === 'time' ? '계절 → 월 → 일' : '자치구 → 대여소' ?></span>
    </div>

    <form method="get" action="rollup.php" class="rollup-filters" id="rollup-filter-form">
      <input type="hidden" name="mode" value="<?= roll_h($mode) ?>">
      <input type="hidden" name="apply" value="1">
      <label>조회 연도
        <select name="year">
          <?php foreach ($years as $year_option): ?>
            <option value="<?= $year_option ?>" <?= $year_option === $year ? 'selected' : '' ?>><?= $year_option ?>년</option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>생활권
        <select name="life_zone" id="rollup-life-zone">
          <option value="">전체</option>
          <?php foreach ($LIFE_ZONES as $zone): ?>
            <option value="<?= roll_h($zone) ?>" <?= $zone === $life_zone ? 'selected' : '' ?>><?= roll_h($zone) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>자치구
        <select name="filter_district_id" id="rollup-district">
          <option value="">전체</option>
          <?php foreach ($districts as $district): ?>
            <option value="<?= $district['district_id'] ?>"
                    data-zone="<?= roll_h($district['life_zone']) ?>"
                    <?= $district['district_id'] === $filter_district_id ? 'selected' : '' ?>>
              <?= roll_h($district['district_name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <button type="submit">조회하기</button>
      <button type="button" onclick="location.href='rollup.php?reset_all=1'">초기화</button>
    </form>
    <p class="rollup-filter-note">선택한 조건에 해당하는 데이터만 집계합니다.</p>
  </section>

  <?php if ($query_error !== ''): ?>
    <p class="msg error rollup-error"><?= roll_h($query_error) ?></p>
  <?php else: ?>
    <section class="kpis rollup-summary">
      <div class="kpi rollup-metric">
        <span>총 대여 건수</span>
        <strong><?= number_format($grand_total) ?>건</strong>
        <small>현재 조회 조건 전체</small>
      </div>
      <div class="kpi rollup-metric">
        <span>현재 집계 단위</span>
        <strong><?= roll_h($level['unit']) ?></strong>
        <small><?= number_format($detail_count) ?>개 그룹 · 단계별 상세 탐색</small>
      </div>
      <div class="kpi rollup-metric">
        <span>최다 이용 <?= roll_h($level['unit']) ?></span>
        <strong><?= $top_row ? roll_h($top_row['label']) : '-' ?></strong>
        <small>
          <?= $top_row ? number_format($top_row['rent_total']) . '건 · 전체의 '
              . number_format($grand_total > 0 ? $top_row['rent_total'] / $grand_total * 100 : 0, 1) . '%' : '집계 결과 없음' ?>
        </small>
      </div>
    </section>

    <section class="rollup-location">
      <div class="rollup-location-main">
        <div>
          <div class="rollup-location-label">현재 위치</div>
          <div class="rollup-path"><?= roll_h(implode(' / ', $path_parts)) ?></div>
        </div>
        <div class="rollup-path-actions">
          <a href="<?= roll_h(roll_query_url($base_query, $up_changes)) ?>" class="<?= $depth === 0 ? 'disabled' : '' ?>">상위로</a>
          <a href="<?= roll_h(roll_query_url($base_query, ['reset_path' => 1])) ?>">처음으로</a>
        </div>
      </div>
      <div class="rollup-session"><strong>자동 저장됨</strong>이 세션에서 마지막으로 본 위치를 유지합니다.</div>
    </section>

    <section class="panel rollup-chart">
      <div class="rollup-chart-head">
        <div>
          <h3><?= roll_h(($depth > 0 ? end($path_parts) . '의 ' : '') . $level['graph_title']) ?></h3>
          <p><?= roll_h($filter_caption) ?></p>
        </div>
        <span class="rollup-chart-meta">대여 건수 · 실제 DB 데이터</span>
      </div>

      <?php if (!$detail_rows): ?>
        <p class="empty">조건에 맞는 집계 결과가 없습니다.</p>
      <?php elseif ($mode === 'time' && $depth === 2): ?>
        <div class="rollup-line-chart">
          <svg viewBox="0 0 <?= $line_chart_width ?> <?= $line_chart_height ?>"
               role="img" aria-label="<?= roll_h($month) ?>월 일별 대여 건수 꺾은선 그래프">
            <line class="rollup-line-grid" x1="<?= $line_chart_left ?>" y1="<?= $line_chart_top ?>"
                  x2="<?= $line_chart_width - $line_chart_right ?>" y2="<?= $line_chart_top ?>" />
            <line class="rollup-line-grid" x1="<?= $line_chart_left ?>" y1="<?= $line_chart_top + $line_plot_height / 2 ?>"
                  x2="<?= $line_chart_width - $line_chart_right ?>" y2="<?= $line_chart_top + $line_plot_height / 2 ?>" />
            <line class="rollup-line-axis" x1="<?= $line_chart_left ?>" y1="<?= $line_chart_top + $line_plot_height ?>"
                  x2="<?= $line_chart_width - $line_chart_right ?>" y2="<?= $line_chart_top + $line_plot_height ?>" />

            <text class="rollup-line-label" x="0" y="<?= $line_chart_top + 4 ?>"><?= number_format($max_value) ?></text>
            <text class="rollup-line-label" x="8" y="<?= $line_chart_top + $line_plot_height / 2 + 4 ?>"><?= number_format((int)round($max_value / 2)) ?></text>
            <text class="rollup-line-label" x="35" y="<?= $line_chart_top + $line_plot_height + 4 ?>">0</text>

            <polyline class="rollup-line-path" points="<?= roll_h($line_polyline) ?>" />
            <?php foreach ($line_chart_points as $point): ?>
              <circle class="rollup-line-point"
                      cx="<?= number_format($point['x'], 2, '.', '') ?>"
                      cy="<?= number_format($point['y'], 2, '.', '') ?>" r="5"
                      tabindex="0"
                      data-label="<?= roll_h($point['label']) ?>"
                      data-value="<?= number_format($point['value']) ?>건" />
            <?php endforeach; ?>

            <?php if ($line_chart_points):
              $first_point = $line_chart_points[0];
              $middle_point = $line_chart_points[(int)floor(($line_count - 1) / 2)];
              $last_point = $line_chart_points[$line_count - 1];
            ?>
              <text class="rollup-line-label" text-anchor="start"
                    x="<?= number_format($first_point['x'], 2, '.', '') ?>" y="<?= $line_chart_height - 8 ?>"><?= roll_h(substr($first_point['label'], 5)) ?></text>
              <text class="rollup-line-label" text-anchor="middle"
                    x="<?= number_format($middle_point['x'], 2, '.', '') ?>" y="<?= $line_chart_height - 8 ?>"><?= roll_h(substr($middle_point['label'], 5)) ?></text>
              <text class="rollup-line-label" text-anchor="end"
                    x="<?= number_format($last_point['x'], 2, '.', '') ?>" y="<?= $line_chart_height - 8 ?>"><?= roll_h(substr($last_point['label'], 5)) ?></text>
            <?php endif; ?>
          </svg>
          <div class="rollup-line-tooltip" id="rollup-line-tooltip" role="tooltip">
            <strong id="rollup-tooltip-label"></strong>
            <span id="rollup-tooltip-value"></span>
          </div>
        </div>
        <p class="rollup-chart-note">일별 대여 건수의 변화를 날짜 순서대로 표시합니다.</p>
        <p class="rollup-chart-note">소계·전체 합계는 중복을 피하기 위해 그래프에서 제외합니다.</p>
      <?php else: ?>
        <div class="rollup-axis">
          <span>대여 건수</span>
          <span class="rollup-axis-scale"><i>0</i><i><?= number_format((int)round($max_value / 2)) ?></i><i><?= number_format($max_value) ?>건</i></span>
          <span>합계</span>
        </div>
        <?php foreach ($detail_rows as $chart_row):
          $width = $max_value > 0 ? max(0.5, $chart_row['rent_total'] / $max_value * 100) : 0;
          $drill_changes = [];
          if ($mode === 'time' && $depth === 0) {
              $drill_changes = ['depth' => 1, 'season' => $chart_row['key_0']];
          } elseif ($mode === 'time' && $depth === 1) {
              $drill_changes = ['depth' => 2, 'month' => (int)$chart_row['key_1']];
          } elseif ($mode === 'space' && $depth === 0) {
              $drill_changes = ['depth' => 1, 'path_district_id' => (int)$chart_row['key_0']];
          }
          $can_drill = $level['next'] !== '';
          $chart_url = $can_drill ? roll_query_url($base_query, $drill_changes) : '';
        ?>
          <div class="rollup-bar-row <?= $chart_row['rent_total'] === $max_value ? 'is-max' : '' ?>">
            <?php if ($can_drill): ?>
              <a class="rollup-bar-label" href="<?= roll_h($chart_url) ?>"><?= roll_h($chart_row['label']) ?></a>
            <?php else: ?>
              <span class="rollup-bar-label"><?= roll_h($chart_row['label']) ?></span>
            <?php endif; ?>
            <div class="rollup-bar-track">
              <?php if ($can_drill): ?><a class="rollup-bar" href="<?= roll_h($chart_url) ?>" style="width:<?= number_format($width, 2, '.', '') ?>%" aria-label="<?= roll_h($chart_row['label']) ?> 상세 보기"></a>
              <?php else: ?><span class="rollup-bar" style="width:<?= number_format($width, 2, '.', '') ?>%"></span><?php endif; ?>
            </div>
            <span class="rollup-bar-value"><?= number_format($chart_row['rent_total']) ?>건</span>
          </div>
        <?php endforeach; ?>
        <p class="rollup-chart-note">소계·전체 합계는 중복을 피하기 위해 그래프에서 제외합니다.</p>
        <?php if ($level['next'] !== ''): ?><p class="rollup-chart-note rollup-chart-action">막대를 클릭해 다음 단계로 이동할 수 있습니다.</p><?php endif; ?>
      <?php endif; ?>
    </section>

    <div class="rollup-result-head">
      <h3><?= roll_h($level['title']) ?></h3>
      <p><?= number_format($detail_count) ?>개 <?= roll_h($level['unit']) ?> · 단위: 건 · 실제 DB 데이터</p>
    </div>
    <p class="rollup-result-guide">
      <?= $level['next'] !== ''
          ? roll_h($level['unit']) . ' 행을 클릭하면 ' . roll_h($level['next']) . '로 이동합니다.'
          : '마지막 탐색 단계입니다. 상위로 버튼을 눌러 이전 집계로 돌아갈 수 있습니다.' ?>
    </p>

    <div class="rollup-table-wrap">
      <table class="result rollup-table">
        <thead>
          <tr>
            <th>구분</th>
            <th><?= roll_h($level['unit']) ?></th>
            <th class="num">대여 건수</th>
            <th class="num">구성비</th>
            <th>상세 탐색</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="5" class="empty">조건에 맞는 집계 결과가 없습니다.</td></tr>
          <?php else: foreach ($rows as $row):
            $row_class = $row['row_type'] === 'grand' ? 'grand-row'
                : ($row['row_type'] === 'subtotal' ? 'subtotal-row' : 'detail-row');
            $can_drill = $row['row_type'] === 'detail' && $level['next'] !== '';
            $drill_changes = [];
            if ($can_drill && $mode === 'time' && $depth === 0) {
                $drill_changes = ['depth' => 1, 'season' => $row['key_0']];
            } elseif ($can_drill && $mode === 'time' && $depth === 1) {
                $drill_changes = ['depth' => 2, 'month' => (int)$row['key_1']];
            } elseif ($can_drill && $mode === 'space' && $depth === 0) {
                $drill_changes = ['depth' => 1, 'path_district_id' => (int)$row['key_0']];
            }
            $row_url = $can_drill ? roll_query_url($base_query, $drill_changes) : '';
            $type_label = $row['row_type'] === 'grand' ? '전체 합계'
                : ($row['row_type'] === 'subtotal' ? '소계' : '상세');
          ?>
            <tr class="<?= $row_class ?>" <?= $can_drill ? 'data-href="' . roll_h($row_url) . '" tabindex="0"' : '' ?>>
              <td><?= roll_h($type_label) ?></td>
              <td><?= roll_h($row['label']) ?></td>
              <td class="num"><?= number_format($row['rent_total']) ?></td>
              <td class="num"><?= number_format($grand_total > 0 ? $row['rent_total'] / $grand_total * 100 : 0, 1) ?>%</td>
              <td>
                <?php if ($can_drill): ?>
                  <a class="rollup-row-link" href="<?= roll_h($row_url) ?>"><?= roll_h($level['next']) ?></a>
                <?php else: ?>—<?php endif; ?>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <div class="rollup-legend">
      <strong>소계</strong><span>선택 그룹의 합산</span>
      <strong>전체 합계</strong><span>현재 조회 조건 전체의 합산 · 합계 행은 클릭되지 않습니다.</span>
    </div>
  <?php endif; ?>
</main>

<script>
(function () {
  var zone = document.getElementById('rollup-life-zone');
  var district = document.getElementById('rollup-district');
  function filterDistricts() {
    var selectedZone = zone.value;
    for (var i = 1; i < district.options.length; i++) {
      var option = district.options[i];
      var visible = selectedZone === '' || option.getAttribute('data-zone') === selectedZone;
      option.hidden = !visible;
      if (!visible && option.selected) district.value = '';
    }
  }
  zone.addEventListener('change', filterDistricts);
  filterDistricts();

  document.querySelectorAll('.rollup-table tr[data-href]').forEach(function (row) {
    row.addEventListener('click', function (event) {
      if (event.target.closest('a')) return;
      location.href = row.getAttribute('data-href');
    });
    row.addEventListener('keydown', function (event) {
      if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        location.href = row.getAttribute('data-href');
      }
    });
  });

  var lineChart = document.querySelector('.rollup-line-chart');
  var lineTooltip = document.getElementById('rollup-line-tooltip');
  if (lineChart && lineTooltip) {
    var tooltipLabel = document.getElementById('rollup-tooltip-label');
    var tooltipValue = document.getElementById('rollup-tooltip-value');

    function showLineTooltip(point, clientX, clientY) {
      var chartRect = lineChart.getBoundingClientRect();
      var pointRect = point.getBoundingClientRect();
      var x = clientX === null ? pointRect.left + pointRect.width / 2 : clientX;
      var y = clientY === null ? pointRect.top : clientY;
      tooltipLabel.textContent = point.dataset.label;
      tooltipValue.textContent = point.dataset.value;
      lineTooltip.style.left = (x - chartRect.left + lineChart.scrollLeft) + 'px';
      lineTooltip.style.top = (y - chartRect.top + lineChart.scrollTop) + 'px';
      lineTooltip.classList.add('visible');
    }

    function hideLineTooltip() {
      lineTooltip.classList.remove('visible');
    }

    lineChart.querySelectorAll('.rollup-line-point').forEach(function (point) {
      point.addEventListener('pointerenter', function (event) {
        showLineTooltip(point, event.clientX, event.clientY);
      });
      point.addEventListener('pointermove', function (event) {
        showLineTooltip(point, event.clientX, event.clientY);
      });
      point.addEventListener('pointerleave', hideLineTooltip);
      point.addEventListener('focus', function () {
        showLineTooltip(point, null, null);
      });
      point.addEventListener('blur', hideLineTooltip);
    });
  }
})();
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
