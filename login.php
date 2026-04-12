<?php
session_start(['read_and_close' => true]);
if (isset($_SESSION['user_id'])) {
  header('Location: index.php');
  exit;
}
$err = isset($_GET['e']) ? 'Login gagal' : '';
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Login | GuruPintar</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@400;600&display=swap" rel="stylesheet" />
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen flex items-center justify-center bg-slate-100 font-[Lexend]">
  <div class="w-full max-w-sm bg-white rounded-2xl shadow p-6">
    <div class="text-center mb-6">
      <div class="text-2xl font-extrabold"><span class="text-sky-600">Guru</span>Pintar</div>
      <div class="text-xs text-slate-500">Sahabat Pendidik Indonesia</div>
    </div>
    <?php if ($err): ?>
      <div class="mb-4 text-sm text-red-600 bg-red-50 border border-red-200 rounded p-3"><?=$err?></div>
    <?php endif; ?>
    <form method="post" action="login_handle.php" class="space-y-4">
      <div>
        <label class="block text-sm font-medium mb-1">Email / No HP</label>
        <input name="username" required placeholder="contoh: guru@email.com atau 08xxxx / 62xxxx" class="w-full border rounded-lg px-3 py-2 outline-none focus:ring-2 focus:ring-sky-500" />
      </div>
      <div>
        <label class="block text-sm font-medium mb-1">Password</label>
        <input type="password" name="password" required class="w-full border rounded-lg px-3 py-2 outline-none focus:ring-2 focus:ring-sky-500" />
      </div>
      <button class="w-full bg-sky-600 hover:bg-sky-700 text-white font-semibold rounded-lg py-2">Masuk</button>
    </form>
  </div>
</body>
</html>
