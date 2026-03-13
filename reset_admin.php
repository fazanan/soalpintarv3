<?php
require __DIR__ . '/db.php';
$remote = $_SERVER['REMOTE_ADDR'] ?? '';
$cli = PHP_SAPI === 'cli';
$host = $_SERVER['HTTP_HOST'] ?? '';
$local = $cli || $remote === '127.0.0.1' || $remote === '::1';
$allowDomain = in_array($host, ['pinterin.my.id','www.pinterin.my.id'], true);
$needToken = !$local && $allowDomain;
$tokenOk = true;
if ($needToken) {
  $expected = getenv('ADMIN_RESET_TOKEN') ?: '';
  $provided = $_POST['token'] ?? $_GET['token'] ?? '';
  $tokenOk = ($expected !== '') && hash_equals($expected, $provided);
}
if (!$local && !($allowDomain && $tokenOk)) {
  http_response_code(403);
  echo 'Forbidden';
  exit;
}
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$message = '';
$error = '';
function g($k,$d=''){return isset($_POST[$k])?trim((string)$_POST[$k]):$d;}
if ($method === 'POST') {
  $u = g('username');
  $p = g('password');
  if ($u === '' || $p === '') {
    $error = 'Username dan password wajib diisi.';
  } elseif (strlen($u) > 100) {
    $error = 'Username terlalu panjang.';
  } elseif (strlen($p) < 6) {
    $error = 'Minimal 6 karakter.';
  } else {
    $stmt = $mysqli->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
    $stmt->bind_param('s', $u);
    $stmt->execute();
    $stmt->bind_result($id);
    $exists = $stmt->fetch();
    $stmt->close();
    $hash = password_hash($p, PASSWORD_BCRYPT);
    if ($exists) {
      $stmt = $mysqli->prepare('UPDATE users SET password = ?, role = "admin" WHERE id = ?');
      $stmt->bind_param('si', $hash, $id);
      if ($stmt->execute()) $message = 'Password diperbarui dan role diset ke admin.';
      else $error = 'Gagal memperbarui.';
      $stmt->close();
    } else {
      $role = 'admin';
      $lp = 300;
      $lg = 5;
      $stmt = $mysqli->prepare('INSERT INTO users (username, password, role, limitpaket, limitgambar) VALUES (?, ?, ?, ?, ?)');
      if ($stmt) {
        $stmt->bind_param('sssii', $u, $hash, $role, $lp, $lg);
        if ($stmt->execute()) $message = 'Admin baru dibuat.';
        else $error = 'Gagal membuat pengguna.';
        $stmt->close();
      } else {
        $error = 'Gagal menyiapkan query.';
      }
    }
  }
}
?><!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Reset Admin</title>
  <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
</head>
<body class="bg-gray-50 min-h-dvh">
  <div class="max-w-sm mx-auto p-6">
    <div class="bg-white rounded-xl border shadow p-6 space-y-4">
      <div class="text-xl font-semibold">Reset Password Admin</div>
      <?php if ($message): ?>
        <div class="p-3 rounded border border-green-200 bg-green-50 text-green-800 text-sm"><?php echo htmlspecialchars($message); ?></div>
      <?php endif; ?>
      <?php if ($error): ?>
        <div class="p-3 rounded border border-red-200 bg-red-50 text-red-800 text-sm"><?php echo htmlspecialchars($error); ?></div>
      <?php endif; ?>
      <form method="post" class="space-y-3">
        <?php if ($needToken): ?>
        <div>
          <label class="text-sm font-medium">Token</label>
          <input name="token" required class="w-full h-10 rounded border px-3" placeholder="Token">
        </div>
        <?php endif; ?>
        <div>
          <label class="text-sm font-medium">Username Admin</label>
          <input name="username" required class="w-full h-10 rounded border px-3" placeholder="admin">
        </div>
        <div>
          <label class="text-sm font-medium">Password Baru</label>
          <input type="password" name="password" required class="w-full h-10 rounded border px-3" placeholder="Minimal 6 karakter">
        </div>
        <button class="w-full h-10 rounded bg-blue-600 hover:bg-blue-700 text-white font-semibold">Simpan</button>
      </form>
      <div class="text-xs text-gray-600"><?php echo $local ? 'Hanya bisa diakses dari localhost.' : 'Akses domain memerlukan token.'; ?></div>
    </div>
  </div>
</body>
</html>
