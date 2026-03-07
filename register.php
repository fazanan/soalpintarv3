<?php
session_start();
require __DIR__ . '/db.php';
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $u = isset($_POST['username']) ? trim($_POST['username']) : '';
  $p = isset($_POST['password']) ? $_POST['password'] : '';
  if ($u !== '' && $p !== '') {
    $hash = password_hash($p, PASSWORD_BCRYPT);
    $stmt = $mysqli->prepare('INSERT INTO users (username, password, limitpaket) VALUES (?, ?, 300)');
    $stmt->bind_param('ss', $u, $hash);
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
  <title>Daftar | SoalPintar</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@400;600&display=swap" rel="stylesheet" />
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen flex items-center justify-center bg-slate-100 font-[Lexend]">
  <div class="w-full max-w-sm bg-white rounded-2xl shadow p-6">
    <div class="text-center mb-6">
      <div class="text-2xl font-extrabold"><span class="text-sky-600">Soal</span>Pintar</div>
      <div class="text-xs text-slate-500">Buat akun</div>
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
    <div class="text-xs text-center mt-4">
      Sudah punya akun? <a href="login.php" class="text-sky-600">Login</a>
    </div>
  </div>
</body>
</html>
