<?php
/**
 * aggregate.php : 복수 기준 그룹 집계 (SELECT + GROUP BY)
 * 작성자 : 유지민
 *
 * 입력 컨트롤
 *   - checkbox : 묶는 기준 (자치구, 날씨, 계절, 요일 등)
 *   - select   : 분석 지표, 집계 함수
 *   - date     : 분석 시작일, 종료일
 *   - button   : 집계, 초기화
 *
 * 묶는 기준은 2개 이상 선택하며 선택한 순서대로 GROUP BY 한다.
 * 사용자 입력 날짜와 페이지 범위는 Prepared Statement로 바인딩한다.
 * 컬럼명·SQL 식·집계 함수처럼 바인딩할 수 없는 값은
 * 서버의 화이트리스트에 등록된 값만 허용한다.
 */

$page_title = '집계';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/header.php';

function agg_h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function agg_valid_date($value, $min_date, $max_date) {
    $date = DateTime::createFromFormat('Y-m-d', (string)$value);
    return $date
        && $date->format('Y-m-d') === $value
        && $value >= $min_date
        && $value <= $max_date;
}

$data_summary = $conn->query(
    "SELECT MIN(rent_date) AS min_date,
            MAX(rent_date) AS max_date,
            COUNT(*) AS row_count,
            COUNT(DISTINCT station_id) AS station_count,
            COUNT(DISTINCT rent_date) AS day_count
       FROM rental_daily"
)->fetch_assoc();

$data_min_date = $data_summary['min_date'] ?: date('Y-m-d');
$data_max_date = $data_summary['max_date'] ?: $data_min_date;

$GROUPS = [
    'district'      => ['label' => '자치구',        'expr' => 'd.district_name'],
    'weather'       => ['label' => '날씨',          'expr' => 'w.weather_type'],
    'season'        => ['label' => '계절',          'expr' => 'c.season'],
    'weekday'       => ['label' => '요일',          'expr' => 'c.day_name'],
    'life_zone'     => ['label' => '생활권',        'expr' => 'd.life_zone'],
    'district_type' => ['label' => '지역 유형',     'expr' => 'd.district_type'],
    'day_type'      => ['label' => '평일 / 휴일',   'expr' => 'c.day_type'],
    'hangang'       => ['label' => '한강공원 여부', 'expr' => "CASE WHEN s.hangang_park IS NULL THEN '일반' ELSE '한강공원' END"],
];

$METRICS = [
    'rent_count'       => ['label' => '이용건수', 'column' => 'r.rent_count',       'unit' => '건'],
    'total_minutes'    => ['label' => '이용시간', 'column' => 'r.total_minutes',    'unit' => '분'],
    'total_distance_m' => ['label' => '이동거리', 'column' => 'r.total_distance_m', 'unit' => 'm'],
];

$FUNCTIONS = [
    'sum' => ['label' => '합계', 'sql' => 'SUM'],
    'avg' => ['label' => '평균', 'sql' => 'AVG'],
    'max' => ['label' => '최대', 'sql' => 'MAX'],
    'min' => ['label' => '최소', 'sql' => 'MIN'],
];

$searched = isset($_GET['search']);
$raw_groups = $searched ? (array)($_GET['group'] ?? []) : [];
$raw_groups = array_values(array_unique(array_filter(
    $raw_groups,
    function ($key) use ($GROUPS) { return isset($GROUPS[$key]); }
)));

// JavaScript가 기록한 체크 순서를 우선 사용하되, JS가 꺼진 경우에도 정상 동작한다.
$order_text = $searched ? (string)($_GET['group_order'] ?? '') : '';
$order_keys = array_filter(explode(',', $order_text));
$selected_groups = [];
foreach ($order_keys as $key) {
    if (isset($GROUPS[$key]) && in_array($key, $raw_groups, true) && !in_array($key, $selected_groups, true)) {
        $selected_groups[] = $key;
    }
}
foreach ($raw_groups as $key) {
    if (!in_array($key, $selected_groups, true)) {
        $selected_groups[] = $key;
    }
}

$metric_key = isset($METRICS[$_GET['metric'] ?? '']) ? $_GET['metric'] : 'rent_count';
$function_key = isset($FUNCTIONS[$_GET['function'] ?? '']) ? $_GET['function'] : 'sum';
$date_from = agg_valid_date($_GET['date_from'] ?? '', $data_min_date, $data_max_date)
    ? $_GET['date_from'] : $data_min_date;
$date_to = agg_valid_date($_GET['date_to'] ?? '', $data_min_date, $data_max_date)
    ? $_GET['date_to'] : $data_max_date;
$page = ctype_digit((string)($_GET['page'] ?? '')) ? max(1, (int)$_GET['page']) : 1;
$page_size = 20;

$errors = [];
$notice = '';
if ($searched && count($selected_groups) < 2) {
    $errors[] = '묶는 기준을 2개 이상 선택하세요.';
}
if ($date_from > $date_to) {
    [$date_from, $date_to] = [$date_to, $date_from];
    $notice = '시작일이 종료일보다 늦어 두 날짜를 바꿔 집계했습니다.';
}

$rows = [];
$total_groups = 0;
$total_pages = 1;
$metric_total = 0;

if ($searched && !$errors) {
    $select_parts = [];
    $group_parts = [];
    $order_parts = [];
    foreach ($selected_groups as $index => $key) {
        $alias = 'group_' . $index;
        $select_parts[] = $GROUPS[$key]['expr'] . ' AS ' . $alias;
        $group_parts[] = $GROUPS[$key]['expr'];
        $order_parts[] = $alias . ' ASC';
    }

    $joins = " FROM rental_daily r
               JOIN station s  ON s.station_id = r.station_id
               JOIN district d ON d.district_id = s.district_id
               JOIN calendar c ON c.cal_date = r.rent_date
               JOIN weather w  ON w.weather_date = r.rent_date
              WHERE r.rent_date BETWEEN ? AND ?";
    $group_sql = implode(', ', $group_parts);

    $count_sql = "SELECT COUNT(*) AS total_groups
                    FROM (SELECT 1 {$joins} GROUP BY {$group_sql}) grouped";
    $stmt = $conn->prepare($count_sql);
    $stmt->bind_param('ss', $date_from, $date_to);
    $stmt->execute();
    $total_groups = (int)$stmt->get_result()->fetch_assoc()['total_groups'];
    $stmt->close();

    $total_pages = max(1, (int)ceil($total_groups / $page_size));
    $page = min($page, $total_pages);
    $offset = ($page - 1) * $page_size;

    $aggregate_sql = $FUNCTIONS[$function_key]['sql'] . '(' . $METRICS[$metric_key]['column'] . ')';
    $data_sql = 'SELECT ' . implode(', ', $select_parts)
              . ", COUNT(DISTINCT r.rent_date) AS period_days,
                   {$aggregate_sql} AS aggregate_value"
              . $joins
              . " GROUP BY {$group_sql}
                  ORDER BY " . implode(', ', $order_parts)
              . ' LIMIT ? OFFSET ?';
    $stmt = $conn->prepare($data_sql);
    $stmt->bind_param('ssii', $date_from, $date_to, $page_size, $offset);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if ($function_key === 'sum') {
        $total_sql = 'SELECT SUM(' . $METRICS[$metric_key]['column'] . ") AS metric_total
                        FROM rental_daily r
                       WHERE r.rent_date BETWEEN ? AND ?";
        $stmt = $conn->prepare($total_sql);
        $stmt->bind_param('ss', $date_from, $date_to);
        $stmt->execute();
        $metric_total = (float)($stmt->get_result()->fetch_assoc()['metric_total'] ?? 0);
        $stmt->close();
    }
}

$selected_labels = array_map(function ($key) use ($GROUPS) {
    return $GROUPS[$key]['label'];
}, $selected_groups);
$function_label = $FUNCTIONS[$function_key]['label'];
$metric_label = $METRICS[$metric_key]['label'];
$metric_unit = $METRICS[$metric_key]['unit'];

function agg_page_url($target_page) {
    $query = $_GET;
    $query['search'] = '1';
    $query['page'] = $target_page;
    return 'aggregate.php?' . http_build_query($query);
}
?>
<style>
.aggregate-page .aggregate-db-info {
  margin: -8px 0 16px;
  color: var(--muted);
  font-size: 13px;
}
.aggregate-page .aggregate-db-info strong { color: var(--green-dark); }

.aggregate-page .aggregate-form { padding: 22px 24px; }
.aggregate-page .aggregate-form .row { gap: 16px 28px; }
.aggregate-page .aggregate-form .row + .row { margin-top: 20px; }
.aggregate-page .aggregate-form label,
.aggregate-page .aggregate-form .group { line-height: 1.4; }
.aggregate-page .aggregate-form select,
.aggregate-page .aggregate-form input[type=date] { margin-left: 5px; }
.aggregate-page .aggregate-group-row { align-items: flex-start; }
.aggregate-page .aggregate-group-row .group {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 6px 18px;
}
.aggregate-page .aggregate-group-row .group label {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  margin-left: 0;
  white-space: nowrap;
}
.aggregate-page .aggregate-group-row input[type=checkbox] { margin: 0; }

.aggregate-page .aggregate-selection {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-top: 10px;
  padding: 7px 10px;
  border: 1px solid #b9ddc6;
  border-radius: 8px;
  background: #f2faf5;
  color: var(--green-dark);
  font-size: 13px;
  line-height: 1.35;
}
.aggregate-page .aggregate-selection-count {
  flex: 0 0 auto;
  padding: 2px 8px;
  border-radius: 999px;
  background: var(--green);
  color: #fff;
  font-weight: 600;
}
.aggregate-page .aggregate-selection-count strong { font-size: 14px; }
.aggregate-page .aggregate-selection-path {
  font-weight: 600;
  word-break: keep-all;
}
.aggregate-page .aggregate-selection.invalid {
  border-color: var(--line);
  background: #f8f9fa;
  color: var(--muted);
}
.aggregate-page .aggregate-selection.invalid .aggregate-selection-count {
  background: #9ca3af;
}
.aggregate-page .aggregate-selection + .aggregate-settings-row {
  margin-top: 24px;
}
.aggregate-page .aggregate-settings-row {
  align-items: center;
  column-gap: 30px;
  row-gap: 16px;
}
.aggregate-page .aggregate-settings-row label {
  display: inline-flex;
  align-items: center;
  gap: 8px;
}
.aggregate-page .aggregate-settings-row select,
.aggregate-page .aggregate-settings-row input[type=date] {
  margin-left: 0;
}

.aggregate-page .aggregate-table-wrap {
  width: 100%;
  overflow-x: auto;
}
.aggregate-page .aggregate-result { min-width: 760px; }
.aggregate-page .aggregate-result th,
.aggregate-page .aggregate-result td { white-space: nowrap; }
.aggregate-page .aggregate-table-footer {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  margin-top: 12px;
  color: var(--muted);
  font-size: 13px;
}
.aggregate-page .aggregate-pagination {
  display: flex;
  gap: 4px;
}
.aggregate-page .aggregate-pagination a {
  min-width: 32px;
  padding: 6px 9px;
  border: 1px solid var(--line);
  border-radius: 6px;
  background: #fff;
  text-align: center;
  text-decoration: none;
}
.aggregate-page .aggregate-pagination a:hover,
.aggregate-page .aggregate-pagination a.active {
  border-color: var(--green);
  background: var(--green);
  color: #fff;
}

@media (max-width: 640px) {
  .aggregate-page .aggregate-form { padding: 18px; }
  .aggregate-page .aggregate-selection { align-items: flex-start; }
  .aggregate-page .aggregate-table-footer {
    align-items: flex-start;
    flex-direction: column;
  }
}
</style>
<main class="container aggregate-page">
  <h2>다중 기준 집계</h2>
  <p class="lead">자치구·날씨·계절·요일 등 원하는 기준을 2개 이상 조합해 따릉이 이용량을 비교하세요.</p>
  <p class="aggregate-db-info">
    <strong><?= agg_h(DB_NAME) ?></strong> DB · 원본 <?= number_format((int)$data_summary['row_count']) ?>행 ·
    대여소 <?= number_format((int)$data_summary['station_count']) ?>개 ·
    <?= number_format((int)$data_summary['day_count']) ?>일
    (<?= agg_h($data_min_date) ?> ~ <?= agg_h($data_max_date) ?>)
  </p>

  <?php foreach ($errors as $error): ?>
    <p class="msg error"><?= agg_h($error) ?></p>
  <?php endforeach; ?>
  <?php if ($notice !== ''): ?><p class="msg notice"><?= agg_h($notice) ?></p><?php endif; ?>

  <form method="get" action="aggregate.php" class="search-form aggregate-form" id="aggregate-form">
    <input type="hidden" name="group_order" id="group-order" value="<?= agg_h(implode(',', $selected_groups)) ?>">

    <div class="row aggregate-group-row">
      <span class="group"><strong>묶는 기준</strong>
        <?php foreach ($GROUPS as $key => $group): ?>
          <label>
            <input type="checkbox" name="group[]" value="<?= agg_h($key) ?>"
                   data-label="<?= agg_h($group['label']) ?>"
                   <?= in_array($key, $selected_groups, true) ? 'checked' : '' ?>>
            <?= agg_h($group['label']) ?>
          </label>
        <?php endforeach; ?>
      </span>
    </div>
    <div class="aggregate-selection <?= count($selected_groups) < 2 ? 'invalid' : '' ?>" id="selection-notice" aria-live="polite">
      <span class="aggregate-selection-count">
        <strong id="selection-count"><?= count($selected_groups) ?></strong>개 선택
      </span>
      <span class="aggregate-selection-path" id="selection-path">
        <?= count($selected_groups) < 2
            ? '묶는 기준을 2개 이상 선택하세요.'
            : agg_h(implode(' → ', $selected_labels)) ?>
      </span>
    </div>

    <div class="row aggregate-settings-row">
        <label>분석 지표
          <select name="metric">
            <?php foreach ($METRICS as $key => $metric): ?>
              <option value="<?= agg_h($key) ?>" <?= $key === $metric_key ? 'selected' : '' ?>><?= agg_h($metric['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>집계 함수
          <select name="function">
            <?php foreach ($FUNCTIONS as $key => $function): ?>
              <option value="<?= agg_h($key) ?>" <?= $key === $function_key ? 'selected' : '' ?>>
                <?= agg_h($function['label']) ?> (<?= agg_h($function['sql']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>시작일
          <input type="date" name="date_from" value="<?= agg_h($date_from) ?>" min="<?= agg_h($data_min_date) ?>" max="<?= agg_h($data_max_date) ?>" required>
        </label>
        <span>~</span>
        <label>종료일
          <input type="date" name="date_to" value="<?= agg_h($date_to) ?>" min="<?= agg_h($data_min_date) ?>" max="<?= agg_h($data_max_date) ?>" required>
        </label>
        <button type="submit" name="search" value="1">집계</button>
        <button type="button" onclick="location.href='aggregate.php'">초기화</button>
    </div>
  </form>

  <?php if ($searched && !$errors): ?>
  <p class="count">
    검색 결과 <strong><?= number_format($total_groups) ?></strong>개 그룹 ·
    <?= agg_h(implode(' → ', $selected_labels)) ?> ·
    <?= agg_h($metric_label) ?> <?= agg_h($function_label) ?> ·
    <?= agg_h($date_from) ?> ~ <?= agg_h($date_to) ?>
  </p>

    <div class="aggregate-table-wrap">
      <table class="result aggregate-result">
        <thead>
          <tr>
            <?php foreach ($selected_labels as $label): ?><th><?= agg_h($label) ?></th><?php endforeach; ?>
            <th class="num">해당 일수</th>
            <th class="num"><?= agg_h($metric_label) ?> <?= agg_h($function_label) ?></th>
            <th class="num">전체 구성비</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="<?= count($selected_groups) + 3 ?>" class="empty">조건에 맞는 집계 결과가 없습니다.</td></tr>
          <?php else: foreach ($rows as $row): ?>
            <tr>
              <?php foreach ($selected_groups as $index => $_group_key): ?>
                <td><?= agg_h($row['group_' . $index]) ?></td>
              <?php endforeach; ?>
              <td class="num"><?= number_format((int)$row['period_days']) ?>일</td>
              <td class="num">
                <?= number_format((float)$row['aggregate_value'], $function_key === 'avg' ? 1 : 0) ?><?= agg_h($metric_unit) ?>
              </td>
              <td class="num">
                <?= $function_key === 'sum' && $metric_total > 0
                    ? number_format((float)$row['aggregate_value'] / $metric_total * 100, 1) . '%'
                    : '-' ?>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <div class="aggregate-table-footer">
      <span>
        <?= $total_groups === 0 ? 0 : number_format(($page - 1) * $page_size + 1) ?>–<?= number_format(min($page * $page_size, $total_groups)) ?>
        / <?= number_format($total_groups) ?>개 그룹
      </span>
      <?php if ($total_pages > 1): ?>
        <nav class="aggregate-pagination" aria-label="집계 결과 페이지">
          <?php if ($page > 1): ?><a href="<?= agg_h(agg_page_url($page - 1)) ?>" aria-label="이전 페이지">‹</a><?php endif; ?>
          <?php
          $page_start = max(1, $page - 2);
          $page_end = min($total_pages, $page + 3);
          for ($p = $page_start; $p <= $page_end; $p++):
          ?>
            <a href="<?= agg_h(agg_page_url($p)) ?>" class="<?= $p === $page ? 'active' : '' ?>" <?= $p === $page ? 'aria-current="page"' : '' ?>><?= $p ?></a>
          <?php endfor; ?>
          <?php if ($page < $total_pages): ?><a href="<?= agg_h(agg_page_url($page + 1)) ?>" aria-label="다음 페이지">›</a><?php endif; ?>
        </nav>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</main>

<script>
(function () {
  var form = document.getElementById('aggregate-form');
  var boxes = Array.prototype.slice.call(form.querySelectorAll('input[name="group[]"]'));
  var orderInput = document.getElementById('group-order');
  var notice = document.getElementById('selection-notice');
  var selectionCount = document.getElementById('selection-count');
  var selectionPath = document.getElementById('selection-path');
  var functionSelect = form.querySelector('select[name="function"]');
  var order = orderInput.value ? orderInput.value.split(',') : [];

  function refreshSelection() {
    order = order.filter(function (key) {
      var box = boxes.find(function (item) { return item.value === key; });
      return box && box.checked;
    });
    boxes.forEach(function (box) {
      if (box.checked && order.indexOf(box.value) === -1) order.push(box.value);
    });
    orderInput.value = order.join(',');
    var labels = order.map(function (key) {
      return boxes.find(function (box) { return box.value === key; }).dataset.label;
    });
    notice.classList.toggle('invalid', order.length < 2);
    selectionCount.textContent = order.length;
    selectionPath.textContent = order.length < 2
      ? '묶는 기준을 2개 이상 선택하세요.'
      : labels.join(' → ');
  }

  boxes.forEach(function (box) { box.addEventListener('change', refreshSelection); });
  form.addEventListener('submit', function (event) {
    refreshSelection();
    if (order.length < 2) {
      event.preventDefault();
      notice.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
  });

  form.querySelectorAll('[data-function]').forEach(function (chip) {
    chip.addEventListener('click', function () {
      functionSelect.value = chip.dataset.function;
      form.querySelectorAll('[data-function]').forEach(function (item) {
        item.classList.toggle('active', item === chip);
      });
    });
  });
  functionSelect.addEventListener('change', function () {
    form.querySelectorAll('[data-function]').forEach(function (chip) {
      chip.classList.toggle('active', chip.dataset.function === functionSelect.value);
    });
  });
  refreshSelection();
})();
</script>
<?php require_once __DIR__ . '/footer.php'; ?>
