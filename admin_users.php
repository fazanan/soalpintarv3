<?php
session_start();
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? 'user') !== 'admin') {
  header('Location: login.php');
  exit;
}
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth_lock.php';

$message = '';
$error = '';
$hasAccessCols = false;
$hasNoHpCol = false;
try {
  $ok1 = false;
  $ok2 = false;
  if ($rs = $mysqli->query("SHOW COLUMNS FROM users LIKE 'access_quiz'")) { $ok1 = $rs->num_rows > 0; $rs->close(); }
  if ($rs = $mysqli->query("SHOW COLUMNS FROM users LIKE 'access_rekap_nilai'")) { $ok2 = $rs->num_rows > 0; $rs->close(); }
  $hasAccessCols = $ok1 && $ok2;
  if ($rs = $mysqli->query("SHOW COLUMNS FROM users LIKE 'no_hp'")) { $hasNoHpCol = $rs->num_rows > 0; $rs->close(); }
} catch (mysqli_sql_exception $e) {
  $hasAccessCols = false;
  $hasNoHpCol = false;
}

function post($k, $default = '') {
  return isset($_POST[$k]) ? trim((string)$_POST[$k]) : $default;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = post('action');
  if ($action === 'create_user') {
    $username = post('username', '');
    $noHp = post('no_hp', '');
    $noHp = trim((string)preg_replace('/[^0-9+()\\-\\s]/', '', $noHp));
    $password = post('password', '');
    $role = post('role', 'user') === 'admin' ? 'admin' : 'user';
    $lpRaw = post('limitpaket', '');
    $lgRaw = post('limitgambar', '');
    $lp = $lpRaw === '' ? 300 : (int)max(0, (int)$lpRaw);
    $lg = $lgRaw === '' ? 0 : (int)max(0, (int)$lgRaw);
    $accessQuiz = isset($_POST['access_quiz']) ? 1 : 0;
    $accessRekap = isset($_POST['access_rekap_nilai']) ? 1 : 0;
    if ($role === 'admin') { $accessQuiz = 1; $accessRekap = 1; }

    if ($username === '' || $password === '') {
      $error = 'Username dan password wajib diisi.';
    } elseif (strlen($noHp) > 32) {
      $error = 'No HP terlalu panjang.';
    } elseif (strlen($username) > 100) {
      $error = 'Username terlalu panjang.';
    } else {
      $hash = password_hash($password, PASSWORD_BCRYPT);
      $stmt = null;
      try {
        if ($hasAccessCols) {
          $stmt = $hasNoHpCol
            ? $mysqli->prepare("INSERT INTO users (username, no_hp, password, role, access_quiz, access_rekap_nilai, limitpaket, limitgambar) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
            : $mysqli->prepare("INSERT INTO users (username, password, role, access_quiz, access_rekap_nilai, limitpaket, limitgambar) VALUES (?, ?, ?, ?, ?, ?, ?)");
        } else {
          $stmt = $hasNoHpCol
            ? $mysqli->prepare("INSERT INTO users (username, no_hp, password, role, limitpaket, limitgambar) VALUES (?, ?, ?, ?, ?, ?)")
            : $mysqli->prepare("INSERT INTO users (username, password, role, limitpaket, limitgambar) VALUES (?, ?, ?, ?, ?)");
        }
      } catch (mysqli_sql_exception $e) {
        $stmt = null;
      }
      if ($stmt) {
        if ($hasAccessCols) {
          if ($hasNoHpCol) $stmt->bind_param('ssssiiii', $username, $noHp, $hash, $role, $accessQuiz, $accessRekap, $lp, $lg);
          else $stmt->bind_param('sssiiii', $username, $hash, $role, $accessQuiz, $accessRekap, $lp, $lg);
        } else {
          if ($hasNoHpCol) $stmt->bind_param('ssssii', $username, $noHp, $hash, $role, $lp, $lg);
          else $stmt->bind_param('sssii', $username, $hash, $role, $lp, $lg);
        }
        if ($stmt->execute()) $message = 'Pengguna berhasil dibuat.';
        else {
          $code = (int)($stmt->errno ?: $mysqli->errno);
          if ($code === 1062) $error = 'Username sudah digunakan.';
          else $error = 'Gagal membuat pengguna.';
        }
        $stmt->close();
      } else {
        $error = 'Gagal menyiapkan pembuatan pengguna.';
      }
    }
  }
  if ($action === 'set_role') {
    $id = (int)post('id', '0');
    $role = post('role', 'user') === 'admin' ? 'admin' : 'user';
    $stmt = $mysqli->prepare("UPDATE users SET role=? WHERE id=?");
    $stmt->bind_param('si', $role, $id);
    if ($stmt->execute()) $message = 'Role pengguna diperbarui.';
    else $error = 'Gagal memperbarui role.';
    $stmt->close();
  }
  if ($action === 'update_limits') {
    $id = (int)post('id', '0');
    $noHp = post('no_hp', '');
    $noHp = trim((string)preg_replace('/[^0-9+()\\-\\s]/', '', $noHp));
    $lp = (int)max(0, (int)post('limitpaket', '0'));
    $lg = (int)max(0, (int)post('limitgambar', '0'));
    $accessQuiz = isset($_POST['access_quiz']) ? 1 : 0;
    $accessRekap = isset($_POST['access_rekap_nilai']) ? 1 : 0;
    if (strlen($noHp) > 32) {
      $error = 'No HP terlalu panjang.';
    } else {
    $stmt = null;
    try {
      if ($hasAccessCols) {
        $stmt = $hasNoHpCol
          ? $mysqli->prepare("UPDATE users SET no_hp=?, limitpaket=?, limitgambar=?, access_quiz=?, access_rekap_nilai=? WHERE id=?")
          : $mysqli->prepare("UPDATE users SET limitpaket=?, limitgambar=?, access_quiz=?, access_rekap_nilai=? WHERE id=?");
      } else {
        $stmt = $hasNoHpCol
          ? $mysqli->prepare("UPDATE users SET no_hp=?, limitpaket=?, limitgambar=? WHERE id=?")
          : $mysqli->prepare("UPDATE users SET limitpaket=?, limitgambar=? WHERE id=?");
      }
    } catch (mysqli_sql_exception $e) {
      $stmt = null;
    }
    if ($stmt) {
      if ($hasAccessCols) {
        if ($hasNoHpCol) $stmt->bind_param('siiiii', $noHp, $lp, $lg, $accessQuiz, $accessRekap, $id);
        else $stmt->bind_param('iiiii', $lp, $lg, $accessQuiz, $accessRekap, $id);
      } else {
        if ($hasNoHpCol) $stmt->bind_param('siii', $noHp, $lp, $lg, $id);
        else $stmt->bind_param('iii', $lp, $lg, $id);
      }
      if ($stmt->execute()) $message = 'Pengaturan pengguna diperbarui.';
      else $error = 'Gagal memperbarui pengaturan pengguna.';
      $stmt->close();
    } else {
      $error = 'Gagal menyiapkan pembaruan batas.';
    }
    }
  }
  if ($action === 'reset_password') {
    $id = (int)post('id', '0');
    $pwd = post('password', '');
    if ($pwd === '') {
      $error = 'Password baru wajib diisi.';
    } else {
      $hash = password_hash($pwd, PASSWORD_BCRYPT);
      $stmt = $mysqli->prepare("UPDATE users SET password=? WHERE id=?");
      $stmt->bind_param('si', $hash, $id);
      if ($stmt->execute()) $message = 'Password pengguna direset.';
      else $error = 'Gagal mereset password.';
      $stmt->close();
    }
  }
  if ($action === 'delete_user') {
    $id = (int)post('id', '0');
    if ($id === (int)($_SESSION['user_id'] ?? 0)) {
      $error = 'Tidak dapat menghapus akun sendiri.';
    } else {
      $stmt = $mysqli->prepare("DELETE FROM users WHERE id=?");
      $stmt->bind_param('i', $id);
      if ($stmt->execute()) $message = 'Pengguna dihapus.';
      else $error = 'Gagal menghapus pengguna.';
      $stmt->close();
    }
  }
  if ($action === 'force_logout') {
    $id = (int)post('id', '0');
    if ($id <= 0) {
      $error = 'ID pengguna tidak valid.';
    } else {
      auth_lock_release($id, null);
      $message = 'Pengguna berhasil dipaksa logout.';
    }
  }
}

$isAjax = ($_SERVER['REQUEST_METHOD'] === 'POST') && (isset($_GET['ajax']) && $_GET['ajax'] === '1');
if ($isAjax) {
  header('Content-Type: application/json');
  echo json_encode([
    'ok' => $error === '',
    'message' => $error ?: $message,
  ]);
  exit;
}

$users = [];
$perPage = 10;
$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $perPage;

// Hitung total data
$total = 0;
if ($q !== '') {
  $like = '%' . $q . '%';
  if (ctype_digit($q)) {
    $idQ = (int)$q;
    $stmtCnt = $mysqli->prepare("SELECT COUNT(*) AS c FROM users WHERE username LIKE ? OR id=?");
    $stmtCnt->bind_param('si', $like, $idQ);
  } else {
    $stmtCnt = $mysqli->prepare("SELECT COUNT(*) AS c FROM users WHERE username LIKE ?");
    $stmtCnt->bind_param('s', $like);
  }
  $stmtCnt->execute();
  $resCnt = $stmtCnt->get_result();
  $row = $resCnt ? $resCnt->fetch_assoc() : null;
  $total = (int)($row['c'] ?? 0);
  $stmtCnt->close();
} else {
  if ($rs = $mysqli->query("SELECT COUNT(*) AS c FROM users")) {
    $row = $rs->fetch_assoc();
    $total = (int)($row['c'] ?? 0);
    $rs->close();
  }
}
$totalPages = max(1, (int)ceil($total / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

// Ambil data per halaman
$stmt = null;
try {
  if ($q !== '') {
    $like = '%' . $q . '%';
    if (ctype_digit($q)) {
      $stmt = $hasAccessCols
        ? $mysqli->prepare($hasNoHpCol
            ? "SELECT id, username, no_hp, role, access_quiz, access_rekap_nilai, limitpaket, limitgambar, created_at FROM users WHERE username LIKE ? OR id=? ORDER BY id DESC LIMIT ? OFFSET ?"
            : "SELECT id, username, role, access_quiz, access_rekap_nilai, limitpaket, limitgambar, created_at FROM users WHERE username LIKE ? OR id=? ORDER BY id DESC LIMIT ? OFFSET ?")
        : $mysqli->prepare($hasNoHpCol
            ? "SELECT id, username, no_hp, role, limitpaket, limitgambar, created_at FROM users WHERE username LIKE ? OR id=? ORDER BY id DESC LIMIT ? OFFSET ?"
            : "SELECT id, username, role, limitpaket, limitgambar, created_at FROM users WHERE username LIKE ? OR id=? ORDER BY id DESC LIMIT ? OFFSET ?");
    } else {
      $stmt = $hasAccessCols
        ? $mysqli->prepare($hasNoHpCol
            ? "SELECT id, username, no_hp, role, access_quiz, access_rekap_nilai, limitpaket, limitgambar, created_at FROM users WHERE username LIKE ? ORDER BY id DESC LIMIT ? OFFSET ?"
            : "SELECT id, username, role, access_quiz, access_rekap_nilai, limitpaket, limitgambar, created_at FROM users WHERE username LIKE ? ORDER BY id DESC LIMIT ? OFFSET ?")
        : $mysqli->prepare($hasNoHpCol
            ? "SELECT id, username, no_hp, role, limitpaket, limitgambar, created_at FROM users WHERE username LIKE ? ORDER BY id DESC LIMIT ? OFFSET ?"
            : "SELECT id, username, role, limitpaket, limitgambar, created_at FROM users WHERE username LIKE ? ORDER BY id DESC LIMIT ? OFFSET ?");
    }
  } else {
    $stmt = $hasAccessCols
      ? $mysqli->prepare($hasNoHpCol
          ? "SELECT id, username, no_hp, role, access_quiz, access_rekap_nilai, limitpaket, limitgambar, created_at FROM users ORDER BY id DESC LIMIT ? OFFSET ?"
          : "SELECT id, username, role, access_quiz, access_rekap_nilai, limitpaket, limitgambar, created_at FROM users ORDER BY id DESC LIMIT ? OFFSET ?")
      : $mysqli->prepare($hasNoHpCol
          ? "SELECT id, username, no_hp, role, limitpaket, limitgambar, created_at FROM users ORDER BY id DESC LIMIT ? OFFSET ?"
          : "SELECT id, username, role, limitpaket, limitgambar, created_at FROM users ORDER BY id DESC LIMIT ? OFFSET ?");
  }
} catch (mysqli_sql_exception $e) {
  $stmt = null;
}
if ($stmt) {
  if ($q !== '') {
    $like = '%' . $q . '%';
    if (ctype_digit($q)) {
      $idQ = (int)$q;
      $stmt->bind_param('siii', $like, $idQ, $perPage, $offset);
    } else {
      $stmt->bind_param('sii', $like, $perPage, $offset);
    }
  } else {
    $stmt->bind_param('ii', $perPage, $offset);
  }
  $stmt->execute();
  $res = $stmt->get_result();
  while ($r = $res->fetch_assoc()) {
    if (!$hasAccessCols) {
      $r['access_quiz'] = 1;
      $r['access_rekap_nilai'] = 1;
    }
    if (!$hasNoHpCol) {
      $r['no_hp'] = '';
    }
    $users[] = $r;
  }
  $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Manajemen Pengguna</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@400;600&display=swap" rel="stylesheet">
  <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
  <style>body{font-family:"Lexend",system-ui,sans-serif}</style>
</head>
<body class="bg-gray-50 min-h-dvh">
  <div class="max-w-none mx-auto p-6">
    <div class="flex items-center justify-between mb-6">
      <h1 class="text-2xl font-semibold">Manajemen Pengguna</h1>
      <a href="index.php" class="px-4 py-2 rounded-lg border bg-white hover:bg-gray-50">Kembali ke Aplikasi</a>
    </div>

    <?php if ($message): ?>
      <div class="mb-4 p-3 rounded-lg bg-green-50 border border-green-200 text-green-800"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="mb-4 p-3 rounded-lg bg-red-50 border border-red-200 text-red-800"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <div class="mb-4 flex flex-col md:flex-row md:items-center md:justify-between gap-3">
      <form method="get" class="flex items-center gap-2">
        <input name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="Cari username atau ID..." class="w-full md:w-72 h-10 rounded-lg border bg-white px-3">
        <button class="px-4 h-10 rounded-lg border bg-white hover:bg-gray-50" type="submit">Cari</button>
        <?php if ($q !== ''): ?>
          <a class="px-4 h-10 inline-flex items-center rounded-lg border bg-white hover:bg-gray-50" href="admin_users.php">Reset</a>
        <?php endif; ?>
      </form>
      <button id="btnCreateUser" type="button" class="px-4 h-10 rounded-lg border bg-white hover:bg-gray-50">Tambah Pengguna</button>
    </div>

    <div class="bg-white rounded-xl border shadow-sm p-6">
      <div class="overflow-auto">
        <table class="min-w-full text-sm border whitespace-nowrap">
          <thead class="bg-gray-100">
            <tr>
              <th class="border px-3 py-2 text-left">ID</th>
              <th class="border px-3 py-2 text-left">Username</th>
              <th class="border px-3 py-2 text-left">No HP</th>
              <th class="border px-3 py-2 text-left">Role</th>
              <th class="border px-3 py-2 text-left">Akses Quiz</th>
              <th class="border px-3 py-2 text-left">Akses Rekap</th>
              <th class="border px-3 py-2 text-left">Limit Paket</th>
              <th class="border px-3 py-2 text-left">Limit Gambar</th>
              <th class="border px-3 py-2 text-left">Dibuat</th>
              <th class="border px-3 py-2">Aksi</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($users as $u): ?>
              <tr data-row="<?php echo (int)$u['id']; ?>" data-u="<?php echo htmlspecialchars($u['username']); ?>" data-hp="<?php echo htmlspecialchars($u['no_hp'] ?? ''); ?>" data-lp="<?php echo (int)($u['limitpaket'] ?? 0); ?>" data-lg="<?php echo (int)($u['limitgambar'] ?? 0); ?>" data-aq="<?php echo (int)($u['access_quiz'] ?? 1); ?>" data-ar="<?php echo (int)($u['access_rekap_nilai'] ?? 1); ?>">
                <td class="border px-3 py-2"><?php echo (int)$u['id']; ?></td>
                <td class="border px-3 py-2"><?php echo htmlspecialchars($u['username']); ?></td>
                <td class="border px-3 py-2"><?php echo htmlspecialchars($u['no_hp'] ?? ''); ?></td>
                <td class="border px-3 py-2"><?php echo htmlspecialchars($u['role'] ?: 'user'); ?></td>
                <td class="border px-3 py-2"><?php echo ((int)($u['access_quiz'] ?? 1) === 1) ? 'Aktif' : 'Nonaktif'; ?></td>
                <td class="border px-3 py-2"><?php echo ((int)($u['access_rekap_nilai'] ?? 1) === 1) ? 'Aktif' : 'Nonaktif'; ?></td>
                <td class="border px-3 py-2">
                  <span><?php echo (int)($u['limitpaket'] ?? 0); ?></span>
                </td>
                <td class="border px-3 py-2">
                  <span><?php echo (int)($u['limitgambar'] ?? 0); ?></span>
                </td>
                <td class="border px-3 py-2"><?php echo htmlspecialchars($u['created_at']); ?></td>
                <td class="border px-3 py-2">
                  <div class="flex flex-wrap items-center gap-2">
                    <button type="button" class="btn-row-edit inline-flex items-center justify-center w-9 h-9 rounded border bg-white hover:bg-gray-50" title="Edit batas" aria-label="Edit batas" data-id="<?php echo (int)$u['id']; ?>">✏️</button>
                    <button type="button" class="btn-row-role inline-flex items-center justify-center w-9 h-9 rounded border bg-white hover:bg-gray-50" title="Set role" aria-label="Set role" data-id="<?php echo (int)$u['id']; ?>">👤</button>
                    <button type="button" class="btn-row-resetpwd inline-flex items-center justify-center w-9 h-9 rounded border bg-white hover:bg-gray-50" title="Reset password" aria-label="Reset password" data-id="<?php echo (int)$u['id']; ?>">🔑</button>
                    <form method="post" class="inline-block" onsubmit="return confirm('Paksa logout pengguna ini?')">
                      <input type="hidden" name="action" value="force_logout">
                      <input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>">
                      <button class="inline-flex items-center justify-center w-9 h-9 rounded border border-amber-300 text-amber-700 bg-white hover:bg-amber-50" title="Force logout" aria-label="Force logout">🚪</button>
                    </form>
                    <?php if ($u['id'] != ($_SESSION['user_id'] ?? 0)): ?>
                      <form method="post" class="inline-block" onsubmit="return confirm('Hapus pengguna ini?')">
                        <input type="hidden" name="action" value="delete_user">
                        <input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>">
                        <button class="inline-flex items-center justify-center w-9 h-9 rounded border border-red-300 text-red-600 bg-white hover:bg-red-50" title="Hapus" aria-label="Hapus">🗑️</button>
                      </form>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (empty($users)): ?>
              <tr><td colspan="10" class="border px-3 py-6 text-center text-gray-500">Belum ada pengguna.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
      <div class="flex items-center justify-between mt-4 text-sm">
        <div class="text-gray-600">
          Menampilkan <?php echo $total ? ($offset + 1) : 0; ?>–<?php echo min($offset + $perPage, $total); ?> dari <?php echo $total; ?> pengguna
        </div>
        <div class="inline-flex items-center gap-1">
          <?php
            $base = strtok($_SERVER['REQUEST_URI'], '?');
            $link = function($p) use ($base, $q) {
              $p = max(1, (int)$p);
              $qs = 'page=' . $p;
              if ($q !== '') $qs .= '&q=' . rawurlencode($q);
              return htmlspecialchars($base . '?' . $qs);
            };
            $start = max(1, $page - 2);
            $end = min($totalPages, $page + 2);
          ?>
          <a href="<?php echo $link(1); ?>" class="px-2 py-1 rounded border bg-white hover:bg-gray-50">Awal</a>
          <a href="<?php echo $link($page - 1); ?>" class="px-2 py-1 rounded border bg-white hover:bg-gray-50">Sebelumnya</a>
          <?php for ($i = $start; $i <= $end; $i++): ?>
            <a href="<?php echo $link($i); ?>" class="px-2 py-1 rounded border <?php echo $i === $page ? 'bg-blue-600 text-white border-blue-600' : 'bg-white hover:bg-gray-50'; ?>"><?php echo $i; ?></a>
          <?php endfor; ?>
          <a href="<?php echo $link($page + 1); ?>" class="px-2 py-1 rounded border bg-white hover:bg-gray-50">Berikutnya</a>
          <a href="<?php echo $link($totalPages); ?>" class="px-2 py-1 rounded border bg-white hover:bg-gray-50">Akhir</a>
        </div>
      </div>
    </div>
  </div>
  <div id="editModal" class="fixed inset-0 bg-black/40 hidden items-center justify-center z-50">
    <div class="bg-white rounded-xl border shadow-lg w-full max-w-md p-6">
      <h2 class="text-lg font-semibold mb-4">Edit Pengguna</h2>
      <form id="modalForm" class="space-y-4">
        <input type="hidden" name="action" value="update_limits">
        <input type="hidden" name="id" id="modalUserId" value="">
        <div>
          <label class="block text-sm font-medium mb-1">Username</label>
          <input id="modalUsername" type="text" class="w-full rounded border h-10 px-3 bg-gray-50" readonly>
        </div>
        <div>
          <label class="block text-sm font-medium mb-1">No HP</label>
          <input id="modalNoHp" name="no_hp" type="text" class="w-full rounded border h-10 px-3" placeholder="Contoh: 0812xxxxxxx">
        </div>
        <div class="grid grid-cols-1 gap-2">
          <label class="inline-flex items-center gap-2 text-sm">
            <input id="modalAccessQuiz" name="access_quiz" type="checkbox">
            <span>Akses Quiz</span>
          </label>
          <label class="inline-flex items-center gap-2 text-sm">
            <input id="modalAccessRekap" name="access_rekap_nilai" type="checkbox">
            <span>Akses Rekap Nilai</span>
          </label>
        </div>
        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium mb-1">Limit Paket</label>
            <input id="modalLimitPaket" name="limitpaket" type="number" min="0" class="w-full rounded border h-10 px-3" required>
          </div>
          <div>
            <label class="block text-sm font-medium mb-1">Limit Gambar</label>
            <input id="modalLimitGambar" name="limitgambar" type="number" min="0" class="w-full rounded border h-10 px-3" required>
          </div>
        </div>
        <div class="flex items-center justify-end gap-2 pt-2">
          <button type="button" id="modalCancel" class="px-4 h-10 rounded border bg-white hover:bg-gray-50">Batal</button>
          <button type="submit" class="px-4 h-10 rounded border bg-blue-600 text-white hover:bg-blue-700">Simpan</button>
        </div>
      </form>
    </div>
  </div>
  <div id="roleModal" class="fixed inset-0 bg-black/40 hidden items-center justify-center z-50">
    <div class="bg-white rounded-xl border shadow-lg w-full max-w-md p-6">
      <h2 class="text-lg font-semibold mb-4">Set Role Pengguna</h2>
      <form id="roleForm" class="space-y-4">
        <input type="hidden" name="action" value="set_role">
        <input type="hidden" name="id" id="roleUserId" value="">
        <div>
          <label class="block text-sm font-medium mb-1">Username</label>
          <input id="roleUsername" type="text" class="w-full rounded border h-10 px-3 bg-gray-50" readonly>
        </div>
        <div>
          <label class="block text-sm font-medium mb-1">Role</label>
          <select id="roleSelect" name="role" class="w-full rounded border h-10 px-3">
            <option value="user">user</option>
            <option value="admin">admin</option>
          </select>
        </div>
        <div class="flex items-center justify-end gap-2 pt-2">
          <button type="button" id="roleCancel" class="px-4 h-10 rounded border bg-white hover:bg-gray-50">Batal</button>
          <button type="submit" class="px-4 h-10 rounded border bg-blue-600 text-white hover:bg-blue-700">Simpan</button>
        </div>
      </form>
    </div>
  </div>
  <div id="pwdModal" class="fixed inset-0 bg-black/40 hidden items-center justify-center z-50">
    <div class="bg-white rounded-xl border shadow-lg w-full max-w-md p-6">
      <h2 class="text-lg font-semibold mb-4">Reset Password</h2>
      <form id="pwdForm" class="space-y-4">
        <input type="hidden" name="action" value="reset_password">
        <input type="hidden" name="id" id="pwdUserId" value="">
        <div>
          <label class="block text-sm font-medium mb-1">Username</label>
          <input id="pwdUsername" type="text" class="w-full rounded border h-10 px-3 bg-gray-50" readonly>
        </div>
        <div>
          <label class="block text-sm font-medium mb-1">Password baru</label>
          <input id="pwdInput" name="password" type="password" class="w-full rounded border h-10 px-3" required>
        </div>
        <div class="flex items-center justify-end gap-2 pt-2">
          <button type="button" id="pwdCancel" class="px-4 h-10 rounded border bg-white hover:bg-gray-50">Batal</button>
          <button type="submit" class="px-4 h-10 rounded border bg-blue-600 text-white hover:bg-blue-700">Reset</button>
        </div>
      </form>
    </div>
  </div>
  <div id="createModal" class="fixed inset-0 bg-black/40 hidden items-center justify-center z-50">
    <div class="bg-white rounded-xl border shadow-lg w-full max-w-md p-6">
      <h2 class="text-lg font-semibold mb-4">Tambah Pengguna</h2>
      <form id="createForm" class="space-y-4">
        <input type="hidden" name="action" value="create_user">
        <div>
          <label class="block text-sm font-medium mb-1">Username</label>
          <input id="createUsername" name="username" type="text" class="w-full rounded border h-10 px-3" required>
        </div>
        <div>
          <label class="block text-sm font-medium mb-1">No HP</label>
          <input id="createNoHp" name="no_hp" type="text" class="w-full rounded border h-10 px-3" placeholder="Contoh: 0812xxxxxxx">
        </div>
        <div>
          <label class="block text-sm font-medium mb-1">Password</label>
          <input id="createPassword" name="password" type="password" class="w-full rounded border h-10 px-3" required>
        </div>
        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium mb-1">Role</label>
            <select id="createRole" name="role" class="w-full rounded border h-10 px-3">
              <option value="user">user</option>
              <option value="admin">admin</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium mb-1">Limit Paket</label>
            <input id="createLimitPaket" name="limitpaket" type="number" min="0" class="w-full rounded border h-10 px-3" placeholder="300">
          </div>
        </div>
        <div>
          <label class="block text-sm font-medium mb-1">Limit Gambar</label>
          <input id="createLimitGambar" name="limitgambar" type="number" min="0" class="w-full rounded border h-10 px-3" placeholder="0">
        </div>
        <div class="grid grid-cols-1 gap-2">
          <label class="inline-flex items-center gap-2 text-sm">
            <input id="createAccessQuiz" name="access_quiz" type="checkbox" checked>
            <span>Akses Quiz</span>
          </label>
          <label class="inline-flex items-center gap-2 text-sm">
            <input id="createAccessRekap" name="access_rekap_nilai" type="checkbox" checked>
            <span>Akses Rekap Nilai</span>
          </label>
        </div>
        <div class="flex items-center justify-end gap-2 pt-2">
          <button type="button" id="createCancel" class="px-4 h-10 rounded border bg-white hover:bg-gray-50">Batal</button>
          <button type="submit" class="px-4 h-10 rounded border bg-blue-600 text-white hover:bg-blue-700">Tambah</button>
        </div>
      </form>
    </div>
  </div>
  <script>
    const modal = document.getElementById('editModal');
    const form = document.getElementById('modalForm');
    const inputId = document.getElementById('modalUserId');
    const inputUsername = document.getElementById('modalUsername');
    const inputNoHp = document.getElementById('modalNoHp');
    const inputLP = document.getElementById('modalLimitPaket');
    const inputLG = document.getElementById('modalLimitGambar');
    const inputAQ = document.getElementById('modalAccessQuiz');
    const inputAR = document.getElementById('modalAccessRekap');
    const currentPage = new URLSearchParams(location.search).get('page') || '1';
    const roleModal = document.getElementById('roleModal');
    const roleForm = document.getElementById('roleForm');
    const roleUserId = document.getElementById('roleUserId');
    const roleUsername = document.getElementById('roleUsername');
    const roleSelect = document.getElementById('roleSelect');
    const pwdModal = document.getElementById('pwdModal');
    const pwdForm = document.getElementById('pwdForm');
    const pwdUserId = document.getElementById('pwdUserId');
    const pwdUsername = document.getElementById('pwdUsername');
    const pwdInput = document.getElementById('pwdInput');
    const createModal = document.getElementById('createModal');
    const createForm = document.getElementById('createForm');
    const btnCreateUser = document.getElementById('btnCreateUser');
    const createUsername = document.getElementById('createUsername');
    const createNoHp = document.getElementById('createNoHp');
    const createPassword = document.getElementById('createPassword');
    const createRole = document.getElementById('createRole');
    const createLimitPaket = document.getElementById('createLimitPaket');
    const createLimitGambar = document.getElementById('createLimitGambar');
    const createAccessQuiz = document.getElementById('createAccessQuiz');
    const createAccessRekap = document.getElementById('createAccessRekap');

    function openModalFromRow(row) {
      inputId.value = row.getAttribute('data-row');
      inputUsername.value = row.getAttribute('data-u') || '';
      inputNoHp.value = row.getAttribute('data-hp') || '';
      inputLP.value = row.getAttribute('data-lp') || '0';
      inputLG.value = row.getAttribute('data-lg') || '0';
      inputAQ.checked = (row.getAttribute('data-aq') || '1') === '1';
      inputAR.checked = (row.getAttribute('data-ar') || '1') === '1';
      modal.classList.remove('hidden');
      modal.classList.add('flex');
    }
    function closeModal() {
      modal.classList.add('hidden');
      modal.classList.remove('flex');
    }
    function openRoleModalFromRow(row) {
      roleUserId.value = row.getAttribute('data-row');
      roleUsername.value = row.getAttribute('data-u') || '';
      roleSelect.value = (row.querySelector('td:nth-child(3)')?.textContent || 'user').trim() === 'admin' ? 'admin' : 'user';
      roleModal.classList.remove('hidden');
      roleModal.classList.add('flex');
    }
    function closeRoleModal() {
      roleModal.classList.add('hidden');
      roleModal.classList.remove('flex');
    }
    function openPwdModalFromRow(row) {
      pwdUserId.value = row.getAttribute('data-row');
      pwdUsername.value = row.getAttribute('data-u') || '';
      pwdInput.value = '';
      pwdModal.classList.remove('hidden');
      pwdModal.classList.add('flex');
    }
    function closePwdModal() {
      pwdModal.classList.add('hidden');
      pwdModal.classList.remove('flex');
    }
    function openCreateModal() {
      createUsername.value = '';
      createNoHp.value = '';
      createPassword.value = '';
      createRole.value = 'user';
      createLimitPaket.value = '';
      createLimitGambar.value = '';
      createAccessQuiz.checked = true;
      createAccessRekap.checked = true;
      createModal.classList.remove('hidden');
      createModal.classList.add('flex');
      setTimeout(() => createUsername.focus(), 0);
    }
    function closeCreateModal() {
      createModal.classList.add('hidden');
      createModal.classList.remove('flex');
    }
    document.addEventListener('click', function(e) {
      if (btnCreateUser && (e.target === btnCreateUser || e.target.closest('#btnCreateUser'))) {
        openCreateModal();
        return;
      }
      const editBtn = e.target.closest('.btn-row-edit');
      if (editBtn) {
        const id = editBtn.getAttribute('data-id');
        const row = document.querySelector(`tr[data-row="${id}"]`);
        if (row) openModalFromRow(row);
      }
      const roleBtn = e.target.closest('.btn-row-role');
      if (roleBtn) {
        const id = roleBtn.getAttribute('data-id');
        const row = document.querySelector(`tr[data-row="${id}"]`);
        if (row) openRoleModalFromRow(row);
      }
      const pwdBtn = e.target.closest('.btn-row-resetpwd');
      if (pwdBtn) {
        const id = pwdBtn.getAttribute('data-id');
        const row = document.querySelector(`tr[data-row="${id}"]`);
        if (row) openPwdModalFromRow(row);
      }
      if (e.target.id === 'modalCancel' || e.target === modal) {
        closeModal();
      }
      if (e.target.id === 'roleCancel' || e.target === roleModal) {
        closeRoleModal();
      }
      if (e.target.id === 'pwdCancel' || e.target === pwdModal) {
        closePwdModal();
      }
      if (e.target.id === 'createCancel' || e.target === createModal) {
        closeCreateModal();
      }
    });
    document.addEventListener('keydown', function(e){
      if (e.key === 'Escape') {
        closeModal();
        closeRoleModal();
        closePwdModal();
        closeCreateModal();
      }
    });
    form.addEventListener('submit', async function(e){
      e.preventDefault();
      const fd = new FormData(form);
      try {
        const res = await fetch(`admin_users.php?ajax=1&page=${encodeURIComponent(currentPage)}`, {
          method: 'POST',
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
          body: fd
        });
        const data = await res.json();
        if (data && data.ok) {
          location.href = `admin_users.php?page=${encodeURIComponent(currentPage)}`;
        } else {
          alert(data && data.message ? data.message : 'Gagal menyimpan perubahan');
        }
      } catch (err) {
        alert('Gagal menyimpan perubahan');
      }
    });
    roleForm.addEventListener('submit', async function(e){
      e.preventDefault();
      const fd = new FormData(roleForm);
      try {
        const res = await fetch(`admin_users.php?ajax=1&page=${encodeURIComponent(currentPage)}`, {
          method: 'POST',
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
          body: fd
        });
        const data = await res.json();
        if (data && data.ok) {
          location.href = `admin_users.php?page=${encodeURIComponent(currentPage)}`;
        } else {
          alert(data && data.message ? data.message : 'Gagal menyimpan role');
        }
      } catch (err) {
        alert('Gagal menyimpan role');
      }
    });
    pwdForm.addEventListener('submit', async function(e){
      e.preventDefault();
      const fd = new FormData(pwdForm);
      try {
        const res = await fetch(`admin_users.php?ajax=1&page=${encodeURIComponent(currentPage)}`, {
          method: 'POST',
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
          body: fd
        });
        const data = await res.json();
        if (data && data.ok) {
          location.href = `admin_users.php?page=${encodeURIComponent(currentPage)}`;
        } else {
          alert(data && data.message ? data.message : 'Gagal mereset password');
        }
      } catch (err) {
        alert('Gagal mereset password');
      }
    });
    createForm.addEventListener('submit', async function(e){
      e.preventDefault();
      const fd = new FormData(createForm);
      try {
        const res = await fetch(`admin_users.php?ajax=1&page=${encodeURIComponent(currentPage)}`, {
          method: 'POST',
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
          body: fd
        });
        const data = await res.json();
        if (data && data.ok) {
          location.href = `admin_users.php?page=${encodeURIComponent(currentPage)}`;
        } else {
          alert(data && data.message ? data.message : 'Gagal menambah pengguna');
        }
      } catch (err) {
        alert('Gagal menambah pengguna');
      }
    });
  </script>
</body>
</html>
