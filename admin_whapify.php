<?php
session_start();
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? 'user') !== 'admin') {
  header('Location: login.php');
  exit;
}
require_once __DIR__ . '/db.php';

$message = '';
$error = '';

function post($k, $default = '') {
  return isset($_POST[$k]) ? trim((string)$_POST[$k]) : $default;
}

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

$mysqli->query("CREATE TABLE IF NOT EXISTS whapify_settings (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  endpoint_url VARCHAR(255) NOT NULL DEFAULT 'https://whapify.id/api/send/whatsapp',
  secret TEXT NOT NULL,
  account VARCHAR(128) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

if (!table_exists($mysqli, 'whapify_settings')) {
  http_response_code(500);
  echo "DB table whapify_settings belum tersedia.";
  exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  $action = post('action');
  if ($action === 'save') {
    $endpoint = post('endpoint_url', 'https://whapify.id/api/send/whatsapp');
    $secret = post('secret');
    $account = post('account');
    $active = (int)(post('is_active', '1') === '1');
    if ($endpoint === '') $endpoint = 'https://whapify.id/api/send/whatsapp';
    if ($secret === '' || $account === '') {
      $error = 'Secret dan Account wajib diisi.';
    } else {
      if ($active) $mysqli->query("UPDATE whapify_settings SET is_active=0");
      $stmt = $mysqli->prepare("INSERT INTO whapify_settings (endpoint_url, secret, account, is_active) VALUES (?,?,?,?)");
      if (!$stmt) {
        $error = 'Gagal menyiapkan query.';
      } else {
        $stmt->bind_param('sssi', $endpoint, $secret, $account, $active);
        if ($stmt->execute()) $message = 'Konfigurasi Whapify tersimpan.';
        else $error = 'Gagal menyimpan konfigurasi.';
        $stmt->close();
      }
    }
  } elseif ($action === 'toggle') {
    $id = (int)post('id', '0');
    $to = (int)post('to', '0');
    if ($id > 0) {
      if ($to === 1) $mysqli->query("UPDATE whapify_settings SET is_active=0");
      $stmt = $mysqli->prepare("UPDATE whapify_settings SET is_active=? WHERE id=?");
      if ($stmt) {
        $stmt->bind_param('ii', $to, $id);
        if ($stmt->execute()) $message = 'Status aktif diperbarui.';
        else $error = 'Gagal mengubah status.';
        $stmt->close();
      } else {
        $error = 'Gagal menyiapkan query.';
      }
    }
  } elseif ($action === 'delete') {
    $id = (int)post('id', '0');
    if ($id > 0) {
      $stmt = $mysqli->prepare("DELETE FROM whapify_settings WHERE id=?");
      if ($stmt) {
        $stmt->bind_param('i', $id);
        if ($stmt->execute()) $message = 'Konfigurasi dihapus.';
        else $error = 'Gagal menghapus konfigurasi.';
        $stmt->close();
      } else {
        $error = 'Gagal menyiapkan query.';
      }
    }
  }
}

$activeRow = null;
$res = $mysqli->query("SELECT id, endpoint_url, account, is_active, created_at, updated_at FROM whapify_settings WHERE is_active=1 ORDER BY id DESC LIMIT 1");
if ($res) {
  $activeRow = $res->fetch_assoc() ?: null;
  $res->close();
}

$rows = [];
$res = $mysqli->query("SELECT id, endpoint_url, account, LEFT(secret, 4) AS secret_preview, is_active, created_at, updated_at FROM whapify_settings ORDER BY id DESC LIMIT 30");
if ($res) {
  while ($r = $res->fetch_assoc()) $rows[] = $r;
  $res->close();
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Pengaturan Whapify</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@400;600&display=swap" rel="stylesheet">
  <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
  <style>body{font-family:"Lexend",system-ui,sans-serif}</style>
</head>
<body class="bg-gray-50 min-h-dvh">
  <div class="max-w-5xl mx-auto p-6">
    <div class="flex items-center justify-between mb-6">
      <h1 class="text-2xl font-semibold">Pengaturan Whapify (WhatsApp)</h1>
      <a href="index.php" class="px-4 py-2 rounded-lg border bg-white hover:bg-gray-50">Kembali ke Aplikasi</a>
    </div>

    <?php if ($message): ?>
      <div class="mb-4 p-3 rounded-lg bg-green-50 border border-green-200 text-green-800"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="mb-4 p-3 rounded-lg bg-red-50 border border-red-200 text-red-800"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
      <div class="bg-white rounded-xl border shadow-sm p-6">
        <div class="text-lg font-semibold mb-4">Konfigurasi Aktif</div>
        <?php if ($activeRow): ?>
          <div class="text-sm space-y-2">
            <div><span class="font-semibold">Endpoint:</span> <?php echo htmlspecialchars($activeRow['endpoint_url']); ?></div>
            <div><span class="font-semibold">Account:</span> <?php echo htmlspecialchars($activeRow['account']); ?></div>
            <div><span class="font-semibold">Updated:</span> <?php echo htmlspecialchars($activeRow['updated_at']); ?></div>
          </div>
        <?php else: ?>
          <div class="text-sm text-gray-600">Belum ada konfigurasi aktif.</div>
        <?php endif; ?>
      </div>

      <div class="bg-white rounded-xl border shadow-sm p-6">
        <div class="text-lg font-semibold mb-4">Tambah / Ganti Konfigurasi</div>
        <form method="post" class="space-y-4">
          <input type="hidden" name="action" value="save">
          <div>
            <label class="text-sm font-semibold mb-1 block">Endpoint URL</label>
            <input name="endpoint_url" class="w-full rounded-lg border h-11 px-3" value="https://whapify.id/api/send/whatsapp" />
          </div>
          <div>
            <label class="text-sm font-semibold mb-1 block">Secret</label>
            <input name="secret" type="password" class="w-full rounded-lg border h-11 px-3" placeholder="YOUR_API_SECRET" required />
          </div>
          <div>
            <label class="text-sm font-semibold mb-1 block">Account</label>
            <input name="account" class="w-full rounded-lg border h-11 px-3" placeholder="WHATSAPP_ACCOUNT_UNIQUE_ID" required />
          </div>
          <div class="flex items-center gap-2">
            <input id="isActive" name="is_active" type="checkbox" value="1" class="rounded border-gray-300" checked>
            <label for="isActive" class="text-sm">Aktifkan konfigurasi ini</label>
          </div>
          <div class="flex items-center justify-end">
            <button class="px-4 h-11 rounded-lg border bg-blue-600 text-white hover:bg-blue-700" type="submit">Simpan</button>
          </div>
        </form>
      </div>
    </div>

    <div class="bg-white rounded-xl border shadow-sm p-6 mt-6">
      <div class="text-lg font-semibold mb-4">Riwayat Konfigurasi</div>
      <div class="overflow-auto">
        <table class="min-w-full text-sm border whitespace-nowrap">
          <thead class="bg-gray-100">
            <tr>
              <th class="border px-3 py-2 text-left">ID</th>
              <th class="border px-3 py-2 text-left">Endpoint</th>
              <th class="border px-3 py-2 text-left">Account</th>
              <th class="border px-3 py-2 text-left">Secret</th>
              <th class="border px-3 py-2 text-left">Aktif</th>
              <th class="border px-3 py-2 text-left">Updated</th>
              <th class="border px-3 py-2">Aksi</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td class="border px-3 py-2"><?php echo (int)$r['id']; ?></td>
                <td class="border px-3 py-2 max-w-[420px] truncate"><?php echo htmlspecialchars($r['endpoint_url']); ?></td>
                <td class="border px-3 py-2"><?php echo htmlspecialchars($r['account']); ?></td>
                <td class="border px-3 py-2"><?php echo htmlspecialchars(($r['secret_preview'] ?? '') . '***'); ?></td>
                <td class="border px-3 py-2"><?php echo ((int)$r['is_active']) ? 'Ya' : 'Tidak'; ?></td>
                <td class="border px-3 py-2"><?php echo htmlspecialchars($r['updated_at']); ?></td>
                <td class="border px-3 py-2">
                  <div class="flex items-center gap-2">
                    <form method="post" onsubmit="return confirm('Ubah status aktif?')">
                      <input type="hidden" name="action" value="toggle">
                      <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                      <input type="hidden" name="to" value="<?php echo ((int)$r['is_active']) ? 0 : 1; ?>">
                      <button class="px-3 py-1 rounded border bg-white hover:bg-gray-50">
                        <?php echo ((int)$r['is_active']) ? 'Nonaktifkan' : 'Aktifkan'; ?>
                      </button>
                    </form>
                    <form method="post" onsubmit="return confirm('Hapus konfigurasi ini?')">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                      <button class="px-3 py-1 rounded border border-red-300 text-red-600 bg-white hover:bg-red-50">Hapus</button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (empty($rows)): ?>
              <tr><td colspan="7" class="border px-3 py-6 text-center text-gray-500">Belum ada konfigurasi.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</body>
</html>
