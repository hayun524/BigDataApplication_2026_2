<?php

/**
 * station_form.php : 대여소 등록·수정·삭제 (INSERT, UPDATE, DELETE)
 * 작성자 : 정하윤 (3. CRUD 웹)
 *
 * - 사용자 입력이 들어가는 쿼리는 전부 Prepared Statement 사용
 * - 처리 후 결과 메시지는 세션에 담고 같은 페이지로 redirect (새로고침 시 중복 처리 방지)
 * - 이용 기록(rental_daily)이 있는 대여소는 FK 때문에 삭제 불가 → 안내 메시지
 *
 */

$page_title = '대여소 관리';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/header.php';

// 작성자: 정하윤 — 화면 출력용 이스케이프 (index.php의 idx_h()처럼 접두어로 구분)
function sf_h($s)
{
  return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

// 작성자: 정하윤 — 결과 메시지를 세션에 저장하고 같은 페이지로 이동
function sf_redirect($type, $msg)
{
  $_SESSION['sf_flash'] = ['type' => $type, 'msg' => $msg];
  header('Location: station_form.php');
  exit;
}

// TODO: 권한 체크 — index.php 기준 role이 'admin'일 때만 데이터 수정 가능
$is_admin = ($_SESSION['role'] ?? '') === 'admin';

// ---------------------------------------------------------------------
// 1) POST 처리 (INSERT / UPDATE / DELETE)
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!$is_admin) {
    sf_redirect('error', '관리자만 수정할 수 있어요.');
  }

  $action = $_POST['action'] ?? '';

  try {
    if ($action === 'insert') {
      // 작성자: 정하윤 — 대여소 등록 (INSERT)
      // TODO: 입력값 검증 (빈 값, 거치대 수 범위 등)
      $stmt = $conn->prepare(
        "INSERT INTO station (station_id, station_name, district_id, rack_count, status)
                 VALUES (?, ?, ?, ?, ?)"
      );
      $stmt->bind_param(
        'isiis',
        $_POST['station_id'],
        $_POST['station_name'],
        $_POST['district_id'],
        $_POST['rack_count'],
        $_POST['status']
      );
      $stmt->execute();
      $stmt->close();
      sf_redirect('ok', '대여소를 등록했어요.');
    } elseif ($action === 'update') {
      // 작성자: 정하윤 — 대여소 수정 (UPDATE)
      // TODO: 입력값 검증
      $stmt = $conn->prepare(
        "UPDATE station
                    SET station_name = ?, district_id = ?, rack_count = ?, status = ?
                  WHERE station_id = ?"
      );
      $stmt->bind_param(
        'siisi',
        $_POST['station_name'],
        $_POST['district_id'],
        $_POST['rack_count'],
        $_POST['status'],
        $_POST['station_id']
      );
      $stmt->execute();
      $stmt->close();
      sf_redirect('ok', '대여소 정보를 수정했어요.');
    } elseif ($action === 'delete') {
      // 작성자: 정하윤 — 대여소 삭제 (DELETE)
      $stmt = $conn->prepare("DELETE FROM station WHERE station_id = ?");
      $stmt->bind_param('i', $_POST['station_id']);
      $stmt->execute();
      $stmt->close();
      sf_redirect('ok', '대여소를 삭제했어요.');
    }
  } catch (mysqli_sql_exception $e) {
    // 1451: 자식 테이블(rental_daily, fault_report)이 참조 중이라 삭제 불가
    // 1062: 이미 있는 대여소 번호로 등록 시도
    if ($e->getCode() == 1451) {
      sf_redirect('error', '이용 기록이나 고장 신고가 있는 대여소는 삭제할 수 없어요. 대신 상태를 바꿔 주세요.');
    } elseif ($e->getCode() == 1062) {
      sf_redirect('error', '이미 있는 대여소 번호예요.');
    }
    sf_redirect('error', '처리 중 오류가 났어요: ' . $e->getMessage());
  }
}

// ---------------------------------------------------------------------
// 2) 결과 메시지 (한 번 보여주고 삭제)
// ---------------------------------------------------------------------
$flash = $_SESSION['sf_flash'] ?? null;
unset($_SESSION['sf_flash']);

// ---------------------------------------------------------------------
// 3) 수정 모드: ?edit=번호 로 들어오면 기존 값 불러오기 (SELECT)
// ---------------------------------------------------------------------
$edit = null;
if (isset($_GET['edit'])) {
  $stmt = $conn->prepare(
    "SELECT station_id, station_name, district_id, rack_count, status
           FROM station WHERE station_id = ?"
  );
  $edit_id = (int)$_GET['edit'];
  $stmt->bind_param('i', $edit_id);
  $stmt->execute();
  $edit = $stmt->get_result()->fetch_assoc();
  $stmt->close();
}

// ---------------------------------------------------------------------
// 4) 폼에 쓸 자치구 목록, 아래 표에 쓸 대여소 목록 (입력 없는 고정 조회)
// ---------------------------------------------------------------------
$districts = $conn->query(
  "SELECT district_id, district_name FROM district ORDER BY district_name"
)
  ->fetch_all(MYSQLI_ASSOC);

$stations = $conn->query(
  "SELECT s.station_id, s.station_name, d.district_name, s.rack_count, s.status
       FROM station s
       JOIN district d ON d.district_id = s.district_id
      ORDER BY s.station_id"
)
  ->fetch_all(MYSQLI_ASSOC);

$STATUS = ['정상', '점검중'];   // TODO: dbcreate.sql의 status 값과 맞추기
?>
<main class="container">
  <h2>대여소 관리</h2>

  <?php if ($flash): ?>
    <p class="flash <?= sf_h($flash['type']) ?>"><?= sf_h($flash['msg']) ?></p>
  <?php endif; ?>

  <?php if (!$is_admin): ?>
    <p class="empty-note">일반 사용자는 조회만 할 수 있어요.</p>
  <?php else: ?>
    <!-- 등록 / 수정 폼 (요구사항 2-(5): text, number, select, radio, button) -->
    <section class="panel">
      <h3><?= $edit ? '대여소 수정' : '대여소 등록' ?></h3>
      <form method="post">
        <input type="hidden" name="action" value="<?= $edit ? 'update' : 'insert' ?>">

        <label>대여소 번호
          <input type="number" name="station_id" required value="<?= sf_h($edit['station_id'] ?? '') ?>"
            <?= $edit ? 'readonly' : '' ?>>
        </label>

        <label>대여소명
          <input type="text" name="station_name" required maxlength="100"
            value="<?= sf_h($edit['station_name'] ?? '') ?>">
        </label>

        <label>자치구
          <select name="district_id" required>
            <?php foreach ($districts as $d): ?>
              <option value="<?= (int)$d['district_id'] ?>"
                <?= ($edit && $edit['district_id'] == $d['district_id']) ? 'selected' : '' ?>>
                <?= sf_h($d['district_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>

        <label>거치대 수
          <input type="number" name="rack_count" min="1" max="100" required
            value="<?= sf_h($edit['rack_count'] ?? '') ?>">
        </label>

        <span>상태</span>
        <?php foreach ($STATUS as $st): ?>
          <label>
            <input type="radio" name="status" value="<?= sf_h($st) ?>"
              <?= (($edit['status'] ?? '정상') === $st) ? 'checked' : '' ?>> <?= sf_h($st) ?>
          </label>
        <?php endforeach; ?>

        <button type="submit"><?= $edit ? '수정 저장' : '등록' ?></button>
        <?php if ($edit): ?><a href="station_form.php">취소</a><?php endif; ?>
      </form>
    </section>
  <?php endif; ?>

  <!-- 대여소 목록 + 수정/삭제 버튼 -->
  <section class="panel">
    <h3>대여소 목록 <small><?= count($stations) ?>개</small></h3>
    <table class="result">
      <thead>
        <tr>
          <th>번호</th>
          <th>대여소명</th>
          <th>자치구</th>
          <th class="num">거치대</th>
          <th>상태</th>
          <?php if ($is_admin): ?><th></th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($stations as $s): ?>
          <tr>
            <td><?= (int)$s['station_id'] ?></td>
            <td><?= sf_h($s['station_name']) ?></td>
            <td><?= sf_h($s['district_name']) ?></td>
            <td class="num"><?= (int)$s['rack_count'] ?></td>
            <td><?= sf_h($s['status']) ?></td>
            <?php if ($is_admin): ?>
              <td>
                <a href="station_form.php?edit=<?= (int)$s['station_id'] ?>">수정</a>
                <!-- 삭제 전 확인창 -->
                <form method="post" style="display:inline"
                  onsubmit="return confirm('<?= sf_h($s['station_name']) ?> 대여소를 삭제할까요?');">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="station_id" value="<?= (int)$s['station_id'] ?>">
                  <button type="submit">삭제</button>
                </form>
              </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </section>
</main>
<?php require_once __DIR__ . '/footer.php'; ?>