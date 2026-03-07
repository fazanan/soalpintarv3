<?php
session_start();
if (!isset($_SESSION['user_id'])) {
  header('Location: login.php');
  exit;
}
require_once __DIR__ . '/db.php';
$userId = (int)($_SESSION['user_id'] ?? 0);
$role = (string)($_SESSION['role'] ?? 'user');
$isAdmin = ($role === 'admin');

$message = '';
$error = '';

function post($k, $default = '') {
  return isset($_POST[$k]) ? trim((string)$_POST[$k]) : $default;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = post('action');
  if ($action === 'add') {
    $provider = strtolower(post('provider', 'openai'));
    $api_key = post('api_key', '');
    $scope   = post('scope', 'user'); // 'user' or 'global'
    $active  = isset($_POST['active']) ? 1 : 0;
    if ($provider === '' || $api_key === '') {
      $error = 'Provider dan API Key wajib diisi.';
    } else {
      if ($scope === 'global') {
        if (!$isAdmin) {
          $error = 'Hanya admin yang boleh mengelola API key global.';
        } else {
          if ($active) {
            $stmt = $mysqli->prepare("UPDATE api_keys SET is_active = 0 WHERE provider=? AND created_by IS NULL");
            $stmt->bind_param('s', $provider);
            $stmt->execute();
            $stmt->close();
          }
          $stmt = $mysqli->prepare("INSERT INTO api_keys (provider, api_key, is_active, created_by) VALUES (?, ?, ?, NULL)");
          $stmt->bind_param('ssi', $provider, $api_key, $active);
          if ($stmt->execute()) $message = 'API key global ditambahkan.';
          else $error = 'Gagal menambah API key global.';
          $stmt->close();
        }
      } else {
        if ($active) {
          $stmt = $mysqli->prepare("UPDATE api_keys SET is_active = 0 WHERE provider=? AND created_by=?");
          $stmt->bind_param('si', $provider, $userId);
          $stmt->execute();
          $stmt->close();
        }
        $stmt = $mysqli->prepare("INSERT INTO api_keys (provider, api_key, is_active, created_by) VALUES (?, ?, ?, ?)");
        $stmt->bind_param('ssii', $provider, $api_key, $active, $userId);
        if ($stmt->execute()) $message = 'API key ditambahkan.';
        else $error = 'Gagal menambah API key.';
        $stmt->close();
      }
    }
  }
  if ($action === 'toggle') {
    $id = (int)post('id', '0');
    $to = (int)post('to', '0');
    $stmt = $mysqli->prepare("SELECT created_by, provider FROM api_keys WHERE id=?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->bind_result($owner, $provider);
    if ($stmt->fetch()) {
      $stmt->close();
      if ($owner === null && !$isAdmin) {
        $error = 'Tidak berhak mengubah API key global.';
      } else if ($owner !== null && $owner != $userId && !$isAdmin) {
        $error = 'Tidak berhak mengubah API key milik user lain.';
      } else {
        if ($to == 1) {
          if ($owner === null) {
            $stmt = $mysqli->prepare("UPDATE api_keys SET is_active = 0 WHERE provider=? AND created_by IS NULL");
            $stmt->bind_param('s', $provider);
          } else {
            $stmt = $mysqli->prepare("UPDATE api_keys SET is_active = 0 WHERE provider=? AND created_by=?");
            $stmt->bind_param('si', $provider, $owner);
          }
          $stmt->execute();
          $stmt->close();
        }
        $stmt = $mysqli->prepare("UPDATE api_keys SET is_active=? WHERE id=?");
        $stmt->bind_param('ii', $to, $id);
        if ($stmt->execute()) $message = 'Status API key diperbarui.';
        else $error = 'Gagal memperbarui status.';
        $stmt->close();
      }
    } else {
      $stmt->close();
      $error = 'API key tidak ditemukan.';
    }
  }
  if ($action === 'delete') {
    $id = (int)post('id', '0');
    $stmt = $mysqli->prepare("SELECT created_by FROM api_keys WHERE id=?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->bind_result($owner);
    if ($stmt->fetch()) {
      $stmt->close();
      if ($owner === null && !$isAdmin) {
        $error = 'Tidak berhak menghapus API key global.';
      } else if ($owner !== null && $owner != $userId && !$isAdmin) {
        $error = 'Tidak berhak menghapus API key milik user lain.';
      } else {
        $stmt = $mysqli->prepare("DELETE FROM api_keys WHERE id=?");
        $stmt->bind_param('i', $id);
        if ($stmt->execute()) $message = 'API key dihapus.';
        else $error = 'Gagal menghapus API key.';
        $stmt->close();
      }
    } else {
      $stmt->close();
      $error = 'API key tidak ditemukan.';
    }
  }
}

$rows = [];
$q = $isAdmin
  ? "SELECT id, provider, LEFT(api_key, 6) AS preview, is_active, created_by, created_at FROM api_keys ORDER BY provider, created_by IS NULL DESC, created_at DESC"
  : "SELECT id, provider, LEFT(api_key, 6) AS preview, is_active, created_by, created_at FROM api_keys WHERE created_by IS NULL OR created_by = ? ORDER BY provider, created_by IS NULL DESC, created_at DESC";
if ($isAdmin) {
  $res = $mysqli->query($q);
} else {
  $stmt = $mysqli->prepare($q);
  $stmt->bind_param('i', $userId);
  $stmt->execute();
  $res = $stmt->get_result();
}
if ($res) {
  while ($r = $res->fetch_assoc()) $rows[] = $r;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Pengaturan API Key</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@400;600&display=swap" rel="stylesheet">
  <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
  <style>body{font-family:"Lexend",system-ui,sans-serif}</style>
</head>
<body class="bg-gray-50 min-h-dvh">
  <div class="max-w-4xl mx-auto p-6">
    <div class="flex items-center justify-between mb-6">
      <h1 class="text-2xl font-semibold">Pengaturan API Key</h1>
      <a href="index.php" class="px-4 py-2 rounded-lg border bg-white hover:bg-gray-50">Kembali ke Aplikasi</a>
    </div>

    <?php if ($message): ?>
      <div class="mb-4 p-3 rounded-lg bg-green-50 border border-green-200 text-green-800"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="mb-4 p-3 rounded-lg bg-red-50 border border-red-200 text-red-800"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <div class="bg-white rounded-xl border shadow-sm p-6 mb-8">
      <div class="text-lg font-semibold mb-4">Tambah API Key</div>
      <form method="post" class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <input type="hidden" name="action" value="add">
        <div>
          <label class="text-sm font-semibold mb-1 block">Provider</label>
          <select name="provider" class="w-full rounded-lg border h-11 px-3">
            <option value="openai">OpenAI</option>
          </select>
        </div>
        <div>
          <label class="text-sm font-semibold mb-1 block">Scope</label>
          <select name="scope" class="w-full rounded-lg border h-11 px-3" <?php echo $isAdmin ? '' : 'disabled'; ?>>
            <option value="user" selected>Pribadi (hanya Anda)</option>
            <option value="global" <?php echo $isAdmin ? '' : 'disabled'; ?>>Global (semua user)</option>
          </select>
          <?php if (!$isAdmin): ?>
            <input type="hidden" name="scope" value="user">
          <?php endif; ?>
        </div>
        <div class="md:col-span-2">
          <label class="text-sm font-semibold mb-1 block">API Key</label>
          <input name="api_key" class="w-full rounded-lg border h-11 px-3" placeholder="sk-..." required />
        </div>
        <div class="md:col-span-2 flex items-center justify-between">
          <label class="inline-flex items-center gap-2">
            <input type="checkbox" name="active" value="1" class="rounded">
            <span>Jadikan aktif (nonaktifkan yang lain pada scope yang sama)</span>
          </label>
          <button class="px-4 h-11 rounded-lg bg-blue-600 hover:bg-blue-700 text-white font-semibold">Simpan</button>
        </div>
      </form>
    </div>

    <div class="bg-white rounded-xl border shadow-sm p-6">
      <div class="text-lg font-semibold mb-4">Daftar API Key</div>
      <div class="overflow-x-auto">
        <table class="min-w-full text-sm border">
          <thead class="bg-gray-100">
            <tr>
              <th class="border px-3 py-2 text-left">Provider</th>
              <th class="border px-3 py-2 text-left">Preview</th>
              <th class="border px-3 py-2 text-left">Scope</th>
              <th class="border px-3 py-2 text-left">Aktif</th>
              <th class="border px-3 py-2 text-left">Dibuat</th>
              <th class="border px-3 py-2">Aksi</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td class="border px-3 py-2"><?php echo htmlspecialchars($r['provider']); ?></td>
                <td class="border px-3 py-2"><?php echo htmlspecialchars($r['preview']); ?>••••</td>
                <td class="border px-3 py-2">
                  <?php echo $r['created_by'] === null ? 'Global' : ($r['created_by'] == $userId ? 'Pribadi (Anda)' : 'User '.$r['created_by']); ?>
                </td>
                <td class="border px-3 py-2"><?php echo $r['is_active'] ? 'Ya' : 'Tidak'; ?></td>
                <td class="border px-3 py-2"><?php echo htmlspecialchars($r['created_at']); ?></td>
                <td class="border px-3 py-2">
                  <div class="flex items-center gap-2">
                    <form method="post" onsubmit="return confirm('Ubah status aktif?')">
                      <input type="hidden" name="action" value="toggle">
                      <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                      <input type="hidden" name="to" value="<?php echo $r['is_active'] ? 0 : 1; ?>">
                      <button class="px-3 py-1 rounded border bg-white hover:bg-gray-50">
                        <?php echo $r['is_active'] ? 'Nonaktifkan' : 'Aktifkan'; ?>
                      </button>
                    </form>
                    <form method="post" onsubmit="return confirm('Hapus API key ini?')">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                      <button class="px-3 py-1 rounded border border-red-300 text-red-600 bg-white hover:bg-red-50">Hapus</button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (empty($rows)): ?>
              <tr><td colspan="6" class="border px-3 py-6 text-center text-gray-500">Belum ada API key.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</body>
</html>
