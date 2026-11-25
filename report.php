<?php

/**
 * report.php : 고장 신고 접수·처리 (트랜잭션)
 * 작성자 : 정하윤 (3. CRUD 웹)
 *
 * 트랜잭션 (지침 2-(7))
 *   - 접수     : fault_report INSERT + station.status = '점검중' UPDATE
 *   - 처리완료 : fault_report.process_status = '완료' UPDATE + station.status = '정상' UPDATE
 *                (같은 대여소에 아직 처리 안 된 신고가 남아 있으면 '점검중' 유지)
 *   - 둘 중 하나라도 실패하면 rollback → 신고만 들어가고 대여소 상태는 그대로인 일이 없게
 *   - config.php가 mysqli_report(STRICT)로 오류를 예외로 던지므로 try/catch로 rollback
 *
 * 권한
 *   - 신고 접수 : 로그인한 사용자 누구나 (신고자 = 세션의 user_id, 지침 2-(6))
 *   - 처리완료  : 관리자(role = 'admin')만 (header.php의 $is_admin)
 *
 * 입력 컨트롤 (지침 2-(5)) : select(대여소, 상태 필터), radio(고장 유형), textarea(내용),
 *                            checkbox(rollback 시연), button(접수, 처리완료, 조회)
 * 사용자 입력은 전부 Prepared Statement 로 바인딩한다.
 */

$page_title = '고장신고';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/header.php';

// 작성자: 정하윤 — 화면 출력용 이스케이프 (다른 페이지와 겹치지 않게 접두어)
function rp_h($s)
{
  return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

// 작성자: 정하윤 — 결과 메시지를 세션에 저장하고 같은 페이지로 이동 (새로고침 중복 방지)
function rp_redirect($type, $msg)
{
  $_SESSION['rp_flash'] = ['type' => $type, 'msg' => $msg];
  $query = $_SESSION['rp_filter'] ?? '';
  header('Location: report.php' . ($query !== '' ? '?status=' . urlencode($query) : ''));
  exit;
}

// dbcreate.sql의 ENUM 값과 같게 유지
$FAULT_TYPES = ['타이어', '브레이크', '체인', '페달', '안장', '단말기', '거치대', '기타'];
$FILTERS     = ['open' => '미처리 (접수·처리중)', 'done' => '완료', 'all' => '전체'];

// ---------------------------------------------------------------------
// 1) 신고 접수 — 트랜잭션: INSERT fault_report + UPDATE station
// 작성자: 정하윤
// ---------------------------------------------------------------------
function rp_receive(mysqli $conn, $station_id, $user_id, $fault_type, $description, $force_fail)
{
  $conn->begin_transaction();
  try {
    // (1) 신고 INSERT — 신고자는 세션의 user_id
    //     신고 일시는 PHP 시각(config.php: Asia/Seoul)으로 넣어 DB 서버 시간대와 상관없이 한국 시간
    $reported_at = date('Y-m-d H:i:s');
    $stmt = $conn->prepare(
      "INSERT INTO fault_report (station_id, user_id, fault_type, description, reported_at)
             VALUES (?, ?, ?, ?, ?)"
    );
    $stmt->bind_param('iisss', $station_id, $user_id, $fault_type, $description, $reported_at);
    $stmt->execute();
    $report_id = $conn->insert_id;
    $stmt->close();

    // rollback 시연용: 두 번째 쿼리 직전에 일부러 실패시킨다
    if ($force_fail) {
      throw new RuntimeException('rollback 시연: 대여소 상태 변경 전에 강제로 실패시켰어요.');
    }

    // (2) 대여소 상태 → '점검중'
    $stmt = $conn->prepare("UPDATE station SET status = '점검중' WHERE station_id = ?");
    $stmt->bind_param('i', $station_id);
    $stmt->execute();
    $stmt->close();

    $conn->commit();
    return $report_id;
  } catch (Throwable $e) {
    $conn->rollback();   // (1) INSERT까지 함께 취소
    throw $e;
  }
}

// ---------------------------------------------------------------------
// 2) 처리완료 — 트랜잭션: UPDATE fault_report + UPDATE station
// 작성자: 정하윤
// ---------------------------------------------------------------------
function rp_complete(mysqli $conn, $report_id, $force_fail)
{
  $conn->begin_transaction();
  try {
    // 신고를 잠그고(FOR UPDATE) 대여소 번호·현재 상태 확인 → 동시에 두 번 처리되는 것 방지
    $stmt = $conn->prepare(
      "SELECT station_id, process_status FROM fault_report WHERE report_id = ? FOR UPDATE"
    );
    $stmt->bind_param('i', $report_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
      throw new RuntimeException('없는 신고 번호예요.');
    }
    if ($row['process_status'] === '완료') {
      throw new RuntimeException('이미 처리완료된 신고예요.');
    }
    $station_id = (int)$row['station_id'];

    // (1) 신고 상태 → '완료'
    $stmt = $conn->prepare("UPDATE fault_report SET process_status = '완료' WHERE report_id = ?");
    $stmt->bind_param('i', $report_id);
    $stmt->execute();
    $stmt->close();

    if ($force_fail) {
      throw new RuntimeException('rollback 시연: 대여소 상태 변경 전에 강제로 실패시켰어요.');
    }

    // (2) 대여소 상태 → '정상' (같은 대여소에 미처리 신고가 더 없을 때만)
    $stmt = $conn->prepare(
      "UPDATE station
                SET status = '정상'
              WHERE station_id = ?
                AND NOT EXISTS (SELECT 1 FROM fault_report
                                 WHERE station_id = ? AND process_status <> '완료')"
    );
    $stmt->bind_param('ii', $station_id, $station_id);
    $stmt->execute();
    $restored = $stmt->affected_rows > 0;
    $stmt->close();

    $conn->commit();
    return $restored;
  } catch (Throwable $e) {
    $conn->rollback();   // (1) 신고 상태 변경까지 함께 취소
    throw $e;
  }
}

// ---------------------------------------------------------------------
// 3) POST 처리
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action     = $_POST['action'] ?? '';
  $force_fail = $is_admin && !empty($_POST['force_fail']);   // rollback 시연은 관리자만

  try {
    if ($action === 'receive') {
      $station_id  = (int)($_POST['station_id'] ?? 0);
      $fault_type  = $_POST['fault_type'] ?? '';
      $description = trim($_POST['description'] ?? '');
      $user_id     = (int)$_SESSION['user_id'];

      if ($station_id <= 0) {
        rp_redirect('error', '대여소를 골라 주세요.');
      }
      if (!in_array($fault_type, $FAULT_TYPES, true)) {
        rp_redirect('error', '고장 유형을 골라 주세요.');
      }
      if (mb_strlen($description) > 500) {
        rp_redirect('error', '내용은 500자까지 쓸 수 있어요.');
      }

      $report_id = rp_receive(
        $conn,
        $station_id,
        $user_id,
        $fault_type,
        $description === '' ? null : $description,
        $force_fail
      );
      rp_redirect('success', "{$report_id}번 신고를 접수했어요. 대여소 상태가 '점검중'으로 바뀌었어요.");
    } elseif ($action === 'complete') {
      if (!$is_admin) {
        rp_redirect('error', '처리완료는 관리자만 할 수 있어요.');
      }
      $report_id = (int)($_POST['report_id'] ?? 0);
      $restored  = rp_complete($conn, $report_id, $force_fail);
      rp_redirect('success', $restored
        ? "{$report_id}번 신고를 처리완료했어요. 대여소 상태가 '정상'으로 돌아왔어요."
        : "{$report_id}번 신고를 처리완료했어요. 같은 대여소에 남은 신고가 있어 '점검중'을 유지해요.");
    }
  } catch (Throwable $e) {
    // rollback은 각 함수 안에서 이미 끝났다
    rp_redirect('error', '처리하지 못해 모두 취소(rollback)했어요. ' . $e->getMessage());
  }
}

// ---------------------------------------------------------------------
// 4) 결과 메시지 (한 번 보여주고 삭제) + 목록 필터 (세션에 기억)
// ---------------------------------------------------------------------
$flash = $_SESSION['rp_flash'] ?? null;
unset($_SESSION['rp_flash']);

$filter = $_GET['status'] ?? ($_SESSION['rp_filter'] ?? 'open');
if (!isset($FILTERS[$filter])) {
  $filter = 'open';
}
$_SESSION['rp_filter'] = $filter;

// ---------------------------------------------------------------------
// 5) 폼에 쓸 대여소 목록, 신고 목록 (SELECT)
// ---------------------------------------------------------------------
$stations = $conn->query(
  "SELECT s.station_id, s.station_name, s.status, d.district_name
       FROM station s
       JOIN district d ON d.district_id = s.district_id
      ORDER BY d.district_name, s.station_name"
)->fetch_all(MYSQLI_ASSOC);

// 필터 값('open'/'done'/'all')은 위에서 허용 목록으로 검사했고, 조건 값은 ? 로 바인딩
$sql = "SELECT r.report_id, r.fault_type, r.description, r.reported_at, r.process_status,
               s.station_id, s.station_name, s.status AS station_status,
               d.district_name, u.user_name
          FROM fault_report r
          JOIN station      s ON s.station_id  = r.station_id
          JOIN district     d ON d.district_id = s.district_id
          JOIN user_account u ON u.user_id     = r.user_id
         WHERE (? = 'all'
                OR (? = 'open' AND r.process_status <> '완료')
                OR (? = 'done' AND r.process_status =  '완료'))
         ORDER BY r.report_id DESC
         LIMIT 100";
$stmt = $conn->prepare($sql);
$stmt->bind_param('sss', $filter, $filter, $filter);
$stmt->execute();
$reports = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<main class="container">
  <h2>고장신고</h2>

  <?php if ($flash): ?>
    <p class="msg <?= rp_h($flash['type']) ?>"><?= rp_h($flash['msg']) ?></p>
  <?php endif; ?>

  <!-- 신고 접수 폼 -->
  <section class="panel">
    <h3>신고 접수 <small class="count">신고자: <?= rp_h($_SESSION['user_name'] ?? '') ?></small></h3>
    <form method="post" class="search-form">
      <input type="hidden" name="action" value="receive">

      <div class="row">
        <label>대여소
          <select name="station_id" required>
            <option value="">-- 대여소 선택 --</option>
            <?php foreach ($stations as $s): ?>
              <option value="<?= (int)$s['station_id'] ?>">
                [<?= rp_h($s['district_name']) ?>] <?= rp_h($s['station_name']) ?>
                <?= $s['status'] === '점검중' ? '(점검중)' : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>
      </div>

      <div class="row group">
        <span>고장 유형</span>
        <?php foreach ($FAULT_TYPES as $i => $t): ?>
          <label>
            <input type="radio" name="fault_type" value="<?= rp_h($t) ?>" <?= $i === 0 ? 'checked' : '' ?>>
            <?= rp_h($t) ?>
          </label>
        <?php endforeach; ?>
      </div>

      <div class="row">
        <label>내용
          <textarea name="description" rows="2" cols="60" maxlength="500" placeholder="예: 앞바퀴 바람이 빠져 있어요"></textarea>
        </label>
      </div>

      <div class="row">
        <button type="submit">신고 접수</button>
        <?php if ($is_admin): ?>
          <label title="트랜잭션 시연용: 두 번째 쿼리 전에 강제로 실패시켜 rollback 확인">
            <input type="checkbox" name="force_fail" value="1"> rollback 시연 (강제 실패)
          </label>
        <?php endif; ?>
      </div>
    </form>
  </section>

  <!-- 신고 목록 -->
  <section class="panel">
    <form method="get" class="search-form">
      <div class="row">
        <h3 style="margin:0">신고 목록 <small class="count"><?= count($reports) ?>건</small></h3>
        <label>상태
          <select name="status">
            <?php foreach ($FILTERS as $key => $label): ?>
              <option value="<?= $key ?>" <?= $filter === $key ? 'selected' : '' ?>><?= rp_h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <button type="submit">조회</button>
      </div>
    </form>

    <table class="result">
      <thead>
        <tr>
          <th class="num">신고번호</th>
          <th>대여소</th>
          <th>유형</th>
          <th>내용</th>
          <th>신고자</th>
          <th>신고 일시</th>
          <th>신고 상태</th>
          <th>대여소 상태</th>
          <?php if ($is_admin): ?><th></th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
        <?php if (!$reports): ?>
          <tr>
            <td class="empty" colspan="<?= $is_admin ? 9 : 8 ?>">신고가 없어요.</td>
          </tr>
        <?php endif; ?>
        <?php foreach ($reports as $r): ?>
          <tr>
            <td class="num"><?= (int)$r['report_id'] ?></td>
            <td>[<?= rp_h($r['district_name']) ?>] <?= rp_h($r['station_name']) ?></td>
            <td><?= rp_h($r['fault_type']) ?></td>
            <td><?= rp_h($r['description'] ?? '') ?></td>
            <td><?= rp_h($r['user_name']) ?></td>
            <td><?= rp_h(substr($r['reported_at'], 0, 16)) ?></td>
            <td class="<?= $r['process_status'] === '완료' ? 'st-ok' : 'st-warn' ?>"><?= rp_h($r['process_status']) ?></td>
            <td class="<?= $r['station_status'] === '정상' ? 'st-ok' : 'st-warn' ?>"><?= rp_h($r['station_status']) ?></td>
            <?php if ($is_admin): ?>
              <td>
                <?php if ($r['process_status'] !== '완료'): ?>
                  <form method="post" style="display:inline"
                    onsubmit="return confirm('<?= (int)$r['report_id'] ?>번 신고를 처리완료할까요?');">
                    <input type="hidden" name="action" value="complete">
                    <input type="hidden" name="report_id" value="<?= (int)$r['report_id'] ?>">
                    <button type="submit">처리완료</button>
                    <label title="트랜잭션 시연용"><input type="checkbox" name="force_fail" value="1"> 강제 실패</label>
                  </form>
                <?php endif; ?>
              </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </section>
</main>
<?php require_once __DIR__ . '/footer.php'; ?>