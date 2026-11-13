<?php
/**
 * weather_manage.php : 날씨 기록 조회(SELECT) · 수정(UPDATE)
 * 작성자 : 정서진 (2. 데이터)
 *
 * 입력 컨트롤
 *   - date     : 시작일, 종료일
 *   - checkbox : 날씨 (맑음 / 흐림 / 비 / 눈)
 *   - select   : 기온 구간
 *   - number   : 최소 강수량(mm), 수정 폼의 기온·강수·습도 등
 *   - radio    : 정렬 기준
 *   - button   : 검색, 초기화, 수정 저장, 취소
 *
 * - 사용자 입력은 전부 Prepared Statement 로 바인딩한다.
 *   정렬 기준처럼 바인딩할 수 없는 값은 화이트리스트로만 허용한다.
 * - 수정은 관리자(role = 'admin')만 가능하다. (세션 role 확인)
 * - weather_type(날씨 구분), temp_band(기온 구간)는 생성 컬럼이라
 *   기온·강수·눈 값을 수정하면 DB가 자동으로 다시 계산한다.
 * - 세션 사용 : 마지막 검색 조건(수정 후 같은 목록으로 돌아오기),
 *               처리 결과 메시지(flash), 수정 폼 CSRF 토큰
 */

require_once __DIR__ . '/config.php';   // $conn (mysqli), 세션 시작 — 6번 공통 파일
require_once __DIR__ . '/header.php';   // 로그인 확인, 메뉴 — 6번 공통 파일

// 작성자: 정서진 — 화면 출력용 이스케이프
function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

// 작성자: 정서진 — 'YYYY-MM-DD' 형식이고 2024년 날짜인지 검사
function valid_date($s) {
    $d = DateTime::createFromFormat('Y-m-d', $s);
    return $d && $d->format('Y-m-d') === $s && $s >= '2024-01-01' && $s <= '2024-12-31';
}

// 작성자: 정서진 — 숫자 입력 읽기 (빈칸이면 null, 숫자가 아니면 false)
function read_num($key) {
    $v = trim($_POST[$key] ?? '');
    if ($v === '') {
        return null;
    }
    return is_numeric($v) ? round((float)$v, 1) : false;
}

$is_admin = ($_SESSION['role'] ?? '') === 'admin';

// ---------------------------------------------------------------------
// 작성자: 정서진 — 허용값 (화이트리스트)
// ---------------------------------------------------------------------
$WEATHER_TYPES = ['맑음', '흐림', '비', '눈'];
$TEMP_BANDS    = ['영하', '0~10', '10~20', '20~28', '28+'];
$SORT_SQL      = [
    'date_asc'  => 'w.weather_date ASC',
    'date_desc' => 'w.weather_date DESC',
    'temp_desc' => 'w.avg_temp DESC, w.weather_date ASC',
    'temp_asc'  => 'w.avg_temp ASC, w.weather_date ASC',
    'rain_desc' => 'w.precipitation DESC, w.weather_date ASC',
];
$SORT_LABEL    = [
    'date_asc' => '날짜순', 'date_desc' => '최근순', 'temp_desc' => '더운 날순',
    'temp_asc' => '추운 날순', 'rain_desc' => '비 많이 온 순',
];

// 수정 폼 항목 : 컬럼 => [화면 이름, 최소, 최대, 빈칸 허용]
// (dbcreate.sql 의 NOT NULL·CHECK 조건과 같게 맞춤)
$EDIT_FIELDS = [
    'avg_temp'      => ['평균기온(℃)',   -40, 50,  false],
    'min_temp'      => ['최저기온(℃)',   -40, 50,  true],
    'max_temp'      => ['최고기온(℃)',   -40, 50,  true],
    'precipitation' => ['강수량(mm)',      0, 999, false],
    'rain_hours'    => ['강수 시간(hr)',   0, 24,  false],
    'avg_humidity'  => ['평균 습도(%)',    0, 100, true],
    'avg_wind'      => ['평균 풍속(m/s)',  0, 99,  true],
    'avg_cloud'     => ['평균 전운량(0~10)', 0, 10, true],
    'new_snow_cm'   => ['새로 내린 눈(cm)', 0, 999, false],
    'snow_depth_cm' => ['쌓인 눈(cm)',     0, 999, false],
];

if (empty($_SESSION['weather_csrf'])) {
    $_SESSION['weather_csrf'] = bin2hex(random_bytes(16));
}

// ---------------------------------------------------------------------
// 작성자: 정서진 — 1) 수정 처리 (POST → UPDATE → 목록으로 리다이렉트)
// ---------------------------------------------------------------------
$edit_errors = [];
$edit_input  = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update') {
    $edit_date = $_POST['weather_date'] ?? '';

    if (!$is_admin) {
        $edit_errors[] = '날씨 기록 수정은 관리자만 할 수 있습니다.';
    } elseif (!hash_equals($_SESSION['weather_csrf'], $_POST['csrf'] ?? '')) {
        $edit_errors[] = '요청이 만료되었습니다. 다시 시도해 주세요.';
    } elseif (!valid_date($edit_date)) {
        $edit_errors[] = '날짜가 올바르지 않습니다.';
    }

    // 입력값 검사 (권한·토큰·날짜가 맞을 때만)
    $values = [];
    foreach (($edit_errors ? [] : $EDIT_FIELDS) as $col => [$label, $min, $max, $nullable]) {
        $v = read_num($col);
        if ($v === false) {
            $edit_errors[] = "{$label}: 숫자를 입력하세요.";
        } elseif ($v === null && !$nullable) {
            $edit_errors[] = "{$label}: 값을 입력하세요.";
        } elseif ($v !== null && ($v < $min || $v > $max)) {
            $edit_errors[] = "{$label}: {$min} ~ {$max} 사이로 입력하세요.";
        }
        $values[$col] = $v;
    }
    if (!$edit_errors) {   // 기온 순서 검사
        $mn = $values['min_temp'];
        $mx = $values['max_temp'];
        $av = $values['avg_temp'];
        if (($mn !== null && $mn > $av) || ($mx !== null && $mx < $av) || ($mn !== null && $mx !== null && $mn > $mx)) {
            $edit_errors[] = '기온은 최저 ≤ 평균 ≤ 최고 순서가 되어야 합니다.';
        }
    }

    if (!$edit_errors) {
        $stmt = $conn->prepare(
            'UPDATE weather
                SET avg_temp = ?, min_temp = ?, max_temp = ?, precipitation = ?, rain_hours = ?,
                    avg_humidity = ?, avg_wind = ?, avg_cloud = ?, new_snow_cm = ?, snow_depth_cm = ?
              WHERE weather_date = ?'
        );
        $stmt->bind_param(
            'dddddddddds',
            $values['avg_temp'], $values['min_temp'], $values['max_temp'], $values['precipitation'],
            $values['rain_hours'], $values['avg_humidity'], $values['avg_wind'], $values['avg_cloud'],
            $values['new_snow_cm'], $values['snow_depth_cm'], $edit_date
        );
        try {
            $stmt->execute();
            $changed = $stmt->affected_rows;
            $stmt->close();

            // 생성 컬럼이 다시 계산된 결과를 메시지에 보여 준다
            $chk = $conn->prepare('SELECT weather_type, temp_band FROM weather WHERE weather_date = ?');
            $chk->bind_param('s', $edit_date);
            $chk->execute();
            $now = $chk->get_result()->fetch_assoc();
            $chk->close();

            $_SESSION['weather_flash'] = $changed > 0
                ? "{$edit_date} 날씨 기록을 수정했습니다. (날씨: {$now['weather_type']}, 기온 구간: {$now['temp_band']})"
                : "{$edit_date}: 바뀐 값이 없습니다.";
            header('Location: weather_manage.php?' . ($_SESSION['weather_query'] ?? ''));
            exit;
        } catch (mysqli_sql_exception $e) {
            $edit_errors[] = 'DB 저장 중 오류가 발생했습니다: ' . $e->getMessage();
        }
    }
    $edit_input = $values;
    $_GET['edit'] = $edit_date;   // 오류가 나면 수정 폼을 다시 보여 준다
}

// ---------------------------------------------------------------------
// 작성자: 정서진 — 2) 검색 조건 읽기 + 허용값 검사
// ---------------------------------------------------------------------
$searched   = isset($_GET['search']);
$date_from  = valid_date($_GET['date_from'] ?? '') ? $_GET['date_from'] : '2024-01-01';
$date_to    = valid_date($_GET['date_to'] ?? '')   ? $_GET['date_to']   : '2024-01-31';
$temp_band  = in_array($_GET['temp_band'] ?? '', $TEMP_BANDS, true) ? $_GET['temp_band'] : '';
$min_rain   = is_numeric($_GET['min_rain'] ?? '') && $_GET['min_rain'] >= 0 ? round((float)$_GET['min_rain'], 1) : '';
$sort       = array_key_exists($_GET['sort'] ?? '', $SORT_SQL) ? $_GET['sort'] : 'date_asc';
$types_sel  = $searched
    ? array_values(array_intersect($WEATHER_TYPES, (array)($_GET['wtype'] ?? [])))
    : $WEATHER_TYPES;

$date_error = '';
if ($date_from > $date_to) {
    $date_error = '시작일이 종료일보다 늦어서 두 날짜를 바꿔서 검색했습니다.';
    [$date_from, $date_to] = [$date_to, $date_from];
}

// 수정 후 같은 목록으로 돌아오도록 현재 검색 조건을 세션에 저장
$query_keep = $_GET;
unset($query_keep['edit']);
$_SESSION['weather_query'] = http_build_query($query_keep);

// ---------------------------------------------------------------------
// 작성자: 정서진 — 3) 동적 WHERE 절 + 바인딩 파라미터
// ---------------------------------------------------------------------
$where  = ['w.weather_date BETWEEN ? AND ?'];
$types  = 'ss';
$params = [$date_from, $date_to];

if (count($types_sel) === 0) {
    $where[] = '1 = 0';                       // 날씨를 하나도 안 고르면 결과 없음
} elseif (count($types_sel) < count($WEATHER_TYPES)) {
    $where[] = 'w.weather_type IN (' . implode(',', array_fill(0, count($types_sel), '?')) . ')';
    $types  .= str_repeat('s', count($types_sel));
    foreach ($types_sel as $t) {
        $params[] = $t;
    }
}
if ($temp_band !== '') {
    $where[] = 'w.temp_band = ?';
    $types  .= 's';
    $params[] = $temp_band;
}
if ($min_rain !== '') {
    $where[] = 'w.precipitation >= ?';
    $types  .= 'd';
    $params[] = $min_rain;
}
$where_sql = implode("\n   AND ", $where);

// 목록 (calendar 와 조인해서 요일·휴일도 같이 표시)
$sql = "SELECT w.*, c.day_name, c.day_type, c.holiday_name
          FROM weather w
          JOIN calendar c ON c.cal_date = w.weather_date
         WHERE {$where_sql}
         ORDER BY {$SORT_SQL[$sort]}";
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// 검색 결과 요약 (같은 조건)
$sql = "SELECT COUNT(*) AS days, ROUND(AVG(w.avg_temp), 1) AS avg_temp,
               ROUND(SUM(w.precipitation), 1) AS rain_sum, SUM(w.weather_type IN ('비','눈')) AS wet_days
          FROM weather w
         WHERE {$where_sql}";
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$summary = $stmt->get_result()->fetch_assoc();
$stmt->close();

// ---------------------------------------------------------------------
// 작성자: 정서진 — 4) 수정 폼에 채울 기존 값 (관리자만)
// ---------------------------------------------------------------------
$edit_row = null;
if ($is_admin && valid_date($_GET['edit'] ?? '')) {
    $stmt = $conn->prepare('SELECT * FROM weather WHERE weather_date = ?');
    $stmt->bind_param('s', $_GET['edit']);
    $stmt->execute();
    $edit_row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($edit_row && $edit_input) {
        $edit_row = array_merge($edit_row, $edit_input);   // 오류 났을 때 입력한 값 유지
    }
}

$flash = $_SESSION['weather_flash'] ?? '';
unset($_SESSION['weather_flash']);
$back_url = 'weather_manage.php?' . $_SESSION['weather_query'];
?>
<main class="container">
  <h2>날씨 기록 관리</h2>

  <?php if ($flash !== ''): ?><p class="msg success"><?= h($flash) ?></p><?php endif; ?>
  <?php if ($date_error !== ''): ?><p class="msg notice"><?= h($date_error) ?></p><?php endif; ?>
  <?php foreach ($edit_errors as $e): ?><p class="msg error"><?= h($e) ?></p><?php endforeach; ?>

  <?php if ($edit_row): ?>
  <!-- 작성자: 정서진 — 수정 폼 (관리자만 보임) -->
  <form method="post" action="<?= h($back_url) ?>" class="search-form">
    <input type="hidden" name="action" value="update">
    <input type="hidden" name="csrf" value="<?= h($_SESSION['weather_csrf']) ?>">
    <input type="hidden" name="weather_date" value="<?= h($edit_row['weather_date']) ?>">
    <div class="row">
      <strong><?= h($edit_row['weather_date']) ?> 수정</strong>
      <span class="count">현재 날씨: <?= h($edit_row['weather_type']) ?> · 기온 구간: <?= h($edit_row['temp_band']) ?>
        (날씨·기온 구간은 저장하면 자동으로 다시 계산돼요)</span>
    </div>
    <div class="row">
      <?php foreach ($EDIT_FIELDS as $col => [$label, $min, $max, $nullable]): ?>
        <label><?= h($label) ?>
          <input type="number" name="<?= $col ?>" step="0.1" min="<?= $min ?>" max="<?= $max ?>"
                 value="<?= h($edit_row[$col] ?? '') ?>" <?= $nullable ? '' : 'required' ?> style="width:90px">
        </label>
      <?php endforeach; ?>
    </div>
    <div class="row">
      <button type="submit">수정 저장</button>
      <button type="button" onclick="location.href='<?= h($back_url) ?>'">취소</button>
    </div>
  </form>
  <?php endif; ?>

  <!-- 작성자: 정서진 — 검색 폼 -->
  <form method="get" action="weather_manage.php" class="search-form">
    <div class="row">
      <label>기간
        <input type="date" name="date_from" value="<?= h($date_from) ?>" min="2024-01-01" max="2024-12-31">
        ~
        <input type="date" name="date_to" value="<?= h($date_to) ?>" min="2024-01-01" max="2024-12-31">
      </label>

      <span class="group">날씨
        <?php foreach ($WEATHER_TYPES as $t): ?>
          <label><input type="checkbox" name="wtype[]" value="<?= h($t) ?>"
                 <?= in_array($t, $types_sel, true) ? 'checked' : '' ?>> <?= h($t) ?></label>
        <?php endforeach; ?>
      </span>

      <label>기온 구간
        <select name="temp_band">
          <option value="">전체</option>
          <?php foreach ($TEMP_BANDS as $b): ?>
            <option value="<?= h($b) ?>" <?= $b === $temp_band ? 'selected' : '' ?>><?= h($b) ?><?= $b === '영하' ? '' : '℃' ?></option>
          <?php endforeach; ?>
        </select>
      </label>

      <label>강수량
        <input type="number" name="min_rain" value="<?= h($min_rain) ?>" min="0" step="0.1" style="width:80px"> mm 이상
      </label>
    </div>

    <div class="row">
      <span class="group">정렬
        <?php foreach ($SORT_LABEL as $v => $label): ?>
          <label><input type="radio" name="sort" value="<?= $v ?>" <?= $v === $sort ? 'checked' : '' ?>> <?= $label ?></label>
        <?php endforeach; ?>
      </span>

      <button type="submit" name="search" value="1">검색</button>
      <button type="button" onclick="location.href='weather_manage.php'">초기화</button>
    </div>
  </form>

  <p class="count">
    검색 결과 <strong><?= (int)$summary['days'] ?></strong>일
    <?php if ((int)$summary['days'] > 0): ?>
      · 평균기온 <?= h($summary['avg_temp']) ?>℃
      · 강수량 합계 <?= h($summary['rain_sum']) ?>mm
      · 비/눈 온 날 <?= (int)$summary['wet_days'] ?>일
    <?php endif; ?>
    <?php if (!$is_admin): ?> · 수정은 관리자만 할 수 있어요<?php endif; ?>
  </p>

  <table class="result">
    <thead>
      <tr>
        <th>날짜</th><th>요일</th><th>날씨</th><th>기온 구간</th>
        <th class="num">평균(℃)</th><th class="num">최저</th><th class="num">최고</th>
        <th class="num">강수(mm)</th><th class="num">강수 시간</th>
        <th class="num">습도(%)</th><th class="num">풍속</th><th class="num">전운량</th>
        <th class="num">새 눈(cm)</th><th class="num">쌓인 눈</th>
        <?php if ($is_admin): ?><th></th><?php endif; ?>
      </tr>
    </thead>
    <tbody>
      <?php if (count($rows) === 0): ?>
        <tr><td colspan="<?= $is_admin ? 15 : 14 ?>" class="empty">조건에 맞는 날씨 기록이 없습니다.</td></tr>
      <?php else: foreach ($rows as $r): ?>
        <tr>
          <td><?= h($r['weather_date']) ?></td>
          <td class="<?= $r['day_type'] === '휴일' ? 'st-warn' : '' ?>"
              title="<?= h($r['holiday_name'] ?? '') ?>"><?= h($r['day_name']) ?><?= $r['holiday_name'] ? ' (' . h($r['holiday_name']) . ')' : '' ?></td>
          <td><?= h($r['weather_type']) ?></td>
          <td><?= h($r['temp_band']) ?></td>
          <td class="num"><?= h($r['avg_temp']) ?></td>
          <td class="num"><?= h($r['min_temp'] ?? '-') ?></td>
          <td class="num"><?= h($r['max_temp'] ?? '-') ?></td>
          <td class="num"><?= h($r['precipitation']) ?></td>
          <td class="num"><?= h($r['rain_hours']) ?></td>
          <td class="num"><?= h($r['avg_humidity'] ?? '-') ?></td>
          <td class="num"><?= h($r['avg_wind'] ?? '-') ?></td>
          <td class="num"><?= h($r['avg_cloud'] ?? '-') ?></td>
          <td class="num"><?= h($r['new_snow_cm']) ?></td>
          <td class="num"><?= h($r['snow_depth_cm']) ?></td>
          <?php if ($is_admin): ?>
            <td><a href="weather_manage.php?<?= h(http_build_query(array_merge($query_keep, ['edit' => $r['weather_date']]))) ?>">수정</a></td>
          <?php endif; ?>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</main>
<?php
if (file_exists(__DIR__ . '/footer.php')) {
    require_once __DIR__ . '/footer.php';
}
