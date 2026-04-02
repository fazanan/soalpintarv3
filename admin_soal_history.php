<?php
session_start();
if (!isset($_SESSION['user_id'])) {
  header('Location: login.php');
  exit;
}
$role = (string)($_SESSION['role'] ?? 'user');
if ($role !== 'admin') {
  http_response_code(403);
  echo 'Forbidden';
  exit;
}
require_once __DIR__ . '/db.php';

$modelCfgCache = [];
function table_exists(mysqli $db, string $table): bool {
  $table = $db->real_escape_string($table);
  $sql = "SHOW TABLES LIKE '$table'";
  if ($res = $db->query($sql)) {
    $ok = $res->num_rows > 0;
    $res->close();
    return $ok;
  }
  return false;
}
function get_model_config(mysqli $db, string $model): ?array {
  if (!table_exists($db, 'api_models')) return null;
  $sql = "SELECT token_input_price, token_output_price, currency_rate_to_idr
            FROM api_models
           WHERE is_active = 1 AND modality='chat' AND model = ?
        ORDER BY updated_at DESC
           LIMIT 1";
  $stmt = $db->prepare($sql);
  if (!$stmt) return null;
  $stmt->bind_param('s', $model);
  $stmt->execute();
  $res = $stmt->get_result();
  $row = $res ? $res->fetch_assoc() : null;
  $stmt->close();
  return $row ?: null;
}

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;

$q = trim((string)($_GET['q'] ?? ''));
$from = trim((string)($_GET['from'] ?? ''));
$to = trim((string)($_GET['to'] ?? ''));

$whereParts = [];
$params = [];
$types = '';
if ($q !== '') {
  $whereParts[] = "(su.title LIKE CONCAT('%', ?, '%') OR u.username LIKE CONCAT('%', ?, '%'))";
  $params[] = $q;
  $params[] = $q;
  $types .= 'ss';
}
if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
  $whereParts[] = "su.created_at >= CONCAT(?, ' 00:00:00')";
  $params[] = $from;
  $types .= 's';
}
if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
  $whereParts[] = "su.created_at <= CONCAT(?, ' 23:59:59')";
  $params[] = $to;
  $types .= 's';
}
$where = $whereParts ? ('WHERE ' . implode(' AND ', $whereParts)) : '';

// summary stats
$summary = [
  'active_users' => 0,
  'all_devices_users' => 0,
  'paket_soal' => 0,
  'modul_ajar' => 0,
  'rpp' => 0,
  'token_input_total' => 0,
  'token_output_total' => 0,
  'total_cost_idr' => 0.0,
];

try {
  $sqlSum = "
    SELECT
      COUNT(DISTINCT su.user_id) AS active_users,
      SUM(CASE WHEN su.question_count > 0 THEN 1 ELSE 0 END) AS paket_soal,
      SUM(CASE WHEN su.title LIKE 'Modul Ajar - %' THEN 1 ELSE 0 END) AS modul_ajar,
      SUM(CASE WHEN su.title LIKE 'RPP - %' THEN 1 ELSE 0 END) AS rpp,
      SUM(su.token_input) AS token_input_total,
      SUM(su.token_output) AS token_output_total,
      SUM(((su.token_input/1000) * su.token_input_price + (su.token_output/1000) * su.token_output_price) * su.currency_rate_to_idr) AS total_cost_idr
    FROM soal_user su
    JOIN users u ON u.id = su.user_id
    $where
  ";
  $stmtSum = $mysqli->prepare($sqlSum);
  if ($stmtSum) {
    if ($types !== '') $stmtSum->bind_param($types, ...$params);
    $stmtSum->execute();
    $resSum = $stmtSum->get_result();
    $rowSum = $resSum ? $resSum->fetch_assoc() : null;
    $stmtSum->close();
    if ($rowSum) {
      $summary['active_users'] = (int)($rowSum['active_users'] ?? 0);
      $summary['paket_soal'] = (int)($rowSum['paket_soal'] ?? 0);
      $summary['modul_ajar'] = (int)($rowSum['modul_ajar'] ?? 0);
      $summary['rpp'] = (int)($rowSum['rpp'] ?? 0);
      $summary['token_input_total'] = (int)($rowSum['token_input_total'] ?? 0);
      $summary['token_output_total'] = (int)($rowSum['token_output_total'] ?? 0);
      $summary['total_cost_idr'] = (float)($rowSum['total_cost_idr'] ?? 0);
    }
  }

  $sqlAll = "
    SELECT COUNT(*) AS c FROM (
      SELECT su.user_id,
        MAX(CASE WHEN su.question_count > 0 THEN 1 ELSE 0 END) AS has_paket,
        MAX(CASE WHEN su.title LIKE 'Modul Ajar - %' THEN 1 ELSE 0 END) AS has_modul,
        MAX(CASE WHEN su.title LIKE 'RPP - %' THEN 1 ELSE 0 END) AS has_rpp
      FROM soal_user su
      JOIN users u ON u.id = su.user_id
      $where
      GROUP BY su.user_id
      HAVING has_paket = 1 AND has_modul = 1 AND has_rpp = 1
    ) x
  ";
  $stmtAll = $mysqli->prepare($sqlAll);
  if ($stmtAll) {
    if ($types !== '') $stmtAll->bind_param($types, ...$params);
    $stmtAll->execute();
    $resAll = $stmtAll->get_result();
    $rowAll = $resAll ? $resAll->fetch_assoc() : null;
    $stmtAll->close();
    $summary['all_devices_users'] = (int)($rowAll['c'] ?? 0);
  }
} catch (mysqli_sql_exception $e) {
}

// count
$sqlCount = "SELECT COUNT(*) FROM soal_user su JOIN users u ON u.id=su.user_id $where";
$stmt = $mysqli->prepare($sqlCount);
if ($where !== '') $stmt->bind_param($types, ...$params);
$stmt->execute();
$stmt->bind_result($totalRows);
$stmt->fetch();
$stmt->close();
$totalRows = (int)$totalRows;
$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) { $page = $totalPages; $offset = ($page - 1) * $perPage; }

$sql = "
  SELECT su.id, su.title, su.question_count, su.token_input, su.token_output,
         su.token_input_price, su.token_output_price, su.currency, su.currency_rate_to_idr, su.model,
         su.created_at, u.username
  FROM soal_user su
  JOIN users u ON u.id = su.user_id
  $where
  ORDER BY su.id DESC
  LIMIT ? OFFSET ?
";
$stmt = $mysqli->prepare($sql);
if ($where !== '') {
  $types2 = $types . 'ii';
  $stmt->bind_param($types2, ...array_merge($params, [$perPage, $offset]));
} else {
  $stmt->bind_param('ii', $perPage, $offset);
}
$stmt->execute();
$res = $stmt->get_result();
$rows = [];
while ($r = $res->fetch_assoc()) $rows[] = $r;
$stmt->close();
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Riwayat Token</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght@300..700" rel="stylesheet" />
  <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
</head>
<body class="bg-gray-50">
  <div class="max-w-none mx-auto p-6">
    <div class="flex items-center justify-between mb-6">
      <div>
        <div class="text-2xl font-bold">Riwayat Token</div>
        <div class="text-sm text-gray-600">Menampilkan konsumsi token untuk Paket Soal, Modul Ajar, dan RPP</div>
      </div>
      <a href="index.php" class="inline-flex items-center gap-2 h-10 px-4 rounded-lg border bg-white hover:bg-gray-50">
        <span class="material-symbols-outlined text-[18px]">arrow_back</span>
        Kembali
      </a>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-7 gap-3 mb-5">
      <div class="bg-white rounded-xl border shadow-sm p-4">
        <div class="text-xs text-gray-500">User Aktif</div>
        <div class="text-2xl font-bold mt-1"><?php echo (int)$summary['active_users']; ?></div>
        <div class="text-xs text-gray-500 mt-1">User yang membuat minimal 1 output</div>
      </div>
      <div class="bg-white rounded-xl border shadow-sm p-4">
        <div class="text-xs text-gray-500">User Semua Perangkat</div>
        <div class="text-2xl font-bold mt-1"><?php echo (int)$summary['all_devices_users']; ?></div>
        <div class="text-xs text-gray-500 mt-1">Paket + Modul + RPP</div>
      </div>
      <div class="bg-white rounded-xl border shadow-sm p-4">
        <div class="text-xs text-gray-500">Paket Soal</div>
        <div class="text-2xl font-bold mt-1"><?php echo (int)$summary['paket_soal']; ?></div>
      </div>
      <div class="bg-white rounded-xl border shadow-sm p-4">
        <div class="text-xs text-gray-500">Modul Ajar</div>
        <div class="text-2xl font-bold mt-1"><?php echo (int)$summary['modul_ajar']; ?></div>
      </div>
      <div class="bg-white rounded-xl border shadow-sm p-4">
        <div class="text-xs text-gray-500">RPP</div>
        <div class="text-2xl font-bold mt-1"><?php echo (int)$summary['rpp']; ?></div>
      </div>
      <div class="bg-white rounded-xl border shadow-sm p-4">
        <div class="text-xs text-gray-500">Total Token Input</div>
        <div class="text-2xl font-bold mt-1"><?php echo number_format((int)$summary['token_input_total'], 0, ',', '.'); ?></div>
      </div>
      <div class="bg-white rounded-xl border shadow-sm p-4">
        <div class="text-xs text-gray-500">Total Token Output</div>
        <div class="text-2xl font-bold mt-1"><?php echo number_format((int)$summary['token_output_total'], 0, ',', '.'); ?></div>
      </div>
    </div>

    <div class="bg-white rounded-xl border shadow-sm p-4 mb-5">
      <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-3">
        <form method="get" class="flex flex-col md:flex-row md:items-end gap-3">
          <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1">Cari</label>
            <input type="text" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="Judul / user..." class="rounded-lg border h-10 px-3 w-full md:w-80" />
          </div>
          <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1">Dari</label>
            <input type="date" name="from" value="<?php echo htmlspecialchars($from); ?>" class="rounded-lg border h-10 px-3" />
          </div>
          <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1">Sampai</label>
            <input type="date" name="to" value="<?php echo htmlspecialchars($to); ?>" class="rounded-lg border h-10 px-3" />
          </div>
          <div class="flex items-center gap-2">
            <button class="h-10 px-4 rounded-lg border bg-white hover:bg-gray-50 font-semibold">Terapkan</button>
            <a href="admin_soal_history.php" class="h-10 px-4 rounded-lg border bg-white hover:bg-gray-50 font-semibold inline-flex items-center">Reset</a>
          </div>
        </form>
        <div class="text-right">
          <div class="text-xs text-gray-500">Total Biaya (Rp)</div>
          <div class="text-2xl font-bold"><?php echo 'Rp' . number_format((float)$summary['total_cost_idr'], 0, ',', '.'); ?></div>
        </div>
      </div>
    </div>

    <div class="bg-white rounded-xl border shadow-sm overflow-auto">
      <table class="min-w-full text-sm border whitespace-nowrap">
        <thead class="bg-gray-100">
          <tr>
            <th class="border px-3 py-2 text-left">No</th>
            <th class="border px-3 py-2 text-left">User</th>
            <th class="border px-3 py-2 text-left">Judul</th>
            <th class="border px-3 py-2 text-right">Jumlah Soal</th>
            <th class="border px-3 py-2 text-right">Token Input</th>
            <th class="border px-3 py-2 text-right">Token Output</th>
            <th class="border px-3 py-2 text-right">Biaya (Rp)</th>
            <th class="border px-3 py-2 text-left">Dibuat</th>
          </tr>
        </thead>
        <tbody>
          <?php
          if (empty($rows)) {
            echo '<tr><td colspan="8" class="border px-3 py-6 text-center text-gray-500">Belum ada data.</td></tr>';
          } else {
            foreach ($rows as $i => $r) {
              $no = $offset + $i + 1;
              $inTok = (int)$r['token_input'];
              $outTok = (int)$r['token_output'];
              $inPrice = (float)($r['token_input_price'] ?? 0);
              $outPrice = (float)($r['token_output_price'] ?? 0);
              $fx = (float)($r['currency_rate_to_idr'] ?? 0);
              // Fallback harga dari api_models jika belum tersimpan di soal_user
              if (($inPrice <= 0 && $outPrice <= 0) || $fx <= 0) {
                $model = (string)($r['model'] ?? '');
                if ($model !== '') {
                  if (!isset($modelCfgCache[$model])) {
                    $modelCfgCache[$model] = get_model_config($mysqli, $model);
                  }
                  $cfg = $modelCfgCache[$model] ?: null;
                  if ($cfg) {
                    if ($inPrice <= 0) $inPrice = (float)($cfg['token_input_price'] ?? 0);
                    if ($outPrice <= 0) $outPrice = (float)($cfg['token_output_price'] ?? 0);
                    if ($fx <= 0) $fx = (float)($cfg['currency_rate_to_idr'] ?? 1);
                  }
                }
              }
              if ($fx <= 0) $fx = 1;
              $costBase = ($inTok / 1000.0) * $inPrice + ($outTok / 1000.0) * $outPrice;
              $costIdr = $costBase * ($fx > 0 ? $fx : 1);
              $costFmt = 'Rp' . number_format($costIdr, 0, ',', '.');
              echo '<tr>';
              echo '<td class="border px-3 py-2">'. $no .'</td>';
              echo '<td class="border px-3 py-2">'. htmlspecialchars($r['username']) .'</td>';
              $title = (string)$r['title'];
              $isNonSoal = (stripos($title, 'lkpd') !== false) || (stripos($title, 'modul') !== false);
              echo '<td class="border px-3 py-2">'. htmlspecialchars($title) .'</td>';
              echo '<td class="border px-3 py-2 text-right">'. ($isNonSoal ? '' : (int)$r['question_count']) .'</td>';
              echo '<td class="border px-3 py-2 text-right">'. (int)$r['token_input'] .'</td>';
              echo '<td class="border px-3 py-2 text-right">'. (int)$r['token_output'] .'</td>';
              echo '<td class="border px-3 py-2 text-right">'. $costFmt .'</td>';
              echo '<td class="border px-3 py-2">'. htmlspecialchars($r['created_at']) .'</td>';
              echo '</tr>';
            }
          }
          ?>
        </tbody>
      </table>
      <div class="flex items-center justify-between px-3 py-2 border-t">
        <div class="text-xs text-gray-600">
          <?php
            $from = $totalRows ? $offset + 1 : 0;
            $to = min($totalRows, $offset + count($rows));
            echo 'Menampilkan ' . $from . ' - ' . $to . ' dari ' . $totalRows;
          ?>
        </div>
        <div class="flex items-center gap-2">
          <?php if ($page > 1): ?>
            <a class="h-8 px-3 rounded-lg border bg-white hover:bg-gray-50 text-xs font-semibold" href="?<?php echo http_build_query(['q'=>$q,'from'=>$from,'to'=>$to,'page'=>$page-1]); ?>">Sebelumnya</a>
          <?php else: ?>
            <span class="h-8 px-3 rounded-lg border bg-gray-100 text-xs font-semibold text-gray-400 inline-flex items-center">Sebelumnya</span>
          <?php endif; ?>
          <span class="text-xs">Halaman <?php echo $totalRows ? $page : 0; ?>/<?php echo $totalRows ? $totalPages : 0; ?></span>
          <?php if ($page < $totalPages): ?>
            <a class="h-8 px-3 rounded-lg border bg-white hover:bg-gray-50 text-xs font-semibold" href="?<?php echo http_build_query(['q'=>$q,'from'=>$from,'to'=>$to,'page'=>$page+1]); ?>">Berikutnya</a>
          <?php else: ?>
            <span class="h-8 px-3 rounded-lg border bg-gray-100 text-xs font-semibold text-gray-400 inline-flex items-center">Berikutnya</span>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</body>
</html>
