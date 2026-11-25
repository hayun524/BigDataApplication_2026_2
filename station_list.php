<?php
/**
 * station_list.php : 대여소 검색 (SELECT)
 * 작성자 : 김수연 (1. DB 설계)
 *
 * 입력 컨트롤
 *   - select   : 생활권, 자치구
 *   - checkbox : 상태 (정상 / 점검중)
 *   - radio    : 한강공원 (전체 / 한강공원만 / 일반만), 정렬 기준
 *   - text     : 대여소명 검색
 *   - button   : 검색, 초기화
 *
 * 사용자 입력은 전부 Prepared Statement 로 바인딩한다.
 * 정렬 컬럼처럼 바인딩할 수 없는 값은 화이트리스트로만 허용한다.
 */

require_once __DIR__ . '/config.php';   // $conn (mysqli) — 6번 공통 파일
require_once __DIR__ . '/header.php';   // 공통 헤더·세션 — 6번 공통 파일

// 작성자: 김수연 — 화면 출력용 이스케이프
function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

// 작성자: 김수연 — 셀렉트 박스 목록 (생활권, 자치구)
function load_districts(mysqli $conn) {
    $sql = "SELECT district_id, district_name, life_zone, district_type
              FROM district
             ORDER BY FIELD(life_zone, '도심권','동북권','서북권','서남권','동남권'), district_name";
    $rows = [];
    $res = $conn->query($sql);          // 사용자 입력 없음 → 일반 쿼리
    while ($r = $res->fetch_assoc()) {
        $rows[] = $r;
    }
    return $rows;
}

// ---------------------------------------------------------------------
// 1) 입력값 읽기 + 허용값 검사
// ---------------------------------------------------------------------
$LIFE_ZONES   = ['도심권', '동북권', '서북권', '서남권', '동남권'];
$STATUSES     = ['정상', '점검중'];
$HANGANG_OPTS = ['all', 'only', 'none'];
$SORT_SQL     = [                       // 화이트리스트: 화면 값 → ORDER BY 절
    'name'  => 's.station_name ASC',
    'rack'  => 's.rack_count DESC, s.station_name ASC',
    'zone'  => "FIELD(d.life_zone, '도심권','동북권','서북권','서남권','동남권'), d.district_name, s.station_name",
];

$searched    = isset($_GET['search']);
$life_zone   = in_array($_GET['life_zone'] ?? '', $LIFE_ZONES, true) ? $_GET['life_zone'] : '';
$district_id = ctype_digit($_GET['district_id'] ?? '') ? (int)$_GET['district_id'] : 0;
$name        = trim($_GET['name'] ?? '');
$hangang     = in_array($_GET['hangang'] ?? '', $HANGANG_OPTS, true) ? $_GET['hangang'] : 'all';
$sort        = array_key_exists($_GET['sort'] ?? '', $SORT_SQL) ? $_GET['sort'] : 'zone';

// 상태 체크박스: 처음 들어왔을 때는 둘 다 체크, 검색 후에는 체크한 것만
if ($searched) {
    $status = array_values(array_intersect($STATUSES, (array)($_GET['status'] ?? [])));
} else {
    $status = $STATUSES;
}

// ---------------------------------------------------------------------
// 2) 동적 WHERE 절 + 바인딩 파라미터 만들기
// ---------------------------------------------------------------------
$where  = [];
$types  = '';
$params = [];

if ($life_zone !== '') {
    $where[] = 'd.life_zone = ?';
    $types  .= 's';
    $params[] = $life_zone;
}
if ($district_id > 0) {
    $where[] = 's.district_id = ?';
    $types  .= 'i';
    $params[] = $district_id;
}
if ($name !== '') {
    $where[] = 's.station_name LIKE ?';
    $types  .= 's';
    $params[] = '%' . $name . '%';
}
if (count($status) === 0) {
    $where[] = '1 = 0';                 // 상태를 하나도 안 고르면 결과 없음
} elseif (count($status) < count($STATUSES)) {
    $where[] = 's.status IN (' . implode(',', array_fill(0, count($status), '?')) . ')';
    $types  .= str_repeat('s', count($status));
    foreach ($status as $st) {
        $params[] = $st;
    }
}
if ($hangang === 'only') {
    $where[] = 's.hangang_park IS NOT NULL';
} elseif ($hangang === 'none') {
    $where[] = 's.hangang_park IS NULL';
}

$sql = "SELECT s.station_id, s.station_name, d.district_name, d.life_zone,
               d.district_type, s.rack_count, s.hangang_park, s.status
          FROM station s
          JOIN district d ON d.district_id = s.district_id"
     . (count($where) ? "\n         WHERE " . implode("\n           AND ", $where) : '')
     . "\n         ORDER BY " . $SORT_SQL[$sort];

// ---------------------------------------------------------------------
// 3) 실행
// ---------------------------------------------------------------------
$stmt = $conn->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result   = $stmt->get_result();
$stations = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$districts = load_districts($conn);
?>
<main class="container">
  <h2>대여소 검색</h2>

  <form method="get" action="station_list.php" class="search-form">
    <div class="row">
      <label>생활권
        <select name="life_zone" id="life_zone">
          <option value="">전체</option>
          <?php foreach ($LIFE_ZONES as $z): ?>
            <option value="<?= h($z) ?>" <?= $z === $life_zone ? 'selected' : '' ?>><?= h($z) ?></option>
          <?php endforeach; ?>
        </select>
      </label>

      <label>자치구
        <select name="district_id" id="district_id">
          <option value="">전체</option>
          <?php foreach ($districts as $d): ?>
            <option value="<?= (int)$d['district_id'] ?>"
                    data-zone="<?= h($d['life_zone']) ?>"
                    <?= (int)$d['district_id'] === $district_id ? 'selected' : '' ?>>
              <?= h($d['district_name']) ?> (<?= h($d['district_type']) ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </label>

      <span class="group">상태
        <?php foreach ($STATUSES as $st): ?>
          <label><input type="checkbox" name="status[]" value="<?= h($st) ?>"
                 <?= in_array($st, $status, true) ? 'checked' : '' ?>> <?= h($st) ?></label>
        <?php endforeach; ?>
      </span>
    </div>

    <div class="row">
      <span class="group">한강공원
        <?php foreach (['all' => '전체', 'only' => '한강공원만', 'none' => '일반만'] as $v => $label): ?>
          <label><input type="radio" name="hangang" value="<?= $v ?>" <?= $v === $hangang ? 'checked' : '' ?>> <?= $label ?></label>
        <?php endforeach; ?>
      </span>

      <span class="group">정렬
        <?php foreach (['zone' => '생활권순', 'name' => '이름순', 'rack' => '거치대 많은 순'] as $v => $label): ?>
          <label><input type="radio" name="sort" value="<?= $v ?>" <?= $v === $sort ? 'checked' : '' ?>> <?= $label ?></label>
        <?php endforeach; ?>
      </span>

      <label>대여소명
        <input type="text" name="name" value="<?= h($name) ?>" placeholder="예: 역" maxlength="50">
      </label>

      <button type="submit" name="search" value="1">검색</button>
      <button type="button" onclick="location.href='station_list.php'">초기화</button>
    </div>
  </form>

  <p class="count">검색 결과 <strong><?= count($stations) ?></strong>건</p>

  <table class="result">
    <thead>
      <tr>
        <th>번호</th><th>대여소명</th><th>자치구</th><th>생활권</th>
        <th>유형</th><th>거치대</th><th>한강공원</th><th>상태</th>
      </tr>
    </thead>
    <tbody>
      <?php if (count($stations) === 0): ?>
        <tr><td colspan="8" class="empty">조건에 맞는 대여소가 없습니다.</td></tr>
      <?php else: foreach ($stations as $s): ?>
        <tr>
          <td><?= (int)$s['station_id'] ?></td>
          <td><?= h($s['station_name']) ?></td>
          <td><?= h($s['district_name']) ?></td>
          <td><?= h($s['life_zone']) ?></td>
          <td><?= h($s['district_type']) ?></td>
          <td class="num"><?= (int)$s['rack_count'] ?></td>
          <td><?= $s['hangang_park'] === null ? '-' : h($s['hangang_park']) ?></td>
          <td class="<?= $s['status'] === '점검중' ? 'st-warn' : 'st-ok' ?>"><?= h($s['status']) ?></td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</main>

<script>
// 작성자: 김수연 — 생활권을 고르면 그 생활권 자치구만 보이게 (서버 검색 조건과 별개인 화면 편의 기능)
(function () {
  var zone = document.getElementById('life_zone');
  var dist = document.getElementById('district_id');
  function filter() {
    var z = zone.value;
    for (var i = 1; i < dist.options.length; i++) {
      var o = dist.options[i];
      var show = (z === '' || o.getAttribute('data-zone') === z);
      o.hidden = !show;
      if (!show && o.selected) { dist.value = ''; }
    }
  }
  zone.addEventListener('change', filter);
  filter();
})();
</script>
<?php
if (file_exists(__DIR__ . '/footer.php')) {
    require_once __DIR__ . '/footer.php';
}
