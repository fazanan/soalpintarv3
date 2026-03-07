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

function post($k, $default = '') { return isset($_POST[$k]) ? trim((string)$_POST[$k]) : $default; }
function get($k, $default = '') { return isset($_GET[$k]) ? trim((string)$_GET[$k]) : $default; }
function to_null_int($v) { $v = trim((string)$v); return $v === '' ? null : (int)$v; }
function to_null_float($v) { $v = trim((string)$v); return $v === '' ? null : (float)$v; }

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = post('action');
  if ($action === 'add') {
    $provider = strtolower(post('provider', 'openai'));
    $modality = post('modality', 'chat');
    $model = post('model', '');
    $displayName = post('display_name', '');
    $endpoint = post('endpoint_url', '');
    $inPrice = (float)post('token_input_price', '0');
    $outPrice = (float)post('token_output_price', '0');
    $currency = post('currency', 'USD');
    $unit = post('unit', 'per_1k_tokens');
    $fxIdr = (float)post('currency_rate_to_idr', '1');
    $maxIn = to_null_int(post('max_input_tokens', ''));
    $maxOut = to_null_int(post('max_output_tokens', ''));
    $supportsJson = isset($_POST['supports_json_mode']) ? 1 : 0;
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    if ($provider === '' || $modality === '' || $model === '' || $endpoint === '') {
      $error = 'Provider, modality, model, dan endpoint wajib diisi.';
    } else {
      $stmt = $mysqli->prepare("INSERT INTO api_models (provider, modality, model, display_name, endpoint_url, token_input_price, token_output_price, currency, currency_rate_to_idr, unit, max_input_tokens, max_output_tokens, supports_json_mode, is_active) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
      if ($stmt) {
        $stmt->bind_param('ssssssssssiiii',
          $provider, $modality, $model, $displayName, $endpoint,
          $inPrice, $outPrice, $currency, $fxIdr, $unit, $maxIn, $maxOut, $supportsJson, $isActive
        );
        if ($stmt->execute()) $message = 'Model ditambahkan.';
        else $error = 'Gagal menambah model (mungkin kombinasi provider+model+modality sudah ada).';
        $stmt->close();
      } else {
        $error = 'DB error.';
      }
    }
  }
  if ($action === 'edit') {
    $id = (int)post('id', '0');
    $provider = strtolower(post('provider', 'openai'));
    $modality = post('modality', 'chat');
    $model = post('model', '');
    $displayName = post('display_name', '');
    $endpoint = post('endpoint_url', '');
    $inPrice = (float)post('token_input_price', '0');
    $outPrice = (float)post('token_output_price', '0');
    $currency = post('currency', 'USD');
    $unit = post('unit', 'per_1k_tokens');
    $fxIdr = (float)post('currency_rate_to_idr', '1');
    $maxIn = to_null_int(post('max_input_tokens', ''));
    $maxOut = to_null_int(post('max_output_tokens', ''));
    $supportsJson = isset($_POST['supports_json_mode']) ? 1 : 0;
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    if ($id <= 0) {
      $error = 'ID tidak valid.';
    } else if ($provider === '' || $modality === '' || $model === '' || $endpoint === '') {
      $error = 'Provider, modality, model, dan endpoint wajib diisi.';
    } else {
      $stmt = $mysqli->prepare("UPDATE api_models SET provider=?, modality=?, model=?, display_name=?, endpoint_url=?, token_input_price=?, token_output_price=?, currency=?, currency_rate_to_idr=?, unit=?, max_input_tokens=?, max_output_tokens=?, supports_json_mode=?, is_active=? WHERE id=?");
      if ($stmt) {
        $stmt->bind_param('ssssssssssiiiii',
          $provider, $modality, $model, $displayName, $endpoint,
          $inPrice, $outPrice, $currency, $fxIdr, $unit, $maxIn, $maxOut, $supportsJson, $isActive, $id
        );
        if ($stmt->execute()) $message = 'Model diperbarui.';
        else $error = 'Gagal memperbarui model.';
        $stmt->close();
      } else {
        $error = 'DB error.';
      }
    }
  }
  if ($action === 'toggle') {
    $id = (int)post('id', '0');
    $to = (int)post('to', '0');
    $stmt = $mysqli->prepare("UPDATE api_models SET is_active=? WHERE id=?");
    if ($stmt) {
      $stmt->bind_param('ii', $to, $id);
      if ($stmt->execute()) $message = 'Status model diperbarui.';
      else $error = 'Gagal memperbarui status.';
      $stmt->close();
    } else {
      $error = 'DB error.';
    }
  }
  if ($action === 'delete') {
    $id = (int)post('id', '0');
    $stmt = $mysqli->prepare("DELETE FROM api_models WHERE id=?");
    if ($stmt) {
      $stmt->bind_param('i', $id);
      if ($stmt->execute()) $message = 'Model dihapus.';
      else $error = 'Gagal menghapus model.';
      $stmt->close();
    } else {
      $error = 'DB error.';
    }
  }
}

$editId = (int)get('edit', '0');
$editRow = null;
if ($editId > 0) {
  $stmt = $mysqli->prepare("SELECT * FROM api_models WHERE id=? LIMIT 1");
  $stmt->bind_param('i', $editId);
  $stmt->execute();
  $res = $stmt->get_result();
  $editRow = $res ? $res->fetch_assoc() : null;
  $stmt->close();
}

$rows = [];
$res = $mysqli->query("SELECT * FROM api_models ORDER BY is_active DESC, provider, modality, model");
if ($res) while ($r = $res->fetch_assoc()) $rows[] = $r;
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Model API</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
  <style>body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Inter,sans-serif}</style>
</head>
<body class="bg-gray-50 min-h-dvh">
  <div class="max-w-6xl mx-auto p-6">
    <div class="flex items-center justify-between mb-6">
      <h1 class="text-2xl font-semibold">Model API</h1>
      <a href="index.php" class="px-4 py-2 rounded-lg border bg-white hover:bg-gray-50">Kembali</a>
    </div>
    <?php if ($message): ?>
      <div class="mb-4 p-3 rounded-lg bg-green-50 border border-green-200 text-green-800"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="mb-4 p-3 rounded-lg bg-red-50 border border-red-200 text-red-800"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <div class="bg-white rounded-xl border shadow-sm p-6 mb-8">
      <div class="text-lg font-semibold mb-4"><?php echo $editRow ? 'Ubah Model' : 'Tambah Model'; ?></div>
      <form method="post" class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <input type="hidden" name="action" value="<?php echo $editRow ? 'edit' : 'add'; ?>">
        <?php if ($editRow): ?>
          <input type="hidden" name="id" value="<?php echo (int)$editRow['id']; ?>">
        <?php endif; ?>
        <div>
          <label class="text-sm font-semibold mb-1 block">Provider</label>
          <input name="provider" class="w-full rounded-lg border h-11 px-3" value="<?php echo htmlspecialchars($editRow['provider'] ?? 'openai'); ?>" required />
        </div>
        <div>
          <label class="text-sm font-semibold mb-1 block">Modality</label>
          <select name="modality" class="w-full rounded-lg border h-11 px-3">
            <?php
              $mods = ['chat'=>'chat','image'=>'image','audio'=>'audio','embedding'=>'embedding','other'=>'other'];
              $sel = $editRow['modality'] ?? 'chat';
              foreach ($mods as $k=>$v) {
                echo '<option value="'.$k.'"'.($sel===$k?' selected':'').'>'.$v.'</option>';
              }
            ?>
          </select>
        </div>
        <div>
          <label class="text-sm font-semibold mb-1 block">Model</label>
          <input name="model" class="w-full rounded-lg border h-11 px-3" value="<?php echo htmlspecialchars($editRow['model'] ?? 'gpt-4o-mini'); ?>" required />
        </div>
        <div>
          <label class="text-sm font-semibold mb-1 block">Display Name</label>
          <input name="display_name" class="w-full rounded-lg border h-11 px-3" value="<?php echo htmlspecialchars($editRow['display_name'] ?? ''); ?>" />
        </div>
        <div class="md:col-span-2">
          <label class="text-sm font-semibold mb-1 block">Endpoint URL</label>
          <input name="endpoint_url" class="w-full rounded-lg border h-11 px-3" placeholder="https://api.openai.com/v1/chat/completions" value="<?php echo htmlspecialchars($editRow['endpoint_url'] ?? 'https://api.openai.com/v1/chat/completions'); ?>" required />
        </div>
        <div>
          <label class="text-sm font-semibold mb-1 block">Harga Token Input</label>
          <input name="token_input_price" type="number" step="0.00000001" class="w-full rounded-lg border h-11 px-3" value="<?php echo htmlspecialchars($editRow['token_input_price'] ?? '0'); ?>" />
        </div>
        <div>
          <label class="text-sm font-semibold mb-1 block">Harga Token Output</label>
          <input name="token_output_price" type="number" step="0.00000001" class="w-full rounded-lg border h-11 px-3" value="<?php echo htmlspecialchars($editRow['token_output_price'] ?? '0'); ?>" />
        </div>
        <div>
          <label class="text-sm font-semibold mb-1 block">Mata Uang</label>
          <input name="currency" class="w-full rounded-lg border h-11 px-3" value="<?php echo htmlspecialchars($editRow['currency'] ?? 'USD'); ?>" />
        </div>
        <div>
          <label class="text-sm font-semibold mb-1 block">Kurs ke IDR</label>
          <input name="currency_rate_to_idr" type="number" step="0.00000001" class="w-full rounded-lg border h-11 px-3" value="<?php echo htmlspecialchars($editRow['currency_rate_to_idr'] ?? '1'); ?>" />
        </div>
        <div>
          <label class="text-sm font-semibold mb-1 block">Unit Harga</label>
          <input name="unit" class="w-full rounded-lg border h-11 px-3" value="<?php echo htmlspecialchars($editRow['unit'] ?? 'per_1k_tokens'); ?>" />
        </div>
        <div>
          <label class="text-sm font-semibold mb-1 block">Max Input Tokens</label>
          <input name="max_input_tokens" type="number" class="w-full rounded-lg border h-11 px-3" value="<?php echo htmlspecialchars($editRow['max_input_tokens'] ?? ''); ?>" />
        </div>
        <div>
          <label class="text-sm font-semibold mb-1 block">Max Output Tokens</label>
          <input name="max_output_tokens" type="number" class="w-full rounded-lg border h-11 px-3" value="<?php echo htmlspecialchars($editRow['max_output_tokens'] ?? ''); ?>" />
        </div>
        <div class="flex items-center gap-2">
          <input type="checkbox" name="supports_json_mode" value="1" class="rounded" <?php echo ($editRow['supports_json_mode'] ?? 1) ? 'checked' : ''; ?> />
          <label>Supports JSON Mode</label>
        </div>
        <div class="flex items-center gap-2">
          <input type="checkbox" name="is_active" value="1" class="rounded" <?php echo ($editRow['is_active'] ?? 1) ? 'checked' : ''; ?> />
          <label>Aktif</label>
        </div>
        <div class="md:col-span-3 flex items-center justify-between">
          <?php if ($editRow): ?>
            <a href="admin_api_models.php" class="px-4 h-11 rounded-lg border bg-white hover:bg-gray-50">Batal</a>
          <?php else: ?>
            <span></span>
          <?php endif; ?>
          <button class="px-4 h-11 rounded-lg bg-blue-600 hover:bg-blue-700 text-white font-semibold"><?php echo $editRow ? 'Simpan Perubahan' : 'Tambah Model'; ?></button>
        </div>
      </form>
    </div>

    <div class="bg-white rounded-xl border shadow-sm p-6">
      <div class="text-lg font-semibold mb-4">Daftar Model</div>
      <div class="overflow-x-auto">
        <table class="min-w-full text-sm border whitespace-nowrap">
          <thead class="bg-gray-100">
            <tr>
              <th class="border px-3 py-2 text-left">Provider</th>
              <th class="border px-3 py-2 text-left">Modality</th>
              <th class="border px-3 py-2 text-left">Model</th>
              <th class="border px-3 py-2 text-left">Endpoint</th>
              <th class="border px-3 py-2 text-left">Harga (In/Out)</th>
              <th class="border px-3 py-2 text-left">Aktif</th>
              <th class="border px-3 py-2">Aksi</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($rows)): ?>
              <tr><td colspan="7" class="border px-3 py-6 text-center text-gray-500">Belum ada model.</td></tr>
            <?php else: foreach ($rows as $r): ?>
              <tr>
                <td class="border px-3 py-2"><?php echo htmlspecialchars($r['provider']); ?></td>
                <td class="border px-3 py-2"><?php echo htmlspecialchars($r['modality']); ?></td>
                <td class="border px-3 py-2"><?php echo htmlspecialchars($r['model']); ?></td>
                <td class="border px-3 py-2"><?php echo htmlspecialchars($r['endpoint_url']); ?></td>
                <td class="border px-3 py-2"><?php echo (float)$r['token_input_price']; ?> / <?php echo (float)$r['token_output_price']; ?> <?php echo htmlspecialchars($r['currency']); ?> (<?php echo htmlspecialchars($r['unit']); ?>)</td>
                <td class="border px-3 py-2"><?php echo $r['is_active'] ? 'Ya' : 'Tidak'; ?></td>
                <td class="border px-3 py-2">
                  <div class="flex items-center gap-2">
                    <a class="px-3 py-1 rounded border bg-white hover:bg-gray-50" href="?edit=<?php echo (int)$r['id']; ?>">Edit</a>
                    <form method="post" onsubmit="return confirm('Ubah status aktif?')">
                      <input type="hidden" name="action" value="toggle">
                      <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                      <input type="hidden" name="to" value="<?php echo $r['is_active'] ? 0 : 1; ?>">
                      <button class="px-3 py-1 rounded border bg-white hover:bg-gray-50">
                        <?php echo $r['is_active'] ? 'Nonaktifkan' : 'Aktifkan'; ?>
                      </button>
                    </form>
                    <form method="post" onsubmit="return confirm('Hapus model ini?')">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                      <button class="px-3 py-1 rounded border border-red-300 text-red-600 bg-white hover:bg-red-50">Hapus</button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</body>
</html>
