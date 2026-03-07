<?php
session_start();
if (!isset($_SESSION['user_id'])) {
  header('Location: login.php');
  exit;
}
require_once __DIR__ . '/db.php';
$userId = (int)($_SESSION['user_id'] ?? 0);
$username = (string)($_SESSION['username'] ?? '');
$message = '';
$error = '';

function post($k, $default = '') {
  return isset($_POST[$k]) ? trim((string)$_POST[$k]) : $default;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $current = post('current_password');
  $new = post('new_password');
  if ($current === '' || $new === '') {
    $error = 'Password saat ini dan password baru wajib diisi.';
  } else {
    $stmt = $mysqli->prepare('SELECT password FROM users WHERE id=?');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->bind_result($hash);
    if ($stmt->fetch()) {
      $stmt->close();
      if (!password_verify($current, $hash)) {
        $error = 'Password saat ini salah.';
      } else {
        $newHash = password_hash($new, PASSWORD_BCRYPT);
        $stmt = $mysqli->prepare('UPDATE users SET password=? WHERE id=?');
        $stmt->bind_param('si', $newHash, $userId);
        if ($stmt->execute()) $message = 'Password berhasil diperbarui.';
        else $error = 'Gagal memperbarui password.';
        $stmt->close();
      }
    } else {
      $stmt->close();
      $error = 'Pengguna tidak ditemukan.';
    }
  }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Profil</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@400;600&display=swap" rel="stylesheet">
  <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
  <style>body{font-family:"Lexend",system-ui,sans-serif}</style>
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet" />
  <style>.material-symbols-outlined{font-variation-settings:"FILL"0,"wght"520,"GRAD"0,"opsz"24}</style>
  </head>
<body class="bg-gray-50 min-h-dvh">
  <div class="max-w-xl mx-auto p-6">
    <div class="flex items-center justify-between mb-6">
      <h1 class="text-2xl font-semibold">Profil</h1>
      <a href="index.php" class="px-4 py-2 rounded-lg border bg-white hover:bg-gray-50">Kembali</a>
    </div>

    <div class="bg-white rounded-xl border shadow-sm p-6 space-y-4">
      <div class="flex items-center gap-3">
        <div class="size-12 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center">
          <span class="material-symbols-outlined">account_circle</span>
        </div>
        <div>
          <div class="text-sm text-gray-500">Username</div>
          <div class="text-lg font-semibold"><?php echo htmlspecialchars($username); ?></div>
        </div>
      </div>

      <?php if ($message): ?>
        <div class="p-3 rounded-lg bg-green-50 border border-green-200 text-green-800"><?php echo htmlspecialchars($message); ?></div>
      <?php endif; ?>
      <?php if ($error): ?>
        <div class="p-3 rounded-lg bg-red-50 border border-red-200 text-red-800"><?php echo htmlspecialchars($error); ?></div>
      <?php endif; ?>

      <div class="pt-2">
        <div class="text-lg font-semibold mb-2">Ubah Password</div>
        <form method="post" class="grid grid-cols-1 gap-4">
          <div>
            <label class="text-sm font-semibold mb-1 block">Password Saat Ini</label>
            <input type="password" name="current_password" class="w-full rounded-lg border h-11 px-3" required>
          </div>
          <div>
            <label class="text-sm font-semibold mb-1 block">Password Baru</label>
            <input type="password" name="new_password" class="w-full rounded-lg border h-11 px-3" required>
          </div>
          <div class="flex items-center justify-end">
            <button class="px-4 h-11 rounded-lg bg-blue-600 hover:bg-blue-700 text-white font-semibold">Simpan Perubahan</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</body>
</html>
