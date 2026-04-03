<?php
session_start();
require __DIR__ . '/db.php';
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? 'user') !== 'admin') {
  header('Location: login.php');
  exit;
}
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $u = isset($_POST['username']) ? trim($_POST['username']) : '';
  $p = isset($_POST['password']) ? $_POST['password'] : '';
  if ($u !== '' && $p !== '') {
    // initial limit from settings table
    $init = 300;
    $mysqli->query("CREATE TABLE IF NOT EXISTS app_settings (`k` VARCHAR(64) PRIMARY KEY, `v` VARCHAR(255) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $res = $mysqli->query("SELECT v FROM app_settings WHERE k='initial_limit' LIMIT 1");
    if ($row=$res->fetch_assoc()) $init = (int)$row['v'];
    $res && $res->close();
    $hash = password_hash($p, PASSWORD_BCRYPT);
    $limitGambar = 0;
    $stmt = $mysqli->prepare('INSERT INTO users (username, password, limitpaket, limitgambar) VALUES (?, ?, ?, ?)');
    $stmt->bind_param('ssii', $u, $hash, $init, $limitGambar);
    if ($stmt->execute()) {
      $msg = 'Pendaftaran berhasil, silakan login';
    } else {
      $msg = 'Gagal mendaftar';
    }
  } else {
    $msg = 'Lengkapi data';
  }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Daftar Pengguna | GuruPintar</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@400;600&display=swap" rel="stylesheet" />
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen flex items-center justify-center bg-slate-100 font-[Lexend]">
  <div class="w-full max-w-sm bg-white rounded-2xl shadow p-6">
    <div class="text-center mb-6">
      <div class="text-2xl font-extrabold"><span class="text-sky-600">Guru</span>Pintar</div>
      <div class="text-xs text-slate-500">Buat akun (Admin)</div>
    </div>
    <?php if ($msg): ?>
      <div class="mb-4 text-sm text-slate-700 bg-slate-100 border border-slate-200 rounded p-3"><?=$msg?></div>
    <?php endif; ?>
    <form method="post" class="space-y-4">
      <div>
        <label class="block text-sm font-medium mb-1">Username</label>
        <input name="username" required class="w-full border rounded-lg px-3 py-2 outline-none focus:ring-2 focus:ring-sky-500" />
      </div>
      <div>
        <label class="block text-sm font-medium mb-1">Password</label>
        <input type="password" name="password" required class="w-full border rounded-lg px-3 py-2 outline-none focus:ring-2 focus:ring-sky-500" />
      </div>
      <button class="w-full bg-sky-600 hover:bg-sky-700 text-white font-semibold rounded-lg py-2">Daftar</button>
    </form>
    <div class="text-xs text-center mt-4"><a href="index.php" class="text-sky-600">Kembali</a></div>
  </div>
</body>
</html>
