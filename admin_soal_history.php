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
$perPage = 20;
$offset = ($page - 1) * $perPage;

$q = trim((string)($_GET['q'] ?? ''));
$where = '';
$params = [];
$types = '';
if ($q !== '') {
  $where = "WHERE (su.title LIKE CONCAT('%', ?, '%') OR u.username LIKE CONCAT('%', ?, '%'))";
  $params[] = $q;
  $params[] = $q;
  $types .= 'ss';
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
  <div class="max-w-6xl mx-auto p-6">
    <div class="flex items-center justify-between mb-6">
      <div>
        <div class="text-2xl font-bold">Riwayat Token</div>
        <div class="text-sm text-gray-600">Menampilkan konsumsi token untuk Soal, LKPD, dan Modul Ajar</div>
      </div>
      <a href="index.php" class="inline-flex items-center gap-2 h-10 px-4 rounded-lg border bg-white hover:bg-gray-50">
        <span class="material-symbols-outlined text-[18px]">arrow_back</span>
        Kembali
      </a>
    </div>

    <form method="get" class="flex items-center gap-2 mb-4">
      <input type="text" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="Cari judul / user..." class="rounded-lg border h-10 px-3 w-72" />
      <button class="h-10 px-4 rounded-lg border bg-white hover:bg-gray-50 font-semibold">Cari</button>
    </form>

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
            <a class="h-8 px-3 rounded-lg border bg-white hover:bg-gray-50 text-xs font-semibold" href="?<?php echo http_build_query(['q'=>$q,'page'=>$page-1]); ?>">Sebelumnya</a>
          <?php else: ?>
            <span class="h-8 px-3 rounded-lg border bg-gray-100 text-xs font-semibold text-gray-400 inline-flex items-center">Sebelumnya</span>
          <?php endif; ?>
          <span class="text-xs">Halaman <?php echo $totalRows ? $page : 0; ?>/<?php echo $totalRows ? $totalPages : 0; ?></span>
          <?php if ($page < $totalPages): ?>
            <a class="h-8 px-3 rounded-lg border bg-white hover:bg-gray-50 text-xs font-semibold" href="?<?php echo http_build_query(['q'=>$q,'page'=>$page+1]); ?>">Berikutnya</a>
          <?php else: ?>
            <span class="h-8 px-3 rounded-lg border bg-gray-100 text-xs font-semibold text-gray-400 inline-flex items-center">Berikutnya</span>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</body>
</html>
