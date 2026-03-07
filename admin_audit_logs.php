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

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 30;
$offset = ($page - 1) * $perPage;
$level = trim((string)($_GET['level'] ?? ''));
$q = trim((string)($_GET['q'] ?? ''));
$where = [];
$params = [];
$types = '';
if ($level !== '' && in_array($level, ['error','warn','info'], true)) {
  $where[] = "level = ?";
  $params[] = $level;
  $types .= 's';
}
if ($q !== '') {
  $where[] = "(category LIKE CONCAT('%', ?, '%') OR message LIKE CONCAT('%', ?, '%'))";
  $params[] = $q;
  $params[] = $q;
  $types .= 'ss';
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$stmt = $mysqli->prepare("SELECT COUNT(*) FROM audit_logs $whereSql");
if ($types !== '') $stmt->bind_param($types, ...$params);
$stmt->execute();
$stmt->bind_result($totalRows);
$stmt->fetch();
$stmt->close();
$totalRows = (int)$totalRows;
$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) { $page = $totalPages; $offset = ($page - 1) * $perPage; }

$sql = "SELECT al.id, al.created_at, al.level, al.category, al.message, al.http_status, al.endpoint, al.ip_address, u.username
          FROM audit_logs al
          LEFT JOIN users u ON u.id = al.user_id
          $whereSql
          ORDER BY al.id DESC
          LIMIT ? OFFSET ?";
$stmt = $mysqli->prepare($sql);
if ($types !== '') {
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
  <title>Audit Log (Admin)</title>
  <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
</head>
<body class="bg-gray-50">
  <div class="max-w-7xl mx-auto p-6">
    <div class="flex items-center justify-between mb-6">
      <div>
        <div class="text-2xl font-bold">Audit Log</div>
        <div class="text-sm text-gray-600">Catatan error, peringatan, dan informasi aplikasi</div>
      </div>
      <a href="index.php" class="px-4 h-10 rounded-lg border bg-white hover:bg-gray-50 inline-flex items-center">Kembali</a>
    </div>

    <form method="get" class="flex items-center gap-2 mb-4">
      <select name="level" class="h-10 rounded-lg border px-3">
        <option value="">Semua Level</option>
        <option value="error" <?php echo $level==='error'?'selected':''; ?>>error</option>
        <option value="warn" <?php echo $level==='warn'?'selected':''; ?>>warn</option>
        <option value="info" <?php echo $level==='info'?'selected':''; ?>>info</option>
      </select>
      <input class="h-10 rounded-lg border px-3 w-80" type="text" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="Cari kategori/pesan..." />
      <button class="h-10 px-4 rounded-lg border bg-white hover:bg-gray-50 font-semibold">Filter</button>
    </form>

    <div class="bg-white rounded-xl border shadow-sm overflow-auto">
      <table class="min-w-full text-sm border whitespace-nowrap">
        <thead class="bg-gray-100">
          <tr>
            <th class="border px-3 py-2 text-left">Waktu</th>
            <th class="border px-3 py-2 text-left">Level</th>
            <th class="border px-3 py-2 text-left">Kategori</th>
            <th class="border px-3 py-2 text-left">User</th>
            <th class="border px-3 py-2 text-left">Pesan</th>
            <th class="border px-3 py-2 text-left">Status</th>
            <th class="border px-3 py-2 text-left">Endpoint</th>
            <th class="border px-3 py-2 text-left">IP</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($rows)): ?>
            <tr><td colspan="8" class="border px-3 py-6 text-center text-gray-500">Belum ada log.</td></tr>
          <?php else: foreach ($rows as $r):
            $msg = mb_strimwidth((string)$r['message'], 0, 140, '…', 'UTF-8');
          ?>
            <tr>
              <td class="border px-3 py-2"><?php echo htmlspecialchars($r['created_at']); ?></td>
              <td class="border px-3 py-2"><?php echo htmlspecialchars($r['level']); ?></td>
              <td class="border px-3 py-2"><?php echo htmlspecialchars($r['category']); ?></td>
              <td class="border px-3 py-2"><?php echo htmlspecialchars($r['username'] ?? '-'); ?></td>
              <td class="border px-3 py-2"><?php echo htmlspecialchars($msg); ?></td>
              <td class="border px-3 py-2"><?php echo $r['http_status'] !== null ? (int)$r['http_status'] : '-'; ?></td>
              <td class="border px-3 py-2"><?php echo htmlspecialchars($r['endpoint'] ?? ''); ?></td>
              <td class="border px-3 py-2"><?php echo htmlspecialchars($r['ip_address'] ?? ''); ?></td>
            </tr>
          <?php endforeach; endif; ?>
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
            <a class="h-8 px-3 rounded-lg border bg-white hover:bg-gray-50 text-xs font-semibold" href="?<?php echo http_build_query(['level'=>$level,'q'=>$q,'page'=>$page-1]); ?>">Sebelumnya</a>
          <?php else: ?>
            <span class="h-8 px-3 rounded-lg border bg-gray-100 text-xs font-semibold text-gray-400 inline-flex items-center">Sebelumnya</span>
          <?php endif; ?>
          <span class="text-xs">Halaman <?php echo $totalRows ? $page : 0; ?>/<?php echo $totalRows ? $totalPages : 0; ?></span>
          <?php if ($page < $totalPages): ?>
            <a class="h-8 px-3 rounded-lg border bg-white hover:bg-gray-50 text-xs font-semibold" href="?<?php echo http_build_query(['level'=>$level,'q'=>$q,'page'=>$page+1]); ?>">Berikutnya</a>
          <?php else: ?>
            <span class="h-8 px-3 rounded-lg border bg-gray-100 text-xs font-semibold text-gray-400 inline-flex items-center">Berikutnya</span>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</body>
</html>
