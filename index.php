<?php
session_start();
if (!isset($_SESSION['user_id'])) {
  header('Location: login.php');
  exit;
}
require_once __DIR__ . '/auth_lock.php';
require_once __DIR__ . '/db.php';
$__uid = (int)($_SESSION['user_id'] ?? 0);
$__sid = session_id();
$__role = (string)($_SESSION['role'] ?? 'user');
$__isLockExempt = (int)($_SESSION['session_lock_exempt'] ?? 0) === 1;

function stmt_bind_params(mysqli_stmt $stmt, string $types, array $values): void {
  $bind = [];
  $bind[] = $types;
  foreach ($values as $i => $v) {
    $bind[] = &$values[$i];
  }
  call_user_func_array([$stmt, 'bind_param'], $bind);
}

function normalize_nama(string $s): string {
  $s = trim((string)preg_replace('/\s+/', ' ', $s));
  if ($s === '') return '';
  if (function_exists('mb_strtolower') && function_exists('mb_convert_case')) {
    $s = mb_strtolower($s, 'UTF-8');
    return mb_convert_case($s, MB_CASE_TITLE, 'UTF-8');
  }
  return ucwords(strtolower($s));
}

if ($__uid > 0 && $__sid && $__role !== 'admin' && !$__isLockExempt) {
  $__did = auth_lock_get_device_id();
  $__fp = auth_lock_fingerprint();
  if (!auth_lock_touch($__uid, $__sid, $__did, $__fp)) {
    $_SESSION = [];
    session_destroy();
    header('Location: login.php?e=busy');
    exit;
  }
}

if (isset($_GET['ajax']) && (string)$_GET['ajax'] === 'profile_update') {
  header('Content-Type: application/json; charset=utf-8');
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
  }
  $nama = normalize_nama(isset($_POST['nama']) ? trim((string)$_POST['nama']) : '');
  $jenjang = isset($_POST['jenjang']) ? trim((string)$_POST['jenjang']) : '';
  $namaSekolah = isset($_POST['nama_sekolah']) ? trim((string)$_POST['nama_sekolah']) : '';
  $noHp = isset($_POST['no_hp']) ? trim((string)$_POST['no_hp']) : '';
  $noHp = trim((string)preg_replace('/[^0-9+()\\-\\s]/', '', $noHp));

  if ($nama === '' || $jenjang === '' || $namaSekolah === '' || $noHp === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Mohon lengkapi Nama, Jenjang, Nama Sekolah, dan No HP.'], JSON_UNESCAPED_UNICODE);
    exit;
  }
  if (strlen($nama) > 120 || strlen($jenjang) > 20 || strlen($namaSekolah) > 160 || strlen($noHp) > 32) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Data terlalu panjang.'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $hasNamaCol = false;
  $hasJenjangCol = false;
  $hasNamaSekolahCol = false;
  $hasNoHpCol = false;
  if ($rs = $mysqli->query("SHOW COLUMNS FROM users LIKE 'nama'")) { $hasNamaCol = $rs->num_rows > 0; $rs->close(); }
  if ($rs = $mysqli->query("SHOW COLUMNS FROM users LIKE 'jenjang'")) { $hasJenjangCol = $rs->num_rows > 0; $rs->close(); }
  if ($rs = $mysqli->query("SHOW COLUMNS FROM users LIKE 'nama_sekolah'")) { $hasNamaSekolahCol = $rs->num_rows > 0; $rs->close(); }
  if ($rs = $mysqli->query("SHOW COLUMNS FROM users LIKE 'no_hp'")) { $hasNoHpCol = $rs->num_rows > 0; $rs->close(); }

  if (!$hasNoHpCol) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Penyimpanan No HP belum aktif di server. Silakan hubungi admin.'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $digits = preg_replace('/\\D+/', '', $noHp);
  $candPhones = [];
  if ($digits !== '') {
    if (substr($digits, 0, 1) === '0') {
      $candPhones[] = $digits;
      $candPhones[] = '62' . substr($digits, 1);
    } elseif (substr($digits, 0, 2) === '62') {
      $candPhones[] = $digits;
      $candPhones[] = '0' . substr($digits, 2);
    } elseif (substr($digits, 0, 1) === '8') {
      $candPhones[] = '0' . $digits;
      $candPhones[] = '62' . $digits;
      $candPhones[] = $digits;
    } else {
      $candPhones[] = $digits;
    }
    $candPhones = array_values(array_unique(array_filter($candPhones, function ($x) { return $x !== ''; })));
    $candPhones = array_slice($candPhones, 0, 4);
  }
  if (count($candPhones) > 0) {
    $phoneExpr = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(no_hp,' ',''),'-',''),'(',''),')',''),'+','')";
    $ph = implode(',', array_fill(0, count($candPhones), '?'));
    $check = $mysqli->prepare("SELECT id FROM users WHERE id<>? AND ($phoneExpr IN ($ph)) LIMIT 1");
    $typesC = 'i' . str_repeat('s', count($candPhones));
    $valsC = array_merge([$__uid], $candPhones);
    stmt_bind_params($check, $typesC, $valsC);
    $check->execute();
    $check->store_result();
    if ($check->num_rows > 0) {
      http_response_code(409);
      echo json_encode(['ok' => false, 'message' => 'Nomor HP tersebut sudah terdaftar pada akun lain. Silakan gunakan nomor yang berbeda atau hubungi admin jika ini nomor Bapak/Ibu.'], JSON_UNESCAPED_UNICODE);
      $check->close();
      exit;
    }
    $check->close();
  }

  $sets = [];
  $types = '';
  $values = [];
  if ($hasNamaCol) { $sets[] = 'nama=?'; $types .= 's'; $values[] = $nama; }
  if ($hasJenjangCol) { $sets[] = 'jenjang=?'; $types .= 's'; $values[] = $jenjang; }
  if ($hasNamaSekolahCol) { $sets[] = 'nama_sekolah=?'; $types .= 's'; $values[] = $namaSekolah; }
  $sets[] = 'no_hp=?'; $types .= 's'; $values[] = $noHp;
  if (!$sets) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Kolom profil belum tersedia di database.'], JSON_UNESCAPED_UNICODE);
    exit;
  }
  $values[] = $__uid; $types .= 'i';

  $sql = "UPDATE users SET " . implode(',', $sets) . " WHERE id=?";
  $stmt = $mysqli->prepare($sql);
  stmt_bind_params($stmt, $types, $values);
  if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Gagal menyimpan profil.'], JSON_UNESCAPED_UNICODE);
    $stmt->close();
    exit;
  }
  $stmt->close();

  $_SESSION['nama'] = $nama;
  $_SESSION['jenjang'] = $jenjang;
  $_SESSION['nama_sekolah'] = $namaSekolah;
  $_SESSION['no_hp'] = $noHp;
  session_write_close();
  echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
  exit;
}

$__userProfile = [
  'nama' => (string)($_SESSION['nama'] ?? ''),
  'jenjang' => (string)($_SESSION['jenjang'] ?? ''),
  'nama_sekolah' => (string)($_SESSION['nama_sekolah'] ?? ''),
  'no_hp' => (string)($_SESSION['no_hp'] ?? ''),
];
if ($__uid > 0) {
  $hasNamaCol = false;
  $hasJenjangCol = false;
  $hasNamaSekolahCol = false;
  $hasNoHpCol = false;
  if ($rs = $mysqli->query("SHOW COLUMNS FROM users LIKE 'nama'")) { $hasNamaCol = $rs->num_rows > 0; $rs->close(); }
  if ($rs = $mysqli->query("SHOW COLUMNS FROM users LIKE 'jenjang'")) { $hasJenjangCol = $rs->num_rows > 0; $rs->close(); }
  if ($rs = $mysqli->query("SHOW COLUMNS FROM users LIKE 'nama_sekolah'")) { $hasNamaSekolahCol = $rs->num_rows > 0; $rs->close(); }
  if ($rs = $mysqli->query("SHOW COLUMNS FROM users LIKE 'no_hp'")) { $hasNoHpCol = $rs->num_rows > 0; $rs->close(); }
  $selNama = $hasNamaCol ? 'nama' : "''";
  $selJenjang = $hasJenjangCol ? 'jenjang' : "''";
  $selSekolah = $hasNamaSekolahCol ? 'nama_sekolah' : "''";
  $selNoHp = $hasNoHpCol ? 'no_hp' : "''";
  $stmt = $mysqli->prepare("SELECT ($selNama) AS nama, ($selJenjang) AS jenjang, ($selSekolah) AS nama_sekolah, ($selNoHp) AS no_hp FROM users WHERE id=? LIMIT 1");
  $stmt->bind_param('i', $__uid);
  if ($stmt->execute()) {
    $namaDb = '';
    $jenjangDb = '';
    $sekolahDb = '';
    $noHpDb = '';
    $stmt->bind_result($namaDb, $jenjangDb, $sekolahDb, $noHpDb);
    if ($stmt->fetch()) {
      if (trim((string)$namaDb) !== '') $__userProfile['nama'] = (string)$namaDb;
      if (trim((string)$jenjangDb) !== '') $__userProfile['jenjang'] = (string)$jenjangDb;
      if (trim((string)$sekolahDb) !== '') $__userProfile['nama_sekolah'] = (string)$sekolahDb;
      if (trim((string)$noHpDb) !== '') $__userProfile['no_hp'] = (string)$noHpDb;
    }
  }
  $stmt->close();
}
session_write_close();
?>
<!DOCTYPE html>
<html lang="id" class="custom-scrollbar">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>GuruPintar</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link
      href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700&family=Noto+Sans:wght@400;500;600;700&display=swap"
      rel="stylesheet"
    />
    <link
      href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap"
      rel="stylesheet"
    />
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <script>
      tailwind.config = {
        darkMode: "class",
        theme: {
          extend: {
            colors: {
              primary: "#137fec",
              "primary-content": "#ffffff",
              "background-light": "#f6f7f8",
              "background-dark": "#101922",
              "surface-light": "#ffffff",
              "surface-dark": "#1a2632",
              "text-main-light": "#0d141b",
              "text-main-dark": "#e2e8f0",
              "text-sub-light": "#4c739a",
              "text-sub-dark": "#94a3b8",
              "border-light": "#e7edf3",
              "border-dark": "#2d3748",
            },
            fontFamily: {
              display: ["Lexend", "sans-serif"],
              body: ["Noto Sans", "sans-serif"],
            },
            boxShadow: {
              paper:
                "0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03)",
            },
          },
        },
      };
    </script>
    <script>
      (function () {
        const ping = async () => {
          try {
            const res = await fetch("api/openai_proxy.php?ping=1", { credentials: "same-origin" });
            if (res.status === 401) window.location.href = "login.php?e=busy";
          } catch (e) {}
        };
        ping();
        setInterval(ping, 120000);
        document.addEventListener("visibilitychange", () => {
          if (!document.hidden) ping();
        });
      })();
    </script>
    <script src="https://cdn.jsdelivr.net/npm/docx@8.5.0/build/index.umd.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/file-saver@2.0.5/dist/FileSaver.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js"></script>
    <style>
      .material-symbols-outlined {
        font-variation-settings: "FILL" 0, "wght" 520, "GRAD" 0, "opsz" 24;
      }
      .no-scrollbar::-webkit-scrollbar {
        display: none;
      }
      .no-scrollbar {
        -ms-overflow-style: none;
        scrollbar-width: none;
      }
      .custom-scrollbar::-webkit-scrollbar {
        width: 14px;
        height: 14px;
      }
      .custom-scrollbar::-webkit-scrollbar-track {
        background: transparent;
      }
      .custom-scrollbar::-webkit-scrollbar-thumb {
        background-color: #cbd5e1;
        border-radius: 7px;
        border: 4px solid transparent;
        background-clip: content-box;
      }
      .custom-scrollbar::-webkit-scrollbar-thumb:hover {
        background-color: #94a3b8;
      }
      @media print {
        .no-print {
          display: none !important;
        }
        body {
          background: #fff !important;
        }
        #paper {
          box-shadow: none !important;
          border: none !important;
          margin: 0 !important;
        }
      }
    </style>
  </head>
  <body
    class="bg-background-light dark:bg-background-dark text-text-main-light dark:text-text-main-dark font-display antialiased min-h-screen flex flex-col"
  >
    <div class="hidden"></div>

    <div class="flex flex-1">
        <aside
        id="mainSidebar"
        class="no-print w-[280px] hidden lg:flex flex-col border-r border-border-light dark:border-border-dark bg-surface-light dark:bg-surface-dark sticky top-0 h-screen overflow-y-auto"
      >
        <div class="p-5 flex flex-col gap-4">
          <div class="flex items-start justify-between gap-3">
            <div class="flex items-center gap-3">
              <div class="size-9 rounded-lg bg-primary/10 text-primary flex items-center justify-center">
                <span class="material-symbols-outlined">menu_book</span>
              </div>
              <div>
                <div class="text-lg font-semibold tracking-tight">
                  <span class="text-primary">Guru</span><span class="text-text-main-light dark:text-text-main-dark">Pintar</span>
                </div>
              <div class="text-xs italic text-text-sub-light dark:text-text-sub-dark mt-0.5">Sahabat Pendidik Indonesia</div>
              </div>
            </div>
          </div>
          <div id="limitSidebar" class="hidden no-print -mt-1 text-[13px] font-semibold text-blue-700 dark:text-blue-300"></div>
          <div id="nav" class="flex flex-col gap-1"></div>
          <div class="h-px bg-border-light dark:bg-border-dark my-2"></div>
          <button
            id="btnReset"
            class="flex w-full items-center justify-center gap-2 rounded-lg h-10 px-4 bg-red-50 dark:bg-red-500/10 border border-red-200 dark:border-red-500/30 text-red-600 dark:text-red-400 hover:bg-red-100 dark:hover:bg-red-500/20 text-sm font-medium transition-colors"
          >
            <span class="material-symbols-outlined text-[18px]">restart_alt</span>
            Reset
          </button>
          <div class="h-px bg-border-light dark:bg-border-dark my-2"></div>
          <button id="btnExport" class="hidden"></button>
          <button id="btnExportKisi" class="hidden"></button>
          <button id="btnExportKunci" class="hidden"></button>
          <button id="btnQuiz" class="hidden"></button>
          <div class="mt-auto text-xs text-text-sub-light dark:text-text-sub-dark">
            <div class="mt-2">
              <span id="badgeSaved" class="hidden text-[10px] font-semibold px-2 py-0.5 rounded-full bg-green-100 text-green-700">Tersimpan</span>
            </div>
          </div>
        </div>
      </aside>

      <main class="flex-1 flex flex-col overflow-hidden">
        <div
          class="no-print bg-surface-light dark:bg-surface-dark border-b border-border-light dark:border-border-dark px-4 md:px-8 pt-4 sticky top-0 z-40"
        >
            <div class="flex items-start justify-between gap-4 mb-2">
            <div class="flex items-center gap-3">
              <button
                id="btnToggleSidebar"
                class="p-2 -ml-2 rounded-lg hover:bg-black/5 dark:hover:bg-white/5 text-text-sub-light dark:text-text-sub-dark transition-colors"
                onclick="toggleSidebar()"
                title="Toggle Sidebar"
              >
                <span class="material-symbols-outlined">menu</span>
              </button>
              <div>
                <div id="pageTitle" class="text-xl md:text-2xl font-semibold tracking-tight">Identitas Soal</div>
                  <div id="pageDesc" class="hidden md:block text-sm text-text-sub-light dark:text-text-sub-dark mt-1">
                  Lengkapi identitas sebelum menyusun paket
                </div>
              </div>
            </div>
            <div class="flex flex-col items-end">
              <div class="text-right">
                  <div class="text-xl md:text-4xl font-bold tracking-tight">
                  <span class="text-primary">Guru</span><span class="text-text-main-light dark:text-text-main-dark">Pintar</span>
                </div>
                  <div class="hidden md:block italic text-xs md:text-sm text-text-sub-light dark:text-text-sub-dark">Sahabat Pendidik Indonesia</div>
              </div>
              <button
                id="btnExportTop"
                class="hidden mt-2 flex items-center gap-2 px-3 py-1.5 rounded-md border border-border-light dark:border-border-dark hover:bg-background-light dark:hover:bg-background-dark text-sm font-medium transition-colors"
              >
                <span class="material-symbols-outlined text-[16px]">description</span>
                Unduh .docx
              </button>
            </div>
          </div>
          <div class="flex items-center justify-start gap-2 pb-3">
            <div id="tabs" class="hidden"></div>
            <div class="flex items-center gap-2 md:hidden">
              <button id="btnSave" class="hidden md:inline-flex items-center gap-2 h-10 rounded-full border bg-white dark:bg-surface-dark border-border-light dark:border-border-dark hover:bg-background-light dark:hover:bg-background-dark transition-colors px-3">
                <span class="material-symbols-outlined text-[18px] shrink-0">save</span>
                <span class="hidden lg:inline text-sm font-medium whitespace-nowrap">Simpan</span>
              </button>
              <button id="btnLoad" class="hidden md:inline-flex items-center gap-2 h-10 rounded-full border bg-white dark:bg-surface-dark border-border-light dark:border-border-dark hover:bg-background-light dark:hover:bg-background-dark transition-colors px-3">
                <span class="material-symbols-outlined text-[18px] shrink-0">folder_open</span>
                <span class="hidden lg:inline text-sm font-medium whitespace-nowrap">Muat</span>
              </button>
              
            </div>
          </div>
        </div>
        <div class="flex-1 p-4 md:p-8 flex justify-center">
          <div id="viewRoot" class="w-full max-w-[1100px]"></div>
        </div>
      </main>
    </div>

    <div
      id="quizOverlay"
      class="hidden fixed inset-0 z-50 bg-black/60 backdrop-blur-sm flex items-center justify-center p-4"
      role="dialog"
      aria-modal="true"
    >
      <div class="w-full max-w-4xl bg-surface-light dark:bg-surface-dark rounded-2xl shadow-2xl border border-border-light dark:border-border-dark overflow-hidden">
        <div class="flex items-center justify-between px-5 py-4 border-b border-border-light dark:border-border-dark">
          <div class="flex items-center gap-3">
            <div class="size-10 rounded-lg bg-primary/10 text-primary flex items-center justify-center">
              <span class="material-symbols-outlined">quiz</span>
            </div>
            <div>
              <div class="font-bold">Mode Kuis</div>
              <div id="quizMeta" class="text-xs text-text-sub-light dark:text-text-sub-dark">0 / 0</div>
            </div>
          </div>
          <div class="flex items-center gap-3">
            <div id="quizScoreDisplay" class="hidden px-3 py-1.5 bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400 rounded-lg text-sm font-bold items-center gap-2 border border-green-200 dark:border-green-800">
              <span class="material-symbols-outlined text-[18px]">trophy</span>
              <span>Skor: <span id="quizScoreValue">0</span></span>
            </div>
            <button
              id="btnQuizClose"
            class="flex size-10 items-center justify-center rounded-full bg-border-light dark:bg-border-dark text-text-sub-light dark:text-text-sub-dark hover:bg-primary/10 hover:text-primary transition-colors"
            title="Tutup"
          >
            <span class="material-symbols-outlined">close</span>
          </button>
          </div>
        </div>
        <div id="quizBody" class="p-5 md:p-7"></div>
        <div
          class="flex flex-col md:flex-row md:items-center gap-3 px-5 py-4 border-t border-border-light dark:border-border-dark bg-background-light/50 dark:bg-background-dark/30"
        >
          <div class="flex items-center gap-2">
            <button
              id="btnQuizPrev"
              class="flex items-center gap-2 rounded-lg h-10 px-4 bg-white dark:bg-surface-dark border border-border-light dark:border-border-dark hover:bg-background-light dark:hover:bg-background-dark text-sm font-bold shadow-sm transition-colors"
            >
              <span class="material-symbols-outlined text-[18px]">chevron_left</span>
              Sebelumnya
            </button>
            <button
              id="btnQuizNext"
              class="flex items-center gap-2 rounded-lg h-10 px-4 bg-primary hover:bg-blue-600 text-primary-content text-sm font-bold shadow-sm transition-colors"
            >
              Selanjutnya
              <span class="material-symbols-outlined text-[18px]">chevron_right</span>
            </button>
          </div>
        </div>
      </div>
    </div>

    <div id="usagePolicyModal" class="fixed inset-0 hidden items-center justify-center" style="display:none; background: rgba(0,0,0,0.6); z-index:70;">
      <div class="bg-white dark:bg-surface-dark rounded-xl border border-border-light dark:border-border-dark shadow-xl w-[92vw] max-w-[780px] max-h-[85vh] overflow-auto">
        <div class="p-5 border-b border-border-light dark:border-border-dark flex items-center justify-between">
          <div class="font-bold text-lg flex items-center gap-2"><span class="material-symbols-outlined">policy</span> Ketentuan Penggunaan</div>
          <button class="size-9 rounded-lg border bg-white dark:bg-surface-dark" onclick="window.__sp.closeUsagePolicy(true)">&times;</button>
        </div>
        <div class="p-5 space-y-4 text-sm leading-relaxed">
          <div class="rounded-lg border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/20 p-4">
            <div class="font-bold text-amber-700 dark:text-amber-300 mb-1">Mohon dibaca dan dipatuhi</div>
            <div class="text-amber-800/90 dark:text-amber-200">
              Aplikasi Guru Pintar ditujukan untuk membantu Bapak/Ibu menyusun dokumen pembelajaran untuk kebutuhan mengajar Bapak/Ibu sendiri.
            </div>
          </div>
          <ul class="list-disc pl-5 space-y-2">
            <li>Dilarang menggunakan semua fitur maupun hasil dokumen dari aplikasi ini untuk membuatkan dokumen pembelajaran bagi guru lain.</li>
            <li>Dilarang membagikan, menjual, memperbanyak, atau mendistribusikan hasil dokumen aplikasi ini untuk kepentingan pihak lain.</li>
            <li>Gunakan secara bertanggung jawab, sesuai etika profesi pendidik, dan patuhi aturan sekolah serta regulasi yang berlaku.</li>
          </ul>
          <div class="flex flex-col sm:flex-row gap-3 sm:justify-end pt-2">
            <a class="inline-flex items-center justify-center h-10 px-4 rounded-lg border bg-white dark:bg-surface-dark hover:bg-background-light dark:hover:bg-background-dark text-sm font-bold" href="logout.php">Keluar</a>
            <button class="inline-flex items-center justify-center h-10 px-4 rounded-lg bg-primary hover:bg-blue-600 text-white text-sm font-bold shadow-sm transition-colors" onclick="window.__sp.closeUsagePolicy(true)">Saya Mengerti</button>
          </div>
        </div>
      </div>
    </div>
    

    <div id="profileRequiredModal" class="fixed inset-0 z-[9999] hidden items-center justify-center bg-black/40 p-4">
      <div class="w-full max-w-lg bg-white dark:bg-surface-dark rounded-2xl border border-border-light dark:border-border-dark shadow-paper overflow-hidden">
        <div class="p-5 border-b border-border-light dark:border-border-dark">
          <div class="font-bold text-lg">Lengkapi Profil</div>
          <div class="text-sm text-text-sub-light dark:text-text-sub-dark mt-1">
            Lengkapi sekali saja agar penggunaan aplikasi lebih cepat dan nyaman.
          </div>
        </div>
        <div class="p-5 space-y-4">
          <div class="rounded-lg border border-blue-200 dark:border-blue-900 bg-blue-50 dark:bg-blue-900/20 p-4 text-sm text-blue-900 dark:text-blue-200">
            Dengan melengkapi data ini, aplikasi bisa mengisi otomatis Nama Guru, Nama Sekolah, dan Jenjang pada dokumen (Modul Ajar/RPP/dll) sehingga Bapak/Ibu tidak perlu mengetik berulang-ulang.
          </div>
          <div id="profileRequiredError" class="hidden rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700"></div>
          <form id="profileRequiredForm" class="space-y-4">
            <div>
              <label class="block text-sm font-semibold mb-1">Nama</label>
              <input id="profileNama" name="nama" type="text" class="w-full rounded-lg border h-11 px-3" placeholder="Nama lengkap" required>
            </div>
            <div>
              <label class="block text-sm font-semibold mb-1">Jenjang</label>
              <select id="profileJenjang" name="jenjang" class="w-full rounded-lg border h-11 px-3" required>
                <option value="">- Pilih Jenjang -</option>
                <option value="PAUD">PAUD</option>
                <option value="TK">TK</option>
                <option value="SD/MI">SD/MI</option>
                <option value="SMP/MTs">SMP/MTs</option>
                <option value="SMA/MA">SMA/MA</option>
                <option value="SMK/MAK">SMK/MAK</option>
                <option value="Kesetaraan">Kesetaraan</option>
              </select>
            </div>
            <div>
              <label class="block text-sm font-semibold mb-1">Nama Sekolah</label>
              <input id="profileSekolah" name="nama_sekolah" type="text" class="w-full rounded-lg border h-11 px-3" placeholder="Nama sekolah" required>
            </div>
            <div>
              <label class="block text-sm font-semibold mb-1">No HP</label>
              <input id="profileNoHp" name="no_hp" type="text" class="w-full rounded-lg border h-11 px-3" placeholder="Contoh: 08xxxx / 62xxxx" required>
              <div class="text-xs text-text-sub-light dark:text-text-sub-dark mt-1">Data ini membantu validasi akun dan layanan bantuan jika diperlukan.</div>
            </div>
            <div class="flex items-center justify-end pt-2">
              <button id="profileSaveBtn" class="inline-flex items-center justify-center h-11 px-4 rounded-lg bg-primary hover:bg-blue-600 text-white text-sm font-bold shadow-sm transition-colors">Simpan & Lanjutkan</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <input id="filePicker" type="file" accept="image/*" class="hidden" />
    <input id="logoPicker" type="file" accept="image/*" class="hidden" />
    <input id="projectPicker" type="file" accept=".json" class="hidden" />
    <input id="lkpdImgUpload" type="file" accept="image/*" class="hidden" />
    <input id="lkpdTxtUpload" type="file" accept=".txt,.md,.markdown,.csv,.json,.html,.htm" class="hidden" />
    <input id="topikImgUpload" type="file" accept="image/*" multiple class="hidden" />
    <input id="topikTxtUpload" type="file" accept=".txt,.md,.markdown,.csv,.json,.html,.htm" class="hidden" />
    <input id="topikPdfUpload" type="file" accept="application/pdf" class="hidden" />
    <input id="rosterPicker" type="file" accept=".csv,.txt" class="hidden" />
    <input id="rekapExcelPicker" type="file" accept=".xlsx,.xls" class="hidden" />
    <input id="rekapPrintLogoPicker" type="file" accept="image/*" class="hidden" />

    <script>
      const OPENAI_API_KEY = "";
      const OPENAI_MODEL = "gpt-4o-mini"; // or gpt-3.5-turbo, gpt-4
      const IS_ADMIN = <?php echo (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') ? 'true' : 'false'; ?>;
      const ACCESS_QUIZ = <?php echo (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') ? 'true' : ((isset($_SESSION['access_quiz']) && (int)$_SESSION['access_quiz'] === 0) ? 'false' : 'true'); ?>;
      const ACCESS_REKAP = <?php echo (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') ? 'true' : ((isset($_SESSION['access_rekap_nilai']) && (int)$_SESSION['access_rekap_nilai'] === 0) ? 'false' : 'true'); ?>;
      const ACCESS_BUAT_SOAL = <?php echo (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') ? 'true' : ((isset($_SESSION['access_buat_soal']) && (int)$_SESSION['access_buat_soal'] === 0) ? 'false' : 'true'); ?>;
      const ACCESS_MODUL_AJAR = <?php echo (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') ? 'true' : ((isset($_SESSION['access_modul_ajar']) && (int)$_SESSION['access_modul_ajar'] === 0) ? 'false' : 'true'); ?>;
      const ACCESS_RPP = <?php echo (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') ? 'true' : ((isset($_SESSION['access_rpp']) && (int)$_SESSION['access_rpp'] === 0) ? 'false' : 'true'); ?>;
      const HAS_QUIZ_ACCESS = IS_ADMIN || ACCESS_QUIZ;
      const HAS_REKAP_ACCESS = IS_ADMIN || ACCESS_REKAP;
      const HAS_BUAT_SOAL_ACCESS = IS_ADMIN || ACCESS_BUAT_SOAL;
      const HAS_MODUL_AJAR_ACCESS = IS_ADMIN || ACCESS_MODUL_AJAR;
      const HAS_RPP_ACCESS = IS_ADMIN || ACCESS_RPP;
      const USER_PROFILE = <?php echo json_encode($__userProfile, JSON_UNESCAPED_UNICODE); ?>;
      const LOGIN_NAME = <?php echo json_encode(trim((string)(($_SESSION['nama'] ?? '') !== '' ? $_SESSION['nama'] : ($_SESSION['username'] ?? ''))), JSON_UNESCAPED_UNICODE); ?>;
      const IS_DEMO_USER = <?php echo (trim(strtolower((string)($_SESSION['username'] ?? ''))) === 'coba@gmail.com') ? 'true' : 'false'; ?>;

      const APP_KEY = "soalpintar:v1";
      const OPENAI_TIMEOUT_MS = 55000;
      const GEN_BATCH_SIZE = 10;
      const GEN_MAX_ATTEMPTS = 12;
      const MA_MAX_PERTEMUAN = 4;
      const VIEWS = [
        { id: "preview", label: "Buat Soal", icon: "description" },
        { id: "modul_ajar", label: "Modul Ajar", icon: "menu_book" },
        { id: "rpp", label: "RPP", icon: "event_note" },
        { id: "quiz", label: "Quiz", icon: "quiz" },
        { id: "lkpd", label: "LKPD", icon: "assignment" },
        { id: "rekap", label: "Rekap Nilai", icon: "summarize" },
        { id: "riwayat", label: "Riwayat", icon: "history" },
      ];

      const bloomPresets = {
        level_dasar: { label: "Level Dasar (Dominan C1-C2) - Untuk Remedial/Latihan Awal", codes: ["C1", "C2"] },
        level_standar: { label: "Level Standar (Campuran C1-C4) - Untuk PTS/PAS/Ujian Umum", codes: ["C1", "C2", "C3", "C4"] },
        level_hots: { label: "Level HOTS (Dominan C4-C6) - Untuk Olimpiade/Pengayaan", codes: ["C4", "C5", "C6"] },
        paket_lengkap: { label: "Paket Lengkap (C1-C6) - Merata Seluruh Level", codes: ["C1", "C2", "C3", "C4", "C5", "C6"] },
      };

      const CLASS_OPTIONS = {
        "SD/MI": ["Kelas 1", "Kelas 2", "Kelas 3", "Kelas 4", "Kelas 5", "Kelas 6"],
        "SMP/MTs": ["Kelas 7", "Kelas 8", "Kelas 9"],
        "SMA/MA": ["Kelas 10", "Kelas 11", "Kelas 12"],
        "SMK": ["Kelas 10", "Kelas 11", "Kelas 12"],
        "SMK/MAK": ["Kelas 10", "Kelas 11", "Kelas 12", "Kelas 13"],
        "Paket A": ["Kelas 1", "Kelas 2", "Kelas 3", "Kelas 4", "Kelas 5", "Kelas 6"],
        "Paket B": ["Kelas 7", "Kelas 8", "Kelas 9"],
        "Paket C": ["Kelas 10", "Kelas 11", "Kelas 12"],
        "PAUD": ["Fase Fondasi"],
        "TK": ["Kelompok A", "Kelompok B"],
      };

      const SUBJECT_OPTIONS = {
        "SD/MI": [
          "Pendidikan Agama Islam dan Budi Pekerti",
          "Pendidikan Agama Kristen dan Budi Pekerti",
          "Pendidikan Agama Katolik dan Budi Pekerti",
          "Pendidikan Agama Hindu dan Budi Pekerti",
          "Pendidikan Agama Buddha dan Budi Pekerti",
          "Pendidikan Agama Khonghucu dan Budi Pekerti",
          "Al-Qur'an Hadis",
          "Akidah Akhlak",
          "Fikih",
          "Sejarah Kebudayaan Islam (SKI)",
          "Bahasa Arab",
          "Pendidikan Pancasila",
          "Bahasa Indonesia",
          "Bahasa Indramayu",
          "Bahasa Jawa",
          "Matematika",
          "Ilmu Pengetahuan Alam dan Sosial (IPAS)",
          "Informatika",
          "Koding dan Kecerdasan Artificial",
          "Pendidikan Jasmani, Olahraga, dan Kesehatan (PJOK)",
          "Seni Musik",
          "Seni Rupa",
          "Seni Teater",
          "Seni Tari",
          "Bahasa Inggris",
          "Pendidikan Lingkungan Hidup",
          "Muatan Lokal",
          "Bimbingan Konseling",
        ],
        "SMP/MTs": [
          "Pendidikan Agama Islam dan Budi Pekerti",
          "Pendidikan Agama Kristen dan Budi Pekerti",
          "Pendidikan Agama Katolik dan Budi Pekerti",
          "Pendidikan Agama Hindu dan Budi Pekerti",
          "Pendidikan Agama Buddha dan Budi Pekerti",
          "Pendidikan Agama Khonghucu dan Budi Pekerti",
          "Al-Qur'an Hadis",
          "Akidah Akhlak",
          "Fikih",
          "Sejarah Kebudayaan Islam (SKI)",
          "Bahasa Arab",
          "Pendidikan Pancasila",
          "Bahasa Indonesia",
          "Bahasa Indramayu",
          "Bahasa Jawa",
          "Matematika",
          "Ilmu Pengetahuan Alam (IPA)",
          "Ilmu Pengetahuan Sosial (IPS)",
          "Bahasa Inggris",
          "Pendidikan Jasmani, Olahraga, dan Kesehatan (PJOK)",
          "Informatika",
          "Koding dan Kecerdasan Artificial",
          "Seni Musik",
          "Seni Rupa",
          "Seni Teater",
          "Seni Tari",
          "Prakarya",
          "Pendidikan Lingkungan Hidup",
          "Muatan Lokal",
          "Bimbingan Konseling",
        ],
        "SMA/MA": [
          "Pendidikan Agama Islam dan Budi Pekerti",
          "Pendidikan Agama Kristen dan Budi Pekerti",
          "Pendidikan Agama Katolik dan Budi Pekerti",
          "Pendidikan Agama Hindu dan Budi Pekerti",
          "Pendidikan Agama Buddha dan Budi Pekerti",
          "Pendidikan Agama Khonghucu dan Budi Pekerti",
          "Al-Qur'an Hadis",
          "Akidah Akhlak",
          "Fikih",
          "Sejarah Kebudayaan Islam (SKI)",
          "Bahasa Arab",
          "Pendidikan Pancasila",
          "Bahasa Indonesia",
          "Bahasa Indramayu",
          "Bahasa Jawa",
          "Matematika",
          "Bahasa Inggris",
          "Fisika",
          "Kimia",
          "Biologi",
          "Sejarah",
          "Geografi",
          "Ekonomi",
          "Sosiologi",
          "Antropologi",
          "Informatika",
          "Koding dan Kecerdasan Artificial",
          "Pendidikan Jasmani, Olahraga, dan Kesehatan (PJOK)",
          "Seni Musik",
          "Seni Rupa",
          "Seni Teater",
          "Seni Tari",
          "Prakarya dan Kewirausahaan",
          "Bahasa Asing Lain",
          "Pendidikan Lingkungan Hidup",
          "Muatan Lokal",
          "Bimbingan Konseling",
        ],
        "SMK": [
          "Pendidikan Agama Islam dan Budi Pekerti",
          "Pendidikan Agama Kristen dan Budi Pekerti",
          "Pendidikan Agama Katolik dan Budi Pekerti",
          "Pendidikan Agama Hindu dan Budi Pekerti",
          "Pendidikan Agama Buddha dan Budi Pekerti",
          "Pendidikan Agama Khonghucu dan Budi Pekerti",
          "Bahasa Arab",
          "Sejarah Kebudayaan Islam (SKI)",
          "Pendidikan Pancasila",
          "Bahasa Indonesia",
          "Bahasa Indramayu",
          "Bahasa Jawa",
          "Matematika",
          "Bahasa Inggris",
          "Sejarah",
          "Seni Budaya",
          "Pendidikan Jasmani, Olahraga, dan Kesehatan (PJOK)",
          "Informatika",
          "Koding dan Kecerdasan Artificial",
          "Ilmu Pengetahuan Alam dan Sosial (IPAS)",
          "Dasar-dasar Program Keahlian",
          "Konsentrasi Keahlian",
          "Projek Kreatif dan Kewirausahaan",
          "Pendidikan Lingkungan Hidup",
          "Muatan Lokal",
          "Bimbingan Konseling",
        ],
        "PAUD": [
          "PAUD (Fase Fondasi)",
          "Koding dan Kecerdasan Artificial",
        ],
        "TK": [
          "PAUD (Fase Fondasi)",
          "Koding dan Kecerdasan Artificial",
        ],
      };
      SUBJECT_OPTIONS["SMK/MAK"] = SUBJECT_OPTIONS["SMK"];
      SUBJECT_OPTIONS["Paket A"] = SUBJECT_OPTIONS["SD/MI"];
      SUBJECT_OPTIONS["Paket B"] = SUBJECT_OPTIONS["SMP/MTs"];
      SUBJECT_OPTIONS["Paket C"] = SUBJECT_OPTIONS["SMA/MA"];

      const KES_PAKET_OPTIONS = ["Paket A", "Paket B", "Paket C"];
      const resolveJenjang = (jenjang, kesetaraanPaket) => {
        const j = String(jenjang || "").trim();
        if (j === "Kesetaraan") return String(kesetaraanPaket || "").trim();
        return j;
      };
      const displayJenjang = (jenjang, kesetaraanPaket) => {
        const j = String(jenjang || "").trim();
        if (j !== "Kesetaraan") return j;
        const p = String(kesetaraanPaket || "").trim();
        return p ? `Kesetaraan (${p})` : "Kesetaraan";
      };

      const uuid = () => {
        const c = globalThis.crypto;
        if (c && typeof c.randomUUID === "function") return c.randomUUID();
        const rnds = new Uint8Array(16);
        if (c && typeof c.getRandomValues === "function") c.getRandomValues(rnds);
        else for (let i = 0; i < rnds.length; i++) rnds[i] = Math.floor(Math.random() * 256);
        rnds[6] = (rnds[6] & 0x0f) | 0x40;
        rnds[8] = (rnds[8] & 0x3f) | 0x80;
        const hex = Array.from(rnds, (b) => b.toString(16).padStart(2, "0"));
        return `${hex[0]}${hex[1]}${hex[2]}${hex[3]}-${hex[4]}${hex[5]}-${hex[6]}${hex[7]}-${hex[8]}${hex[9]}-${hex[10]}${hex[11]}${hex[12]}${hex[13]}${hex[14]}${hex[15]}`;
      };

      const DEFAULT_STATE = () => ({
        theme: "light",
        activeView: "preview",
        previewTab: "identitas",
        modulAjarTab: "informasi",
        rppTab: "informasi",
        soalError: null,
        modulAjarError: null,
        _isGenerating: false,
        lkpd: {
          sumber: "topik",
          topik: "",
          materi: "",
          jenjang: "",
          kesetaraanPaket: "",
          fase: "",
          kelas: "",
          mataPelajaran: "",
          jenisAktivitas: "Eksperimen / Praktikum",
          tujuan: "",
          link: "",
        },
        modulAjar: {
          namaGuru: "", institusi: "",
          kurikulum: "Kurikulum Merdeka",
          jenjang: "", fase: "", kelas: "", mapel: "",
          kesetaraanPaket: "",
          judulModul: "", jumlahPertemuan: "2",
          durasi: "50", jumlahSiswa: "30",
          pendekatan: "Standar",
          modelPembelajaran: "Project Based Learning (PjBL)",
          supervisi: false,
          dimensi: [], hasil: "", isGenerating: false, isRefiningKegiatan: false, kegiatanRefinedOnce: false,
        },
        rpp: {
          jenjang: "",
          kesetaraanPaket: "",
          fase: "",
          kelas: "",
          mata_pelajaran: "",
          materi: "",
          kurikulum: "Merdeka",
          pendekatan: "Standar",
          format: "1 lembar",
          alokasi_waktu: "2 x 40 menit",
          nama_sekolah: "",
          nama_guru: "",
          hasil: "",
          isGenerating: false,
        },
        identity: {
          namaGuru: "",
          namaSekolah: "",
          jenjang: "",
          kesetaraanPaket: "",
          fase: "",
          kelas: "",
          mataPelajaran: "",
          topik_raw: "",
          topik_ringkas: "",
          topik: "",
          logo: "",
        },
        paket: {
          judul: "",
          semester: "",
          tahunAjaran: "",
          waktuMenit: 90,
          hariTanggal: "",
        },
        specialInstruction: "",
        previewFlags: { kunci: true, kisi: true },
        sections: [
          {
            id: uuid(),
            judul: "Bagian 1",
            bentuk: "pg",
            opsiPG: 4,
            jumlahPG: 10,
            jumlahIsian: 3,
            tingkatKesulitan: "campuran",
            cakupanBloom: "level_standar",
            dimensi: ["C1", "C2", "C3", "C4"],
            soalKonteks: false,
            pakaiGambar: false,
          },
        ],
        globalSeed: Math.floor(Math.random() * 1e9),
        questions: [],
        quiz: { idx: 0, answered: {}, input: "", reveal: false },
        quizSubtab: "live",
        quizShareTab: "buat_link",
        quizPublishForm: { slug: "", jumlah: 32, expire: "", roster: [] },
        quizLastLink: "",
        quizLastPubId: 0,
        quizLastRoster: [],
        quizLastSlug: "",
        quizPublications: [],
        quizResults: [],
        quizResultsQuery: "",
        quizResultsLoadedAt: "",
        quizSelectedSlug: "",
        quizPreviewCount: 10,
        quizShowPreview: false,
        riwayatKreditSearch: "",
        rekap: { 
          raw: [], 
          data: [], 
          columns: [], 
          bobot: {}, 
          custom: false,
          predikatRules: [
            { grade: 'A', min: 85, max: 100 },
            { grade: 'B', min: 70, max: 84.9 },
            { grade: 'C', min: 55, max: 69.9 },
            { grade: 'D', min: 40, max: 54.9 },
            { grade: 'E', min: 0,  max: 39.9 },
          ],
        },
        creditHistory: [],
        limitInfo: {},
      });

      let state = DEFAULT_STATE();
      const el = (id) => document.getElementById(id);

      const clamp = (n, a, b) => Math.max(a, Math.min(b, n));
      const safeText = (s) =>
        String(s ?? "")
          .replaceAll("&", "&amp;")
          .replaceAll("<", "&lt;")
          .replaceAll(">", "&gt;");
      const safeAttr = (s) =>
        safeText(s)
          .replaceAll('"', "&quot;")
          .replaceAll("'", "&#39;");
      const decodeMaybeUrlText = (s) => {
        let out = String(s ?? "");
        for (let i = 0; i < 2; i++) {
          if (!out.includes("%") && !out.includes("+")) break;
          try {
            const next = decodeURIComponent(out.replaceAll("+", " "));
            if (next === out) break;
            out = next;
          } catch {
            break;
          }
        }
        return out;
      };
      const sectionLetter = (idx) => String.fromCharCode("A".charCodeAt(0) + idx);

      const cp046MapelUi = {
        modulAjar: { open: false, items: [], key: "", abort: null, timer: null, q: "" },
        rpp: { open: false, items: [], key: "", abort: null, timer: null, q: "" },
      };

      const cp046GetCtxMeta = (ctx) => {
        if (ctx === "modulAjar") {
          const M = state.modulAjar || {};
          return {
            jenjang: resolveJenjang(M.jenjang, M.kesetaraanPaket),
            fase: String(M.fase || "").trim(),
          };
        }
        if (ctx === "rpp") {
          const R = state.rpp || {};
          return {
            jenjang: resolveJenjang(R.jenjang, R.kesetaraanPaket),
            fase: String(R.fase || "").trim(),
          };
        }
        return { jenjang: "", fase: "" };
      };

      const cp046MapelDropdownId = (ctx) => {
        if (ctx === "modulAjar") return "cp046MapelDropdown_modulAjar";
        if (ctx === "rpp") return "cp046MapelDropdown_rpp";
        return "";
      };

      const cp046MapelClose = (ctx) => {
        const ui = cp046MapelUi[ctx];
        if (!ui) return;
        ui.open = false;
        ui.items = [];
        const id = cp046MapelDropdownId(ctx);
        const box = id ? document.getElementById(id) : null;
        if (box) {
          box.innerHTML = "";
          box.classList.add("hidden");
        }
      };

      const cp046MapelBlur = (ctx) => {
        cp046MapelClose(ctx);
      };

      const cp046MapelPick = (ctx, label, slug) => {
        cp046MapelClose(ctx);
        if (ctx === "modulAjar") {
          window.__sp.setMA("mapel", label, false);
          window.__sp.setMA("mapel_cp046_slug", slug, true);
          return;
        }
        if (ctx === "rpp") {
          window.__sp.setRPP("mata_pelajaran", label, false);
          window.__sp.setRPP("mapel_cp046_slug", slug, true);
          return;
        }
        render();
      };

      const cp046MapelPickFromEl = (node) => {
        const el = node && node.getAttribute ? node : null;
        if (!el) return;
        const ctx = String(el.getAttribute("data-ctx") || "");
        const label = String(el.getAttribute("data-label") || "");
        const slug = String(el.getAttribute("data-slug") || "");
        cp046MapelPick(ctx, label, slug);
      };

      const cp046MapelRenderBox = (ctx, items) => {
        const ui = cp046MapelUi[ctx];
        if (!ui) return;
        const id = cp046MapelDropdownId(ctx);
        const box = id ? document.getElementById(id) : null;
        if (!box) return;
        box.innerHTML = "";
        const arr = Array.isArray(items) ? items : [];
        if (arr.length === 0) { box.classList.add("hidden"); return; }
        for (const it of arr) {
          const label = String(it?.label || "").trim();
          const slug = String(it?.slug || "").trim();
          if (!label || !slug) continue;
          const btn = document.createElement("button");
          btn.type = "button";
          btn.className = "w-full text-left px-3 py-2 text-sm hover:bg-background-light dark:hover:bg-background-dark";
          btn.setAttribute("data-ctx", ctx);
          btn.setAttribute("data-label", label);
          btn.setAttribute("data-slug", slug);
          btn.textContent = label;
          btn.addEventListener("mousedown", (e) => {
            try { e.preventDefault(); } catch {}
            try { e.stopPropagation(); } catch {}
            window.__sp.cp046MapelPickFromEl(btn);
          });
          box.appendChild(btn);
        }
        box.classList.remove("hidden");
      };

      const cp046MapelInput = async (ctx, q) => {
        const ui = cp046MapelUi[ctx];
        if (!ui) return;
        ui.q = String(q || "");
        const meta = cp046GetCtxMeta(ctx);
        const jenjang = String(meta.jenjang || "").trim();
        const fase = String(meta.fase || "").trim();
        const key = `${jenjang}||${fase}`;
        ui.key = key;
        if (!jenjang || !fase) { cp046MapelClose(ctx); return; }
        if (ui.timer) { try { clearTimeout(ui.timer); } catch {} }
        const key0 = key;
        ui.timer = setTimeout(async () => {
          if (ui.abort) { try { ui.abort.abort(); } catch {} }
          const ctrl = new AbortController();
          ui.abort = ctrl;
          try {
            const resp = await fetch("api/cp046_mapel_search.php", {
              method: "POST",
              headers: { "Content-Type": "application/json" },
              body: JSON.stringify({ jenjang, fase, q: ui.q, limit: 12 }),
              signal: ctrl.signal,
            });
            if (!resp.ok) throw new Error("bad_response");
            const data = await resp.json();
            if (ui.abort !== ctrl) return;
            if (ui.key !== key0) return;
            if (!data?.ok || !Array.isArray(data?.results)) throw new Error("bad_payload");
            ui.items = data.results;
            ui.open = true;
            cp046MapelRenderBox(ctx, ui.items);
          } catch {
            if (ui.abort === ctrl) { ui.items = []; ui.open = false; cp046MapelClose(ctx); }
          }
        }, 220);
      };

      const focusByPath = (path) => {
        const p = String(path || "");
        if (!p) return;
        const esc = (v) => (window.CSS && typeof window.CSS.escape === "function" ? window.CSS.escape(v) : v.replace(/"/g, '\\"'));
        const node = document.querySelector(`[data-path="${esc(p)}"]`);
        if (!node) return;
        try { node.scrollIntoView({ behavior: "smooth", block: "center" }); } catch {}
        try { node.focus({ preventScroll: true }); } catch { try { node.focus(); } catch {} }
      };

      const focusMAKey = (key) => {
        const k = String(key || "");
        if (!k) return;
        if (k === "dimensi") {
          const wrap = document.getElementById("maDimensiWrap");
          if (wrap) {
            try { wrap.scrollIntoView({ behavior: "smooth", block: "center" }); } catch {}
            const first = wrap.querySelector("input[type='checkbox']");
            if (first) { try { first.focus({ preventScroll: true }); } catch { try { first.focus(); } catch {} } }
          }
          return;
        }
        const esc = (v) => (window.CSS && typeof window.CSS.escape === "function" ? window.CSS.escape(v) : v.replace(/"/g, '\\"'));
        const node = document.querySelector(`[data-ma-key="${esc(k)}"]`);
        if (!node) return;
        try { node.scrollIntoView({ behavior: "smooth", block: "center" }); } catch {}
        try { node.focus({ preventScroll: true }); } catch { try { node.focus(); } catch {} }
      };

      const validateBuatSoal = () => {
        const I = state.identity || {};
        const P = state.paket || {};
        const miss = (msg, tab, path) => ({ ok: false, msg, tab, path: path || "" });

        if (!String(I.namaSekolah || "").trim()) return miss("Langkah 1 belum lengkap: isi Nama Sekolah dulu ya.", "identitas", "identity.namaSekolah");
        if (!String(I.jenjang || "").trim()) return miss("Langkah 1 belum lengkap: pilih Jenjang dulu ya.", "identitas", "identity.jenjang");
        if (String(I.jenjang || "").trim() === "Kesetaraan" && !String(I.kesetaraanPaket || "").trim()) {
          return miss("Langkah 1 belum lengkap: pilih Paket Kesetaraan dulu ya.", "identitas", "identity.kesetaraanPaket");
        }
        if (!String(I.fase || "").trim()) return miss("Langkah 1 belum lengkap: pilih Fase dulu ya.", "identitas", "identity.fase");
        if (!String(I.kelas || "").trim()) return miss("Langkah 1 belum lengkap: isi Kelas dulu ya.", "identitas", "identity.kelas");
        if (!String(I.mataPelajaran || "").trim()) return miss("Langkah 1 belum lengkap: pilih Mata Pelajaran dulu ya.", "identitas", "identity.mataPelajaran");
        if (!String(P.judul || "").trim()) return miss("Langkah 1 belum lengkap: isi Judul Paket dulu ya.", "identitas", "paket.judul");
        if (!String(P.tahunAjaran || "").trim()) return miss("Langkah 1 belum lengkap: isi Tahun Ajaran dulu ya.", "identitas", "paket.tahunAjaran");

        const sections = Array.isArray(state.sections) ? state.sections : [];
        if (!sections.length) return miss("Langkah 2 belum lengkap: tambah minimal 1 Bagian di Konfigurasi dulu ya.", "konfigurasi", "");
        const total = sections.reduce((acc, s) => {
          const bentuk = String(s?.bentuk || "");
          const isObjective = ["pg", "benar_salah", "pg_kompleks", "menjodohkan"].includes(bentuk);
          const isEssay = ["isian", "uraian"].includes(bentuk);
          const jumlah = isObjective ? Number(s?.jumlahPG || 0) : isEssay ? Number(s?.jumlahIsian || 0) : 0;
          return acc + (Number.isFinite(jumlah) ? jumlah : 0);
        }, 0);
        if (total <= 0) return miss("Langkah 2 belum lengkap: atur Jumlah Soal dulu ya (minimal 1).", "konfigurasi", "");

        return { ok: true, msg: "", tab: "", path: "" };
      };

      const parseKelasNumber = (raw) => {
        const s = String(raw || '').trim().toUpperCase();
        if (!s) return null;
        const dm = s.match(/\b(1[0-2]|[1-9])\b/);
        if (dm) return Number(dm[1]);
        const rm = s.match(/\b(XII|XI|X|IX|VIII|VII|VI|V|IV|III|II|I)\b/);
        if (!rm) return null;
        const roman = rm[1];
        const map = { I:1, II:2, III:3, IV:4, V:5, VI:6, VII:7, VIII:8, IX:9, X:10, XI:11, XII:12 };
        return map[roman] || null;
      };
      const faseLetterFromLabel = (raw) => {
        const s = String(raw || '').trim();
        const m = s.match(/Fase\s+([A-F])/i);
        return m ? String(m[1]).toUpperCase() : null;
      };
      const expectedFaseLetter = (jenjang, kelasNum) => {
        let j = String(jenjang || '').trim();
        const k = Number(kelasNum || 0);
        if (!k) return null;
        if (j === 'Paket A') j = 'SD/MI';
        if (j === 'Paket B') j = 'SMP/MTs';
        if (j === 'Paket C') j = 'SMA/MA';
        if (j === 'SD/MI') return k <= 2 ? 'A' : (k <= 4 ? 'B' : (k <= 6 ? 'C' : null));
        if (j === 'SMP/MTs') return (k >= 7 && k <= 9) ? 'D' : null;
        if (j === 'SMA/MA') return k === 10 ? 'E' : ((k === 11 || k === 12) ? 'F' : null);
        if (j === 'SMK') return k === 10 ? 'E' : ((k === 11 || k === 12) ? 'F' : ((k >= 7 && k <= 9) ? 'D' : null));
        if (j === 'SMK/MAK') return k === 10 ? 'E' : ((k === 11 || k === 12 || k === 13) ? 'F' : null);
        return null;
      };

      const identityTopikDisplay = (I) => {
        const raw = String(I?.topik_raw || "").replace(/\r\n/g, "\n").replace(/\r/g, "\n").trim();
        if (!raw) return "";
        const oneLine = raw.replace(/\s+/g, " ").trim();
        const stop = new Set([
          "dan","yang","dari","di","ke","untuk","pada","dengan","atau","sebagai","dalam","adalah","yaitu","yakni",
          "ini","itu","tersebut","oleh","para","agar","bagi","tentang","serta","karena","maka","jika","sehingga",
        ]);
        const tokenize = (s) => s
          .split(" ")
          .map(w => w.replace(/^[^\p{L}\p{N}]+|[^\p{L}\p{N}]+$/gu, "").trim())
          .filter(Boolean);
        const words = tokenize(oneLine);
        const filtered = words.filter(w => !stop.has(w.toLowerCase()));
        const pick = (arr) => arr.slice(0, 5).join(" ").trim();
        return pick(filtered.length ? filtered : words);
      };

      const inputText = (label, path, value, placeholder) => `
        <div class="flex flex-col gap-2">
          <label class="text-sm font-semibold text-text-sub-light dark:text-text-sub-dark">${safeText(label)}</label>
          <input
            class="w-full rounded-lg border-border-light dark:border-border-dark bg-background-light dark:bg-background-dark/40 focus:border-primary focus:ring-primary h-11 px-4 text-sm"
            data-path="${safeText(path)}"
            placeholder="${safeText(placeholder || "")}"
            value="${safeText(value ?? "")}"
          />
        </div>
      `;

      const inputTextarea = (label, path, value, placeholder) => `
        <div class="flex flex-col gap-2">
          <label class="text-sm font-semibold text-text-sub-light dark:text-text-sub-dark">${safeText(label)}</label>
          <textarea
            class="w-full rounded-lg border-border-light dark:border-border-dark bg-background-light dark:bg-background-dark/40 focus:border-primary focus:ring-primary px-4 py-3 text-sm min-h-[120px]"
            data-path="${safeText(path)}"
            placeholder="${safeText(placeholder || "")}"
          >${safeText(value ?? "")}</textarea>
        </div>
      `;

      const selectField = (label, path, value, options) => `
        <div class="flex flex-col gap-2">
          <label class="text-sm font-semibold text-text-sub-light dark:text-text-sub-dark">${safeText(label)}</label>
          <select
            class="w-full rounded-lg border-border-light dark:border-border-dark bg-background-light dark:bg-background-dark/40 focus:border-primary focus:ring-primary h-11 px-4 text-sm"
            data-path="${safeText(path)}"
          >
            <option value="">Pilih</option>
            ${options
              .map((o) => `<option value="${safeText(o)}" ${String(o) === String(value || "") ? "selected" : ""}>${safeText(o)}</option>`)
              .join("")}
          </select>
        </div>
      `;

      const summaryRow = (k, v) => `
        <div class="flex items-start justify-between gap-4 text-sm">
          <div class="text-text-sub-light dark:text-text-sub-dark">${safeText(k)}</div>
          <div class="text-right font-semibold">${safeText(v)}</div>
        </div>
      `;

      const computeStats = async () => {
        return;
      };

      const openUsagePolicy = () => {
        const m = el("usagePolicyModal");
        if (!m) return;
        m.classList.remove("hidden");
        m.style.display = "flex";
      };
      const closeUsagePolicy = (ack = false) => {
        const m = el("usagePolicyModal");
        if (m) {
          m.classList.add("hidden");
          m.style.display = "none";
        }
        if (ack) {
          try { localStorage.setItem(APP_KEY + ":usage_policy_ack", "1"); } catch {}
        }
      };
      const ensureUsagePolicyAck = () => {
        try {
          const ok = localStorage.getItem(APP_KEY + ":usage_policy_ack");
          if (ok === "1") return;
        } catch {}
        openUsagePolicy();
      };

      const setView = (id) => {
        state.activeView = id;
        if (window.innerWidth < 1024) {
          const sb = document.getElementById("mainSidebar");
          if (sb) {
            sb.classList.add("hidden");
            sb.classList.remove("flex");
          }
          try { window.scrollTo({ top: 0, behavior: "smooth" }); } catch {}
        }
        saveDebounced(true);
        render();
      };
      const setPreviewTab = (tab) => {
        state.previewTab = tab;
        saveDebounced(false);
        render();
      };
      const startBuildSoal = () => {
        const chk = validateBuatSoal();
        if (!chk.ok) {
          state.soalError = { tab: chk.tab, msg: chk.msg, path: chk.path };
          state.previewTab = chk.tab;
          saveDebounced(false);
          render();
          setTimeout(() => { try { focusByPath(chk.path); } catch {} }, 50);
          return;
        }
        state.soalError = null;
        state.previewTab = "naskah";
        saveDebounced(false);
        buildPackage();
      };
      const openNaskahSoalFromKonfigurasi = () => {
        const hasSoal = Array.isArray(state.questions) && state.questions.length > 0;
        if (hasSoal) {
          state.soalError = null;
          state.previewTab = "naskah";
          saveDebounced(false);
          render();
          return;
        }
        startBuildSoal();
      };
      const downloadSoalDocx = async () => {
        if (!Array.isArray(state.questions) || state.questions.length === 0) return;
        const btn = document.getElementById("btnSoalDocxTop");
        const orig = btn?.innerHTML;
        try { if (btn) { btn.disabled = true; btn.innerHTML = `<span class="animate-spin material-symbols-outlined text-[18px]">progress_activity</span>`; } } catch {}
        try { await exportDocx(); } catch {}
        await new Promise((r) => setTimeout(r, 300));
        try { await exportKunciDocx(); } catch {}
        await new Promise((r) => setTimeout(r, 300));
        try { await exportKisiDocx(); } catch {}
        try { if (btn) { btn.disabled = false; btn.innerHTML = orig || "Download .docx"; } } catch {}
      };
      const downloadSoalPDF = () => {
        if (!Array.isArray(state.questions) || state.questions.length === 0) return;
        (async () => {
          const btn = document.getElementById("btnSoalPdfTop");
          const orig = btn?.innerHTML;
          try { if (btn) { btn.disabled = true; btn.innerHTML = `<span class="animate-spin material-symbols-outlined text-[18px]">progress_activity</span>`; } } catch {}
          try {
            await exportSoalPDF();
          } catch {
            alert("Gagal mengunduh PDF. Silakan coba lagi.");
          } finally {
            try { if (btn) { btn.disabled = false; btn.innerHTML = orig || "Download PDF"; } } catch {}
          }
        })();
      };

      async function exportSoalPDF() {
        if (!Array.isArray(state.questions) || state.questions.length === 0) return;
        await ensureJsPDF();
        const { jsPDF } = window.jspdf;

        const safe = `${String(state.identity.mataPelajaran || "Mapel").replace(/\s+/g, "_")}_${String(state.identity.kelas || "Kelas").replace(/\s+/g, "_")}_${String(state.paket.judul || "Paket").replace(/[\s/]+/g, "_")}`;
        const fileSoal = `Soal_${safe}.pdf`;
        const fileKunci = `Kunci_${safe}.pdf`;
        const fileKisi = `KisiKisi_${safe}.pdf`;

        const makeDoc = () => {
          const doc = new jsPDF("p", "pt", "a4");
          const pageW = doc.internal.pageSize.getWidth();
          const pageH = doc.internal.pageSize.getHeight();
          const margin = 40;
          const footerY = pageH - 22;
          const maxW = pageW - margin * 2;
          let y = margin;
          const newPage = () => { doc.addPage(); y = margin; };
          const newPageIfNeeded = (h) => { if (y + h > footerY - 10) newPage(); };
          const setFont = (style = "normal", size = 11) => { doc.setFont("times", style); doc.setFontSize(size); };
          const addCenter = (text, size = 14, style = "bold", after = 6) => {
            setFont(style, size);
            const t = String(text || "");
            const lines = doc.splitTextToSize(t, maxW);
            const lineH = size + 4;
            newPageIfNeeded(lines.length * lineH + after);
            doc.text(lines, pageW / 2, y, { align: "center" });
            y += lines.length * lineH + after;
          };
          const addPara = (text, size = 11, style = "normal", indent = 0, after = 6) => {
            setFont(style, size);
            const t = String(text || "");
            const lines = doc.splitTextToSize(t, maxW - indent);
            const lineH = size + 4;
            newPageIfNeeded(lines.length * lineH + after);
            doc.text(lines, margin + indent, y);
            y += lines.length * lineH + after;
          };
          const addHanging = (prefix, text, size = 12, style = "normal", indent = 0, after = 6) => {
            setFont(style, size);
            const p = String(prefix || "");
            const t = String(text || "");
            const px = margin + indent;
            const pw = doc.getTextWidth(p + " ");
            const avail = Math.max(40, maxW - indent - pw);
            const lines = doc.splitTextToSize(t, avail);
            const lineH = size + 4;
            newPageIfNeeded(lines.length * lineH + after);
            doc.text(p, px, y);
            doc.text(lines[0] || "", px + pw, y);
            for (let i = 1; i < lines.length; i++) doc.text(lines[i], px + pw, y + lineH * i);
            y += lines.length * lineH + after;
          };
          const drawDottedLine = (x1, x2, yy) => {
            doc.setDrawColor(0);
            doc.setLineWidth(0.8);
            try { doc.setLineDashPattern([1.2, 2.2], 0); } catch {}
            doc.line(x1, yy, x2, yy);
            try { doc.setLineDashPattern([], 0); } catch {}
          };
          const drawHeader = (title, kind) => {
            y = margin;
            addCenter(String(state.identity.namaSekolah || "NAMA SEKOLAH").toUpperCase(), 16, "bold", 4);
            addCenter(String(title || "").toUpperCase(), 13, "bold", 6);
            addCenter(`Tahun Pelajaran ${String(state.paket.tahunAjaran || "-")}`, 11, "normal", 16);

            const logo = state.identity.logo || "";
            if (logo) {
              try {
                const imgType = String(logo).toLowerCase().includes("jpeg") ? "JPEG" : "PNG";
                const w = 80, h = 40;
                doc.addImage(String(logo), imgType, pageW - margin - w, margin - 6, w, h);
              } catch {}
            }

            const labelW = 140;
            const colGap = 40;
            const colW = (maxW - colGap) / 2;
            const leftX = margin;
            const rightX = margin + colW + colGap;
            const colonX = labelW;
            const valX = colonX + 12;
            const rowH = 18;
            const topikDisplay = identityTopikDisplay(state.identity);
            const isSpesifik = !!topikDisplay;

            const leftRows = [
              ["Mata Pelajaran", String(state.identity.mataPelajaran || "-"), "text"],
              ["Kelas / Fase", `${String(state.identity.kelas || "-")} / ${String(state.identity.fase || "-")}`, "text"],
              ...(isSpesifik ? [["Topik / Lingkup Materi", String(topikDisplay || "-"), "text"]] : []),
              ...(kind === "soal" ? [["Hari / Tanggal", "", "line"]] : []),
            ];
            const rightRows = kind === "soal"
              ? [
                  ["Waktu", "", "line"],
                  ["Nama", "", "line"],
                  ["No. Absen", "", "line"],
                ]
              : [
                  ["Kurikulum", "Merdeka", "text"],
                  ["Jumlah Soal", String((state.questions || []).length), "text"],
                ];

            const rowCount = Math.max(leftRows.length, rightRows.length);
            for (let i = 0; i < rowCount; i++) {
              const l = leftRows[i] || ["", "", "text"];
              const r = rightRows[i] || ["", "", "text"];
              newPageIfNeeded(80);
              setFont("normal", 11);

              const drawCol = (baseX, row) => {
                const [label, value, mode] = row;
                if (!label) return 1;
                doc.text(String(label), baseX, y);
                doc.text(":", baseX + colonX, y);
                if (mode === "line") {
                  drawDottedLine(baseX + valX, baseX + colW, y + 3);
                  return 1;
                }
                const lines = doc.splitTextToSize(String(value || ""), colW - valX);
                doc.text(lines, baseX + valX, y);
                return Math.max(1, lines.length);
              };

              const lLines = drawCol(leftX, l);
              const rLines = drawCol(rightX, r);
              y += rowH * Math.max(lLines, rLines);
            }

            y += 6;
            doc.setDrawColor(0);
            doc.setLineWidth(1.2);
            doc.line(margin, y, pageW - margin, y);
            y += 22;
          };
          return { doc, pageW, pageH, margin, footerY, maxW, getY: () => y, setY: (v) => { y = v; }, newPage, newPageIfNeeded, addCenter, addPara, addHanging, drawHeader };
        };

        const cp046PagesForKisi = (() => {
          const pages = state?.cp046?.soal?.pages;
          const arr = Array.isArray(pages) ? pages.map(x => Number(x)).filter(x => Number.isFinite(x) && x > 0) : [];
          if (!arr.length) return "";
          arr.sort((a,b)=>a-b);
          return arr.join(", ");
        })();

        const items0 = Array.isArray(state.questions) ? state.questions : [];
        const imgCache = new Map();
        const blobToDataUrl = (blob) => new Promise((resolve) => {
          try {
            const fr = new FileReader();
            fr.onload = () => resolve(String(fr.result || ""));
            fr.onerror = () => resolve("");
            fr.readAsDataURL(blob);
          } catch { resolve(""); }
        });
        const getImgDimensions = (dataUrl) => new Promise((resolve) => {
          try {
            const img = new Image();
            img.onload = () => resolve({ w: Number(img.naturalWidth || img.width || 0) || 0, h: Number(img.naturalHeight || img.height || 0) || 0 });
            img.onerror = () => resolve({ w: 0, h: 0 });
            img.src = dataUrl;
          } catch { resolve({ w: 0, h: 0 }); }
        });
        const loadPdfImage = async (src) => {
          const s = String(src || "").trim();
          if (!s) return null;
          if (imgCache.has(s)) return imgCache.get(s);
          let dataUrl = "";
          try {
            if (/^data:image\//i.test(s)) dataUrl = s;
            else {
              const resp = await fetch(s);
              const blob = await resp.blob();
              dataUrl = await blobToDataUrl(blob);
            }
          } catch { dataUrl = ""; }
          if (!dataUrl) { imgCache.set(s, null); return null; }
          const dim = await getImgDimensions(dataUrl);
          const format = /^data:image\/jpeg/i.test(dataUrl) ? "JPEG" : "PNG";
          const out = { dataUrl, format, w: dim.w || 300, h: dim.h || 300 };
          imgCache.set(s, out);
          return out;
        };
        const items = await Promise.all(items0.map(async (q) => {
          if (!q || !q.image) return q;
          const info = await loadPdfImage(q.image);
          if (!info) return q;
          return { ...q, _pdfImg: info };
        }));
        const order = ["pg", "benar_salah", "pg_kompleks", "menjodohkan", "isian", "uraian"];
        const titleMap = { pg: "PILIHAN GANDA", benar_salah: "BENAR / SALAH", pg_kompleks: "PILIHAN GANDA KOMPLEKS", menjodohkan: "MENJODOHKAN", isian: "ISIAN SINGKAT", uraian: "URAIAN" };
        const subtitleMap = {
          pg: "Pilihlah salah satu jawaban yang paling tepat!",
          benar_salah: "Pilihlah jawaban Benar atau Salah!",
          pg_kompleks: "Pilihlah jawaban yang benar (bisa lebih dari satu)!",
          menjodohkan: "Jodohkanlah pernyataan pada lajur kiri dengan jawaban pada lajur kanan!",
          isian: "Jawablah pertanyaan berikut dengan singkat dan tepat!",
          uraian: "Jawablah pertanyaan-pertanyaan berikut dengan jelas dan benar!",
        };

        const buildSoalPdf = () => {
          const ctx = makeDoc();
          ctx.drawHeader(String(state.paket.judul || "NASKAH SOAL"), "soal");

          let firstTypeRendered = false;
          for (const t of order) {
            const qs = items.filter((q) => q && q.type === t);
            if (!qs.length) continue;

            const chunks = [];
            for (let i = 0; i < qs.length; i += 10) chunks.push(qs.slice(i, i + 10));
            chunks.forEach((chunk, chunkIdx) => {
              const needPageBreak = firstTypeRendered || chunkIdx > 0;
              if (needPageBreak) ctx.newPage();

              if (chunkIdx === 0) {
                ctx.addPara(`${titleMap[t]}`, 12, "bold", 0, 4);
                ctx.addPara(subtitleMap[t], 11, "italic", 0, 14);
              }

              const startIndex = chunkIdx * 10;
              const normKey = (t) => String(t || '').replace(/\r\n/g, '\n').replace(/\r/g, '\n').replace(/\s+/g, ' ').trim();
              const renderOne = (q, num) => {
                ctx.addHanging(`${num}.`, String(q.question || "").trim(), 12, "normal", 0, 8);
                if (q._pdfImg) {
                  try {
                    const img = q._pdfImg;
                    const x = ctx.margin + 30;
                    const maxWimg = Math.max(120, ctx.maxW - 60);
                    const ratio = Math.max(0.05, Number(img.w || 300) / Math.max(1, Number(img.h || 300)));
                    let w = maxWimg;
                    let h = Math.max(1, Math.round(w / ratio));
                    const maxHimg = 220;
                    if (h > maxHimg) {
                      h = maxHimg;
                      w = Math.max(1, Math.round(h * ratio));
                    }
                    ctx.newPageIfNeeded(h + 14);
                    ctx.doc.addImage(String(img.dataUrl), String(img.format), x, ctx.getY(), w, h);
                    ctx.setY(ctx.getY() + h + 10);
                  } catch {}
                }

                if (q.type === "pg" || q.type === "benar_salah" || q.type === "pg_kompleks") {
                  const opts = Array.isArray(q.options) ? q.options : [];
                  for (let oi = 0; oi < opts.length; oi++) {
                    ctx.addHanging(`${String.fromCharCode(65 + oi)}.`, String(opts[oi] || ""), 11, "normal", 30, 4);
                  }
                  ctx.setY(ctx.getY() + 6);
                } else if (q.type === "menjodohkan") {
                  const leftList = Array.isArray(q.options) ? q.options : [];
                  const rightList = Array.isArray(q.answer) ? q.answer : [];
                  const leftText = ["Lajur A (Pernyataan)", ...leftList.map((x, idx) => `${idx + 1}. ${String(x ?? "")}`)].join("\n");
                  const rightText = ["Lajur B (Jawaban)", ...rightList.map((x, idx) => `${String.fromCharCode(65 + idx)}. ${String(x ?? "")}`)].join("\n");
                  ctx.newPageIfNeeded(180);
                  ctx.doc.autoTable({
                    startY: ctx.getY(),
                    margin: { left: ctx.margin, right: ctx.margin },
                    body: [[leftText, rightText]],
                    theme: "grid",
                    styles: { font: "times", fontSize: 10, textColor: [0, 0, 0], cellPadding: 6, lineWidth: 0.6, lineColor: [0, 0, 0], overflow: "linebreak", valign: "top" },
                    columnStyles: { 0: { cellWidth: ctx.maxW / 2 }, 1: { cellWidth: ctx.maxW / 2 } },
                  });
                  ctx.setY((ctx.doc.lastAutoTable?.finalY || ctx.getY()) + 26);
                } else if (q.type === "isian") {
                  ctx.addPara("Jawaban:", 11, "normal", 30, 2);
                  ctx.doc.setDrawColor(0);
                  ctx.doc.setLineWidth(0.8);
                  try { ctx.doc.setLineDashPattern([1.2, 2.2], 0); } catch {}
                  const y0 = ctx.getY() + 6;
                  ctx.doc.line(ctx.margin + 90, y0, ctx.pageW - ctx.margin, y0);
                  try { ctx.doc.setLineDashPattern([], 0); } catch {}
                  ctx.setY(ctx.getY() + 16);
                } else if (q.type === "uraian") {
                  for (let k = 0; k < 4; k++) {
                    ctx.newPageIfNeeded(18);
                    ctx.doc.setDrawColor(0);
                    ctx.doc.setLineWidth(0.8);
                    try { ctx.doc.setLineDashPattern([1.2, 2.2], 0); } catch {}
                    const yy = ctx.getY() + 10;
                    ctx.doc.line(ctx.margin + 30, yy, ctx.pageW - ctx.margin, yy);
                    try { ctx.doc.setLineDashPattern([], 0); } catch {}
                    ctx.setY(ctx.getY() + 18);
                  }
                  ctx.setY(ctx.getY() + 8);
                }
              };

              for (let i = 0; i < chunk.length; i++) {
                const q = chunk[i] || {};
                const qCtx = String(q.context || "").replace(/\r\n/g, "\n").replace(/\r/g, "\n").trim();
                if (qCtx) {
                  const key = normKey(qCtx);
                  let j = i;
                  while (j + 1 < chunk.length) {
                    const nxt = chunk[j + 1] || {};
                    const nxtCtx = String(nxt.context || "").replace(/\r\n/g, "\n").replace(/\r/g, "\n").trim();
                    if (!nxtCtx) break;
                    if (normKey(nxtCtx) !== key) break;
                    j++;
                  }
                  const a = startIndex + i + 1;
                  const b = startIndex + j + 1;
                  const rangeText = a === b ? `nomor ${a}` : `nomor ${a} s.d. ${b}`;
                  ctx.addPara(`Untuk menjawab soal ${rangeText}, pahami bacaan berikut.`, 11, "italic", 0, 6);
                  const paras = qCtx.split(/\n\s*\n/).map(s => String(s || "").trim()).filter(Boolean);
                  for (const p of paras) ctx.addPara(p, 11, "normal", 20, 6);
                  for (let k = i; k <= j; k++) renderOne({ ...(chunk[k] || {}), context: "" }, startIndex + k + 1);
                  i = j;
                  continue;
                }
                renderOne(q, startIndex + i + 1);
              }

              firstTypeRendered = true;
            });
          }

          const totalPages = ctx.doc.getNumberOfPages();
          for (let p = 1; p <= totalPages; p++) {
            ctx.doc.setPage(p);
            ctx.doc.setFont("times", "normal");
            ctx.doc.setFontSize(9);
            ctx.doc.setTextColor(0, 0, 0);
            const footer = `${String(state.identity.mataPelajaran || "")} — ${String(state.identity.namaSekolah || "")} | Halaman ${p}`;
            ctx.doc.text(footer, ctx.pageW / 2, ctx.footerY, { align: "center" });
          }
          ctx.doc.setTextColor(0, 0, 0);
          return ctx.doc;
        };

        const buildKunciPdf = () => {
          const ctx = makeDoc();
          ctx.drawHeader("KUNCI JAWABAN", "meta");

          const sections = [
            { type: "pg", title: "PILIHAN GANDA" },
            { type: "benar_salah", title: "BENAR / SALAH" },
            { type: "pg_kompleks", title: "PILIHAN GANDA KOMPLEKS" },
            { type: "menjodohkan", title: "MENJODOHKAN" },
            { type: "isian", title: "ISIAN SINGKAT" },
            { type: "uraian", title: "URAIAN" },
          ];
          let sectionIndex = 0;
          for (const sec of sections) {
            const qs = items.filter((q) => q && q.type === sec.type);
            if (!qs.length) continue;
            const letter = String.fromCharCode(65 + sectionIndex++);
            ctx.addPara(`${letter}. ${sec.title}`, 12, "bold", 0, 10);

            if (sec.type === "pg" || sec.type === "benar_salah") {
              const cols = 5;
              const body = [];
              for (let i = 0; i < qs.length; i += cols) {
                const row = [];
                for (let j = 0; j < cols; j++) {
                  const q = qs[i + j];
                  if (!q) { row.push(""); continue; }
                  let ansChar = "-";
                  if (sec.type === "benar_salah") {
                    const idx = Number(q.answer);
                    ansChar = idx === 1 ? "Salah" : "Benar";
                  } else if (typeof q.answer === "number") {
                    ansChar = String.fromCharCode(65 + q.answer);
                  }
                  else if (typeof q.answer === "string") ansChar = q.answer;
                  row.push(`${i + j + 1}. ${ansChar}`);
                }
                body.push(row);
              }
              ctx.doc.autoTable({
                startY: ctx.getY(),
                margin: { left: ctx.margin, right: ctx.margin },
                body,
                theme: "plain",
                styles: { font: "times", fontSize: 11, textColor: [0, 0, 0], cellPadding: 2, lineWidth: 0 },
                columnStyles: { 0: { cellWidth: ctx.maxW / 5 }, 1: { cellWidth: ctx.maxW / 5 }, 2: { cellWidth: ctx.maxW / 5 }, 3: { cellWidth: ctx.maxW / 5 }, 4: { cellWidth: ctx.maxW / 5 } },
              });
              ctx.setY((ctx.doc.lastAutoTable?.finalY || ctx.getY()) + 14);
              continue;
            }

            for (let i = 0; i < qs.length; i++) {
              const q = qs[i] || {};
              let ansText = "";
              if (sec.type === "pg_kompleks") ansText = Array.isArray(q.answer) ? q.answer.map((idx) => String.fromCharCode(65 + Number(idx))).join(", ") : String(q.answer ?? "");
              else if (sec.type === "menjodohkan") ansText = Array.isArray(q.matchKey) ? q.matchKey.map((pos, idx) => `${idx + 1}–${String.fromCharCode(65 + Number(pos || 0))}`).join(", ") : "";
              else ansText = String(q.answer || "(Belum ada kunci)");
              ctx.addHanging(`${i + 1}.`, ansText, 11, "normal", 0, 4);
            }
            ctx.setY(ctx.getY() + 14);
          }

          const totalPages = ctx.doc.getNumberOfPages();
          for (let p = 1; p <= totalPages; p++) {
            ctx.doc.setPage(p);
            ctx.doc.setFont("times", "normal");
            ctx.doc.setFontSize(9);
            ctx.doc.setTextColor(0, 0, 0);
            const footer = `${String(state.identity.mataPelajaran || "")} — ${String(state.identity.namaSekolah || "")} | Halaman ${p}`;
            ctx.doc.text(footer, ctx.pageW / 2, ctx.footerY, { align: "center" });
          }
          ctx.doc.setTextColor(0, 0, 0);
          return ctx.doc;
        };

        const buildKisiPdf = () => {
          const ctx = makeDoc();
          ctx.drawHeader("KISI-KISI SOAL", "meta");

          const kisiSections = [
            { type: "pg", title: "PILIHAN GANDA", label: "PG" },
            { type: "benar_salah", title: "BENAR / SALAH", label: "B/S" },
            { type: "pg_kompleks", title: "PILIHAN GANDA KOMPLEKS", label: "PG Komp" },
            { type: "menjodohkan", title: "MENJODOHKAN", label: "Jodoh" },
            { type: "isian", title: "ISIAN SINGKAT", label: "Isian" },
            { type: "uraian", title: "URAIAN", label: "Uraian" },
          ];
          let sectionIndex = 0;
          for (const sec of kisiSections) {
            const qs = items.filter((q) => q && q.type === sec.type);
            if (!qs.length) continue;
            const letter = String.fromCharCode(65 + sectionIndex++);
            ctx.addPara(`${letter}. ${sec.title}`, 12, "bold", 0, 14);
            const rows = qs.map((q, idx) => [String(idx + 1), String(q.materi || "-"), String(q.indikator || "-"), String(q.bloom || "-"), String(sec.label), String(idx + 1)]);
            ctx.doc.autoTable({
              startY: ctx.getY(),
              margin: { left: ctx.margin, right: ctx.margin },
              head: [["No", "Materi", "Indikator Soal", "Level", "Bentuk", "No. Soal"]],
              body: rows,
              styles: { font: "times", fontSize: 10, textColor: [0, 0, 0], cellPadding: 4, lineWidth: 0.6, lineColor: [0, 0, 0], overflow: "linebreak" },
              headStyles: { fillColor: [224, 224, 224], textColor: [0, 0, 0], fontStyle: "bold", halign: "center" },
              bodyStyles: { fontStyle: "bold" },
              columnStyles: {
                0: { cellWidth: 34, halign: "center" },
                1: { cellWidth: 110 },
                2: { cellWidth: ctx.maxW - 34 - 110 - 60 - 60 - 60 },
                3: { cellWidth: 60, halign: "center" },
                4: { cellWidth: 60, halign: "center" },
                5: { cellWidth: 60, halign: "center" },
              },
            });
            ctx.setY((ctx.doc.lastAutoTable?.finalY || ctx.getY()) + 26);
          }

          const totalPages = ctx.doc.getNumberOfPages();
          for (let p = 1; p <= totalPages; p++) {
            ctx.doc.setPage(p);
            ctx.doc.setFont("times", "normal");
            ctx.doc.setFontSize(9);
            ctx.doc.setTextColor(0, 0, 0);
            if (cp046PagesForKisi) {
              ctx.doc.text(`Catatan: sesuai dengan CP046 hal. ${cp046PagesForKisi}`, ctx.pageW / 2, ctx.footerY - 12, { align: "center" });
            }
            const footer = `${String(state.identity.mataPelajaran || "")} — ${String(state.identity.namaSekolah || "")} | Halaman ${p}`;
            ctx.doc.text(footer, ctx.pageW / 2, ctx.footerY, { align: "center" });
          }
          ctx.doc.setTextColor(0, 0, 0);
          return ctx.doc;
        };

        const docSoal = buildSoalPdf();
        docSoal.save(fileSoal);
        await new Promise((r) => setTimeout(r, 300));
        const docKunci = buildKunciPdf();
        docKunci.save(fileKunci);
        await new Promise((r) => setTimeout(r, 300));
        const docKisi = buildKisiPdf();
        docKisi.save(fileKisi);
      }
      const setModulAjarTab = (tab) => {
        state.modulAjarTab = tab;
        saveDebounced(false);
        render();
      };
      const openModulAjarFromDetail = () => {
        if (!state.modulAjar) state.modulAjar = {};
        const M = state.modulAjar || {};
        const hasilAda = !!M.hasil;
        if (hasilAda || M.isGenerating) {
          state.modulAjarError = null;
          state.modulAjarTab = "modul";
          saveDebounced(false);
          render();
          return;
        }
        buildModulAjar();
      };
      function logCreditUsage(kind, cost, detail) {
        const rec = { ts: new Date().toISOString(), kind, cost: Number(cost)||0, detail: String(detail||'') };
        state.creditHistory = Array.isArray(state.creditHistory) ? [rec, ...state.creditHistory].slice(0, 200) : [rec];
        saveDebounced(true);
      }
      async function refreshCreditLimit(doRender = false) {
        try {
          const r = await fetch("api/openai_proxy.php", { method:"POST", headers:{"Content-Type":"application/json"}, credentials:"same-origin", body: JSON.stringify({ type:"get_limits" }) });
          if (r.ok) {
            const j = await r.json();
            state.limitInfo = j || {};
            saveDebounced(false);
            if (doRender) render();
          }
        } catch {}
      }
      async function loadLimitConfig() {
        if (!IS_ADMIN) return;
        try {
          const r = await fetch('api/limit_config_get.php', { credentials:'same-origin' });
          if (r.ok) {
            const j = await r.json();
            if (j && j.ok) {
              state.limitConfig = { costs: j.costs || {}, initial_limit: j.initial_limit || 0 };
              saveDebounced(false);
            }
          }
        } catch {}
      }
      async function saveLimitConfig() {
        if (!IS_ADMIN) return;
        try {
          const payload = { 
            costs: state.limitConfig?.costs || {}, 
            initial_limit: Number(state.limitConfig?.initial_limit||0) 
          };
          const r = await fetch('api/limit_config_set.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload), credentials:'same-origin' });
          if (r.ok) {
            await loadLimitConfig();
            alert('Pengaturan limit tersimpan.');
          } else {
            alert('Gagal menyimpan pengaturan.');
          }
        } catch { alert('Gagal menyimpan pengaturan.'); }
      }

      const ensureXLSX = () => {
        return new Promise((resolve) => {
          if (window.XLSX) return resolve();
          const s = document.createElement('script');
          s.src = 'https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js';
          s.onload = () => resolve();
          document.head.appendChild(s);
        });
      };
      const ensureJsPDF = () => {
        return new Promise((resolve) => {
          if (window.jspdf && window.jspdf.jsPDF && window.jspdf_autotable) return resolve();
          const s1 = document.createElement('script');
          s1.src = 'https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js';
          s1.onload = () => {
            const s2 = document.createElement('script');
            s2.src = 'https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js';
            s2.onload = () => resolve();
            document.head.appendChild(s2);
          };
          document.head.appendChild(s1);
        });
      };
      const ensureHtml2Pdf = () => {
        return new Promise((resolve) => {
          if (window.html2pdf) return resolve();
          const s = document.createElement('script');
          s.src = 'https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js';
          s.onload = () => resolve();
          document.head.appendChild(s);
        });
      };
      function rekapPredikat(n) {
        let rules = (state.rekap && Array.isArray(state.rekap.predikatRules)) ? state.rekap.predikatRules : [];
        // Abaikan predikat nonaktif (0-0) jika tersimpan
        rules = rules.filter(r => !(Number(r.min)===0 && Number(r.max)===0));
        for (const r of rules) {
          const min = Number(r.min); const max = Number(r.max);
          if (n >= min && n <= max) return String(r.grade || '').toUpperCase();
        }
        if (rules.length) {
          const lowest = rules.reduce((acc, r) => (r.min < acc.min ? r : acc), rules[0]);
          return String(lowest.grade || 'E').toUpperCase();
        }
        return 'E';
      }
      function rekapRenderTable() {
        const head = el('rekapHeadRow');
        const tbody = el('rekapTBody');
        if (!head || !tbody) return;
        if (!state.rekap || state.rekap.data.length === 0) {
          head.innerHTML = `
            <th class="border px-3 py-2">No</th>
            <th class="border px-3 py-2 text-left">Nama Siswa</th>
            <th class="border px-3 py-2">Kelas</th>
            <th class="border px-3 py-2">Nilai Akhir</th>
            <th class="border px-3 py-2">Predikat</th>
            <th class="border px-3 py-2">Ranking</th>
          `;
          tbody.innerHTML = `<tr><td colspan="6" class="border px-3 py-6 text-center text-text-sub-light">Belum ada data.</td></tr>`;
          const info1 = el('rekapTotalRows'); const info2 = el('rekapTotalData');
          if (info1) info1.textContent = '0';
          if (info2) info2.textContent = '0';
          return;
        }
        let header = `
          <th class="border px-3 py-2">No</th>
          <th class="border px-3 py-2 text-left">Nama Siswa</th>
          <th class="border px-3 py-2">Kelas</th>
        `;
        state.rekap.columns.forEach(c => { header += `<th class="border px-3 py-2">${safeText(c)}</th>`; });
        header += `
          <th class="border px-3 py-2">Nilai Akhir</th>
          <th class="border px-3 py-2">Predikat</th>
          <th class="border px-3 py-2">Ranking</th>
        `;
        head.innerHTML = header;
        const rows = state.rekap.data.map((r, idx) => {
          const cls = r.nilaiAkhir >= 85 ? 'bg-green-100 text-green-700' : (r.nilaiAkhir < 60 ? 'bg-red-100 text-red-700' : '');
          let cells = '';
          state.rekap.columns.forEach(c => { cells += `<td class="border px-3 py-2 text-center">${Number(r[c] || 0)}</td>`; });
          const trophy = r.ranking <= 3 ? `<span class="material-symbols-outlined text-amber-500 align-middle">trophy</span> ${r.ranking}` : r.ranking;
          return `
            <tr>
              <td class="border px-3 py-2 text-center">${idx + 1}</td>
              <td class="border px-3 py-2"><strong>${safeText(r.nama)}</strong></td>
              <td class="border px-3 py-2 text-center">${safeText(r.kelas)}</td>
              ${cells}
              <td class="border px-3 py-2 text-center"><span class="px-2 py-1 rounded ${cls}">${r.nilaiAkhir}</span></td>
              <td class="border px-3 py-2 text-center">${r.predikat}</td>
              <td class="border px-3 py-2 text-center">${trophy}</td>
            </tr>
          `;
        }).join('');
        tbody.innerHTML = rows;
        const info1 = el('rekapTotalRows'); const info2 = el('rekapTotalData');
        if (info1) info1.textContent = String(state.rekap.data.length);
        if (info2) info2.textContent = String(state.rekap.data.length);
        rekapUpdateStats();
      }
      function rekapUpdateStats() {
        const arr = state.rekap?.data?.map(x=>Number(x.nilaiAkhir)||0) || [];
        const max = arr.length ? Math.max(...arr) : 0;
        const min = arr.length ? Math.min(...arr) : 0;
        const avg = arr.length ? Math.round((arr.reduce((a,b)=>a+b,0)/arr.length)*10)/10 : 0;
        const m = el('rekapMax'); const n = el('rekapMin'); const a = el('rekapAvg');
        if (m) m.textContent = String(max);
        if (n) n.textContent = String(min);
        if (a) a.textContent = String(avg);
      }
      function rekapProcessData(data) {
        const ex = ['no','nama siswa','nama','kelas'];
        const first = data[0] || {};
        const cols = Object.keys(first).filter(k=>!ex.includes(String(k).toLowerCase().trim()));
        if (!state.rekap) state.rekap = { raw: [], data: [], columns: [], bobot: {}, custom: false };
        state.rekap.raw = data;
        state.rekap.columns = cols;
        if (!state.rekap.custom || Object.keys(state.rekap.bobot||{}).length===0) {
          const per = cols.length ? 100/cols.length : 0;
          state.rekap.bobot = {};
          cols.forEach(c=> state.rekap.bobot[c]=per);
          state.rekap.custom = false;
        }
        const rows = data.map((row, idx) => {
          let total = 0;
          const out = {};
          cols.forEach(c => {
            const v = parseFloat(row[c] || 0) || 0;
            out[c] = v;
            const b = Number(state.rekap.bobot[c] || 0);
            total += v * (b/100);
          });
          const akhir = Math.round(total*10)/10;
          return {
            no: idx+1,
            nama: row['Nama Siswa'] || row['Nama'] || row['nama'] || row['nama siswa'] || '-',
            kelas: row['Kelas'] || row['kelas'] || '-',
            ...out,
            nilaiAkhir: akhir,
            predikat: rekapPredikat(akhir),
          };
        });
        rows.sort((a,b)=>b.nilaiAkhir - a.nilaiAkhir);
        rows.forEach((r,i)=> r.ranking = i+1);
        state.rekap.data = rows;
        saveDebounced(false);
        rekapRenderTable();
      }
      async function rekapHandlePicker(evt) {
        const f = evt.target?.files?.[0];
        if (!f) return;
        await ensureXLSX();
        const reader = new FileReader();
        reader.onload = (e)=>{
          try{
            const data = new Uint8Array(e.target.result);
            const wb = XLSX.read(data, { type:'array' });
            const ws = wb.Sheets[wb.SheetNames[0]];
            let json = [];
            try {
              const rows = XLSX.utils.sheet_to_json(ws, { header: 1, blankrows: false });
              let headerIdx = -1;
              for (let i = 0; i < rows.length; i++) {
                const r = rows[i].map(x => String(x||'').toLowerCase().trim());
                const hasNama = r.includes('nama siswa') || r.includes('nama');
                const hasKelas = r.includes('kelas');
                const nonEmpty = r.filter(x=>x).length;
                if ((hasNama && hasKelas) || nonEmpty >= 3) { headerIdx = i; break; }
              }
              if (headerIdx >= 0) {
                const header = rows[headerIdx].map(x=>String(x||'').trim());
                for (let i = headerIdx + 1; i < rows.length; i++) {
                  const row = rows[i];
                  if (!row || row.every(v=>v===null||v===undefined||String(v).trim()==='')) continue;
                  const obj = {};
                  for (let j=0;j<header.length;j++){
                    const key = header[j] || `Kol${j+1}`;
                    obj[key] = row[j];
                  }
                  json.push(obj);
                }
              }
            } catch {}
            if (!json.length) {
              json = XLSX.utils.sheet_to_json(ws);
            }
            if (json && json.length) {
              rekapProcessData(json);
              state.activeView = 'rekap';
              render();
            }
          }catch(_){}
        };
        reader.readAsArrayBuffer(f);
      }
      async function rekapDownloadTemplate() {
        await ensureXLSX();
        const t1 = [
          ['TEMPLATE 1'],
          [],
          ['No','Nama Siswa','Kelas','Tugas','UH','UTS','UAS'],
          [1,'Andi Pratama','7A',85,90,88,92],
        ];
        const wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet(t1), 'Template 1');
        XLSX.writeFile(wb, 'template_rekap_nilai.xlsx');
      }
      function openBobotModal() {
        if (!state.rekap || state.rekap.columns.length===0) return;
        const m = el('modalBobot');
        const c = el('bobotInputs');
        if (!m || !c) return;
        const per = state.rekap.columns.map(col => {
          const val = Number(state.rekap.bobot[col] ?? (100/state.rekap.columns.length));
          return `
            <div class="flex items-center gap-3 bg-background-light dark:bg-background-dark rounded-lg p-3">
              <label class="flex-1 font-semibold">${safeText(col)}</label>
              <input type="number" data-col="${safeText(col)}" class="rekap-bobot-input w-24 h-10 rounded-lg border bg-white dark:bg-surface-dark text-center" value="${val.toFixed(1)}" min="0" max="100" step="0.1"><span class="text-sm">%</span>
            </div>
          `;
        }).join('');
        c.innerHTML = per;
        m.style.display = 'flex';
        updateTotalBobotDisplay();
        c.querySelectorAll('.rekap-bobot-input').forEach(inp => {
          inp.oninput = updateTotalBobotDisplay;
          inp.onkeyup = updateTotalBobotDisplay;
        });
      }
      function closeBobotModal() {
        const m = el('modalBobot');
        if (m) m.style.display = 'none';
      }
      function resetBobot() {
        if (!state.rekap || state.rekap.columns.length===0) return;
        const n = state.rekap.columns.length;
        let eq = Math.round((100/n)*10)/10; // satu desimal
        state.rekap.bobot = {};
        // Set untuk n-1 kolom, kolom terakhir disesuaikan agar total tepat 100.0
        let totalAssigned = 0;
        for (let i=0; i<n; i++) {
          if (i < n-1) {
            state.rekap.bobot[state.rekap.columns[i]] = eq;
            totalAssigned += eq;
          } else {
            const last = Math.round((100 - totalAssigned)*10)/10;
            state.rekap.bobot[state.rekap.columns[i]] = last;
          }
        }
        state.rekap.custom = false;
        const inputs = document.querySelectorAll('.rekap-bobot-input');
        inputs.forEach((inp, idx) => {
          const col = inp.getAttribute('data-col');
          inp.value = String((state.rekap.bobot[col] ?? eq).toFixed(1));
        });
        updateTotalBobotDisplay();
      }
      function saveBobotSettings() {
        if (!state.rekap) return;
        const inputs = document.querySelectorAll('.rekap-bobot-input');
        const bobot = {};
        inputs.forEach(inp => {
          const col = inp.getAttribute('data-col');
          bobot[col] = parseFloat(inp.value)||0;
        });
        let total = 0;
        Object.values(bobot).forEach(v=> total += (parseFloat(v)||0));
        // Wajib tepat 100%, jika tidak tampilkan peringatan dan jangan tutup modal
        if (Math.abs(total - 100) > 0.05) {
          updateTotalBobotDisplay();
          alert('Total bobot harus tepat 100%. Mohon sesuaikan kembali.');
          return;
        }
        // Simpan apa adanya (dibulatkan satu desimal)
        const norm = {};
        Object.keys(bobot).forEach(k => norm[k] = Math.round((bobot[k])*10)/10);
        // Koreksi kemungkinan selisih rounding pada kolom terakhir
        const keys = Object.keys(norm);
        let sum = keys.reduce((a,k)=>a+(norm[k]||0),0);
        const diff = Math.round((100 - sum)*10)/10;
        if (Math.abs(diff) >= 0.1 && keys.length) {
          norm[keys[keys.length-1]] = Math.round((norm[keys[keys.length-1]] + diff)*10)/10;
        }
        state.rekap.bobot = norm;
        state.rekap.custom = true;
        closeBobotModal();
        if (state.rekap.raw && state.rekap.raw.length) {
          rekapProcessData(state.rekap.raw);
        }
      }
      function updateTotalBobotDisplay() {
        const inputs = document.querySelectorAll('.rekap-bobot-input');
        let total = 0;
        inputs.forEach(inp => { total += parseFloat(inp.value)||0; });
        const d = el('totalBobotDisplay');
        if (d) {
          d.textContent = total.toFixed(1)+'%';
          d.style.color = (Math.abs(total - 100) < 0.05) ? '#059669' : '#dc2626';
        }
      }
      function rekapFilter() {
        const q = (el('rekapSearch')?.value || '').toLowerCase();
        const rows = Array.from(el('rekapTBody')?.querySelectorAll('tr') || []);
        let vis = 0;
        rows.forEach(r=>{
          const t = r.textContent.toLowerCase();
          const show = t.includes(q);
          r.style.display = show ? '' : 'none';
          if (show) vis++;
        });
        const info = el('rekapTotalRows');
        if (info) info.textContent = String(vis);
      }
      function openRekapHelp() {
        const m = el('modalRekapHelp');
        if (m) m.style.display = 'flex';
      }
      function closeRekapHelp() {
        const m = el('modalRekapHelp');
        if (m) m.style.display = 'none';
      }
      function openRekapPrint() {
        const m = el('modalRekapPrint');
        if (m) m.style.display = 'flex';
      }
      function closeRekapPrint() {
        const m = el('modalRekapPrint');
        if (m) m.style.display = 'none';
      }
      function openIdentitasHelp() {
        const m = el('modalIdentitasHelp');
        if (m) m.style.display = 'flex';
      }
      function closeIdentitasHelp() {
        const m = el('modalIdentitasHelp');
        if (m) m.style.display = 'none';
      }
      function openKonteksHelp(konteksSoalCount) {
        const m = el('modalKonteksHelp');
        if (!m) return;
        const c = Number(konteksSoalCount || 0);
        const cnt = Number.isFinite(c) && c > 0 ? c : null;
        const titleEl = el('konteksHelpTitle');
        const bodyEl = el('konteksHelpBody');
        if (titleEl) titleEl.textContent = 'Petunjuk Soal Berkonteks (Stimulus)';
        if (bodyEl) {
          bodyEl.innerHTML = `
            <div class="space-y-3">
              <div>Fitur ini membuat <b>1 stimulus/bacaan</b> yang dipakai oleh sebagian soal dalam bagian yang sama.</div>
              <ul class="list-disc pl-5 space-y-1">
                <li>Dalam 1 bagian: <b>hanya 1 konteks</b>.</li>
                <li>Jumlah soal yang memakai konteks: maksimal <b>30%</b> dari jumlah soal (minimal <b>3 soal</b> jika memungkinkan).</li>
                ${cnt ? `<li>Bagian ini: 1 konteks untuk <b>${cnt} soal</b>.</li>` : ``}
                <li>Konteks dibuat minimal <b>2 paragraf</b> (dipisahkan 1 baris kosong).</li>
              </ul>
            </div>
          `;
        }
        m.style.display = 'flex';
      }
      function closeKonteksHelp() {
        const m = el('modalKonteksHelp');
        if (m) m.style.display = 'none';
      }
      function openBuatSoalHelp() {
        const m = el('modalBuatSoalHelp');
        if (m) m.style.display = 'flex';
      }
      function closeBuatSoalHelp() {
        const m = el('modalBuatSoalHelp');
        if (m) m.style.display = 'none';
      }
      function openBagikanLinkHelp() {
        const m = el('modalBagikanLinkHelp');
        if (m) m.style.display = 'flex';
      }
      function closeBagikanLinkHelp() {
        const m = el('modalBagikanLinkHelp');
        if (m) m.style.display = 'none';
      }
      function openBagikanLinkFieldHelp(key) {
        const m = el('modalBagikanLinkFieldHelp');
        if (!m) return;
        const titleEl = el('blfhTitle');
        const bodyEl = el('blfhBody');
        const map = {
          slug: {
            title: 'Nama Link (wajib)',
            html: `<div class="space-y-2">
              <div>Nama link dipakai sebagai identitas quiz saat dibagikan dan untuk memilih hasil di menu Hasil Quiz.</div>
              <ul class="list-disc pl-5 space-y-1">
                <li>Gunakan huruf/angka dan tanda minus.</li>
                <li>Contoh: <b>biologi-kls10-pts</b></li>
              </ul>
            </div>`,
          },
          jumlah: {
            title: 'Jumlah Siswa',
            html: `<div class="space-y-2">
              <div>Membatasi nomor absen/siswa yang boleh mengisi.</div>
              <ul class="list-disc pl-5 space-y-1">
                <li>Jika memakai Data Siswa (diunggah), batasnya mengikuti jumlah data siswa.</li>
              </ul>
            </div>`,
          },
          expire: {
            title: 'Batas Waktu Link (opsional)',
            html: `<div class="space-y-2">
              <div>Quiz hanya bisa diakses/dikerjakan sampai waktu ini. Jika sudah lewat, link tidak bisa dibuka lagi.</div>
              <ul class="list-disc pl-5 space-y-1">
                <li>Maksimal 14 hari dari tanggal pembuatan link quiz.</li>
                <li>Jika dikosongkan, sistem otomatis mengisi batas waktu 14 hari.</li>
                <li>Isi tanggal, lalu pilih jam dan menit.</li>
              </ul>
            </div>`,
          },
          roster: {
            title: 'Data Siswa (tidak wajib)',
            html: `<div class="space-y-2">
              <div>Jika diunggah, sistem bisa membuat link per siswa dan menampilkan nama di rekap.</div>
              <ul class="list-disc pl-5 space-y-1">
                <li>Format baris: <b>NoAbsen,Nama Siswa</b> (atau dipisah tab/semicolon).</li>
                <li>Gunakan tombol Download Template TXT untuk contoh format.</li>
                <li>Jika tidak diunggah, siswa akan diminta mengisi No Absen & Nama saat membuka link.</li>
              </ul>
            </div>`,
          },
          opsi: {
            title: 'Opsi Quiz',
            html: `<div class="space-y-3">
              <div class="space-y-1">
                <div class="font-bold">Tampilkan jawaban & pembahasan</div>
                <div>Jika dicentang, setelah siswa submit, siswa dapat melihat jawaban benar dan pembahasan. Cocok untuk mode pembelajaran/latihan.</div>
              </div>
              <div class="space-y-1">
                <div class="font-bold">Sertakan gambar (maks 5)</div>
                <div>Jika dicentang, gambar pada soal akan disertakan saat publish. Maksimal 5 gambar per link quiz. Jika lebih dari 5, sistem akan menolak publish.</div>
              </div>
            </div>`,
          },
        };
        const item = map[String(key || '')] || { title: 'Petunjuk', html: '' };
        if (titleEl) titleEl.textContent = item.title;
        if (bodyEl) bodyEl.innerHTML = item.html;
        m.style.display = 'flex';
      }
      function closeBagikanLinkFieldHelp() {
        const m = el('modalBagikanLinkFieldHelp');
        if (m) m.style.display = 'none';
      }
      function openHasilQuizHelp() {
        const m = el('modalHasilQuizHelp');
        if (m) m.style.display = 'flex';
      }
      function closeHasilQuizHelp() {
        const m = el('modalHasilQuizHelp');
        if (m) m.style.display = 'none';
      }
      function toggleQuizPreviewPanel() {
        const next = !state.quizShowPreview;
        state.quizShowPreview = next;
        state.quizPreviewCount = 10;
        if (next) state.quizSubtab = 'live';
        saveDebounced(false);
        render();
      }
      function moreQuizPreview() {
        const items = Array.isArray(state.questions) ? state.questions.filter(q => q && q.type === 'pg' && Array.isArray(q.options) && q.options.length >= 3) : [];
        state.quizPreviewCount = Math.min((state.quizPreviewCount || 10) + 10, items.length);
        saveDebounced(false);
        render();
      }
      function buildQuizItemsHTMLInline() {
        const items = Array.isArray(state.questions) ? state.questions.filter(q => q && q.type === 'pg' && Array.isArray(q.options) && q.options.length >= 3) : [];
        const total = items.length;
        const withImg = items.filter(q => String(q.image||'').trim()).length;
        const n = Math.min(state.quizPreviewCount || 10, total);
        const note = withImg > 5 ? `<div class="no-print rounded-md border border-amber-200 bg-amber-50 text-amber-900 p-3 text-xs">Terdeteksi ${withImg} soal bergambar. Saat Bagikan Link, maksimal 5 gambar akan disertakan.</div>` : '';

        const renderQuizItem = (q, i) => `
          <div class="relative break-inside-avoid">
            <div class="flex gap-4">
              <span class="font-bold text-lg min-w-[1.5rem]">${i + 1}.</span>
              <div class="flex-1 pr-2">
                <div>
                  <p class="mb-4 pr-10 text-justify leading-relaxed text-lg">${safeText(String(q.question||''))}</p>
                  ${q.image ? `<img src="${q.image}" class="w-64 h-64 object-contain rounded-lg mb-2 border shadow-sm">` : ""}
                  <div class="grid grid-cols-1 gap-2 pl-1">
                    ${(Array.isArray(q.options) ? q.options : []).map((opt, oi) => `
                      <div class="flex gap-3 items-start">
                        <span class="font-semibold pt-0.5">${String.fromCharCode(65 + oi)}.</span>
                        <span class="leading-relaxed">${safeText(String(opt||''))}</span>
                      </div>`).join("")}
                  </div>
                </div>
              </div>
            </div>
          </div>
        `;

        const list = items.slice(0, n).map((q, i) => renderQuizItem(q, i)).join('');
        return `
          <div class="space-y-3">
            <div id="paperQuiz" class="bg-white text-black p-4 md:p-10 md:shadow-paper md:min-h-[297mm] font-serif border border-gray-200 mx-auto">
              <div class="border-b-2 border-black pb-6 mb-8 relative">
                ${state.identity.logo ? `<img src="${state.identity.logo}" class="absolute right-0 top-0 h-16 w-auto">` : ``}
                <div class="text-center mb-6">
                  <h2 class="font-bold text-xl md:text-2xl uppercase tracking-wider mb-1">${safeText(state.identity.namaSekolah || "NAMA SEKOLAH")}</h2>
                  <h3 class="font-bold text-base md:text-lg uppercase tracking-wide">${safeText(state.paket.judul || "PENILAIAN AKHIR SEMESTER")}</h3>
                  <div class="text-sm mt-1">Tahun Pelajaran ${safeText(state.paket.tahunAjaran)}</div>
                  <div class="text-xs mt-1">(Pratinjau Soal untuk Quiz • PG saja)</div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-x-12 gap-y-2 text-sm">
                  <div class="space-y-1.5">
                    <div class="flex items-start"><span class="w-36 font-semibold shrink-0">Mata Pelajaran</span><span class="mr-2">:</span><span>${safeText(state.identity.mataPelajaran)}</span></div>
                    <div class="flex items-start"><span class="w-36 font-semibold shrink-0">Kelas / Fase</span><span class="mr-2">:</span><span>${safeText(state.identity.kelas)} / ${safeText(state.identity.fase)}</span></div>
                    ${identityTopikDisplay(state.identity) ? `<div class="flex items-start"><span class="w-36 font-semibold shrink-0">Topik / Lingkup Materi</span><span class="mr-2">:</span><span>${safeText(identityTopikDisplay(state.identity))}</span></div>` : ``}
                    <div class="flex items-center"><span class="w-36 font-semibold shrink-0">Hari / Tanggal</span><span class="mr-2">:</span><div class="border-b border-black border-dotted flex-1 h-4"></div></div>
                  </div>
                  <div class="space-y-1.5">
                    <div class="flex items-center"><span class="w-36 font-semibold shrink-0">Waktu</span><span class="mr-2">:</span><div class="border-b border-black border-dotted flex-1 h-4"></div></div>
                    <div class="flex items-center"><span class="w-36 font-semibold shrink-0">Nama</span><span class="mr-2">:</span><div class="border-b border-black border-dotted flex-1 h-4"></div></div>
                    <div class="flex items-center"><span class="w-36 font-semibold shrink-0">No. Absen</span><span class="mr-2">:</span><div class="border-b border-black border-dotted flex-1 h-4"></div></div>
                  </div>
                </div>
              </div>
              <div class="space-y-6">
                <div>
                  <div class="font-bold mb-1">PILIHAN GANDA</div>
                  <div class="italic text-sm mb-4">Pilihlah salah satu jawaban yang paling tepat!</div>
                  <div class="no-print text-xs text-text-sub-light dark:text-text-sub-dark mb-3">Total PG untuk quiz: <b>${total}</b> • Soal bergambar: <b>${withImg}</b></div>
                  ${note}
                  <div class="space-y-6">
                    ${list || `<div class="p-10 text-center text-sm text-text-sub-light dark:text-text-sub-dark">Belum ada soal PG di paket.</div>`}
                  </div>
                  ${n < total ? `<div class="no-print mt-6"><button class="px-4 h-10 rounded-lg border bg-white dark:bg-surface-dark" onclick="window.__sp.moreQuizPreview()">Muat Lagi</button></div>` : ``}
                </div>
              </div>
            </div>
          </div>
        `;
      }
      function openSumberMateriHelp() {
        const m = el('modalSumberMateriHelp');
        if (m) m.style.display = 'flex';
      }
      function closeSumberMateriHelp() {
        const m = el('modalSumberMateriHelp');
        if (m) m.style.display = 'none';
      }
      function openKonfigurasiHelp() {
        const m = el('modalKonfigurasiHelp');
        if (m) m.style.display = 'flex';
      }
      function closeKonfigurasiHelp() {
        const m = el('modalKonfigurasiHelp');
        if (m) m.style.display = 'none';
      }
      function openPreviewHelp() {
        const m = el('modalPreviewHelp');
        if (m) m.style.display = 'flex';
      }
      function closePreviewHelp() {
        const m = el('modalPreviewHelp');
        if (m) m.style.display = 'none';
      }
      const BUAT_SOAL_TUTORIALS = {
        identitas: [
          { id: 'i1', title: 'Isi Identitas Dasar', src: 'tutorial/buatsoal/IdentitasSoal.wav' },
          { id: 'i2', title: 'Sumber Materi: Cara Isi yang Benar', src: 'tutorial/buatsoal/SumberMateri.wav' },
          { id: 'i3', title: 'Perintah Khusus: Contoh Penggunaan', src: 'tutorial/buatsoal/PerintahKhusus.wav' },
        ],
        konfigurasi: [
          { id: 'k1', title: 'Konsep Multi-bagian', src: 'tutorial/buatsoal/KonsepMultiBagian.wav' },
          { id: 'k2', title: 'Atur Bentuk & Jumlah Soal', src: 'tutorial/buatsoal/BagiandanJumlahSoal.wav' },
          { id: 'k3', title: 'Tingkat Kesulitan & Bloom', src: 'tutorial/buatsoal/TingkatKesulitanDanBloom.wav' },
          { id: 'k4', title: 'Duplikat & Hapus Bagian', src: 'tutorial/buatsoal/DuplikatDanHapusBagian.wav' },
          { id: 'k5', title: 'Lanjut ke Naskah Soal', src: 'tutorial/buatsoal/NaskahSoal.wav' },
        ],
        naskah: [
          { id: 'n1', title: 'Pengenalan Naskah Soal', src: 'tutorial/buatsoal/PengenalanNaskahSoal.wav' },
          { id: 'n2', title: 'Buat Soal Sekarang', src: 'tutorial/buatsoal/BuatSoalSekarang.wav' },
          { id: 'n3', title: 'Buat Ulang Soal per Nomor', src: 'tutorial/buatsoal/BuatUlangSoalPerNomor.wav' },
          { id: 'n4', title: 'Prompt Gambar & Upload', src: 'tutorial/buatsoal/PromptGambarUpload.wav' },
          { id: 'n5', title: 'Upload & Hapus Gambar', src: 'tutorial/buatsoal/UploadHapusGambar.wav' },
          { id: 'n6', title: 'Download PDF & DOCX, Simpan & Muat', src: 'tutorial/buatsoal/DownloadSimpanMuat.wav' },
        ],
      };
      function openBuatSoalTutorial(tab) {
        const m = el('modalBuatSoalTutorial');
        if (!m) return;
        const t = String(tab || state.previewTab || 'identitas');
        const list = Array.isArray(BUAT_SOAL_TUTORIALS[t]) ? BUAT_SOAL_TUTORIALS[t] : BUAT_SOAL_TUTORIALS.identitas;
        const titleEl = el('bstModalTitle');
        if (titleEl) {
          const map = { identitas: 'Tutorial • Identitas', konfigurasi: 'Tutorial • Konfigurasi', naskah: 'Tutorial • Naskah Soal' };
          titleEl.textContent = map[t] || 'Tutorial Buat Soal';
        }
        const listEl = el('bstList');
        if (listEl) {
          listEl.className = (t === 'konfigurasi' || t === 'naskah') ? 'grid grid-cols-1 md:grid-cols-2 gap-2' : 'space-y-2';
          listEl.innerHTML = list.map((it, i) => `
            <div class="w-full h-full rounded-lg border bg-white dark:bg-surface-dark p-4">
              <div class="flex items-start justify-between gap-3">
                <div>
                  <div class="text-xs text-text-sub-light">#${i + 1}</div>
                  <div class="font-bold">${safeText(it.title)}</div>
                </div>
                ${it.src ? `` : `<div class="text-xs px-2 py-1 rounded-full bg-gray-100 dark:bg-gray-800 text-gray-500">Segera hadir</div>`}
              </div>
              ${it.src ? `<div class="mt-3"><audio class="w-full rounded-lg border bg-white dark:bg-surface-dark" controls preload="none" src="${safeText(it.src)}"></audio></div>` : ``}
            </div>
          `).join('');
        }
        m.style.display = 'flex';
      }
      function closeBuatSoalTutorial() {
        const m = el('modalBuatSoalTutorial');
        if (m) m.style.display = 'none';
        try {
          document.querySelectorAll('#bstList audio').forEach(a => {
            try { a.pause(); } catch {}
            try { a.currentTime = 0; } catch {}
          });
        } catch {}
      }
      function playBuatSoalTutorial(id, tab) {
        const t = String(tab || state.previewTab || 'identitas');
        const list = Array.isArray(BUAT_SOAL_TUTORIALS[t]) ? BUAT_SOAL_TUTORIALS[t] : BUAT_SOAL_TUTORIALS.identitas;
        const item = list.find(x => x.id === String(id || ''));
        if (!item) return;
        if (!item.src) return;
        const title = el('bstTitle');
        if (title) title.textContent = item.title;
        const v = el('bstPlayer');
        if (!v) return;
        v.src = item.src;
        v.load();
        try { v.play(); } catch {}
      }
      function openMAHelp1(){ const m = el('modalMAHelp1'); if (m){ m.classList.remove('hidden'); m.style.display='flex'; } }
      function closeMAHelp1(){ const m = el('modalMAHelp1'); if (m){ m.style.display='none'; m.classList.add('hidden'); } }
      function openMAHelp2(){ const m = el('modalMAHelp2'); if (m){ m.classList.remove('hidden'); m.style.display='flex'; } }
      function closeMAHelp2(){ const m = el('modalMAHelp2'); if (m){ m.style.display='none'; m.classList.add('hidden'); } }
      function openMAHelp3(){ const m = el('modalMAHelp3'); if (m){ m.classList.remove('hidden'); m.style.display='flex'; } }
      function closeMAHelp3(){ const m = el('modalMAHelp3'); if (m){ m.style.display='none'; m.classList.add('hidden'); } }
      function openRPPHelp1(){ const m = el('modalRPPHelp1'); if (m){ m.classList.remove('hidden'); m.style.display='flex'; } }
      function closeRPPHelp1(){ const m = el('modalRPPHelp1'); if (m){ m.style.display='none'; m.classList.add('hidden'); } }
      function openRPPHelp2(){ const m = el('modalRPPHelp2'); if (m){ m.classList.remove('hidden'); m.style.display='flex'; } }
      function closeRPPHelp2(){ const m = el('modalRPPHelp2'); if (m){ m.style.display='none'; m.classList.add('hidden'); } }
      async function exportModulAjarPDF() {
        try {
          await ensureJsPDF();
          const M = state.modulAjar || {};
          const md = String(M.hasil || '').trim();
          if (!md) { alert('Modul Ajar belum tersedia. Generate dulu.'); return; }
          const { jsPDF } = window.jspdf;
          const doc = new jsPDF('p', 'pt', 'letter');
          const pageW = doc.internal.pageSize.getWidth();
          const pageH = doc.internal.pageSize.getHeight();

          const FONT = 'times';
          const margin = 72; // 1 inch (gaya Word default/Letter)
          const maxW = pageW - (margin * 2);
          const footerY = pageH - 28;
          let y = margin;

          const sp = (b = 3, a = 3) => { y += b; return () => { y += a; }; };
          const newPageIfNeeded = (need = 0) => {
            if (y + need > pageH - 56) { doc.addPage(); y = margin; }
          };
          const addWrapped = (text, fontSize = 11, bold = false, italics = false, align = 'left', indentX = 0, lineH = 14) => {
            doc.setFont(FONT, bold ? 'bold' : (italics ? 'italic' : 'normal'));
            doc.setFontSize(fontSize);
            const lines = doc.splitTextToSize(String(text || ''), maxW - indentX);
            const x = margin + indentX;
            if (align === 'center') {
              for (const ln of lines) {
                if (y + lineH > pageH - 56) { doc.addPage(); y = margin; }
                doc.text(ln, pageW / 2, y, { align: 'center' });
                y += lineH;
              }
              return;
            }
            for (const ln of lines) {
              if (y + lineH > pageH - 56) { doc.addPage(); y = margin; }
              doc.text(ln, x, y);
              y += lineH;
            }
          };

          let contentText = maNormalizeContent(M);
          const { pre: preLKPD, lkpd: lkpdText, post: postLKPD } = maSplitLKPD(contentText);
          const lkpdData = maEnsureLKPDData(lkpdText ? maParseLKPD(lkpdText, M) : { judul: M.judulModul || '', tujuan: [], alat: [], langkah: [], refleksi: [], kesimpulan: [] }, M, lkpdText);

          // Header ala docx: judul tengah + subjudul miring
          { const after = sp(10, 3); addWrapped(`MODUL AJAR ${(M.mapel || '').toUpperCase()}`.trim(), 14, true, false, 'center', 0, 18); after(); }
          sp(0, 12)();
          if (M.judulModul) {
            addWrapped(`"${M.judulModul}"`, 12, false, true, 'center', 0, 16);
            sp(0, 18)();
          } else {
            sp(0, 10)();
          }

          function parseContent(raw) {
            const lines = String(raw || '').split('\n');
            const out = [];
            let tblRows = [];
            let inTbl = false;

            const flushTbl = () => {
              if (!tblRows.length) return;
              const nc = Math.max(...tblRows.map(r => r.length));
              out.push({ t: 'tbl', rows: tblRows, nc });
              tblRows = [];
              inTbl = false;
            };

            for (let i = 0; i < lines.length; i++) {
              const line = lines[i] || '';
              if (line.trim() === '[[PAGE_BREAK]]') { out.push({ t: 'pb' }); continue; }
              if (line.trim().match(/^---+$/)) { out.push({ t: 'hr' }); continue; }
              const fullBold = line.match(/^\s*\*\*(.+?)\*\*\s*$/);
              if (fullBold) { out.push({ t: 'h', l: 3, v: String(fullBold[1] || '').trim() }); continue; }
              if (line.match(/^\|[-|: ]+\|?$/)) continue; // separator
              if (line.trim().startsWith('|')) {
                inTbl = true;
                tblRows.push(line.split('|').slice(1, -1).map(c =>
                  String(c || '')
                    .trim()
                    .replace(/<[^>]+>/g, ' ')
                    .replace(/&nbsp;/g, ' ')
                    .replace(/\*\*(.+?)\*\*/g, '$1')
                    .replace(/\*(.+?)\*/g, '$1')
                    .replace(/\s+/g, ' ')
                    .trim()
                ));
                continue;
              }
              if (inTbl) flushTbl();
              if (!line.trim()) { out.push({ t: 'sp' }); continue; }
              if (/^####\s*/.test(line)) out.push({ t: 'h', l: 4, v: line.replace(/^####\s*/, '') });
              else if (/^###\s*/.test(line)) out.push({ t: 'h', l: 3, v: line.replace(/^###\s*/, '') });
              else if (/^##\s*/.test(line)) out.push({ t: 'h', l: 2, v: line.replace(/^##\s*/, '') });
              else if (/^#\s*/.test(line)) out.push({ t: 'h', l: 1, v: line.replace(/^#\s*/, '') });
              else if (line.match(/^[-•] /)) out.push({ t: 'ul', v: line.replace(/^[-•] /, '').replace(/\*\*(.+?)\*\*/g, '$1') });
              else if (line.match(/^\d+\. /)) {
                const m = line.match(/^(\d+)\. (.*)/);
                const rest = m ? String(m[2] || '') : String(line || '');
                const isBold = /\*\*(.+?)\*\*/.test(rest);
                const v = rest.replace(/\*\*(.+?)\*\*/g, '$1').replace(/\*(.+?)\*/g, '$1').trim();
                const cont = [];
                let k = i + 1;
                while (k < lines.length) {
                  const l = lines[k] || '';
                  if (!l.trim()) break;
                  if (l.trim().match(/^---+$/)) break;
                  if (/^\s*\d+\.\s+/.test(l) || /^\s*[-•]\s+/.test(l) || /^\s*#{1,4}\s*/.test(l) || l.includes('|')) break;
                  if (/^\s{2,}\S/.test(l)) { cont.push(l.trim()); k++; continue; }
                  break;
                }
                out.push({ t: 'ol', n: m ? m[1] : '', v, bold: isBold, cont });
                i = k - 1;
              } else out.push({ t: 'p', v: line.replace(/\*\*(.+?)\*\*/g, '$1').replace(/\*(.+?)\*/g, '$1') });
            }
            if (inTbl) flushTbl();
            return out;
          }

          const renderBlocks = (blocks) => {
            for (const b of blocks) {
              if (b.t === 'pb') {
                if (y !== margin) { doc.addPage(); y = margin; }
                continue;
              }
              if (b.t === 'sp') { sp(3, 3)(); continue; }
              if (b.t === 'h') {
                newPageIfNeeded(60);
                const fs = b.l === 1 ? 14 : b.l === 2 ? 13 : b.l === 3 ? 12 : 11;
                const after = sp(b.l === 1 ? 14 : 12, 6);
                addWrapped(b.v, fs, true, false, 'left', 0, 16);
                after();
                continue;
              }
              if (b.t === 'hr') { const after = sp(10, 10); newPageIfNeeded(20); doc.setDrawColor(150); doc.line(margin, y, pageW - margin, y); y += 10; after(); continue; }
              if (b.t === 'p') { const after = sp(3, 3); addWrapped(b.v, 11, false, false, 'left', 0, 14); after(); continue; }
              if (b.t === 'ul') { const after = sp(2, 2); addWrapped(`• ${b.v}`, 11, false, false, 'left', 24, 14); after(); continue; }
              if (b.t === 'ol') {
                const after = sp(2, 2);
                addWrapped(`${b.n}. ${b.v}`, 11, !!b.bold, false, 'left', 24, 14);
                if (Array.isArray(b.cont) && b.cont.length) {
                  for (const c of b.cont) addWrapped(String(c || ''), 11, false, false, 'left', 44, 14);
                }
                after();
                continue;
              }
              if (b.t === 'tbl') {
                const after = sp(6, 6);
                newPageIfNeeded(120);
                const header = b.rows[0] || [];
                const body = (b.rows || []).slice(1);
                doc.autoTable({
                  head: [header],
                  body,
                  startY: y,
                  margin: { left: margin, right: margin },
                  styles: { font: FONT, fontSize: 11, cellPadding: 5, lineWidth: 0.5, lineColor: [120,120,120], textColor: [0,0,0] },
                  headStyles: { fillColor: [217,217,217], textColor: [0,0,0], fontStyle: 'bold' },
                  bodyStyles: { textColor: [0,0,0] },
                });
                y = (doc.lastAutoTable?.finalY || y);
                after();
                continue;
              }
            }
          };
          const renderLKPD = (d) => {
            if (!d) return;
            if (y > margin + 10) { doc.addPage(); y = margin; }
            sp(10, 6)();
            doc.setFont(FONT, 'bold'); doc.setFontSize(12); doc.setTextColor(0, 0, 0);
            addWrapped('C. LAMPIRAN', 12, true, false, 'left', 0, 16);
            const afterLamp = sp(6, 6);
            addWrapped('1. Lembar Kerja Peserta Didik (LKPD)', 12, true, false, 'left', 0, 16);
            afterLamp();
            sp(6, 0)();
            const boxX = margin;
            const boxW = maxW;
            const headerH = 34;
            const startY = y;
            doc.setDrawColor(90);
            doc.setFillColor(217, 217, 217);
            doc.rect(boxX, startY, boxW, headerH, 'F');
            doc.setTextColor(0, 0, 0);
            doc.setFont(FONT, 'bold'); doc.setFontSize(11);
            doc.text('LEMBAR KERJA PESERTA DIDIK (LKPD)', boxX + boxW / 2, startY + 16, { align: 'center' });
            doc.setFont(FONT, 'normal'); doc.setFontSize(10);
            doc.text(String(d.judul || ' ').trim() || ' ', boxX + boxW / 2, startY + 29, { align: 'center' });
            doc.setTextColor(0, 0, 0);
            y = startY + headerH + 16;
            doc.setFont(FONT, 'normal'); doc.setFontSize(11);
            const lineW = boxW - 120;
            const field = (label) => {
              doc.text(label, boxX + 10, y);
              doc.line(boxX + 90, y + 2, boxX + 90 + lineW, y + 2);
              y += 18;
            };
            field('Nama Siswa :');
            field('Kelas     :');
            field('Kelompok  :');
            y += 4;
            const sectionTitle = (t) => { doc.setFont(FONT, 'bold'); doc.text(t, boxX + 10, y); y += 16; doc.setFont(FONT, 'normal'); };
            const bullet = (t) => { addWrapped('• ' + t, 11, false, false, 'left', 24, 14); };
            const numbered = (t, idx) => { const v = /^\d+[\.\)]\s+/.test(t) ? t : `${idx + 1}. ${t}`; addWrapped(v, 11, false, false, 'left', 24, 14); };
            sectionTitle('A. Tujuan');
            (d.tujuan && d.tujuan.length ? d.tujuan : ['-']).forEach(x => bullet(String(x)));
            y += 6;
            sectionTitle('B. Alat dan Bahan');
            (d.alat && d.alat.length ? d.alat : ['-']).forEach(x => bullet(String(x)));
            y += 6;
            sectionTitle('C. Langkah Kegiatan');
            (d.langkah && d.langkah.length ? d.langkah : ['-']).forEach((x, i) => numbered(String(x), i));
            y += 6;
            sectionTitle('D. Pertanyaan Refleksi');
            (d.refleksi && d.refleksi.length ? d.refleksi : ['-']).forEach((x, i) => numbered(String(x), i));
            doc.text('Jawaban:', boxX + 10, y); y += 14;
            for (let i = 0; i < 3; i++) { doc.line(boxX + 10, y + 2, boxX + boxW - 10, y + 2); y += 16; }
            y += 4;
            sectionTitle('E. Kesimpulan');
            for (let i = 0; i < 3; i++) { doc.line(boxX + 10, y + 2, boxX + boxW - 10, y + 2); y += 16; }
            const endY = y + 10;
            doc.setDrawColor(90);
            doc.rect(boxX, startY, boxW, endY - startY);
            y = endY + 14;
          };

          renderBlocks(parseContent(maInsertPageBreakMarkers(maStripCLampiranHeading(preLKPD || ''))));
          renderLKPD(lkpdData);
          if (String(postLKPD || '').trim()) { doc.addPage(); y = margin; }
          renderBlocks(parseContent(maInsertPageBreakMarkers(maStripCLampiranHeading(postLKPD || ''))));

          const totalPages = doc.getNumberOfPages();
          for (let p = 1; p <= totalPages; p++) {
            doc.setPage(p);
            doc.setFont(FONT, 'normal');
            doc.setFontSize(9);
            doc.setTextColor(136, 136, 136);
            const footer = `Modul Ajar ${M.mapel || ''} — ${M.institusi || ''} ${new Date().getFullYear()} | Halaman ${p}`;
            doc.text(footer, pageW / 2, footerY, { align: 'center' });
          }
          doc.setTextColor(0, 0, 0);
          doc.save(`ModulAjar_${(M.mapel||'Mapel').replace(/\s+/g,'')}_${(M.judulModul||'Modul').replace(/[\s/]+/g,'_')}.pdf`);
        } catch (e) {
          alert('Gagal membuat PDF. Silakan coba lagi atau unduh .docx terlebih dahulu.');
        }
      }
      async function generateRekapPDF() {
        await ensureJsPDF();
        const { jsPDF } = window.jspdf;
        const doc = new jsPDF('p','pt','a4');
        const title = 'Rekap Nilai';
        const mapel = (el('printMapel')?.value || '').trim();
        const guru = (el('printGuru')?.value || '').trim();
        // Header
        doc.setFont('helvetica','bold'); doc.setFontSize(18);
        doc.text(title, 40, 50);
        doc.setFont('helvetica','normal'); doc.setFontSize(11);
        if (mapel) doc.text(`Mata pelajaran: ${mapel}`, 40, 72);
        if (guru) doc.text(`Guru: ${guru}`, 40, 88);
        // Logo (opsional)
        const logoFile = el('rekapPrintLogoPicker')?.files?.[0] || null;
        if (logoFile) {
          const dataUrl = await new Promise(res => {
            const fr = new FileReader();
            fr.onload = () => res(fr.result);
            fr.readAsDataURL(logoFile);
          });
          try { doc.addImage(dataUrl, 'PNG', 450, 30, 120, 60); } catch {}
        }
        // Ringkasan
        const max = el('rekapMax')?.textContent || '0';
        const min = el('rekapMin')?.textContent || '0';
        const avg = el('rekapAvg')?.textContent || '0';
        doc.setFontSize(11);
        doc.roundedRect(40, 110, 160, 50, 6, 6);
        doc.text('Nilai Tertinggi', 50, 130); doc.setFont('helvetica','bold'); doc.text(String(max), 50, 150); doc.setFont('helvetica','normal');
        doc.roundedRect(220, 110, 160, 50, 6, 6);
        doc.text('Nilai Terendah', 230, 130); doc.setFont('helvetica','bold'); doc.text(String(min), 230, 150); doc.setFont('helvetica','normal');
        doc.roundedRect(400, 110, 160, 50, 6, 6);
        doc.text('Rata-Rata Kelas', 410, 130); doc.setFont('helvetica','bold'); doc.text(String(avg), 410, 150); doc.setFont('helvetica','normal');
        // Tabel
        const rows = (state.rekap?.data || []).map((r, i) => [i+1, String(r.nama||''), String(r.kelas||''), String(r.nilaiAkhir||0), String(r.predikat||''), String(r.ranking||'')]);
        const head = [['No','Nama Siswa','Kelas','Nilai Akhir','Predikat','Ranking']];
        doc.autoTable({
          head, body: rows,
          startY: 180,
          styles: { font: 'helvetica', fontSize: 9, cellPadding: 4, lineWidth: 0.1 },
          headStyles: { fillColor: [240,240,240], textColor: [0,0,0] },
          columnStyles: { 0: { halign: 'center', cellWidth: 30 }, 2: { halign:'center', cellWidth: 60 }, 3: { halign:'center', cellWidth: 80 }, 4: { halign:'center', cellWidth: 70 }, 5:{halign:'center', cellWidth:60 } }
        });
        doc.save('rekap_nilai.pdf');
        closeRekapPrint();
      }
      function openPredikatModal() {
        const m = el('modalPredikat');
        const c = el('predikatInputs');
        if (!m || !c) return;
        const rules = (state.rekap?.predikatRules || []).slice();
        const order = ['A','B','C','D','E'];
        const byGrade = (g)=> rules.find(r => String(r.grade).toUpperCase()===g) || {grade:g,min:0,max:0};
        const inputsHTML = order.map(g => {
          const r = byGrade(g);
          return `
            <div class="flex items-center gap-3 bg-background-light dark:bg-background-dark rounded-lg p-3">
              <label class="w-32 whitespace-nowrap font-semibold">Predikat ${g}</label>
              <span class="text-sm text-text-sub-light">Min</span>
              <input type="number" class="predikat-min w-24 h-10 rounded-lg border bg-white dark:bg-surface-dark text-center" data-grade="${g}" value="${Number(r.min)}" min="0" max="100" step="0.1">
              <span class="text-sm text-text-sub-light">Max</span>
              <input type="number" class="predikat-max w-24 h-10 rounded-lg border bg-white dark:bg-surface-dark text-center" data-grade="${g}" value="${Number(r.max)}" min="0" max="100" step="0.1">
            </div>
          `;
        }).join('');
        c.innerHTML = inputsHTML;
        m.style.display = 'flex';
      }
      function closePredikatModal() {
        const m = el('modalPredikat');
        if (m) m.style.display = 'none';
      }
      function resetPredikat() {
        if (!state.rekap) state.rekap = {};
        state.rekap.predikatRules = [
          { grade: 'A', min: 85, max: 100 },
          { grade: 'B', min: 70, max: 84.9 },
          { grade: 'C', min: 55, max: 69.9 },
          { grade: 'D', min: 40, max: 54.9 },
          { grade: 'E', min: 0,  max: 39.9 },
        ];
        openPredikatModal();
      }
      function savePredikatSettings() {
        const mins = document.querySelectorAll('.predikat-min');
        const maxs = document.querySelectorAll('.predikat-max');
        const map = {};
        mins.forEach(inp => {
          const g = inp.getAttribute('data-grade');
          map[g] = map[g] || {};
          map[g].min = clamp(parseFloat(inp.value)||0, 0, 100);
        });
        maxs.forEach(inp => {
          const g = inp.getAttribute('data-grade');
          map[g] = map[g] || {};
          map[g].max = clamp(parseFloat(inp.value)||0, 0, 100);
        });
        const order = ['A','B','C','D','E'];
        // Bentuk aturan dan filter predikat nonaktif (0-0)
        let rules = order.map(g => ({ grade:g, min: map[g]?.min ?? 0, max: map[g]?.max ?? 0 }));
        // Tolak jika ada min>max pada aturan aktif
        for (const r of rules) {
          const disabled = (r.min===0 && r.max===0);
          if (!disabled && r.min > r.max) {
            alert(`Predikat ${r.grade}: Min tidak boleh lebih besar dari Max.`);
            return;
          }
        }
        // Gunakan hanya aturan aktif untuk validasi overlap
        const enabled = rules.filter(r => !(r.min===0 && r.max===0));
        for (let i=0;i<enabled.length;i++){
          for (let j=i+1;j<enabled.length;j++){
            const a = enabled[i], b = enabled[j];
            if (Math.max(a.min, b.min) <= Math.min(a.max, b.max)) {
              alert(`Rentang predikat ${a.grade} dan ${b.grade} bertumpuk. Mohon sesuaikan.`);
              return;
            }
          }
        }
        // Simpan aturan (aktif saja), urutkan dari min tertinggi ke terendah
        enabled.sort((x,y)=>y.min - x.min);
        state.rekap.predikatRules = enabled;
        closePredikatModal();
        // Re-hitung predikat & ranking
        if (state.rekap && state.rekap.raw && state.rekap.raw.length) {
          rekapProcessData(state.rekap.raw);
        } else {
          // Jika tidak ada raw, hanya re-render
          rekapRenderTable();
        }
      }

      const autoFillPaket = () => {
        const mapel = String(state.identity.mataPelajaran || "");
        const kelas = String(state.identity.kelas || "");
        const tahun = new Date().getFullYear();
        const next = tahun + 1;
        if (!state.paket.judul) state.paket.judul = `Penilaian ${mapel ? " " + mapel : ""}`;
        if (!state.paket.tahunAjaran) state.paket.tahunAjaran = `${tahun}/${next}`;
      };

      const addSection = () => {
        state.sections.push({
          id: uuid(),
          judul: `Bagian ${state.sections.length + 1}`,
          bentuk: "pg",
          opsiPG: 4,
          jumlahPG: 10,
          jumlahIsian: 3,
          tingkatKesulitan: "campuran",
          cakupanBloom: "level_standar",
          dimensi: ["C1", "C2", "C3", "C4"],
          soalKonteks: false,
          pakaiGambar: false,
        });
        saveDebounced(true);
        render();
      };

      const removeSection = (id) => {
        state.sections = state.sections.filter((x) => x.id !== id);
        saveDebounced(true);
        render();
      };

      const duplicateSection = (id) => {
        const s = state.sections.find((x) => x.id === id);
        if (!s) return;
        state.sections.push({ ...structuredClone(s), id: uuid(), judul: `${s.judul} (Copy)` });
        saveDebounced(true);
        render();
      };

      const updateSection = (id, key, value, renderNow = true) => {
        const s = state.sections.find((x) => x.id === id);
        if (!s) return;
        s[key] = value;
        if (state.soalError) state.soalError = null;
        if (key === "bentuk") {
          const isObjective = ["pg", "benar_salah", "pg_kompleks", "menjodohkan"].includes(value);
          const isEssay = ["isian", "uraian"].includes(value);
          if (isObjective) s.jumlahIsian = 0;
          if (isEssay) s.jumlahPG = 0;
        }
        if (key === "cakupanBloom") {
          const codes = bloomPresets[value]?.codes;
          if (codes) s.dimensi = codes;
        }
        saveDebounced(false);
        if (renderNow) {
          render();
          saveDebounced(true);
        }
      };

      let saveTimer = null;
      let storageFullWarned = false;

      const save = () => {
        try {
          localStorage.setItem(APP_KEY, JSON.stringify(structuredClone(state)));
          el("badgeSaved").classList.remove("hidden");
          setTimeout(() => el("badgeSaved").classList.add("hidden"), 1200);
          storageFullWarned = false;
        } catch (e) {
          console.error("Gagal menyimpan otomatis (Storage Penuh?):", e);
          if (!storageFullWarned) {
             alert("Penyimpanan browser penuh! Silakan 'Simpan' proyek sebagai file JSON agar data aman.");
             storageFullWarned = true;
          }
        }
      };

      const saveDebounced = (flush) => {
        clearTimeout(saveTimer);
        if (flush) return save();
        saveTimer = setTimeout(save, 1000);
      };

      const load = () => {
        const raw = localStorage.getItem(APP_KEY);
        if (!raw) return false;
        const parsed = JSON.parse(raw);
        state = { ...DEFAULT_STATE(), ...parsed };
        state.sections = parsed.sections?.length ? parsed.sections : DEFAULT_STATE().sections;
        try {
          state.identity = state.identity || {};
          const I = state.identity;
          if (String(I.jenjang || '').trim() === 'SMK') I.jenjang = 'SMK/MAK';
          const pj = String(I.jenjang || '').trim();
          if (pj === 'Paket A' || pj === 'Paket B' || pj === 'Paket C') {
            I.kesetaraanPaket = pj;
            I.jenjang = 'Kesetaraan';
          }
          if (!String(I.topik_raw || "").trim() && !String(I.topik_ringkas || "").trim() && String(I.topik || "").trim()) {
            I.topik_raw = String(I.topik || "");
            I.topik_ringkas = String(I.topik || "");
          }
          if (String(I.topik_raw || "").trim() && !String(I.topik_ringkas || "").trim()) {
            const rawTxt = String(I.topik_raw || "").replace(/\r\n/g, "\n").replace(/\r/g, "\n");
            const firstLine = rawTxt.split("\n").map(s => s.trim()).filter(Boolean)[0] || "";
            I.topik_ringkas = (firstLine || rawTxt.slice(0, 180)).trim();
          }
        } catch {}
        try {
          if (state.lkpd && String(state.lkpd.jenjang || '').trim() === 'SMK') state.lkpd.jenjang = 'SMK/MAK';
        } catch {}
        try {
          if (state.modulAjar && String(state.modulAjar.jenjang || '').trim() === 'SMK') state.modulAjar.jenjang = 'SMK/MAK';
        } catch {}
        try {
          if (state.rpp && String(state.rpp.jenjang || '').trim() === 'SMK') state.rpp.jenjang = 'SMK/MAK';
        } catch {}
        try {
          if (state.lkpd) {
            const lj = String(state.lkpd.jenjang || '').trim();
            if (lj === 'Paket A' || lj === 'Paket B' || lj === 'Paket C') {
              state.lkpd.kesetaraanPaket = lj;
              state.lkpd.jenjang = 'Kesetaraan';
            }
          }
        } catch {}
        try {
          if (state.modulAjar) {
            const mj = String(state.modulAjar.jenjang || '').trim();
            if (mj === 'Paket A' || mj === 'Paket B' || mj === 'Paket C') {
              state.modulAjar.kesetaraanPaket = mj;
              state.modulAjar.jenjang = 'Kesetaraan';
            }
            const jp = Number(state.modulAjar.jumlahPertemuan || 0);
            if (Number.isFinite(jp) && jp > MA_MAX_PERTEMUAN) state.modulAjar.jumlahPertemuan = String(MA_MAX_PERTEMUAN);
          }
        } catch {}
        try {
          if (state.rpp) {
            const rj = String(state.rpp.jenjang || '').trim();
            if (rj === 'Paket A' || rj === 'Paket B' || rj === 'Paket C') {
              state.rpp.kesetaraanPaket = rj;
              state.rpp.jenjang = 'Kesetaraan';
            }
          }
        } catch {}
        try {
          const I = state.identity || {};
          const je = resolveJenjang(I.jenjang, I.kesetaraanPaket);
          const faseOpts = MA_FASE_MAP[je] || [];
          if (String(I.fase || '').trim() && !faseOpts.includes(String(I.fase || '').trim())) {
            const fl = faseLetterFromLabel(I.fase);
            const opt = fl ? faseOpts.find(x => new RegExp(`\\bFase\\s+${fl}\\b`, 'i').test(String(x))) : null;
            if (opt) I.fase = opt;
          }
          if (!String(I.fase || '').trim()) {
            const kn = parseKelasNumber(I.kelas);
            const fl = expectedFaseLetter(je, kn);
            const opt = fl ? faseOpts.find(x => new RegExp(`\\bFase\\s+${fl}\\b`, 'i').test(String(x))) : null;
            if (opt) I.fase = opt;
          }
        } catch {}
        try {
          const L = state.lkpd || {};
          const je = resolveJenjang(L.jenjang, L.kesetaraanPaket);
          const faseOpts = MA_FASE_MAP[je] || [];
          if (String(L.fase || '').trim() && !faseOpts.includes(String(L.fase || '').trim())) {
            const fl = faseLetterFromLabel(L.fase);
            const opt = fl ? faseOpts.find(x => new RegExp(`\\bFase\\s+${fl}\\b`, 'i').test(String(x))) : null;
            if (opt) L.fase = opt;
          }
          if (!String(L.fase || '').trim()) {
            const kn = parseKelasNumber(L.kelas);
            const fl = expectedFaseLetter(je, kn);
            const opt = fl ? faseOpts.find(x => new RegExp(`\\bFase\\s+${fl}\\b`, 'i').test(String(x))) : null;
            if (opt) L.fase = opt;
          }
        } catch {}
        try {
          const R = state.rpp || {};
          const je = resolveJenjang(R.jenjang, R.kesetaraanPaket);
          const faseOpts = MA_FASE_MAP[je] || [];
          if (String(R.fase || '').trim() && !faseOpts.includes(String(R.fase || '').trim())) {
            const fl = faseLetterFromLabel(R.fase);
            const opt = fl ? faseOpts.find(x => new RegExp(`\\bFase\\s+${fl}\\b`, 'i').test(String(x))) : null;
            if (opt) R.fase = opt;
          }
          if (!String(R.fase || '').trim()) {
            const kn = parseKelasNumber(R.kelas);
            const fl = expectedFaseLetter(je, kn);
            const opt = fl ? faseOpts.find(x => new RegExp(`\\bFase\\s+${fl}\\b`, 'i').test(String(x))) : null;
            if (opt) R.fase = opt;
          }
        } catch {}
        try {
          const qst = String(state.quizSubtab || "").trim();
          if (qst === "publish") { state.quizSubtab = "share"; state.quizShareTab = "buat_link"; }
          if (qst === "results") { state.quizSubtab = "share"; state.quizShareTab = "hasil"; }
          if (!state.quizShareTab) state.quizShareTab = "buat_link";
          if (state.quizSubtab !== "live" && state.quizSubtab !== "share") state.quizSubtab = "live";
        } catch {}
        return true;
      };

      const resetAll = () => {
        state = DEFAULT_STATE();
        localStorage.removeItem(APP_KEY);
        applyTheme();
        render();
      };

      const normalizeAnswerIndex = (answer, options) => {
        if (answer == null) return 0;
        if (typeof answer === "number" && Number.isFinite(answer)) return clamp(Math.trunc(answer), 0, Math.max(0, options.length - 1));
        const s = String(answer).trim();
        if (!s) return 0;
        const upper = s.toUpperCase();
        const letter = upper.match(/^[A-E]$/)?.[0];
        if (letter) return clamp(letter.charCodeAt(0) - "A".charCodeAt(0), 0, Math.max(0, options.length - 1));
        const num = Number(s);
        if (Number.isFinite(num)) {
          if (Number.isInteger(num) && num >= 1 && num <= options.length) return num - 1;
          return clamp(Math.trunc(num), 0, Math.max(0, options.length - 1));
        }
        return 0;
      };

      const normalizeQuestion = (item, sec) => {
        const cleanInlineHtml = (input) => {
          let t = String(input ?? "");
          t = t
            .replace(/<\s*sup\s*>([\s\S]*?)<\s*\/\s*sup\s*>/gi, (_m, g1) => `^${String(g1 ?? "").trim()}`)
            .replace(/<\s*sub\s*>([\s\S]*?)<\s*\/\s*sub\s*>/gi, (_m, g1) => `_${String(g1 ?? "").trim()}`)
            .replace(/<\s*\/\s*sup\s*>/gi, "")
            .replace(/<\s*\/\s*sub\s*>/gi, "")
            .replace(/<\s*sup\s*>/gi, "^")
            .replace(/<\s*sub\s*>/gi, "_")
            .replace(/<\/?[^>]+>/g, "");
          t = t.replace(/&nbsp;/gi, " ").replace(/\s+/g, " ").trim();
          return t;
        };
        const cleanMultilineHtml = (input) => {
          let t = String(input ?? "");
          t = t.replace(/\r\n/g, "\n").replace(/\r/g, "\n");
          t = t
            .replace(/<\s*sup\s*>([\s\S]*?)<\s*\/\s*sup\s*>/gi, (_m, g1) => `^${String(g1 ?? "").trim()}`)
            .replace(/<\s*sub\s*>([\s\S]*?)<\s*\/\s*sub\s*>/gi, (_m, g1) => `_${String(g1 ?? "").trim()}`)
            .replace(/<\s*\/\s*sup\s*>/gi, "")
            .replace(/<\s*\/\s*sub\s*>/gi, "")
            .replace(/<\s*sup\s*>/gi, "^")
            .replace(/<\s*sub\s*>/gi, "_")
            .replace(/<\/?[^>]+>/g, "");
          t = t.replace(/&nbsp;/gi, " ");
          t = t
            .split("\n")
            .map((line) => String(line).replace(/\s+/g, " ").trim())
            .join("\n");
          t = t.replace(/\n{3,}/g, "\n\n").trim();
          return t;
        };
        const cleanOptionText = (s) => {
          let t = cleanInlineHtml(s);
          t = t.replace(/^\s*\(?([A-Ea-e]|[1-9]|10)\)?\s*[\)\.\-:]\s*/,'');
          t = t.replace(/^\s*[A-Ea-e]\.\s+/,'');
          t = t.replace(/^\s*[A-Ea-e]\s*-\s+/,'');
          return t.trim();
        };
        const shuffleWithSeed = (arr, seed) => {
          const out = arr.slice();
          let s = seed >>> 0;
          for (let i = out.length - 1; i > 0; i--) {
            s = (s * 1664525 + 1013904223) >>> 0;
            const j = s % (i + 1);
            [out[i], out[j]] = [out[j], out[i]];
          }
          return out;
        };
        const stableSeed = (text) => {
          const str = String(text || '');
          let h = 2166136261 >>> 0;
          for (let i=0;i<str.length;i++) {
            h ^= str.charCodeAt(i);
            h = Math.imul(h, 16777619) >>> 0;
          }
          return h >>> 0;
        };
        const rawType = String(item?.type ?? "").toLowerCase();
        let type = "isian";
        if (rawType.includes("pg")) type = "pg";
        if (rawType.includes("benar") || rawType.includes("salah") || rawType.includes("true_false") || rawType.includes("truefalse")) type = "benar_salah";
        if (rawType.includes("kompleks")) type = "pg_kompleks";
        if (rawType.includes("menjodohkan")) type = "menjodohkan";
        if (rawType.includes("uraian")) type = "uraian";
        if (rawType === "isian") type = "isian";
        if (sec?.bentuk) type = sec.bentuk;
        
        const context = cleanMultilineHtml(item?.context ?? item?.stimulus ?? "");
        const question = cleanInlineHtml(item?.question ?? "");
        const explanation = cleanInlineHtml(item?.explanation ?? "");
        const difficulty = cleanInlineHtml(item?.difficulty ?? "");
        const bloom = cleanInlineHtml(item?.bloom ?? "");
        const materi = cleanInlineHtml(item?.materi ?? "");
        const indikator = cleanInlineHtml(item?.indikator ?? "");
        const imagePrompt = cleanInlineHtml(item?.imagePrompt ?? "");
        let invalidMenjodohkan = false;
        
        const options = Array.isArray(item?.options) ? item.options.map((x) => cleanOptionText(x)).filter(Boolean) : [];
        
        let cappedOptions = options;
        if (type === "benar_salah") {
             cappedOptions = ["Benar", "Salah"];
        } else if (type === "pg" || type === "pg_kompleks") {
             const max = clamp(Number(sec?.opsiPG || 4), 3, 5);
             if (cappedOptions.length > max) cappedOptions = cappedOptions.slice(0, max);
        }

        let answer = item?.answer;
        if (type === "benar_salah") {
             const s = String(answer ?? '').trim().toLowerCase();
             if (s === 'benar' || s === 'true' || s === 'b') answer = 0;
             else if (s === 'salah' || s === 'false' || s === 's') answer = 1;
             else answer = normalizeAnswerIndex(answer, cappedOptions);
             if (!Number.isFinite(answer)) answer = 0;
             answer = Number(answer) === 1 ? 1 : 0;
        } else if (type === "pg") {
             answer = normalizeAnswerIndex(answer, cappedOptions);
        } else if (type === "pg_kompleks") {
             const toList = (val) => {
               const out = [];
               const pushOne = (x) => {
                 const idx = normalizeAnswerIndex(x, cappedOptions);
                 if (Number.isFinite(idx) && idx >= 0) out.push(idx);
               };
               if (Array.isArray(val)) {
                 for (const v of val) out.push(...toList(v));
                 return out;
               }
               if (val == null) return out;
               if (typeof val === 'string') {
                 const s0 = val.trim();
                 if (!s0) return out;
                 const s = s0
                   .toUpperCase()
                   .replace(/\bDAN\b/g, ',')
                   .replace(/\b&\b/g, ',')
                   .replace(/\s+/g, ' ');
                 const parts = s.split(/[^A-E0-9]+/).map(x => x.trim()).filter(Boolean);
                 if (parts.length > 1) {
                   for (const p of parts) pushOne(p);
                   return out;
                 }
                 pushOne(s0);
                 return out;
               }
               pushOne(val);
               return out;
             };
             answer = toList(answer);
             answer = [...new Set(answer)].sort((a,b)=>a-b);
        } else if (type === "menjodohkan") {
             const cleanList = (list) => (Array.isArray(list) ? list : [])
               .map(x => cleanOptionText(x))
               .filter(Boolean);
             let leftList = cappedOptions;
             let rightList = [];
             const parseMappingPairs = (val) => {
               const pairs = [];
               const arr = Array.isArray(val) ? val : [];
               for (const it of arr) {
                 if (it == null) continue;
                 if (typeof it === 'string') {
                   const s = it.trim();
                   const m = s.match(/^(\d+)\s*(?:,|;|:|->|—|–|-)\s*(\d+)$/);
                   if (m) pairs.push([Number(m[1]), Number(m[2])]);
                   continue;
                 }
                 if (typeof it === 'object') {
                   const a = it?.a ?? it?.left ?? it?.l ?? null;
                   const b = it?.b ?? it?.right ?? it?.r ?? null;
                   if (a != null && b != null && Number.isFinite(Number(a)) && Number.isFinite(Number(b))) {
                     pairs.push([Number(a), Number(b)]);
                   }
                   continue;
                 }
               }
               return pairs;
             };
             const pairs = Array.isArray(item?.pairs) ? item.pairs : null;
             if (pairs && pairs.length) {
               leftList = pairs.map(p => cleanOptionText(p?.left)).filter(Boolean);
               rightList = pairs.map(p => cleanOptionText(p?.right)).filter(Boolean);
             } else {
               const rightFrom = Array.isArray(item?.right_options) ? item.right_options
                 : Array.isArray(item?.rightOptions) ? item.rightOptions
                 : Array.isArray(item?.jawaban) ? item.jawaban
                 : Array.isArray(answer) ? answer
                 : null;
               if (rightFrom) rightList = cleanList(rightFrom);
               else if (typeof answer === 'string') rightList = cleanList(String(answer).split(/\r?\n|;|,/));
             }
             if ((!pairs || !pairs.length) && rightList.length) {
               const looksNumeric = (t) => /^\d+(\s*(?:,|;|:|->|—|–|-)\s*\d+)?$/.test(String(t || '').trim());
               if (rightList.every(looksNumeric)) {
                 invalidMenjodohkan = true;
               }
             }
             const n = Math.min(leftList.length, rightList.length);
             leftList = leftList.slice(0, n);
             rightList = rightList.slice(0, n);
             if (n < 2) invalidMenjodohkan = true;
             if (invalidMenjodohkan) {
               cappedOptions = [];
               answer = [];
               item = { ...item, _matchKey: null };
             } else {
             const seed = stableSeed(question + '|' + leftList.join('|') + '|' + rightList.join('|'));
             const idxs = shuffleWithSeed([...Array(n).keys()], seed);
             const rightShuffled = idxs.map(i => rightList[i]);
             const matchKey = [];
             const mappingPairs = parseMappingPairs(answer);
             if (mappingPairs.length && n > 0) {
               const leftHasZero = mappingPairs.some(([a]) => a === 0);
               const rightHasZero = mappingPairs.some(([,b]) => b === 0);
               const leftBase = leftHasZero ? 0 : 1;
               const rightBase = rightHasZero ? 0 : 1;
               const origToShuf = new Map();
               for (let shufPos=0; shufPos<idxs.length; shufPos++) origToShuf.set(idxs[shufPos], shufPos);
               const byLeft = new Map();
               for (const [a,b] of mappingPairs) {
                 const li = a - leftBase;
                 const ri = b - rightBase;
                 if (li >= 0 && li < n && ri >= 0 && ri < n && !byLeft.has(li)) byLeft.set(li, ri);
               }
               for (let li=0; li<n; li++) {
                 const ri = byLeft.has(li) ? byLeft.get(li) : 0;
                 const pos = origToShuf.has(ri) ? origToShuf.get(ri) : 0;
                 matchKey.push(Number(pos) || 0);
               }
             } else {
               const buckets = new Map();
               for (let i=0;i<rightShuffled.length;i++) {
                 const k = String(rightShuffled[i] ?? '');
                 if (!buckets.has(k)) buckets.set(k, []);
                 buckets.get(k).push(i);
               }
               for (let i=0;i<n;i++) {
                 const k = String(rightList[i] ?? '');
                 const b = buckets.get(k);
                 const pos = b && b.length ? b.shift() : 0;
                 matchKey.push(Number(pos) || 0);
               }
             }
             cappedOptions = leftList;
             answer = rightShuffled;
             item = { ...item, _matchKey: matchKey };
             }
        } else {
             answer = String(answer ?? "").trim();
        }

        return {
          id: uuid(),
          sectionId: sec?.id,
          pakaiGambar: Boolean(sec?.pakaiGambar),
          type,
          context,
          question: invalidMenjodohkan ? '' : question,
          options: cappedOptions,
          answer,
          matchKey: Array.isArray(item?._matchKey) ? item._matchKey : null,
          explanation,
          difficulty,
          bloom,
          materi,
          indikator,
          imagePrompt,
          asciiDiagram: item?.asciiDiagram || null,
          svgSource: item?.svgSource || null,
          image: null,
        };
      };

      function downloadJSON(content, fileName) {
        const a = document.createElement("a");
        const file = new Blob([JSON.stringify(content, null, 2)], { type: "application/json" });
        a.href = URL.createObjectURL(file);
        a.download = fileName;
        a.click();
      }
      function downloadDOC(content, fileName) {
        const a = document.createElement("a");
        const file = new Blob([content], { type: "application/msword" });
        a.href = URL.createObjectURL(file);
        a.download = fileName;
        a.click();
      }
      async function makeQrDataUrl(text, size = 120) {
        try {
          const url = `https://api.qrserver.com/v1/create-qr-code/?size=${size}x${size}&data=${encodeURIComponent(text)}`;
          const resp = await fetch(url);
          if (!resp.ok) throw new Error("QR service error");
          const blob = await resp.blob();
          return await new Promise((resolve) => {
            const fr = new FileReader();
            fr.onload = () => resolve(fr.result);
            fr.readAsDataURL(blob);
          });
        } catch {
          return "";
        }
      }

      function saveProject() {
        const { mataPelajaran, kelas } = state.identity;
        const date = new Date().toISOString().slice(0, 10);
        const fileName = `GuruPintar_${mataPelajaran.replace(/\s+/g, "_")}_${kelas.replace(/\s+/g, "_")}_${date}.json`;
        downloadJSON(state, fileName);
      }

      function loadProject(event) {
        const file = event.target.files[0];
        if (!file) return;
        const reader = new FileReader();
        reader.onload = (e) => {
          try {
            const data = JSON.parse(e.target.result);
            state = { ...DEFAULT_STATE(), ...data };
            try {
              state.identity = state.identity || {};
              const I = state.identity;
              if (!String(I.topik_raw || "").trim() && !String(I.topik_ringkas || "").trim() && String(I.topik || "").trim()) {
                I.topik_raw = String(I.topik || "");
                I.topik_ringkas = String(I.topik || "");
              }
              if (String(I.topik_raw || "").trim() && !String(I.topik_ringkas || "").trim()) {
                const rawTxt = String(I.topik_raw || "").replace(/\r\n/g, "\n").replace(/\r/g, "\n");
                const firstLine = rawTxt.split("\n").map(s => s.trim()).filter(Boolean)[0] || "";
                I.topik_ringkas = (firstLine || rawTxt.slice(0, 180)).trim();
              }
            } catch {}
            try {
              const qst = String(state.quizSubtab || "").trim();
              if (qst === "publish") { state.quizSubtab = "share"; state.quizShareTab = "buat_link"; }
              if (qst === "results") { state.quizSubtab = "share"; state.quizShareTab = "hasil"; }
              if (!state.quizShareTab) state.quizShareTab = "buat_link";
              if (state.quizSubtab !== "live" && state.quizSubtab !== "share") state.quizSubtab = "live";
            } catch {}
            saveDebounced(true);
            render();
            alert("Proyek berhasil dimuat!");
          } catch (err) {
            alert("Gagal memuat file JSON.");
          }
        };
        reader.readAsText(file);
      }

      async function callOpenAI(prompt, timeoutMs = OPENAI_TIMEOUT_MS) {
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(new Error("timeout")), timeoutMs);
        try {
          const response = await fetch("api/openai_proxy.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            credentials: "same-origin",
            body: JSON.stringify({ type: "chat", prompt, model: OPENAI_MODEL }),
            signal: controller.signal,
          });
          if (!response.ok) {
            const errText = await response.text();
            if (response.status === 401) {
              try { alert("Sesi login berakhir. Silakan login ulang."); } catch {}
              try { window.location.href = "login.php"; } catch {}
            }
            throw new Error(`Proxy Error ${response.status}: ${errText}`);
          }
          const data = await response.json();
          return data;
        } finally {
          clearTimeout(timer);
        }
      }

      async function fetchWithRetry(url, options, retries = 3, backoff = 1000) {
        try {
          const response = await fetch(url, options);
          if (response.ok) return response;
          if (retries > 0 && (response.status === 429 || response.status >= 500)) {
            const errText = await response.text();
            console.warn(`Retrying... (${retries} left). Status: ${response.status}. Error: ${errText}`);
            await new Promise(r => setTimeout(r, backoff));
            return fetchWithRetry(url, options, retries - 1, backoff * 2);
          }
          throw new Error(`HTTP Error ${response.status}: ${await response.text()}`);
        } catch (e) {
          if (retries > 0) {
            console.warn(`Retrying connection... (${retries} left). Error: ${e.message}`);
            await new Promise(r => setTimeout(r, backoff));
            return fetchWithRetry(url, options, retries - 1, backoff * 2);
          }
          throw e;
        }
      }

      function buildImageSubject(topic, mapel, question) {
        const t = String(topic || '').toLowerCase();
        const m = String(mapel || '').toLowerCase();
        const q = String(question || '').toLowerCase();
        const src = [t, q].join(' ');
        const stop = ['yang','dan','atau','dengan','pada','dari','di','ke','apa','bagaimana','mengapa','adalah','untuk','dalam','the','of','a','an','to','on','at','by','is','are','do','does','did'];
        const tokens = src.split(/[^a-z0-9]+/i).filter(w => w && !stop.includes(w) && w.length > 2);
        const uniq = [];
        for (const w of tokens) if (!uniq.includes(w)) uniq.push(w);
        const phrase = uniq.slice(0, 5).join(' ') || 'educational diagram';
        const isProcess = /(proses|bagaimana|terjadi|menghasilkan|input|keluaran|siklus|daur|perubahan|langkah)/.test(src);
        const isPart = /(bagian|struktur|penampang|organ|anatomi|komponen|unsur)/.test(src) || /(daun|akar|batang|jantung|lensa|rangkaian)/.test(src);
        const isChart = /(diagram batang|diagram garis|grafik|chart|tabel)/.test(src);
        const isMap = /(peta|map)/.test(src);
        if (isChart) return 'simple chart with axes and few items, minimal colors, labels optional';
        if (isMap) return 'map with simple legend, scale bar and compass rose, minimal colors, labels optional';
        if (isProcess) return `clear vector diagram of ${phrase} with key inputs and outputs, arrows, minimal colors, labels optional`;
        if (isPart) return `cross-section of ${phrase} showing main parts, minimal colors, labels optional`;
        return `simple vector illustration of ${phrase}, minimal colors, white background, labels optional`;
      }

      function stripPrefixForSubject(p) {
        const s = String(p || '');
        const key = 'High quality educational illustration, clear vector style, white background: ';
        if (s.startsWith(key)) return s.slice(key.length);
        return s;
      }
      function enrichSubject(subject, topic, mapel, question) {
        const sub = String(subject || '').trim();
        // Jika terlalu pendek/satu kata atau generik, perluas via builder
        if (sub.length < 15 || /^\w+$/i.test(sub)) {
          return buildImageSubject(topic, mapel, question);
        }
        return sub;
      }
      function extractQuotedWord(text) {
        const m = String(text || '').match(/[\"'‘’“”]([^\"'‘’“”]+)[\"'‘’“”]/);
        return (m && m[1]) ? m[1].trim() : '';
      }
      function pickConcreteOption(options) {
        if (!Array.isArray(options) || options.length === 0) return '';
        const bad = [/^semua jawaban/i, /^tidak ada/i, /^semua di atas/i, /^pilihan/i, /^opsi/i];
        const cleaned = options
          .map(x => String(x || '').trim())
          .filter(x => x.length > 0 && !bad.some(r => r.test(x)));
        if (cleaned.length === 0) return '';
        // Pilih opsi terpendek yang terlihat "bendawi" (satu atau dua kata)
        cleaned.sort((a,b) => a.length - b.length);
        const candidate = cleaned.find(x => x.split(/\s+/).length <= 3) || cleaned[0];
        return candidate;
      }

      async function generateImage(prompt, firstSize = "512x512") {
        const callApi = async (model, p, size) => {
          const response = await fetch("api/openai_proxy.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            credentials: "same-origin",
            body: JSON.stringify({ type: "image", prompt: p, model, size })
          });
          if (!response.ok) {
            const errText = await response.text();
            throw new Error(`Proxy Error ${response.status}: ${errText}`);
          }
          const data = await response.json();
          if (data.data && data.data.length > 0) {
            const b64 = data.data[0].b64_json;
            if (b64) return `data:image/png;base64,${b64}`;
          }
          throw new Error("API Response invalid: " + JSON.stringify(data));
        };

        try {
          const enhancedPrompt = `High quality educational illustration, clear vector style, white background: ${prompt}`;
          return await callApi("gpt-image-1", enhancedPrompt, firstSize);
        } catch (e) {
          console.warn("Image gen first attempt failed, trying fallback...", e);
        }

        try {
          const promptNormal = `High quality educational illustration, clear vector style, white background: ${prompt}`;
          try {
            // fallback ke 512 jika percobaan awal bukan 512, jika sudah 512 tetap ulangi
            const fallbackSize = firstSize === "512x512" ? "512x512" : "512x512";
            return await callApi("gpt-image-1", promptNormal, fallbackSize);
          } catch (e2) {
            console.warn("gpt-image-1 failed, trying dall-e-3...", e2);
            return await callApi("dall-e-3", promptNormal, "1024x1024");
          }
        } catch (e) {
          console.error("Image gen failed", e);
          return null;
        }
      }

      async function makeImageRunFromDataUrl(dataUrl, width, height) {
        try {
          const s = String(dataUrl || '');
          let outBlob = null;
          if (/^data:image\/(png|jpeg);base64,/i.test(s)) {
            const base64 = s.split(',')[1] || '';
            const bin = atob(base64);
            const len = bin.length;
            const bytes = new Uint8Array(len);
            for (let i = 0; i < len; i++) bytes[i] = bin.charCodeAt(i);
            outBlob = new Blob([bytes], { type: s.includes('jpeg') ? 'image/jpeg' : 'image/png' });
          } else {
            const resp = await fetch(s);
            outBlob = await resp.blob();
          }
          if (!outBlob || !["image/png", "image/jpeg"].includes(outBlob.type)) {
            await new Promise((resolve) => {
              const img = new Image();
              img.onload = () => {
                const canvas = document.createElement("canvas");
                canvas.width = img.naturalWidth || 256;
                canvas.height = img.naturalHeight || 256;
                const ctx = canvas.getContext("2d");
                if (ctx) ctx.drawImage(img, 0, 0);
                canvas.toBlob((pngBlob) => {
                  outBlob = pngBlob || outBlob;
                  resolve();
                }, "image/png", 0.92);
              };
              img.onerror = () => resolve();
              img.src = s;
            });
          }
          const buffer = await outBlob.arrayBuffer();
          return new docx.ImageRun({ data: new Uint8Array(buffer), transformation: { width, height } });
        } catch {
          return null;
        }
      }

      function updateQuestionData(id, newData) {
        const idx = state.questions.findIndex((q) => q.id === id);
        if (idx === -1) return;
        state.questions[idx] = { ...state.questions[idx], ...newData };
        saveDebounced(true);
        render();
      }

      const renderNaskah = () => {
        const canDownload = Array.isArray(state.questions) && state.questions.length > 0 && !state._isGenerating;
        const buildNow = `
          <div class="no-print bg-surface-light dark:bg-surface-dark rounded-xl border border-border-light dark:border-border-dark shadow-sm">
            <div class="p-6 flex items-center justify-between gap-3">
              <div>
                <div class="text-xl font-bold">Naskah Soal</div>
                <div class="text-sm text-text-sub-light dark:text-text-sub-dark mt-1">Klik tombol untuk membuat soal otomatis</div>
                <div class="mt-3 flex flex-wrap items-center gap-2">
                  <button id="btnSoalDocxTop" class="${canDownload ? 'inline-flex bg-green-600 hover:bg-green-700 text-white border border-green-600' : 'inline-flex bg-gray-200 text-gray-500 border border-gray-300 opacity-60 cursor-not-allowed'} items-center gap-2 h-9 px-4 rounded-lg text-sm font-bold"
                    ${canDownload ? `onclick="window.__sp.downloadSoalDocx()"` : 'disabled'}>
                    <span class="material-symbols-outlined text-[18px]">download</span>
                    Download .docx
                  </button>
                  <button id="btnSoalPdfTop" class="${canDownload ? 'inline-flex bg-green-600 hover:bg-green-700 text-white border border-green-600' : 'inline-flex bg-gray-200 text-gray-500 border border-gray-300 opacity-60 cursor-not-allowed'} items-center gap-2 h-9 px-4 rounded-lg text-sm font-bold"
                    ${canDownload ? `onclick="window.__sp.downloadSoalPDF()"` : 'disabled'}>
                    <span class="material-symbols-outlined text-[18px]">picture_as_pdf</span>
                    Download PDF
                  </button>
                </div>
              </div>
              <button class="inline-flex items-center gap-2 h-10 px-5 rounded-lg bg-primary hover:bg-blue-600 text-white text-sm font-bold"
                onclick="window.__sp.startBuildSoal()">
                <span class="material-symbols-outlined text-[18px]">auto_awesome</span>
                Buat Soal Sekarang
              </button>
            </div>
          </div>
        `;
        if (state.questions.length === 0) return `<div class="space-y-3">${buildNow}<div class="p-10 text-center">Belum ada soal. Klik "Buat Soal Sekarang".</div></div>`;
        
        const renderItem = (q, i) => `
          <div class="relative group break-inside-avoid">
            <div class="flex gap-4">
              <span class="font-bold text-lg min-w-[1.5rem]">${i + 1}.</span>
              <div class="flex-1 relative group/soal max-h-[60vh] overflow-y-auto pr-2 print:max-h-none print:overflow-visible custom-scrollbar">
                <div class="no-print">
                  <div class="absolute right-2 top-1/2 -translate-y-1/2 flex flex-col gap-1 w-[180px] bg-white/90 dark:bg-surface-dark/80 backdrop-blur-sm px-2 py-1 rounded-lg shadow-sm border border-gray-100 dark:border-gray-700">
                    <button ${q._loadingText ? "disabled" : ""} onclick="regenSingle('${q.id}')" class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-md ${q._loadingText ? "bg-primary text-white opacity-80" : "bg-primary/10 text-primary hover:bg-primary hover:text-white"} text-xs font-bold transition-all">
                      <span class="material-symbols-outlined text-[16px] ${q._loadingText ? "animate-spin" : ""}">${q._loadingText ? "progress_activity" : "autorenew"}</span>
                      <span>${q._loadingText ? "Memproses..." : "Buat Ulang Soal"}</span>
                    </button>
                    ${
                      q.pakaiGambar
                        ? `
                          <button ${q._loadingImage ? "disabled" : ""} onclick="regenImage('${q.id}')" class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-md ${q._loadingImage ? "bg-green-600 text-white opacity-80" : "bg-green-500/10 text-green-600 hover:bg-green-600 hover:text-white"} text-xs font-bold transition-all">
                            <span class="material-symbols-outlined text-[16px] ${q._loadingImage ? "animate-spin" : ""}">${q._loadingImage ? "progress_activity" : "image"}</span>
                            <span>${q._loadingImage ? "Memproses..." : q.image ? "Buat Ulang Gambar" : "Buat Gambar"}</span>
                          </button>
                          ${q.image ? `
                            <button onclick="deleteImage('${q.id}')" class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-md bg-red-500/10 text-red-600 hover:bg-red-600 hover:text-white text-xs font-bold transition-all">
                              <span class="material-symbols-outlined text-[16px]">delete</span>
                              <span>Hapus Gambar</span>
                            </button>
                          ` : ``}
                        `
                        : ``
                    }
                  </div>
                </div>
                <div class="pr-44">
                  <p class="mb-4 pr-10 text-justify leading-relaxed text-lg">${safeText(q.question)}</p>
                  ${q.image ? `<img src="${q.image}" class="w-64 h-64 object-contain rounded-lg mb-2 border shadow-sm">` : ""}
                  ${q._showImagePrompt && !q.image && q.imagePrompt ? `
                    <div class="mb-3 italic text-xs text-text-sub-light flex items-center gap-2">
                      <span>Prompt Gambar: ${safeText(q.imagePrompt)}</span>
                      <button class="inline-flex items-center justify-center size-6 rounded hover:bg-background-light border border-border-light" title="Salin prompt" onclick="copyImagePrompt('${q.id}', this)">
                        <span class="material-symbols-outlined text-[16px]">content_copy</span>
                      </button>
                      <button class="inline-flex items-center justify-center size-6 rounded hover:bg-background-light border border-border-light" title="Buka ChatGPT (prompt disalin)" onclick="openChatGPTWithPrompt('${q.id}', this)">
                        <span class="material-symbols-outlined text-[16px]">smart_toy</span>
                      </button>
                      <a href="https://gemini.google.com/share/03bfbeff5d94" target="_blank" rel="noopener" class="inline-flex items-center justify-center size-6 rounded hover:bg-background-light border border-border-light" title="Buka pembuat prompt">
                        <span class="material-symbols-outlined text-[16px]">open_in_new</span>
                      </a>
                      <button class="inline-flex items-center justify-center size-6 rounded hover:bg-background-light border border-border-light" title="Upload gambar" onclick="document.getElementById('qImgFile-${q.id}').click()">
                        <span class="material-symbols-outlined text-[16px]">file_upload</span>
                      </button>
                      <input id="qImgFile-${q.id}" type="file" accept="image/*" class="hidden" onchange="uploadQuestionImage('${q.id}', this)">
                    </div>
                  ` : ``}
                  ${!q.image && !q.asciiDiagram && !q.svgSource && q._imageError ? `<div class="mb-4 text-xs px-2 py-1 rounded bg-red-100 text-red-700 inline-flex items-center gap-1"><span class="material-symbols-outlined text-[14px]">error</span><span>${safeText(q._imageError)}</span></div>` : ""}
                  ${
                    (q.type === "pg" || q.type === "benar_salah" || q.type === "pg_kompleks")
                      ? `
                        <div class="grid grid-cols-1 gap-2 pl-1">
                          ${q.options.map((opt, oi) => `
                            <div class="flex gap-3 items-start">
                              <span class="font-semibold pt-0.5">${String.fromCharCode(65 + oi)}.</span>
                              <span class="leading-relaxed">${safeText(opt)}</span>
                            </div>`).join("")}
                        </div>
                      `
                      : q.type === "menjodohkan"
                      ? `
                        <div class="grid grid-cols-2 gap-8 text-sm">
                           <div class="space-y-3">
                              ${q.options.map((opt, oi) => `
                                 <div class="flex items-center gap-2">
                                    <span class="font-bold text-xs bg-gray-100 dark:bg-gray-800 px-2 py-1 rounded">${oi + 1}</span>
                                    <div class="p-2 border rounded bg-white dark:bg-gray-800 w-full">${safeText(opt)}</div>
                                 </div>
                              `).join("")}
                           </div>
                           <div class="space-y-3">
                              ${(Array.isArray(q.answer) ? q.answer : []).map((ans, ai) => `
                                 <div class="flex items-center gap-2">
                                    <span class="font-bold text-xs bg-gray-100 dark:bg-gray-800 px-2 py-1 rounded">${String.fromCharCode(65 + ai)}</span>
                                    <div class="p-2 border rounded bg-white dark:bg-gray-800 w-full">${safeText(ans)}</div>
                                 </div>
                              `).join("")}
                           </div>
                        </div>
                      `
                      : `<div class="${q.type === 'uraian' ? 'h-48' : 'h-24'} border-b border-dotted border-black w-full mt-2"></div>`
                  }
                </div>
              </div>
            </div>
          </div>
        `;

        const pg = state.questions.filter(q => q.type === 'pg');
        const essay = state.questions.filter(q => q.type !== 'pg');

        return `
          <div class="space-y-3">
          ${buildNow}
          <div id="paper" class="bg-white text-black p-4 md:p-10 md:shadow-paper md:min-h-[297mm] font-serif border border-gray-200 mx-auto print:border-none print:shadow-none print:p-0">
            <div class="border-b-2 border-black pb-6 mb-8 relative">
              ${state.identity.logo ? `<img src="${state.identity.logo}" class="absolute right-0 top-0 h-16 w-auto">` : ``}
              <div class="text-center mb-6">
                <h2 class="font-bold text-xl md:text-2xl uppercase tracking-wider mb-1">${safeText(state.identity.namaSekolah || "NAMA SEKOLAH")}</h2>
                <h3 class="font-bold text-base md:text-lg uppercase tracking-wide">${safeText(state.paket.judul || "PENILAIAN AKHIR SEMESTER")}</h3>
                <div class="text-sm mt-1">Tahun Pelajaran ${safeText(state.paket.tahunAjaran)}</div>
              </div>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-x-12 gap-y-2 text-sm">
                <div class="space-y-1.5">
                  <div class="flex items-start"><span class="w-36 font-semibold shrink-0">Mata Pelajaran</span><span class="mr-2">:</span><span>${safeText(state.identity.mataPelajaran)}</span></div>
                  <div class="flex items-start"><span class="w-36 font-semibold shrink-0">Kelas / Fase</span><span class="mr-2">:</span><span>${safeText(state.identity.kelas)} / ${safeText(state.identity.fase)}</span></div>
                  ${identityTopikDisplay(state.identity) ? `<div class="flex items-start"><span class="w-36 font-semibold shrink-0">Topik / Lingkup Materi</span><span class="mr-2">:</span><span>${safeText(identityTopikDisplay(state.identity))}</span></div>` : ``}
                  <div class="flex items-center"><span class="w-36 font-semibold shrink-0">Hari / Tanggal</span><span class="mr-2">:</span><div class="border-b border-black border-dotted flex-1 h-4"></div></div>
                </div>
                <div class="space-y-1.5">
                   <div class="flex items-center"><span class="w-36 font-semibold shrink-0">Waktu</span><span class="mr-2">:</span><div class="border-b border-black border-dotted flex-1 h-4"></div></div>
                  <div class="flex items-center"><span class="w-36 font-semibold shrink-0">Nama</span><span class="mr-2">:</span><div class="border-b border-black border-dotted flex-1 h-4"></div></div>
                  <div class="flex items-center"><span class="w-36 font-semibold shrink-0">No. Absen</span><span class="mr-2">:</span><div class="border-b border-black border-dotted flex-1 h-4"></div></div>
                </div>
              </div>
            </div>

            <div class="space-y-6">
              ${(() => {
                const order = ['pg','benar_salah','pg_kompleks','menjodohkan','isian','uraian'];
                const titleMap = { pg: 'PILIHAN GANDA', benar_salah: 'BENAR / SALAH', pg_kompleks: 'PILIHAN GANDA KOMPLEKS', menjodohkan: 'MENJODOHKAN', isian: 'ISIAN SINGKAT', uraian: 'URAIAN' };
                const subtitleMap = {
                  pg: 'Pilihlah salah satu jawaban yang paling tepat!',
                  benar_salah: 'Pilihlah jawaban Benar atau Salah!',
                  pg_kompleks: 'Pilihlah jawaban yang benar (bisa lebih dari satu)!',
                  menjodohkan: 'Jodohkanlah pernyataan pada lajur kiri dengan jawaban pada lajur kanan!',
                  isian: 'Jawablah pertanyaan berikut dengan singkat dan tepat!',
                  uraian: 'Jawablah pertanyaan-pertanyaan berikut dengan jelas dan benar!',
                };
                let firstTypeRendered = false;
                let html = '';
                for (const t of order) {
                  const items = state.questions.filter(q => q.type === t);
                  if (items.length === 0) continue;
                  // pecah per 10 soal
                  const chunks = [];
                  for (let i = 0; i < items.length; i += 10) {
                    chunks.push(items.slice(i, i + 10));
                  }
                  chunks.forEach((chunk, chunkIdx) => {
                    const needPageBreak = firstTypeRendered || chunkIdx > 0;
                    const startIndex = chunkIdx * 10;
                    html += `
                      ${needPageBreak ? '<div style="page-break-before: always;"></div>' : ''}
                      <div>
                        ${chunkIdx === 0 ? `
                          <div class="font-bold mb-1">${titleMap[t]}</div>
                          <div class="italic text-sm mb-4">${subtitleMap[t]}</div>
                        ` : ``}
                        <div class="space-y-6">
                          ${(() => {
                            const normKey = (t) => String(t || '')
                              .replace(/\r\n/g, '\n')
                              .replace(/\r/g, '\n')
                              .replace(/\s+/g, ' ')
                              .trim();
                            let out = '';
                            for (let i = 0; i < chunk.length; i++) {
                              const q = chunk[i] || {};
                              const ctxRaw = String(q.context || '').replace(/\r\n/g, '\n').replace(/\r/g, '\n').trim();
                              if (ctxRaw) {
                                const key = normKey(ctxRaw);
                                let j = i;
                                while (j + 1 < chunk.length) {
                                  const next = chunk[j + 1] || {};
                                  const nextCtx = String(next.context || '').replace(/\r\n/g, '\n').replace(/\r/g, '\n').trim();
                                  if (!nextCtx) break;
                                  if (normKey(nextCtx) !== key) break;
                                  j++;
                                }
                                const a = startIndex + i + 1;
                                const b = startIndex + j + 1;
                                const rangeText = a === b ? `nomor ${a}` : `nomor ${a} s.d. ${b}`;
                                const ctxHtml = safeText(ctxRaw).replace(/\n/g, '<br>');
                                out += `<div class="mb-4 p-3 rounded-lg border border-border-light dark:border-border-dark bg-transparent text-[15px] leading-relaxed">
                                  <div class="font-bold mb-1">Untuk menjawab soal ${rangeText}, pahami bacaan berikut.</div>
                                  <div>${ctxHtml}</div>
                                </div>`;
                                for (let k = i; k <= j; k++) {
                                  out += renderItem({ ...(chunk[k] || {}), context: '' }, startIndex + k);
                                }
                                i = j;
                                continue;
                              }
                              out += renderItem(q, startIndex + i);
                            }
                            return out;
                          })()}
                        </div>
                      </div>
                    `;
                  });
                  firstTypeRendered = true;
                }
                return html;
              })()}
            </div>
          </div>
          <div id="modalPreviewHelp" class="fixed inset-0 hidden items-center justify-center" style="display:none; background: rgba(0,0,0,0.5); z-index:50;">
            <div class="bg-white dark:bg-surface-dark rounded-xl border border-border-light dark:border-border-dark shadow-xl w-[92vw] max-w-[800px] max-h-[85vh] overflow-auto">
              <div class="p-5 border-b border-border-light dark:border-border-dark flex items-center justify-between">
                <div class="font-bold text-lg flex items-center gap-2"><span class="material-symbols-outlined">help</span> Petunjuk Naskah Soal</div>
                <button class="size-9 rounded-lg border bg-white dark:bg-surface-dark" onclick="window.__sp.closePreviewHelp()">&times;</button>
              </div>
              <div class="p-5 space-y-3 text-sm leading-relaxed">
                <ol class="list-decimal pl-5 space-y-2">
                  <li>Buat ulang soal: gunakan ikon refresh/bolt pada setiap butir untuk menghasilkan versi baru soal tersebut.</li>
                  <li>Buat gambar: aktifkan opsi gambar pada Konfigurasi bagian; di tiap soal tersedia tombol untuk membuat/ulang gambar (prioritas ASCII/SVG, jika perlu prompt image).</li>
                  <li>Prompt gambar: teks acuan yang dipakai AI untuk membuat ilustrasi. Tombol di samping prompt:
                    <ul class="list-disc pl-5 mt-1">
                      <li>Salin prompt: menyalin prompt ke clipboard.</li>
                      <li>Buka ChatGPT: membuka ChatGPT (prompt otomatis tersalin).</li>
                      <li>Upload gambar: unggah file gambar jika ingin mengganti hasil AI.</li>
                    </ul>
                  </li>
                  <li>Simpan: tombol “Simpan” di header atas menyimpan proyek Anda (identitas, konfigurasi, dan naskah yang sudah dibuat).</li>
                  <li>Muat: tombol “Muat” memulihkan file proyek (.json) yang tersimpan sebelumnya.</li>
                  <li>Cetak / Unduh: gunakan “Cetak” untuk mencetak halaman, dan “Unduh .docx” untuk mengunduh naskah dalam format Word.</li>
                </ol>
                <div class="rounded-md border border-blue-200 bg-blue-50 text-blue-800 p-3 text-xs">
                  Tips: Lakukan “Simpan” setelah selesai membuat paket, agar konfigurasi dan hasil dapat dimuat ulang kapan saja.
                </div>
              </div>
            </div>
          </div>
          </div>
        `;
      };

      const renderKunci = () => {
        const sections = [
          { type: 'pg', title: 'PILIHAN GANDA' },
          { type: 'benar_salah', title: 'BENAR / SALAH' },
          { type: 'pg_kompleks', title: 'PILIHAN GANDA KOMPLEKS' },
          { type: 'menjodohkan', title: 'MENJODOHKAN' },
          { type: 'isian', title: 'ISIAN SINGKAT' },
          { type: 'uraian', title: 'URAIAN' },
        ];
        let letterIdx = 0;
        const parts = [];
        for (const sec of sections) {
          const items = state.questions.filter(q => q && q.type === sec.type);
          if (!items.length) continue;
          const letter = String.fromCharCode(65 + letterIdx);
          letterIdx++;
          const rows = items.map((q, i) => {
            let ans = '';
            if (sec.type === 'pg') {
              ans = typeof q.answer === 'number' ? String.fromCharCode(65 + q.answer) : String(q.answer ?? '');
            } else if (sec.type === 'benar_salah') {
              const idx = Number(q.answer);
              ans = idx === 1 ? 'Salah' : 'Benar';
            } else if (sec.type === 'pg_kompleks') {
              if (Array.isArray(q.answer)) ans = q.answer.map(n => String.fromCharCode(65 + Number(n))).join(', ');
              else ans = String(q.answer ?? '');
            } else if (sec.type === 'menjodohkan') {
              if (Array.isArray(q.answer)) {
                if (Array.isArray(q.matchKey)) {
                  ans = q.matchKey.map((pos, idx) => `${idx + 1}–${String.fromCharCode(65 + Number(pos || 0))}`).join(', ');
                } else {
                  ans = '';
                }
              } else {
                ans = String(q.answer ?? '');
              }
            } else {
              ans = String(q.answer || '');
            }
            return `<tr><td class="border px-2 py-1 text-center">${i + 1}</td><td class="border px-2 py-1">${safeText(ans || '-')}</td></tr>`;
          }).join('');
          parts.push(`
            <div class="mt-6">
              <div class="font-bold text-base mb-2">${letter}. ${sec.title}</div>
              <table class="w-full text-sm border-collapse">
                <thead><tr><th class="border px-2 py-1 w-14">No</th><th class="border px-2 py-1">Kunci</th></tr></thead>
                <tbody>${rows}</tbody>
              </table>
            </div>
          `);
        }
        return `
          <div class="bg-white p-10 shadow-paper font-serif border border-gray-200 mx-auto print:border-none print:shadow-none print:p-0">
            <div class="font-bold text-lg mb-3">KUNCI JAWABAN</div>
            ${parts.length ? parts.join('') : `<div class="text-sm text-gray-500">Belum ada kunci.</div>`}
          </div>
        `;
      };

      const renderKisi = () => {
        const sections = [
          { type: 'pg', title: 'PILIHAN GANDA', label: 'PG' },
          { type: 'benar_salah', title: 'BENAR / SALAH', label: 'B/S' },
          { type: 'pg_kompleks', title: 'PILIHAN GANDA KOMPLEKS', label: 'PG Komp' },
          { type: 'menjodohkan', title: 'MENJODOHKAN', label: 'Jodoh' },
          { type: 'isian', title: 'ISIAN SINGKAT', label: 'Isian' },
          { type: 'uraian', title: 'URAIAN', label: 'Uraian' },
        ];
        let letterIdx = 0;
        const parts = [];
        for (const sec of sections) {
          const items = state.questions.filter(q => q && q.type === sec.type);
          if (!items.length) continue;
          const letter = String.fromCharCode(65 + letterIdx);
          letterIdx++;
          const rows = items.map((q, i) => `
            <tr>
              <td class="border px-2 py-1 text-center">${i + 1}</td>
              <td class="border px-2 py-1">${safeText(q.materi || '-')}</td>
              <td class="border px-2 py-1">${safeText(q.indikator || '-')}</td>
              <td class="border px-2 py-1">${safeText(q.bloom || '-')}</td>
              <td class="border px-2 py-1 text-center">${safeText(sec.label)}</td>
            </tr>
          `).join('');
          parts.push(`
            <div class="mt-6">
              <div class="font-bold text-base mb-2">${letter}. ${sec.title}</div>
              <table class="w-full text-sm border-collapse">
                <thead>
                  <tr>
                    <th class="border px-2 py-1 w-14">No</th>
                    <th class="border px-2 py-1">Materi</th>
                    <th class="border px-2 py-1">Indikator</th>
                    <th class="border px-2 py-1 w-20">Level</th>
                    <th class="border px-2 py-1 w-24">Bentuk</th>
                  </tr>
                </thead>
                <tbody>${rows}</tbody>
              </table>
            </div>
          `);
        }
        return `
          <div class="bg-white p-10 shadow-paper font-serif border border-gray-200 mx-auto print:border-none print:shadow-none print:p-0">
            <div class="font-bold text-lg mb-3">KISI-KISI</div>
            ${parts.length ? parts.join('') : `<div class="text-sm text-gray-500">Belum ada kisi-kisi.</div>`}
          </div>
        `;
      };

      const LKPD_ACTIVITY_OPTIONS = [
        "Eksperimen / Praktikum",
        "Tugas Proyek",
        "Diskusi Kelompok",
        "Studi Kasus",
        "Observasi Lapangan",
        "Pemecahan Masalah",
      ];

      const renderLKPD = () => {
        const L = state.lkpd || {};
        const jenjangEfektif = resolveJenjang(L.jenjang, L.kesetaraanPaket);
        const isKesetaraan = String(L.jenjang || "").trim() === "Kesetaraan";
        const faseOpts = MA_FASE_MAP[jenjangEfektif] || [];
        const sumberTopikActive = L.sumber === "topik";
        const sumberUploadActive = L.sumber === "upload";
        const segBtn = (active, label, onclick) =>
          `<button onclick="${onclick}" class="px-3 py-2 text-sm font-bold border ${active ? 'bg-primary text-white border-primary' : 'bg-white dark:bg-surface-dark border-border-light dark:border-border-dark hover:bg-background-light dark:hover:bg-background-dark'}">${label}</button>`;
        return `
          <div class="space-y-6">
            <div class="bg-surface-light dark:bg-surface-dark rounded-xl border border-border-light dark:border-border-dark shadow-sm overflow-hidden">
              <div class="p-6 space-y-6">
                <div class="flex items-center justify-between gap-3">
                  <div>
                    <div class="text-xs font-bold text-primary bg-primary/10 inline-flex px-3 py-1 rounded-full">Langkah 1</div>
                    <div class="text-xl font-bold mt-2">Identitas Aktivitas</div>
                  </div>
                </div>
                <div class="space-y-3">
                  <div class="text-sm font-semibold text-text-sub-light dark:text-text-sub-dark">Sumber Materi</div>
                  <div class="inline-flex rounded-lg overflow-hidden">
                    ${segBtn(sumberTopikActive, "Topik Singkat", "window.__sp.setLkpdSource('topik')")}
                    ${segBtn(sumberUploadActive, "Upload / Paste Materi", "window.__sp.setLkpdSource('upload')")}
                  </div>
                </div>
                <div class="grid grid-cols-1 gap-5">
                  ${sumberTopikActive
                    ? inputText("Topik Singkat", "lkpd.topik", L.topik, "Contoh: Ekosistem Sawah, Perlawanan Diponegoro")
                    : `
                      <div class="flex flex-col gap-2">
                        <label class="text-sm font-semibold text-text-sub-light dark:text-text-sub-dark">Materi (Paste / Ringkas)</label>
                        <div class="relative">
                          <textarea
                            class="w-full rounded-lg border-border-light dark:border-border-dark bg-background-light dark:bg-background-dark/40 focus:border-primary focus:ring-primary px-4 py-3 text-sm min-h-[280px] pr-44"
                            data-path="lkpd.materi"
                            placeholder="Paste materi pelajaran lengkap di sini, atau upload file..."
                          >${safeText(L.materi || "")}</textarea>
                          <div class="absolute bottom-3 right-3 flex gap-2">
                            <button id="btnLkpdUploadImg" onclick="window.__sp.pickLkpdImage()" class="flex items-center gap-2 rounded-lg h-8 px-3 bg-white dark:bg-surface-dark border border-border-light dark:border-border-dark hover:bg-background-light dark:hover:bg-background-dark text-sm font-bold shadow-sm transition-colors">
                              <span class="material-symbols-outlined text-[16px]">image</span>
                              Upload Gambar
                            </button>
                            <button id="btnLkpdUploadTxt" onclick="window.__sp.pickLkpdText()" class="flex items-center gap-2 rounded-lg h-8 px-3 bg-white dark:bg-surface-dark border border-border-light dark:border-border-dark hover:bg-background-light dark:hover:bg-background-dark text-sm font-bold shadow-sm transition-colors">
                              <span class="material-symbols-outlined text-[16px]">description</span>
                              Upload Teks
                            </button>
                          </div>
                        </div>
                      </div>
                    `}
                </div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                  <div class="flex flex-col gap-2">
                    <label class="text-sm font-semibold text-text-sub-light dark:text-text-sub-dark">Jenjang</label>
                    <select class="w-full rounded-lg border-border-light dark:border-border-dark bg-background-light dark:bg-background-dark/40 focus:border-primary focus:ring-primary px-4 py-3 text-sm" data-path="lkpd.jenjang">
                      <option value="">Pilih...</option>
                      ${["PAUD","TK","SD/MI","SMP/MTs","SMA/MA","SMK/MAK","Kesetaraan"].map(v => `<option value="${safeText(v)}"${String(L.jenjang||"")===v ? " selected" : ""}>${safeText(v)}</option>`).join("")}
                    </select>
                    <div class="${isKesetaraan ? "" : "hidden"} mt-2 flex flex-col gap-2">
                      <label class="text-sm font-semibold text-text-sub-light dark:text-text-sub-dark">Paket</label>
                      <select class="w-full rounded-lg border-border-light dark:border-border-dark bg-background-light dark:bg-background-dark/40 focus:border-primary focus:ring-primary px-4 py-3 text-sm" data-path="lkpd.kesetaraanPaket">
                        <option value="">Pilih Paket...</option>
                        ${KES_PAKET_OPTIONS.map(v => `<option value="${safeText(v)}"${String(L.kesetaraanPaket||"")===v ? " selected" : ""}>${safeText(v)}</option>`).join("")}
                      </select>
                    </div>
                  </div>
                  ${selectField("Fase", "lkpd.fase", L.fase, faseOpts)}
                  ${selectField("Kelas", "lkpd.kelas", L.kelas, CLASS_OPTIONS[jenjangEfektif] || [])}
                </div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                  ${selectField("Mata Pelajaran", "lkpd.mataPelajaran", L.mataPelajaran, SUBJECT_OPTIONS[jenjangEfektif] || [])}
                  ${selectField("Jenis Aktivitas", "lkpd.jenisAktivitas", L.jenisAktivitas, LKPD_ACTIVITY_OPTIONS)}
                  <div></div>
                </div>
              </div>
            </div>
            <div class="bg-surface-light dark:bg-surface-dark rounded-xl border border-border-light dark:border-border-dark shadow-sm overflow-hidden">
              <div class="p-6 space-y-6">
                <div>
                  <div class="text-xs font-bold text-primary bg-primary/10 inline-flex px-3 py-1 rounded-full">Langkah 2</div>
                  <div class="text-xl font-bold mt-2">Detail Konten</div>
                </div>
                <div class="grid grid-cols-1 gap-5">
                  ${inputTextarea("Tujuan Pembelajaran (Opsional)", "lkpd.tujuan", L.tujuan, "Contoh: Siswa dapat menjelaskan proses fotosintesis...")}
                  ${inputText("Link Materi Digital (Video/Artikel)", "lkpd.link", L.link, "https://...")}
                </div>
                <div class="pt-2">
                  <button id="btnBuildLKPD" onclick="window.__sp.buildLKPD()" class="w-full md:w-auto flex items-center justify-center gap-2 rounded-lg h-10 px-4 bg-primary hover:bg-blue-600 text-primary-content text-sm font-bold shadow-sm transition-colors">
                    <span class="material-symbols-outlined text-[18px]">auto_awesome</span>
                    BUAT LKPD SEKARANG
                  </button>
                </div>
              </div>
            </div>
          </div>
        `;
      };

      

      // ═══════════════════════════════════════════════
      //  MODUL AJAR — renderModulAjar / build / export
      // ═══════════════════════════════════════════════
      const renderRekap = () => {
        if (!HAS_REKAP_ACCESS) {
          return `
            <div class="space-y-4">
              <div class="bg-surface-light dark:bg-surface-dark rounded-xl border border-border-light dark:border-border-dark shadow-sm overflow-hidden">
                <div class="p-6 border-b border-border-light dark:border-border-dark">
                  <div>
                    <div class="text-2xl font-bold">Rekap Nilai Otomatis</div>
                    <div class="text-sm text-text-sub-light">Rekap nilai untuk semua jenjang dan mata pelajaran</div>
                  </div>
                </div>
                <div class="p-6">
                  <div class="rounded-lg border border-amber-200 bg-amber-50 text-amber-900 p-4 text-sm">
                    Akses fitur Rekap Nilai belum diaktifkan untuk akun Anda. Upgrade ke paket Pro (50rb) untuk akses modul Quiz Online dan Rekap Nilai. Hubungi Admin <a class="font-semibold underline text-blue-700" href="https://wa.me/6282174028646" target="_blank" rel="noopener">klik di sini</a>.
                  </div>
                </div>
              </div>
            </div>
          `;
        }
        const statCard = (title, value, color) => `
          <div class="rounded-xl border border-border-light dark:border-border-dark bg-white dark:bg-surface-dark p-4 flex items-center gap-3">
            <div class="size-9 rounded-lg ${color} text-white flex items-center justify-center">
              <span class="material-symbols-outlined">insights</span>
            </div>
            <div>
              <div class="text-xs text-text-sub-light dark:text-text-sub-dark">${title}</div>
              <div class="text-lg font-extrabold">${value}</div>
            </div>
          </div>`;
        const chip = (label, active=false) => `
          <button class="px-3 h-9 rounded-full border text-sm font-semibold ${active ? 'bg-primary text-white border-primary' : 'bg-white dark:bg-surface-dark border-border-light dark:border-border-dark hover:bg-background-light dark:hover:bg-background-dark'}">${label}</button>`;
        const rows = [
          {no:1,nama:'Andi Pratama',kelas:'7A',rata:'92.5',tugas:'92.0',uts:'95',uas:'95',uak:'95',akhir:'95.0',pred:'A',rank:1},
          {no:2,nama:'Budi Setiawan',kelas:'7A',rata:'96.0',tugas:'92.0',uts:'95',uas:'95',uak:'92',akhir:'97.0',pred:'A',rank:2},
          {no:3,nama:'Citra Dewi',kelas:'7A',rata:'86.7',tugas:'84.0',uts:'90',uas:'80',uak:'87',akhir:'72.0',pred:'B',rank:3},
          {no:4,nama:'Dewi Cahya',kelas:'7A',rata:'85.0',tugas:'85.0',uts:'95',uas:'88',uak:'86',akhir:'85.5',pred:'B',rank:4},
          {no:5,nama:'Erika Lestari',kelas:'7A',rata:'89.3',tugas:'82.0',uts:'85',uas:'86',uak:'80',akhir:'88.0',pred:'B',rank:5},
          {no:6,nama:'Farhan Maulana',kelas:'7A',rata:'83.5',tugas:'92.0',uts:'95',uas:'90',uak:'88',akhir:'85.5',pred:'B',rank:6},
          {no:7,nama:'Galih Putra',kelas:'7A',rata:'91.5',tugas:'92.0',uts:'96',uas:'76',uak:'76',akhir:'62.0',pred:'C',rank:7},
          {no:8,nama:'Hafiz Alamsyah',kelas:'7A',rata:'81.9',tugas:'84.0',uts:'96',uas:'76',uak:'76',akhir:'62.0',pred:'C',rank:8},
          {no:9,nama:'Indah Wati',kelas:'7A',rata:'62.0',tugas:'62.0',uts:'86',uas:'70',uak:'76',akhir:'62.0',pred:'C',rank:9},
          {no:10,nama:'Joko Santoso',kelas:'7A',rata:'70.0',tugas:'73.0',uts:'76',uas:'70',uak:'70',akhir:'73.5',pred:'C',rank:10},
        ].map(r => {
          const gradeClass = Number(r.akhir) >= 90 ? 'bg-green-100 text-green-700' : Number(r.akhir)>=75 ? 'bg-amber-100 text-amber-700' : 'bg-red-100 text-red-700';
          return `<tr>
            <td class="border px-3 py-2 text-center">${r.no}</td>
            <td class="border px-3 py-2">${r.nama}</td>
            <td class="border px-3 py-2 text-center">${r.kelas}</td>
            <td class="border px-3 py-2 text-center">${r.rata}</td>
            <td class="border px-3 py-2 text-center">${r.tugas}</td>
            <td class="border px-3 py-2 text-center">${r.uts}</td>
            <td class="border px-3 py-2 text-center">${r.uas}</td>
            <td class="border px-3 py-2 text-center">${r.uak}</td>
            <td class="border px-3 py-2 text-center"><span class="px-2 py-1 rounded ${gradeClass}">${r.akhir}</span></td>
            <td class="border px-3 py-2 text-center">${r.pred}</td>
            <td class="border px-3 py-2 text-center">${r.rank <= 3 ? `<span class="material-symbols-outlined text-amber-500 align-middle">trophy</span> ${r.rank}` : r.rank}</td>
          </tr>`;
        }).join('');
        return `
          <div class="space-y-4">
            <div class="bg-surface-light dark:bg-surface-dark rounded-xl border border-border-light dark:border-border-dark shadow-sm overflow-hidden">
              <div class="p-6 border-b border-border-light dark:border-border-dark">
                <div>
                  <div class="text-2xl font-bold">Rekap Nilai Otomatis</div>
                  <div class="text-sm text-text-sub-light">Rekap nilai untuk semua jenjang dan mata pelajaran</div>
                </div>
              </div>
              <div class="p-6 space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                  <div class="flex items-center gap-2">
                    <button onclick="document.getElementById('rekapExcelPicker').click()" class="px-4 h-10 rounded-lg bg-primary text-white font-bold"><span class="material-symbols-outlined text-[18px]">upload</span> <span class="ml-1">Upload Excel</span></button>
                    <button onclick="window.__sp.rekapDownloadTemplate()" class="px-4 h-10 rounded-lg border bg-white dark:bg-surface-dark"><span class="material-symbols-outlined text-[18px]">download</span> <span class="ml-1">Download Template</span></button>
                    
                    <button class="px-4 h-10 rounded-lg border bg-white dark:bg-surface-dark" onclick="window.__sp.openBobotModal()"><span class="material-symbols-outlined text-[18px]">tune</span> <span class="ml-1">Atur Bobot</span></button>
                    <button class="px-4 h-10 rounded-lg border bg-white dark:bg-surface-dark" onclick="window.__sp.openPredikatModal()"><span class="material-symbols-outlined text-[18px]">grading</span> <span class="ml-1">Atur Predikat</span></button>
                    <button class="px-4 h-10 rounded-lg border bg-white dark:bg-surface-dark" onclick="window.__sp.openRekapHelp()"><span class="material-symbols-outlined text-[18px]">help</span> <span class="ml-1">Petunjuk</span></button>
                  </div>
                  <div>
                    <button class="px-4 h-10 rounded-lg border bg-white dark:bg-surface-dark" onclick="window.__sp.openRekapPrint()"><span class="material-symbols-outlined text-[18px]">print</span> <span class="ml-1">Cetak</span></button>
                  </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                  <div class="rounded-xl border border-border-light dark:border-border-dark bg-white dark:bg-surface-dark p-4 flex items-center gap-3">
                    <div class="size-9 rounded-lg bg-green-500 text-white flex items-center justify-center">
                      <span class="material-symbols-outlined">insights</span>
                    </div>
                    <div>
                      <div class="text-xs text-text-sub-light dark:text-text-sub-dark">Nilai Tertinggi</div>
                      <div id="rekapMax" class="text-lg font-extrabold">0</div>
                    </div>
                  </div>
                  <div class="rounded-xl border border-border-light dark:border-border-dark bg-white dark:bg-surface-dark p-4 flex items-center gap-3">
                    <div class="size-9 rounded-lg bg-red-500 text-white flex items-center justify-center">
                      <span class="material-symbols-outlined">insights</span>
                    </div>
                    <div>
                      <div class="text-xs text-text-sub-light dark:text-text-sub-dark">Nilai Terendah</div>
                      <div id="rekapMin" class="text-lg font-extrabold">0</div>
                    </div>
                  </div>
                  <div class="rounded-xl border border-border-light dark:border-border-dark bg-white dark:bg-surface-dark p-4 flex items-center gap-3">
                    <div class="size-9 rounded-lg bg-blue-500 text-white flex items-center justify-center">
                      <span class="material-symbols-outlined">insights</span>
                    </div>
                    <div>
                      <div class="text-xs text-text-sub-light dark:text-text-sub-dark">Rata-Rata Kelas</div>
                      <div id="rekapAvg" class="text-lg font-extrabold">0</div>
                    </div>
                  </div>
                </div>
                <div class="flex items-center justify-between gap-3">
                  <div>
                    <div class="inline-flex items-center gap-2">
                      <button class="px-3 h-9 rounded-lg border bg-white dark:bg-surface-dark flex items-center gap-2"><span class="material-symbols-outlined text-[18px]">event</span> Periode</button>
                      <button class="px-3 h-9 rounded-lg border bg-white dark:bg-surface-dark flex items-center gap-2"><span class="material-symbols-outlined text-[18px]">tune</span> Kolom</button>
                    </div>
                  </div>
                  <div class="relative">
                    <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-text-sub-light text-[18px]">search</span>
                    <input id="rekapSearch" placeholder="Cari siswa..." class="pl-9 pr-3 h-9 rounded-lg border bg-white dark:bg-surface-dark w-64" />
                  </div>
                </div>
                <div class="overflow-auto">
                  <table id="rekapTable" class="min-w-full text-sm border">
                    <thead class="bg-background-light dark:bg-background-dark">
                      <tr id="rekapHeadRow">
                        <th class="border px-3 py-2">No</th>
                        <th class="border px-3 py-2 text-left">Nama Siswa</th>
                        <th class="border px-3 py-2">Kelas</th>
                        <th class="border px-3 py-2">Rata-Rata</th>
                        <th class="border px-3 py-2">Rata-Rata Tugas</th>
                        <th class="border px-3 py-2">Nilai UTS</th>
                        <th class="border px-3 py-2">Nilai UAS</th>
                        <th class="border px-3 py-2">Nilai UAK</th>
                        <th class="border px-3 py-2">Nilai Akhir</th>
                        <th class="border px-3 py-2">Predikat</th>
                        <th class="border px-3 py-2">Ranking</th>
                      </tr>
                    </thead>
                    <tbody id="rekapTBody">${rows}</tbody>
                  </table>
                </div>
                <div class="flex items-center justify-between text-xs text-text-sub-light">
                  <div>Menampilkan <span id="rekapTotalRows">0</span> dari <span id="rekapTotalData">0</span> data</div>
                  <div class="flex items-center gap-1">
                    <button class="size-8 rounded border bg-white dark:bg-surface-dark">&laquo;</button>
                    <button class="size-8 rounded border bg-white dark:bg-surface-dark">1</button>
                    <button class="size-8 rounded border bg-white dark:bg-surface-dark">&raquo;</button>
                  </div>
                </div>
              </div>
            </div>
            <div id="modalBobot" class="fixed inset-0 hidden items-center justify-center" style="display:none; background: rgba(0,0,0,0.5); z-index:50;">
              <div class="bg-white dark:bg-surface-dark rounded-xl border border-border-light dark:border-border-dark shadow-xl w-[90vw] max-w-[720px] max-h-[80vh] overflow-auto">
                <div class="p-5 border-b border-border-light dark:border-border-dark flex items-center justify-between">
                  <div class="font-bold text-lg flex items-center gap-2"><span class="material-symbols-outlined">scale</span> Atur Bobot Penilaian</div>
                  <button class="size-9 rounded-lg border bg-white dark:bg-surface-dark" onclick="window.__sp.closeBobotModal()">&times;</button>
                </div>
                <div class="p-5 space-y-3">
                  <div class="text-xs rounded-md border border-blue-200 bg-blue-50 text-blue-800 p-3">Total bobot harus 100%. Atur sesuai kebijakan sekolah.</div>
                  <div id="bobotInputs" class="grid grid-cols-1 gap-2"></div>
                  <div class="flex items-center justify-between bg-background-light dark:bg-background-dark rounded-lg p-3">
                    <div class="text-sm font-semibold">Total Bobot</div>
                    <div id="totalBobotDisplay" class="text-lg font-extrabold text-primary">0%</div>
                  </div>
                  <div class="flex items-center justify-end gap-2">
                    <button class="px-3 h-10 rounded-lg border bg-white dark:bg-surface-dark" onclick="window.__sp.resetBobot()">Reset (Rata)</button>
                    <button class="px-4 h-10 rounded-lg bg-primary text-white" onclick="window.__sp.saveBobotSettings()">Simpan</button>
                  </div>
                </div>
              </div>
            </div>
            <div id="modalPredikat" class="fixed inset-0 hidden items-center justify-center" style="display:none; background: rgba(0,0,0,0.5); z-index:50;">
              <div class="bg-white dark:bg-surface-dark rounded-xl border border-border-light dark:border-border-dark shadow-xl w-[90vw] max-w-[720px] max-h-[80vh] overflow-auto">
                <div class="p-5 border-b border-border-light dark:border-border-dark flex items-center justify-between">
                  <div class="font-bold text-lg flex items-center gap-2"><span class="material-symbols-outlined">grading</span> Atur Predikat</div>
                  <button class="size-9 rounded-lg border bg-white dark:bg-surface-dark" onclick="window.__sp.closePredikatModal()">&times;</button>
                </div>
                <div class="p-5 space-y-3">
                  <div class="text-xs rounded-md border border-amber-200 bg-amber-50 text-amber-800 p-3">Rentang nilai 0–100, tanpa tumpang tindih. Untuk menonaktifkan predikat, set Min=0 dan Max=0.</div>
                  <div id="predikatInputs" class="grid grid-cols-1 gap-2"></div>
                  <div class="flex items-center justify-end gap-2">
                    <button class="px-3 h-10 rounded-lg border bg-white dark:bg-surface-dark" onclick="window.__sp.resetPredikat()">Reset Default</button>
                    <button class="px-4 h-10 rounded-lg bg-primary text-white" onclick="window.__sp.savePredikatSettings()">Simpan</button>
                  </div>
                </div>
              </div>
            </div>
            <div id="modalRekapHelp" class="fixed inset-0 hidden items-center justify-center" style="display:none; background: rgba(0,0,0,0.5); z-index:50;">
              <div class="bg-white dark:bg-surface-dark rounded-xl border border-border-light dark:border-border-dark shadow-xl w-[92vw] max-w-[800px] max-h-[85vh] overflow-auto">
                <div class="p-5 border-b border-border-light dark:border-border-dark flex items-center justify-between">
                  <div class="font-bold text-lg flex items-center gap-2"><span class="material-symbols-outlined">help</span> Petunjuk Rekap Nilai</div>
                  <button class="size-9 rounded-lg border bg-white dark:bg-surface-dark" onclick="window.__sp.closeRekapHelp()">&times;</button>
                </div>
                <div class="p-5 space-y-4 text-sm leading-relaxed">
                  <div class="rounded-md border border-blue-200 bg-blue-50 text-blue-800 p-3">Gunakan template agar header dikenali otomatis. Sistem akan melewati baris judul seperti “TEMPLATE 1”.</div>
                  <ol class="list-decimal pl-5 space-y-2">
                    <li>Siapkan file Excel dengan header minimal: No, Nama Siswa, Kelas, lalu kolom nilai bebas (mis. Tugas, UH, UTS, UAS).</li>
                    <li>Pilih Upload Excel dan buka file Anda.</li>
                    <li>Sistem mendeteksi kolom nilai dan membagi bobot rata. Nilai Akhir, Predikat, dan Ranking dihitung otomatis.</li>
                    <li>Klik Atur Bobot untuk mengubah bobot per kolom hingga total tepat 100%. Simpan untuk menghitung ulang.</li>
                    <li>Klik Atur Predikat untuk mengubah rentang nilai tiap grade. Untuk menonaktifkan grade, set Min=0 dan Max=0.</li>
                    <li>Gunakan kolom Cari siswa untuk memfilter tabel secara cepat.</li>
                    <li>Download Template untuk mengunduh contoh template Excel.</li>
                    <li>Gunakan Cetak untuk mencetak laporan rekap.</li>
                  </ol>
                  <div>
                    <div class="font-semibold mb-1">Catatan</div>
                    <ul class="list-disc pl-5 space-y-1">
                      <li>Range nilai dibatasi 0–100 dan tidak boleh tumpang tindih.</li>
                      <li>Jika sebuah nilai di luar semua rentang aktif, sistem menggunakan grade dengan batas minimum terendah sebagai fallback.</li>
                      <li>Jika Anda mengubah bobot atau predikat, pastikan klik Simpan agar perhitungan diperbarui.</li>
                    </ul>
                  </div>
                </div>
              </div>
            </div>
            <div id="modalRekapPrint" class="fixed inset-0 hidden items-center justify-center" style="display:none; background: rgba(0,0,0,0.5); z-index:50;">
              <div class="bg-white dark:bg-surface-dark rounded-xl border border-border-light dark:border-border-dark shadow-xl w-[92vw] max-w-[700px] max-h-[85vh] overflow-auto">
                <div class="p-5 border-b border-border-light dark:border-border-dark flex items-center justify-between">
                  <div class="font-bold text-lg flex items-center gap-2"><span class="material-symbols-outlined">print</span> Cetak Laporan Rekap</div>
                  <button class="size-9 rounded-lg border bg-white dark:bg-surface-dark" onclick="window.__sp.closeRekapPrint()">&times;</button>
                </div>
                <div class="p-5 space-y-4 text-sm">
                  <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div>
                      <label class="text-xs font-semibold text-text-sub-light">Nama Mata Pelajaran (opsional)</label>
                      <input id="printMapel" class="w-full h-10 rounded-lg border bg-white dark:bg-surface-dark px-3" placeholder="mis. Matematika" />
                    </div>
                    <div>
                      <label class="text-xs font-semibold text-text-sub-light">Nama Guru (opsional)</label>
                      <input id="printGuru" class="w-full h-10 rounded-lg border bg-white dark:bg-surface-dark px-3" placeholder="mis. Budi Hartono, S.Pd" />
                    </div>
                  </div>
                  <div class="flex items-center gap-3">
                    <button onclick="document.getElementById('rekapPrintLogoPicker').click()" class="px-3 h-10 rounded-lg border bg-white dark:bg-surface-dark"><span class="material-symbols-outlined text-[18px]">imagesmode</span> <span class="ml-1">Upload Logo Sekolah (opsional)</span></button>
                    <span id="printLogoName" class="text-xs text-text-sub-light"></span>
                  </div>
                  <div class="flex items-center justify-end gap-2 pt-2">
                    <button class="px-4 h-10 rounded-lg border bg-white dark:bg-surface-dark" onclick="window.__sp.closeRekapPrint()">Batal</button>
                    <button class="px-4 h-10 rounded-lg bg-primary text-white" onclick="window.__sp.generateRekapPDF()">Cetak (PDF)</button>
                  </div>
                </div>
              </div>
            </div>
          </div>
        `;
      };
      const MA_FASE_MAP = {
        "SD/MI":   ["Fase A (Kelas 1–2)","Fase B (Kelas 3–4)","Fase C (Kelas 5–6)"],
        "SMP/MTs": ["Fase D (Kelas 7–9)"],
        "SMA/MA":  ["Fase E (Kelas 10)","Fase F (Kelas 11–12)"],
        "SMK":     ["Fase D (Kelas 7–9)","Fase E (Kelas 10)","Fase F (Kelas 11–12)"],
        "SMK/MAK": ["Fase E (Kelas 10)","Fase F (Kelas 11–12/13)"],
        "Paket A": ["Fase A (Kelas 1–2)","Fase B (Kelas 3–4)","Fase C (Kelas 5–6)"],
        "Paket B": ["Fase D (Kelas 7–9)"],
        "Paket C": ["Fase E (Kelas 10)","Fase F (Kelas 11–12)"],
        "PAUD":    ["Fase Fondasi"],
        "TK":      ["Fase Fondasi"],
      };
      const MA_MODEL = [
        "Project Based Learning (PjBL)",
        "Problem Based Learning (PBL)",
        "Discovery Learning","Inquiry Learning",
        "Direct Learning","Cooperative Learning",
      ];
      const MA_DIMENSI = [
        {v:"Keimanan, Ketakwaan & Akhlak Mulia", ic:"volunteer_activism"},
        {v:"Kewargaan",          ic:"account_balance"},
        {v:"Penalaran Kritis",   ic:"psychology"},
        {v:"Kreativitas",        ic:"palette"},
        {v:"Kolaborasi",         ic:"group"},
        {v:"Kemandirian",        ic:"self_improvement"},
        {v:"Kesehatan",          ic:"favorite"},
        {v:"Komunikasi",         ic:"forum"},
      ];

      const renderModulAjar = () => {
        const M = state.modulAjar || {};
        const jenjangEfektif = resolveJenjang(M.jenjang, M.kesetaraanPaket);
        const isKesetaraan = String(M.jenjang || "").trim() === "Kesetaraan";
        const faseOpts  = MA_FASE_MAP[jenjangEfektif] || [];
        const kelasOpts = CLASS_OPTIONS[jenjangEfektif] || [];
        const dimArr    = Array.isArray(M.dimensi) ? M.dimensi : [];
        const hasilAda  = !!M.hasil;
        const isRefiningKegiatan = !!M.isRefiningKegiatan;
        const kegiatanRefinedOnce = !!M.kegiatanRefinedOnce;
        const tab = state.modulAjarTab || (hasilAda ? 'modul' : 'informasi');
        const maErr = state.modulAjarError;
        const pendekatanOpts = ['Standar','CTL (Contextual Teaching and Learning)','Deep Learning','Deep Learning + CTL','Berbasis Cinta (KBC)','Deep Learning + KBC'];
        if (M.pendekatan && !pendekatanOpts.includes(M.pendekatan)) pendekatanOpts.unshift(M.pendekatan);
        const kurikulumOpts = ['Kurikulum Merdeka','Kurikulum 2013 (K13)'];
        if (M.kurikulum && !kurikulumOpts.includes(M.kurikulum)) kurikulumOpts.unshift(M.kurikulum);

        const mkSel = (lbl, key, val, opts) => `
          <div class="flex flex-col gap-2">
            <label class="text-sm font-semibold text-text-sub-light dark:text-text-sub-dark">${safeText(lbl)}</label>
            <select class="w-full rounded-lg border-border-light dark:border-border-dark bg-background-light dark:bg-background-dark/40 focus:border-primary focus:ring-primary h-11 px-4 text-sm"
              data-ma-key="${safeText(key)}"
              onchange="window.__sp.setMA('${key}',this.value,true)">
              <option value="">— Pilih —</option>
              ${opts.map(o=>`<option value="${safeText(o)}" ${String(o)===String(val||'')?'selected':''}>${safeText(o)}</option>`).join('')}
            </select>
          </div>`;

        const mkInp = (lbl, key, val, ph='') => `
          <div class="flex flex-col gap-2">
            <label class="text-sm font-semibold text-text-sub-light dark:text-text-sub-dark">${safeText(lbl)}</label>
            <input class="w-full rounded-lg border-border-light dark:border-border-dark bg-background-light dark:bg-background-dark/40 focus:border-primary focus:ring-primary h-11 px-4 text-sm"
              data-ma-key="${safeText(key)}"
              placeholder="${safeText(ph)}" value="${safeText(val||'')}"
              oninput="window.__sp.setMA('${key}',this.value,false)"
              onchange="window.__sp.setMA('${key}',this.value,true)"
              onblur="window.__sp.setMA('${key}',this.value,true)" />
          </div>`;

        const dimChecks = MA_DIMENSI.map(d => {
          const on = dimArr.includes(d.v);
          return `<label class="flex items-center gap-2.5 p-3 rounded-lg border cursor-pointer transition-colors select-none
            ${on ? 'border-primary bg-primary/5 dark:bg-primary/10' : 'border-border-light dark:border-border-dark bg-white dark:bg-surface-dark hover:border-primary/40'}">
            <input type="checkbox" class="accent-primary shrink-0" ${on?'checked':''}
              onchange="window.__sp.toggleMADimensi('${safeText(d.v)}',this.checked)">
            <span class="material-symbols-outlined text-[16px] ${on?'text-primary':'text-text-sub-light'}">${d.ic}</span>
            <span class="text-sm font-medium leading-snug">${safeText(d.v)}</span>
          </label>`;
        }).join('');

        const helpOnClick = tab === 'informasi'
          ? "window.__sp.openMAHelp1()"
          : (tab === 'detail' ? "window.__sp.openMAHelp2()" : "window.__sp.openMAHelp3()");

        const desktopTabs = `
          <div class="hidden md:flex items-center justify-between gap-3 mb-4">
            <div class="inline-flex rounded-lg border bg-white dark:bg-surface-dark overflow-x-auto no-scrollbar">
              ${[
                { id: "informasi", label: "1. Informasi Dasar" },
                { id: "detail", label: "2. Detail Pembelajaran" },
                { id: "modul", label: "3. Modul Ajar" },
              ].map(t => {
                const active = tab === t.id;
                return `<button class="${active ? 'bg-primary text-white' : 'bg-white dark:bg-surface-dark'} px-4 h-10 rounded-lg text-sm font-bold whitespace-nowrap" onclick="window.__sp.setModulAjarTab('${t.id}')">${t.label}</button>`;
              }).join('')}
            </div>
            <div class="flex items-center gap-2">
              <button class="inline-flex items-center justify-center h-10 px-4 rounded-lg border bg-white dark:bg-surface-dark hover:bg-background-light dark:hover:bg-background-dark text-sm font-bold"
                onclick="saveProject()" title="Simpan">
                <span class="material-symbols-outlined text-[18px]">save</span>
                <span class="ml-2 hidden lg:inline">Simpan</span>
              </button>
              <button class="inline-flex items-center justify-center h-10 px-4 rounded-lg border bg-white dark:bg-surface-dark hover:bg-background-light dark:hover:bg-background-dark text-sm font-bold"
                onclick="document.getElementById('projectPicker').value=''; document.getElementById('projectPicker').click();" title="Muat">
                <span class="material-symbols-outlined text-[18px]">folder_open</span>
                <span class="ml-2 hidden lg:inline">Muat</span>
              </button>
              <button class="inline-flex items-center justify-center h-10 px-4 rounded-lg border bg-white dark:bg-surface-dark hover:bg-background-light dark:hover:bg-background-dark text-sm font-bold"
                onclick="${helpOnClick}" title="Petunjuk">
                <span class="material-symbols-outlined text-[18px]">help</span>
                <span class="ml-2">Petunjuk</span>
              </button>
              <button class="inline-flex items-center justify-center h-10 px-4 rounded-lg border bg-white dark:bg-surface-dark hover:bg-background-light dark:hover:bg-background-dark text-sm font-bold"
                onclick="window.__sp.openModulAjarTutorial()" title="Tutorial">
                <span class="material-symbols-outlined text-[18px]">volume_up</span>
                <span class="ml-2 hidden lg:inline">Tutorial</span>
              </button>
            </div>
          </div>
        `;

        const mobileNav = (cur) => {
          const prev = cur === 'detail' ? 'informasi' : (cur === 'modul' ? 'detail' : null);
          const next = cur === 'informasi' ? 'detail' : (cur === 'detail' ? 'modul' : null);
          const rightLabel = cur === 'informasi' ? 'Detail Pembelajaran' : (cur === 'detail' ? 'Modul Ajar' : '');
          const rightOnClick = cur === 'detail'
            ? `onclick="window.__sp.openModulAjarFromDetail()"`
            : (next ? `onclick="window.__sp.setModulAjarTab('${next}')"` : '');
          return `
            <div class="md:hidden mt-6 flex items-center gap-3">
              <button class="flex-1 h-12 rounded-xl border bg-white dark:bg-surface-dark font-bold" ${prev ? `onclick="window.__sp.setModulAjarTab('${prev}')"` : 'disabled'}>Kembali</button>
              <button class="flex-1 h-12 rounded-xl ${next ? 'bg-primary text-white' : 'bg-gray-200 text-gray-500'} font-bold" ${next ? rightOnClick : 'disabled'}>${rightLabel || 'Lanjut'}</button>
            </div>
          `;
        };

        const step1Html = `
          <div class="bg-surface-light dark:bg-surface-dark rounded-xl border border-border-light dark:border-border-dark shadow-sm">
            <div class="p-6 space-y-5">
              ${maErr && tab === 'informasi' ? `
                <div class="rounded-lg bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 p-4 text-sm text-red-700 dark:text-red-300">
                  ${safeText(maErr.msg || '')}
                </div>` : ``}
              <div>
                <div class="flex items-center justify-between gap-3">
                  <div class="flex items-center gap-2">
                    <div class="text-xs font-bold text-primary bg-primary/10 inline-flex px-3 py-1 rounded-full">Langkah 1</div>
                    <button class="h-6 w-6 rounded-full border border-border-light dark:border-border-dark bg-white dark:bg-surface-dark hover:bg-background-light dark:hover:bg-background-dark inline-flex items-center justify-center"
                      title="Petunjuk Langkah 1"
                      onclick="window.__sp.openMAHelp1()">
                      <span class="material-symbols-outlined text-[16px]">help</span>
                    </button>
                  </div>
                  <button
                    class="inline-flex items-center gap-2 h-10 px-4 rounded-lg bg-primary hover:bg-blue-600 text-white text-sm font-bold shadow-sm transition-colors"
                    onclick="window.__sp.setModulAjarTab('detail')"
                    title="Lanjut ke Detail Pembelajaran"
                  >
                    <span class="hidden sm:inline">Detail Pembelajaran</span>
                    <span class="sm:hidden">Detail</span>
                    <span class="material-symbols-outlined text-[18px]">arrow_forward</span>
                  </button>
                </div>
                <div class="text-xl font-bold mt-2">Informasi Dasar</div>
                <div class="text-sm text-text-sub-light dark:text-text-sub-dark mt-1">Identitas pendidik dan konteks akademik</div>
              </div>
              <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                ${mkInp('Nama Guru','namaGuru',M.namaGuru,'Contoh: Sunarwan, S.Pd.')}
                ${mkInp('Nama Institusi','institusi',M.institusi,'Contoh: SDN 1 Cilodong')}
              </div>
              <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                ${mkSel('Kurikulum','kurikulum',M.kurikulum,kurikulumOpts)}
                <div class="flex flex-col gap-2">
                  <label class="text-sm font-semibold text-text-sub-light dark:text-text-sub-dark">Jenjang</label>
                  <select class="w-full rounded-lg border-border-light dark:border-border-dark bg-background-light dark:bg-background-dark/40 focus:border-primary focus:ring-primary h-11 px-4 text-sm"
                    data-ma-key="jenjang"
                    onchange="window.__sp.setMA('jenjang',this.value,true)">
                    <option value="">— Pilih —</option>
                    ${['PAUD','TK','SD/MI','SMP/MTs','SMA/MA','SMK/MAK','Kesetaraan'].map(o=>`<option value="${safeText(o)}" ${String(o)===String(M.jenjang||'')?'selected':''}>${safeText(o)}</option>`).join('')}
                  </select>
                  <div class="${isKesetaraan ? "" : "hidden"} flex flex-col gap-2 mt-2">
                    <label class="text-sm font-semibold text-text-sub-light dark:text-text-sub-dark">Paket</label>
                    <select class="w-full rounded-lg border-border-light dark:border-border-dark bg-background-light dark:bg-background-dark/40 focus:border-primary focus:ring-primary h-11 px-4 text-sm"
                      data-ma-key="kesetaraanPaket"
                      onchange="window.__sp.setMA('kesetaraanPaket',this.value,true)">
                      <option value="">— Pilih Paket —</option>
                      ${KES_PAKET_OPTIONS.map(o=>`<option value="${safeText(o)}" ${String(o)===String(M.kesetaraanPaket||'')?'selected':''}>${safeText(o)}</option>`).join('')}
                    </select>
                  </div>
                </div>
                ${mkSel('Fase','fase',M.fase,faseOpts)}
              </div>
              <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                ${mkSel('Kelas','kelas',M.kelas,kelasOpts)}
                ${mkSel('Mata Pelajaran','mapel',M.mapel,(SUBJECT_OPTIONS[jenjangEfektif] || []))}
              </div>
            </div>
          </div>
        `;

        const step2Html = `
          <div class="bg-surface-light dark:bg-surface-dark rounded-xl border border-border-light dark:border-border-dark shadow-sm">
            <div class="p-6 space-y-5">
              ${maErr && tab === 'detail' ? `
                <div class="rounded-lg bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 p-4 text-sm text-red-700 dark:text-red-300">
                  ${safeText(maErr.msg || '')}
                </div>` : ``}
              <div>
                <div class="flex items-center justify-between gap-3">
                  <div class="flex items-center gap-2">
                    <div class="text-xs font-bold text-primary bg-primary/10 inline-flex px-3 py-1 rounded-full">Langkah 2</div>
                    <button class="h-6 w-6 rounded-full border border-border-light dark:border-border-dark bg-white dark:bg-surface-dark hover:bg-background-light dark:hover:bg-background-dark inline-flex items-center justify-center"
                      title="Petunjuk Langkah 2"
                      onclick="window.__sp.openMAHelp2()">
                      <span class="material-symbols-outlined text-[16px]">help</span>
                    </button>
                  </div>
                  <button
                    class="inline-flex items-center gap-2 h-10 px-4 rounded-lg bg-primary hover:bg-blue-600 text-white text-sm font-bold shadow-sm transition-colors"
                    onclick="window.__sp.openModulAjarFromDetail()"
                    title="Buka tab Modul Ajar (generate jika belum ada)"
                  >
                    <span class="hidden sm:inline">Modul Ajar</span>
                    <span class="sm:hidden">Modul</span>
                    <span class="material-symbols-outlined text-[18px]">arrow_forward</span>
                  </button>
                </div>
                <div class="text-xl font-bold mt-2">Detail Pembelajaran</div>
                <div class="text-sm text-text-sub-light dark:text-text-sub-dark mt-1">Materi, durasi, model, dan profil lulusan</div>
              </div>
              <div class="grid grid-cols-1 gap-5">
                ${mkInp('Materi Pokok / Judul Modul','judulModul',M.judulModul,'Contoh: Pengenalan Bunyi dan Kosa Kata')}
              </div>
              <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                ${mkSel('Jumlah Pertemuan','jumlahPertemuan',M.jumlahPertemuan,Array.from({ length: MA_MAX_PERTEMUAN }, (_, i) => String(i + 1)))}
                ${mkInp('Durasi per Pertemuan (menit)','durasi',M.durasi,'Contoh: 50')}
                ${mkInp('Jumlah Peserta Didik','jumlahSiswa',M.jumlahSiswa,'Contoh: 30')}
              </div>
              <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                ${mkSel('Pendekatan Pembelajaran','pendekatan',M.pendekatan,pendekatanOpts)}
                ${mkSel('Model Pembelajaran','modelPembelajaran',M.modelPembelajaran,MA_MODEL)}
              </div>
              <div>
                <div class="text-sm font-semibold text-text-sub-light dark:text-text-sub-dark mb-2">
                  Dimensi Profil Lulusan
                  <span class="font-normal italic ml-1 text-xs">(min. 1 — sesuai SKL 2025)</span>
                </div>
                <div id="maDimensiWrap" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-2">${dimChecks}</div>
              </div>
              <div class="rounded-lg border border-border-light dark:border-border-dark bg-white dark:bg-surface-dark p-4">
                <label class="flex items-start gap-3 cursor-pointer select-none">
                  <input type="checkbox" class="mt-1 accent-primary" ${M.supervisi ? 'checked' : ''} onchange="window.__sp.setMA('supervisi',this.checked,true)" />
                  <div>
                    <div class="text-sm font-bold">Mode Supervisi</div>
                    <div class="text-xs text-text-sub-light dark:text-text-sub-dark mt-1">Tambahkan CP, ATP, dan KKTP agar lebih lengkap untuk supervisi. Jika nonaktif, bagian tersebut tidak dibuat.</div>
                  </div>
                </label>
              </div>
            </div>
          </div>
        `;

        const step3Html = `
          <div class="bg-surface-light dark:bg-surface-dark rounded-xl border border-border-light dark:border-border-dark shadow-sm">
            <div class="p-6 space-y-4">
              <div class="flex items-start justify-between gap-4">
                <div>
                  <div class="text-xl font-bold">Modul Ajar</div>
                  <div class="text-sm text-text-sub-light dark:text-text-sub-dark mt-1">Generate dan lihat hasil dokumen modul ajar</div>
                  <div class="mt-3 flex flex-wrap items-center gap-2">
                    <button id="btnMADocxTop" class="${hasilAda ? 'inline-flex bg-green-600 hover:bg-green-700 text-white border border-green-600' : 'inline-flex bg-gray-200 text-gray-500 border border-gray-300 opacity-60 cursor-not-allowed'} items-center gap-2 h-9 px-4 rounded-lg text-sm font-bold"
                      ${hasilAda ? `onclick="window.__sp.exportModulAjarDocx()"` : 'disabled'}>
                      <span class="material-symbols-outlined text-[18px]">download</span>
                      Download .docx
                    </button>
                    <button id="btnMAPdfTop" class="${hasilAda ? 'inline-flex bg-green-600 hover:bg-green-700 text-white border border-green-600' : 'inline-flex bg-gray-200 text-gray-500 border border-gray-300 opacity-60 cursor-not-allowed'} items-center gap-2 h-9 px-4 rounded-lg text-sm font-bold"
                      ${hasilAda ? `onclick="window.__sp.exportModulAjarPDF()"` : 'disabled'}>
                      <span class="material-symbols-outlined text-[18px]">picture_as_pdf</span>
                      Download PDF
                    </button>
                    <button id="btnMADetailKegiatan" class="${(hasilAda && !isRefiningKegiatan && !M.isGenerating && !kegiatanRefinedOnce) ? 'inline-flex bg-white dark:bg-surface-dark hover:bg-background-light dark:hover:bg-background-dark text-primary border border-primary' : 'inline-flex bg-gray-200 text-gray-500 border border-gray-300 opacity-60 cursor-not-allowed'} items-center gap-2 h-9 px-4 rounded-lg text-sm font-bold"
                      ${(hasilAda && !isRefiningKegiatan && !M.isGenerating && !kegiatanRefinedOnce) ? `onclick="window.__sp.refineModulAjarKegiatan()"` : 'disabled'}>
                      <span class="material-symbols-outlined text-[18px]">edit_note</span>
                      Perjelas Kegiatan
                    </button>
                  </div>
                </div>
                <button onclick="window.__sp.buildModulAjar()"
                  class="shrink-0 flex items-center gap-2 rounded-lg h-10 px-6 bg-primary hover:bg-blue-600 text-primary-content text-sm font-bold shadow-sm transition-colors">
                  <span class="material-symbols-outlined text-[18px]">auto_awesome</span>
                  Buat Modul Ajar Sekarang
                </button>
              </div>
              <div id="maError" class="${(maErr && tab === 'modul') ? '' : 'hidden'} rounded-lg bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 p-4 text-sm text-red-700 dark:text-red-300">${safeText((maErr && tab === 'modul') ? (maErr.msg || '') : '')}</div>
            </div>
          </div>

          ${hasilAda ? `
          <div class="overflow-auto max-h-[72vh] custom-scrollbar">
            <div id="maPreview" class="
              [&_.ma-table-wrap]:overflow-x-auto [&_.ma-table-wrap]:-mx-1 [&_.ma-table-wrap]:px-1 [&_.ma-table-wrap]:my-3
              [&_table]:w-full [&_table]:border-collapse [&_table]:text-[14px]
              [&_td]:border [&_td]:border-gray-300 [&_td]:px-3 [&_td]:py-2 [&_td]:align-top
              [&_th]:border [&_th]:border-gray-300 [&_th]:px-3 [&_th]:py-2 [&_th]:bg-gray-100 [&_th]:font-bold
              [&_.ma-tbl>tbody>tr:nth-child(even)>td]:bg-gray-50
              [&_ul]:pl-6 [&_ul]:my-2 [&_li]:mb-1.5
              [&_ol]:pl-6 [&_ol]:my-2 [&_ol]:list-decimal [&_ol>li]:mb-1.5
              [&_em]:italic [&_strong]:font-bold
              [&_p]:mb-3 [&_p]:text-justify">
              ${maBuildPreviewHtmlWysiwyg(M)}
            </div>
          </div>` : `
          <div class="rounded-lg border border-border-light dark:border-border-dark bg-white dark:bg-surface-dark p-6 text-sm text-text-sub-light dark:text-text-sub-dark">
            Hasil belum ada. Klik Buat Modul Ajar Sekarang untuk membuat dokumen.
          </div>
          `}
        `;

        if (M.isGenerating) return `
          <div class="flex flex-col items-center justify-center p-10 md:p-20 gap-4 max-w-2xl mx-auto">
            <div class="size-12 rounded-full bg-primary/10 text-primary flex items-center justify-center">
              <span class="material-symbols-outlined animate-spin">progress_activity</span>
            </div>
            <div class="text-center">
              <div class="font-bold text-lg">Menyusun Modul Ajar...</div>
              <div class="text-sm text-text-sub-light mt-1">AI sedang membuat modul lengkap. Semakin banyak JP/pertemuan dan semakin detail tabel, waktu proses akan semakin lama.</div>
            </div>
            <div class="rounded-lg border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/20 max-w-md w-full">
              <div class="flex items-start gap-3 p-4">
                <span class="material-symbols-outlined text-amber-500 mt-0.5">warning</span>
                <div class="text-sm text-amber-700 dark:text-amber-200">Jangan tutup halaman ini. Pastikan layar tidak mati.</div>
              </div>
            </div>
          </div>`;

        const body = tab === 'detail'
          ? (step2Html + mobileNav('detail'))
          : tab === 'modul'
            ? (step3Html + mobileNav('modul'))
            : (step1Html + mobileNav('informasi'));

        const maHelpModals = `
          <div id="modalMAHelp1" class="fixed inset-0 hidden items-center justify-center" style="display:none; background: rgba(0,0,0,0.5); z-index:50;">
            <div class="bg-white dark:bg-surface-dark rounded-xl border border-border-light dark:border-border-dark shadow-xl w-[92vw] max-w-[760px] max-h-[85vh] overflow-auto">
              <div class="p-5 border-b border-border-light dark:border-border-dark flex items-center justify-between">
                <div class="font-bold text-lg flex items-center gap-2"><span class="material-symbols-outlined">help</span> Petunjuk Modul Ajar • Langkah 1</div>
                <button class="size-9 rounded-lg border bg-white dark:bg-surface-dark" onclick="window.__sp.closeMAHelp1()">&times;</button>
              </div>
              <div class="p-5 space-y-3 text-sm leading-relaxed">
                <ol class="list-decimal pl-5 space-y-2">
                  <li>Isi Nama Guru dan Institusi.</li>
                  <li>Pilih Kurikulum, Jenjang, Fase, dan Kelas.</li>
                  <li>Isi Mata Pelajaran dan Materi Pokok/Judul Modul.</li>
                  <li>Pastikan identitas lengkap sebelum lanjut ke Langkah 2.</li>
                </ol>
              </div>
            </div>
          </div>
          <div id="modalMAHelp2" class="fixed inset-0 hidden items-center justify-center" style="display:none; background: rgba(0,0,0,0.5); z-index:50;">
            <div class="bg-white dark:bg-surface-dark rounded-xl border border-border-light dark:border-border-dark shadow-xl w-[92vw] max-w-[760px] max-h-[85vh] overflow-auto">
              <div class="p-5 border-b border-border-light dark:border-border-dark flex items-center justify-between">
                <div class="font-bold text-lg flex items-center gap-2"><span class="material-symbols-outlined">help</span> Petunjuk Modul Ajar • Langkah 2</div>
                <button class="size-9 rounded-lg border bg-white dark:bg-surface-dark" onclick="window.__sp.closeMAHelp2()">&times;</button>
              </div>
              <div class="p-5 space-y-3 text-sm leading-relaxed">
                <ol class="list-decimal pl-5 space-y-2">
                  <li>Isi jumlah pertemuan, durasi per pertemuan, jumlah siswa.</li>
                  <li>Pilih Model Pembelajaran yang relevan.</li>
                  <li>Pilih Dimensi Profil Pelajar Pancasila (min. satu).</li>
                  <li>Klik Buat Modul Ajar Sekarang untuk membuat dokumen.</li>
                  <li>Gunakan Download .docx untuk menyimpan hasil.</li>
                </ol>
              </div>
            </div>
          </div>
          <div id="modalMAHelp3" class="fixed inset-0 hidden items-center justify-center" style="display:none; background: rgba(0,0,0,0.5); z-index:50;">
            <div class="bg-white dark:bg-surface-dark rounded-xl border border-border-light dark:border-border-dark shadow-xl w-[92vw] max-w-[760px] max-h-[85vh] overflow-auto">
              <div class="p-5 border-b border-border-light dark:border-border-dark flex items-center justify-between">
                <div class="font-bold text-lg flex items-center gap-2"><span class="material-symbols-outlined">help</span> Petunjuk Modul Ajar • Langkah 3</div>
                <button class="size-9 rounded-lg border bg-white dark:bg-surface-dark" onclick="window.__sp.closeMAHelp3()">&times;</button>
              </div>
              <div class="p-5 space-y-3 text-sm leading-relaxed">
                <ol class="list-decimal pl-5 space-y-2">
                  <li>Tab ini menampilkan hasil Modul Ajar dalam format preview.</li>
                  <li>Jika hasil belum ada, kembali ke Langkah 2 lalu klik Buat Modul Ajar Sekarang.</li>
                  <li>Gunakan Download .docx untuk menyimpan dokumen Word.</li>
                  <li>Gunakan Download PDF untuk menyimpan versi PDF (jika tersedia).</li>
                  <li>Jika dokumen panjang, tunggu sampai proses selesai dan jangan tutup halaman saat loading.</li>
                </ol>
              </div>
            </div>
          </div>
        `;

        const maTutorialModal = `
          <div id="modalModulAjarTutorial" class="fixed inset-0 hidden items-center justify-center" style="display:none; background: rgba(0,0,0,0.5); z-index:50;">
            <div class="bg-white dark:bg-surface-dark rounded-xl border border-border-light dark:border-border-dark shadow-xl w-[92vw] max-w-[900px] max-h-[85vh] overflow-auto">
              <div class="p-5 border-b border-border-light dark:border-border-dark flex items-center justify-between">
                <div class="font-bold text-lg flex items-center gap-2"><span class="material-symbols-outlined">volume_up</span> Tutorial Modul Ajar</div>
                <button class="size-9 rounded-lg border bg-white dark:bg-surface-dark" onclick="window.__sp.closeModulAjarTutorial()">&times;</button>
              </div>
              <div class="p-5">
                <div id="maTutorialList" class="grid grid-cols-1 md:grid-cols-2 gap-2"></div>
                <div class="text-xs text-text-sub-light dark:text-text-sub-dark mt-3">Catatan: audio menyusul.</div>
              </div>
            </div>
          </div>
        `;

        return `<div class="space-y-6">${desktopTabs}${body}${maHelpModals}${maTutorialModal}</div>`;
      };

      const MODUL_AJAR_TUTORIALS = [
        { id: 'ma1', title: 'Informasi Dasar (Isi identitas)', src: 'tutorial/modulajar/modul1.wav' },
        { id: 'ma2', title: 'Detail Pembelajaran (materi, durasi, model, dimensi)', src: 'tutorial/modulajar/modul2.wav' },
        { id: 'ma3', title: 'Mode Supervisi (CP, ATP, KKTP)', src: 'tutorial/modulajar/modul3.wav' },
        { id: 'ma4', title: 'Buat Modul Ajar Sekarang (generate)', src: 'tutorial/modulajar/modul4.wav' },
        { id: 'ma5', title: 'Review hasil & perbaikan cepat', src: 'tutorial/modulajar/modul5.wav' },
        { id: 'ma6', title: 'Download DOCX/PDF, Simpan & Muat', src: 'tutorial/modulajar/modul6.wav' },
      ];
      function openModulAjarTutorial() {
        const m = el('modalModulAjarTutorial');
        if (!m) return;
        const list = el('maTutorialList');
        if (list) {
          list.innerHTML = MODUL_AJAR_TUTORIALS.map((it, i) => `
            <div class="w-full h-full rounded-lg border bg-white dark:bg-surface-dark p-4">
              <div class="flex items-start justify-between gap-3">
                <div>
                  <div class="text-xs text-text-sub-light">#${i + 1}</div>
                  <div class="font-bold">${safeText(it.title)}</div>
                </div>
                ${it.src ? `` : `<div class="text-xs px-2 py-1 rounded-full bg-gray-100 dark:bg-gray-800 text-gray-500">Segera hadir</div>`}
              </div>
              ${it.src ? `<div class="mt-3"><audio class="w-full rounded-lg border bg-white dark:bg-surface-dark" controls preload="none" src="${safeText(it.src)}"></audio></div>` : ``}
            </div>
          `).join('');
        }
        m.classList.remove('hidden');
        m.classList.add('flex');
        m.style.display = 'flex';
      }
      function closeModulAjarTutorial() {
        const m = el('modalModulAjarTutorial');
        if (m) {
          m.style.display = 'none';
          m.classList.add('hidden');
          m.classList.remove('flex');
        }
        try {
          document.querySelectorAll('#maTutorialList audio').forEach(a => {
            try { a.pause(); } catch {}
            try { a.currentTime = 0; } catch {}
          });
        } catch {}
      }

      const renderLimit = () => {
        const info = state.limitInfo || {};
        const sisa = Number(info?.limitpaket ?? 0);
        const awal = Number(info?.initial_limitpaket ?? info?.total ?? 0);
        const pakai = Math.max(awal - sisa, 0);
        const rows = (state.creditHistory || []).map((r, idx) => {
          const dt = new Date(r.ts || Date.now());
          const t = dt.toLocaleString('id-ID', { day:'2-digit', month:'short', year:'2-digit', hour:'2-digit', minute:'2-digit' });
          return `<tr>
            <td class="border px-3 py-2 text-center">${idx+1}</td>
            <td class="border px-3 py-2">${t}</td>
            <td class="border px-3 py-2">${safeText(r.kind||'-')}</td>
            <td class="border px-3 py-2">${safeText(r.detail||'')}</td>
            <td class="border px-3 py-2 text-center text-red-600 font-semibold">-${Number(r.cost||0)}</td>
          </tr>`;
        }).join('');
        const cfg = state.limitConfig?.costs || {};
        const cPublish = Number(cfg.publish_quiz ?? 3);
        const cModul = Number(cfg.modul_ajar ?? 3);
        const cSoal = Number(cfg.buat_soal ?? 2);
        const cRekap = Number(cfg.rekap_nilai ?? 0);
        const initLimit = Number(state.limitConfig?.initial_limit ?? 300);
        return `
          <div class="space-y-4">
            <div class="bg-surface-light dark:bg-surface-dark rounded-xl border border-border-light dark:border-border-dark shadow-sm overflow-hidden">
              <div class="p-6 border-b border-border-light dark:border-border-dark">
                <div class="flex items-center justify-between">
                  <div>
                    <div class="text-2xl font-bold">Kredit Limit</div>
                    <div class="text-sm text-text-sub-light">Kuota penggunaan fitur berbayar aplikasi</div>
                  </div>
                  <button class="px-3 h-10 rounded-lg border bg-white dark:bg-surface-dark" onclick="window.__sp.refreshCreditLimit(true)"><span class="material-symbols-outlined text-[18px]">refresh</span> Segarkan</button>
                </div>
              </div>
              <div class="p-6 space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                  <div class="rounded-xl border bg-white dark:bg-surface-dark p-4">
                    <div class="text-xs text-text-sub-light">Sisa Kredit</div>
                    <div class="text-2xl font-extrabold text-green-600">${sisa}</div>
                  </div>
                  <div class="rounded-xl border bg-white dark:bg-surface-dark p-4">
                    <div class="text-xs text-text-sub-light">Kredit Awal</div>
                    <div class="text-2xl font-extrabold">${awal}</div>
                  </div>
                  <div class="rounded-xl border bg-white dark:bg-surface-dark p-4">
                    <div class="text-xs text-text-sub-light">Terpakai</div>
                    <div class="text-2xl font-extrabold text-amber-600">${pakai}</div>
                  </div>
                </div>
                <div class="rounded-lg border bg-white dark:bg-surface-dark p-4">
                  <div class="font-bold mb-2">Biaya Kredit per Fitur <span class="text-green-600">(GRATIS*)</span></div>
                  <ul class="text-sm list-disc pl-5 space-y-1">
                    <li>Publish Quiz: ${cPublish} kredit</li>
                    <li>Modul Ajar: ${cModul} kredit</li>
                    <li>Buat Soal: ${cSoal} kredit</li>
                    <li>Rekap Nilai: ${cRekap} kredit</li>
                  </ul>
                  <div class="mt-3 text-sm">
                    Top up kredit: hubungi Admin via WhatsApp <a class="text-blue-600 underline" href="https://wa.me/6285882412124" target="_blank" rel="noopener">0858-8241-2124</a>.
                  </div>
                  <div class="mt-1 text-xs text-green-700">
                    *Saat ini top up masih gratis. Apabila ke depannya biaya operasional aplikasi meningkat, top up akan dikenakan biaya yang tetap terjangkau.
                  </div>
                </div>
                ${IS_ADMIN ? `
                <div class="rounded-lg border bg-white dark:bg-surface-dark p-4">
                  <div class="font-bold mb-3">Pengaturan (Admin)</div>
                  <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <label class="text-sm">Publish Quiz (kredit)
                      <input class="w-full h-10 rounded-lg border bg-white dark:bg-surface-dark px-3"
                        value="${cPublish}" oninput="(state.limitConfig=state.limitConfig||{}, state.limitConfig.costs=state.limitConfig.costs||{}, state.limitConfig.costs.publish_quiz=Number(this.value)||0)">
                    </label>
                    <label class="text-sm">Modul Ajar (kredit)
                      <input class="w-full h-10 rounded-lg border bg-white dark:bg-surface-dark px-3"
                        value="${cModul}" oninput="(state.limitConfig=state.limitConfig||{}, state.limitConfig.costs=state.limitConfig.costs||{}, state.limitConfig.costs.modul_ajar=Number(this.value)||0)">
                    </label>
                    <label class="text-sm">Buat Soal (kredit)
                      <input class="w-full h-10 rounded-lg border bg-white dark:bg-surface-dark px-3"
                        value="${cSoal}" oninput="(state.limitConfig=state.limitConfig||{}, state.limitConfig.costs=state.limitConfig.costs||{}, state.limitConfig.costs.buat_soal=Number(this.value)||0)">
                    </label>
                    <label class="text-sm">Rekap Nilai (kredit)
                      <input class="w-full h-10 rounded-lg border bg-white dark:bg-surface-dark px-3"
                        value="${cRekap}" oninput="(state.limitConfig=state.limitConfig||{}, state.limitConfig.costs=state.limitConfig.costs||{}, state.limitConfig.costs.rekap_nilai=Number(this.value)||0)">
                    </label>
                    <label class="text-sm">Limit Awal User Baru
                      <input class="w-full h-10 rounded-lg border bg-white dark:bg-surface-dark px-3"
                        value="${initLimit}" oninput="(state.limitConfig=state.limitConfig||{}, state.limitConfig.initial_limit=Number(this.value)||0)">
                    </label>
                  </div>
                  <div class="flex items-center justify-end mt-3">
                    <button class="px-4 h-10 rounded-lg bg-primary text-white" onclick="window.__sp.saveLimitConfig()">Simpan Pengaturan</button>
                  </div>
                </div>
                ` : ``}
                <div class="rounded-lg border bg-white dark:bg-surface-dark p-4">
                  <div class="flex items-center justify-between">
                    <div class="font-bold">Riwayat Penggunaan Kredit</div>
                    <div class="text-xs text-text-sub-light">${Array.isArray(state.creditHistory)?state.creditHistory.length:0} entri tersimpan (lokal)</div>
                  </div>
                  <div class="overflow-auto mt-2">
                    <table class="min-w-full text-sm border">
                      <thead class="bg-background-light dark:bg-background-dark">
                        <tr>
                          <th class="border px-3 py-2 text-center">No</th>
                          <th class="border px-3 py-2">Waktu</th>
                          <th class="border px-3 py-2">Kegiatan</th>
                          <th class="border px-3 py-2">Rincian</th>
                          <th class="border px-3 py-2 text-center">Kredit</th>
                        </tr>
                      </thead>
                      <tbody>${rows || `<tr><td colspan="5" class="border px-3 py-6 text-center text-text-sub-light">Belum ada riwayat lokal. Publikasi/Generate akan muncul di sini.</td></tr>`}</tbody>
                    </table>
                  </div>
                </div>
              </div>
            </div>
          </div>
        `;
      };
      function maMarkdownToHtml(md) {
        if (!md) return '';
        const esc = (s) => s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
        const fmtInline = (raw) =>
          esc(String(raw || ''))
            .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
            .replace(/\*(.+?)\*/g, '<em>$1</em>');
        const lines = md.split('\n');
        const parts = [];
        let i = 0;
        while (i < lines.length) {
          const line = lines[i];
          if (/^\s*---+\s*$/.test(line)) {
            parts.push('<hr class="ma-hr">');
            i++;
            continue;
          }
          const fullBold = line.match(/^\s*\*\*(.+?)\*\*\s*$/);
          if (fullBold) {
            parts.push(`<h2>${fmtInline(fullBold[0].trim())}</h2>`);
            i++;
            continue;
          }
          const hasPipe = line.includes('|') && line.trim() !== '';
          if (hasPipe) {
            const next = lines[i + 1] || '';
            const sepRe = /^\s*\|?\s*:?-{2,}\s*(\|\s*:?-{2,}\s*)+\|?\s*$/;
            let headerSep = sepRe.test(next);
            let rows = [];
            let j = i;
            while (j < lines.length && lines[j].includes('|') && lines[j].trim() !== '') {
              if (sepRe.test(lines[j])) { j++; continue; }
              rows.push(lines[j]);
              j++;
            }
            if (rows.length) {
              const fmtCell = (raw) =>
                esc(String(raw || '').trim())
                  .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
                  .replace(/\*(.+?)\*/g, '<em>$1</em>');
              const cells = rows.map(r => r.trim().replace(/^\|?/, '').replace(/\|?$/, '').split('|').map(c => fmtCell(c)));
              let html = '<table class="ma-tbl">';
              if (headerSep && cells.length > 0) {
                html += '<thead><tr>' + cells[0].map(h => `<th>${h}</th>`).join('') + '</tr></thead><tbody>';
                for (let ri = 1; ri < cells.length; ri++) {
                  html += '<tr>' + cells[ri].map(c => `<td>${c}</td>`).join('') + '</tr>';
                }
                html += '</tbody></table>';
              } else {
                html += '<tbody>';
                for (let ri = 0; ri < cells.length; ri++) {
                  html += '<tr>' + cells[ri].map(c => `<td>${c}</td>`).join('') + '</tr>';
                }
                html += '</tbody></table>';
              }
              parts.push(html);
              i = j;
              continue;
            }
          }
          // Deteksi heading markdown (dengan/ tanpa spasi setelah '#')
          const head = line.match(/^\s*(#{1,4})\s*(.+)$/);
          if (head) {
            const lvl = head[1].length;
            const text = head[2];
            if (lvl === 1 || lvl === 2) {
              const isMain = /^MODUL AJAR\b/i.test(text);
              parts.push(isMain ? `<h1 class="ma-title">${esc(text)}</h1>` : `<h1>${esc(text)}</h1>`);
            } else if (lvl === 3) {
              parts.push(`<h2>${fmtInline(text)}</h2>`);
            } else {
              parts.push(`<h3>${fmtInline(text)}</h3>`);
            }
            i++; 
            continue;
          }
          const numHeadRe = /^\s*(\d+)\.\s+(.+)$/;
          if (numHeadRe.test(line)) {
            let k = i + 1;
            let nextNonEmpty = '';
            while (k < lines.length && lines[k].trim() === '') k++;
            nextNonEmpty = lines[k] || '';
            const isFollowingNum = numHeadRe.test(nextNonEmpty);
            const isFollowingBullet = /^\s*[-•]\s+/.test(nextNonEmpty);
            if (!isFollowingNum && !isFollowingBullet) {
              const title = line.replace(numHeadRe, (_m, n, t) => `${n}. ${t}` );
              parts.push(`<h2>${fmtInline(title)}</h2>`);
              i++;
              continue;
            }
          }
          if (/^\s*[-•]\s+/.test(line)) {
            let j = i;
            let html = '<ul>';
            while (j < lines.length && /^\s*[-•]\s+/.test(lines[j])) {
              const txt = fmtInline(lines[j].replace(/^\s*[-•]\s+/, ''));
              html += `<li>${txt}</li>`;
              j++;
            }
            html += '</ul>';
            parts.push(html);
            i = j;
            continue;
          }
          if (/^\s*\d+\.\s+/.test(line)) {
            let j = i;
            let html = '<ol>';
            while (j < lines.length && /^\s*\d+\.\s+/.test(lines[j])) {
              const title = fmtInline(lines[j].replace(/^\s*\d+\.\s+/, ''));
              const cont = [];
              let k = j + 1;
              while (k < lines.length) {
                const l = lines[k];
                if (l.trim() === '') break;
                if (/^\s*\d+\.\s+/.test(l) || /^\s*[-•]\s+/.test(l) || /^\s*#{1,4}\s*/.test(l) || l.includes('|') || /^\s*---+\s*$/.test(l)) break;
                if (/^\s{2,}\S/.test(l)) {
                  cont.push(fmtInline(l.replace(/^\s+/, '')));
                  k++;
                  continue;
                }
                break;
              }
              if (cont.length) {
                html += `<li><div>${title}</div>${cont.map(x => `<div>${x}</div>`).join('')}</li>`;
              } else {
                html += `<li>${title}</li>`;
              }
              j = k;
            }
            html += '</ol>';
            parts.push(html);
            i = j;
            continue;
          }
          if (line.trim() === '') { parts.push(''); i++; continue; }
          let j = i + 1;
          let para = [line];
          while (j < lines.length) {
            const l = lines[j];
            if (l.trim() === '' || l.includes('|') || /^\s*#{1,4}\s*/.test(l) || /^\s*[-•]\s+/.test(l) || /^\s*\d+\.\s+/.test(l)) break;
            para.push(l);
            j++;
          }
          const ptxt = fmtInline(para.join(' ').replace(/\s+/g,' ').trim());
          parts.push(`<p>${ptxt}</p>`);
          i = j;
          continue;
          parts.push(line);
          i++;
        }
        let h = parts.join('\n');
        return h;
      }
      function maNormalizeContent(M) {
        const cleanRaw = (raw) => {
          let s = String(raw || '');
          s = s.replace(/\r\n/g, '\n');
          s = s.replace(/<br\s*\/?>/gi, ' ');
          s = s.replace(/&nbsp;/gi, ' ');
          s = s.replace(/<\/?(?:div|span|p|strong|em|b|i|u|small|mark|code|pre|h1|h2|h3|h4|h5|h6|table|thead|tbody|tr|th|td)[^>]*>/gi, ' ');
          s = s.replace(/<[^>]+>/g, ' ');
          s = s.replace(/[ \t]+/g, ' ');
          s = s.replace(/\n[ \t]+\n/g, '\n\n');
          s = s.replace(/\n{3,}/g, '\n\n');
          return s;
        };
        const normalizeIdentifikasiKesiapan = (text) => {
          const lines = String(text || '').split('\n');
          const isHeading = (line) => /^(?:#{1,6}\s*)?IDENTIFIKASI\s+KESIAPAN\s+PESERTA\s+DIDIK\b/i.test(String(line || '').trim());
          const isNextSection = (line) => /^(?:#{1,6}\s*)?(?:[ABC]\.\s*|##\s+|###\s+|#\s+)/.test(String(line || '').trim());
          let start = -1;
          for (let i = 0; i < lines.length; i++) {
            if (isHeading(lines[i])) { start = i; break; }
          }
          if (start === -1) return text;
          let end = lines.length;
          for (let i = start + 1; i < lines.length; i++) {
            const t = String(lines[i] || '').trim();
            if (!t) continue;
            if (isHeading(lines[i])) continue;
            if (isNextSection(lines[i])) { end = i; break; }
          }
          const headingLine = String(lines[start] || '').trim();
          const blockText = lines.slice(start + 1, end).join(' ');
          let s = String(blockText || '');
          s = s.replace(/\uF0B7/g, '•');
          s = s.replace(/ï‚·/g, '•');
          s = s.replace(/[●○◦∙·▪•]/g, '•');
          s = s.replace(/\s+/g, ' ').trim();
          const labelRe = /(Pengetahuan\s*Awal|Minat|Latar\s*Belakang|Kebutuhan\s*Belajar|Visual|Auditori|Kinestetik)\s*[:：]\s*/gi;
          const hits = [];
          let m;
          while ((m = labelRe.exec(s)) !== null) {
            hits.push({ key: m[1].replace(/\s+/g, ' ').trim(), idx: m.index, end: labelRe.lastIndex });
            if (hits.length > 30) break;
          }
          if (hits.length === 0) return text;
          const values = {};
          for (let i = 0; i < hits.length; i++) {
            const cur = hits[i];
            const next = hits[i + 1];
            const v = s.slice(cur.end, next ? next.idx : s.length).trim();
            values[cur.key.toLowerCase()] = v.replace(/^(?:[-*•]\s*)/,'').trim();
          }
          const outLines = [];
          const get = (k) => String(values[String(k).toLowerCase()] || '').trim();
          const vPen = get('pengetahuan awal');
          const vMinat = get('minat');
          const vLB = get('latar belakang');
          const vKB = get('kebutuhan belajar');
          const vVis = get('visual');
          const vAud = get('auditori');
          const vKin = get('kinestetik');
          if (vPen) outLines.push(`- Pengetahuan Awal: ${vPen}`);
          if (vMinat) outLines.push(`- Minat: ${vMinat}`);
          if (vLB) outLines.push(`- Latar Belakang: ${vLB}`);
          if (vKB || vVis || vAud || vKin) {
            outLines.push(`- Kebutuhan Belajar${vKB && !(vVis || vAud || vKin) ? `: ${vKB}` : ':'}`);
            if (vVis) outLines.push(`  - Visual: ${vVis}`);
            if (vAud) outLines.push(`  - Auditori: ${vAud}`);
            if (vKin) outLines.push(`  - Kinestetik: ${vKin}`);
          }
          if (outLines.length === 0) return text;
          const rebuilt = [headingLine, ...outLines, ''];
          const before = lines.slice(0, start);
          const after = lines.slice(end);
          return [...before, ...rebuilt, ...after].join('\n').replace(/\n{3,}/g, '\n\n');
        };
        const normalizeKegiatanPertemuanTables = (text) => {
          const lines = String(text || '').replace(/\r\n/g, '\n').replace(/\r/g, '\n').split('\n');
          const isPertemuan = (line) => /^\s*(?:#{1,6}\s*)?Pertemuan\s+\d+\b/i.test(String(line || '').trim());
          const parsePertemuanHeader = (line) => {
            const t = String(line || '').trim();
            const m = t.match(/Pertemuan\s+(\d+)\s*(?:\((\d+)\s*menit\))?/i);
            return { no: m ? Number(m[1]) : null, total: m && m[2] ? Number(m[2]) : null };
          };
          const isKegiatanPembelajaranHeading = (line) => {
            const t = String(line || '').trim();
            if (!t) return false;
            return /^(?:#{1,6}\s*)?(?:\*{0,2})?(?:\d+\.\s*)?Kegiatan\s+Pembelajaran\b/i.test(t);
          };
          const isBoundary = (line) => {
            const t = String(line || '').trim();
            if (!t) return false;
            if (isPertemuan(t)) return false;
            if (/^(?:#{1,6}\s*)?Pertemuan\s+\d+\b/i.test(t)) return false;
            if (/^(?:#{1,6}\s*)?IDENTIFIKASI\s+KESIAPAN\s+PESERTA\s+DIDIK\b/i.test(t)) return true;
            if (/^(?:#{1,6}\s*)?(?:##\s+|###\s+|#\s+|[ABC]\.\s+)/.test(t)) return true;
            return false;
          };
          const stageRe = /^\s*(?:[-•]\s*)?(Pendahuluan|Kegiatan\s+Inti|Penutup)\s*\((\d+)\s*menit\)\s*$/i;
          const itemRe = /^\s*[-•]\s+(.+?)\s*$/;
          const mmjTagRe = /\[(Mindful|Meaningful|Joyful)\]/gi;
          const esc = (s) => String(s || '').replace(/\|/g, '\\|').replace(/\s+/g, ' ').trim();

          const out = [];
          for (let i = 0; i < lines.length; i++) {
            const line = lines[i];
            if (isKegiatanPembelajaranHeading(line)) {
              out.push('---', '', '**5. Kegiatan Pembelajaran**', '');
              continue;
            }
            if (!isPertemuan(line)) {
              out.push(line);
              continue;
            }
            const hdr = parsePertemuanHeader(line);
            let j = i + 1;
            while (j < lines.length && !isPertemuan(lines[j]) && !isBoundary(lines[j])) j++;
            const block = lines.slice(i + 1, j);

            const stages = [
              { name: 'Pendahuluan', total: null, items: [] },
              { name: 'Kegiatan Inti', total: null, items: [] },
              { name: 'Penutup', total: null, items: [] },
            ];
            const blockTextRaw = block.join('\n');
            const legacySteps = [];
            if (/\[Durasi:\s*\d+\s*menit\]/i.test(blockTextRaw)) {
              const cleaned = blockTextRaw
                .replace(/\\r\\n/g, '\n')
                .replace(/\\r/g, '\n')
                .replace(/\s+/g, ' ')
                .replace(/\(\s*(\d+)\s*\)\s*/g, '\n($1) ')
                .trim();
              const tm = cleaned.match(/Kegiatan\s+Inti\s*\(\s*(\d+)\s*menit\s*\)/i);
              if (tm) stages[1].total = Number(tm[1]);
              const stepRe = /\((\d+)\)\s*\[Durasi:\s*(\d+)\s*menit\]\s*(?:\[Komponen:\s*([^\]]+)\]\s*)?([^()]+?)(?=\(\d+\)\s*\[Durasi:|$)/gi;
              let m;
              while ((m = stepRe.exec(cleaned)) !== null) {
                const dur = Number(m[2]) || 0;
                let komponen = String(m[3] || '').replace(/\[Komponen\]/gi, '').replace(/\s+/g, ' ').trim();
                let detail = String(m[4] || '').replace(/\[Komponen[^\]]*\]/gi, '').replace(/\s+/g, ' ').trim();
                if (!komponen) {
                  const first = detail.split('.').map(s => s.trim()).filter(Boolean)[0] || '';
                  komponen = (first || 'Langkah').slice(0, 60).trim();
                } else {
                  komponen = komponen.length > 80 ? komponen.slice(0, 80).trim() : komponen;
                }
                legacySteps.push({ komponen, detail, dur, catatan: '' });
              }
              if (legacySteps.length) {
                stages[1].items = legacySteps;
              }
            }
            const stageIdx = (n) => {
              const key = String(n || '').toLowerCase().replace(/\s+/g, ' ').trim();
              if (key === 'pendahuluan') return 0;
              if (key === 'kegiatan inti') return 1;
              if (key === 'penutup') return 2;
              return -1;
            };
            let curStage = -1;
            const hasTable = block.some((l) => /^\s*\|\s*Tahap\s*\|\s*Komponen\s*\|/i.test(String(l || '').trim()));
            if (legacySteps.length) {
            } else if (hasTable) {
              for (let k = 0; k < block.length; k++) {
                const t = String(block[k] || '').trim();
                if (!t) continue;
                if (!t.startsWith('|')) continue;
                if (/^\|\s*-+\s*\|/i.test(t)) continue;
                if (/^\|\s*Tahap\s*\|\s*Komponen\s*\|/i.test(t)) continue;
                const cols = t.replace(/^\|/, '').replace(/\|$/, '').split('|').map(x => String(x || '').trim());
                if (cols.length < 5) continue;
                const tahap = cols[0];
                const komponen = cols[1];
                const detail = cols[2];
                const dur = Number(String(cols[3] || '').replace(/[^\d]/g, '')) || 0;
                const catatan = cols.slice(4).join(' | ').trim();
                const si = stageIdx(tahap);
                const idx = si >= 0 ? si : 1;
                stages[idx].items.push({ komponen, detail, dur, catatan });
              }
            } else {
              for (let k = 0; k < block.length; k++) {
                const raw = String(block[k] || '');
                const t = raw.trim();
                if (!t) continue;
                const sm = t.match(stageRe);
                if (sm) {
                  curStage = stageIdx(sm[1]);
                  if (curStage >= 0) stages[curStage].total = Number(sm[2]);
                  continue;
                }
                const im = raw.match(itemRe);
                if (!im) continue;
                const content = String(im[1] || '').trim();
                const compSplit = content.split(':');
                let komponen = '';
                let detail = '';
                if (compSplit.length >= 2) {
                  komponen = compSplit.shift().trim();
                  detail = compSplit.join(':').trim();
                } else {
                  komponen = content.length > 40 ? content.slice(0, 40).trim() : content;
                  detail = content;
                }
                let dur = null;
                const dm = detail.match(/\((\d+)\s*menit\)/i);
                if (dm) {
                  dur = Number(dm[1]);
                  detail = detail.replace(dm[0], '').trim();
                }
                const tags = [];
                let m;
                while ((m = mmjTagRe.exec(detail)) !== null) {
                  const v = String(m[1] || '').trim();
                  if (v && !tags.includes(v)) tags.push(v);
                }
                detail = detail.replace(mmjTagRe, '').replace(/\s+/g, ' ').trim();
                const catatan = tags.length ? tags.join(' / ') : '';
                if (curStage === -1) curStage = 1;
                stages[curStage].items.push({ komponen, detail, dur, catatan });
              }
            }

            const knownStageTotals = stages.map(s => (typeof s.total === 'number' && !Number.isNaN(s.total) ? s.total : null));
            let pertemuanTotal = hdr.total;
            if (pertemuanTotal == null) {
              const sumKnown = knownStageTotals.filter(v => v != null).reduce((a, b) => a + b, 0);
              if (sumKnown > 0) pertemuanTotal = sumKnown;
            }
            if (pertemuanTotal != null) {
              const missingIdx = stages.map((s, idx) => (s.total == null ? idx : -1)).filter(v => v !== -1);
              const sumKnown = knownStageTotals.filter(v => v != null).reduce((a, b) => a + b, 0);
              const remain = pertemuanTotal - sumKnown;
              if (missingIdx.length === 1 && remain > 0) stages[missingIdx[0]].total = remain;
              if (missingIdx.length > 1 && remain > 0) {
                const base = Math.floor(remain / missingIdx.length);
                let rem = remain - base * missingIdx.length;
                missingIdx.forEach((idx) => {
                  stages[idx].total = base + (rem > 0 ? 1 : 0);
                  rem = Math.max(0, rem - 1);
                });
              }
            }

            stages.forEach((s) => {
              const items = s.items;
              if (!items.length) return;
              const total = typeof s.total === 'number' && !Number.isNaN(s.total) ? s.total : null;
              const sumKnown = items.filter(it => typeof it.dur === 'number').reduce((a, it) => a + it.dur, 0);
              const missing = items.filter(it => it.dur == null);
              if (missing.length) {
                const target = total != null ? Math.max(0, total - sumKnown) : missing.length;
                const base = Math.floor(target / missing.length);
                let rem = target - base * missing.length;
                missing.forEach((it) => {
                  it.dur = base + (rem > 0 ? 1 : 0);
                  rem = Math.max(0, rem - 1);
                });
              } else if (total != null && sumKnown !== total) {
                const diff = total - sumKnown;
                items[items.length - 1].dur = Math.max(0, items[items.length - 1].dur + diff);
              }
            });

            const finalTotal = pertemuanTotal != null
              ? pertemuanTotal
              : stages.flatMap(s => s.items).reduce((a, it) => a + (Number(it.dur) || 0), 0);

            out.push('---', '');
            out.push(`**Pertemuan ${hdr.no || ''}${finalTotal ? ` (${finalTotal} menit)` : ''}**`.trim());
            out.push('');

            const renderStage = (stage, label) => {
              const s = stage || { total: null, items: [] };
              const sum = Array.isArray(s.items) ? s.items.reduce((a, it) => a + (Number(it.dur) || 0), 0) : 0;
              const total = (typeof s.total === 'number' && !Number.isNaN(s.total)) ? s.total : sum;
              if (!Array.isArray(s.items) || s.items.length === 0) return;
              out.push(`**${label}${total ? ` (±${total} menit)` : ''}**`);
              out.push('');
              let idx = 1;
              s.items.forEach((it) => {
                const dur = Number(it.dur) || 0;
                let title = String(it.komponen || '').replace(/\s+/g, ' ').trim();
                const detail = String(it.detail || '').replace(/\s+/g, ' ').trim();
                if (!title) {
                  const first = detail.split('.').map(s => s.trim()).filter(Boolean)[0] || '';
                  title = (first || 'Langkah').slice(0, 60).trim();
                }
                const cat = String(it.catatan || '').replace(/\s+/g, ' ').trim();
                out.push(`${idx}. **${title} (${dur} menit)**`);
                out.push(`   ${detail}${cat ? ` (${cat})` : ''}`.trimEnd());
                out.push('');
                idx++;
              });
              out.push('');
            };

            renderStage(stages[0], 'Kegiatan Pendahuluan');
            renderStage(stages[1], 'Kegiatan Inti');
            renderStage(stages[2], 'Kegiatan Penutup');

            out.push('---');
            out.push('');
            i = j - 1;
          }
          return out.join('\n').replace(/\n{3,}/g, '\n\n');
        };
        let contentText = cleanRaw(M?.hasil || '');
        contentText = contentText.replace(/^\s*#{1,3}\s*MODUL AJAR[^\n]*\n?/i, '');
        contentText = contentText.replace(/^\s*#{1,3}\s*["“][^\n"”]+["”]\s*\n?/i, '');
        contentText = normalizeIdentifikasiKesiapan(contentText);
        contentText = normalizeKegiatanPertemuanTables(contentText);
        return contentText.trim();
      }
      function maInsertPageBreakMarkers(text) {
        let s = String(text || '');
        const marker = '[[PAGE_BREAK]]';
        const addBeforeLine = (reWithGroups) => {
          s = s.replace(reWithGroups, (_m, p1, p2) => `${p1}${marker}\n${p2}`);
        };
        // CP mulai halaman baru (pisahkan dari Informasi Umum)
        addBeforeLine(/(^|\n)([^\S\r\n]*(?:#{1,4}\s*)?(?:\*{0,2})Capaian\s+Pembelajaran\s*\(CP\)\b[^\n]*)/im);
        // Page break sebelum Komponen Inti agar halaman 1 hanya Judul + Informasi Umum
        addBeforeLine(/(^|\n)([^\S\r\n]*(?:#{1,4}\s*)?(?:\*{0,2})B\.\s*KOMPONEN\s+INTI\b[^\n]*)/im);
        // Kegiatan Pembelajaran mulai halaman baru
        addBeforeLine(/(^|\n)([^\S\r\n]*(?:#{1,4}\s*)?(?:\*{0,2})?(?:\d+\.\s*)?Kegiatan\s+Pembelajaran\b[^\n]*)/im);
        // Rubrik Penilaian mulai halaman baru
        addBeforeLine(/(^|\n)([^\S\r\n]*(?:#{1,4}\s*)?(?:\*{0,2})?(?:\d+\.\s*)?Rubrik\s+Penilaian\b[^\n]*)/im);
        // rapikan marker berulang
        s = s.replace(new RegExp(`${marker}\\s*\\n\\s*${marker}`, 'g'), marker);
        return s;
      }
      function maStripCLampiranHeading(text) {
        let s = String(text || '');
        s = s.replace(/(^|\n)\s*(?:#{1,4}\s*)?(?:\*{0,2})C\.\s*LAMPIRAN\b[^\n]*\n?/im, '$1');
        s = s.replace(/\n{3,}/g, '\n\n');
        return s.trim();
      }
      function maSplitLKPD(raw) {
        const src = String(raw || '');
        const startRe = /^###\s*(?:1\.)?\s*LKPD\b.*$/im;
        const m = startRe.exec(src);
        if (!m) return { pre: src, lkpd: '', post: '' };
        const startLineIdx = m.index;
        const afterStartLine = src.indexOf('\n', startLineIdx);
        const bodyStart = afterStartLine === -1 ? src.length : afterStartLine + 1;
        const rest = src.slice(bodyStart);
        const endRe = /^###\s*(?!1\.)\d+\.\s+/m;
        const mEnd = endRe.exec(rest);
        const bodyEnd = mEnd ? bodyStart + mEnd.index : src.length;
        return {
          pre: src.slice(0, startLineIdx).trim(),
          lkpd: src.slice(bodyStart, bodyEnd).trim(),
          post: src.slice(bodyEnd).trim(),
        };
      }
      function maParseLKPD(lkpdText, M) {
        const lines = String(lkpdText || '').split('\n').map(s => s.trim()).filter(Boolean);
        let judul = String(M?.judulModul || '').trim();
        if (!judul && lines.length) {
          const l0 = lines[0];
          const m0 = l0.match(/^(judul(\s+lkpd)?|topik)\s*[:\-]\s*(.+)$/i);
          judul = (m0 ? m0[3] : l0).trim();
        }
        const data = { judul, tujuan: [], alat: [], langkah: [], refleksi: [], kesimpulan: [] };
        let cur = '';
        const setCur = (k) => { cur = k; };
        const pushLine = (k, v) => { if (v) data[k].push(v); };
        for (const line of lines) {
          if (/^a[\.\)]\s*tujuan\b/i.test(line) || /^tujuan\b/i.test(line)) { setCur('tujuan'); continue; }
          if (/^b[\.\)]\s*alat\b/i.test(line) || /^alat\s+dan\s+bahan\b/i.test(line) || /^alat\b/i.test(line)) { setCur('alat'); continue; }
          if (/^c[\.\)]\s*langkah\b/i.test(line) || /^langkah\s+kegiatan\b/i.test(line) || /^langkah\b/i.test(line)) { setCur('langkah'); continue; }
          if (/^d[\.\)]\s*pertanyaan\b/i.test(line) || /^pertanyaan\s+refleksi\b/i.test(line) || /^refleksi\b/i.test(line)) { setCur('refleksi'); continue; }
          if (/^e[\.\)]\s*kesimpulan\b/i.test(line) || /^kesimpulan\b/i.test(line)) { setCur('kesimpulan'); continue; }
          const cleaned = line
            .replace(/^\s*[-•*]\s+/, '')
            .replace(/^\s*\d+[\.\)]\s+/, (m) => m)
            .trim();
          if (!cur) continue;
          pushLine(cur, cleaned);
        }
        return data;
      }
      function maEnsureLKPDData(data, M, sourceText) {
        const mapel = String(M?.mapel || '').trim();
        const judul = String(data?.judul || M?.judulModul || '').trim() || 'Topik Pembelajaran';
        const d = data || { judul, tujuan: [], alat: [], langkah: [], refleksi: [], kesimpulan: [] };
        d.judul = judul;
        const hasAny = (arr) => Array.isArray(arr) && arr.some(x => String(x || '').trim());
        const safeArr = (arr) => (Array.isArray(arr) ? arr.filter(x => String(x || '').trim()) : []);
        d.tujuan = safeArr(d.tujuan);
        d.alat = safeArr(d.alat);
        d.langkah = safeArr(d.langkah);
        d.refleksi = safeArr(d.refleksi);
        const fromAI = String(sourceText || '').trim();
        if (!hasAny(d.tujuan)) {
          d.tujuan = [
            `Memahami konsep utama materi "${judul}"${mapel ? ` pada mata pelajaran ${mapel}` : ''}.`,
            `Menerapkan konsep melalui kegiatan terarah dan bekerja sama dalam kelompok.`,
            `Menyampaikan hasil kerja dan menarik kesimpulan berdasarkan kegiatan.`,
          ];
        }
        if (!hasAny(d.alat)) {
          d.alat = [
            mapel ? `Buku/handout ${mapel}` : 'Buku/handout materi',
            'Lembar kerja ini (LKPD)',
            'Alat tulis (pensil/pulpen) dan penghapus',
            'Kertas/lembar presentasi (opsional)',
          ];
        }
        if (!hasAny(d.langkah)) {
          d.langkah = [
            `Baca petunjuk dan tujuan pembelajaran pada LKPD ini dengan cermat.`,
            `Diskusikan bersama kelompok tentang materi "${judul}" berdasarkan sumber yang tersedia.`,
            `Catat poin penting (konsep, istilah, contoh) yang ditemukan selama diskusi.`,
            `Kerjakan tugas/aktivitas yang diminta guru sesuai arahan dan waktu yang ditentukan.`,
            `Siapkan hasil kerja untuk dipresentasikan (ringkas, jelas, dan rapi).`,
            `Presentasikan hasil dan berikan tanggapan terhadap presentasi kelompok lain.`,
            `Perbaiki hasil kerja bila ada masukan, lalu simpulkan bersama.`,
          ];
        }
        if (!hasAny(d.refleksi)) {
          d.refleksi = [
            `Apa hal baru yang kamu pelajari dari materi "${judul}"?`,
            `Bagian mana yang paling menantang dan bagaimana cara kamu mengatasinya?`,
            `Bagaimana kerja sama kelompokmu membantu memahami materi?`,
          ];
        }
        if (fromAI && (fromAI.length < 40)) {
          d.refleksi = d.refleksi.length ? d.refleksi : [
            `Apa hal baru yang kamu pelajari hari ini?`,
            `Bagian mana yang paling sulit?`,
            `Apa yang akan kamu lakukan agar lebih paham?`,
          ];
        }
        return d;
      }
      function maRenderLKPDHtml(data) {
        const title = safeText(data.judul || 'LKPD');
        const list = (arr, ordered = false) => {
          if (!arr || !arr.length) return '<div class="text-gray-600">-</div>';
          if (ordered) {
            const items = arr.map(x => `<li>${safeText(String(x).replace(/^\d+[\.\)]\s+/, ''))}</li>`).join('');
            return `<ol class="list-decimal pl-6 space-y-1">${items}</ol>`;
          }
          const items = arr.map(x => `<li>${safeText(String(x).replace(/^\d+[\.\)]\s+/, ''))}</li>`).join('');
          return `<ul class="list-disc pl-6 space-y-1">${items}</ul>`;
        };
        const answerLines = `<div class="mt-1 space-y-2">${Array.from({length:3}).map(()=>`<div class="border-b border-gray-400 h-5"></div>`).join('')}</div>`;
        return `
          <div class="mt-8">
            <div class="font-bold text-[16px]">C. LAMPIRAN</div>
            <div class="mt-2 font-bold text-[15px]">1. Lembar Kerja Peserta Didik (LKPD)</div>
            <div class="mt-3 border border-gray-500">
              <div class="px-3 py-2 text-center text-black" style="background:#D9D9D9;">
                <div class="font-bold text-[14px]">LEMBAR KERJA PESERTA DIDIK (LKPD)</div>
                <div class="text-[12px]">${title}</div>
              </div>
              <div class="p-3 text-[13px] leading-5">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-2 mb-3">
                  <div><span class="font-semibold">Nama Siswa</span> : <span class="inline-block border-b border-gray-400 w-[180px] align-middle"></span></div>
                  <div><span class="font-semibold">Kelas</span> : <span class="inline-block border-b border-gray-400 w-[180px] align-middle"></span></div>
                  <div><span class="font-semibold">Kelompok</span> : <span class="inline-block border-b border-gray-400 w-[180px] align-middle"></span></div>
                </div>
                <div class="space-y-3">
                  <div>
                    <div class="font-bold">A. Tujuan</div>
                    <div class="mt-1">${list(data.tujuan, false)}</div>
                  </div>
                  <div>
                    <div class="font-bold">B. Alat dan Bahan</div>
                    <div class="mt-1">${list(data.alat, false)}</div>
                  </div>
                  <div>
                    <div class="font-bold">C. Langkah Kegiatan</div>
                    <div class="mt-1">${list(data.langkah, true)}</div>
                  </div>
                  <div>
                    <div class="font-bold">D. Pertanyaan Refleksi</div>
                    <div class="mt-1">${list(data.refleksi, true)}</div>
                    ${answerLines}
                  </div>
                  <div>
                    <div class="font-bold">E. Kesimpulan</div>
                    ${answerLines}
                  </div>
                </div>
              </div>
            </div>
          </div>
        `;
      }
      function maBuildPreviewHtml(M) {
        const mapel = String(M?.mapel || '').trim();
        const judul = String(M?.judulModul || '').trim();
        const normalized = maNormalizeContent(M);
        const { pre, lkpd, post } = maSplitLKPD(normalized);
        const lkpdData = maEnsureLKPDData(lkpd ? maParseLKPD(lkpd, M) : { judul, tujuan: [], alat: [], langkah: [], refleksi: [], kesimpulan: [] }, M, lkpd);
        const lkpdHtml = maRenderLKPDHtml(lkpdData);
        const body = maMarkdownToHtml(maStripCLampiranHeading(pre)) + lkpdHtml + maMarkdownToHtml(maStripCLampiranHeading(post));
        const title = mapel ? `MODUL AJAR ${mapel.toUpperCase()}` : 'MODUL AJAR';
        const sub = judul ? `"${safeText(judul)}"` : '';
        return `
          <div class="text-center">
            <div class="font-bold text-[20px] tracking-wide">${safeText(title)}</div>
            ${sub ? `<div class="italic text-[18px] mt-2">${sub}</div>` : ``}
          </div>
          <div class="mt-10">${body}</div>
        `;
      }
      function maBuildPreviewHtmlWysiwyg(M) {
        const mapel = String(M?.mapel || '').trim();
        const judul = String(M?.judulModul || '').trim();
        const title = mapel ? `MODUL AJAR ${mapel.toUpperCase()}` : 'MODUL AJAR';
        const sub = judul ? `"${safeText(judul)}"` : '';
        const titleHtml = `
          <div class="text-center">
            <div class="font-bold text-[20px] tracking-wide">${safeText(title)}</div>
            ${sub ? `<div class="italic text-[18px] mt-2">${sub}</div>` : ``}
          </div>
        `;

        const normalized = maInsertPageBreakMarkers(maNormalizeContent(M));
        const { pre, lkpd, post } = maSplitLKPD(normalized);
        const preMd = maStripCLampiranHeading(pre || '');
        const postMd = maStripCLampiranHeading(post || '');

        const splitMdPages = (md) => {
          const src = String(md || '');
          const parts = src.split('[[PAGE_BREAK]]').map(s => s.trim()).filter(Boolean);
          return parts.length ? parts : [''];
        };
        const wrapTables = (html) => {
          let s = String(html || '');
          s = s.replace(/<table/gi, '<div class="ma-table-wrap"><table');
          s = s.replace(/<\/table>/gi, '</table></div>');
          return s;
        };

        const pages = [];
        const prePages = splitMdPages(preMd);
        prePages.forEach((md, idx) => {
          const html = wrapTables(maMarkdownToHtml(md));
          const content = idx === 0 ? `${titleHtml}<div class="mt-10">${html}</div>` : html;
          pages.push(content);
        });

        const lkpdData = maEnsureLKPDData(lkpd ? maParseLKPD(lkpd, M) : { judul, tujuan: [], alat: [], langkah: [], refleksi: [], kesimpulan: [] }, M, lkpd);
        const lkpdHtml = maRenderLKPDHtml(lkpdData);
        if (String(lkpdHtml || '').trim()) pages.push(lkpdHtml);

        const postPages = splitMdPages(postMd);
        if (String(postMd || '').trim()) {
          postPages.forEach((md) => {
            const html = wrapTables(maMarkdownToHtml(md));
            pages.push(html);
          });
        }

        const pageWrap = (inner) => `
          <div class="bg-white text-black p-4 md:p-10 md:shadow-paper md:min-h-[297mm] font-serif border border-gray-200 mx-auto print:border-none print:shadow-none print:p-0 w-full">
            ${inner}
          </div>
        `;

        return `<div class="space-y-6">${pages.map(pageWrap).join('')}</div>`;
      }

      async function buildModulAjar() {
        const M = state.modulAjar || {};
        const baselineModulAjar = String(M.hasil || '');
        const req = [M.namaGuru,M.institusi,M.jenjang,M.mapel,M.judulModul,M.jumlahPertemuan,M.durasi,M.modelPembelajaran];
        const errEl = () => document.getElementById('maError');
        const showErr = (msg) => { const e=errEl(); if(e){e.textContent='⚠️ '+msg; e.classList.remove('hidden');} };

        const failMA = (tab, msg, key) => {
          state.modulAjarTab = tab;
          state.modulAjarError = { tab, msg, key };
          render();
          setTimeout(() => { try { focusMAKey(key); } catch {} }, 80);
        };
        const namaGuru = String(M.namaGuru || '').trim();
        const institusi = String(M.institusi || '').trim();
        const jenjang = String(M.jenjang || '').trim();
        const kesPaket = String(M.kesetaraanPaket || '').trim();
        const jenjangDisplay = displayJenjang(jenjang, kesPaket);
        const jenjangEfektif = resolveJenjang(jenjang, kesPaket);
        const mapel = String(M.mapel || '').trim();
        const judul = String(M.judulModul || '').trim();
        const jumlah = String(M.jumlahPertemuan || '').trim();
        const durasi = String(M.durasi || '').trim();
        const model = String(M.modelPembelajaran || '').trim();

        if (!namaGuru) { failMA('informasi', 'Langkah 1 belum lengkap: isi Nama Guru dulu ya.', 'namaGuru'); return; }
        if (!institusi) { failMA('informasi', 'Langkah 1 belum lengkap: isi Nama Institusi dulu ya.', 'institusi'); return; }
        if (!jenjang) { failMA('informasi', 'Langkah 1 belum lengkap: pilih Jenjang dulu ya.', 'jenjang'); return; }
        if (jenjang === 'Kesetaraan' && !kesPaket) { failMA('informasi', 'Langkah 1 belum lengkap: pilih Paket Kesetaraan dulu ya.', 'kesetaraanPaket'); return; }
        if (!mapel) { failMA('informasi', 'Langkah 1 belum lengkap: isi Mata Pelajaran dulu ya.', 'mapel'); return; }
        if (!judul) { failMA('detail', 'Langkah 2 belum lengkap: isi Materi Pokok / Judul Modul dulu ya.', 'judulModul'); return; }
        if (!jumlah) { failMA('detail', 'Langkah 2 belum lengkap: pilih Jumlah Pertemuan dulu ya.', 'jumlahPertemuan'); return; }
        if (!durasi) { failMA('detail', 'Langkah 2 belum lengkap: isi Durasi per Pertemuan dulu ya.', 'durasi'); return; }
        if (!model) { failMA('detail', 'Langkah 2 belum lengkap: pilih Model Pembelajaran dulu ya.', 'modelPembelajaran'); return; }
        if (!Array.isArray(M.dimensi)||M.dimensi.length===0) { failMA('detail', 'Langkah 2 belum lengkap: pilih minimal 1 Dimensi Profil Lulusan dulu ya.', 'dimensi'); return; }

        state.modulAjarTab = "modul";
        state.modulAjarError = null;
        state.modulAjar.isGenerating = true;
        state.modulAjar.isRefiningKegiatan = false;
        state.modulAjar.kegiatanRefinedOnce = false;
        state.modulAjar.hasil = '';
        render();

        const kurikulumLabel = String(M.kurikulum || 'Kurikulum Merdeka').trim();
        const pendekatanLabel = String(M.pendekatan || 'Standar').trim() || 'Standar';
        const isK13 = /2013|k13/i.test(kurikulumLabel);
        const isDL = /deep\s*learning/i.test(pendekatanLabel);
        const isKBC = /\bKBC\b/i.test(pendekatanLabel) || /berbasis cinta/i.test(pendekatanLabel);
        const isDandK = isDL && isKBC;
        const isCTL = /\bCTL\b/i.test(pendekatanLabel) || /contextual\s+teaching\s+and\s+learning/i.test(pendekatanLabel);
        const isDLCTL = isDL && isCTL && !isKBC;

        const noKbcCleanupRule = !isKBC
          ? `CATATAN PENTING (TANPA KBC):
- Jangan menambahkan bagian/istilah KBC sama sekali.
- Jika pada dokumen baseline masih ada "Unsur KBC", "Implementasi KBC", atau aktivitas KBC di kegiatan pembelajaran/refleksi/asesmen, hapus bagian tersebut sepenuhnya.`
          : ``;

        const noCtlCleanupRule = !isCTL
          ? `CATATAN PENTING (TANPA CTL):
- Jangan menyebut istilah CTL atau "Contextual Teaching and Learning" sama sekali.
- Jika pada dokumen baseline masih ada bagian "Pendekatan CTL", tabel "Komponen CTL", atau tag CTL seperti [Konstruktivisme], [Inkuiri], [Questioning], [Learning Community], [Modeling], [Refleksi], [Penilaian Autentik], hapus bagian tersebut sepenuhnya.`
          : ``;

        const approachRules = isDandK
          ? `ARAH PENDEKATAN (DEEP LEARNING + KBC):
- Di SETIAP pertemuan wajib ada urutan eksplisit: Eksplorasi → Analisis → Refleksi.
- Di SETIAP pertemuan wajib ada elemen Mindful, Meaningful, Joyful (tulis eksplisit di kegiatan pembelajaran).
- Di SETIAP pertemuan sisipkan minimal 1 aktivitas KBC yang konkret dan tertulis jelas (etika komunikasi/empati/gotong royong/kepedulian lingkungan/refleksi syukur/niat belajar).
- Pertanyaan pemantik: open-ended dan mengandung dimensi nilai tanpa menggurui.
- Asesmen: rubrik gabungan (kognitif + proses + karakter/KBC), skala 1–4, indikator dapat diamati.
${noCtlCleanupRule}`
          : isDLCTL
            ? `ARAH PENDEKATAN (DEEP LEARNING + CTL):
- Wajib ada konteks nyata/masalah pemantik yang konsisten dari awal hingga penutup.
- Di SETIAP pertemuan wajib ada urutan eksplisit: Eksplorasi → Analisis → Refleksi.
- Di SETIAP pertemuan wajib ada elemen Mindful, Meaningful, Joyful (tulis eksplisit di kegiatan pembelajaran).
- Wajib memuat komponen CTL secara eksplisit dan aplikatif: Konstruktivisme, Inkuiri, Bertanya (Questioning), Learning Community, Modeling, Refleksi, Penilaian Autentik.
- Kegiatan Inti harus berorientasi penyelidikan: mengamati konteks, merumuskan pertanyaan, mengumpulkan data/percobaan sederhana, menganalisis, menyimpulkan, mengomunikasikan.
- Asesmen harus autentik (proses + produk/kinerja/portofolio) + rubrik skala 1–4 yang siap dipakai guru.
- WAJIB ada subbagian "### Pendekatan CTL (Contextual Teaching and Learning)" yang merangkum 7 komponen CTL dan implementasinya pada modul ini.
${noKbcCleanupRule}
${noCtlCleanupRule}`
            : isDL
            ? `ARAH PENDEKATAN (DEEP LEARNING):
- Di SETIAP pertemuan wajib ada urutan eksplisit: Eksplorasi → Analisis → Refleksi.
- Di SETIAP pertemuan wajib ada elemen Mindful, Meaningful, Joyful (tulis eksplisit di kegiatan pembelajaran).
- Pertanyaan pemantik: open-ended, menuntut alasan (mengapa/bagaimana) dan konteks nyata.
- Asesmen: menilai proses berpikir (bukti analisis/justifikasi), rubrik analitis skala 1–4.
${noKbcCleanupRule}
${noCtlCleanupRule}`
            : isKBC
              ? `ARAH PENDEKATAN (KBC):
- Tambahkan "Unsur KBC" dan "Implementasi KBC" secara eksplisit dan kontekstual.
- Di SETIAP pertemuan sisipkan minimal 1 aktivitas KBC yang konkret dan tertulis jelas.
- Asesmen: tambah observasi sikap/kolaborasi + refleksi nilai (tetap skala 1–4, indikator dapat diamati).
- Hindari bahasa menggurui; tetap formal namun hangat.
${noCtlCleanupRule}`
              : isCTL
                ? `ARAH PENDEKATAN (CTL / CONTEXTUAL TEACHING AND LEARNING):
- Wajib ada konteks nyata/masalah pemantik yang dekat dengan kehidupan peserta didik (rumah/sekolah/lingkungan/sosial/teknologi) dan konsisten dari awal hingga penutup.
- Wajib memuat komponen CTL secara eksplisit dan aplikatif: Konstruktivisme, Inkuiri, Bertanya (Questioning), Learning Community, Modeling, Refleksi, Penilaian Autentik.
- Kegiatan Inti harus berorientasi penyelidikan: mengamati konteks, merumuskan pertanyaan, mengumpulkan data/percobaan sederhana, menganalisis, menyimpulkan, mengomunikasikan.
- Asesmen harus autentik: menilai proses dan produk (kinerja/produk/portofolio) + rubrik 1–4 yang dapat dipakai guru.
- Pertanyaan Pemantik dan Refleksi harus memandu peserta didik mengaitkan konsep dengan konteks nyata (mengapa/bagaimana) dan rencana penerapan.`
                + `\n- WAJIB ada subbagian "### Pendekatan CTL (Contextual Teaching and Learning)" yang merangkum 7 komponen CTL dan implementasinya pada modul ini.`
              : `ARAH PENDEKATAN (STANDAR):
- Kegiatan pembelajaran instruksional, terstruktur, dan terukur (contoh → latihan terbimbing → latihan mandiri).
- Asesmen ringkas namun jelas (diagnostik/formatif/sumatif) dan rubrik skala 1–4 yang mudah dipakai.
${noKbcCleanupRule}
${noCtlCleanupRule}`;

        const modelLabel = String(M.modelPembelajaran || '').trim();
        const isPjBL = /\bpjbl\b/i.test(modelLabel) || /project\s*based\s*learning/i.test(modelLabel);
        const isPBL = /\bpbl\b/i.test(modelLabel) || /problem\s*based\s*learning/i.test(modelLabel);
        const isInquiry = /inquiry\s*learning/i.test(modelLabel) || /inkuiri/i.test(modelLabel);
        const isDiscovery = /discovery\s*learning/i.test(modelLabel);
        const isDirect = /direct\s*learning/i.test(modelLabel) || /direct\s*instruction/i.test(modelLabel);
        const isCoop = /cooperative\s*learning/i.test(modelLabel) || /kooperatif/i.test(modelLabel);

        const modelRules = isPjBL
          ? `ATURAN MODEL (PjBL / Project Based Learning):
- Wajib ada subbagian "### Produk Proyek" yang menjelaskan produk/artefak, kriteria keberhasilan, dan standar minimal.
- Kegiatan Inti per pertemuan wajib mengikuti sintaks PjBL (tulis fase sebagai label di kolom "Komponen" pada tabel), minimal memuat: Pertanyaan Mendasar/Challenge, Perencanaan Proyek, Penyusunan Jadwal, Monitoring Progres, Uji Hasil/Produk, Presentasi/Publikasi, Evaluasi & Refleksi.
- LKPD wajib berbentuk LKPD Proyek: tujuan proyek, kriteria produk, bahan/alat, langkah kerja, pembagian peran, timeline, logbook, dan format pelaporan.
- Asesmen sumatif utama adalah produk proyek + presentasi; rubrik wajib memuat aspek produk dan proses proyek (kolaborasi, manajemen waktu, dokumentasi).`
          : isPBL
            ? `ATURAN MODEL (PBL / Problem Based Learning):
- Wajib ada subbagian "### Skenario Masalah" (kasus kontekstual) dan "### Keluaran Akhir" (bentuk solusi/argumen yang dinilai).
- Kegiatan Inti per pertemuan wajib mengikuti sintaks PBL (tulis fase sebagai label di kolom "Komponen" pada tabel), minimal memuat: Orientasi Masalah, Organisasi Belajar, Penyelidikan/Investigasi, Pengembangan & Presentasi Solusi, Analisis & Evaluasi.
- LKPD wajib berbentuk LKPD Investigasi Masalah: rumusan masalah, data/informasi, hipotesis/alternatif, analisis, keputusan/solusi, dan justifikasi.
- Asesmen sumatif utama adalah kualitas solusi/argumentasi; rubrik wajib memuat aspek analisis masalah, bukti/data, kualitas solusi, argumentasi, dan refleksi.`
            : isInquiry
              ? `ATURAN MODEL (Inquiry Learning):
- Kegiatan Inti wajib mengikuti alur inkuiri (tulis fase sebagai label di kolom "Komponen" pada tabel): Orientasi, Merumuskan Pertanyaan/Masalah, Merumuskan Hipotesis, Mengumpulkan Data, Menguji Hipotesis, Menyimpulkan, Mengomunikasikan.
- LKPD wajib berformat inkuiri (pertanyaan, hipotesis, data, analisis, kesimpulan).
- Asesmen menilai proses inkuiri dan kesimpulan berbasis data.`
              : isDiscovery
                ? `ATURAN MODEL (Discovery Learning):
- Kegiatan Inti wajib mengikuti fase discovery (tulis fase sebagai label di kolom "Komponen" pada tabel): Stimulation, Problem Statement, Data Collection, Data Processing, Verification, Generalization.
- LKPD berisi langkah discovery dan kolom temuan peserta didik.
- Asesmen menilai temuan, generalisasi, dan pembuktian.`
                : isDirect
                  ? `ATURAN MODEL (Direct Instruction / Pembelajaran Langsung):
- Kegiatan Inti wajib memuat urutan eksplisit: Modeling (contoh), Latihan Terbimbing, Umpan Balik, Latihan Mandiri, Penutup.
- LKPD berisi latihan bertahap dari contoh ke mandiri.
- Asesmen menilai ketepatan dan kemandirian.`
                  : isCoop
                    ? `ATURAN MODEL (Cooperative Learning):
- Kegiatan Inti wajib memuat struktur kooperatif yang jelas (minimal 1 teknik dipilih dan ditulis eksplisit: Think-Pair-Share atau Jigsaw atau STAD).
- Wajib tulis pembagian peran anggota (ketua, pencatat, penjaga waktu, penyaji) dan aturan kerja kelompok.
- LKPD berisi tugas kelompok + bagian kontribusi individu.
- Rubrik wajib memuat kolaborasi, komunikasi, kontribusi individu, dan kualitas hasil.`
                    : `ATURAN MODEL (UMUM):
- Kegiatan Inti wajib menuliskan fase-fase model "${M.modelPembelajaran}" secara eksplisit di tabel dan aktivitas harus konkret.
- LKPD dan Rubrik harus konsisten dengan model yang dipilih.`;

        const sys = String(baselineModulAjar || '').trim()
          ? `Anda adalah editor kurikulum Indonesia. Tugas Anda adalah merevisi dokumen Modul Ajar yang sudah ada agar selaras dengan kurikulum dan pendekatan yang diminta, tanpa mengurangi kelengkapan dan tanpa mengubah struktur utama. Gunakan Bahasa Indonesia baku dan formal.`
          : `Anda adalah pakar desainer kurikulum Indonesia. Buat Modul Ajar lengkap sesuai kurikulum dan pendekatan yang diminta. Gunakan Bahasa Indonesia baku dan formal. Hasilkan konten LENGKAP, DETAIL, SIAP PAKAI — tidak boleh ada placeholder. Rubrik wajib skala 1–4.`;

        const modeSupervisiLabel = M.supervisi
          ? (isK13 ? 'AKTIF (sertakan KD, Indikator, KKM)' : 'AKTIF (sertakan CP, ATP, KKTP)')
          : (isK13 ? 'NONAKTIF (tanpa KD/Indikator/KKM)' : 'NONAKTIF (tanpa CP, ATP, KKTP)');

        const supervisiA = M.supervisi
          ? (isK13
              ? `
### Kompetensi Dasar (KD) dan Indikator
Buat tabel:
- KD Pengetahuan (3.x) | Indikator
- KD Keterampilan (4.x) | Indikator
Minimal 2 KD per ranah dan indikatornya terukur serta relevan dengan materi "${M.judulModul}".`
              : `
### Capaian Pembelajaran (CP)
Buat tabel: Elemen | Capaian Pembelajaran (ringkas, sesuai jenjang/fase). Minimal 3 elemen. Pastikan CP relevan dengan materi "${M.judulModul}".`)
          : ``;

        const supervisiB = M.supervisi
          ? (isK13
              ? `
### Kriteria Ketuntasan Minimal (KKM)
Tuliskan KKM yang realistis untuk topik ini dan jelaskan kriteria ketuntasan secara ringkas.`
              : `
### Alur Tujuan Pembelajaran (ATP)
Buat tabel: Pertemuan | Tujuan Pembelajaran | Materi Kunci | Asesmen | Catatan.
Jumlah baris harus sesuai "Jumlah Pertemuan" (${M.jumlahPertemuan}). Tujuan harus konsisten dengan CP dan materi.`)
          : ``;

        const supervisiC = M.supervisi && !isK13
          ? `
### 2. Kriteria Ketercapaian Tujuan Pembelajaran (KKTP)
Min. 4 indikator konkret dan terukur.`
          : ``;

        const instrKegiatan = (() => {
          const dur = String(M.durasi || '').trim();
          const durLabel = dur ? `${dur} menit` : '... menit';
          const common = `FORMAT WAJIB (RAPI) untuk SETIAP pertemuan:
- Bagian ini WAJIB menggunakan format uraian seperti contoh (bukan tabel) dan TANPA tanda heading '#'.
- Awali bagian ini dengan pemisah: '---' lalu judul tebal: '**5. Kegiatan Pembelajaran**'.
- Untuk setiap pertemuan, WAJIB tulis 3 bagian: **Kegiatan Pendahuluan**, **Kegiatan Inti**, **Kegiatan Penutup**.
- Format per pertemuan wajib seperti ini:
  ---
  **Pertemuan 1 (${durLabel})**

  **Kegiatan Pendahuluan (±... menit)**
  1. **Orientasi (N menit)**
     Uraian aktivitas guru & peserta didik secara detail dalam 1–2 kalimat.
  2. ...

  **Kegiatan Inti (±... menit)**
  1. **Fase/Sintaks Model (N menit)**
     Uraian aktivitas guru & peserta didik secara detail dalam 1–2 kalimat.
  2. ...

  **Kegiatan Penutup (±... menit)**
  1. **Refleksi (N menit)**
     Uraian aktivitas penutup secara jelas.
  2. ...
  ---
- Ketentuan format:
  - Gunakan penomoran '1.' '2.' dst (bukan bullet).
  - Judul langkah WAJIB tebal dan memuat durasi dalam tanda kurung '(N menit)'.
  - Baris uraian di bawahnya diindent 3 spasi.
- Ketentuan isi:
  - Kegiatan Pendahuluan minimal 4 langkah (mis. orientasi, apersepsi, motivasi, penyampaian tujuan/aturan main).
  - Kegiatan Inti minimal 7 langkah dan judul langkah WAJIB mengikuti fase/sintaks model (mis. Stimulation, Problem Statement, Data Collection, Mengamati, Menanya, dst).
  - Kegiatan Penutup minimal 3 langkah (mis. refleksi, rangkuman, tindak lanjut/penugasan, salam/doa).
- Alokasi waktu:
  - Total durasi Pendahuluan + Inti + Penutup untuk 1 pertemuan WAJIB = ${durLabel}.
  - Setiap langkah WAJIB punya durasi angka (menit).
- Jangan gunakan bullet simbol (●/○/•/).`;

          const intiK13 = `Untuk Kegiatan Inti (K13), gunakan alur saintifik 5M sebagai urutan langkah dengan urutan:
Mengamati → Menanya → Mencoba/Mengeksplorasi → Mengasosiasi/Menalar → Mengomunikasikan.
Pastikan masing-masing punya durasi dan aktivitas yang konkret.`;

          const intiMerdeka = `Untuk Kegiatan Inti, wajib mengikuti sintaks model "${M.modelPembelajaran}" dan menuliskan fase-fase model secara eksplisit sebagai judul langkah (tebal) pada langkah-langkah Kegiatan Inti. Aktivitas harus konkret (apa yang dilakukan guru & peserta didik, data/lembar kerja yang dipakai, output yang dihasilkan).`;

          const dlRules = `Khusus Deep Learning:
- Di Kegiatan Inti, wajib ada langkah eksplisit: **Eksplorasi**, **Analisis**, **Refleksi** (boleh dipetakan ke 5M untuk K13).
- Wajib ada elemen Mindful, Meaningful, Joyful tertulis eksplisit minimal 1 kali per pertemuan. Tulis sebagai 3 baris ringkas setelah Kegiatan Inti:
  Mindful: ...
  Meaningful: ...
  Joyful: ...
- Pertanyaan pemantik dan refleksi harus mendorong alasan (mengapa/bagaimana) dan transfer ke konteks nyata.`;

          if (isDL) return `${common}\n\n${isK13 ? intiK13 : intiMerdeka}\n\n${dlRules}`;
          return `${common}\n\n${isK13 ? intiK13 : intiMerdeka}`;
        })();

        const kbcKegiatan = isKBC
          ? `
Khusus KBC: Pada SETIAP pertemuan, sisipkan minimal 1 aktivitas/strategi yang mencerminkan unsur KBC (misalnya: afirmasi/niat belajar, praktik empati, gotong royong, kepedulian lingkungan, etika komunikasi, refleksi rasa syukur). Pastikan tertulis eksplisit di deskripsi kegiatan.`
          : ``;

        const ctlKegiatan = isCTL
          ? `
Khusus CTL: Pada SETIAP pertemuan, wajib ada urutan yang tampak jelas: (1) pemantik konteks nyata, (2) inkuiri/eksplorasi data, (3) diskusi/learning community, (4) modeling (contoh/produk/format), (5) presentasi/komunikasi hasil, (6) refleksi, (7) tindak lanjut/penerapan.
Di akhir langkah yang relevan, tuliskan tag komponen CTL yang muncul (gunakan tag persis): [Konstruktivisme], [Inkuiri], [Questioning], [Learning Community], [Modeling], [Refleksi], [Penilaian Autentik]. Pastikan ketujuh komponen muncul pada modul ini.`
          : ``;

        const identifikasiKesiapan = `
## IDENTIFIKASI KESIAPAN PESERTA DIDIK
Tulis ringkas, kontekstual, dan rapi memakai daftar Markdown (jangan gunakan simbol bullet seperti ●/○/•/).
Gunakan format ini:
- Pengetahuan Awal: 1–2 kalimat.
- Minat: 1 kalimat.
- Latar Belakang: 1–2 kalimat (contoh konteks keseharian yang relevan).
- Kebutuhan Belajar:
  - Visual: 1 kalimat (media/representasi yang digunakan).
  - Auditori: 1 kalimat (strategi mendengar/diskusi).
  - Kinestetik: 1 kalimat (aktivitas praktik/permainan/gerak).
Pastikan bagian ini muncul setelah A. INFORMASI UMUM.`;

        const bahanPendidik = `Untuk Pendidik: 3–4 paragraf panduan pedagogis sesuai pendekatan "${pendekatanLabel}" dan model "${M.modelPembelajaran}". Jangan menyebut pendekatan/model lain selain yang dipilih. Jika pendekatan bukan CTL, jangan menyebut CTL atau Contextual Teaching and Learning.`;

        const revisionLead = String(baselineModulAjar || '').trim()
          ? `Tolong lakukan REVISI TERARAH, bukan membuat ulang dari nol.

ATURAN REVISI:
1) Pertahankan struktur utama dan heading besar yang sudah ada. Jangan hapus bagian besar.
2) Fokus revisi pada:
   - Pertanyaan Pemantik
   - Kegiatan Pembelajaran per pertemuan (Pendahuluan/Inti/Penutup)
   - Asesmen (diagnostik/formatif/sumatif) dan rubriknya
   - Refleksi peserta didik & pendidik
   - (Opsional) LKPD bila perlu agar konsisten
3) Bagian lain pertahankan semaksimal mungkin, hanya rapikan jika perlu.
4) Output harus tetap berupa Modul Ajar lengkap dan siap pakai.
${!isKBC ? '\n5) Hapus semua bagian/penyebutan KBC jika ada pada baseline.' : ''}`
          : `Buatkan Modul Ajar LENGKAP dengan data berikut:`;

        const extra = [];
        let cp046PagesText = "";
        if (!isK13 && String(M.fase || '').trim()) {
          try {
            const ctrlCp = new AbortController();
            const timerCp = setTimeout(()=>ctrlCp.abort(), 45000);
            const respCp = await fetch("api/cp046_rpp_context.php", {
              method: "POST",
              headers: { "Content-Type": "application/json" },
              body: JSON.stringify({ docType: "modul_ajar", jenjang: jenjangEfektif, fase: M.fase, mapel, mapel_slug: String(M.mapel_cp046_slug || ""), materi: judul, kurikulum: kurikulumLabel }),
              signal: ctrlCp.signal,
            });
            clearTimeout(timerCp);
            if (respCp.ok) {
              const ctx = await respCp.json();
              const block = String(ctx?.block || "").trim();
              const pages = Array.isArray(ctx?.pages) ? ctx.pages.map(x => Number(x)).filter(x => Number.isFinite(x) && x > 0) : [];
              if (pages.length) cp046PagesText = pages.sort((a,b)=>a-b).join(", ");
              if (ctx?.ok && block) extra.unshift(block);
            }
          } catch {}
        }

        const usr = `${revisionLead}

=== DATA INPUT ===
Nama Guru         : ${M.namaGuru}
Institusi         : ${M.institusi}
Tahun             : ${new Date().getFullYear()}
Kurikulum         : ${kurikulumLabel}
Pendekatan        : ${pendekatanLabel}
Jenjang           : ${jenjangDisplay}
Kelas             : ${M.kelas||'-'}
Fase              : ${M.fase||'-'}
Mata Pelajaran    : ${M.mapel}
Judul Modul       : ${M.judulModul}
Jumlah Pertemuan  : ${M.jumlahPertemuan}
Durasi/Pertemuan  : ${M.durasi} menit
Model Pembelajaran: ${M.modelPembelajaran}
Jumlah Siswa      : ${M.jumlahSiswa||'30'} siswa
Dimensi Profil    : ${M.dimensi.join(', ')}
Mode Supervisi    : ${modeSupervisiLabel}
=================

${approachRules}
${modelRules}
${extra.length ? `

${extra.join('\n\n')}
` : ''}

Hasilkan Modul Ajar dengan SEMUA bagian berikut secara LENGKAP dan DETAIL:

## MODUL AJAR ${M.mapel.toUpperCase()}
### "${M.judulModul}"

## A. INFORMASI UMUM
Tabel 2 kolom (Komponen | Keterangan): Nama Penyusun, Institusi, Tahun, Jenjang, Kelas, Fase, Alokasi Waktu, Kompetensi Awal (2-3 kalimat), Dimensi Profil Lulusan (tiap dimensi 1-2 kalimat kontekstual), Sarana dan Prasarana, Target Peserta Didik, Model Pembelajaran.
${isKBC ? `
Tambahkan komponen khusus KBC:
- "Unsur KBC" (jelaskan singkat 4–6 nilai/unsur yang dipakai pada modul ini, misalnya: cinta kepada Tuhan YME, cinta diri, cinta sesama, cinta lingkungan, cinta bangsa; sesuaikan dengan materi dan konteks kelas).
- "Implementasi KBC" (2–4 poin praktik nyata yang terlihat di kegiatan pembelajaran, asesmen, dan refleksi).` : ``}
${supervisiA}

${identifikasiKesiapan}

## B. KOMPONEN INTI
${supervisiB}

### 1. Tujuan Pembelajaran
Min. 4 tujuan. WAJIB beri kode TP (TP1, TP2, TP3, dst) dan tulis dengan format:
- TP1: Peserta didik mampu [kata kerja Bloom] [objek] [kondisi/kriteria]
- TP2: ...
Pastikan semua TP terukur dan relevan dengan materi.

${supervisiC}

### Pemetaan Tujuan–Asesmen–Kegiatan–Bukti (WAJIB)
Buat tabel Markdown yang memetakan keterkaitan antar komponen agar dokumen nyambung.
Format tabel:
| Kode TP | Pertemuan & Kegiatan Kunci (Tahap/Komponen) | Bukti/Produk yang Dikumpulkan | Jenis Asesmen (Diagnostik/Form/Sum) | Aspek Rubrik yang Menilai |
|---|---|---|---|---|
Aturan:
- Setiap TP wajib muncul minimal 1 baris.
- Setiap pertemuan wajib mencantumkan bukti/produk yang jelas.
- Bukti/produk harus konsisten dengan model pembelajaran yang dipilih.

### 3. Asesmen
a. Diagnostik (Awal) — aktivitas konkret
b. Formatif (Proses) — cara guru memantau
c. Sumatif (Akhir) — produk/instrumen penilaian

### 4. Pertanyaan Pemantik
3 pertanyaan open-ended, kontekstual, mendorong berpikir kritis.

### 5. Kegiatan Pembelajaran
${instrKegiatan}
${kbcKegiatan}
${ctlKegiatan}

### 6. Refleksi
2–3 pertanyaan refleksi untuk Peserta Didik dan 2–3 untuk Pendidik.

## C. LAMPIRAN

### 1. LKPD
WAJIB konsisten dengan model pembelajaran yang dipilih dan nyambung dengan TP.
Format:
- Judul, Identitas siswa, Tujuan (sebutkan TP yang dituju), Petunjuk, Alat dan Bahan.
- Langkah Kegiatan minimal 5 langkah, WAJIB diberi kode: LKPD-1, LKPD-2, LKPD-3, dst.
- Setiap langkah LKPD wajib mencantumkan: aktivitas, output/bukti yang dihasilkan, dan kode TP yang didukung.
- 3 Pertanyaan Refleksi dan Kolom Kesimpulan.

### 2. Pengayaan dan Remedial
Pengayaan konkret. Remedial dengan strategi spesifik.

### 3. Bahan Bacaan
Untuk Peserta Didik: 3–4 paragraf sesuai jenjang.
${bahanPendidik}

### 4. Media Pembelajaran
Sumber video YouTube relevan, alat peraga, platform digital.

### 5. Glosarium
Min. 5 istilah kunci dengan definisi sesuai jenjang.

### 6. Rubrik Penilaian
Tabel: Aspek | Skor 4 (Sangat Baik) | Skor 3 (Baik) | Skor 2 (Cukup) | Skor 1 (Perlu Bimbingan)
Min. 5 aspek, deskripsi KONKRET dan DAPAT DIAMATI.
Aturan nyambung:
- Pada kolom "Aspek", WAJIB tulis keterkaitan TP (contoh: "Analisis masalah (TP2, TP3)" atau "Produk proyek (TP4)").
- Aspek rubrik harus selaras dengan Bukti/Produk di tabel Pemetaan.

### 7. Daftar Pustaka
Min. 3 referensi format APA (1 Kemendikbudristek, 1 buku pedagogi, 1 lainnya).

PENTING:
- Tidak ada placeholder. Semua konten kontekstual untuk ${M.mapel} kelas ${M.kelas||M.fase}. Bahasa Indonesia baku.
- Jika Mode Supervisi NONAKTIF: ${isK13 ? 'JANGAN tulis KD/Indikator/KKM sama sekali.' : 'JANGAN tulis CP, ATP, dan KKTP sama sekali.'}
${String(baselineModulAjar || '').trim() ? `

BASELINE MODUL AJAR (yang direvisi):
<<<
${baselineModulAjar}
>>>` : ''}`;

        try {
          const ctrl = new AbortController();
          const pertemuan = Math.min(MA_MAX_PERTEMUAN, Math.max(1, Number(M.jumlahPertemuan || 1) || 1));
          const timeoutMs = Math.min(600000, 150000 + pertemuan * 60000);
          const timer = setTimeout(()=>ctrl.abort(), timeoutMs);
          const postBody = (maxTokens) => JSON.stringify({ type:"modul_ajar", messages:[{role:"system",content:sys},{role:"user",content:usr}], model:OPENAI_MODEL, max_tokens: maxTokens });
          let resp = await fetch("api/openai_proxy.php", {
            method: "POST",
            headers: {"Content-Type":"application/json"},
            credentials: "same-origin",
            body: postBody(12000),
            signal: ctrl.signal,
          });
          if (!resp.ok && (resp.status === 502 || resp.status === 503 || resp.status === 504)) {
            try { await new Promise(r => setTimeout(r, 1200)); } catch {}
            resp = await fetch("api/openai_proxy.php", {
              method: "POST",
              headers: {"Content-Type":"application/json"},
              credentials: "same-origin",
              body: postBody(8000),
              signal: ctrl.signal,
            });
          }
          clearTimeout(timer);
          if (!resp.ok) throw new Error(`Proxy ${resp.status}: ${await resp.text()}`);
          const data = await resp.json();
          const text = data?.content || data?.choices?.[0]?.message?.content || '';
          if (!text) throw new Error("Respons API kosong.");
          const stripKbcFromModulAjar = (raw) => {
            const src = String(raw || '').replace(/\r\n/g, '\n').replace(/\r/g, '\n');
            const lines = src.split('\n');
            const out = [];
            for (let i = 0; i < lines.length; i++) {
              const line = String(lines[i] || '');
              const t = line.trim();
              if (!t) { out.push(line); continue; }
              if (/\bKBC\b/i.test(t)) continue;
              if (/berbasis\s+cinta/i.test(t)) continue;
              if (/unsur\s+kbc/i.test(t)) continue;
              if (/implementasi\s+kbc/i.test(t)) continue;
              out.push(line);
            }
            return out.join('\n').replace(/\n{3,}/g, '\n\n').trim();
          };
          const stripCtlFromModulAjar = (raw) => {
            let s = String(raw || '').replace(/\r\n/g, '\n').replace(/\r/g, '\n');
            s = s.replace(/(^###\s*(Pendekatan|Catatan)\s+CTL[\s\S]*?)(?=^###\s|^##\s|\n##\s|$)/gmi, '');
            s = s.replace(/\[(Konstruktivisme|Inkuiri|Questioning|Learning Community|Modeling|Refleksi|Penilaian Autentik)\]/gi, '');
            s = s.replace(/[^\n.!?]*\bCTL\b[^\n.!?]*[.!?]?/gi, '');
            s = s.replace(/[^\n.!?]*contextual\s+teaching\s+and\s+learning[^\n.!?]*[.!?]?/gi, '');
            s = s.replace(/\n{3,}/g, '\n\n');
            s = s.replace(/[ \t]{2,}/g, ' ');
            return s.trim();
          };
          const ensureCtlInModulAjar = (raw) => {
            if (!isCTL) return String(raw || '');
            const src0 = String(raw || '').replace(/\r\n/g, '\n').replace(/\r/g, '\n');
            const hasCtl = /\bCTL\b/i.test(src0) || /konstruktivisme/i.test(src0) || /learning community/i.test(src0) || /\[Konstruktivisme\]/i.test(src0);
            if (hasCtl) return src0;
            const block = [
              '### Pendekatan CTL (Contextual Teaching and Learning)',
              'Ringkas implementasi CTL pada modul ini (wajib ada dan aplikatif):',
              '| Komponen CTL | Implementasi Praktis di Modul Ini |',
              '|---|---|',
              '| Konstruktivisme | Aktivasi pengetahuan awal, mengaitkan konsep dengan konteks nyata, peserta didik menyusun pemahaman lewat kegiatan. |',
              '| Inkuiri | Peserta didik melakukan penyelidikan sederhana: merumuskan masalah, mengumpulkan data, menganalisis, menyimpulkan. |',
              '| Questioning | Pertanyaan pemantik (guru) + pertanyaan investigasi (peserta didik) ditulis eksplisit. |',
              '| Learning Community | Diskusi pasangan/kelompok, berbagi temuan, saling umpan balik dengan peran yang jelas. |',
              '| Modeling | Guru memberi contoh produk/format/strategi (contoh jawaban, contoh tabel, contoh prosedur, contoh rubrik). |',
              '| Refleksi | Refleksi peserta didik dan pendidik yang mengaitkan konsep dengan konteks nyata dan perbaikan berikutnya. |',
              '| Penilaian Autentik | Penilaian proses + produk/kinerja/portofolio dengan rubrik skala 1–4. |',
              '',
              'Catatan: Pada tabel kegiatan per pertemuan, tuliskan tag CTL di kolom "Catatan": [Konstruktivisme], [Inkuiri], [Questioning], [Learning Community], [Modeling], [Refleksi], [Penilaian Autentik].',
            ].join('\n');
            const lines = src0.split('\n');
            let idx = -1;
            for (let i = 0; i < lines.length; i++) {
              const t = String(lines[i] || '').trim();
              if (/^##\s*B\.\s*KOMPONEN\s+INTI\b/i.test(t) || /^##\s*KOMPONEN\s+INTI\b/i.test(t)) { idx = i; break; }
            }
            if (idx >= 0) {
              let insertAt = idx + 1;
              while (insertAt < lines.length && String(lines[insertAt] || '').trim() === '') insertAt++;
              lines.splice(insertAt, 0, block, '');
              return lines.join('\n').replace(/\n{3,}/g, '\n\n').trim();
            }
            return `${src0.trim()}\n\n${block}\n`;
          };
          const finalText0 = isKBC ? text : stripKbcFromModulAjar(text);
          const finalText1 = ensureCtlInModulAjar(finalText0);
          const finalText = isCTL ? finalText1 : stripCtlFromModulAjar(finalText1);
          const ensureCp046InDaftarPustaka = (raw, pagesText) => {
            const src = String(raw || '').replace(/\r\n/g, '\n').replace(/\r/g, '\n');
            if (!String(pagesText || '').trim()) return src;
            if (/\bCP046\b/i.test(src)) return src;
            const p = String(pagesText || '').trim();
            const refLine = `- Kementerian Pendidikan, Kebudayaan, Riset, dan Teknologi. (2022). Capaian Pembelajaran (CP046). (CP046 hal. ${p}).`;
            const lines = src.split('\n');
            let idx = -1;
            for (let i = 0; i < lines.length; i++) {
              const t = String(lines[i] || '').trim();
              if (/^###\s*7\.\s*Daftar\s+Pustaka\b/i.test(t) || /^###\s*Daftar\s+Pustaka\b/i.test(t)) { idx = i; break; }
            }
            if (idx >= 0) {
              let insertAt = idx + 1;
              while (insertAt < lines.length && String(lines[insertAt] || '').trim() === '') insertAt++;
              lines.splice(insertAt, 0, refLine);
              return lines.join('\n').replace(/\n{3,}/g, '\n\n').trim();
            }
            return `${src.trim()}\n\n### 7. Daftar Pustaka\n${refLine}\n`;
          };
          const finalText2 = ensureCp046InDaftarPustaka(finalText, cp046PagesText);
          state.modulAjar.hasil = finalText2;
          state.modulAjar.isGenerating = false;
          saveDebounced(true);
          render();
          // Catat token & biaya + kurangi limit seperti generate soal
          try {
            const usageIn = Number(data?.usage?.prompt_tokens ?? data?.usage?.input_tokens ?? data?._usage?.in ?? 0);
            const usageOut = Number(data?.usage?.completion_tokens ?? data?.usage?.output_tokens ?? data?._usage?.out ?? 0);
            const title = `Modul Ajar - ${M.mapel || ''} - ${M.judulModul || ''}`.trim();
            const snapshot = {
              identity: { jenjang: M.jenjang||'', kelas: M.kelas||'', mataPelajaran: M.mapel||'' },
              modulAjar: { ...M, hasil: finalText2 },
              questions: []
            };
            await fetch("api/soal_user.php", {
              method: "POST",
              headers: {"Content-Type":"application/json"},
              body: JSON.stringify({ type: "save", title, state: snapshot, token_input: usageIn, token_output: usageOut, model: OPENAI_MODEL })
            });
            await fetch("api/openai_proxy.php", {
              method: "POST",
              headers: {"Content-Type":"application/json"},
              credentials: "same-origin",
              body: JSON.stringify({ type: "add_tokens", input_tokens: usageIn, output_tokens: usageOut })
            });
            const maCost = Number(state.limitConfig?.costs?.modul_ajar ?? 3);
            const calls = [];
            for (let i=0;i<maCost;i++) {
              calls.push(fetch("api/openai_proxy.php", {
                method: "POST",
                headers: {"Content-Type":"application/json"},
                credentials: "same-origin",
                body: JSON.stringify({ type: "decrement_package" })
              }));
            }
            await Promise.all(calls);
            try { await computeStats(); } catch {}
            // Log kredit lokal
            logCreditUsage('Modul Ajar', maCost, `${M.mapel||''} • ${M.judulModul||''}`);
          } catch {}
        } catch(e) {
          state.modulAjar.isGenerating = false;
          render();
          const msg = (e && (e.name === 'AbortError' || /aborted/i.test(String(e.message || ''))))
            ? 'Proses terlalu lama (timeout). Semakin banyak JP/pertemuan dan semakin detail tabel, waktu proses makin lama. Silakan coba lagi dan tunggu sampai selesai.'
            : (e?.message || 'Terjadi kesalahan.');
          setTimeout(()=>{ const el=document.getElementById('maError'); if(el){el.textContent='⚠️ Gagal: '+msg; el.classList.remove('hidden');} }, 120);
        }
      }

      async function refineModulAjarKegiatan() {
        const M = state.modulAjar || {};
        if (M.kegiatanRefinedOnce) { alert('Perjelas Kegiatan hanya dapat dilakukan 1x setiap kali generate Modul Ajar. Jika ingin memperjelas lagi, silakan generate ulang Modul Ajar.'); return; }
        const md = String(M.hasil || '').trim();
        if (!md) { alert('Modul Ajar belum tersedia. Generate dulu.'); return; }
        if (M.isGenerating || M.isRefiningKegiatan) return;
        state.modulAjar.isRefiningKegiatan = true;
        state.modulAjarError = null;
        render();

        const splitKegiatan = (raw) => {
          const lines = String(raw || '').replace(/\r\n/g, '\n').replace(/\r/g, '\n').split('\n');
          const isStart = (t) => /^(?:#{1,6}\s*)?(?:\*{0,2})?(?:\d+\.\s*)?Kegiatan\s+Pembelajaran\b/i.test(String(t || '').trim()) || /^\s*\*\*\s*5\.\s*Kegiatan\s+Pembelajaran\s*\*\*\s*$/i.test(String(t || ''));
          const isEnd = (t) => /^(?:#{1,6}\s*)?(?:\*{0,2})?(?:6\.\s*)?Rubrik\s+Penilaian\b/i.test(String(t || '').trim())
            || /^(?:#{1,6}\s*)?(?:\*{0,2})?(?:7\.\s*)?Daftar\s+Pustaka\b/i.test(String(t || '').trim())
            || /^(?:#{1,6}\s*)?(?:\*{0,2})C\.\s*LAMPIRAN\b/i.test(String(t || '').trim());
          let start = -1;
          for (let i = 0; i < lines.length; i++) {
            if (isStart(lines[i])) { start = i; break; }
          }
          if (start < 0) return null;
          let end = lines.length;
          for (let i = start + 1; i < lines.length; i++) {
            if (isEnd(lines[i])) { end = i; break; }
          }
          return {
            before: lines.slice(0, start).join('\n').trimEnd(),
            section: lines.slice(start, end).join('\n').trim(),
            after: lines.slice(end).join('\n').trimStart(),
          };
        };

        try {
          const parts = splitKegiatan(md);
          if (!parts || !String(parts.section || '').trim()) throw new Error('Bagian Kegiatan Pembelajaran tidak ditemukan.');

          const sys = 'Anda adalah editor kurikulum Indonesia. Tugas Anda memperjelas uraian kegiatan pembelajaran tanpa mengubah struktur utama. Gunakan Bahasa Indonesia baku dan formal.';
          const usr = `Perjelas bagian berikut agar lebih detail (lebih kaya langkah operasional), tetapi WAJIB patuhi aturan ini:\n- Jangan mengubah urutan pertemuan.\n- Jangan mengubah judul pertemuan, judul bagian (Pendahuluan/Inti/Penutup), dan jangan mengubah format pemisah '---'.\n- Jangan menambah atau menghapus nomor langkah. Nomor 1., 2., dst harus tetap sama.\n- Jangan mengubah durasi yang sudah ada pada judul langkah (angka menit dalam tanda kurung).\n- Perjelas uraian tiap langkah menjadi 2–4 kalimat: jelaskan peran guru & murid, media/LKPD, output/produk, dan transisi singkat ke langkah berikutnya.\n- Hindari simbol bullet aneh (●/○/).\n\nKeluarkan HANYA bagian hasil revisinya (mulai dari judul Kegiatan Pembelajaran sampai sebelum bagian berikutnya).\n\nBAGIAN YANG DIREVISI:\n<<<\n${parts.section}\n>>>`;

          const ctrl = new AbortController();
          const timer = setTimeout(() => ctrl.abort(), 240000);
          const resp = await fetch('api/openai_proxy.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ type: 'modul_ajar', messages: [{ role: 'system', content: sys }, { role: 'user', content: usr }], model: OPENAI_MODEL, max_tokens: 8000 }),
            signal: ctrl.signal,
          });
          clearTimeout(timer);
          if (!resp.ok) throw new Error(`Proxy ${resp.status}: ${await resp.text()}`);
          const data = await resp.json();
          const refined = String(data?.content || data?.choices?.[0]?.message?.content || '').trim();
          if (!refined) throw new Error('Respons API kosong.');

          const merged = [parts.before, refined, parts.after].filter(s => String(s || '').trim()).join('\n\n').replace(/\n{3,}/g, '\n\n').trim();
          state.modulAjar.hasil = merged;
          state.modulAjar.isRefiningKegiatan = false;
          state.modulAjar.kegiatanRefinedOnce = true;
          saveDebounced(true);
          render();
        } catch (e) {
          state.modulAjar.isRefiningKegiatan = false;
          render();
          const msg = (e && (e.name === 'AbortError' || /aborted/i.test(String(e.message || ''))))
            ? 'Proses terlalu lama (timeout). Silakan coba lagi.'
            : (e?.message || 'Terjadi kesalahan.');
          setTimeout(()=>{ const el=document.getElementById('maError'); if(el){el.textContent='⚠️ Gagal: '+msg; el.classList.remove('hidden');} }, 120);
        }
      }

      async function exportModulAjarDocx() {
        const M = state.modulAjar || {};
        if (!M.hasil) return;
        const btn = document.getElementById('btnExportMA') || document.getElementById('btnExportMA2');
        const origHTML = btn?.innerHTML;
        if (btn) { btn.disabled=true; btn.textContent='Membuat file...'; }

        try {
          const { Document,Packer,Paragraph,TextRun,Table,TableRow,TableCell,
                  AlignmentType,BorderStyle,WidthType,ShadingType,
                  Footer,PageNumber,PageBreak } = docx;

          const FONT='Times New Roman', SZ=24, CW=9360;
          const bdr={style:BorderStyle.SINGLE,size:4,color:'999999'};
          const borders={top:bdr,bottom:bdr,left:bdr,right:bdr};
          const sp=(b=60,a=60)=>({spacing:{before:b,after:a}});

          function parseContent(raw) {
            const src = maInsertPageBreakMarkers(String(raw || ''));
            const lines = src.split('\n');
            const out = [];
            let tblRows=[], inTbl=false;
            let lastWasPB = false;
            let lastWasBlank = false;
            const parseRuns = (text, baseSize = SZ) => {
              const s = String(text || '');
              const runs = [];
              const re = /\*\*(.+?)\*\*/g;
              let last = 0;
              let m;
              while ((m = re.exec(s)) !== null) {
                const pre = s.slice(last, m.index);
                if (pre) runs.push(new TextRun({ text: pre.replace(/\*(.+?)\*/g, '$1'), font: FONT, size: baseSize }));
                runs.push(new TextRun({ text: String(m[1] || '').replace(/\*(.+?)\*/g, '$1'), font: FONT, size: baseSize, bold: true }));
                last = m.index + m[0].length;
              }
              const tail = s.slice(last);
              if (tail) runs.push(new TextRun({ text: tail.replace(/\*(.+?)\*/g, '$1'), font: FONT, size: baseSize }));
              if (!runs.length) runs.push(new TextRun({ text: s.replace(/\*\*(.+?)\*\*/g, '$1').replace(/\*(.+?)\*/g, '$1'), font: FONT, size: baseSize }));
              return runs;
            };

            const flushTbl = () => {
              if (!tblRows.length) return;
              const nc = Math.max(...tblRows.map(r=>r.length));
              const cw = Math.floor(CW/nc);
              out.push(new Table({
                width:{size:CW,type:WidthType.DXA},
                columnWidths:Array(nc).fill(cw),
                rows: tblRows.map((row,ri)=>new TableRow({
                  children: Array.from({length:nc},(_,ci)=>{
                    const txt=(row[ci]||'').trim().replace(/<[^>]+>/g,' ').replace(/&nbsp;/g,' ').replace(/\*\*(.+?)\*\*/g,'$1').replace(/\*(.+?)\*/g,'$1').replace(/\s+/g,' ').trim();
                    return new TableCell({
                      borders, width:{size:cw,type:WidthType.DXA},
                      shading: ri===0?{fill:'D9D9D9',type:ShadingType.CLEAR}:{fill:'FFFFFF',type:ShadingType.CLEAR},
                      margins:{top:80,bottom:80,left:100,right:100},
                      children:[new Paragraph({children:[new TextRun({text:txt,font:FONT,size:20,bold:ri===0})]})]
                    });
                  })
                }))
              }));
              tblRows=[]; inTbl=false;
            };

            for (let i=0;i<lines.length;i++) {
              const line=lines[i];
              if (String(line || '').trim() === '[[PAGE_BREAK]]') {
                if (inTbl) flushTbl();
                if (out.length === 0 || lastWasPB) continue;
                out.push(new Paragraph({ children: [new PageBreak()] }));
                lastWasPB = true;
                lastWasBlank = false;
                continue;
              }
              if (String(line || '').trim().match(/^---+$/)) {
                if (inTbl) flushTbl();
                if (out.length === 0 || lastWasPB) continue;
                out.push(new Paragraph({ border: { bottom: { style: BorderStyle.SINGLE, size: 6, color: '999999' } }, ...sp(80, 80) }));
                lastWasBlank = false;
                lastWasPB = false;
                continue;
              }
              const fullBold = String(line || '').match(/^\s*\*\*(.+?)\*\*\s*$/);
              if (fullBold) {
                if (inTbl) flushTbl();
                out.push(new Paragraph({keepNext:true,keepLines:true,...sp(180,80),children:[new TextRun({text:String(fullBold[1] || '').trim(),font:FONT,size:26,bold:true})]}));
                continue;
              }
              if (line.match(/^\|[-|: ]+\|?$/)) continue;
              if (line.trim().startsWith('|')) {
                inTbl=true;
                tblRows.push(line.split('|').slice(1,-1).map(c=>c.trim()));
                continue;
              }
              if (inTbl) flushTbl();
              if (!line.trim()) {
                if (out.length === 0 || lastWasBlank || lastWasPB) continue;
                out.push(new Paragraph({...sp(40,40)}));
                lastWasBlank = true;
                continue;
              }
              lastWasPB = false;
              lastWasBlank = false;
              if (/^####\s*/.test(line)) {
                out.push(new Paragraph({keepNext:true,keepLines:true,...sp(140,60),children:[new TextRun({text:line.replace(/^####\s*/,''),font:FONT,size:24,bold:true})]}));
              } else if (/^###\s*/.test(line)) {
                out.push(new Paragraph({keepNext:true,keepLines:true,...sp(180,80),children:[new TextRun({text:line.replace(/^###\s*/,''),font:FONT,size:26,bold:true})]}));
              } else if (/^##\s*/.test(line)) {
                out.push(new Paragraph({keepNext:true,keepLines:true,...sp(240,100),children:[new TextRun({text:line.replace(/^##\s*/,''),font:FONT,size:28,bold:true})]}));
              } else if (/^#\s*/.test(line)) {
                out.push(new Paragraph({keepNext:true,keepLines:true,...sp(280,120),children:[new TextRun({text:line.replace(/^#\s*/,''),font:FONT,size:32,bold:true})]}));
              } else if (/^\s*[-•]\s+/.test(line)) {
                const lead = (line.match(/^\s*/)?.[0] || '').length;
                const level = lead >= 2 ? 1 : 0;
                const left = level ? 760 : 400;
                const hang = 200;
                out.push(new Paragraph({
                  ...sp(40,40),
                  indent:{left,hanging:hang},
                  children:[new TextRun({text:'• ',font:FONT,size:SZ}), ...parseRuns(String(line).replace(/^\s*[-•]\s+/,''), SZ)]
                }));
              } else if (line.match(/^\d+\. /)) {
                const m=line.match(/^(\d+)\. (.*)/);
                const n = m ? m[1] : '';
                const rest = m ? String(m[2] || '') : String(line || '');
                out.push(new Paragraph({...sp(40,10),indent:{left:400,hanging:200},children:[new TextRun({text:`${n}. `,font:FONT,size:SZ}), ...parseRuns(rest, SZ)]}));
                let k = i + 1;
                while (k < lines.length) {
                  const l = lines[k] || '';
                  if (!l.trim()) break;
                  if (String(l).trim().match(/^---+$/)) break;
                  if (/^\s*\d+\.\s+/.test(l) || /^\s*[-•]\s+/.test(l) || /^\s*#{1,4}\s*/.test(l) || l.includes('|')) break;
                  if (/^\s{2,}\S/.test(l)) {
                    out.push(new Paragraph({...sp(0,10),indent:{left:650},children:parseRuns(String(l).trim(), SZ)}));
                    k++;
                    continue;
                  }
                  break;
                }
                i = k - 1;
              } else {
                out.push(new Paragraph({...sp(60,60),children:parseRuns(String(line || ''), SZ)}));
              }
            }
            if (inTbl) flushTbl();
            return out;
          }

          let contentText = maNormalizeContent(M);
          const { pre: preLKPD, lkpd: lkpdText, post: postLKPD } = maSplitLKPD(contentText);
          const lkpdData = maEnsureLKPDData(lkpdText ? maParseLKPD(lkpdText, M) : { judul: M.judulModul || '', tujuan: [], alat: [], langkah: [], refleksi: [], kesimpulan: [] }, M, lkpdText);
          const lkpdTable = lkpdData ? new Table({
            width: { size: CW, type: WidthType.DXA },
            rows: [
              new TableRow({
                children: [
                  new TableCell({
                    borders,
                    shading: { fill: 'D9D9D9', type: ShadingType.CLEAR },
                    margins: { top: 120, bottom: 120, left: 120, right: 120 },
                    children: [
                      new Paragraph({
                        alignment: AlignmentType.CENTER,
                        children: [new TextRun({ text: 'LEMBAR KERJA PESERTA DIDIK (LKPD)', font: FONT, size: 22, bold: true })],
                      }),
                      new Paragraph({
                        alignment: AlignmentType.CENTER,
                        children: [new TextRun({ text: String(lkpdData.judul || '').trim() || ' ', font: FONT, size: 20 })],
                      }),
                    ],
                  }),
                ],
              }),
              new TableRow({
                children: [
                  new TableCell({
                    borders,
                    margins: { top: 120, bottom: 120, left: 160, right: 160 },
                    children: [
                      new Paragraph({ ...sp(60, 80), children: [new TextRun({ text: 'Nama Siswa : ________________________________', font: FONT, size: SZ })] }),
                      new Paragraph({ ...sp(20, 80), children: [new TextRun({ text: 'Kelas     : ________________________________', font: FONT, size: SZ })] }),
                      new Paragraph({ ...sp(20, 120), children: [new TextRun({ text: 'Kelompok  : ________________________________', font: FONT, size: SZ })] }),
                      new Paragraph({ ...sp(120, 40), children: [new TextRun({ text: 'A. Tujuan', font: FONT, size: SZ, bold: true })] }),
                      ...((lkpdData.tujuan && lkpdData.tujuan.length) ? lkpdData.tujuan : ['-']).map(t =>
                        new Paragraph({ ...sp(20, 20), indent: { left: 400, hanging: 200 }, children: [new TextRun({ text: '• ' + String(t), font: FONT, size: SZ })] })
                      ),
                      new Paragraph({ ...sp(120, 40), children: [new TextRun({ text: 'B. Alat dan Bahan', font: FONT, size: SZ, bold: true })] }),
                      ...((lkpdData.alat && lkpdData.alat.length) ? lkpdData.alat : ['-']).map(t =>
                        new Paragraph({ ...sp(20, 20), indent: { left: 400, hanging: 200 }, children: [new TextRun({ text: '• ' + String(t), font: FONT, size: SZ })] })
                      ),
                      new Paragraph({ ...sp(120, 40), children: [new TextRun({ text: 'C. Langkah Kegiatan', font: FONT, size: SZ, bold: true })] }),
                      ...((lkpdData.langkah && lkpdData.langkah.length) ? lkpdData.langkah : ['-']).map((t, idx) => {
                        const txt = String(t);
                        const numbered = /^\d+[\.\)]\s+/.test(txt) ? txt : `${idx + 1}. ${txt}`;
                        return new Paragraph({ ...sp(20, 20), indent: { left: 400, hanging: 200 }, children: [new TextRun({ text: numbered, font: FONT, size: SZ })] });
                      }),
                      new Paragraph({ ...sp(120, 40), children: [new TextRun({ text: 'D. Pertanyaan Refleksi', font: FONT, size: SZ, bold: true })] }),
                      ...((lkpdData.refleksi && lkpdData.refleksi.length) ? lkpdData.refleksi : ['-']).map((t, idx) => {
                        const txt = String(t);
                        const numbered = /^\d+[\.\)]\s+/.test(txt) ? txt : `${idx + 1}. ${txt}`;
                        return new Paragraph({ ...sp(20, 20), indent: { left: 400, hanging: 200 }, children: [new TextRun({ text: numbered, font: FONT, size: SZ })] });
                      }),
                      new Paragraph({ ...sp(60, 20), children: [new TextRun({ text: 'Jawaban:', font: FONT, size: SZ })] }),
                      ...Array.from({ length: 3 }).map(() => new Paragraph({ ...sp(20, 60), children: [new TextRun({ text: '____________________________________________', font: FONT, size: SZ })] })),
                      new Paragraph({ ...sp(120, 40), children: [new TextRun({ text: 'E. Kesimpulan', font: FONT, size: SZ, bold: true })] }),
                      ...Array.from({ length: 3 }).map(() => new Paragraph({ ...sp(20, 60), children: [new TextRun({ text: '____________________________________________', font: FONT, size: SZ })] })),
                    ],
                  }),
                ],
              }),
            ],
          }) : null;

          const children = [
            new Paragraph({alignment:AlignmentType.CENTER,...sp(200,60),
              children:[new TextRun({text:`MODUL AJAR ${(M.mapel||'').toUpperCase()}`,font:FONT,size:32,bold:true})]}),
            new Paragraph({alignment:AlignmentType.CENTER,...sp(0,260),
              children:[new TextRun({text:`"${M.judulModul||''}"`,font:FONT,size:26,italics:true})]}),
            ...parseContent(maStripCLampiranHeading(preLKPD || '')),
            ...(lkpdTable ? [
              new Paragraph({ children: [new PageBreak()] }),
              new Paragraph({ ...sp(200, 80), children: [new TextRun({ text: 'C. LAMPIRAN', font: FONT, size: 26, bold: true })] }),
              new Paragraph({ ...sp(80, 80), children: [new TextRun({ text: '1. Lembar Kerja Peserta Didik (LKPD)', font: FONT, size: 24, bold: true })] }),
              lkpdTable,
              new Paragraph({ ...sp(120, 60) }),
              new Paragraph({ children: [new PageBreak()] }),
            ] : []),
            ...parseContent(maStripCLampiranHeading(postLKPD || ''))
          ];

          const doc2 = new Document({
            styles:{default:{document:{run:{font:FONT,size:SZ}}}},
            sections:[{
              properties:{page:{size:{width:12240,height:15840},margin:{top:1440,right:1440,bottom:1440,left:1440}}},
              footers:{default:new Footer({children:[new Paragraph({
                alignment:AlignmentType.CENTER,
                children:[
                  new TextRun({text:`Modul Ajar ${M.mapel||''} — ${M.institusi||''} ${new Date().getFullYear()} | Halaman `,font:FONT,size:18,color:'888888'}),
                  new TextRun({children:[PageNumber.CURRENT],font:FONT,size:18,color:'888888'})
                ]
              })]})},
              children
            }]
          });

          const blob = await Packer.toBlob(doc2);
          const url = URL.createObjectURL(blob);
          const a = document.createElement('a');
          a.href=url;
          a.download=`ModulAjar_${(M.mapel||'Mapel').replace(/\s+/g,'')}_${(M.judulModul||'Modul').replace(/[\s/]+/g,'_')}.docx`;
          a.click();
          URL.revokeObjectURL(url);
        } catch(e) {
          alert('Gagal membuat file: '+e.message);
        } finally {
          if (btn) { btn.disabled=false; btn.innerHTML=origHTML||'<span class="material-symbols-outlined text-[16px]">download</span> Download .docx'; }
        }
      }
      // ═══════════════════════════════════════════════
      //  END MODUL AJAR
      // ═══════════════════════════════════════════════

      function stripRppDuplicateInfo(raw) {
        let txt = String(raw || '');
        txt = txt.replace(/\r\n/g, '\n').replace(/\r/g, '\n');
        const label = '(Sekolah|Satuan\\s*Pendidikan|Kelas\\s*\\/\\s*Semester|Kelas|Semester|Mata\\s*Pelajaran|Mapel|Materi|Materi\\s*Pokok|Topik|Alokasi\\s*Waktu|Waktu|Nama\\s*Guru|Guru|Tahun\\s*Pelajaran|Tahun)';
        const oneMetaLine = String.raw`(?:\s*(?:[-*•]\s*)?(?:\d+[\.\)]\s*)?(?:\*\*)?${label}(?:\*\*)?\s*[:：]\s*[^\n]*\n)`;
        const headingLine = String.raw`(?:\s*(?:#{1,6}\s*)?(?:RPP\b[^\n]*|Rencana\s+Pelaksanaan\s+Pembelajaran[^\n]*)\n)`;
        const blockRe = new RegExp(String.raw`(?:^|\n)${headingLine}(?:(?:\s*\n)*)(${oneMetaLine}){2,}(?:\s*\n)*`, 'gi');
        txt = txt.replace(blockRe, '\n');
        return txt;
      }

      function rppNormalizeRubrikToTables(raw) {
        const src = String(raw || '').replace(/\r\n/g, '\n').replace(/\r/g, '\n');
        const lines = src.split('\n');
        const isRubrikHeading = (line) => /^(?:#{1,6}\s*)?Rubrik\s+Penilaian\b/i.test(String(line || '').trim());
        const isHeading = (line) => /^(?:#{1,6}\s+|[ABC]\.\s+)/.test(String(line || '').trim());
        const pickLabel = (line) => {
          const t = String(line || '').trim();
          const m = t.match(/^(?:[-*•]\s*)?(Sikap|Pengetahuan|Keterampilan)\s*:?$/i);
          return m ? String(m[1] || '').toLowerCase() : null;
        };
        const parseSikapItem = (line) => {
          const t = String(line || '').trim();
          const m = t.match(/^(?:[-*•]\s*)?([1-5])\s*[:.\-]\s*(.+)$/);
          return m ? { skor: m[1], ket: m[2].trim() } : null;
        };
        const parseRangeItem = (line) => {
          const t = String(line || '').trim();
          const m = t.match(/^(?:[-*•]\s*)?(\d{1,3}\s*-\s*\d{1,3})\s*[:.\-]\s*(.+)$/);
          return m ? { rentang: m[1].replace(/\s+/g, ''), ket: m[2].trim() } : null;
        };

        let start = -1;
        for (let i = 0; i < lines.length; i++) {
          if (isRubrikHeading(lines[i])) { start = i; break; }
        }
        if (start === -1) return raw;
        let end = lines.length;
        for (let i = start + 1; i < lines.length; i++) {
          if (isRubrikHeading(lines[i])) continue;
          if (isHeading(lines[i])) { end = i; break; }
        }
        const block = lines.slice(start + 1, end);
        const sections = { sikap: [], pengetahuan: [], keterampilan: [] };
        let cur = null;
        for (let i = 0; i < block.length; i++) {
          const line = block[i];
          const lbl = pickLabel(line);
          if (lbl) { cur = lbl; continue; }
          if (!cur) continue;
          if (cur === 'sikap') {
            const it = parseSikapItem(line);
            if (it) sections.sikap.push(it);
          } else if (cur === 'pengetahuan' || cur === 'keterampilan') {
            const it = parseRangeItem(line);
            if (it) sections[cur].push(it);
          }
        }
        const hasAny = sections.sikap.length || sections.pengetahuan.length || sections.keterampilan.length;
        if (!hasAny) return raw;

        const out = [];
        out.push('## Rubrik Penilaian');
        if (sections.sikap.length) {
          out.push('');
          out.push('### Sikap');
          out.push('| Skor | Kriteria |');
          out.push('| ---: | --- |');
          sections.sikap.forEach(it => out.push(`| ${String(it.skor)} | ${String(it.ket).replace(/\|/g,'\\|')} |`));
        }
        if (sections.pengetahuan.length) {
          out.push('');
          out.push('### Pengetahuan');
          out.push('| Rentang Nilai | Kriteria |');
          out.push('| --- | --- |');
          sections.pengetahuan.forEach(it => out.push(`| ${String(it.rentang)} | ${String(it.ket).replace(/\|/g,'\\|')} |`));
        }
        if (sections.keterampilan.length) {
          out.push('');
          out.push('### Keterampilan');
          out.push('| Rentang Nilai | Kriteria |');
          out.push('| --- | --- |');
          sections.keterampilan.forEach(it => out.push(`| ${String(it.rentang)} | ${String(it.ket).replace(/\|/g,'\\|')} |`));
        }
        out.push('');

        const before = lines.slice(0, start);
        const after = lines.slice(end);
        return [...before, ...out, ...after].join('\n').replace(/\n{3,}/g, '\n\n');
      }

      const setRppTab = (tab) => {
        state.rppTab = tab;
        saveDebounced(false);
        render();
      };

      const renderRPP = () => {
        const R = (state.rpp && typeof state.rpp === 'object') ? state.rpp : {};
        const isKesetaraan = String(R.jenjang || '').trim() === 'Kesetaraan';
        const jenjangDisplay = displayJenjang(R.jenjang, R.kesetaraanPaket);
        const jenjangEfektif = resolveJenjang(R.jenjang, R.kesetaraanPaket);
        const faseOpts = (MA_FASE_MAP[jenjangEfektif] || []).map(v => ({ v, l: v }));
        const kelasOpts = (CLASS_OPTIONS[jenjangEfektif] || []).map(v => ({ v, l: v }));
        const hasilAda = !!R.hasil;
        const tab = state.rppTab || (hasilAda ? 'rpp' : 'informasi');
        const rppInsertPageBreakMarkers = (text) => {
          let s = String(text || '');
          const marker = '[[PAGE_BREAK]]';
          const addBeforeLine = (reWithGroups) => {
            s = s.replace(reWithGroups, (_m, p1, p2) => `${p1}${marker}\n${p2}`);
          };
          addBeforeLine(/(^|\n)([^\S\r\n]*(?:#{1,4}\s*)?(?:\*{0,2})(?:A\.\s*)?Identitas\b[^\n]*)/im);
          addBeforeLine(/(^|\n)([^\S\r\n]*(?:#{1,4}\s*)?(?:\*{0,2})Kegiatan\s+Pembelajaran\b[^\n]*)/im);
          addBeforeLine(/(^|\n)([^\S\r\n]*(?:#{1,4}\s*)?(?:\*{0,2})Asesmen\b[^\n]*)/im);
          addBeforeLine(/(^|\n)([^\S\r\n]*(?:#{1,4}\s*)?(?:\*{0,2})Penilaian\b[^\n]*)/im);
          addBeforeLine(/(^|\n)([^\S\r\n]*(?:#{1,4}\s*)?(?:\*{0,2})Lampiran\b[^\n]*)/im);
          s = s.replace(new RegExp(`${marker}\\s*\\n\\s*${marker}`, 'g'), marker);
          return s;
        };
        const rppBuildPreviewHtmlWysiwyg = (R) => {
          const mapel = String(R.mata_pelajaran || '').trim();
          const materi = String(R.materi || '').trim();
          const kelas = String(R.kelas || '').trim();
          const fase = String(R.fase || '').trim();
          const jenjang = displayJenjang(R.jenjang, R.kesetaraanPaket);
          const kurikulum = String(R.kurikulum || '').trim();
          const pendekatan = String(R.pendekatan || '').trim();
          const format = String(R.format || '').trim();
          const alokasi = String(R.alokasi_waktu || '').trim();
          const namaSekolah = String(R.nama_sekolah || '').trim();
          const namaGuru = String(R.nama_guru || '').trim();
          const title = 'RPP (Rencana Pelaksanaan Pembelajaran)';
          const sub = [mapel, kelas, materi].filter(Boolean).join(' · ');
          const rows = [
            ['Jenjang', jenjang || '-'],
            ['Fase', fase || '-'],
            ['Kelas', kelas || '-'],
            ['Mata Pelajaran', mapel || '-'],
            ['Materi / Topik', materi || '-'],
            ['Kurikulum', kurikulum || '-'],
            ['Pendekatan', pendekatan || '-'],
            ['Format', format || '-'],
            ['Alokasi Waktu', alokasi || '-'],
            ['Nama Sekolah', namaSekolah || '-'],
            ['Nama Guru', namaGuru || '-'],
          ];
          const table = `
            <table class="ma-tbl">
              <thead><tr><th>Komponen</th><th>Keterangan</th></tr></thead>
              <tbody>${rows.map(([k,v])=>`<tr><td>${safeText(k)}</td><td>${safeText(v)}</td></tr>`).join('')}</tbody>
            </table>
          `;
          const titleHtml = `
            <div class="text-center">
              <div class="font-bold text-[20px] tracking-wide">${safeText(title)}</div>
              ${sub ? `<div class="italic text-[16px] mt-2">${safeText(sub)}</div>` : ``}
            </div>
            <div class="mt-8">${table}</div>
          `;
          let bodyMd = stripRppDuplicateInfo(String(R.hasil || ''));
          bodyMd = bodyMd.replace(/^\s*#{1,3}\s*RPP\b[^\n]*\n?/i, '');
          bodyMd = bodyMd.trim();
          bodyMd = rppNormalizeRubrikToTables(bodyMd);
          bodyMd = rppInsertPageBreakMarkers(bodyMd);
          const parts = String(bodyMd || '').split('[[PAGE_BREAK]]').map(s => s.trim()).filter(Boolean);
          const pages = parts.length ? parts : [''];
          const wrapTables = (html) => {
            let s = String(html || '');
            s = s.replace(/<table/gi, '<div class="ma-table-wrap"><table');
            s = s.replace(/<\/table>/gi, '</table></div>');
            return s;
          };
          const pageWrap = (inner) => `
            <div class="rpp-page bg-white text-black p-4 md:p-10 md:shadow-paper md:min-h-[297mm] font-serif border border-gray-200 mx-auto print:border-none print:shadow-none print:p-0 w-full">
              ${inner}
            </div>
          `;
          const pageHtml = pages.map((md, idx) => {
            const html = wrapTables(maMarkdownToHtml(md));
            const content = idx === 0 ? `${titleHtml}<div class="mt-10">${html}</div>` : html;
            return pageWrap(content);
          }).join('');
          return `<div class="space-y-6">${pageHtml}</div>`;
        };
        const mkSel = (lbl, key, val, opts) => `
          <div class="flex flex-col gap-2">
            <label class="text-sm font-semibold text-text-sub-light dark:text-text-sub-dark">${safeText(lbl)}</label>
            <select class="w-full rounded-lg border-border-light dark:border-border-dark bg-background-light dark:bg-background-dark/40 focus:border-primary focus:ring-primary h-11 px-4 text-sm"
              data-rpp-key="${safeText(key)}"
              onchange="window.__sp.setRPP('${key}',this.value,true)">
              <option value="">— Pilih —</option>
              ${opts.map(o=>`<option value="${safeText(o.v)}" ${String(o.v)===String(val||'')?'selected':''}>${safeText(o.l)}</option>`).join('')}
            </select>
          </div>`;
        const mkInp = (lbl, key, val, ph='') => `
          <div class="flex flex-col gap-2">
            <label class="text-sm font-semibold text-text-sub-light dark:text-text-sub-dark">${safeText(lbl)}</label>
            <input class="w-full rounded-lg border-border-light dark:border-border-dark bg-background-light dark:bg-background-dark/40 focus:border-primary focus:ring-primary h-11 px-4 text-sm"
              placeholder="${safeText(ph)}" value="${safeText(val||'')}"
              oninput="window.__sp.setRPP('${key}',this.value,false)"
              onchange="window.__sp.setRPP('${key}',this.value,true)"
              onblur="window.__sp.setRPP('${key}',this.value,true)" />
          </div>`;
        const mkTxt = (lbl, key, val, ph='') => `
          <div class="flex flex-col gap-2">
            <label class="text-sm font-semibold text-text-sub-light dark:text-text-sub-dark">${safeText(lbl)}</label>
            <textarea class="w-full rounded-lg border-border-light dark:border-border-dark bg-background-light dark:bg-background-dark/40 focus:border-primary focus:ring-primary px-4 py-3 text-sm min-h-[100px]"
              placeholder="${safeText(ph)}"
              oninput="window.__sp.setRPP('${key}',this.value,false)"
              onchange="window.__sp.setRPP('${key}',this.value,true)"
              onblur="window.__sp.setRPP('${key}',this.value,true)">${safeText(val||'')}</textarea>
          </div>`;

        const pendekatanOpts = [
          { v: 'Standar', l: 'Standar' },
          { v: 'CTL (Contextual Teaching and Learning)', l: 'CTL (Contextual Teaching and Learning)' },
          { v: 'Deep Learning', l: 'Deep Learning' },
          { v: 'Deep Learning + CTL', l: 'Deep Learning + CTL' },
          { v: 'Berbasis Cinta (KBC)', l: 'Berbasis Cinta (KBC)' },
          { v: 'Deep Learning + KBC', l: 'Deep Learning + KBC' },
        ];
        const kurikulumOpts = [
          { v: 'Merdeka', l: 'Kurikulum Merdeka' },
          { v: 'K13', l: 'Kurikulum 2013 (K13)' },
        ];
        const formatOpts = [
          { v: '1 lembar', l: '1 Lembar (Ringkas)' },
          { v: 'panjang', l: 'Panjang (Lengkap + Rubrik)' },
        ];
        const jenjangOpts = [
          { v: 'PAUD', l: 'PAUD' },
          { v: 'TK', l: 'TK' },
          { v: 'SD/MI', l: 'SD/MI' },
          { v: 'SMP/MTs', l: 'SMP/MTs' },
          { v: 'SMA/MA', l: 'SMA/MA' },
          { v: 'SMK/MAK', l: 'SMK/MAK' },
          { v: 'Kesetaraan', l: 'Kesetaraan' },
        ];

        const helpOnClick = tab === 'informasi'
          ? "window.__sp.openRPPHelp1()"
          : "window.__sp.openRPPHelp2()";

        const desktopTabs = `
          <div class="hidden md:flex items-center justify-between gap-3">
            <div class="inline-flex rounded-lg border bg-white dark:bg-surface-dark overflow-x-auto no-scrollbar">
              ${[
                { id: 'informasi', label: '1. Informasi Dasar' },
                { id: 'rpp', label: '2. RPP' },
              ].map(t=>{
                const active = tab === t.id;
                return `<button class="${active?'bg-primary text-white':'bg-white dark:bg-surface-dark'} px-4 h-10 rounded-lg text-sm font-bold whitespace-nowrap" onclick="window.__sp.setRppTab('${t.id}')">${t.label}</button>`;
              }).join('')}
            </div>
            <div class="flex items-center gap-2">
              <button class="inline-flex items-center justify-center h-10 px-4 rounded-lg border bg-white dark:bg-surface-dark hover:bg-background-light dark:hover:bg-background-dark text-sm font-bold"
                onclick="saveProject()" title="Simpan">
                <span class="material-symbols-outlined text-[18px]">save</span>
                <span class="ml-2 hidden lg:inline">Simpan</span>
              </button>
              <button class="inline-flex items-center justify-center h-10 px-4 rounded-lg border bg-white dark:bg-surface-dark hover:bg-background-light dark:hover:bg-background-dark text-sm font-bold"
                onclick="document.getElementById('projectPicker').value=''; document.getElementById('projectPicker').click();" title="Muat">
                <span class="material-symbols-outlined text-[18px]">folder_open</span>
                <span class="ml-2 hidden lg:inline">Muat</span>
              </button>
              <button class="inline-flex items-center justify-center h-10 px-4 rounded-lg border bg-white dark:bg-surface-dark hover:bg-background-light dark:hover:bg-background-dark text-sm font-bold"
                onclick="${helpOnClick}" title="Petunjuk">
                <span class="material-symbols-outlined text-[18px]">help</span>
                <span class="ml-2">Petunjuk</span>
              </button>
            </div>
          </div>
        `;

        const mobileNav = (cur) => {
          const prev = cur === 'rpp' ? 'informasi' : null;
          const next = cur === 'informasi' ? 'rpp' : null;
          const rightLabel = cur === 'informasi' ? 'RPP' : '';
          return `
            <div class="md:hidden mt-6 flex items-center gap-3">
              <button class="flex-1 h-12 rounded-xl border bg-white dark:bg-surface-dark font-bold" ${prev ? `onclick="window.__sp.setRppTab('${prev}')"` : 'disabled'}>Kembali</button>
              <button class="flex-1 h-12 rounded-xl ${next ? 'bg-primary text-white' : 'bg-gray-200 text-gray-500'} font-bold" ${next ? `onclick="window.__sp.setRppTab('${next}')"` : 'disabled'}>${rightLabel || 'Lanjut'}</button>
            </div>
          `;
        };

        const step1Html = `
          <div class="bg-surface-light dark:bg-surface-dark rounded-xl border border-border-light dark:border-border-dark shadow-sm">
            <div class="p-6 space-y-5">
              <div class="flex items-center justify-between gap-3">
                <div>
                  <div class="text-xl font-bold">Informasi Dasar</div>
                  <div class="text-sm text-text-sub-light dark:text-text-sub-dark mt-1">Lengkapi data untuk menyusun RPP</div>
                </div>
                <button class="hidden md:flex items-center gap-2 rounded-lg h-10 px-4 bg-primary hover:bg-blue-600 text-primary-content text-sm font-bold shadow-sm transition-colors"
                  onclick="window.__sp.setRppTab('rpp')">
                  <span class="material-symbols-outlined text-[18px]">arrow_forward</span>
                  RPP
                </button>
              </div>
              <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <div class="flex flex-col gap-2">
                  <label class="text-sm font-semibold text-text-sub-light dark:text-text-sub-dark">Jenjang</label>
                  <select class="w-full rounded-lg border-border-light dark:border-border-dark bg-background-light dark:bg-background-dark/40 focus:border-primary focus:ring-primary h-11 px-4 text-sm"
                    data-rpp-key="jenjang"
                    onchange="window.__sp.setRPP('jenjang',this.value,true)">
                    <option value="">— Pilih —</option>
                    ${jenjangOpts.map(o=>`<option value="${safeText(o.v)}" ${String(o.v)===String(R.jenjang||'')?'selected':''}>${safeText(o.l)}</option>`).join('')}
                  </select>
                  <div class="${isKesetaraan ? "" : "hidden"} flex flex-col gap-2 mt-2">
                    <label class="text-sm font-semibold text-text-sub-light dark:text-text-sub-dark">Paket</label>
                    <select class="w-full rounded-lg border-border-light dark:border-border-dark bg-background-light dark:bg-background-dark/40 focus:border-primary focus:ring-primary h-11 px-4 text-sm"
                      data-rpp-key="kesetaraanPaket"
                      onchange="window.__sp.setRPP('kesetaraanPaket',this.value,true)">
                      <option value="">— Pilih Paket —</option>
                      ${KES_PAKET_OPTIONS.map(o=>`<option value="${safeText(o)}" ${String(o)===String(R.kesetaraanPaket||'')?'selected':''}>${safeText(o)}</option>`).join('')}
                    </select>
                  </div>
                </div>
                ${mkSel('Fase','fase',R.fase,faseOpts)}
              </div>
              <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                ${mkSel('Kelas','kelas',R.kelas,kelasOpts)}
                <div></div>
              </div>
              <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                ${mkSel('Mata Pelajaran','mata_pelajaran',R.mata_pelajaran,(() => {
                  const je = resolveJenjang(R.jenjang, R.kesetaraanPaket);
                  const list = SUBJECT_OPTIONS[je] || [];
                  const out = list.map(v => ({ v, l: v }));
                  const cur = String(R.mata_pelajaran || '').trim();
                  if (cur && !list.includes(cur)) out.unshift({ v: cur, l: cur });
                  return out;
                })())}
                ${mkInp('Materi / Topik','materi',R.materi,'Contoh: Listrik Statis')}
              </div>
              <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                ${mkSel('Kurikulum','kurikulum',R.kurikulum,kurikulumOpts)}
                ${mkSel('Pendekatan','pendekatan',R.pendekatan,pendekatanOpts)}
                ${mkSel('Format','format',R.format,formatOpts)}
              </div>
              <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                ${mkInp('Alokasi Waktu','alokasi_waktu',R.alokasi_waktu,'Contoh: 2 x 40 menit')}
                ${mkInp('Nama Sekolah (opsional)','nama_sekolah',R.nama_sekolah,'Contoh: SMPN 1')}
                ${mkInp('Nama Guru (opsional)','nama_guru',R.nama_guru,'Contoh: Siti, S.Pd.')}
              </div>
              <details class="rounded-lg border border-border-light dark:border-border-dark bg-white dark:bg-surface-dark p-4">
                <summary class="cursor-pointer font-semibold text-sm">Lanjutan (opsional)</summary>
                <div class="mt-4">
                  ${mkTxt('CP / TP / KD (opsional)','cp_tp_kd',R.cp_tp_kd||'','Tempel CP/TP (Merdeka) atau KD (K13) jika ada')}
                </div>
              </details>
              <div id="rppError" class="hidden rounded-lg bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 p-4 text-sm text-red-700 dark:text-red-300"></div>
            </div>
          </div>
        `;

        const downloadBtnClass = hasilAda
          ? 'bg-green-600 hover:bg-green-700 text-white'
          : 'bg-gray-200 text-gray-500 cursor-not-allowed';

        const step2Html = `
          <div class="space-y-6">
            <div class="bg-surface-light dark:bg-surface-dark rounded-xl border border-border-light dark:border-border-dark shadow-sm">
              <div class="p-6 flex items-start justify-between gap-4">
                <div>
                  <div class="text-xl font-bold">RPP</div>
                  <div class="text-sm text-text-sub-light dark:text-text-sub-dark mt-1">Preview dan unduh dokumen RPP</div>
                </div>
                <div class="flex flex-wrap items-center justify-end gap-2">
                  <button type="button" data-action="build-rpp"
                    class="flex items-center gap-2 rounded-lg h-10 px-6 bg-primary hover:bg-blue-600 text-primary-content text-sm font-bold shadow-sm transition-colors">
                    <span class="material-symbols-outlined text-[18px]">auto_awesome</span>
                    Buat RPP Sekarang
                  </button>
                  <button type="button" id="btnExportRPPTop" data-action="export-rpp-docx"
                    class="flex items-center gap-2 rounded-lg h-10 px-5 ${downloadBtnClass} text-sm font-bold shadow-sm transition-colors"
                    ${hasilAda ? '' : 'disabled'}>
                    <span class="material-symbols-outlined text-[18px]">download</span>
                    Download .docx
                  </button>
                  <button type="button" id="btnExportRPPPdfTop" data-action="export-rpp-pdf"
                    class="flex items-center gap-2 rounded-lg h-10 px-5 ${downloadBtnClass} text-sm font-bold shadow-sm transition-colors"
                    ${hasilAda ? '' : 'disabled'}>
                    <span class="material-symbols-outlined text-[18px]">picture_as_pdf</span>
                    Download PDF
                  </button>
                </div>
              </div>
              <div id="rppErrorTop" class="hidden mx-6 mb-6 rounded-lg bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 p-4 text-sm text-red-700 dark:text-red-300"></div>
            </div>
            ${hasilAda ? `
              <div class="overflow-auto max-h-[72vh] custom-scrollbar">
                <div id="rppPreview" class="
                  [&_.ma-table-wrap]:overflow-x-auto [&_.ma-table-wrap]:-mx-1 [&_.ma-table-wrap]:px-1 [&_.ma-table-wrap]:my-3
                  [&_table]:w-full [&_table]:border-collapse [&_table]:text-[14px]
                  [&_td]:border [&_td]:border-gray-300 [&_td]:px-3 [&_td]:py-2 [&_td]:align-top
                  [&_th]:border [&_th]:border-gray-300 [&_th]:px-3 [&_th]:py-2 [&_th]:bg-gray-100 [&_th]:font-bold
                  [&_.ma-tbl>tbody>tr:nth-child(even)>td]:bg-gray-50
                  [&_ul]:pl-6 [&_ul]:my-2 [&_li]:mb-1.5
                  [&_ol]:pl-6 [&_ol]:my-2 [&_ol]:list-decimal [&_ol>li]:mb-1.5
                  [&_em]:italic [&_strong]:font-bold
                  [&_p]:mb-3 [&_p]:text-justify">
                  ${rppBuildPreviewHtmlWysiwyg(R)}
                </div>
              </div>
            ` : `
              <div class="rounded-lg border border-border-light dark:border-border-dark bg-white dark:bg-surface-dark p-6 text-sm text-text-sub-light dark:text-text-sub-dark">
                Hasil belum ada. Klik Buat RPP Sekarang untuk membuat dokumen.
              </div>
            `}
          </div>
        `;

        if (R.isGenerating) return `
          <div class="flex flex-col items-center justify-center p-10 md:p-20 gap-4 max-w-2xl mx-auto">
            <div class="size-12 rounded-full bg-primary/10 text-primary flex items-center justify-center">
              <span class="material-symbols-outlined animate-spin">progress_activity</span>
            </div>
            <div class="text-center">
              <div class="font-bold text-lg">Menyusun RPP...</div>
              <div class="text-sm text-text-sub-light mt-1">AI sedang membuat RPP, tunggu beberapa detik</div>
            </div>
            <div class="rounded-lg border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/20 max-w-md w-full">
              <div class="flex items-start gap-3 p-4">
                <span class="material-symbols-outlined text-amber-500 mt-0.5">warning</span>
                <div class="text-sm text-amber-700 dark:text-amber-200">Jangan tutup halaman ini. Pastikan layar tidak mati.</div>
              </div>
            </div>
          </div>`;

        const body = tab === 'rpp' ? (step2Html + mobileNav('rpp')) : (step1Html + mobileNav('informasi'));

        const rppHelpModals = `
          <div id="modalRPPHelp1" class="fixed inset-0 hidden items-center justify-center" style="display:none; background: rgba(0,0,0,0.5); z-index:50;">
            <div class="bg-white dark:bg-surface-dark rounded-xl border border-border-light dark:border-border-dark shadow-xl w-[92vw] max-w-[760px] max-h-[85vh] overflow-auto">
              <div class="p-5 border-b border-border-light dark:border-border-dark flex items-center justify-between">
                <div class="font-bold text-lg flex items-center gap-2"><span class="material-symbols-outlined">help</span> Petunjuk RPP • Tab 1</div>
                <button class="size-9 rounded-lg border bg-white dark:bg-surface-dark" onclick="window.__sp.closeRPPHelp1()">&times;</button>
              </div>
              <div class="p-5 space-y-3 text-sm leading-relaxed">
                <ol class="list-decimal pl-5 space-y-2">
                  <li>Lengkapi Jenjang, Kelas, Mata Pelajaran, dan Materi/Topik.</li>
                  <li>Pilih Kurikulum, Pendekatan, dan Format RPP.</li>
                  <li>Isi Alokasi Waktu. Nama Sekolah dan Nama Guru bersifat opsional.</li>
                  <li>Jika punya CP/TP/KD, tempel di bagian Lanjutan (opsional) agar RPP lebih presisi.</li>
                </ol>
              </div>
            </div>
          </div>
          <div id="modalRPPHelp2" class="fixed inset-0 hidden items-center justify-center" style="display:none; background: rgba(0,0,0,0.5); z-index:50;">
            <div class="bg-white dark:bg-surface-dark rounded-xl border border-border-light dark:border-border-dark shadow-xl w-[92vw] max-w-[760px] max-h-[85vh] overflow-auto">
              <div class="p-5 border-b border-border-light dark:border-border-dark flex items-center justify-between">
                <div class="font-bold text-lg flex items-center gap-2"><span class="material-symbols-outlined">help</span> Petunjuk RPP • Tab 2</div>
                <button class="size-9 rounded-lg border bg-white dark:bg-surface-dark" onclick="window.__sp.closeRPPHelp2()">&times;</button>
              </div>
              <div class="p-5 space-y-3 text-sm leading-relaxed">
                <ol class="list-decimal pl-5 space-y-2">
                  <li>Klik Buat RPP Sekarang untuk membuat dokumen.</li>
                  <li>Jika Anda memilih Deep Learning, pastikan RPP memuat Eksplorasi–Analisis–Refleksi dan tag [Mindful]/[Meaningful]/[Joyful].</li>
                  <li>Gunakan Download .docx untuk Word dan Download PDF untuk versi PDF.</li>
                  <li>Jika proses lama, tunggu sampai selesai dan jangan tutup halaman.</li>
                </ol>
              </div>
            </div>
          </div>
        `;

        return `<div class="space-y-6">${desktopTabs}${body}${rppHelpModals}</div>`;
      };

      function setRPP(key, value, persist) {
        state.rpp = state.rpp || {};
        state.rpp[key] = value;
        if (key === 'jenjang' || key === 'kesetaraanPaket' || key === 'fase') {
          state.rpp.mapel_cp046_slug = '';
        }
        if (key === 'jenjang' || key === 'kesetaraanPaket') {
          const R = state.rpp || {};
          const je = resolveJenjang(R.jenjang, R.kesetaraanPaket);
          const faseOpts = MA_FASE_MAP[je] || [];
          const kelasOpts = CLASS_OPTIONS[je] || [];
          if (faseOpts.length === 1) R.fase = faseOpts[0];
          else if (R.fase && !faseOpts.includes(R.fase)) {
            const fl = faseLetterFromLabel(R.fase);
            const opt = fl ? faseOpts.find(x => new RegExp(`\\bFase\\s+${fl}\\b`, 'i').test(String(x))) : null;
            R.fase = opt || '';
          }
          if (kelasOpts.length === 1) R.kelas = kelasOpts[0];
          else if (R.kelas && !kelasOpts.includes(R.kelas)) R.kelas = '';
        }
        if (persist) {
          saveDebounced(true);
          render();
        } else {
          saveDebounced(false);
        }
      }

      async function buildRPP() {
        if (!state.rpp || typeof state.rpp !== 'object') state.rpp = {};
        const R = state.rpp;
        const errEl = () => document.getElementById('rppError') || document.getElementById('rppErrorTop');
        const showErr = (msg) => { const e=errEl(); if(e){e.textContent='⚠️ '+msg; e.classList.remove('hidden');} };
        const hideErr = () => { const e=errEl(); if(e){e.textContent=''; e.classList.add('hidden');} };

        const getRppDomValue = (key) => {
          const el = document.querySelector(`[data-rpp-key="${String(key || '')}"]`);
          return el ? String(el.value || '') : '';
        };
        const jenjang = String(R.jenjang || getRppDomValue('jenjang') || '').trim();
        const kesPaket = String(R.kesetaraanPaket || getRppDomValue('kesetaraanPaket') || '').trim();
        const jenjangEfektif = resolveJenjang(jenjang, kesPaket);
        const fase = String(R.fase || getRppDomValue('fase') || '').trim();
        const kelas = String(R.kelas || getRppDomValue('kelas') || '').trim();
        const mapel = String(R.mata_pelajaran || '').trim();
        const materi = String(R.materi || '').trim();
        const kurikulum = String(R.kurikulum || '').trim();
        const pendekatan = String(R.pendekatan || '').trim();
        const format = String(R.format || '').trim();
        const alokasi = String(R.alokasi_waktu || '').trim();
        if (!String(R.jenjang || '').trim() && jenjang) {
          state.rpp = state.rpp || {};
          state.rpp.jenjang = jenjang;
          saveDebounced(false);
        }
        if (!String(R.kesetaraanPaket || '').trim() && kesPaket) {
          state.rpp = state.rpp || {};
          state.rpp.kesetaraanPaket = kesPaket;
          saveDebounced(false);
        }
        if (!String(R.fase || '').trim() && fase) {
          state.rpp = state.rpp || {};
          state.rpp.fase = fase;
          saveDebounced(false);
        }
        const missing = [];
        if (!jenjang) missing.push('Jenjang');
        if (jenjang === 'Kesetaraan' && !kesPaket) missing.push('Paket Kesetaraan');
        if (!fase) missing.push('Fase');
        if (!kelas) missing.push('Kelas');
        if (!mapel) missing.push('Mata Pelajaran');
        if (!materi) missing.push('Materi / Topik');
        if (!kurikulum) missing.push('Kurikulum');
        if (!pendekatan) missing.push('Pendekatan');
        if (!format) missing.push('Format');
        if (!alokasi) missing.push('Alokasi Waktu');
        if (missing.length) {
          showErr(`Harap lengkapi field wajib: ${missing.join(', ')}.`);
          return;
        }
        hideErr();

        state.rpp.isGenerating = true;
        state.rpp.hasil = '';
        state.rppTab = 'rpp';
        render();

        const sys = `Kamu adalah asisten profesional pembuat RPP (Rencana Pelaksanaan Pembelajaran) untuk guru di Indonesia. Buat RPP sesuai pilihan kurikulum dan pendekatan. Output harus formal, rapi, dan siap digunakan untuk supervisi sekolah. Gunakan Bahasa Indonesia baku, jelas, dan profesional. Tujuan pembelajaran berorientasi HOTS (utamakan C4–C6) dan kontekstual. Jangan generik. Hindari output yang terkesan AI. Output HARUS langsung berupa RPP tanpa penjelasan tambahan. Jangan mengulang bagian identitas/metadata (Sekolah, Kelas/Semester, Mata Pelajaran, Materi, Alokasi Waktu, Nama Guru) karena identitas sudah ditampilkan terpisah.`;

        const extra = [];
        const cp = String(R.cp_tp_kd || '').trim();
        if (cp) extra.push(`CP/TP/KD (opsional, jadikan rujukan):\n${cp}`);
        const kurLabel = kurikulum === 'K13' ? 'Kurikulum 2013 (K13)' : 'Kurikulum Merdeka';
        const formatInstr = format === 'panjang'
          ? 'Format panjang (detail lengkap + rubrik penilaian).'
          : 'Format 1 lembar (ringkas, padat, siap supervisi).';
        const pendekatanLabel = String(pendekatan || '').trim() || 'Standar';
        const isK13 = kurikulum === 'K13';
        const isDL = /deep\s*learning/i.test(pendekatanLabel);
        const isKBC = /\bKBC\b/i.test(pendekatanLabel) || /berbasis\s+cinta/i.test(pendekatanLabel);
        const isDandK = isDL && isKBC;
        const isCTL = /\bCTL\b/i.test(pendekatanLabel) || /contextual\s+teaching\s+and\s+learning/i.test(pendekatanLabel);
        const isDLCTL = isDL && isCTL && !isKBC;

        const pendekatanInstr = isDandK
          ? 'Pendekatan Deep Learning + KBC.'
          : isDLCTL
            ? 'Pendekatan Deep Learning + CTL.'
          : isDL
            ? 'Pendekatan Deep Learning.'
            : isKBC
              ? 'Pendekatan Berbasis Cinta (KBC).'
              : isCTL
                ? 'Pendekatan CTL (Contextual Teaching and Learning).'
                : 'Pendekatan Standar.';

        const approachRules = isDandK
          ? `ATURAN PENDEKATAN (DEEP LEARNING + KBC) — WAJIB DIPATUHI:
- Di bagian Kegiatan Pembelajaran, wajib ada urutan eksplisit: Eksplorasi → Analisis → Refleksi.
- Di setiap pertemuan wajib ada elemen Mindful, Meaningful, Joyful yang ditulis eksplisit (gunakan tag: [Mindful], [Meaningful], [Joyful]).
- Wajib ada integrasi KBC yang eksplisit dalam kegiatan, asesmen, dan refleksi (jangan menggurui).
- Wajib ada subbagian "Unsur KBC" dan "Implementasi KBC" (ringkas, konkret).`
          : isDLCTL
            ? `ATURAN PENDEKATAN (DEEP LEARNING + CTL) — WAJIB DIPATUHI:
- Wajib ada konteks nyata/masalah pemantik di awal, dan konteks itu dipakai konsisten sampai akhir.
- Di bagian Kegiatan Pembelajaran, wajib ada urutan eksplisit: Eksplorasi → Analisis → Refleksi.
- Di setiap pertemuan wajib ada elemen Mindful, Meaningful, Joyful yang ditulis eksplisit (gunakan tag: [Mindful], [Meaningful], [Joyful]).
- Wajib memuat komponen CTL: Konstruktivisme, Inkuiri, Questioning, Learning Community, Modeling, Refleksi, Penilaian Autentik.
- Kegiatan Inti berorientasi inkuiri: mengamati konteks → merumuskan pertanyaan → mengumpulkan data/percobaan sederhana → menganalisis → menyimpulkan → mengomunikasikan.
- Asesmen harus autentik (proses + produk/kinerja) dan rubrik dalam tabel (skala 1–4) yang siap dipakai guru.
- Refleksi peserta didik harus mengaitkan konsep dengan penerapan di konteks nyata (mengapa/bagaimana).
- Di Kegiatan Pembelajaran, beri tag komponen CTL pada aktivitas (gunakan tag persis): [Konstruktivisme], [Inkuiri], [Questioning], [Learning Community], [Modeling], [Refleksi], [Penilaian Autentik]. Pastikan ketujuh komponen muncul.
- Dilarang menambahkan KBC sama sekali (jangan tulis "KBC", "Berbasis Cinta", "Unsur KBC", "Implementasi KBC").`
          : isDL
            ? `ATURAN PENDEKATAN (DEEP LEARNING) — WAJIB DIPATUHI:
- Di bagian Kegiatan Pembelajaran, wajib ada urutan eksplisit: Eksplorasi → Analisis → Refleksi.
- Di setiap pertemuan wajib ada elemen Mindful, Meaningful, Joyful yang ditulis eksplisit (gunakan tag: [Mindful], [Meaningful], [Joyful]).
- Dilarang menambahkan KBC sama sekali (jangan tulis "KBC", "Berbasis Cinta", "Unsur KBC", "Implementasi KBC").`
            : isKBC
              ? `ATURAN PENDEKATAN (KBC) — WAJIB DIPATUHI:
- Wajib ada subbagian "Unsur KBC" dan "Implementasi KBC" (ringkas, konkret, kontekstual).
- Di Kegiatan Pembelajaran wajib ada aktivitas KBC yang eksplisit (mis. etika komunikasi, empati, gotong royong, kepedulian lingkungan, refleksi syukur/niat belajar).
- Rubrik/asesmen memuat indikator sikap/kolaborasi yang dapat diamati (tidak menggurui).`
              : isCTL
                ? `ATURAN PENDEKATAN (CTL / CONTEXTUAL TEACHING AND LEARNING) — WAJIB DIPATUHI:
- Wajib ada konteks nyata/masalah pemantik di awal, dan konteks itu dipakai konsisten sampai akhir.
- Wajib memuat komponen CTL: Konstruktivisme, Inkuiri, Questioning, Learning Community, Modeling, Refleksi, Penilaian Autentik.
- Kegiatan Inti berorientasi inkuiri: mengamati konteks → merumuskan pertanyaan → mengumpulkan data/percobaan sederhana → menganalisis → menyimpulkan → mengomunikasikan.
- Asesmen harus autentik (proses + produk/kinerja) dan rubrik dalam tabel (skala 1–4) yang siap dipakai guru.
- Refleksi peserta didik harus mengaitkan konsep dengan penerapan di konteks nyata (mengapa/bagaimana).`
                + `\n- Di Kegiatan Pembelajaran, beri tag komponen CTL pada aktivitas (gunakan tag persis): [Konstruktivisme], [Inkuiri], [Questioning], [Learning Community], [Modeling], [Refleksi], [Penilaian Autentik]. Pastikan ketujuh komponen muncul.`
                : `ATURAN PENDEKATAN (STANDAR) — WAJIB DIPATUHI:
- Kegiatan pembelajaran instruksional, terstruktur, dan terukur (contoh → latihan terbimbing → latihan mandiri).
- Dilarang menambahkan KBC sama sekali (jangan tulis "KBC", "Berbasis Cinta", "Unsur KBC", "Implementasi KBC").`;

        const sintaks = kurikulum === 'K13'
          ? 'Gunakan sintaks: Apersepsi, Eksplorasi, Elaborasi, Konfirmasi, Refleksi.'
          : 'Gunakan struktur: Tujuan Pembelajaran, Materi, Kegiatan (Pendahuluan–Inti–Penutup), Asesmen (diagnostik/formatif/sumatif), Penilaian (sikap/pengetahuan/keterampilan).';

        const k13Rules = isK13
          ? `ATURAN K13 — WAJIB:
- Gunakan pendekatan saintifik 5M di Kegiatan Inti: Mengamati, Menanya, Mencoba, Menalar, Mengomunikasikan (tulis eksplisit).
- Kaitkan KI/KD/Indikator bila tersedia dari CP/TP/KD (opsional) yang ditempel.`
          : ``;

        if (!isK13) {
          try {
            const respCp = await fetch("api/cp046_rpp_context.php", {
              method: "POST",
              headers: { "Content-Type": "application/json" },
              body: JSON.stringify({ jenjang: jenjangEfektif, fase, mapel, mapel_slug: String(R.mapel_cp046_slug || ""), materi, kurikulum: kurLabel }),
            });
            if (respCp.ok) {
              const ctx = await respCp.json();
              const block = String(ctx?.block || "").trim();
              if (ctx?.ok && block) extra.unshift(block);
            }
          } catch {}
        }

        const usr = `Buatkan RPP dengan detail berikut:\n\nJenjang: ${displayJenjang(jenjang, kesPaket)}\nFase: ${fase}\nKelas: ${kelas}\nMata Pelajaran: ${mapel}\nMateri: ${materi}\nKurikulum: ${kurLabel}\nPendekatan: ${pendekatanLabel}\nFormat: ${format}\nAlokasi Waktu: ${alokasi}\nNama Sekolah: ${String(R.nama_sekolah || '').trim()}\nNama Guru: ${String(R.nama_guru || '').trim()}\n\nKetentuan:\n- ${formatInstr}\n- ${pendekatanInstr}\n- ${sintaks}\n${k13Rules ? `- ${k13Rules.replace(/\n/g,'\n')}\n` : ''}- ${approachRules.replace(/\n/g,'\n')}\n- Sertakan penilaian autentik: sikap, pengetahuan, keterampilan.\n- Output harus rapi (gunakan heading dan tabel bila perlu).\n- Rubrik Penilaian WAJIB dalam bentuk tabel Markdown saja (bukan bullet):\n  - Sikap: tabel | Skor | Kriteria |\n  - Pengetahuan: tabel | Rentang Nilai | Kriteria |\n  - Keterampilan: tabel | Rentang Nilai | Kriteria |\n- PENTING: Jangan tulis ulang judul dan identitas/metadata seperti:\n  Sekolah:, Kelas/Semester:, Mata Pelajaran:, Materi:, Alokasi Waktu:, Nama Guru:.\n  Mulai langsung dari isi RPP (mis. Tujuan Pembelajaran / Komponen Inti / Kegiatan Pembelajaran).\n${extra.length ? '\n' + extra.join('\n\n') : ''}\n\nOutput HARUS langsung berupa RPP.`;

        try {
          const ctrl = new AbortController();
          const timer = setTimeout(()=>ctrl.abort(), 90000);
          const resp = await fetch("api/openai_proxy.php", {
            method: "POST",
            headers: {"Content-Type":"application/json"},
            credentials: "same-origin",
            body: JSON.stringify({ type:"rpp", messages:[{role:"system",content:sys},{role:"user",content:usr}], model:OPENAI_MODEL }),
            signal: ctrl.signal,
          });
          clearTimeout(timer);
          if (!resp.ok) throw new Error(`Proxy ${resp.status}: ${await resp.text()}`);
          const data = await resp.json();
          const text = data?.content || data?.choices?.[0]?.message?.content || '';
          if (!text) throw new Error("Respons API kosong.");
          const stripKbcFromRpp = (raw) => {
            const src = String(raw || '').replace(/\r\n/g, '\n').replace(/\r/g, '\n');
            const lines = src.split('\n');
            const out = [];
            for (let i = 0; i < lines.length; i++) {
              const line = String(lines[i] || '');
              const t = line.trim();
              if (!t) { out.push(line); continue; }
              if (/\bKBC\b/i.test(t)) continue;
              if (/berbasis\s+cinta/i.test(t)) continue;
              if (/unsur\s+kbc/i.test(t)) continue;
              if (/implementasi\s+kbc/i.test(t)) continue;
              out.push(line);
            }
            return out.join('\n').replace(/\n{3,}/g, '\n\n').trim();
          };
          let finalText = isKBC ? text : stripKbcFromRpp(text);
          if (isDL) {
            const hasEAR = /eksplorasi/i.test(finalText) && /analisis/i.test(finalText) && /refleksi/i.test(finalText);
            const hasMMJ = /\[Mindful\]/i.test(finalText) && /\[Meaningful\]/i.test(finalText) && /\[Joyful\]/i.test(finalText);
            if (!hasEAR || !hasMMJ) {
              try {
                const reviseSys = `Anda adalah editor RPP. Perbaiki RPP agar patuh ketat dengan aturan pendekatan. Jangan menambah identitas/metadata. Jangan memberi penjelasan—output langsung RPP yang sudah diperbaiki.`;
                const reviseUsr = `Tolong revisi RPP berikut agar memenuhi aturan ini:\n${approachRules}\n${k13Rules ? `\n${k13Rules}\n` : ''}\n\nRPP (baseline):\n<<<\n${finalText}\n>>>`;
                const resp2 = await fetch("api/openai_proxy.php", {
                  method: "POST",
                  headers: {"Content-Type":"application/json"},
                  credentials: "same-origin",
                  body: JSON.stringify({ type:"rpp", messages:[{role:"system",content:reviseSys},{role:"user",content:reviseUsr}], model:OPENAI_MODEL }),
                  signal: ctrl.signal,
                });
                if (resp2.ok) {
                  const data2 = await resp2.json();
                  const text2 = data2?.content || data2?.choices?.[0]?.message?.content || '';
                  if (text2) finalText = isKBC ? text2 : stripKbcFromRpp(text2);
                }
              } catch {}
            }
          }
          const ensureCtlInRpp = (raw) => {
            const src0 = String(raw || '').replace(/\r\n/g, '\n').replace(/\r/g, '\n');
            if (!isCTL) return src0;
            const hasCtl = /\bCTL\b/i.test(src0) || /konstruktivisme/i.test(src0) || /learning community/i.test(src0) || /\[Konstruktivisme\]/i.test(src0);
            if (hasCtl) return src0;
            const block = [
              '### Catatan Pendekatan CTL (Contextual Teaching and Learning)',
              '- Konteks nyata/masalah pemantik digunakan konsisten pada seluruh kegiatan.',
              '- Komponen CTL yang wajib tampak: Konstruktivisme, Inkuiri, Questioning, Learning Community, Modeling, Refleksi, Penilaian Autentik.',
              '- Di Kegiatan Pembelajaran, beri tag di akhir baris aktivitas (gunakan tag persis): [Konstruktivisme], [Inkuiri], [Questioning], [Learning Community], [Modeling], [Refleksi], [Penilaian Autentik].',
              '- Asesmen autentik menilai proses dan produk/kinerja; rubrik skala 1–4 dalam tabel.',
            ].join('\n');
            const lines = src0.split('\n');
            let idx = -1;
            for (let i = 0; i < lines.length; i++) {
              const t = String(lines[i] || '').trim();
              if (/^##\s*Kegiatan\b/i.test(t) || /^###\s*Kegiatan\b/i.test(t)) { idx = i; break; }
              if (/^###\s*Kegiatan\s+Pembelajaran\b/i.test(t)) { idx = i; break; }
            }
            if (idx >= 0) {
              lines.splice(idx, 0, block, '');
              return lines.join('\n').replace(/\n{3,}/g, '\n\n').trim();
            }
            return `${block}\n\n${src0.trim()}`.trim();
          };
          if (isCTL) finalText = ensureCtlInRpp(finalText);
          state.rpp.hasil = finalText;
          state.rpp.isGenerating = false;
          saveDebounced(true);
          render();
          try {
            const usageIn = Number(data?.usage?.prompt_tokens ?? data?.usage?.input_tokens ?? data?._usage?.in ?? 0);
            const usageOut = Number(data?.usage?.completion_tokens ?? data?.usage?.output_tokens ?? data?._usage?.out ?? 0);
            const title = `RPP - ${mapel} ${kelas} - ${materi}`.trim();
            const snapshot = { rpp: { ...R, hasil: finalText }, identity: { ...state.identity }, questions: [] };
            await fetch("api/soal_user.php", {
              method: "POST",
              headers: {"Content-Type":"application/json"},
              body: JSON.stringify({ type: "save", title, state: snapshot, token_input: usageIn, token_output: usageOut, model: OPENAI_MODEL })
            });
            await fetch("api/openai_proxy.php", {
              method: "POST",
              headers: {"Content-Type":"application/json"},
              credentials: "same-origin",
              body: JSON.stringify({ type: "add_tokens", input_tokens: usageIn, output_tokens: usageOut })
            });
            const rppCost = Number(state.limitConfig?.costs?.rpp ?? 2);
            const calls = [];
            for (let i=0;i<rppCost;i++) {
              calls.push(fetch("api/openai_proxy.php", {
                method: "POST",
                headers: {"Content-Type":"application/json"},
                credentials: "same-origin",
                body: JSON.stringify({ type: "decrement_package" })
              }));
            }
            await Promise.all(calls);
            try { await computeStats(); } catch {}
            logCreditUsage('RPP', rppCost, `${mapel||''} • ${materi||''}`);
          } catch {}
        } catch(e) {
          state.rpp.isGenerating = false;
          state.rppTab = 'rpp';
          render();
          setTimeout(()=>{ showErr('Gagal: '+(e?.message || 'Terjadi kesalahan.')); }, 120);
        }
      }

      async function exportRPPDocx() {
        const R = state.rpp || {};
        if (!R.hasil) return;
        const btn = document.getElementById('btnExportRPPTop') || document.getElementById('btnExportRPP') || document.getElementById('btnExportRPP2');
        const origHTML = btn?.innerHTML;
        if (btn) { btn.disabled=true; btn.textContent='Membuat file...'; }
        try {
          const { Document, Packer, Paragraph, TextRun, Table, TableRow, TableCell, AlignmentType, BorderStyle, WidthType, ShadingType } = docx;
          const FONT='Times New Roman', SZ=24, CW=9360;
          const bdr={style:BorderStyle.SINGLE,size:4,color:'999999'};
          const borders={top:bdr,bottom:bdr,left:bdr,right:bdr};
          const sp=(b=60,a=60)=>({spacing:{before:b,after:a}});

          function parseContent(raw) {
            const lines = String(raw || '').split('\n');
            const out = [];
            let tblRows=[], inTbl=false;

            const flushTbl = () => {
              if (!tblRows.length) return;
              const nc = Math.max(...tblRows.map(r=>r.length));
              const cw = Math.floor(CW/nc);
              out.push(new Table({
                width:{size:CW,type:WidthType.DXA},
                columnWidths:Array(nc).fill(cw),
                rows: tblRows.map((row,ri)=>new TableRow({
                  children: Array.from({length:nc},(_,ci)=>{
                    const txt=(row[ci]||'').trim().replace(/<[^>]+>/g,' ').replace(/&nbsp;/g,' ').replace(/\*\*(.+?)\*\*/g,'$1').replace(/\*(.+?)\*/g,'$1').replace(/\s+/g,' ').trim();
                    return new TableCell({
                      borders, width:{size:cw,type:WidthType.DXA},
                      shading: ri===0?{fill:'D9D9D9',type:ShadingType.CLEAR}:{fill:'FFFFFF',type:ShadingType.CLEAR},
                      margins:{top:80,bottom:80,left:100,right:100},
                      children:[new Paragraph({children:[new TextRun({text:txt,font:FONT,size:20,bold:ri===0})]})]
                    });
                  })
                }))
              }));
              tblRows=[]; inTbl=false;
            };

            for (let i=0;i<lines.length;i++) {
              const line=String(lines[i]||'').replace(/\r/g,'');
              if (line.match(/^\|[-|: ]+\|?$/)) continue;
              if (line.trim().startsWith('|')) {
                inTbl=true;
                tblRows.push(line.split('|').slice(1,-1).map(c=>c.trim()));
                continue;
              }
              if (inTbl) flushTbl();
              if (!line.trim()) { out.push(new Paragraph({...sp()})); continue; }
              if (/^####\s*/.test(line)) {
                out.push(new Paragraph({...sp(140,60),children:[new TextRun({text:line.replace(/^####\s*/,''),font:FONT,size:24,bold:true})]}));
              } else if (/^###\s*/.test(line)) {
                out.push(new Paragraph({...sp(180,80),children:[new TextRun({text:line.replace(/^###\s*/,''),font:FONT,size:26,bold:true})]}));
              } else if (/^##\s*/.test(line)) {
                out.push(new Paragraph({...sp(240,100),children:[new TextRun({text:line.replace(/^##\s*/,''),font:FONT,size:28,bold:true})]}));
              } else if (/^#\s*/.test(line)) {
                out.push(new Paragraph({...sp(280,120),children:[new TextRun({text:line.replace(/^#\s*/,''),font:FONT,size:32,bold:true})]}));
              } else if (line.match(/^[-•] /)) {
                out.push(new Paragraph({...sp(40,40),indent:{left:400,hanging:200},children:[new TextRun({text:'• '+line.replace(/^[-•] /,'').replace(/\*\*(.+?)\*\*/g,'$1'),font:FONT,size:SZ})]}));
              } else if (line.match(/^\d+\. /)) {
                const m=line.match(/^(\d+)\. (.*)/);
                out.push(new Paragraph({...sp(40,40),indent:{left:400,hanging:200},children:[new TextRun({text:`${m[1]}. ${m[2].replace(/\*\*(.+?)\*\*/g,'$1')}`,font:FONT,size:SZ})]}));
              } else {
                const txt=line.replace(/\*\*(.+?)\*\*/g,'$1').replace(/\*(.+?)\*/g,'$1');
                out.push(new Paragraph({...sp(60,60),children:[new TextRun({text:txt,font:FONT,size:SZ})]}));
              }
            }
            if (inTbl) flushTbl();
            return out;
          }

          const mapel = String(R.mata_pelajaran || '').trim();
          const materi = String(R.materi || '').trim();
          const kelas = String(R.kelas || '').trim();
          const jenjang = String(R.jenjang || '').trim();
          const kurikulum = String(R.kurikulum || '').trim();
          const pendekatan = String(R.pendekatan || '').trim();
          const format = String(R.format || '').trim();
          const alokasi = String(R.alokasi_waktu || '').trim();
          const namaSekolah = String(R.nama_sekolah || '').trim();
          const namaGuru = String(R.nama_guru || '').trim();

          const metaRows = [
            ['Jenjang', jenjang || '-'],
            ['Kelas', kelas || '-'],
            ['Mata Pelajaran', mapel || '-'],
            ['Materi / Topik', materi || '-'],
            ['Kurikulum', kurikulum || '-'],
            ['Pendekatan', pendekatan || '-'],
            ['Format', format || '-'],
            ['Alokasi Waktu', alokasi || '-'],
            ['Nama Sekolah', namaSekolah || '-'],
            ['Nama Guru', namaGuru || '-'],
          ];

          const cw = Math.floor(CW/2);
          const metaTable = new Table({
            width:{size:CW,type:WidthType.DXA},
            columnWidths:[cw,cw],
            rows: [
              new TableRow({
                children: [
                  new TableCell({ borders, width:{size:cw,type:WidthType.DXA}, shading:{fill:'D9D9D9',type:ShadingType.CLEAR}, margins:{top:80,bottom:80,left:100,right:100}, children:[new Paragraph({alignment:AlignmentType.CENTER,children:[new TextRun({text:'Komponen',font:FONT,size:20,bold:true})]})]}),
                  new TableCell({ borders, width:{size:cw,type:WidthType.DXA}, shading:{fill:'D9D9D9',type:ShadingType.CLEAR}, margins:{top:80,bottom:80,left:100,right:100}, children:[new Paragraph({alignment:AlignmentType.CENTER,children:[new TextRun({text:'Keterangan',font:FONT,size:20,bold:true})]})]}),
                ]
              }),
              ...metaRows.map(([k,v]) => new TableRow({
                children: [
                  new TableCell({ borders, width:{size:cw,type:WidthType.DXA}, margins:{top:80,bottom:80,left:100,right:100}, children:[new Paragraph({children:[new TextRun({text:String(k),font:FONT,size:SZ,bold:true})]})]}),
                  new TableCell({ borders, width:{size:cw,type:WidthType.DXA}, margins:{top:80,bottom:80,left:100,right:100}, children:[new Paragraph({children:[new TextRun({text:String(v),font:FONT,size:SZ})]})]}),
                ]
              }))
            ]
          });

          let bodyText = String(R.hasil || '');
          bodyText = stripRppDuplicateInfo(bodyText);
          bodyText = bodyText.trim();
          bodyText = rppNormalizeRubrikToTables(bodyText);

          const children = [
            new Paragraph({alignment:AlignmentType.CENTER,...sp(200,60),children:[new TextRun({text:'RPP (Rencana Pelaksanaan Pembelajaran)',font:FONT,size:32,bold:true})]}),
            ...(mapel || materi || kelas ? [new Paragraph({alignment:AlignmentType.CENTER,...sp(0,240),children:[new TextRun({text:[mapel,kelas,materi].filter(Boolean).join(' · '),font:FONT,size:24,italics:true})]})] : [new Paragraph({...sp(0,240)})]),
            metaTable,
            new Paragraph({...sp(200,120)}),
            ...parseContent(bodyText),
          ];

          const doc = new Document({
            styles:{default:{document:{run:{font:FONT,size:SZ}}}},
            sections:[{
              properties:{page:{size:{width:12240,height:15840},margin:{top:1440,right:1440,bottom:1440,left:1440}}},
              children
            }]
          });
          const blob = await Packer.toBlob(doc);
          const a = document.createElement('a');
          a.href = URL.createObjectURL(blob);
          const safe = `${String(R.mata_pelajaran||'Mapel').replace(/\\s+/g,'_')}_${String(R.kelas||'Kelas').replace(/\\s+/g,'_')}_${String(R.materi||'Materi').replace(/[\\s/]+/g,'_')}`;
          a.download = `RPP_${safe}.docx`;
          a.click();
          URL.revokeObjectURL(a.href);
        } finally {
          if (btn) { btn.disabled=false; if (origHTML) btn.innerHTML = origHTML; }
        }
      }

      async function exportRPPPdf() {
        const R = state.rpp || {};
        if (!R.hasil) return;
        const btn = document.getElementById('btnExportRPPPdfTop');
        const origHTML = btn?.innerHTML;
        if (btn) { btn.disabled = true; btn.textContent = 'Membuat PDF...'; }
        try {
          await ensureHtml2Pdf();
          const src = document.getElementById('rppPreview');
          if (!src) { alert('Buka tab RPP dulu untuk menampilkan preview.'); return; }
          const clone = src.cloneNode(true);
          const wrapper = document.createElement('div');
          wrapper.style.background = '#ffffff';
          wrapper.style.color = '#000000';
          const style = document.createElement('style');
          style.textContent = `
            .space-y-6 > :not([hidden]) ~ :not([hidden]) { margin-top: 0 !important; }
            .rpp-page { page-break-after: always; break-after: page; box-shadow: none !important; border: none !important; min-height: auto !important; height: auto !important; }
            .rpp-page:last-child { page-break-after: auto; break-after: auto; }
            table { width: 100%; border-collapse: collapse; }
            th, td { border: 1px solid #cfcfcf; padding: 6px 8px; vertical-align: top; }
            th { background: #f3f4f6; font-weight: 700; }
          `;
          wrapper.appendChild(style);
          wrapper.appendChild(clone);

          const safe = `${String(R.mata_pelajaran||'Mapel').replace(/\s+/g,'_')}_${String(R.kelas||'Kelas').replace(/\s+/g,'_')}_${String(R.materi||'Materi').replace(/[\s/]+/g,'_')}`;
          await window.html2pdf().set({
            margin: [10, 10, 12, 10],
            filename: `RPP_${safe}.pdf`,
            image: { type: 'jpeg', quality: 0.98 },
            html2canvas: { scale: 2, useCORS: true, backgroundColor: '#ffffff' },
            jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' },
            pagebreak: { mode: ['css'] },
          }).from(wrapper).save();
        } catch (e) {
          alert('Gagal membuat PDF: ' + (e?.message || 'Terjadi kesalahan.'));
        } finally {
          if (btn) { btn.disabled = false; if (origHTML) btn.innerHTML = origHTML; }
        }
      }

      const buildNavAndTabs = async () => {
        const nav = el("nav");
        const tabs = el("tabs");
        if (!nav || !tabs) return;
        const baseBtn = (icon, label, extra = '', attrs = '') =>
          `<button ${attrs} class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium hover:bg-background-light dark:hover:bg-background-dark ${extra}">
            <span class="material-symbols-outlined text-[18px]">${icon}</span>
            <span>${label}</span>
          </button>`;
        const baseLink = (href, icon, label) =>
          `<a href="${href}" class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium hover:bg-background-light dark:hover:bg-background-dark">
            <span class="material-symbols-outlined text-[18px]">${icon}</span>
            <span>${label}</span>
          </a>`;

        const navViews = VIEWS.filter(v => v.id !== 'lkpd');
        const coreNav = navViews.map(v => `
            <button onclick="__sp.setView('${v.id}')" class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium hover:bg-background-light dark:hover:bg-background-dark ${state.activeView === v.id ? 'bg-primary/10 text-primary' : ''}">
              <span class="material-symbols-outlined text-[18px]">${v.icon}</span>
              <span>${v.label}</span>
            </button>
        `).join('');

        const extraNav =
          `<div class="px-3 py-2 rounded-lg border border-border-light dark:border-border-dark bg-white/60 dark:bg-surface-dark/40">
             <div class="flex items-center gap-2">
               <span class="material-symbols-outlined text-[18px] text-text-sub-light dark:text-text-sub-dark">account_circle</span>
               <div class="min-w-0">
                 <div class="text-[11px] uppercase tracking-wide text-text-sub-light dark:text-text-sub-dark">Masuk sebagai</div>
                 <div class="text-sm font-semibold truncate">${safeText(String((USER_PROFILE && USER_PROFILE.nama) ? USER_PROFILE.nama : (LOGIN_NAME || '')) || '')}</div>
               </div>
             </div>
           </div>
           <div class="h-px bg-border-light dark:bg-border-dark my-2"></div>
           ${baseBtn('dark_mode','Tema','', 'id="btnTheme" onclick="toggleTheme()"')}
           ${IS_DEMO_USER ? `` : baseLink('profile.php','account_circle','Profil')}
           ${IS_ADMIN ? `
            ${baseLink('admin_soal_history.php','history','Riwayat')}
             ${baseLink('admin_audit_logs.php','bug_report','Audit Log')}
            ${baseLink('admin_api_models.php','model_training','Model API')}
             ${baseLink('admin_api_keys.php','key','Api Key')}
             ${baseLink('admin_users.php','group','Pengguna')}
           ` : ``}
           ${baseLink('logout.php','logout','Keluar')}
          `;
        nav.innerHTML = coreNav + extraNav;
        try {} catch {}
        tabs.innerHTML = `
          ${navViews.map(v => `
            <button onclick="__sp.setView('${v.id}')" class="flex items-center gap-2 rounded-full px-3 py-1.5 text-xs font-medium border ${state.activeView === v.id ? 'bg-primary text-white border-primary' : 'bg-white dark:bg-surface-dark border-border-light dark:border-border-dark'}">
              <span class="material-symbols-outlined text-[16px]">${v.icon}</span>
              <span>${v.label}</span>
            </button>
          `).join('')}
        `;
      };

      const applyTheme = () => {
        const root = document.documentElement;
        if (state.theme === "dark") root.classList.add("dark");
        else root.classList.remove("dark");
      };
      const toggleTheme = () => {
        state.theme = state.theme === "dark" ? "light" : "dark";
        saveDebounced(true);
        applyTheme();
        render();
      };

      const generatingPreviewHtml = () => `
        <div class="flex flex-col items-center justify-center p-10 md:p-16 lg:p-20 gap-4 max-w-2xl mx-auto">
          <div class="size-12 rounded-full bg-primary/10 text-primary flex items-center justify-center">
            <span class="material-symbols-outlined animate-spin">progress_activity</span>
          </div>
          <div class="text-center">
            <div class="font-bold text-lg">Menyusun Paket Soal...</div>
            <div id="genProgress" class="text-sm text-text-sub-light">Mohon tunggu beberapa saat</div>
          </div>
          <div class="w-full">
            <div class="rounded-lg border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/20">
              <div class="flex items-start gap-3 p-4">
                <span class="material-symbols-outlined text-amber-500 mt-0.5">warning</span>
                <div>
                  <div class="font-bold text-amber-700 dark:text-amber-300">PERINGATAN PENTING</div>
                  <div class="text-sm text-amber-700/90 dark:text-amber-200">
                    Jangan tutup halaman ini. Pastikan layar perangkat Anda tidak mati selama proses berlangsung agar pembuatan soal tidak terputus.
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>`;

      const computeView = () => {
        const noAccessBox = (title) => `
          <div class="bg-surface-light dark:bg-surface-dark rounded-xl border border-border-light dark:border-border-dark shadow-sm overflow-hidden">
            <div class="p-6 space-y-2">
              <div class="text-xl font-bold">${safeText(title)}</div>
              <div class="rounded-lg border border-amber-200 bg-amber-50 text-amber-900 p-4 text-sm">
                Akses fitur ${safeText(title)} belum diaktifkan untuk akun Anda. Silakan upgrade paket atau hubungi Admin <a class="font-semibold underline text-blue-700" href="https://wa.me/6282174028646" target="_blank" rel="noopener">klik di sini</a>.
              </div>
            </div>
          </div>
        `;
        if (state.activeView === "preview" && !HAS_BUAT_SOAL_ACCESS) return noAccessBox("Buat Soal");
        if (state.activeView === "modul_ajar" && !HAS_MODUL_AJAR_ACCESS) return noAccessBox("Modul Ajar");
        if (state.activeView === "rpp" && !HAS_RPP_ACCESS) return noAccessBox("RPP");
        if (state.activeView === "preview") {
          const helpOnClick = "window.__sp.openBuatSoalHelp()";
          const tutorialBtn = `
                <button class="inline-flex items-center justify-center h-10 px-4 rounded-lg border bg-white dark:bg-surface-dark hover:bg-background-light dark:hover:bg-background-dark text-sm font-bold"
                  onclick="window.__sp.openBuatSoalTutorial()" title="Tutorial">
                  <span class="material-symbols-outlined text-[18px]">volume_up</span>
                  <span class="ml-2 hidden lg:inline">Tutorial</span>
                </button>
              `;
          const tabBar = `
            <div class="hidden md:flex items-center justify-between gap-3 mb-2">
              <div class="inline-flex rounded-lg border bg-white dark:bg-surface-dark overflow-x-auto no-scrollbar">
                ${["identitas","konfigurasi","naskah"].map(t=>{
                  const label = t==="identitas"?"1. Identitas":(t==="konfigurasi"?"2. Konfigurasi":"3. Naskah Soal");
                  const active = state.previewTab===t;
                  return `<button class="${active?'bg-primary text-white':'bg-white dark:bg-surface-dark'} px-4 h-10 rounded-lg text-sm font-bold whitespace-nowrap" onclick="window.__sp.setPreviewTab('${t}')">${label}</button>`;
                }).join('')}
              </div>
              <div class="flex items-center gap-2">
                <button class="inline-flex items-center justify-center h-10 px-4 rounded-lg border bg-white dark:bg-surface-dark hover:bg-background-light dark:hover:bg-background-dark text-sm font-bold"
                  onclick="saveProject()" title="Simpan">
                  <span class="material-symbols-outlined text-[18px]">save</span>
                  <span class="ml-2 hidden lg:inline">Simpan</span>
                </button>
                <button class="inline-flex items-center justify-center h-10 px-4 rounded-lg border bg-white dark:bg-surface-dark hover:bg-background-light dark:hover:bg-background-dark text-sm font-bold"
                  onclick="document.getElementById('projectPicker').value=''; document.getElementById('projectPicker').click();" title="Muat">
                  <span class="material-symbols-outlined text-[18px]">folder_open</span>
                  <span class="ml-2 hidden lg:inline">Muat</span>
                </button>
                <button class="inline-flex items-center justify-center h-10 px-4 rounded-lg border bg-white dark:bg-surface-dark hover:bg-background-light dark:hover:bg-background-dark text-sm font-bold"
                  onclick="${helpOnClick}" title="Petunjuk">
                  <span class="material-symbols-outlined text-[18px]">help</span>
                  <span class="ml-2">Petunjuk</span>
                </button>
                ${tutorialBtn}
              </div>
            </div>
          `;

          const mobileStepNav = (tab) => {
            const prev = tab === 'konfigurasi' ? 'identitas' : (tab === 'naskah' ? 'konfigurasi' : null);
            const next = tab === 'identitas' ? 'konfigurasi' : (tab === 'konfigurasi' ? 'naskah' : null);
            const rightLabel = tab === 'identitas' ? 'Konfigurasi' : (tab === 'konfigurasi' ? 'Naskah Soal' : '');
            const rightOnClick = tab === 'konfigurasi'
              ? `onclick="window.__sp.openNaskahSoalFromKonfigurasi()"`
              : (next ? `onclick="window.__sp.setPreviewTab('${next}')"` : '');
            return `
              <div class="md:hidden mt-6 flex items-center gap-3">
                <button class="flex-1 h-12 rounded-xl border bg-white dark:bg-surface-dark font-bold" ${prev ? `onclick="window.__sp.setPreviewTab('${prev}')"` : 'disabled'}>
                  Kembali
                </button>
                <button class="flex-1 h-12 rounded-xl ${next ? 'bg-primary text-white' : 'bg-gray-200 text-gray-500'} font-bold" ${next ? rightOnClick : 'disabled'}>
                  ${rightLabel || 'Lanjut'}
                </button>
              </div>
            `;
          };

          let body = "";
          if (state._isGenerating) {
            body = generatingPreviewHtml();
          } else if (state.previewTab === "identitas") body = renderIdentitas() + mobileStepNav("identitas");
          else if (state.previewTab === "konfigurasi") body = renderKonfigurasi() + mobileStepNav("konfigurasi");
          else {
            const parts = [renderNaskah()];
            if (state.previewFlags?.kunci) parts.push(`<div class="my-6 border-t border-dashed border-gray-300"></div><div style="break-before: page; page-break-before: always;"></div><div class="mt-10">${renderKunci()}</div>`);
            if (state.previewFlags?.kisi) parts.push(`<div class="my-6 border-t border-dashed border-gray-300"></div><div style="break-before: page; page-break-before: always;"></div><div class="mt-10">${renderKisi()}</div>`);
            body = parts.join("") + mobileStepNav("naskah");
          }

          const globalHelp = `
            <div id="modalBuatSoalHelp" class="fixed inset-0 hidden items-center justify-center" style="display:none; background: rgba(0,0,0,0.5); z-index:50;">
              <div class="bg-white dark:bg-surface-dark rounded-xl border border-border-light dark:border-border-dark shadow-xl w-[92vw] max-w-[860px] max-height-[85vh] overflow-auto">
                <div class="p-5 border-b border-border-light dark:border-border-dark flex items-center justify-between">
                  <div class="font-bold text-lg flex items-center gap-2"><span class="material-symbols-outlined">help</span> Petunjuk Buat Soal</div>
                  <button class="size-9 rounded-lg border bg-white dark:bg-surface-dark" onclick="window.__sp.closeBuatSoalHelp()">&times;</button>
                </div>
                <div class="p-5 space-y-5 text-sm leading-relaxed">
                  <div>
                    <div class="font-bold mb-1">Alur singkat</div>
                    <ol class="list-decimal pl-5 space-y-1">
                      <li>Isi Identitas (jenjang/fase/kelas/mapel + Sumber Materi).</li>
                      <li>Atur Konfigurasi bagian (bentuk soal & jumlah soal).</li>
                      <li>Buat Naskah Soal, lalu unduh/cetak bila diperlukan.</li>
                    </ol>
                  </div>
                  <div>
                    <div class="font-bold mb-1">Tombol Simpan & Muat</div>
                    <ul class="list-disc pl-5 space-y-1">
                      <li>Simpan: menyimpan proyek ke file .json (agar bisa dibuka lagi kapan saja).</li>
                      <li>Muat: memulihkan proyek dari file .json yang pernah disimpan.</li>
                      <li>Catatan: aplikasi juga menyimpan otomatis di browser, tapi Simpan .json tetap disarankan untuk backup.</li>
                    </ul>
                  </div>
                  <div>
                    <div class="font-bold mb-1">Input wajib (mandatory)</div>
                    <ul class="list-disc pl-5 space-y-1">
                      <li>Identitas: Nama Sekolah, Jenjang, Fase, Kelas, Mata Pelajaran, Judul Paket, Tahun Ajaran.</li>
                      <li>Konfigurasi: minimal 1 Bagian dan Jumlah Soal minimal 1.</li>
                      <li>Sumber Materi: opsional, tetapi sangat disarankan agar soal lebih relevan.</li>
                    </ul>
                  </div>
                  <div>
                    <div class="font-bold mb-1">Fungsi tiap tab</div>
                    <ul class="list-disc pl-5 space-y-1">
                      <li>1. Identitas: mengatur konteks soal (level & mapel) dan memasukkan Sumber Materi.</li>
                      <li>2. Konfigurasi: mengatur bentuk soal per bagian (PG/Isian/Uraian, dll), jumlah soal, kesulitan, dan opsi gambar.</li>
                      <li>3. Naskah Soal: melihat hasil soal, melakukan perbaikan, lalu unduh/cetak.</li>
                    </ul>
                  </div>
                  <div class="rounded-md border border-blue-200 bg-blue-50 text-blue-800 p-3 text-xs">
                    Tips: Jika hasil soal terlalu umum, tambahkan materi yang lebih spesifik di Sumber Materi, lalu buat ulang paket.
                  </div>
                </div>
              </div>
            </div>
            <div id="modalKonteksHelp" class="fixed inset-0 hidden items-center justify-center" style="display:none; background: rgba(0,0,0,0.5); z-index:50;">
              <div class="bg-white dark:bg-surface-dark rounded-xl border border-border-light dark:border-border-dark shadow-xl w-[92vw] max-w-[720px] max-height-[85vh] overflow-auto">
                <div class="p-5 border-b border-border-light dark:border-border-dark flex items-center justify-between">
                  <div id="konteksHelpTitle" class="font-bold text-lg flex items-center gap-2"><span class="material-symbols-outlined">help</span> Petunjuk Soal Berkonteks</div>
                  <button class="size-9 rounded-lg border bg-white dark:bg-surface-dark" onclick="window.__sp.closeKonteksHelp()">&times;</button>
                </div>
                <div id="konteksHelpBody" class="p-5 text-sm leading-relaxed"></div>
              </div>
            </div>
          `;
          const tutorialModal = `
            <div id="modalBuatSoalTutorial" class="fixed inset-0 hidden items-center justify-center" style="display:none; background: rgba(0,0,0,0.5); z-index:50;">
              <div class="bg-white dark:bg-surface-dark rounded-xl border border-border-light dark:border-border-dark shadow-xl w-[92vw] max-w-[900px] max-h-[85vh] overflow-auto">
                <div class="p-5 border-b border-border-light dark:border-border-dark flex items-center justify-between">
                  <div class="font-bold text-lg flex items-center gap-2"><span class="material-symbols-outlined">volume_up</span> <span id="bstModalTitle">Tutorial Buat Soal</span></div>
                  <button class="size-9 rounded-lg border bg-white dark:bg-surface-dark" onclick="window.__sp.closeBuatSoalTutorial()">&times;</button>
                </div>
                <div class="p-5">
                  <div class="space-y-2">
                    <div class="text-sm font-bold">Daftar tutorial</div>
                    <div id="bstList" class="space-y-2"></div>
                    <div class="text-xs text-text-sub-light dark:text-text-sub-dark">
                      Catatan: file video/voice over akan ditambahkan menyusul.
                    </div>
                  </div>
                </div>
              </div>
            </div>
          `;
          return `<div class="pb-6 md:pb-0">${tabBar}${body}${globalHelp}${tutorialModal}</div>`;
        }
        if (state.activeView === "lkpd") return renderLKPD();
        if (state.activeView === "modul_ajar") return renderModulAjar();
        if (state.activeView === "rpp") return renderRPP();
        if (state.activeView === "quiz") return renderQuizLanding();
        if (state.activeView === "rekap") return renderRekap();
        if (state.activeView === "limit") { state.activeView = "preview"; return ""; }
        if (state.activeView === "riwayat") return renderRiwayat();
        return "";
      };

      const render = async () => {
        await buildNavAndTabs();
        computeStats();
        const view = VIEWS.find((v) => v.id === state.activeView) || VIEWS[0];
        el("pageTitle").textContent = view.label;
        el("pageDesc").textContent =
          {
            preview: "Buat soal, identitas, dan konfigurasi paket",
            lkpd: "Generator LKPD otomatis sesuai tema aplikasi",
            modul_ajar: "Generator Modul Ajar Kurikulum Merdeka 2025 · Deep Learning",
            rpp: "Generator RPP siap supervisi · Ringkas atau lengkap",
            quiz: "Mode kuis interaktif untuk kelas",
            rekap: "Rekap nilai otomatis, tabel ringkasan dan unduhan",
            limit: "Pantau sisa kredit dan riwayat penggunaannya",
            riwayat: "Riwayat paket soal yang tersimpan",
          }[state.activeView] || "";
        const root = el("viewRoot");
        root.innerHTML = computeView();
        wireInputs(root);
        if (state.activeView === "quiz" && state.quizSubtab === "share" && state.quizShareTab === "hasil") {
          try {
            const items = Array.isArray(state.quizPublications) ? state.quizPublications : [];
            if (!items.length) {
              await loadPublications();
              const rootQuiz = el("viewRoot");
              if (rootQuiz) {
                rootQuiz.innerHTML = computeView();
                wireInputs(rootQuiz);
              }
            }
          } catch {}
        }
        if (state.activeView === "riwayat") {
          loadRiwayat();
        }
        if (state.activeView === "rekap") {
          const picker = el("rekapExcelPicker");
          if (picker) picker.onchange = rekapHandlePicker;
          const q = el("rekapSearch");
          if (q) q.oninput = rekapFilter;
          rekapRenderTable();
        }
        if (state.activeView === "limit") {
          if (IS_ADMIN) await loadLimitConfig();
          await refreshCreditLimit(false);
          const root2 = el("viewRoot");
          if (root2) {
            root2.innerHTML = computeView();
            wireInputs(root2);
          }
        }
      };

      const renderIdentitas = () => {
        const i = state.identity;
        const p = state.paket;
        const jenjangEfektif = resolveJenjang(i.jenjang, i.kesetaraanPaket);
        const isKesetaraan = String(i.jenjang || "").trim() === "Kesetaraan";
        const faseOpts = MA_FASE_MAP[jenjangEfektif] || [];
        return `
          <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div class="lg:col-span-2 bg-surface-light dark:bg-surface-dark rounded-xl border border-border-light dark:border-border-dark shadow-sm overflow-hidden">
              <div class="p-6 space-y-6">
                ${state.soalError && state.soalError.tab === 'identitas' ? `
                  <div class="rounded-lg bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 p-4 text-sm text-red-700 dark:text-red-300">
                    ${safeText(state.soalError.msg || '')}
                  </div>` : ``}
                <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                  <div>
                    <div class="flex items-center gap-2">
                      <div class="text-[10px] font-bold text-primary bg-primary/10 inline-flex px-2 py-0.5 rounded-full">Langkah 1</div>
                      <button class="h-6 w-6 rounded-full border border-border-light dark:border-border-dark bg-white dark:bg-surface-dark hover:bg-background-light dark:hover:bg-background-dark inline-flex items-center justify-center"
                        title="Petunjuk pengisian identitas"
                        onclick="window.__sp.openIdentitasHelp()">
                        <span class="material-symbols-outlined text-[16px]">help</span>
                      </button>
                    </div>
                    <div class="text-xl font-bold mt-2">Identitas Soal</div>
                    <div class="text-sm text-text-sub-light dark:text-text-sub-dark mt-1">Data identitas disimpan otomatis</div>
                  </div>
                  <div class="flex gap-2">
                    <button
                      class="hidden md:flex items-center gap-2 rounded-lg h-10 px-4 bg-primary hover:bg-blue-600 text-primary-content text-sm font-bold shadow-sm transition-colors"
                      onclick="window.__sp.setPreviewTab('konfigurasi')"
                    >
                      <span class="material-symbols-outlined text-[18px]">arrow_forward</span>
                      Konfigurasi
                    </button>
                  </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                  ${inputText("Nama Guru", "identity.namaGuru", i.namaGuru, "Contoh: Budi Santoso, S.Pd")}
                  ${inputText("Nama Sekolah", "identity.namaSekolah", i.namaSekolah, "Masukkan nama sekolah")}
                  <div class="flex flex-col gap-2">
                    <label class="text-sm font-semibold text-text-sub-light dark:text-text-sub-dark">Logo Sekolah (≤ 200KB)</label>
                    <div class="flex items-center gap-4">
                      <div class="w-20 h-20 rounded-lg border border-border-light dark:border-border-dark bg-white dark:bg-gray-800 flex items-center justify-center overflow-hidden">
                        ${i.logo ? `<img src="${i.logo}" class="max-w-full max-h-full">` : `<span class="material-symbols-outlined text-[24px] text-text-sub-light dark:text-text-sub-dark">imagesmode</span>`}
                      </div>
                      <div class="flex gap-2">
                        <button class="flex items-center gap-2 rounded-lg h-10 px-4 bg-white dark:bg-surface-dark border border-border-light dark:border-border-dark hover:bg-background-light dark:hover:bg-background-dark text-sm font-bold shadow-sm transition-colors" onclick="window.__sp.pickLogo()">
                          <span class="material-symbols-outlined text-[18px]">upload</span>
                          Unggah
                        </button>
                        ${i.logo ? `
                          <button class="flex items-center gap-2 rounded-lg h-10 px-4 bg-red-50 hover:bg-red-100 text-red-600 border border-red-200 text-sm font-bold shadow-sm transition-colors" onclick="window.__sp.clearLogo()">
                            <span class="material-symbols-outlined text-[18px]">delete</span>
                            Hapus
                          </button>` : ``}
                      </div>
                    </div>
                  </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                  <div class="flex flex-col gap-2">
                    <label class="text-sm font-semibold text-text-sub-light dark:text-text-sub-dark">Jenjang</label>
                    <select class="w-full rounded-lg border-border-light dark:border-border-dark bg-background-light dark:bg-background-dark/40 focus:border-primary focus:ring-primary px-4 py-3 text-sm" data-path="identity.jenjang">
                      <option value="">Pilih...</option>
                      ${["PAUD","TK","SD/MI","SMP/MTs","SMA/MA","SMK/MAK","Kesetaraan"].map(v => `<option value="${safeText(v)}"${String(i.jenjang||"")===v ? " selected" : ""}>${safeText(v)}</option>`).join("")}
                    </select>
                    <div class="${isKesetaraan ? "" : "hidden"} mt-2 flex flex-col gap-2">
                      <label class="text-sm font-semibold text-text-sub-light dark:text-text-sub-dark">Paket</label>
                      <select class="w-full rounded-lg border-border-light dark:border-border-dark bg-background-light dark:bg-background-dark/40 focus:border-primary focus:ring-primary px-4 py-3 text-sm" data-path="identity.kesetaraanPaket">
                        <option value="">Pilih Paket...</option>
                        ${KES_PAKET_OPTIONS.map(v => `<option value="${safeText(v)}"${String(i.kesetaraanPaket||"")===v ? " selected" : ""}>${safeText(v)}</option>`).join("")}
                      </select>
                    </div>
                  </div>
                  ${selectField("Fase", "identity.fase", i.fase, faseOpts)}
                  ${selectField("Kelas", "identity.kelas", i.kelas, CLASS_OPTIONS[jenjangEfektif] || [])}
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                  ${selectField("Mata Pelajaran", "identity.mataPelajaran", i.mataPelajaran, SUBJECT_OPTIONS[jenjangEfektif] || [])}
                  ${inputText("Semester (opsional)", "paket.semester", p.semester, "Contoh: Semester 2")}
                </div>
                <div class="grid grid-cols-1 gap-5">
                  <div class="space-y-3">
                    <details id="detailsTopikRaw" open class="rounded-lg border border-border-light dark:border-border-dark bg-white dark:bg-surface-dark overflow-hidden">
                      <summary class="cursor-pointer select-none px-4 py-4 flex items-center justify-between gap-3">
                        <span class="flex items-center gap-2">
                          <span class="text-sm font-bold">Sumber Materi</span>
                          <button type="button"
                            class="flex size-7 items-center justify-center rounded-full border border-border-light dark:border-border-dark bg-white dark:bg-surface-dark text-text-sub-light dark:text-text-sub-dark hover:bg-primary/10 hover:text-primary transition-colors"
                            title="Petunjuk Sumber Materi"
                            onclick="event.preventDefault(); event.stopPropagation(); window.__sp.openSumberMateriHelp();"
                          >
                            <span class="material-symbols-outlined text-[16px]">help</span>
                          </button>
                        </span>
                        <span class="flex items-center gap-2 text-xs text-text-sub-light dark:text-text-sub-dark">
                          <span>${safeText(String(i.topik_raw || '').trim() ? `${String(i.topik_raw || '').trim().length} karakter` : 'kosong')}</span>
                          <span class="material-symbols-outlined text-[18px]">expand_more</span>
                        </span>
                      </summary>
                      <div class="p-4 space-y-3 border-t border-border-light dark:border-border-dark">
                        <div class="flex flex-col gap-2">
                          <label class="text-sm font-semibold text-text-sub-light dark:text-text-sub-dark">Acuan Utama Soal</label>
                          <textarea
                            class="w-full rounded-lg border-border-light dark:border-border-dark bg-background-light dark:bg-background-dark/40 focus:border-primary focus:ring-primary px-4 py-3 text-sm min-h-[180px] md:min-h-[220px]"
                            data-path="identity.topik_raw"
                            placeholder="Paste materi lengkap di sini, atau upload gambar/file teks."
                          >${safeText(i.topik_raw ?? "")}</textarea>
                        </div>
                        <div class="flex flex-col sm:flex-row sm:flex-wrap items-stretch sm:items-center gap-2">
                          <button id="btnTopikUploadImg" class="w-full sm:w-auto justify-center flex items-center gap-2 rounded-lg h-10 px-4 bg-white dark:bg-surface-dark border border-border-light dark:border-border-dark hover:bg-background-light dark:hover:bg-background-dark text-sm font-bold shadow-sm transition-colors" onclick="window.__sp.pickTopikImage()">
                            <span class="material-symbols-outlined text-[18px]">image</span>
                            Upload Gambar
                          </button>
                          <button id="btnTopikUploadTxt" class="w-full sm:w-auto justify-center flex items-center gap-2 rounded-lg h-10 px-4 bg-white dark:bg-surface-dark border border-border-light dark:border-border-dark hover:bg-background-light dark:hover:bg-background-dark text-sm font-bold shadow-sm transition-colors" onclick="window.__sp.pickTopikText()">
                            <span class="material-symbols-outlined text-[18px]">upload_file</span>
                            Upload File Teks
                          </button>
                          <button id="btnTopikUploadPdf" class="w-full sm:w-auto justify-center flex items-center gap-2 rounded-lg h-10 px-4 bg-white dark:bg-surface-dark border border-border-light dark:border-border-dark hover:bg-background-light dark:hover:bg-background-dark text-sm font-bold shadow-sm transition-colors" onclick="window.__sp.pickTopikPdf()">
                            <span class="material-symbols-outlined text-[18px]">picture_as_pdf</span>
                            Upload PDF
                          </button>
                        </div>
                      </div>
                    </details>
                    <div id="modalSumberMateriHelp" class="fixed inset-0 hidden items-center justify-center" style="display:none; background: rgba(0,0,0,0.5); z-index:50;">
                      <div class="bg-white dark:bg-surface-dark rounded-xl border border-border-light dark:border-border-dark shadow-xl w-[92vw] max-w-[760px] max-height-[85vh] overflow-auto">
                        <div class="p-5 border-b border-border-light dark:border-border-dark flex items-center justify-between">
                          <div class="font-bold text-lg flex items-center gap-2"><span class="material-symbols-outlined">help</span> Petunjuk Sumber Materi</div>
                          <button class="size-9 rounded-lg border bg-white dark:bg-surface-dark" onclick="window.__sp.closeSumberMateriHelp()">&times;</button>
                        </div>
                        <div class="p-5 space-y-4 text-sm leading-relaxed">
                          <div>
                            <div class="font-bold mb-1">Fungsi</div>
                            <ul class="list-disc pl-5 space-y-1">
                              <li>Sumber Materi adalah acuan utama untuk membuat soal.</li>
                              <li>Semakin lengkap materinya, soal biasanya makin relevan dan tidak terlalu umum.</li>
                              <li>Sistem tetap menyesuaikan level sesuai Jenjang, Fase, dan Kelas.</li>
                            </ul>
                          </div>
                          <div>
                            <div class="font-bold mb-1">Cara pakai</div>
                            <ol class="list-decimal pl-5 space-y-1">
                              <li>Tempel materi ke kolom Acuan Utama Soal, atau upload Gambar/File Teks.</li>
                              <li>Atur Jenjang, Fase, Kelas, dan Mata Pelajaran.</li>
                              <li>Lanjutkan Konfigurasi Bagian, lalu buat paket soal.</li>
                            </ol>
                          </div>
                          <div class="rounded-md border border-blue-200 bg-blue-50 text-blue-800 p-3 text-xs">
                            Tips: Jika materi sangat panjang, cukup ambil bagian inti/topik utama agar soal lebih fokus.
                          </div>
                        </div>
                      </div>
                    </div>
                    <div class="text-xs text-text-sub-light dark:text-text-sub-dark">Topik untuk tampilan akan dibuat otomatis (maks. 5 kata) dari Sumber Materi.</div>
                    <div class="pt-2">
                      ${inputText("Perintah Khusus (opsional)", "specialInstruction", state.specialInstruction || "", "Contoh: Perbanyak soal cerita. / Soal Bahasa Jawa ngoko, huruf latin.")}
                    </div>
                  </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-5 pt-2">
                  ${inputText("Judul Paket", "paket.judul", p.judul, "Contoh: Ulangan Harian")}
                  ${inputText("Tahun Ajaran", "paket.tahunAjaran", p.tahunAjaran, "Contoh: 2025/2026")}
                </div>
                
              </div>
            </div>
            <div id="modalIdentitasHelp" class="fixed inset-0 hidden items-center justify-center" style="display:none; background: rgba(0,0,0,0.5); z-index:50;">
              <div class="bg-white dark:bg-surface-dark rounded-xl border border-border-light dark:border-border-dark shadow-xl w-[92vw] max-w-[760px] max-height-[85vh] overflow-auto">
                <div class="p-5 border-b border-border-light dark:border-border-dark flex items-center justify-between">
                  <div class="font-bold text-lg flex items-center gap-2"><span class="material-symbols-outlined">help</span> Petunjuk Pengisian Identitas</div>
                  <button class="size-9 rounded-lg border bg-white dark:bg-surface-dark" onclick="window.__sp.closeIdentitasHelp()">&times;</button>
                </div>
                <div class="p-5 space-y-3 text-sm leading-relaxed">
                  <ol class="list-decimal pl-5 space-y-2">
                    <li>Isi Nama Guru dan Nama Sekolah. Unggah logo sekolah (opsional, ≤ 200KB) untuk terlihat di kop lembar soal.</li>
                    <li>Pilih Jenjang, Fase, dan Kelas sesuai peserta didik.</li>
                    <li>Pilih Mata Pelajaran. Isi Tema/Topik Ringkas (opsional) untuk tampilan.</li>
                    <li>Jika ingin soal sangat relevan, isi Sumber Materi (Mentah) dengan paste atau upload (gambar/file teks/.docx). Sistem akan membuat Tema Ringkas otomatis, tapi acuan utama soal tetap Materi Mentah.</li>
                    <li>Isi Judul Paket dan Tahun Ajaran. Semester bersifat opsional.</li>
                    <li>Klik Simpan untuk menyimpan identitas, atau klik Konfigurasi untuk lanjut mengatur bagian soal.</li>
                  </ol>
                  <div class="rounded-md border border-blue-200 bg-blue-50 text-blue-800 p-3 text-xs">
                    Tips: Data identitas tersimpan otomatis. Anda selalu dapat kembali mengubahnya sebelum “Buat Paket Soal”.
                  </div>
                </div>
              </div>
            </div>
          </div>
        `;
      };

      const estimateTotalQuestions = () =>
        state.sections.reduce((acc, s) => {
          const isObjective = ["pg", "benar_salah", "pg_kompleks", "menjodohkan"].includes(s.bentuk);
          const isEssay = ["isian", "uraian"].includes(s.bentuk);
          return acc + (isObjective ? Number(s.jumlahPG || 0) : isEssay ? Number(s.jumlahIsian || 0) : 0);
        }, 0);

      const renderKonfigurasi = () => `
        <div class="space-y-6">
          ${state.soalError && state.soalError.tab === 'konfigurasi' ? `
            <div class="rounded-lg bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 p-4 text-sm text-red-700 dark:text-red-300">
              ${safeText(state.soalError.msg || '')}
            </div>` : ``}
          <div class="bg-surface-light dark:bg-surface-dark rounded-xl border border-border-light dark:border-border-dark shadow-sm p-6">
          <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div class="flex items-center gap-2">
              <span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-primary/10 text-primary">Langkah 2</span>
              <button class="h-6 w-6 rounded-full border border-border-light dark:border-border-dark bg-white dark:bg-surface-dark hover:bg-background-light dark:hover:bg-background-dark inline-flex items-center justify-center"
                title="Petunjuk konfigurasi paket"
                onclick="window.__sp.openKonfigurasiHelp()">
                <span class="material-symbols-outlined text-[16px]">help</span>
              </button>
              <div class="hidden md:block text-sm text-text-sub-light dark:text-text-sub-dark">Multi-bagian (bertingkat) dalam satu paket</div>
            </div>
            <div class="flex gap-2">
              <button
                class="flex items-center gap-2 rounded-lg h-10 px-4 bg-white dark:bg-surface-dark border border-border-light dark:border-border-dark hover:bg-background-light dark:hover:bg-background-dark text-sm font-bold shadow-sm transition-colors"
                onclick="window.__sp.addSection()"
              >
                <span class="material-symbols-outlined text-[18px]">add</span>
                Tambah Bagian
              </button>
              <button id="btnBuild"
                class="hidden md:flex items-center gap-2 rounded-lg h-10 px-4 bg-primary hover:bg-blue-600 text-primary-content text-sm font-bold shadow-sm transition-colors"
                onclick="window.__sp.openNaskahSoalFromKonfigurasi()"
              >
                <span class="material-symbols-outlined text-[18px]">arrow_forward</span>
                Naskah Soal
              </button>
            </div>
          </div>
          </div>
          <div id="modalKonfigurasiHelp" class="fixed inset-0 hidden items-center justify-center" style="display:none; background: rgba(0,0,0,0.5); z-index:50;">
            <div class="bg-white dark:bg-surface-dark rounded-xl border border-border-light dark:border-border-dark shadow-xl w-[92vw] max-w-[760px] max-height-[85vh] overflow-auto">
              <div class="p-5 border-b border-border-light dark:border-border-dark flex items-center justify-between">
                <div class="font-bold text-lg flex items-center gap-2"><span class="material-symbols-outlined">help</span> Petunjuk Konfigurasi Paket</div>
                <button class="size-9 rounded-lg border bg-white dark:bg-surface-dark" onclick="window.__sp.closeKonfigurasiHelp()">&times;</button>
              </div>
              <div class="p-5 space-y-3 text-sm leading-relaxed">
                <ol class="list-decimal pl-5 space-y-2">
                  <li>Klik Tambah Bagian untuk membuat bagian baru (A, B, C, ...).</li>
                  <li>Pilih Bentuk Soal per bagian: Pilihan Ganda, PG Kompleks, Menjodohkan, Isian, atau Uraian.</li>
                  <li>Atur Jumlah Soal. Jika PG, tentukan juga Jumlah Opsi (3–5).</li>
                  <li>Pilih Tingkat Kesulitan dan Cakupan Dimensi Bloom untuk variasi kognitif.</li>
                  <li>Aktifkan Ilustrasi/Gambar jika diperlukan agar sistem menyiapkan diagram/ascii/svg.</li>
                  <li>Ulangi untuk setiap bagian sesuai kebutuhan paket.</li>
                  <li>Setelah selesai, klik Naskah Soal untuk menghasilkan naskah lengkap.</li>
                </ol>
                <div class="rounded-md border border-blue-200 bg-blue-50 text-blue-800 p-3 text-xs">
                  Tips: Anda dapat menduplikasi bagian yang sudah sesuai lalu melakukan penyesuaian kecil.
                </div>
              </div>
            </div>
          </div>
          <div class="grid grid-cols-1 gap-5">
            ${state.sections.map((s, idx) => renderSectionCard(s, idx)).join("")}
          </div>
        </div>
      `;

      const renderSectionCard = (s, idx) => {
        const diff = s.tingkatKesulitan || "campuran";
        const bloomPreset = s.cakupanBloom || "level_standar";
        
        const isObjective = ["pg", "benar_salah", "pg_kompleks", "menjodohkan"].includes(s.bentuk);
        const isEssay = ["isian", "uraian"].includes(s.bentuk);
        const showOpsiPG = s.bentuk === "pg" || s.bentuk === "pg_kompleks";
        const totalTarget = Number(isObjective ? s.jumlahPG : s.jumlahIsian) || 0;
        const jmlSoalKonteks = totalTarget >= 3 ? Math.min(totalTarget, Math.max(3, Math.floor(totalTarget * 0.3))) : totalTarget;

        return `
          <div class="bg-surface-light dark:bg-surface-dark rounded-xl border border-border-light dark:border-border-dark shadow-sm overflow-hidden">
            <div class="p-6 space-y-6">
              <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                <div class="flex items-center gap-3">
                  <div class="size-9 rounded-lg bg-primary/10 text-primary flex items-center justify-center font-bold">${sectionLetter(
                    idx
                  )}</div>
                  <div>
                    <div class="text-sm font-bold">Bagian ${idx + 1}</div>
                    <div class="text-xs text-text-sub-light dark:text-text-sub-dark">Konfigurasi terpisah per bagian</div>
                  </div>
                </div>
                <div class="flex gap-2">
                  <button
                    class="flex items-center gap-2 rounded-lg h-9 px-3 bg-white dark:bg-surface-dark border border-border-light dark:border-border-dark hover:bg-background-light dark:hover:bg-background-dark text-sm font-bold shadow-sm transition-colors"
                    onclick="window.__sp.duplicateSection('${s.id}')"
                  >
                    <span class="material-symbols-outlined text-[18px]">content_copy</span>
                    Duplikat
                  </button>
                  <button
                    class="flex items-center gap-2 rounded-lg h-9 px-3 bg-red-50 dark:bg-red-500/10 border border-red-200 dark:border-red-500/30 text-red-600 dark:text-red-400 hover:bg-red-100 dark:hover:bg-red-500/20 text-sm font-bold transition-colors"
                    onclick="window.__sp.removeSection('${s.id}')"
                  >
                    <span class="material-symbols-outlined text-[18px]">delete</span>
                    Hapus
                  </button>
                </div>
              </div>

              <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                <div class="flex flex-col gap-2">
                  <label class="text-sm font-semibold text-text-sub-light dark:text-text-sub-dark">Judul Bagian</label>
                  <input
                    class="w-full rounded-lg border-border-light dark:border-border-dark bg-background-light dark:bg-background-dark/40 focus:border-primary focus:ring-primary h-11 px-4 text-sm"
                    value="${safeText(s.judul || "")}"
                    oninput="window.__sp.updateSection('${s.id}','judul',this.value,false)"
                    onblur="window.__sp.updateSection('${s.id}','judul',this.value,true)"
                  />
                </div>
                <div class="flex flex-col gap-2">
                  <label class="text-sm font-semibold text-text-sub-light dark:text-text-sub-dark">Bentuk Soal</label>
                  <select
                    class="w-full rounded-lg border-border-light dark:border-border-dark bg-background-light dark:bg-background-dark/40 focus:border-primary focus:ring-primary h-11 px-4 text-sm"
                    onchange="window.__sp.updateSection('${s.id}','bentuk',this.value)"
                  >
                    <option value="pg" ${s.bentuk === "pg" ? "selected" : ""}>Pilihan Ganda (Biasa)</option>
                    <option value="benar_salah" ${s.bentuk === "benar_salah" ? "selected" : ""}>Benar / Salah</option>
                    <option value="pg_kompleks" ${s.bentuk === "pg_kompleks" ? "selected" : ""}>Pilihan Ganda Kompleks (Jawaban >1)</option>
                    <option value="menjodohkan" ${s.bentuk === "menjodohkan" ? "selected" : ""}>Menjodohkan</option>
                    <option value="isian" ${s.bentuk === "isian" ? "selected" : ""}>Uraian Singkat (Esai)</option>
                    <option value="uraian" ${s.bentuk === "uraian" ? "selected" : ""}>Uraian Panjang</option>
                  </select>
                </div>
                <div class="flex flex-col gap-2">
                  <label class="text-sm font-semibold text-text-sub-light dark:text-text-sub-dark">Ilustrasi/Gambar</label>
                  <label class="flex items-center gap-3 h-11 px-4 rounded-lg border border-border-light dark:border-border-dark bg-background-light dark:bg-background-dark/40 cursor-pointer">
                    <input type="checkbox" ${s.pakaiGambar ? "checked" : ""} onchange="window.__sp.updateSection('${s.id}','pakaiGambar',this.checked)" />
                    <span class="text-sm font-semibold">Sertakan gambar</span>
                  </label>
                </div>
              </div>

              <div class="grid grid-cols-1 md:grid-cols-${showOpsiPG ? 3 : 2} gap-5">
                ${showOpsiPG ? `
                  <div class="flex flex-col gap-2">
                    <label class="text-sm font-semibold text-text-sub-light dark:text-text-sub-dark">Jumlah Opsi PG</label>
                    <select
                      class="w-full rounded-lg border-border-light dark:border-border-dark bg-background-light dark:bg-background-dark/40 focus:border-primary focus:ring-primary h-11 px-4 text-sm"
                      onchange="window.__sp.updateSection('${s.id}','opsiPG',Number(this.value))"
                    >
                      ${[3, 4, 5]
                        .map((n) => `<option value="${n}" ${Number(s.opsiPG || 4) === n ? "selected" : ""}>${n} opsi</option>`)
                        .join("")}
                    </select>
                  </div>
                ` : ``}
                <div class="flex flex-col gap-2">
                  <label class="text-sm font-semibold text-text-sub-light dark:text-text-sub-dark">Jumlah Soal</label>
                  <input
                    type="number"
                    min="0"
                    class="w-full rounded-lg border-border-light dark:border-border-dark bg-background-light dark:bg-background-dark/40 focus:border-primary focus:ring-primary h-11 px-4 text-sm"
                    value="${safeText(Number(isObjective ? s.jumlahPG : s.jumlahIsian) || 0)}"
                    oninput="window.__sp.updateSection('${s.id}','${isObjective ? 'jumlahPG' : 'jumlahIsian'}',Number(this.value),false)"
                    onblur="window.__sp.updateSection('${s.id}','${isObjective ? 'jumlahPG' : 'jumlahIsian'}',Number(this.value),true)"
                  />
                </div>
                <div class="flex flex-col gap-2">
                  <div class="flex items-center gap-2">
                    <label class="text-sm font-semibold text-text-sub-light dark:text-text-sub-dark">Soal Berkonteks (Stimulus)</label>
                    <button type="button" class="inline-flex items-center justify-center size-7 rounded-md border border-border-light dark:border-border-dark bg-white dark:bg-surface-dark hover:bg-background-light dark:hover:bg-background-dark/60 transition-colors" onclick="window.__sp.openKonteksHelp(${jmlSoalKonteks})" title="Petunjuk">
                      <span class="material-symbols-outlined text-[16px]">help</span>
                    </button>
                  </div>
                  <label class="flex items-center gap-3 h-11 px-4 rounded-lg border border-border-light dark:border-border-dark bg-background-light dark:bg-background-dark/40 cursor-pointer">
                    <input type="checkbox" ${s.soalKonteks ? "checked" : ""} onchange="window.__sp.updateSection('${s.id}','soalKonteks',this.checked)" />
                    <span class="text-sm font-semibold">${s.soalKonteks ? "ON" : "OFF"} • 1 konteks untuk ${jmlSoalKonteks} soal</span>
                  </label>
                </div>
              </div>

              <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
                <div class="p-5 rounded-xl bg-background-light dark:bg-background-dark/30 border border-border-light dark:border-border-dark">
                  <div class="flex items-center justify-between gap-3 mb-4">
                    <div>
                      <div class="text-sm font-bold">Tingkat Kesulitan</div>
                      <div class="text-xs text-text-sub-light dark:text-text-sub-dark mt-1">Pilih tingkat kesulitan soal</div>
                    </div>
                  </div>
                  <div class="grid grid-cols-1 gap-3">
                    <select
                      class="w-full rounded-lg border-border-light dark:border-border-dark bg-white dark:bg-surface-dark focus:border-primary focus:ring-primary h-11 px-4 text-sm"
                      onchange="window.__sp.updateSection('${s.id}','tingkatKesulitan',this.value)"
                    >
                      <option value="mudah" ${diff === "mudah" ? "selected" : ""}>Mudah</option>
                      <option value="sedang" ${diff === "sedang" ? "selected" : ""}>Sedang</option>
                      <option value="sulit" ${diff === "sulit" ? "selected" : ""}>Sulit</option>
                      <option value="campuran" ${diff === "campuran" ? "selected" : ""}>Campuran</option>
                    </select>
                  </div>
                </div>
                <div class="p-5 rounded-xl bg-background-light dark:bg-background-dark/30 border border-border-light dark:border-border-dark">
                  <div class="flex items-center justify-between gap-3 mb-4">
                    <div>
                      <div class="text-sm font-bold">Dimensi Kognitif (Bloom)</div>
                      <div class="text-xs text-text-sub-light dark:text-text-sub-dark mt-1">Pilih cakupan dimensi kognitif</div>
                    </div>
                  </div>
                  <div class="grid grid-cols-1 gap-3">
                    <select
                      class="w-full rounded-lg border-border-light dark:border-border-dark bg-white dark:bg-surface-dark focus:border-primary focus:ring-primary h-11 px-4 text-sm"
                      onchange="window.__sp.updateSection('${s.id}','cakupanBloom',this.value)"
                    >
                      ${Object.entries(bloomPresets).map(([k, v]) => `
                        <option value="${k}" ${bloomPreset === k ? "selected" : ""}>${v.label}</option>
                      `).join("")}
                    </select>
                  </div>
                </div>
              </div>
            </div>
          </div>
        `;
      };

      const renderQuizLanding = () => {
        const sub = state.quizSubtab || "live";
        const shareTab = state.quizShareTab || "buat_link";
        const mapel = String(state.identity.mataPelajaran || "");
        const judulPaket = String(state.paket?.judul || "");
        const baseForSlug = mapel || judulPaket || 'soal';
        const slugDefault = baseForSlug.toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-+|-+$/g,'');
        if (!state.quizPublishForm.slug) state.quizPublishForm.slug = slugDefault || 'kelas';
        const tabs = `
          <div class="flex items-center justify-between gap-2 border-b border-border-light dark:border-border-dark px-4 pt-4 pb-3">
            <div class="flex items-center gap-2">
              <button onclick="window.__sp.toggleQuizPreviewPanel()" class="inline-flex items-center gap-2 h-10 px-3 rounded-lg text-sm font-bold border ${state.quizShowPreview ? 'bg-primary text-white border-primary' : 'bg-white dark:bg-surface-dark border-border-light dark:border-border-dark text-text-sub-light hover:bg-background-light dark:hover:bg-background-dark'}">
                <span class="material-symbols-outlined text-[18px]">quiz</span>
                Soal untuk Quiz
              </button>
              <button onclick="window.__sp.setQuizTab('live')" class="inline-flex items-center gap-2 h-10 px-3 rounded-lg text-sm font-bold border ${(!state.quizShowPreview && sub==='live')?'bg-primary text-white border-primary':'bg-white dark:bg-surface-dark border-border-light dark:border-border-dark text-text-sub-light hover:bg-background-light dark:hover:bg-background-dark'}">
                <span class="material-symbols-outlined text-[18px]">sports_esports</span>
                Quiz Live
              </button>
              <button onclick="window.__sp.setQuizTab('share')" class="inline-flex items-center gap-2 h-10 px-3 rounded-lg text-sm font-bold border ${(!state.quizShowPreview && sub==='share')?'bg-primary text-white border-primary':'bg-white dark:bg-surface-dark border-border-light dark:border-border-dark text-text-sub-light hover:bg-background-light dark:hover:bg-background-dark'}">
                <span class="material-symbols-outlined text-[18px]">link</span>
                Bagikan Link
              </button>
            </div>
            <div class="flex items-center gap-2">
              <span class="hidden md:inline-flex items-center gap-2 h-10 px-3 rounded-lg border border-amber-200 bg-amber-50 text-amber-900 text-sm font-bold">
                <span class="material-symbols-outlined text-[18px]">info</span>
                Quiz hanya mendukung soal Pilihan Ganda
              </span>
              <button class="inline-flex items-center gap-2 h-10 px-3 rounded-lg border bg-white dark:bg-surface-dark hover:bg-background-light dark:hover:bg-background-dark text-sm font-bold"
                onclick="window.__sp.openQuizHelp()"><span class="material-symbols-outlined text-[18px]">help</span><span class="hidden md:inline">Petunjuk</span></button>
              <button class="inline-flex items-center gap-2 h-10 px-3 rounded-lg border bg-white dark:bg-surface-dark hover:bg-background-light dark:hover:bg-background-dark text-sm font-bold"
                onclick="window.__sp.openQuizTutorial()" title="Tutorial">
                <span class="material-symbols-outlined text-[18px]">volume_up</span>
                <span class="hidden md:inline">Tutorial</span>
              </button>
            </div>
          </div>
        `;
        const haveQuestions = Array.isArray(state.questions) && state.questions.length > 0;
        const noAccess = `
          <div class="p-6 space-y-2">
            <div class="text-xl font-bold">Quiz Online</div>
            <div class="rounded-lg border border-amber-200 bg-amber-50 text-amber-900 p-4 text-sm">
              Akses fitur Quiz Online belum diaktifkan untuk akun Anda. Upgrade ke paket Pro (50rb) untuk akses modul Quiz Online dan Rekap Nilai. Hubungi Admin <a class="font-semibold underline text-blue-700" href="https://wa.me/6282174028646" target="_blank" rel="noopener">klik di sini</a>.
            </div>
          </div>
        `;
        const live = `
          <div class="p-6 space-y-3">
            <div>
              <div class="text-xl font-bold">Quiz Live</div>
              <div class="text-sm text-text-sub-light dark:text-text-sub-dark">${haveQuestions ? 'Mode interaktif: tampilkan soal di layar guru dan jalankan kuis di kelas. Cek dulu di “Soal untuk Quiz” untuk memastikan soal yang akan dipakai.' : 'Buat naskah soal dulu di menu Buat Soal (Identitas/Konfigurasi). Setelah ada, cek “Soal untuk Quiz”, lalu mulai Quiz Live atau Bagikan Link.'}</div>
            </div>
            <button ${haveQuestions ? '' : 'disabled'} onclick="openQuiz()" class="px-4 py-2 rounded-lg ${haveQuestions ? 'bg-primary hover:bg-blue-600 text-white' : 'bg-gray-200 text-gray-500 cursor-not-allowed'} font-bold">Mulai Quiz Live</button>
          </div>
        `;
        const pub = (() => {
          const f = state.quizPublishForm || {};
          const last = state.quizLastLink || '';
          return `
            <div class="p-6 space-y-5">
              <div>
                <div class="flex items-center gap-2">
                  <div class="text-xl font-bold">Bagikan Link</div>
                  <button type="button"
                    class="flex size-8 items-center justify-center rounded-full border border-border-light dark:border-border-dark bg-white dark:bg-surface-dark text-text-sub-light dark:text-text-sub-dark hover:bg-primary/10 hover:text-primary transition-colors"
                    title="Petunjuk Bagikan Link"
                    onclick="window.__sp.openBagikanLinkHelp()"
                  >
                    <span class="material-symbols-outlined text-[18px]">help</span>
                  </button>
                </div>
                <div class="text-sm text-text-sub-light mt-1">Buat tautan yang bisa diakses siswa tanpa login (untuk tugas/ujian mandiri).</div>
              </div>
              <div id="modalBagikanLinkHelp" class="fixed inset-0 hidden items-center justify-center" style="display:none; background: rgba(0,0,0,0.5); z-index:50;">
                <div class="bg-white dark:bg-surface-dark rounded-xl border border-border-light dark:border-border-dark shadow-xl w-[92vw] max-w-[800px] max-height-[85vh] overflow-auto">
                  <div class="p-5 border-b border-border-light dark:border-border-dark flex items-center justify-between">
                    <div class="font-bold text-lg flex items-center gap-2"><span class="material-symbols-outlined">help</span> Petunjuk Bagikan Link</div>
                    <button class="size-9 rounded-lg border bg-white dark:bg-surface-dark" onclick="window.__sp.closeBagikanLinkHelp()">&times;</button>
                  </div>
                  <div class="p-5 space-y-4 text-sm leading-relaxed">
                    <div>
                      <div class="font-bold mb-1">Fungsi</div>
                      <ul class="list-disc pl-5 space-y-1">
                        <li>Membuat link yang bisa dibuka siswa tanpa login.</li>
                        <li>Cocok untuk tugas/ujian mandiri di rumah atau di lab.</li>
                      </ul>
                    </div>
                    <div>
                      <div class="font-bold mb-1">Dua cara pakai</div>
                      <ol class="list-decimal pl-5 space-y-1">
                        <li>Tanpa Data Siswa: isi Pengaturan Link, lalu klik “Buat & Salin Link”. Siswa mengisi No Absen & Nama saat membuka link.</li>
                        <li>Dengan Data Siswa: upload CSV/TXT dulu, lalu klik “Buat & Salin Link” untuk menampilkan daftar link per siswa.</li>
                      </ol>
                    </div>
                    <div>
                      <div class="font-bold mb-1">Input wajib & tidak wajib</div>
                      <ul class="list-disc pl-5 space-y-1">
                        <li>Wajib: Nama Link.</li>
                        <li>Tidak wajib: Batas Waktu Link, Data Siswa, dan Opsi.</li>
                      </ul>
                    </div>
                    <div class="rounded-md border border-blue-200 bg-blue-50 text-blue-800 p-3 text-xs">
                      Tips: Jika ingin rekap nama otomatis dan link unik per siswa, unggah Data Siswa.
                    </div>
                  </div>
                </div>
              </div>
              <div id="modalBagikanLinkFieldHelp" class="fixed inset-0 hidden items-center justify-center" style="display:none; background: rgba(0,0,0,0.5); z-index:50;">
                <div class="bg-white dark:bg-surface-dark rounded-xl border border-border-light dark:border-border-dark shadow-xl w-[92vw] max-w-[680px] max-height-[85vh] overflow-auto">
                  <div class="p-5 border-b border-border-light dark:border-border-dark flex items-center justify-between">
                    <div class="font-bold text-lg flex items-center gap-2"><span class="material-symbols-outlined">help</span> <span id="blfhTitle">Petunjuk</span></div>
                    <button class="size-9 rounded-lg border bg-white dark:bg-surface-dark" onclick="window.__sp.closeBagikanLinkFieldHelp()">&times;</button>
                  </div>
                  <div id="blfhBody" class="p-5 text-sm leading-relaxed"></div>
                </div>
              </div>
              <div class="rounded-xl border bg-white dark:bg-surface-dark p-4 space-y-4">
                <div class="font-bold">A. Pengaturan Link</div>
                <div class="grid grid-cols-1 md:grid-cols-12 gap-4">
                  <div class="space-y-1.5 md:col-span-6">
                    <div class="flex items-center gap-2">
                      <label class="text-sm font-semibold">Nama Link (wajib)</label>
                      <button type="button" class="flex size-8 items-center justify-center rounded-full border border-border-light dark:border-border-dark bg-white dark:bg-surface-dark text-text-sub-light dark:text-text-sub-dark hover:bg-primary/10 hover:text-primary transition-colors" title="Petunjuk" onclick="window.__sp.openBagikanLinkFieldHelp('slug')">
                        <span class="material-symbols-outlined text-[18px]">help</span>
                      </button>
                    </div>
                    <input class="w-full h-11 px-3 rounded-lg border bg-white dark:bg-surface-dark" value="${safeText(f.slug || '')}" placeholder="contoh: biologi-kls10-pts" oninput="window.__sp.setQuizPublish('slug', this.value)">
                  </div>
                  <div class="space-y-1.5 md:col-span-2">
                    <div class="flex items-center gap-2">
                      <label class="text-sm font-semibold">Jumlah Siswa</label>
                      <button type="button" class="flex size-8 items-center justify-center rounded-full border border-border-light dark:border-border-dark bg-white dark:bg-surface-dark text-text-sub-light dark:text-text-sub-dark hover:bg-primary/10 hover:text-primary transition-colors" title="Petunjuk" onclick="window.__sp.openBagikanLinkFieldHelp('jumlah')">
                        <span class="material-symbols-outlined text-[18px]">help</span>
                      </button>
                    </div>
                    <input type="number" min="1" class="w-full h-11 px-3 rounded-lg border bg-white dark:bg-surface-dark" value="${Number(f.jumlah||32)}" placeholder="contoh: 32" oninput="window.__sp.setQuizPublish('jumlah', Number(this.value))">
                  </div>
                  <div class="space-y-1.5 md:col-span-4">
                    <div class="flex items-center gap-2">
                      <label class="text-sm font-semibold">Batas Waktu Link (opsional)</label>
                      <button type="button" class="flex size-8 items-center justify-center rounded-full border border-border-light dark:border-border-dark bg-white dark:bg-surface-dark text-text-sub-light dark:text-text-sub-dark hover:bg-primary/10 hover:text-primary transition-colors" title="Petunjuk" onclick="window.__sp.openBagikanLinkFieldHelp('expire')">
                        <span class="material-symbols-outlined text-[18px]">help</span>
                      </button>
                    </div>
                    ${(() => {
                      const parts = parseExpireParts(f.expire || '');
                      const hourOptions = Array.from({ length: 24 }, (_, i) => String(i).padStart(2, '0'))
                        .map(h => `<option value="${h}" ${h===parts.hh?'selected':''}>${h}</option>`).join('');
                      const minOptions = Array.from({ length: 60 }, (_, i) => String(i).padStart(2, '0'))
                        .map(m => `<option value="${m}" ${m===parts.mm?'selected':''}>${m}</option>`).join('');
                      return `
                        <div class="flex items-center gap-2 flex-wrap">
                          <input placeholder="31-12-2026" class="w-[12ch] h-11 px-3 rounded-lg border bg-white dark:bg-surface-dark" value="${safeText(parts.date)}" oninput="window.__sp.setQuizExpirePart('date', this.value)">
                          <select class="w-20 h-11 px-3 rounded-lg border bg-white dark:bg-surface-dark" onchange="window.__sp.setQuizExpirePart('hh', this.value)">${hourOptions}</select>
                          <select class="w-20 h-11 px-3 rounded-lg border bg-white dark:bg-surface-dark" onchange="window.__sp.setQuizExpirePart('mm', this.value)">${minOptions}</select>
                        </div>
                      `;
                    })()}
                  </div>
                </div>
              </div>
              <div class="rounded-xl border bg-white dark:bg-surface-dark p-4 space-y-3">
                <div class="flex items-center gap-2">
                  <div class="font-bold">B. Opsi</div>
                  <button type="button" class="flex size-8 items-center justify-center rounded-full border border-border-light dark:border-border-dark bg-white dark:bg-surface-dark text-text-sub-light dark:text-text-sub-dark hover:bg-primary/10 hover:text-primary transition-colors" title="Petunjuk" onclick="window.__sp.openBagikanLinkFieldHelp('opsi')">
                    <span class="material-symbols-outlined text-[18px]">help</span>
                  </button>
                </div>
                <label class="inline-flex items-center gap-2 text-sm">
                  <input type="checkbox" ${f.showSolution ? 'checked' : ''} onchange="window.__sp.setQuizPublish('showSolution', this.checked)">
                  <span>Tampilkan jawaban & pembahasan setelah submit</span>
                </label>
                <label class="inline-flex items-center gap-2 text-sm">
                  <input type="checkbox" ${(f.includeImages ?? true) ? 'checked' : ''} onchange="window.__sp.setQuizPublish('includeImages', this.checked)">
                  <span>Sertakan gambar (maks 5)</span>
                </label>
              </div>
              <div class="rounded-xl border bg-white dark:bg-surface-dark p-4 space-y-3">
                <div class="flex items-center gap-2">
                  <div class="font-bold">C. Data Siswa (tidak wajib)</div>
                  <button type="button" class="flex size-8 items-center justify-center rounded-full border border-border-light dark:border-border-dark bg-white dark:bg-surface-dark text-text-sub-light dark:text-text-sub-dark hover:bg-primary/10 hover:text-primary transition-colors" title="Petunjuk" onclick="window.__sp.openBagikanLinkFieldHelp('roster')">
                    <span class="material-symbols-outlined text-[18px]">help</span>
                  </button>
                </div>
                <div class="flex items-center gap-3 flex-wrap">
                  <button type="button" onclick="document.getElementById('rosterPicker').click()" class="px-3 h-9 rounded-lg border bg-white dark:bg-surface-dark">Upload CSV/TXT</button>
                  <button type="button" onclick="window.__sp.downloadRosterTemplate()" class="px-3 h-9 rounded-lg border bg-white dark:bg-surface-dark">Download Template TXT</button>
                  <div class="text-xs text-text-sub-light">${Array.isArray(f.roster) && f.roster.length ? `Terbaca ${f.roster.length} siswa` : 'Belum ada file diunggah'}</div>
                </div>
                ${Array.isArray(f.roster) && f.roster.length ? `
                  <div class="rounded-lg border border-green-200 bg-green-50 text-green-800 p-3 text-sm">
                    Berhasil diupload: terbaca <b>${f.roster.length}</b> siswa.
                  </div>
                ` : ``}
              </div>
              <div class="flex items-center gap-3">
                <button onclick="window.__sp.publishQuiz()" class="px-4 h-11 rounded-lg bg-green-600 hover:bg-green-700 text-white font-semibold">Buat & Salin Link</button>
                <div id="pubMsg" class="text-sm text-text-sub-light"></div>
              </div>
              ${(() => {
                const hasRoster = Array.isArray(f.roster) && f.roster.length > 0;
                const showLast = last && (!hasRoster || !state.quizLastPubId);
                return showLast ? `
                <div class="space-y-2">
                  <div class="text-xs text-text-sub-light">Link untuk siswa:</div>
                  <code class="block px-2.5 py-1 rounded-md border bg-white dark:bg-surface-dark font-mono text-xs">${last}</code>
                  <div>
                    <button type="button" data-link="${last}" onclick="navigator.clipboard.writeText(this.getAttribute('data-link')); this.textContent='Disalin'; setTimeout(()=>this.textContent='Salin',1500)" class="px-3 h-9 rounded-lg border bg-white dark:bg-surface-dark hover:bg-background-light dark:hover:bg-background-dark text-sm">Salin</button>
                  </div>
                </div>
                ` : ``;
              })()}
              ${Array.isArray(f.roster) && f.roster.length && state.quizLastPubId ? (() => {
                const rows = f.roster.map(r => {
                  const nm = decodeMaybeUrlText(String(r.nama || ''));
                  const link = `${location.origin}/soal_view.php?id=${encodeURIComponent(String(state.quizLastPubId))}&n=${encodeURIComponent(String(r.absen))}&name=${encodeURIComponent(nm)}`;
                  return `<tr><td class="border px-2 py-1 text-center">${r.absen}</td><td class="border px-2 py-1">${safeText(nm || '')}</td><td class="border px-2 py-1"><a href="${link}" target="_blank" class="text-blue-600 underline">${link}</a></td></tr>`;
                }).join('');
                return `
                  <div class="mt-3">
                    <div class="flex items-center justify-between gap-2">
                      <div class="text-sm font-semibold">Daftar Link Siswa (${f.roster.length})</div>
                      <div class="flex items-center gap-2">
                        <button class="px-4 h-9 rounded-lg bg-green-600 hover:bg-green-700 text-white font-bold" onclick="window.__sp.exportRosterLinksCSV(${Number(state.quizLastPubId)}, '${safeText(state.quizLastSlug||'')}')">Download CSV</button>
                        <button class="px-4 h-9 rounded-lg bg-green-600 hover:bg-green-700 text-white font-bold inline-flex items-center gap-2" onclick="window.__sp.exportRosterLinksPDF(${Number(state.quizLastPubId)}, '${safeText(state.quizLastSlug||'')}')">
                          <span class="material-symbols-outlined text-[18px]">picture_as_pdf</span>
                          Download PDF
                        </button>
                      </div>
                    </div>
                    <div class="overflow-auto mt-2">
                      <table class="min-w-full text-xs border">
                        <thead><tr><th class="border px-2 py-1">No Absen</th><th class="border px-2 py-1">Nama Siswa</th><th class="border px-2 py-1">Link</th></tr></thead>
                        <tbody>${rows}</tbody>
                      </table>
                    </div>
                  </div>`;
              })() : `<div class="mt-3 text-xs text-text-sub-light">Unggah daftar siswa untuk menghasilkan link unik per siswa.</div>`}
            </div>
          `;
        })();
        const res = (() => {
          const items = Array.isArray(state.quizPublications) ? state.quizPublications : [];
          const sel = state.quizSelectedSlug || (items[0]?.slug || '');
          const options = items.map(it => `<option value="${safeText(it.slug)}" ${it.slug===sel?'selected':''}>${safeText(it.slug)} • ${safeText(it.mapel)} • ${safeText(it.created_at || '')}</option>`).join('');
          const dataRows = Array.isArray(state.quizResults) ? state.quizResults.slice() : [];
          dataRows.sort((a,b)=>{
            const pa = a && a.total ? (a.score/a.total) : 0;
            const pb = b && b.total ? (b.score/b.total) : 0;
            if (pb !== pa) return pb - pa;
            return (a.absen||0) - (b.absen||0);
          });
          const pubObj = items.find(it => it.slug === sel);
          const exampleLink = pubObj ? `${location.origin}/soal_view.php?id=${encodeURIComponent(String(pubObj.id))}&n=1` : '';
          const roster = Array.isArray(state.quizPublishForm?.roster) ? state.quizPublishForm.roster : [];
          const nameMap = new Map(roster.map(r => [Number(r.absen), String(r.nama||'')]));
          const query = String(state.quizResultsQuery || '').trim().toLowerCase();
          const filteredRows = query
            ? dataRows.filter(r => {
                const ab = String(r?.absen ?? '').toLowerCase();
                const nmRaw = String(r?.nama || r?.name || '') || (nameMap.get(Number(r?.absen)) || '');
                const nm = decodeMaybeUrlText(nmRaw).toLowerCase();
                return ab.includes(query) || nm.includes(query);
              })
            : dataRows;
          const scores = dataRows.map(r => r && r.total ? Math.round((Number(r.score||0)/Number(r.total||1))*100) : 0);
          const avg = scores.length ? Math.round(scores.reduce((a,b)=>a+b,0)/scores.length) : 0;
          const max = scores.length ? Math.max(...scores) : 0;
          const loadedAtText = (() => {
            const raw = String(state.quizResultsLoadedAt || '').trim();
            if (!raw) return '';
            const d = new Date(raw);
            if (Number.isNaN(d.getTime())) return '';
            return d.toLocaleString('id-ID');
          })();
          const status = (() => {
            if (!pubObj) return { text: 'Belum dipilih', cls: 'bg-gray-100 text-gray-700 border-gray-200' };
            const active = Number(pubObj?.is_active ?? 1) === 1;
            const expRaw = String(pubObj?.expire_at || '').trim();
            const exp = expRaw ? new Date(expRaw.replace(' ', 'T')) : null;
            const expired = exp && !Number.isNaN(exp.getTime()) ? exp.getTime() < Date.now() : false;
            if (!active) return { text: 'Nonaktif', cls: 'bg-red-50 text-red-700 border-red-200' };
            if (expired) return { text: 'Kedaluwarsa', cls: 'bg-amber-50 text-amber-800 border-amber-200' };
            return { text: 'Aktif', cls: 'bg-green-50 text-green-800 border-green-200' };
          })();
          const top3 = dataRows.slice(0,3).map((r,i) => {
            const ab = Number(r.absen);
            const nmRaw = String(r?.nama || r?.name || '') || (nameMap.get(ab) || '');
            const nm = decodeMaybeUrlText(nmRaw);
            return { rank: i+1, absen: ab, name: nm, nilai: r && r.total ? Math.round((Number(r.score||0)/Number(r.total||1))*100) : 0 };
          });
          const rows = filteredRows.map((r, idx) => {
            const pct = r && r.total ? Math.round((Number(r.score||0)/Number(r.total||1))*100) : 0;
            const ab = Number(r.absen);
            const nmRaw = String(r?.nama || r?.name || '') || (nameMap.get(ab) || '');
            const nm = decodeMaybeUrlText(nmRaw);
            const trophy = idx < 3 ? `<span class="material-symbols-outlined text-amber-500 text-[18px] align-middle">trophy</span>` : '';
            const rowCls = idx % 2 ? 'bg-background-light/40 dark:bg-background-dark/30' : '';
            return `<tr>
              <td class="border px-3 py-2 text-center ${rowCls}">${idx+1} ${trophy}</td>
              <td class="border px-3 py-2 text-center ${rowCls}">${ab}</td>
              <td class="border px-3 py-2 ${rowCls}">${safeText(nm || '-')}</td>
              <td class="border px-3 py-2 text-center ${rowCls}">${pct}</td>
            </tr>`;
          }).join('');
          return `
            <div class="p-6 space-y-4">
              <div class="flex items-center gap-2">
                <div class="text-xl font-bold">Hasil Quiz</div>
                <button type="button"
                  class="flex size-8 items-center justify-center rounded-full border border-border-light dark:border-border-dark bg-white dark:bg-surface-dark text-text-sub-light dark:text-text-sub-dark hover:bg-primary/10 hover:text-primary transition-colors"
                  title="Petunjuk Hasil Quiz"
                  onclick="window.__sp.openHasilQuizHelp()"
                >
                  <span class="material-symbols-outlined text-[18px]">help</span>
                </button>
                <span class="ml-1 inline-flex items-center px-2.5 h-7 rounded-full border text-xs font-semibold ${status.cls}">${status.text}</span>
              </div>
              <div id="modalHasilQuizHelp" class="fixed inset-0 hidden items-center justify-center" style="display:none; background: rgba(0,0,0,0.5); z-index:50;">
                <div class="bg-white dark:bg-surface-dark rounded-xl border border-border-light dark:border-border-dark shadow-xl w-[92vw] max-w-[800px] max-height-[85vh] overflow-auto">
                  <div class="p-5 border-b border-border-light dark:border-border-dark flex items-center justify-between">
                    <div class="font-bold text-lg flex items-center gap-2"><span class="material-symbols-outlined">help</span> Petunjuk Hasil Quiz</div>
                    <button class="size-9 rounded-lg border bg-white dark:bg-surface-dark" onclick="window.__sp.closeHasilQuizHelp()">&times;</button>
                  </div>
                  <div class="p-5 space-y-4 text-sm leading-relaxed">
                    <div>
                      <div class="font-bold mb-1">Fungsi</div>
                      <ul class="list-disc pl-5 space-y-1">
                        <li>Melihat nilai dan peringkat siswa dari link yang sudah dibagikan.</li>
                        <li>Mengunduh laporan untuk arsip.</li>
                      </ul>
                    </div>
                    <div>
                      <div class="font-bold mb-1">Cara cek hasil</div>
                      <ol class="list-decimal pl-5 space-y-1">
                        <li>Pilih Nama Link pada dropdown.</li>
                        <li>Klik Muat untuk mengambil data nilai terbaru.</li>
                        <li>Daftar Nama Link akan muncul otomatis setelah Anda membuat link.</li>
                      </ol>
                    </div>
                    <div>
                      <div class="font-bold mb-1">Fungsi tombol</div>
                      <ul class="list-disc pl-5 space-y-1">
                        <li>Muat: mengambil hasil dari link yang dipilih.</li>
                        <li>Ikon PDF: unduh laporan PDF.</li>
                        <li>Ikon Tabel: unduh laporan CSV (untuk Excel).</li>
                      </ul>
                    </div>
                    <div class="rounded-md border border-amber-200 bg-amber-50 text-amber-900 p-3 text-xs">
                      Catatan: Data hasil dan gambar di server akan dihapus otomatis 14 hari setelah publish. Segera unduh laporan untuk arsip.
                    </div>
                  </div>
                </div>
              </div>
              <div class="rounded-xl border bg-white dark:bg-surface-dark p-4 space-y-3">
                <div class="flex flex-col md:flex-row md:items-center gap-2">
                  <div class="flex-1 min-w-0">
                    <div class="text-xs text-text-sub-light mb-1">Pilih Link Quiz</div>
                    <select id="selPub" class="w-full h-11 px-3 rounded-lg border bg-white dark:bg-surface-dark">${options}</select>
                  </div>
                  <div class="flex items-center gap-2 flex-wrap">
                    <button onclick="window.__sp.loadResults()" class="px-4 h-11 rounded-lg border bg-white dark:bg-surface-dark">Tampilkan Hasil Quiz</button>
                    <button ${pubObj ? `onclick="window.__sp.exportResultsPDF('${safeText(pubObj.slug)}')"` : 'disabled'} title="Unduh PDF"
                      class="flex items-center justify-center h-11 w-11 rounded-lg ${pubObj ? 'bg-green-600 hover:bg-green-700 text-white' : 'bg-gray-200 text-gray-500 cursor-not-allowed'}">
                      <span class="material-symbols-outlined">picture_as_pdf</span>
                    </button>
                    <button ${pubObj ? `onclick="window.__sp.exportResultsCSV('${safeText(pubObj.slug)}')"` : 'disabled'} title="Unduh CSV (Excel)"
                      class="flex items-center justify-center h-11 w-11 rounded-lg ${pubObj ? 'bg-green-600 hover:bg-green-700 text-white' : 'bg-gray-200 text-gray-500 cursor-not-allowed'}">
                      <span class="material-symbols-outlined">table</span>
                    </button>
                  </div>
                </div>
                <div class="flex flex-col md:flex-row md:items-center gap-2">
                  <div class="flex-1 min-w-0">
                    <input class="w-full h-10 px-3 rounded-lg border bg-white dark:bg-surface-dark text-sm" placeholder="Cari No Absen atau Nama Siswa..." value="${safeText(state.quizResultsQuery || '')}" oninput="window.__sp.setQuizResultsQuery(this.value)">
                  </div>
                </div>
                <div class="text-xs rounded-md border border-amber-200 bg-amber-50 text-amber-800 p-3">
                  Data hasil dan gambar di server akan dihapus otomatis 14 hari setelah publish. Segera unduh laporan untuk arsip.
                </div>
              </div>
              <div class="grid grid-cols-1 md:grid-cols-4 gap-3 text-sm">
                <div class="rounded-lg border bg-white dark:bg-surface-dark p-4">
                  <div class="text-xs text-text-sub-light">Mata Pelajaran</div>
                  <div class="font-bold">${safeText(state.quizResultsMapel || '-')}</div>
                </div>
                <div class="rounded-lg border bg-white dark:bg-surface-dark p-4">
                  <div class="text-xs text-text-sub-light">Nilai Rata-rata</div>
                  <div class="font-bold">${avg}</div>
                </div>
                <div class="rounded-lg border bg-white dark:bg-surface-dark p-4">
                  <div class="text-xs text-text-sub-light">Nilai Tertinggi</div>
                  <div class="font-bold">${max}</div>
                </div>
                <div class="rounded-lg border bg-white dark:bg-surface-dark p-4">
                  <div class="text-xs text-text-sub-light">Respon Masuk</div>
                  <div class="font-bold">${dataRows.length}</div>
                </div>
              </div>
              <div class="rounded-lg border bg-white dark:bg-surface-dark p-4">
                <div class="font-bold mb-2">3 Besar</div>
                <div class="space-y-1">
                  ${top3.map(t => `<div class="flex items-center gap-2"><span class="material-symbols-outlined text-amber-500">trophy</span><span>#${t.rank}</span><span>• No ${t.absen}</span><span>• ${safeText(t.name||'-')}</span><span class="ml-auto font-bold">${t.nilai}</span></div>`).join('')}
                </div>
              </div>
              <div class="overflow-auto">
                <table class="min-w-full text-sm border">
                  <thead class="bg-background-light dark:bg-background-dark sticky top-0 z-10">
                    <tr><th class="border px-3 py-2 text-center">Peringkat</th><th class="border px-3 py-2 text-center">No Absen</th><th class="border px-3 py-2 text-left">Nama Siswa</th><th class="border px-3 py-2 text-center">Nilai</th></tr>
                  </thead>
                  <tbody>${rows || `<tr><td colspan="4" class="border px-3 py-6 text-center text-text-sub-light">${query ? 'Tidak ada data yang cocok.' : 'Belum ada hasil.'}</td></tr>`}</tbody>
                </table>
              </div>
            </div>
          `;
        })();
        const shareNav = `
          <div class="px-4 pt-4">
            <div class="inline-flex rounded-lg border bg-white dark:bg-surface-dark overflow-x-auto no-scrollbar">
              <button class="${shareTab==='buat_link'?'bg-primary text-white':'bg-white dark:bg-surface-dark'} px-4 h-10 rounded-lg text-sm font-bold whitespace-nowrap" onclick="window.__sp.setQuizShareTab('buat_link')">1. Buat Link</button>
              <button class="${shareTab==='hasil'?'bg-primary text-white':'bg-white dark:bg-surface-dark'} px-4 h-10 rounded-lg text-sm font-bold whitespace-nowrap" onclick="window.__sp.setQuizShareTab('hasil')">2. Hasil Quiz</button>
            </div>
          </div>
        `;
        const quizHelpModal = `
          <div id="modalQuizHelp" class="fixed inset-0 hidden items-center justify-center" style="display:none; background: rgba(0,0,0,0.5); z-index:50;">
            <div class="bg-white dark:bg-surface-dark rounded-xl border border-border-light dark:border-border-dark shadow-xl w-[92vw] max-w-[760px] max-h-[85vh] overflow-auto">
              <div class="p-5 border-b border-border-light dark:border-border-dark flex items-center justify-between">
                <div class="font-bold text-lg flex items-center gap-2"><span class="material-symbols-outlined">help</span> Fungsi Menu Quiz</div>
                <button class="size-9 rounded-lg border bg-white dark:bg-surface-dark" onclick="window.__sp.closeQuizHelp()">&times;</button>
              </div>
              <div class="p-5 space-y-3 text-sm leading-relaxed">
                <ol class="list-decimal pl-5 space-y-2">
                  <li>Soal untuk Quiz: menampilkan pratinjau soal Pilihan Ganda yang akan dipakai untuk Quiz Live dan Bagikan Link. Pastikan sudah ada naskah soal terlebih dahulu.</li>
                  <li>Quiz Live: dipakai saat kuis berlangsung di kelas. Guru menampilkan dan menjalankan soal secara langsung.</li>
                  <li>Bagikan Link → 1. Buat Link: dipakai untuk membuat tautan yang bisa dibuka siswa (tugas/ujian mandiri tanpa login).</li>
                  <li>Bagikan Link → 2. Hasil Quiz: dipakai untuk melihat nilai dan peringkat siswa dari link yang sudah dibagikan.</li>
                </ol>
                <div class="rounded-md border border-blue-200 bg-blue-50 text-blue-800 p-3 text-xs">
                  Catatan: Link dan data hasil akan otomatis dihapus 14 hari setelah publish. Segera simpan arsip JSON/ZIP.
                </div>
              </div>
            </div>
          </div>
          <div id="modalQuizTutorial" class="fixed inset-0 hidden items-center justify-center" style="display:none; background: rgba(0,0,0,0.5); z-index:50;">
              <div class="bg-white dark:bg-surface-dark rounded-xl border border-border-light dark:border-border-dark shadow-xl w-[92vw] max-w-[900px] max-h-[85vh] overflow-auto">
                <div class="p-5 border-b border-border-light dark:border-border-dark flex items-center justify-between">
                  <div class="font-bold text-lg flex items-center gap-2"><span class="material-symbols-outlined">volume_up</span> Tutorial Quiz</div>
                  <button class="size-9 rounded-lg border bg-white dark:bg-surface-dark" onclick="window.__sp.closeQuizTutorial()">&times;</button>
                </div>
                <div class="p-5">
                  <div class="grid grid-cols-1 md:grid-cols-2 gap-2" id="quizTutorialList"></div>
                  <div class="text-xs text-text-sub-light dark:text-text-sub-dark mt-3">Catatan: audio menyusul.</div>
                </div>
              </div>
            </div>
            <div id="modalMAHelp1" class="fixed inset-0 hidden items-center justify-center" style="display:none; background: rgba(0,0,0,0.5); z-index:50;">
              <div class="bg-white dark:bg-surface-dark rounded-xl border border-border-light dark:border-border-dark shadow-xl w-[92vw] max-w-[760px] max-h-[85vh] overflow-auto">
                <div class="p-5 border-b border-border-light dark:border-border-dark flex items-center justify-between">
                  <div class="font-bold text-lg flex items-center gap-2"><span class="material-symbols-outlined">help</span> Petunjuk Modul Ajar • Langkah 1</div>
                  <button class="size-9 rounded-lg border bg-white dark:bg-surface-dark" onclick="window.__sp.closeMAHelp1()">&times;</button>
                </div>
                <div class="p-5 space-y-3 text-sm leading-relaxed">
                  <ol class="list-decimal pl-5 space-y-2">
                    <li>Isi Nama Guru dan Institusi.</li>
                    <li>Pilih Kurikulum, Jenjang, Fase, dan Kelas.</li>
                    <li>Isi Mata Pelajaran dan Materi Pokok/Judul Modul.</li>
                    <li>Pastikan identitas lengkap sebelum lanjut ke Langkah 2.</li>
                  </ol>
                </div>
              </div>
            </div>
            <div id="modalMAHelp2" class="fixed inset-0 hidden items-center justify-center" style="display:none; background: rgba(0,0,0,0.5); z-index:50;">
              <div class="bg-white dark:bg-surface-dark rounded-xl border border-border-light dark:border-border-dark shadow-xl w-[92vw] max-w-[760px] max-h-[85vh] overflow-auto">
                <div class="p-5 border-b border-border-light dark:border-border-dark flex items-center justify-between">
                  <div class="font-bold text-lg flex items-center gap-2"><span class="material-symbols-outlined">help</span> Petunjuk Modul Ajar • Langkah 2</div>
                  <button class="size-9 rounded-lg border bg-white dark:bg-surface-dark" onclick="window.__sp.closeMAHelp2()">&times;</button>
                </div>
                <div class="p-5 space-y-3 text-sm leading-relaxed">
                  <ol class="list-decimal pl-5 space-y-2">
                    <li>Isi jumlah pertemuan, durasi per pertemuan, jumlah siswa.</li>
                    <li>Pilih Model Pembelajaran yang relevan.</li>
                    <li>Pilih Dimensi Profil Pelajar Pancasila (min. satu).</li>
                    <li>Klik Buat Modul Ajar Sekarang untuk membuat dokumen.</li>
                    <li>Gunakan Download .docx untuk menyimpan hasil.</li>
                  </ol>
                </div>
              </div>
            </div>
            <div id="modalMAHelp3" class="fixed inset-0 hidden items-center justify-center" style="display:none; background: rgba(0,0,0,0.5); z-index:50;">
              <div class="bg-white dark:bg-surface-dark rounded-xl border border-border-light dark:border-border-dark shadow-xl w-[92vw] max-w-[760px] max-h-[85vh] overflow-auto">
                <div class="p-5 border-b border-border-light dark:border-border-dark flex items-center justify-between">
                  <div class="font-bold text-lg flex items-center gap-2"><span class="material-symbols-outlined">help</span> Petunjuk Modul Ajar • Langkah 3</div>
                  <button class="size-9 rounded-lg border bg-white dark:bg-surface-dark" onclick="window.__sp.closeMAHelp3()">&times;</button>
                </div>
                <div class="p-5 space-y-3 text-sm leading-relaxed">
                  <ol class="list-decimal pl-5 space-y-2">
                    <li>Tab ini menampilkan hasil Modul Ajar dalam format preview.</li>
                    <li>Jika hasil belum ada, kembali ke Langkah 2 lalu klik Buat Modul Ajar Sekarang.</li>
                    <li>Gunakan Download .docx untuk menyimpan dokumen Word.</li>
                    <li>Gunakan Download PDF untuk menyimpan versi PDF (jika tersedia).</li>
                    <li>Jika dokumen panjang, tunggu sampai proses selesai dan jangan tutup halaman saat loading.</li>
                  </ol>
                </div>
              </div>
            </div>
          `;
        const previewInline = state.quizShowPreview ? `
          <div class="px-4 pt-3 pb-6">
            ${buildQuizItemsHTMLInline()}
            <div class="mt-5 flex items-center gap-2">
              <button class="inline-flex items-center gap-2 h-10 px-5 rounded-lg bg-green-600 hover:bg-green-700 text-white text-sm font-bold"
                onclick="window.__sp.setPreviewTab('naskah'); window.__sp.setView('preview')">
                <span class="material-symbols-outlined text-[18px]">auto_awesome</span>
                Buka Naskah Soal
              </button>
            </div>
          </div>
        ` : ``;
        const body = !HAS_QUIZ_ACCESS
          ? noAccess
          : (sub === 'share'
              ? `${shareNav}${shareTab==='hasil' ? res : pub}`
              : (state.quizShowPreview ? previewInline : live));
        return `
          <div class="bg-surface-light dark:bg-surface-dark rounded-xl border border-border-light dark:border-border-dark shadow-sm overflow-hidden">
            ${tabs}
            ${body}
            ${quizHelpModal}
          </div>
        `;
      };
      function openQuizHelp(){ const m = el('modalQuizHelp'); if (m) m.style.display='flex'; }
      function closeQuizHelp(){ const m = el('modalQuizHelp'); if (m) m.style.display='none'; }
      const QUIZ_TUTORIALS = [
        { id: 'q1', title: 'Soal untuk Quiz (Pratinjau)', src: 'tutorial/quiz/quiz1.wav' },
        { id: 'q2', title: 'Quiz Live (Mulai & kontrol)', src: 'tutorial/quiz/quiz2.wav' },
        { id: 'q3', title: 'Bagikan Link (Buat link + Data Siswa)', src: 'tutorial/quiz/quiz3.wav' },
        { id: 'q4', title: 'Opsi & Pengaturan Link (expire, jumlah, gambar, pembahasan)', src: 'tutorial/quiz/quiz4.wav' },
        { id: 'q5', title: 'Hasil Quiz (lihat, filter, unduh)', src: 'tutorial/quiz/quiz5.wav' },
        { id: 'q6', title: 'Catatan 14 hari & Troubleshooting', src: 'tutorial/quiz/quiz6.wav' },
      ];
      function openQuizTutorial() {
        const m = el('modalQuizTutorial'); if (!m) return;
        const list = el('quizTutorialList');
        if (list) {
          list.innerHTML = QUIZ_TUTORIALS.map((it, i) => `
            <div class="w-full h-full rounded-lg border bg-white dark:bg-surface-dark p-4">
              <div class="flex items-start justify-between gap-3">
                <div>
                  <div class="text-xs text-text-sub-light">#${i + 1}</div>
                  <div class="font-bold">${safeText(it.title)}</div>
                </div>
                ${it.src ? `` : `<div class="text-xs px-2 py-1 rounded-full bg-gray-100 dark:bg-gray-800 text-gray-500">Segera hadir</div>`}
              </div>
              ${it.src ? `<div class="mt-3"><audio class="w-full rounded-lg border bg-white dark:bg-surface-dark" controls preload="none" src="${safeText(it.src)}"></audio></div>` : ``}
            </div>
          `).join('');
        }
        m.classList.remove('hidden');
        m.classList.add('flex');
        m.style.display = 'flex';
      }
      function closeQuizTutorial() {
        const m = el('modalQuizTutorial');
        if (m) {
          m.style.display = 'none';
          m.classList.add('hidden');
          m.classList.remove('flex');
        }
        try {
          document.querySelectorAll('#quizTutorialList audio').forEach(a => {
            try { a.pause(); } catch {}
            try { a.currentTime = 0; } catch {}
          });
        } catch {}
      }

      const openQuiz = () => {
        if (!Array.isArray(state.questions) || state.questions.length === 0) {
          alert("Buat naskah soal terlebih dahulu.");
          return;
        }
        document.getElementById('quizOverlay')?.classList.remove('hidden');
        state.quiz.idx = 0;
        state.quiz.reveal = false;
        renderQuizContent();
      };
      const closeQuiz = () => {
        document.getElementById('quizOverlay')?.classList.add('hidden');
      };
      const renderQuizContent = () => {
        const q = state.questions[state.quiz.idx];
        const body = document.getElementById('quizBody');
        if (!q || !body) return;
        if (q.type === 'pg' || q.type === 'pg_kompleks') {
          const selected = state.quiz.answered[state.quiz.idx];
          const optsHtml = q.options.map((opt, i) => {
            const correct = Array.isArray(q.answer) ? (q.answer || []).includes(i) : i === (q.answer || 0);
            const chosen = Array.isArray(selected) ? (selected || []).includes(i) : selected === i;
            const base = "block w-full text-left px-3 py-2 border rounded transition-colors";
            let stateCls = "";
            if (state.quiz.reveal) {
              if (correct) stateCls = " bg-green-50 border-green-300 text-green-700";
              else if (chosen) stateCls = " bg-red-50 border-red-300 text-red-700";
              else stateCls = " opacity-70";
            }
            const dis = state.quiz.reveal ? "disabled" : "";
            return `<button ${dis} onclick="handleQuizAnswer(${i})" class="${base}${stateCls}">${String.fromCharCode(65 + i)}. ${safeText(opt)}</button>`;
          }).join('');
          body.innerHTML = `
            <div class="p-6">
              <div class="font-bold mb-4">${safeText(q.question)}</div>
              <div class="space-y-2">
                ${optsHtml}
              </div>
              ${state.quiz.reveal ? `<div class="mt-4 font-bold text-green-600">Kunci: ${Array.isArray(q.answer) ? q.answer.map(n => String.fromCharCode(65 + n)).join(', ') : String.fromCharCode(65 + (q.answer || 0))}</div>` : ``}
            </div>
          `;
        } else {
          body.innerHTML = `
            <div class="p-6">
              <div class="font-bold mb-4">${safeText(q.question)}</div>
              <div class="text-sm text-text-sub-light">Jawaban ditampilkan setelah diungkap</div>
              ${state.quiz.reveal ? `<div class="mt-4 font-bold text-green-600">Kunci: ${safeText(String(q.answer || ''))}</div>` : ``}
            </div>
          `;
        }
        document.getElementById('quizMeta').textContent = `${state.quiz.idx + 1} / ${state.questions.length}`;
      };
      const handleQuizAnswer = (i) => {
        state.quiz.answered[state.quiz.idx] = i;
        state.quiz.reveal = true;
        renderQuizContent();
      };
      const setQuizTab = (t) => {
        const v = String(t || '').trim();
        state.quizShowPreview = false;
        if (v === 'results') {
          state.quizSubtab = 'share';
          state.quizShareTab = 'hasil';
        } else if (v === 'publish' || v === 'share') {
          state.quizSubtab = 'share';
          if (!state.quizShareTab) state.quizShareTab = 'buat_link';
        } else {
          state.quizSubtab = 'live';
        }
        state.quizShareTab = state.quizShareTab || 'buat_link';
        if (state.quizShareTab !== 'buat_link' && state.quizShareTab !== 'hasil') state.quizShareTab = 'buat_link';
        saveDebounced(true);
        render();
      };
      const setQuizShareTab = async (t) => {
        state.quizSubtab = 'share';
        state.quizShowPreview = false;
        const v = String(t || '').trim();
        state.quizShareTab = (v === 'hasil') ? 'hasil' : 'buat_link';
        saveDebounced(true);
        if (state.quizShareTab === 'hasil') {
          try { await loadPublications(); } catch {}
        }
        render();
      };
      const setQuizPublish = (k, v) => {
        state.quizPublishForm = state.quizPublishForm || {};
        state.quizPublishForm[k] = v;
        saveDebounced(false);
      };
      const parseExpireParts = (s) => {
        const raw0 = String(s || '').trim();
        if (!raw0) return { date: '', hh: '23', mm: '59' };
        const raw = raw0.replace('T', ' ');
        const mDate = raw.match(/(\d{2}-\d{2}-\d{4}|\d{4}-\d{2}-\d{2})/);
        const mTime = raw.match(/(\d{1,2}):(\d{2})/);
        let date = mDate ? String(mDate[1] || '').trim() : raw0;
        let hh = '23';
        let mm = '59';
        if (mTime) {
          hh = String(mTime[1] ?? '23').padStart(2, '0');
          mm = String(mTime[2] ?? '59').padStart(2, '0');
        }
        if (/^\d{4}-\d{2}-\d{2}$/.test(date)) {
          const parts = date.split('-');
          date = `${parts[2]}-${parts[1]}-${parts[0]}`;
        }
        return { date, hh, mm };
      };
      const setQuizExpirePart = (part, val) => {
        state.quizPublishForm = state.quizPublishForm || {};
        const cur = parseExpireParts(state.quizPublishForm.expire || '');
        let v = String(val ?? '').trim();
        if (part === 'date') v = String(v.split(/\s+/)[0] || '').trim().slice(0, 10);
        const next = { ...cur };
        if (part === 'date') next.date = v;
        if (part === 'hh') next.hh = String(v || '23').padStart(2, '0');
        if (part === 'mm') next.mm = String(v || '59').padStart(2, '0');
        if (!next.date) {
          state.quizPublishForm.expire = '';
        } else {
          const hh = String(next.hh || '23').padStart(2, '0');
          const mm = String(next.mm || '59').padStart(2, '0');
          state.quizPublishForm.expire = `${next.date} ${hh}:${mm}`;
        }
        saveDebounced(false);
      };
      const setQuizResultsQuery = (q) => {
        state.quizResultsQuery = String(q || "");
        saveDebounced(false);
        render();
      };
      const parseRosterText = (text) => {
        const lines = String(text||'').split(/\r?\n/).map(l=>l.trim()).filter(Boolean);
        const rows = [];
        for (const l of lines) {
          const parts = l.split(/[,\t;]+/).map(s=>s.trim()).filter(Boolean);
          if (parts.length >= 2) {
            const ab = Number(parts[0]);
            const nm = parts.slice(1).join(' ');
            if (Number.isFinite(ab) && ab > 0 && nm) rows.push({ absen: ab, nama: nm });
          }
        }
        return rows;
      };
      const downloadRosterTemplate = () => {
        const lines = [
          '1,Ahmad Fauzan',
          '2,Siti Aisyah',
          '3,Budi Setiawan',
          '4,Citra Dewi',
        ];
        const blob = new Blob([lines.join('\n')], { type: 'text/plain;charset=utf-8' });
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'Template_DaftarSiswa.txt';
        a.click();
        URL.revokeObjectURL(a.href);
      };
      const handleRosterSelected = (evt) => {
        const f = evt.target?.files?.[0];
        if (!f) return;
        const reader = new FileReader();
        reader.onload = (e) => {
          const txt = String(e.target?.result || '');
          const rows = parseRosterText(txt);
          state.quizPublishForm = state.quizPublishForm || {};
          state.quizPublishForm.roster = rows;
          if (rows.length > 0) state.quizPublishForm.jumlah = rows.length;
          saveDebounced(false);
          render();
          if (!rows.length) alert('Tidak ada data valid pada file.');
        };
        reader.readAsText(f);
      };
      const exportRosterLinksCSV = (pubId, slug) => {
        const roster = Array.isArray(state.quizPublishForm?.roster) ? state.quizPublishForm.roster : [];
        if (!pubId || roster.length === 0) return;
        const base = `${location.origin}/soal_view.php?id=${encodeURIComponent(String(pubId))}`;
        const lines = ['No Absen,Nama Siswa,Link'];
        for (const r of roster) {
          const nm = decodeMaybeUrlText(String(r.nama || ''));
          const link = `${base}&n=${encodeURIComponent(String(r.absen))}&name=${encodeURIComponent(nm)}`;
          lines.push(`${r.absen},"${String(nm || '').replace(/"/g,'""')}",${link}`);
        }
        const blob = new Blob([lines.join('\n')], { type: 'text/csv;charset=utf-8' });
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = `DaftarLink_${slug||'publikasi'}.csv`;
        a.click();
        URL.revokeObjectURL(a.href);
      };
      const exportRosterLinksPDF = async (pubId, slug) => {
        const roster = Array.isArray(state.quizPublishForm?.roster) ? state.quizPublishForm.roster : [];
        if (!pubId || roster.length === 0) return;
        await ensureJsPDF();
        const { jsPDF } = window.jspdf;
        const doc = new jsPDF('p', 'pt', 'a4');
        const pageW = doc.internal.pageSize.getWidth();
        const margin = 40;
        const base = `${location.origin}/soal_view.php?id=${encodeURIComponent(String(pubId))}`;
        const title = 'Daftar Link Siswa (Quiz Online)';
        const subtitle = `${String(slug || 'publikasi')} • ${new Date().toLocaleString()}`;
        doc.setFont('helvetica', 'bold');
        doc.setFontSize(14);
        doc.text(title, margin, 40);
        doc.setFont('helvetica', 'normal');
        doc.setFontSize(10);
        doc.setTextColor(90, 90, 90);
        doc.text(subtitle, margin, 58);
        doc.setTextColor(0, 0, 0);
        const body = roster.map(r => {
          const nm = decodeMaybeUrlText(String(r.nama || ''));
          const link = `${base}&n=${encodeURIComponent(String(r.absen))}&name=${encodeURIComponent(nm)}`;
          return [String(r.absen), nm, link];
        });
        doc.autoTable({
          head: [['No Absen', 'Nama Siswa', 'Link']],
          body,
          startY: 74,
          margin: { left: margin, right: margin },
          styles: { font: 'helvetica', fontSize: 9, cellPadding: 4, lineWidth: 0.5, lineColor: [120,120,120], textColor: [0,0,0], overflow: 'linebreak' },
          headStyles: { fillColor: [217,217,217], textColor: [0,0,0], fontStyle: 'bold' },
          columnStyles: {
            0: { cellWidth: 58 },
            1: { cellWidth: 150 },
            2: { cellWidth: pageW - (margin * 2) - 58 - 150 },
          },
          didDrawCell: (data) => {
            if (data.section !== 'body') return;
            if (data.column.index !== 2) return;
            const url = body[data.row.index]?.[2];
            if (!url) return;
            const c = data.cell;
            doc.link(c.x, c.y, c.width, c.height, { url: String(url) });
          },
        });
        doc.save(`DaftarLink_${slug || 'publikasi'}.pdf`);
      };
      const seedQuizResults = async (slug, count = 30, overwrite = true) => {
        const s = String(slug || '').trim();
        if (!s) return;
        try {
          const res = await fetch('api/seed_quiz_results.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ slug: s, count: Number(count || 30), overwrite: overwrite ? 1 : 0 }),
          });
          const js = await res.json().catch(() => null);
          if (!res.ok || !js || !js.ok) {
            alert('Gagal membuat dummy hasil.');
            return;
          }
          await loadResults();
        } catch {
          alert('Gagal membuat dummy hasil.');
        }
      };
      const publishQuiz = async () => {
        const mapel = String(state.identity.mataPelajaran || "").trim();
        if (!mapel) { alert("Lengkapi mata pelajaran di Identitas."); return; }
        if (!Array.isArray(state.questions) || state.questions.length === 0) {
          alert("Buat naskah soal terlebih dahulu.");
          return;
        }
        const items = Array.isArray(state.questions) ? state.questions : [];
        const pg = items.filter(q => q && q.type === 'pg' && Array.isArray(q.options) && q.options.length >= 3);
        if (pg.length === 0) { alert("Hanya mendukung PG. Tidak ada PG pada paket ini."); return; }
        const includeImages = state.quizPublishForm?.includeImages ?? true;
        const imageCount = includeImages ? pg.reduce((acc, q) => acc + (String(q.image || '').trim() ? 1 : 0), 0) : 0;
        if (includeImages && imageCount > 5) {
          alert(`Maksimal 5 gambar per paket publish. Saat ini terdeteksi ${imageCount} gambar.`);
          return;
        }
        const payload = pg.map(q => ({ question: String(q.question||''), options: q.options.map(x=>String(x||'')) }));
        const answer_key = pg.map(q => Number(Array.isArray(q.answer)? q.answer[0] : q.answer || 0));
        const slug = String(state.quizPublishForm?.slug || "").trim().toLowerCase().replace(/[^a-z0-9\-]+/g,'-').replace(/^-+|-+$/g,'');
        let expireRaw = String(state.quizPublishForm?.expire || "").trim();
        const addDays = (d, days) => {
          const x = new Date(d.getTime());
          x.setDate(x.getDate() + Number(days || 0));
          return x;
        };
        const pad2 = (n) => String(Number(n) || 0).padStart(2, '0');
        const fmtDDMMYYYY = (d) => `${pad2(d.getDate())}-${pad2(d.getMonth() + 1)}-${d.getFullYear()}`;
        const maxExpireDate = (() => {
          const d = addDays(new Date(), 14);
          d.setHours(23, 59, 0, 0);
          return d;
        })();
        const parseExpireToDate = (s) => {
          const v = String(s || '').trim().replace('T', ' ');
          const m1 = v.match(/^(\d{2})-(\d{2})-(\d{4})\s+(\d{1,2}):(\d{2})$/);
          if (m1) return new Date(Number(m1[3]), Number(m1[2]) - 1, Number(m1[1]), Number(m1[4]), Number(m1[5]), 0, 0);
          const m2 = v.match(/^(\d{4})-(\d{2})-(\d{2})\s+(\d{1,2}):(\d{2})$/);
          if (m2) return new Date(Number(m2[1]), Number(m2[2]) - 1, Number(m2[3]), Number(m2[4]), Number(m2[5]), 0, 0);
          const m3 = v.match(/^(\d{2})-(\d{2})-(\d{4})$/);
          if (m3) return new Date(Number(m3[3]), Number(m3[2]) - 1, Number(m3[1]), 23, 59, 0, 0);
          const m4 = v.match(/^(\d{4})-(\d{2})-(\d{2})$/);
          if (m4) return new Date(Number(m4[1]), Number(m4[2]) - 1, Number(m4[3]), 23, 59, 0, 0);
          return null;
        };
        if (!expireRaw) {
          expireRaw = `${fmtDDMMYYYY(maxExpireDate)} 23:59`;
          state.quizPublishForm = state.quizPublishForm || {};
          state.quizPublishForm.expire = expireRaw;
          saveDebounced(false);
        } else {
          const dt = parseExpireToDate(expireRaw);
          if (dt && !Number.isNaN(dt.getTime()) && dt.getTime() > maxExpireDate.getTime()) {
            const fixed = `${fmtDDMMYYYY(maxExpireDate)} 23:59`;
            expireRaw = fixed;
            state.quizPublishForm = state.quizPublishForm || {};
            state.quizPublishForm.expire = fixed;
            saveDebounced(false);
            alert('Batas waktu link maksimal 14 hari dari tanggal pembuatan. Nilai otomatis disesuaikan.');
          }
        }
        if (/^\d{2}-\d{2}-\d{4}$/.test(expireRaw)) expireRaw = `${expireRaw} 23:59`;
        if (/^\d{4}-\d{2}-\d{2}$/.test(expireRaw)) expireRaw = `${expireRaw} 23:59`;
        const normalizeExpireDate = (s) => {
          const v = String(s || "").trim();
          const vv = v.replace('T', ' ');
          const m1 = vv.match(/^(\d{2})-(\d{2})-(\d{4})(?:\s+(\d{1,2}):(\d{2}))?$/);
          if (m1) {
            const dd = m1[1];
            const mm = m1[2];
            const yyyy = m1[3];
            const hh = String(m1[4] ?? '').trim();
            const mi = String(m1[5] ?? '').trim();
            if (hh && mi) return `${yyyy}-${mm}-${dd} ${String(hh).padStart(2,'0')}:${String(mi).padStart(2,'0')}`;
            return `${yyyy}-${mm}-${dd}`;
          }
          const m2 = vv.match(/^(\d{4})-(\d{2})-(\d{2})(?:\s+(\d{1,2}):(\d{2}))?$/);
          if (m2) {
            const yyyy = m2[1];
            const mm = m2[2];
            const dd = m2[3];
            const hh = String(m2[4] ?? '').trim();
            const mi = String(m2[5] ?? '').trim();
            if (hh && mi) return `${yyyy}-${mm}-${dd} ${String(hh).padStart(2,'0')}:${String(mi).padStart(2,'0')}`;
            return `${yyyy}-${mm}-${dd}`;
          }
          return v;
        };
        const expire = normalizeExpireDate(expireRaw);
        if (!slug) { alert("Isi slug."); return; }
        const btn = document.getElementById('pubMsg');
        if (btn) btn.textContent = "Memproses...";
        try {
          const roster = Array.isArray(state.quizPublishForm?.roster) ? state.quizPublishForm.roster : [];
          const compressImageDataUrl = async (dataUrl, opts = {}) => {
            const maxSide = Number(opts.maxSide || 1280);
            const maxBytes = Number(opts.maxBytes || (350 * 1024));
            const minQuality = Number(opts.minQuality || 0.55);
            let quality = Number(opts.quality || 0.82);
            const img = await new Promise((resolve, reject) => {
              const im = new Image();
              im.onload = () => resolve(im);
              im.onerror = () => reject(new Error('image_load_failed'));
              im.src = dataUrl;
            });
            const w0 = Number(img.naturalWidth || img.width || 0);
            const h0 = Number(img.naturalHeight || img.height || 0);
            if (!w0 || !h0) return dataUrl;
            const scale = Math.min(1, maxSide / Math.max(w0, h0));
            const w = Math.max(1, Math.round(w0 * scale));
            const h = Math.max(1, Math.round(h0 * scale));
            const canvas = document.createElement('canvas');
            canvas.width = w;
            canvas.height = h;
            const ctx = canvas.getContext('2d', { alpha: false });
            if (!ctx) return dataUrl;
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, w, h);
            ctx.drawImage(img, 0, 0, w, h);
            let out = canvas.toDataURL('image/jpeg', quality);
            while (out.length > (maxBytes * 1.37) && quality > minQuality) {
              quality = Math.max(minQuality, quality - 0.07);
              out = canvas.toDataURL('image/jpeg', quality);
            }
            return out;
          };
          const uploadIfNeeded = async (img) => {
            const s = String(img || '');
            if (!s) return '';
            if (!includeImages) return '';
            if (/^data:image\//i.test(s)) {
              try {
                const compressed = await compressImageDataUrl(s, { maxSide: 1280, maxBytes: 350 * 1024, quality: 0.82, minQuality: 0.55 });
                if (compressed.length > (600 * 1024 * 1.37)) {
                  return '';
                }
                const r = await fetch('api/upload_image.php', {
                  method: 'POST',
                  headers: {'Content-Type':'application/json'},
                  credentials: 'same-origin',
                  body: JSON.stringify({ dataUrl: compressed })
                });
                const jsUp = await r.json().catch(()=>null);
                if (r.ok && jsUp && jsUp.ok && jsUp.url) return String(jsUp.url);
                return '';
              } catch { return ''; }
            }
            return s;
          };
          const params = new URLSearchParams();
          params.set('slug', slug);
          params.set('mapel', mapel);
          params.set('kelas', String(state.identity.kelas||''));
          params.set('sekolah', String(state.identity.namaSekolah||''));
          params.set('guru', String(state.identity.namaGuru||''));
          // tambahkan pembahasan jika ada
          const payloadWithExplain = await Promise.all(pg.map(async (q) => {
            const imgUrl = await uploadIfNeeded(q.image);
            return {
              question: String(q.question||''),
              options: q.options.map(x=>String(x||'')),
              explain: String(q.explanation || q.pembahasan || q.rationale || ''),
              image: imgUrl ? String(imgUrl) : ''
            };
          }));
          params.set('payload_public', JSON.stringify(payloadWithExplain));
          params.set('answer_key', JSON.stringify(answer_key));
          const maxAbsen = roster.length > 0 ? roster.length : (Number(state.quizPublishForm?.jumlah || 0) || 0);
          params.set('max_absen', String(maxAbsen));
          params.set('show_solution', String(state.quizPublishForm?.showSolution ? 1 : 0));
          if (expire) params.set('expire_at', expire);
          const res = await fetch('api/publish_quiz.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'}, body: params.toString(), credentials: 'same-origin' });
          const raw = await res.text();
          let js = null;
          try { js = JSON.parse(raw); } catch {}
          if (res.ok && js && js.ok) {
            const expText = (expireRaw && expireRaw.trim()) ? expireRaw.trim() : '14 hari (otomatis)';
            const classLink = `${location.origin}/soal_view.php?id=${encodeURIComponent(String(js.id))}`;
            const exampleLink = `${classLink}&n=1`;
            const adjusted = Number(js.slug_adjusted || 0) === 1 && String(js.slug_original || '') && String(js.slug || '') !== String(js.slug_original || '');
            const adjustedMsg = adjusted ? `<br><span class="text-amber-700">Slug disesuaikan menjadi: <b>${safeText(String(js.slug||''))}</b></span>` : ``;
            if (btn) btn.innerHTML = `Berhasil buat link siswa.${adjustedMsg}<br>Link untuk siswa: <a class="text-blue-600 underline" href="${classLink}" target="_blank" rel="noopener">${classLink}</a><br><span class="text-xs text-text-sub-light">Siswa akan diminta mengisi No Absen dan Nama saat membuka link.</span><br>Maks absen: ${Number(state.quizPublishForm?.jumlah||0) || '-'} • Expire: ${expText}<br><span class="text-xs text-text-sub-light">Contoh akses:</span> <a class="text-blue-600 underline text-xs" href="${exampleLink}" target="_blank" rel="noopener">${exampleLink}</a>`;
            state.quizLastLink = classLink;
            await loadPublications();
            state.quizSelectedSlug = String(js.slug);
            state.quizLastPubId = Number(js.id);
            state.quizLastSlug = String(js.slug || '');
            state.quizPublishForm = state.quizPublishForm || {};
            state.quizPublishForm.slug = String(js.slug || state.quizPublishForm.slug || '');
            saveDebounced(true);
            render();
            try {
              const cost = Number(state.limitConfig?.costs?.publish_quiz ?? 3);
              const calls = [];
              for (let i=0;i<cost;i++) calls.push(fetch("api/openai_proxy.php", { method:"POST", headers:{"Content-Type":"application/json"}, credentials:"same-origin", body: JSON.stringify({ type:"decrement_package" }) }));
              await Promise.all(calls);
              try { await computeStats(); } catch {}
              logCreditUsage('Publish Quiz', cost, `Slug: ${String(js.slug||'')}`);
            } catch {}
            try {
              const title = `Publish Quiz - ${mapel}${kelas ? ` ${kelas}` : ''} - ${String(js.slug || '')}`.trim();
              const snapshot = {
                identity: { ...state.identity },
                paket: { ...state.paket },
                questions: pg,
                quizPublishMeta: {
                  published_id: Number(js.id || 0),
                  slug: String(js.slug || ''),
                  link: classLink,
                  expire_at: expText,
                  created_at: new Date().toISOString(),
                },
              };
              await fetch("api/soal_user.php", {
                method: "POST",
                headers: {"Content-Type":"application/json"},
                body: JSON.stringify({ type: "save", title, state: snapshot, token_input: 0, token_output: 0, model: OPENAI_MODEL })
              });
            } catch {}
          } else {
            const snippet = raw ? String(raw).slice(0,120).replace(/\s+/g,' ').trim() : '';
            const detail = js?.error || (res.status === 409 ? 'slug_exists' : `http_${res.status}${snippet ? ': '+snippet : ''}`);
            if (btn) btn.textContent = `Gagal buat link siswa (${detail}).`;
          }
        } catch (e) {
          if (btn) btn.textContent = "Gagal buat link siswa (network).";
        }
      };
      const loadPublications = async () => {
        try {
          const res = await fetch('api/published_quiz_list.php');
          if (!res.ok) return;
          const js = await res.json();
          if (js && js.ok) {
            state.quizPublications = js.items || [];
            if (!state.quizSelectedSlug && state.quizPublications[0]) state.quizSelectedSlug = state.quizPublications[0].slug;
            saveDebounced(false);
          }
        } catch {}
      };
      const loadResults = async () => {
        const selEl = document.getElementById('selPub');
        if (selEl) state.quizSelectedSlug = selEl.value;
        const slug = state.quizSelectedSlug;
        if (!slug) return;
        try {
          const res = await fetch('api/published_quiz_results.php?'+new URLSearchParams({ slug }));
          if (!res.ok) return;
          const js = await res.json();
          if (js && js.ok) {
            state.quizResults = js.items || [];
            state.quizResultsMapel = js.mapel || '';
            state.quizResultsLoadedAt = new Date().toISOString();
            saveDebounced(false);
            render();
          }
        } catch {}
      };
      const exportResultsCSV = (slug) => {
        const rows = Array.isArray(state.quizResults) ? state.quizResults.slice() : [];
        const roster = Array.isArray(state.quizPublishForm?.roster) ? state.quizPublishForm.roster : [];
        const nameMap = new Map(roster.map(r => [Number(r.absen), String(r.nama||'')]));
        const head = ['No Absen','Nama Siswa','Nilai','Tanggal Submit'];
        const body = rows.map(r => {
          const pct = r && r.total ? Math.round((Number(r.score||0)/Number(r.total||1))*100) : 0;
          const ab = Number(r.absen);
          const nmRaw = String(r?.nama || r?.name || '') || (nameMap.get(ab) || '');
          const nm = decodeMaybeUrlText(nmRaw);
          const dt = String(r.created_at || '');
          return [String(ab), nm, String(pct), dt];
        });
        const lines = [head.join(','), ...body.map(cols => cols.map(c => `"${String(c).replace(/"/g,'""')}"`).join(','))];
        const blob = new Blob([lines.join('\n')], { type:'text/csv;charset=utf-8' });
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = `Laporan_${slug||'hasil'}.csv`;
        a.click();
        URL.revokeObjectURL(a.href);
      };
      const exportResultsPDF = async (slug) => {
        const s = String(slug || '').trim();
        if (!s) return;
        await ensureJsPDF();
        const { jsPDF } = window.jspdf;
        const doc = new jsPDF('p', 'pt', 'a4');
        const pageW = doc.internal.pageSize.getWidth();
        const pageH = doc.internal.pageSize.getHeight();
        const margin = 40;
        const now = new Date();

        const items = Array.isArray(state.quizPublications) ? state.quizPublications : [];
        const pub = items.find(it => String(it.slug) === s) || null;
        const mapel = String(pub?.mapel || state.quizResultsMapel || '').trim();
        const kelas = String(pub?.kelas || '').trim();
        const createdAt = String(pub?.created_at || '').trim();
        const expireAt = String(pub?.expire_at || '').trim();

        const roster = Array.isArray(state.quizPublishForm?.roster) ? state.quizPublishForm.roster : [];
        const nameMap = new Map(roster.map(r => [Number(r.absen), String(r.nama || '')]));
        const rows = Array.isArray(state.quizResults) ? state.quizResults.slice() : [];
        rows.sort((a,b)=>{
          const pa = a && a.total ? (Number(a.score||0)/Number(a.total||1)) : 0;
          const pb = b && b.total ? (Number(b.score||0)/Number(b.total||1)) : 0;
          if (pb !== pa) return pb - pa;
          return Number(a.absen||0) - Number(b.absen||0);
        });
        const totalPeserta = rows.length;
        const scores = rows.map(r => r && r.total ? Math.round((Number(r.score||0)/Number(r.total||1))*100) : 0);
        const avg = scores.length ? Math.round(scores.reduce((a,b)=>a+b,0)/scores.length) : 0;
        const max = scores.length ? Math.max(...scores) : 0;
        const min = scores.length ? Math.min(...scores) : 0;

        doc.setFont('helvetica', 'bold');
        doc.setFontSize(16);
        doc.text('Laporan Hasil Quiz Online', margin, 44);
        doc.setFont('helvetica', 'normal');
        doc.setFontSize(10);
        doc.setTextColor(90, 90, 90);
        const metaLeftLines = [
          mapel ? `Mapel: ${mapel}` : '',
          kelas ? `Kelas: ${kelas}` : '',
          s ? `Slug: ${s}` : '',
        ].filter(Boolean);
        const metaRightLines = [
          createdAt ? `Publish: ${createdAt}` : '',
          expireAt ? `Expire: ${expireAt}` : '',
          `Cetak: ${now.toLocaleString()}`,
        ].filter(Boolean);
        const colGap = 14;
        const colW = (pageW - (margin * 2) - colGap) / 2;
        let metaY = 62;
        const metaLineH = 12;
        const wrapLines = (arr, width) => arr.flatMap(t => doc.splitTextToSize(String(t), width));
        const leftWrapped = wrapLines(metaLeftLines, colW);
        const rightWrapped = wrapLines(metaRightLines, colW);
        const maxLines = Math.max(leftWrapped.length, rightWrapped.length, 1);
        for (let i = 0; i < maxLines; i++) {
          if (leftWrapped[i]) doc.text(leftWrapped[i], margin, metaY);
          if (rightWrapped[i]) doc.text(rightWrapped[i], pageW - margin, metaY, { align: 'right' });
          metaY += metaLineH;
        }
        doc.setTextColor(0, 0, 0);

        const body = rows.map((r, idx) => {
          const pct = r && r.total ? Math.round((Number(r.score||0)/Number(r.total||1))*100) : 0;
          const ab = Number(r.absen);
          const nmRaw = String(r?.nama || r?.name || '') || (nameMap.get(ab) || '');
          const nm = decodeMaybeUrlText(nmRaw);
          const dt = String(r.created_at || '');
          return [String(idx+1), String(ab||''), nm || '-', String(pct), dt];
        });
        doc.autoTable({
          startY: metaY + 10,
          margin: { left: margin, right: margin },
          theme: 'grid',
          head: [['#', 'No Absen', 'Nama Siswa', 'Nilai', 'Waktu Submit']],
          body: body.length ? body : [['-','-','-','-','-']],
          styles: { font: 'helvetica', fontSize: 9, cellPadding: 4, lineWidth: 0.5, lineColor: [120,120,120], textColor: [0,0,0] },
          headStyles: { fillColor: [217,217,217], textColor: [0,0,0], fontStyle: 'bold' },
          columnStyles: {
            0: { halign: 'center', cellWidth: 28 },
            1: { halign: 'center', cellWidth: 70 },
            3: { halign: 'center', cellWidth: 50 },
            4: { cellWidth: 120 },
          },
          didDrawPage: () => {
            const pageNum = doc.getNumberOfPages();
            doc.setFont('helvetica','normal');
            doc.setFontSize(9);
            doc.setTextColor(120,120,120);
            doc.text(`Halaman ${pageNum}`, pageW - margin, pageH - 20, { align: 'right' });
            doc.setTextColor(0,0,0);
          }
        });

        if (totalPeserta > 30) { doc.addPage(); }
        const afterResultsY = totalPeserta > 30 ? 60 : (doc.lastAutoTable?.finalY || (metaY + 10)) + 16;
        doc.setFont('helvetica', 'bold');
        doc.setFontSize(11);
        doc.text('Ringkasan Nilai', margin, afterResultsY);
        doc.setFont('helvetica', 'normal');
        doc.setFontSize(10);
        doc.autoTable({
          startY: afterResultsY + 8,
          margin: { left: margin, right: margin },
          theme: 'grid',
          head: [['Peserta', 'Rata-rata', 'Tertinggi', 'Terendah']],
          body: [[String(totalPeserta), String(avg), String(max), String(min)]],
          styles: { font: 'helvetica', fontSize: 10, cellPadding: 6, lineWidth: 0.5, lineColor: [120,120,120], textColor: [0,0,0] },
          headStyles: { fillColor: [217,217,217], textColor: [0,0,0], fontStyle: 'bold' },
          bodyStyles: { textColor: [0,0,0] },
          columnStyles: { 0: { halign: 'center' }, 1: { halign: 'center' }, 2: { halign: 'center' }, 3: { halign: 'center' } }
        });

        const top3 = rows.slice(0, 3).map((r, i) => {
          const pct = r && r.total ? Math.round((Number(r.score||0)/Number(r.total||1))*100) : 0;
          const ab = Number(r.absen);
          const nmRaw = String(r?.nama || r?.name || '') || (nameMap.get(ab) || '');
          const nm = decodeMaybeUrlText(nmRaw);
          return [String(i+1), String(ab||''), nm || '-', String(pct)];
        });
        const afterSummaryY = (doc.lastAutoTable?.finalY || (afterResultsY + 8)) + 16;
        doc.setFont('helvetica', 'bold');
        doc.setFontSize(11);
        doc.text('3 Besar (Peringkat)', margin, afterSummaryY);
        doc.setFont('helvetica', 'normal');
        doc.setFontSize(10);
        doc.autoTable({
          startY: afterSummaryY + 8,
          margin: { left: margin, right: margin },
          theme: 'grid',
          head: [['Peringkat', 'No Absen', 'Nama Siswa', 'Nilai']],
          body: top3.length ? top3 : [['-','-','-','-']],
          styles: { font: 'helvetica', fontSize: 10, cellPadding: 5, lineWidth: 0.5, lineColor: [120,120,120], textColor: [0,0,0] },
          headStyles: { fillColor: [217,217,217], textColor: [0,0,0], fontStyle: 'bold' },
          columnStyles: { 0: { halign: 'center', cellWidth: 70 }, 1: { halign: 'center', cellWidth: 80 }, 3: { halign: 'center', cellWidth: 60 } }
        });

        const safeSlug = (s || 'hasil').replace(/[^a-z0-9_\-]/gi, '_');
        doc.save(`HasilQuiz_${safeSlug}.pdf`);
      };
      const exportJSON = (slug) => {
        if (!slug) return;
        const url = 'api/export_publication_json.php?'+new URLSearchParams({ slug });
        window.open(url, '_blank');
      };
      const exportZIP = (slug) => {
        if (!slug) return;
        const url = 'api/export_publication_zip.php?'+new URLSearchParams({ slug });
        window.open(url, '_blank');
      };

      const buildPackage = async () => {
        const chk = validateBuatSoal();
        if (!chk.ok) {
          state.soalError = { tab: chk.tab, msg: chk.msg, path: chk.path };
          state.previewTab = chk.tab;
          saveDebounced(false);
          render();
          setTimeout(() => { try { focusByPath(chk.path); } catch {} }, 50);
          return;
        }
        state.soalError = null;
        autoFillPaket();
        // preflight limit check
        try {
          const res = await fetch("api/openai_proxy.php", { method: "POST", headers: {"Content-Type":"application/json"}, credentials:"same-origin", body: JSON.stringify({ type: "get_limits" }) });
          if (res.ok) {
            const limits = await res.json();
            if ((limits?.limitpaket ?? 0) < 2) {
              alert("Kredit tidak mencukupi untuk membuat paket soal (butuh 2 kredit). Hubungi admin untuk menambah kuota.");
              return;
            }
          }
        } catch {}
        state.questions = [];
        state._isGenerating = true;
        saveDebounced(true);
        setView("preview");

        // loading UI will be rendered by computeView() based on _isGenerating flag

        let cp046SoalBlock = "";
        let cp046SoalPagesText = "";
        try {
          const jenjangResolved0 = resolveJenjang(state.identity.jenjang, state.identity.kesetaraanPaket);
          const kelasNum0 = parseKelasNumber(state.identity.kelas);
          const faseSel0 = faseLetterFromLabel(state.identity.fase);
          const faseExp0 = expectedFaseLetter(jenjangResolved0, kelasNum0);
          const faseEfektif0 = faseExp0 ? `Fase ${faseExp0}` : (faseSel0 ? `Fase ${faseSel0}` : String(state.identity.fase || ''));
          const mapel0 = String(state.identity.mataPelajaran || "").trim();
          const materiHint0 = identityTopikDisplay(state.identity) || String(state.paket?.judul || "").trim();
          if (jenjangResolved0 && faseEfektif0 && mapel0) {
            const respCp = await fetch("api/cp046_rpp_context.php", {
              method: "POST",
              headers: { "Content-Type": "application/json" },
              body: JSON.stringify({ docType: "soal", jenjang: jenjangResolved0, fase: faseEfektif0, mapel: mapel0, materi: materiHint0, kurikulum: "Kurikulum Merdeka" }),
            });
            if (respCp.ok) {
              const ctx = await respCp.json();
              if (ctx?.ok && String(ctx?.block || "").trim()) {
                cp046SoalBlock = String(ctx.block || "").trim();
                const pages = Array.isArray(ctx?.pages) ? ctx.pages.map(x => Number(x)).filter(x => Number.isFinite(x) && x > 0) : [];
                if (pages.length) cp046SoalPagesText = pages.sort((a,b)=>a-b).join(", ");
                state.cp046 = state.cp046 || {};
                state.cp046.soal = { jenjang: ctx.jenjang, fase: ctx.fase, mapel_slug: ctx.mapel_slug, pages: ctx.pages, cp_file: ctx.cp_file };
                saveDebounced(false);
              }
            }
          }
        } catch {}

        const total = state.sections.reduce((acc, s) => {
          const isObjective = ["pg", "benar_salah", "pg_kompleks", "menjodohkan"].includes(s.bentuk);
          const isEssay = ["isian", "uraian"].includes(s.bentuk);
          return acc + (isObjective ? Number(s.jumlahPG || 0) : isEssay ? Number(s.jumlahIsian || 0) : 0);
        }, 0);
        const updateGenProgress = () => {
          const done = state.questions.length;
          const elp = document.getElementById("genProgress");
          if (elp) elp.textContent = `Membuat soal: ${done}/${total}`;
        };
        updateGenProgress();

        let pkgTokenIn = 0;
        let pkgTokenOut = 0;
        for (const sec of state.sections) {
          const isObjective = ["pg", "benar_salah", "pg_kompleks", "menjodohkan"].includes(sec.bentuk);
          const isEssay = ["isian", "uraian"].includes(sec.bentuk);
          const jumlahPG = Number(sec.jumlahPG || 0);
          const jumlahIsian = Number(sec.jumlahIsian || 0);
          const totalSec = isObjective ? jumlahPG : isEssay ? jumlahIsian : 0;
          if (totalSec === 0) continue;
          const konteksOn = !!sec.soalKonteks;
          const konteksSoalCount = konteksOn ? (totalSec >= 3 ? Math.min(totalSec, Math.max(3, Math.floor(totalSec * 0.3))) : totalSec) : 0;
          const applyContextPolicy = (q) => {
            if (!q) return q;
            let ctx = String(q.context || '').replace(/\r\n/g, '\n').replace(/\r/g, '\n').trim();
            if (!konteksOn || !ctx) return { ...q, context: '' };
            if (!/\n\s*\n/.test(ctx)) {
              const m = ctx.match(/^(.{40,}?[.!?])\s+/);
              if (m) ctx = ctx.replace(m[0], `${m[1]}\n\n`);
              else if (ctx.length > 140) ctx = `${ctx.slice(0, 140)}\n\n${ctx.slice(140)}`;
            }
            return { ...q, context: ctx.slice(0, 1100).trim() };
          };
          const applySingleContextForSection = (startIdx, endIdx) => {
            if (!Number.isFinite(startIdx) || !Number.isFinite(endIdx) || startIdx < 0 || endIdx <= startIdx) return;
            const slice = state.questions.slice(startIdx, endIdx);
            const found = slice.find(x => x && String(x.context || '').trim());
            const base = found ? String(found.context || '') : '';
            if (!konteksOn || !base.trim() || konteksSoalCount <= 0) {
              for (let i = startIdx; i < endIdx; i++) state.questions[i] = { ...(state.questions[i] || {}), context: '' };
              return;
            }
            const normalized = applyContextPolicy({ context: base }).context || '';
            let assigned = 0;
            for (let i = startIdx; i < endIdx; i++) {
              const q = state.questions[i];
              if (!q || !String(q.question || '').trim()) { state.questions[i] = { ...(q || {}), context: '' }; continue; }
              if (assigned < konteksSoalCount) state.questions[i] = { ...q, context: normalized };
              else state.questions[i] = { ...q, context: '' };
              assigned++;
            }
          };

          const opsi = clamp(Number(sec.opsiPG || 4), 3, 5);
          const bloomPreset = sec.cakupanBloom || "level_standar";
          const bloomCodes = bloomPresets[bloomPreset]?.codes || ["C1", "C2", "C3", "C4"];
          const bloomLabel = bloomPresets[bloomPreset]?.label || bloomPreset;
          const bagianLabel = sec.bentuk === "pg" ? "Pilihan Ganda" :
                              sec.bentuk === "benar_salah" ? "Benar/Salah" :
                              sec.bentuk === "pg_kompleks" ? "Pilihan Ganda Kompleks" :
                              sec.bentuk === "menjodohkan" ? "Menjodohkan" :
                              sec.bentuk === "isian" ? "Isian Singkat" : "Uraian";
          const topikRaw = String(state.identity.topik_raw || "").trim();
          const topikRawClip = topikRaw ? topikRaw.slice(0, 12000) : "";
          const topikDisplay = identityTopikDisplay(state.identity);
          const jenjangDisplay = displayJenjang(state.identity.jenjang, state.identity.kesetaraanPaket);
          const jenjangResolved = resolveJenjang(state.identity.jenjang, state.identity.kesetaraanPaket);
          const topikText = topikDisplay
            ? `tema ${topikDisplay} untuk ${state.identity.mataPelajaran} tingkat ${jenjangDisplay} kelas ${state.identity.kelas}`
            : `berbagai tema/topik yang sesuai untuk ${state.identity.mataPelajaran} tingkat ${jenjangDisplay} kelas ${state.identity.kelas}`;

          const kelasNum = parseKelasNumber(state.identity.kelas);
          const faseSel = faseLetterFromLabel(state.identity.fase);
          const faseExp = expectedFaseLetter(jenjangResolved, kelasNum);
          const faseEfektif = faseExp ? `Fase ${faseExp}` : (faseSel ? `Fase ${faseSel}` : String(state.identity.fase || ''));
          const jenjangEfektif = String(jenjangResolved || '').trim();
          const mapelEfektif = String(state.identity.mataPelajaran || '').trim();
          const isSMA = jenjangEfektif === 'SMA/MA' || jenjangEfektif === 'SMK' || jenjangEfektif === 'SMK/MAK';
          const isPancasila = /pancasila/i.test(mapelEfektif);
          const isKelasAtas = Number(kelasNum || 0) >= 11;
          const levelRules = isSMA && isPancasila && isKelasAtas
            ? `KETENTUAN LEVEL (WAJIB):
- Ini untuk ${jenjangEfektif} Kelas ${kelasNum || state.identity.kelas} (${faseEfektif}). Level harus setara SMA akhir.
- DILARANG membuat soal level SD/SMP, contohnya: bunyi sila-sila, "sila pertama apa", lambang Garuda, urutan sila, definisi Pancasila yang sangat dasar.
- Utamakan soal berbasis konteks/kasus, analisis argumen, dilema kebijakan publik, partisipasi warga, etika digital, HAM, demokrasi, konstitusi/UUD 1945, keberagaman dan konflik sosial, serta literasi kewargaan kontemporer.
- Pertanyaan harus menuntut penalaran (mengapa/bagaimana), bukan hafalan.`
            : (isSMA && Number(kelasNum || 0) >= 10
              ? `KETENTUAN LEVEL (WAJIB):
- Ini untuk ${jenjangEfektif} Kelas ${kelasNum || state.identity.kelas} (${faseEfektif}). Soal harus setara SMA/SMK, bukan level SD.
- Utamakan konteks nyata, analisis, dan penerapan konsep (hindari definisi 1 kalimat yang terlalu dasar).`
              : ``);

          const special = String(state.specialInstruction || "").trim();
          const specialRules = special
            ? `\nATURAN TAMBAHAN DARI GURU (WAJIB DIPATUHI):\n${special}\n- Jika aturan ini bertentangan dengan instruksi lain, prioritaskan aturan tambahan dari guru.\n`
            : ``;
          const contextRules = konteksOn
            ? `\nMODE SOAL BERKONTEKS: AKTIF
- Buat tepat 1 stimulus/bacaan (field "context") yang sama untuk ${konteksSoalCount} soal. Soal lainnya field "context" kosong.
- Field "context" WAJIB minimal 2 paragraf (dipisahkan 1 baris kosong) dan memuat data/situasi nyata yang digunakan pada soal.
- Panjang "context" disarankan 450–1100 karakter (jangan melebihi 1100).
- Soal tanpa konteks WAJIB tetap boleh (field "context" kosong).
`
            : `\nMODE SOAL BERKONTEKS: NONAKTIF
- Field "context" harus kosong. Soal langsung ke pertanyaan.\n`;

          const outputSchema = sec.bentuk === "menjodohkan"
            ? `OUTPUT JSON (Array of Objects):
[
  {
    "type": "menjodohkan",
    "context": "...",
    "question": "...",
    "pairs": [
      { "left": "...", "right": "..." }
    ],
    "explanation": "...",
    "difficulty": "...",
    "bloom": "...",
    "materi": "...",
    "indikator": "...",
    "asciiDiagram": "...",
    "svgSource": "...",
    "imagePrompt": "..."
  }
]
Kembalikan JSON persis: {"items": [...]}`
            : (sec.bentuk === "benar_salah")
              ? `OUTPUT JSON (Array of Objects):
[
  {
    "type": "benar_salah",
    "context": "...",
    "question": "...",
    "options": ["Benar", "Salah"],
    "answer": 0,
    "explanation": "...",
    "difficulty": "...",
    "bloom": "...",
    "materi": "...",
    "indikator": "...",
    "asciiDiagram": "...",
    "svgSource": "...",
    "imagePrompt": "..."
  }
]
Kembalikan JSON persis: {"items": [...]}`
            : (sec.bentuk === "pg" || sec.bentuk === "pg_kompleks")
              ? `OUTPUT JSON (Array of Objects):
[
  {
    "type": "${sec.bentuk}",
    "context": "...",
    "question": "...",
    "options": ["..."],
    "answer": ...,
    "explanation": "...",
    "difficulty": "...",
    "bloom": "...",
    "materi": "...",
    "indikator": "...",
    "asciiDiagram": "...",
    "svgSource": "...",
    "imagePrompt": "..."
  }
]
Kembalikan JSON persis: {"items": [...]}`
              : `OUTPUT JSON (Array of Objects):
[
  {
    "type": "${sec.bentuk}",
    "context": "...",
    "question": "...",
    "answer": "...",
    "explanation": "...",
    "difficulty": "...",
    "bloom": "...",
    "materi": "...",
    "indikator": "...",
    "asciiDiagram": "...",
    "svgSource": "...",
    "imagePrompt": "..."
  }
]
Kembalikan JSON persis: {"items": [...]}`;

          const promptBase = `Bertindaklah sebagai Guru Profesional.
Buatlah daftar soal untuk BAGIAN: ${bagianLabel}.

KONTEKS:
Jenjang: ${displayJenjang(state.identity.jenjang, state.identity.kesetaraanPaket)} ${faseEfektif ? faseEfektif : state.identity.fase} Kelas ${state.identity.kelas}
Mapel: ${state.identity.mataPelajaran}
Sumber Materi Mentah (WAJIB jadi dasar utama soal):${topikRawClip ? `\n<<<\n${topikRawClip}\n>>>` : " -"}
${levelRules ? `\n${levelRules}\n` : ''}
${cp046SoalBlock ? `\n${cp046SoalBlock}\n` : ''}

KETENTUAN KESESUAIAN LEVEL (WAJIB):
- Soal harus sesuai jenjang/fase/kelas yang dipilih. Jika materi mentah terlalu dasar atau terlalu tinggi, sesuaikan kedalaman dan kompleksitasnya agar tetap tepat level.
- Prioritaskan konsep/subtopik yang paling dominan muncul di Materi Mentah (jika tersedia), bukan sekadar tema umum.

PARAMETER:
- Jumlah Soal: __JUMLAH__ butir.
- Tingkat Kesulitan: ${sec.tingkatKesulitan === 'campuran' ? 'Bervariasi (Mudah, Sedang, Sulit)' : sec.tingkatKesulitan}.
- Kognitif: ${bloomLabel} (${bloomCodes.join(', ')}).
- Opsi: ${opsi} pilihan (untuk PG).
- SEMUA item bertipe: ${sec.bentuk} (JANGAN buat tipe lain).

ATURAN JAWABAN (WAJIB):
- Jika tipe "pg": field "answer" harus 1 angka index 0-based (0=A, 1=B, dst).
- Jika tipe "benar_salah": field "answer" harus 0 (Benar) atau 1 (Salah).
- Jika tipe "pg_kompleks": field "answer" harus ARRAY angka index 0-based, MINIMAL 2 jawaban benar. Contoh: [0,2] berarti A dan C benar.
- Jika tipe "menjodohkan": field "pairs" HARUS ada dan berisi ARRAY object { "left": "...", "right": "..." } (teks, bukan angka). Panjang pairs 4–8. Jangan output pasangan angka seperti "1-3" atau "0,3".

INSTRUKSI FORMAT MATEMATIKA (WAJIB PATUH):
1. JANGAN GUNAKAN FORMAT LATEX ($..$).
2. Gunakan tag HTML <sup> untuk Pangkat, <sub> untuk Indeks.
3. Gunakan simbol Unicode (√, ×, ÷, °, π).

INSTRUKSI FORMAT GAMBAR:
Jika soal membutuhkan gambar/diagram:
1. Prioritas 1: Buat diagram ASCII sederhana di field "asciiDiagram".
2. Prioritas 2: Buat kode SVG sederhana (hitam putih, viewBox minimal, tanpa width/height fixed) di field "svgSource".
3. Prioritas 3: Jika sangat kompleks, kosongkan ascii/svg dan isi "imagePrompt" untuk digenerate AI Image.
${contextRules}
${specialRules}
${outputSchema}`;

          const sectionStartIdx = state.questions.length;

          let needed = totalSec;
          let attempts = 0;
          let noProgress = 0;
          let batchSize = Math.min(GEN_BATCH_SIZE, needed);
          while (needed > 0 && attempts < GEN_MAX_ATTEMPTS && noProgress < 3) {
            let added = 0;
            try {
              const ask = Math.min(batchSize, needed);
              const prompt = promptBase.replaceAll("__JUMLAH__", String(ask));
              const res = await callOpenAI(prompt);
              if (res && res._usage) {
                pkgTokenIn += Number(res._usage.in || 0);
                pkgTokenOut += Number(res._usage.out || 0);
              }
              let items = Array.isArray(res?.items) ? res.items : [];
              if (items.length > ask) items = items.slice(0, ask);
              for (const item of items) {
                let q = normalizeQuestion(item, sec);
                if (!q.question) continue;
                q = applyContextPolicy(q);
                state.questions.push(q);
                needed--;
                added++;
                updateGenProgress();
                if (needed <= 0) break;
              }
              if (added === 0) noProgress++;
              else noProgress = 0;
              if (items.length < ask && batchSize > 1) {
                batchSize = Math.max(1, Math.floor(batchSize / 2));
              }
            } catch (e) {
              console.warn("Gen batch error:", e?.message || e);
              batchSize = Math.max(1, Math.floor(batchSize / 2));
              noProgress++;
              await new Promise(r => setTimeout(r, 1000));
            } finally {
              attempts++;
            }
          }
          if (needed > 0) {
            let fillTries = 0;
            const maxFill = Math.min(24, Math.max(6, needed * 3));
            while (needed > 0 && fillTries < maxFill) {
              try {
                const prompt = promptBase.replaceAll("__JUMLAH__", "1");
                const res = await callOpenAI(prompt);
                if (res && res._usage) {
                  pkgTokenIn += Number(res._usage.in || 0);
                  pkgTokenOut += Number(res._usage.out || 0);
                }
                const item = Array.isArray(res?.items) ? res.items[0] : null;
                let q = item ? normalizeQuestion(item, sec) : null;
                if (q && q.question) {
                  q = applyContextPolicy(q);
                  state.questions.push(q);
                  needed--;
                  updateGenProgress();
                }
              } catch {}
              fillTries++;
              if (needed > 0) await new Promise(r => setTimeout(r, 400));
            }
          }

          applySingleContextForSection(sectionStartIdx, state.questions.length);
        }

        state._isGenerating = false;
        saveDebounced(true);
        render();
        try {
          const title = [
            state.identity.mataPelajaran || 'Soal',
            state.identity.jenjang || '',
            state.identity.kelas || '',
            state.paket?.judul || ''
          ].filter(Boolean).join(' - ');
          await fetch("api/soal_user.php", {
            method: "POST",
            headers: {"Content-Type":"application/json"},
            body: JSON.stringify({ type: "save", title, state, token_input: pkgTokenIn, token_output: pkgTokenOut, model: OPENAI_MODEL })
          });
        } catch {}
        const actual = state.questions.length;
        if (actual === total) {
          try {
            await Promise.all([
              fetch("api/openai_proxy.php", { method: "POST", headers: {"Content-Type":"application/json"}, credentials:"same-origin", body: JSON.stringify({ type: "decrement_package" }) }),
              fetch("api/openai_proxy.php", { method: "POST", headers: {"Content-Type":"application/json"}, credentials:"same-origin", body: JSON.stringify({ type: "decrement_package" }) }),
            ]);
          } catch {}
          try {
            await fetch("api/openai_proxy.php", {
              method: "POST",
              headers: {"Content-Type":"application/json"},
            credentials: "same-origin",
              body: JSON.stringify({ type: "add_tokens", input_tokens: pkgTokenIn, output_tokens: pkgTokenOut })
            });
          } catch {}
          // Log kredit lokal (2 kredit untuk Buat Soal)
          logCreditUsage('Buat Soal', 2, `${state.identity.mataPelajaran||''} • ${state.identity.kelas||''} • ${total} butir`);
          try { await computeStats(); } catch {}
        } else {
          alert(`Paket tersusun ${actual} dari ${total}. Kuota tidak berkurang.`);
        }
      };

      const regenSingle = async (qId) => {
        const q = state.questions.find((x) => x.id === qId);
        if (!q) return;
        updateQuestionData(qId, { _loadingText: true });
        try {
          const secCfg = state.sections.find((s) => s.id === q.sectionId) || {};
          const konteksOn = !!secCfg.soalKonteks;
          const totalInSection = state.questions.filter(x => x && x.sectionId === q.sectionId).length;
          const konteksSoalCount = konteksOn ? (totalInSection >= 3 ? Math.min(totalInSection, Math.max(3, Math.floor(totalInSection * 0.3))) : totalInSection) : 0;
          const existingContext = String(q.context || '').replace(/\r\n/g, '\n').replace(/\r/g, '\n').trim();
          const tieToContext = konteksOn && !!existingContext;

          const out = q.type === 'menjodohkan'
            ? `OUTPUT JSON:
{
  "items": [
    {
      "type": "menjodohkan",
      "context": "...",
      "question": "...",
      "pairs": [{"left":"...","right":"..."}],
      "explanation": "...",
      "difficulty": "...",
      "bloom": "...",
      "materi": "...",
      "indikator": "...",
      "asciiDiagram": "...",
      "svgSource": "...",
      "imagePrompt": "..."
    }
  ]
}`
            : `OUTPUT JSON:
{
  "items": [
    {
      "type": "${q.type}",
      "context": "...",
      "question": "...",
      "options": [...],
      "answer": ...,
      "explanation": "...",
      "difficulty": "...",
      "bloom": "...",
      "materi": "...",
      "indikator": "...",
      "asciiDiagram": "...",
      "svgSource": "...",
      "imagePrompt": "..."
    }
  ]
}`;
          const contextBlock = tieToContext
            ? `\nKONTEKS BACAAN (WAJIB dijadikan acuan, jangan diubah):\n<<<\n${existingContext}\n>>>\n`
            : ``;
          const prompt = `Buat ulang 1 butir soal sesuai detail berikut:
Jenis: ${q.type}
Tingkat Kesulitan: sedang
Bloom: ${q.bloom || 'C2'}
Materi: ${q.materi || '-'}
${contextBlock}
Instruksi:
1. Gunakan Bahasa Indonesia.
2. Jika tipe "pg" atau "pg_kompleks", buat ${clamp(Number(state.sections.find(s=>s.id===q.sectionId)?.opsiPG || 4), 3, 5)} opsi.
3. Pastikan jawaban benar dan disertai penjelasan singkat.
4. Jika butuh gambar: Prioritas 1: "asciiDiagram", Prioritas 2: "svgSource", Prioritas 3: "imagePrompt".
5. Jika tipe "pg_kompleks", field "answer" HARUS array minimal 2 jawaban benar (contoh: [0,2]).
6. Jika tipe "menjodohkan", field "pairs" HARUS ada: [{"left":"...","right":"..."}, ...] (teks, bukan angka). Jangan output "0,3" atau "1-4".
7. Aturan konteks:
- Jika bagian ini mode konteks OFF, field "context" wajib kosong.
- Jika bagian ini mode konteks ON dan soal ini terkait konteks: field "context" wajib kosong (konteks sudah diberikan di atas dan harus tetap sama). Buat pertanyaan yang merujuk konteks tersebut.
- Jika bagian ini mode konteks ON tapi soal ini tidak terkait konteks: field "context" tetap kosong.
8. Jangan membuat stimulus baru. Dalam 1 bagian hanya ada 1 konteks (dipakai untuk ${konteksSoalCount} soal).
${out}`;
        const res = await callOpenAI(prompt);
        const item = Array.isArray(res?.items) ? res.items[0] : null;
        if (!item) return updateQuestionData(qId, { _loadingText: false });
        const sec = state.sections.find((s) => s.id === q.sectionId) || {};
        const next = normalizeQuestion(item, sec);
        updateQuestionData(qId, {
          type: next.type,
          context: q.context || "",
          question: next.question,
          options: next.options,
          answer: next.answer,
          explanation: next.explanation,
          difficulty: next.difficulty,
          bloom: next.bloom,
          imagePrompt: next.imagePrompt,
          asciiDiagram: next.asciiDiagram,
          svgSource: next.svgSource,
          image: q.image,
          pakaiGambar: q.pakaiGambar,
          sectionId: q.sectionId,
        });
        } catch (e) {
          console.error(e);
        } finally {
          updateQuestionData(qId, { _loadingText: false });
        }
      }

      const deleteImage = (id) => {
        updateQuestionData(id, { image: null, _imageError: null });
      };

      const uploadQuestionImage = (id, inputEl) => {
        try {
          const file = inputEl?.files?.[0];
          if (!file) return;
          const reader = new FileReader();
          reader.onload = () => {
            const dataUrl = String(reader.result || "");
            updateQuestionData(id, { image: dataUrl, _showImagePrompt: false, _imageError: null });
            inputEl.value = "";
          };
          reader.readAsDataURL(file);
        } catch {}
      };

      const copyImagePrompt = async (id, el) => {
        try {
          const q = state.questions.find(x => x.id === id);
          const p = String(q?.imagePrompt || '').trim();
          if (!p) return;
          await navigator.clipboard.writeText(p);
          if (el) {
            const prev = el.innerHTML;
            el.innerHTML = '<span class="material-symbols-outlined text-[16px]">done</span>';
            setTimeout(() => { el.innerHTML = '<span class="material-symbols-outlined text-[16px]">content_copy</span>'; }, 1200);
          }
        } catch {}
      };

      const openChatGPTWithPrompt = async (id, el) => {
        try {
          const q = state.questions.find(x => x.id === id);
          const p = String(q?.imagePrompt || '').trim();
          if (!p) return;
          // Copy prompt terlebih dahulu karena ChatGPT tidak mendukung prefill lewat URL
          await navigator.clipboard.writeText(p);
          window.open('https://chat.openai.com/', '_blank', 'noopener');
          if (el) {
            const prev = el.innerHTML;
            el.innerHTML = '<span class="material-symbols-outlined text-[16px]">done</span>';
            setTimeout(() => { el.innerHTML = '<span class="material-symbols-outlined text-[16px]">smart_toy</span>'; }, 1200);
          }
        } catch {}
      };

      const regenImage = async (id) => {
        const q = state.questions.find(x => x.id === id);
        if (!q) return;
        updateQuestionData(id, { _loadingImage: true, _imageError: null });
        try {
          try {
            const res = await fetch("api/openai_proxy.php", { method: "POST", headers: {"Content-Type":"application/json"}, credentials:"same-origin", body: JSON.stringify({ type: "get_limits" }) });
            if (res.ok) {
              const limits = await res.json();
              if ((limits?.limitgambar ?? 0) <= 0) {
                const ctx = state.identity || {};
                const baseTopic = String(q.materi || ctx.topik || ctx.mataPelajaran || q.question || '').trim();
                const existing = stripPrefixForSubject(String(q.imagePrompt || '').trim());
                const quoted = extractQuotedWord(q.question || '');
                const optGuess = pickConcreteOption(q.options || []);
                const rawSubj = existing || quoted || optGuess || baseTopic;
                const subject = enrichSubject(rawSubj, baseTopic, ctx.mataPelajaran, q.question);
                const enhanced = `High quality educational illustration, clear vector style, white background: ${subject}`;
                updateQuestionData(id, { imagePrompt: enhanced, _showImagePrompt: true, _imageError: null });
                return;
              }
            }
          } catch {}
          const preferSize = q.image ? "512x512" : "256x256";
          const ctx = state.identity || {};
          const subjSource = stripPrefixForSubject(q.imagePrompt || '');
          const quoted = extractQuotedWord(q.question || '');
          const optGuess = pickConcreteOption(q.options || []);
          const rawSubj = subjSource || quoted || optGuess || (q.materi || ctx.topik || ctx.mataPelajaran || q.question);
          const subj = enrichSubject(rawSubj, q.materi || ctx.topik || ctx.mataPelajaran, ctx.mataPelajaran, q.question);
          const img = await generateImage(subj || 'diagram', preferSize);
          if (!img) throw new Error('Gagal membuat gambar');
          updateQuestionData(id, { image: img });
        } catch (e) {
          const msg = String(e.message || e);
          if (msg.includes('403') || msg.toLowerCase().includes('habis')) {
            const ctx = state.identity || {};
            const baseTopic = String(q.materi || ctx.topik || ctx.mataPelajaran || q.question || '').trim();
            const existing = stripPrefixForSubject(String(q.imagePrompt || '').trim());
            const quoted = extractQuotedWord(q.question || '');
            const optGuess = pickConcreteOption(q.options || []);
            const rawSubj = existing || quoted || optGuess || baseTopic;
            const subject = enrichSubject(rawSubj, baseTopic, ctx.mataPelajaran, q.question);
            const enhanced = `High quality educational illustration, clear vector style, white background: ${subject}`;
            updateQuestionData(id, { imagePrompt: enhanced, _showImagePrompt: true, _imageError: null });
          } else {
            updateQuestionData(id, { _imageError: msg });
          }
        } finally {
          updateQuestionData(id, { _loadingImage: false });
        }
      };

      const buildNav = () => {};

      const wireInputs = (root) => {
        const nodes = root.querySelectorAll("[data-path]");
        for (const node of nodes) {
          const path = node.getAttribute("data-path");
          const getByPath = (p) => p.split(".").reduce((o, k) => (o ? o[k] : undefined), state);
          const setByPath = (p, v) => {
            const keys = p.split(".");
            let cur = state;
            for (let i = 0; i < keys.length - 1; i++) cur = cur[keys[i]];
            cur[keys[keys.length - 1]] = v;
          };
          const val = getByPath(path);
          if (node.tagName === "SELECT") node.value = String(val ?? "");
          else node.value = String(val ?? "");

          const updateValue = () => {
            const path = node.getAttribute("data-path");
            const v = node.type === "number" ? Number(node.value) : node.value;
            setByPath(path, v);
            if (state.soalError) state.soalError = null;
            autoFillPaket();
            try {
              if (path === 'identity.jenjang' || path === 'identity.kesetaraanPaket' || path === 'identity.kelas') {
                const I = state.identity || {};
                const je = resolveJenjang(I.jenjang, I.kesetaraanPaket);
                const faseOpts = MA_FASE_MAP[je] || [];
                const kelasOpts = CLASS_OPTIONS[je] || [];
                const mapelOpts = SUBJECT_OPTIONS[je] || [];
                if (faseOpts.length === 1) I.fase = faseOpts[0];
                else if (I.fase && !faseOpts.includes(I.fase)) {
                  const fl = faseLetterFromLabel(I.fase);
                  const opt = fl ? faseOpts.find(x => new RegExp(`\\bFase\\s+${fl}\\b`, 'i').test(String(x))) : null;
                  I.fase = opt || '';
                }
                if (kelasOpts.length === 1) I.kelas = kelasOpts[0];
                else if (I.kelas && !kelasOpts.includes(I.kelas)) I.kelas = '';
                if (I.mataPelajaran && !mapelOpts.includes(I.mataPelajaran)) I.mataPelajaran = '';
                if (path === 'identity.kelas') {
                  const kn = parseKelasNumber(I.kelas);
                  const fl = expectedFaseLetter(je, kn);
                  const opt = fl ? faseOpts.find(x => new RegExp(`\\bFase\\s+${fl}\\b`, 'i').test(String(x))) : null;
                  if (opt) I.fase = opt;
                }
              }
              if (path === 'lkpd.jenjang' || path === 'lkpd.kesetaraanPaket' || path === 'lkpd.kelas') {
                const L = state.lkpd || {};
                const je = resolveJenjang(L.jenjang, L.kesetaraanPaket);
                const faseOpts = MA_FASE_MAP[je] || [];
                const kelasOpts = CLASS_OPTIONS[je] || [];
                const mapelOpts = SUBJECT_OPTIONS[je] || [];
                if (faseOpts.length === 1) L.fase = faseOpts[0];
                else if (L.fase && !faseOpts.includes(L.fase)) {
                  const fl = faseLetterFromLabel(L.fase);
                  const opt = fl ? faseOpts.find(x => new RegExp(`\\bFase\\s+${fl}\\b`, 'i').test(String(x))) : null;
                  L.fase = opt || '';
                }
                if (kelasOpts.length === 1) L.kelas = kelasOpts[0];
                else if (L.kelas && !kelasOpts.includes(L.kelas)) L.kelas = '';
                if (L.mataPelajaran && !mapelOpts.includes(L.mataPelajaran)) L.mataPelajaran = '';
                if (path === 'lkpd.kelas') {
                  const kn = parseKelasNumber(L.kelas);
                  const fl = expectedFaseLetter(je, kn);
                  const opt = fl ? faseOpts.find(x => new RegExp(`\\bFase\\s+${fl}\\b`, 'i').test(String(x))) : null;
                  if (opt) L.fase = opt;
                }
              }
            } catch {}
          };

          node.addEventListener("input", updateValue);
          node.addEventListener("change", () => {
            saveDebounced(true);
            render();
          });
          
          node.addEventListener("blur", () => {
            saveDebounced(true);
            render();
          });
        }
      };

      const pickLogo = () => {
        const input = el("logoPicker");
        if (!input) return;
        input.value = "";
        input.click();
      };
      const setLkpdSource = (v) => {
        state.lkpd = state.lkpd || {};
        state.lkpd.sumber = v;
        saveDebounced(true);
        render();
      };
      const pickLkpdImage = () => {
        const elp = el("lkpdImgUpload");
        if (!elp) return;
        elp.value = "";
        elp.click();
      };
      const pickLkpdText = () => {
        const elp = el("lkpdTxtUpload");
        if (!elp) return;
        elp.value = "";
        elp.click();
      };
      const pickTopikImage = () => {
        const elp = el("topikImgUpload");
        if (!elp) return;
        elp.value = "";
        elp.click();
      };
      const pickTopikText = () => {
        const elp = el("topikTxtUpload");
        if (!elp) return;
        elp.value = "";
        elp.click();
      };
      const pickTopikPdf = () => {
        const elp = el("topikPdfUpload");
        if (!elp) return;
        elp.value = "";
        elp.click();
      };
      const ensurePdfJs = async () => {
        if (window.pdfjsLib && window.pdfjsLib.getDocument) return;
        const loadScript = (src) => new Promise((resolve, reject) => {
          const existing = document.querySelector(`script[data-pdfjs-src="${src}"]`);
          if (existing) {
            existing.addEventListener('load', resolve, { once: true });
            existing.addEventListener('error', () => reject(new Error('Gagal memuat PDF parser.')), { once: true });
            return;
          }
          const s = document.createElement('script');
          s.src = src;
          s.async = true;
          s.setAttribute('data-pdfjs-src', src);
          s.onload = resolve;
          s.onerror = () => reject(new Error('Gagal memuat PDF parser.'));
          document.head.appendChild(s);
        });

        const candidates = [
          {
            lib: 'https://cdn.jsdelivr.net/npm/pdfjs-dist@4.10.38/legacy/build/pdf.min.js',
            worker: 'https://cdn.jsdelivr.net/npm/pdfjs-dist@4.10.38/legacy/build/pdf.worker.min.js',
          },
          {
            lib: 'https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/pdf.min.js',
            worker: 'https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/pdf.worker.min.js',
          },
        ];

        let lastErr = null;
        for (const c of candidates) {
          try {
            await loadScript(c.lib);
            if (window.pdfjsLib && window.pdfjsLib.getDocument) {
              window.pdfjsLib.GlobalWorkerOptions.workerSrc = c.worker;
              return;
            }
          } catch (e) {
            lastErr = e;
          }
        }
        throw lastErr || new Error('PDF parser tidak tersedia.');
      };
      const pdfToText = async (file, maxPages = 25) => {
        await ensurePdfJs();
        const ab = await file.arrayBuffer();
        const open = async (disableWorker) => window.pdfjsLib.getDocument({ data: ab, disableWorker }).promise;
        let pdf;
        try {
          pdf = await open(false);
        } catch {
          pdf = await open(true);
        }
        const pages = Math.min(maxPages, pdf.numPages || 0);
        const out = [];
        for (let p = 1; p <= pages; p++) {
          const page = await pdf.getPage(p);
          const content = await page.getTextContent();
          const text = (content?.items || []).map(it => String(it?.str || '')).join(' ').replace(/\s+/g, ' ').trim();
          if (text) out.push(text);
        }
        return out.join("\n\n").trim();
      };
      const handleTopikPdfSelected = async (evt) => {
        const file = evt.target?.files?.[0];
        if (!file) return;
        const btn = el("btnTopikUploadPdf");
        const original = btn ? btn.innerHTML : "";
        if (btn) { btn.disabled = true; btn.innerHTML = '<span class="material-symbols-outlined animate-spin text-[18px]">progress_activity</span> Membaca PDF...'; }
        try {
          const text = await pdfToText(file, 25);
          const cleaned = String(text || '').trim();
          if (!cleaned) throw new Error('Teks PDF kosong atau tidak terbaca.');
          state.identity = state.identity || {};
          const prev = String(state.identity.topik_raw || "").replace(/\r\n/g, "\n").replace(/\r/g, "\n").trim();
          const head = `\n\n---\nSumber PDF: ${String(file.name || 'materi.pdf').trim()}\n---\n`;
          const merged = [prev, head + cleaned].filter(Boolean).join("\n").replace(/\n{3,}/g, "\n\n").trim();
          state.identity.topik_raw = merged.slice(0, 30000);
          saveDebounced(true);
          render();
          alert('Sumber materi berhasil dimuat dari PDF.');
        } catch (e) {
          const msg = String(e?.message || '').trim();
          const extra = msg ? `\n\nDetail: ${msg}` : '';
          alert('Gagal membaca PDF. Jika PDF berupa scan gambar, gunakan Upload Gambar agar dibaca OCR.' + extra);
        } finally {
          if (btn) { btn.disabled = false; if (original) btn.innerHTML = original; }
        }
      };
      async function ocrImageToText(file) {
        if (!window.Tesseract) throw new Error("OCR tidak tersedia");
        const result = await Tesseract.recognize(file, "eng");
        return String(result?.data?.text || "");
      }
      const summarizeMateriToTema = async (rawText) => {
        const text = String(rawText || "").replace(/\r\n/g, "\n").replace(/\r/g, "\n").trim();
        if (!text) throw new Error("Teks kosong");
        const clipped = text.slice(0, 12000);
        const prompt = `Ringkas materi berikut menjadi tema/topik untuk pembuatan paket soal.\n\nATURAN:\n- Output HARUS JSON.\n- Tema harus sesuai mapel/jenjang/kelas dari konteks.\n- Buat tema yang spesifik namun ringkas (maks 1–2 kalimat).\n\nOUTPUT JSON:\n{\n  "tema": "....",\n  "kata_kunci": ["...","...","..."]\n}\n\nKONTEKS:\n- Jenjang: ${displayJenjang(state.identity?.jenjang, state.identity?.kesetaraanPaket) || "-"}\n- Kelas: ${String(state.identity?.kelas || "-")}\n- Fase: ${String(state.identity?.fase || "-")}\n- Mapel: ${String(state.identity?.mataPelajaran || "-")}\n\nMATERI:\n${clipped}`;
        const res = await callOpenAI(prompt);
        const tema = String(res?.tema || res?.topik || "").trim();
        const kata = Array.isArray(res?.kata_kunci) ? res.kata_kunci.map(x => String(x || "").trim()).filter(Boolean).slice(0, 7) : [];
        const joined = kata.length ? ` (kata kunci: ${kata.join(", ")})` : "";
        return (tema ? `${tema}${joined}` : clipped.slice(0, 200)).trim();
      };
      const summarizeTopikInput = async () => {
        const rawNode = document.querySelector(`[data-path="identity.topik_raw"]`);
        const ringkasNode = document.querySelector(`[data-path="identity.topik_ringkas"]`);
        const raw = String(rawNode?.value ?? rawNode?.textContent ?? "").trim();
        const fallback = String(ringkasNode?.value ?? ringkasNode?.textContent ?? "").trim();
        const src = raw || fallback;
        if (!src) { alert("Isi Materi Mentah (atau Tema Ringkas) dulu."); return; }
        const btn = el("btnTopikSummarize");
        const original = btn ? btn.innerHTML : "";
        if (btn) { btn.disabled = true; btn.innerHTML = '<span class="material-symbols-outlined animate-spin text-[18px]">progress_activity</span> Meringkas...'; }
        try {
          const tema = await summarizeMateriToTema(src);
          state.identity = state.identity || {};
          state.identity.topik_ringkas = tema;
          saveDebounced(true);
          render();
        } catch (e) {
          alert("Gagal meringkas: " + (e?.message || "Terjadi kesalahan."));
        } finally {
          if (btn) { btn.disabled = false; if (original) btn.innerHTML = original; }
        }
      };
      const setTopikFromMateri = async (text, btn, originalHtml, label) => {
        try {
          state.identity = state.identity || {};
          const rawTxt = String(text || "").replace(/\r\n/g, "\n").replace(/\r/g, "\n").trim();
          state.identity.topik_raw = rawTxt.slice(0, 30000);
          saveDebounced(true);
          render();
          alert(`Sumber materi berhasil dimuat dari ${label}.`);
        } catch (e) {
          alert("Gagal menganalisis materi: " + (e?.message || "Terjadi kesalahan."));
        } finally {
          if (btn) { btn.disabled = false; if (originalHtml) btn.innerHTML = originalHtml; }
        }
      };
      const handleTopikTextSelected = (evt) => {
        const file = evt.target?.files?.[0];
        if (!file) return;
        const btn = el("btnTopikUploadTxt");
        const original = btn ? btn.innerHTML : "";
        if (btn) { btn.disabled = true; btn.innerHTML = '<span class="material-symbols-outlined animate-spin text-[18px]">progress_activity</span> Membaca...'; }
        const reader = new FileReader();
        reader.onload = () => {
          const text = String(reader.result || "");
          setTopikFromMateri(text, btn, original, "file teks");
        };
        reader.onerror = () => { if (btn) { btn.disabled = false; btn.innerHTML = original; } };
        reader.readAsText(file);
      };
      const handleTopikImageSelected = async (evt) => {
        const files = Array.from(evt.target?.files || []).filter(Boolean);
        if (!files.length) return;
        const btn = el("btnTopikUploadImg");
        const original = btn ? btn.innerHTML : "";
        if (btn) { btn.disabled = true; btn.innerHTML = '<span class="material-symbols-outlined animate-spin text-[18px]">progress_activity</span> OCR (0/' + files.length + ')...'; }
        try {
          state.identity = state.identity || {};
          const prev = String(state.identity.topik_raw || "").replace(/\r\n/g, "\n").replace(/\r/g, "\n").trim();
          const chunks = [];
          for (let i = 0; i < files.length; i++) {
            const f = files[i];
            if (btn) btn.innerHTML = '<span class="material-symbols-outlined animate-spin text-[18px]">progress_activity</span> OCR (' + (i + 1) + '/' + files.length + ')...';
            const text = await ocrImageToText(f);
            const cleaned = String(text || "").replace(/\r\n/g, "\n").replace(/\r/g, "\n").trim();
            if (!cleaned) continue;
            const head = `\n\n---\nSumber Gambar: ${String(f.name || `gambar-${i + 1}`).trim()}\n---\n`;
            chunks.push(head + cleaned);
          }
          const merged = [prev, ...chunks].filter(Boolean).join("\n").replace(/\n{3,}/g, "\n\n").trim();
          state.identity.topik_raw = merged.slice(0, 30000);
          saveDebounced(true);
          render();
          alert(files.length > 1 ? `Sumber materi berhasil dimuat dari ${files.length} gambar.` : 'Sumber materi berhasil dimuat dari gambar.');
        } catch (e) {
          alert("Gagal OCR gambar. Silakan coba file lain.");
        } finally {
          if (btn) { btn.disabled = false; if (original) btn.innerHTML = original; }
        }
      };
      const handleLkpdTextSelected = (evt) => {
        const file = evt.target?.files?.[0];
        if (!file) return;
        const btn = el("btnLkpdUploadTxt");
        const original = btn ? btn.innerHTML : "";
        if (btn) { btn.disabled = true; btn.innerHTML = '<span class="material-symbols-outlined animate-spin text-[18px]">progress_activity</span> Memuat...'; }
        const reader = new FileReader();
        reader.onload = () => {
          const text = String(reader.result || "");
          state.lkpd = state.lkpd || {};
          state.lkpd.materi = text;
          saveDebounced(true);
          render();
          if (btn) { btn.disabled = false; btn.innerHTML = original; }
        };
        reader.onerror = () => { if (btn) { btn.disabled = false; btn.innerHTML = original; } };
        reader.readAsText(file);
      };
      const handleLkpdImageSelected = async (evt) => {
        const file = evt.target?.files?.[0];
        if (!file) return;
        const btn = el("btnLkpdUploadImg");
        const original = btn ? btn.innerHTML : "";
        if (btn) { btn.disabled = true; btn.innerHTML = '<span class="material-symbols-outlined animate-spin text-[18px]">progress_activity</span> OCR...'; }
        try {
          const text = await ocrImageToText(file);
          state.lkpd = state.lkpd || {};
          const prev = String(state.lkpd.materi || "");
          state.lkpd.materi = [prev, text].filter(Boolean).join("\n\n");
          saveDebounced(true);
          render();
        } catch (e) {
          alert("Gagal OCR gambar. Silakan coba file lain.");
        } finally {
          if (btn) { btn.disabled = false; btn.innerHTML = original; }
        }
      };
      const buildLKPD = async () => {
        const btn = document.getElementById("btnBuildLKPD");
        const original = btn ? btn.innerHTML : "";
        if (btn) {
          btn.disabled = true;
          btn.innerHTML = '<span class="material-symbols-outlined animate-spin text-[18px]">progress_activity</span> Memproses...';
        }
        const L = state.lkpd || {};
        const I = state.identity || {};
        const mapel = String(L.mataPelajaran || I.mataPelajaran || "-");
        const kelas = String(L.kelas || I.kelas || "-");
        const jenjang = displayJenjang(L.jenjang || I.jenjang, (String(L.jenjang || "").trim() ? L.kesetaraanPaket : I.kesetaraanPaket)) || "-";
        const fase = String(L.fase || I.fase || "-");
        const topik = L.sumber === "upload" ? (L.materi ? L.materi.slice(0, 300) : "-") : (L.topik || "-");
        const aktivitas = String(L.jenisAktivitas || "Eksperimen / Praktikum");
        const tujuan = String(L.tujuan || "").trim();
        const link = String(L.link || "").trim();
        const qrDataUrl = link ? await makeQrDataUrl(link, 140) : "";
        const prompt = `Buatkan LKPD singkat untuk kebutuhan guru Indonesia dengan format JSON.
KONTEKS:
- Jenjang: ${jenjang} ${fase} Kelas ${kelas}
- Mapel: ${mapel}
- Sumber: ${L.sumber === "upload" ? "materi" : "topik"}
- Topik/Materi: ${topik}
- Jenis Aktivitas Utama: ${aktivitas}
- Tujuan: ${tujuan || "-"}

ATURAN:
- Bahasa Indonesia.
- Susun 3-4 aktivitas berurutan.
- Setiap aktivitas: judul singkat, deskripsi ringkas, 3-5 langkah.
- Buat rubrik 4 level untuk 4 kriteria umum.
- Beri bagian 'guru' berisi 3-6 butir kunci/arah jawaban dan 3 butir diferensiasi.

OUTPUT JSON PERSIS:
{
  "judul": "LKPD <Mapel> - <Topik>",
  "petunjuk": ["..."],
  "aktivitas": [
    { "judul": "...", "deskripsi": "...", "langkah": ["...","...","..."], "lembar": "kotak" }
  ],
  "rubrik": [
    { "kriteria":"...", "skor1":"...", "skor2":"...", "skor3":"...", "skor4":"..." }
  ],
  "guru": {
    "kunci": ["..."],
    "diferensiasi": ["..."]
  }
}`;
        let structured = null;
        try {
          const res = await callOpenAI(prompt);
          if (res && typeof res === "object") {
            structured = res;
          } else {
            try { structured = JSON.parse(String(res)); } catch {}
          }
        } catch {}
        const data = structured && (Array.isArray(structured.aktivitas) ? structured : null);
        const titleH3 = `Mata Pelajaran: ${mapel} | Topik: ${safeText(L.sumber === "upload" ? (L.topik || "Materi Pilihan") : (L.topik || "-"))}`;
        const fileName = `LKPD_${mapel.replace(/[^a-z0-9]/gi,'_')}_${kelas.replace(/[^a-z0-9]/gi,'_')}.doc`;
        const mkList = (items) => Array.isArray(items) && items.length ? `<ul>${items.map(s=>`<li>${safeText(String(s||''))}</li>`).join('')}</ul>` : "";
        const mkActivities = (arr) => {
          if (!Array.isArray(arr) || !arr.length) return `
<html xmlns:o='urn:schemas-microsoft-com:office:office' xmlns:w='urn:schemas-microsoft-com:office:word' xmlns='http://www.w3.org/TR/REC-html40'><head><meta charset='utf-8'><title>Dokumen LKPD</title></head><body>
<div class="style-container">
<style>
body{font-family:'Times New Roman',serif;font-size:11pt;line-height:1.5;color:#000;margin:0;padding:0;background:#f0f0f0}
.main-wrapper{max-width:21cm;margin:0 auto;background:#fff;padding:2cm;border:1px solid #ccc;box-shadow:0 0 10px rgba(0,0,0,.1);box-sizing:border-box}
@media print{body{background:#fff}.main-wrapper{margin:0;padding:0;border:none;box-shadow:none;width:100%;max-width:none}.page-break{page-break-before:always}}
.header{text-align:center;border-bottom:3px double #000;padding-bottom:20px;margin-bottom:30px}
.header h1{font-size:18pt;margin:0;font-weight:bold;text-transform:uppercase;letter-spacing:2px}
.header h3{font-size:12pt;margin:5px 0 0 0;font-weight:normal}
.identity-box{border:1px solid #000;padding:10px;margin-bottom:30px;width:100%;box-sizing:border-box}
.identity-table{width:100%;border-collapse:collapse;border:none}
.identity-table td{padding:8px 5px;vertical-align:bottom;width:50%;border:none}
.section-title{background:#000;color:#fff;padding:8px 15px;font-weight:bold;font-size:12pt;text-transform:uppercase;margin-bottom:15px;border:1px solid #000;text-align:center}
.content-text{margin-bottom:20px;text-align:justify}
.activity-box{border:1px solid #000;padding:20px;margin-bottom:25px;page-break-inside:avoid;background:#fff;position:relative}
.activity-header{background:#e0e0e0;padding:5px 10px;font-weight:bold;border-bottom:1px solid #000;margin:-20px -20px 20px -20px;font-size:11pt;text-transform:uppercase}
.answer-line{border-bottom:1px dotted #000;height:25px;width:100%;display:block;margin-top:5px}
.answer-box{border:1px solid #000;height:100px;width:100%;display:block;margin-top:10px}
table.rubric{width:100%;border-collapse:collapse;border:1px solid #000;margin-top:10px}
table.rubric th{background:#e0e0e0;border:1px solid #000;padding:8px;text-align:center;font-weight:bold}
table.rubric td{border:1px solid #000;padding:8px;vertical-align:top}
.page-break-marker{page-break-before:always;border-top:2px dashed #999;margin-top:50px;padding-top:20px;text-align:center;color:#999;font-style:italic}
.teacher-box{border:4px double #000;padding:30px;margin-top:20px;background:#fff}
</style>
</div>
<div class="main-wrapper">
  <div class="header">
    <h1>LEMBAR KERJA PESERTA DIDIK</h1>
    <h3>${safeText(titleH3)}</h3>
  </div>
  <div class="identity-box">
    <table class="identity-table"><tbody>
      <tr><td>Nama: ........................................</td><td>Kelompok: ........................................</td></tr>
      <tr><td>Kelas: ${safeText(kelas || "........................................")}</td><td>Tanggal: ........................................</td></tr>
    </tbody></table>
  </div>
  <div class="section-title" style="margin-top:0">Petunjuk Belajar</div>
  <div class="content-text">
    <ul>
      <li>Berdoalah sebelum memulai kegiatan.</li>
      <li>Baca setiap instruksi dengan teliti.</li>
      <li>Kerjakan aktivitas urut dari awal hingga akhir.</li>
      <li>Tanyakan pada guru jika mengalami kesulitan.</li>
    </ul>
  </div>
  <div class="section-title">Lembar Kerja & Aktivitas Siswa</div>
  <div class="activity-box">
    <div class="activity-header">Aktivitas 1: Pemahaman Awal</div>
    <div class="content-text">Jelaskan pengetahuan awal tentang topik ${safeText(topik)} pada mata pelajaran ${safeText(mapel)}. Tuliskan poin penting pada ruang yang tersedia.</div>
    <span class="answer-box"></span>
  </div>
  <div class="activity-box">
    <div class="activity-header">Aktivitas 2: ${safeText(aktivitas)}</div>
    <div class="content-text">Lakukan kegiatan utama yang dirancang guru terkait ${safeText(topik)}. Dokumentasikan proses, temuan, dan refleksi hasil kegiatan.</div>
    <span class="answer-box"></span>
  </div>
  <div class="activity-box">
    <div class="activity-header">Aktivitas 3: Diskusi & Presentasi</div>
    <div class="content-text">Diskusikan hasil dengan kelompok lalu presentasikan. Catat umpan balik dan perbaikan yang disepakati.</div>
    <span class="answer-box"></span>
  </div>
  <div class="activity-box">
    <div class="activity-header">Aktivitas 4: Refleksi Diri</div>
    <div class="content-text">Tuliskan refleksi pembelajaran: hal baru yang dipahami, tantangan, dan rencana belajar selanjutnya.</div>
    <span class="answer-box"></span>
  </div>
  <div class="section-title">Rubrik Penilaian</div>
  <table class="rubric">
    <tr><th>Kriteria</th><th>Skor 1</th><th>Skor 2</th><th>Skor 3</th><th>Skor 4</th></tr>
    <tr><td>Partisipasi</td><td>Pasif</td><td>Cukup aktif</td><td>Aktif</td><td>Sangat aktif</td></tr>
    <tr><td>Kualitas Produk</td><td>Tidak sesuai</td><td>Cukup sesuai</td><td>Sesuai</td><td>Sangat sesuai & kreatif</td></tr>
    <tr><td>Kerja Sama</td><td>Kurang</td><td>Cukup</td><td>Baik</td><td>Sangat baik</td></tr>
    <tr><td>Presentasi</td><td>Tidak jelas</td><td>Cukup jelas</td><td>Jelas</td><td>Sangat jelas & menarik</td></tr>
  </table>
  ${tujuan ? `<div class="section-title">Tujuan Pembelajaran</div><div class="content-text">${safeText(tujuan)}</div>` : ``}
  ${link ? `<div class="content-text">Link Materi: ${safeText(link)}</div>` : ``}
</div>
<div class="page-break-marker">-- Potong di sini (Khusus Guru) --</div>
<div class="main-wrapper teacher-box">
  <div class="header"><h1>Panduan Guru & Jawaban</h1></div>
  <h3>A. Kunci Jawaban/Checklist Penilaian</h3>
  <div class="content-text">Disesuaikan oleh guru berdasarkan indikator dan tujuan pembelajaran untuk topik ${safeText(topik)}.</div>
  <h3>B. Strategi Diferensiasi</h3>
  <div class="content-text">Sediakan pengayaan dan remedial sesuai kebutuhan siswa.</div>
</div>
</body></html>`;
          return html;
        };
        let html = "";
        if (data) {
          const judul = String(data.judul || `LKPD ${mapel} - ${L.topik || "Topik"}`);
          const petunjuk = Array.isArray(data.petunjuk) && data.petunjuk.length ? data.petunjuk : [
            "Berdoalah sebelum memulai kegiatan.",
            "Baca setiap instruksi dengan teliti.",
            "Kerjakan aktivitas urut dari awal hingga akhir.",
            "Tanyakan pada guru jika mengalami kesulitan."
          ];
          const acts = Array.isArray(data.aktivitas) ? data.aktivitas : [];
          const rub = Array.isArray(data.rubrik) ? data.rubrik : [];
          const guru = data.guru || {};
          const actHtml = acts.map((a, idx) => `
            <div class="activity-box">
              <div class="activity-header">${safeText(String(a.judul || `Aktivitas ${idx+1}`))}</div>
              <div class="content-text">${safeText(String(a.deskripsi || ""))}</div>
              ${mkList(a.langkah)}
              <span class="${String(a.lembar||'kotak')==='garis'?'answer-line':'answer-box'}"></span>
            </div>
          `).join('');
          const rubHtml = rub.length ? `
            <div class="section-title">Rubrik Penilaian</div>
            <table class="rubric">
              <tr><th>Kriteria</th><th>Skor 1</th><th>Skor 2</th><th>Skor 3</th><th>Skor 4</th></tr>
              ${rub.map(r=>`<tr><td>${safeText(r.kriteria||'')}</td><td>${safeText(r.skor1||'')}</td><td>${safeText(r.skor2||'')}</td><td>${safeText(r.skor3||'')}</td><td>${safeText(r.skor4||'')}</td></tr>`).join('')}
            </table>` : '';
          html = `
<html xmlns:o='urn:schemas-microsoft-com:office:office' xmlns:w='urn:schemas-microsoft-com:office:word' xmlns='http://www.w3.org/TR/REC-html40'><head><meta charset='utf-8'><title>${safeText(judul)}</title></head><body>
<div class="style-container">
<style>
body{font-family:'Times New Roman',serif;font-size:11pt;line-height:1.5;color:#000;margin:0;padding:0;background:#f0f0f0}
.main-wrapper{max-width:21cm;margin:0 auto;background:#fff;padding:2cm;border:1px solid #ccc;box-shadow:0 0 10px rgba(0,0,0,.1);box-sizing:border-box}
@media print{body{background:#fff}.main-wrapper{margin:0;padding:0;border:none;box-shadow:none;width:100%;max-width:none}.page-break{page-break-before:always}}
.header{text-align:center;border-bottom:3px double #000;padding-bottom:20px;margin-bottom:30px}
.header h1{font-size:18pt;margin:0;font-weight:bold;text-transform:uppercase;letter-spacing:2px}
.header h3{font-size:12pt;margin:5px 0 0 0;font-weight:normal}
.identity-box{border:1px solid #000;padding:10px;margin-bottom:30px;width:100%;box-sizing:border-box}
.identity-table{width:100%;border-collapse:collapse;border:none}
.identity-table td{padding:8px 5px;vertical-align:bottom;width:50%;border:none}
.section-title{background:#000;color:#fff;padding:8px 15px;font-weight:bold;font-size:12pt;text-transform:uppercase;margin-bottom:15px;border:1px solid #000;text-align:center}
.content-text{margin-bottom:20px;text-align:justify}
.activity-box{border:1px solid #000;padding:20px;margin-bottom:25px;page-break-inside:avoid;background:#fff;position:relative}
.activity-header{background:#e0e0e0;padding:5px 10px;font-weight:bold;border-bottom:1px solid #000;margin:-20px -20px 20px -20px;font-size:11pt;text-transform:uppercase}
.answer-line{border-bottom:1px dotted #000;height:25px;width:100%;display:block;margin-top:5px}
.answer-box{border:1px solid #000;height:100px;width:100%;display:block;margin-top:10px}
table.rubric{width:100%;border-collapse:collapse;border:1px solid #000;margin-top:10px}
table.rubric th{background:#e0e0e0;border:1px solid #000;padding:8px;text-align:center;font-weight:bold}
table.rubric td{border:1px solid #000;padding:8px;vertical-align:top}
.page-break-marker{page-break-before:always;border-top:2px dashed #999;margin-top:50px;padding-top:20px;text-align:center;color:#999;font-style:italic}
.teacher-box{border:4px double #000;padding:30px;margin-top:20px;background:#fff}
</style>
</div>
<div class="main-wrapper">
  <div class="header">
    <h1>LEMBAR KERJA PESERTA DIDIK</h1>
    <h3>Mata Pelajaran: ${safeText(mapel)} | Topik: ${safeText(L.topik || 'Materi Pilihan')}</h3>
  </div>
  <div class="identity-box">
    <table class="identity-table"><tbody>
      <tr><td>Nama: ........................................</td><td>Kelompok: ........................................</td></tr>
      <tr><td>Kelas: ${safeText(kelas)}</td><td>Tanggal: ........................................</td></tr>
    </tbody></table>
  </div>
  <div class="section-title" style="margin-top:0">Petunjuk Belajar</div>
  <div class="content-text">${mkList(petunjuk)}</div>
  <div class="section-title">Lembar Kerja & Aktivitas Siswa</div>
  ${actHtml}
  ${rubHtml}
  ${tujuan ? `<div class="section-title">Tujuan Pembelajaran</div><div class="content-text">${safeText(tujuan)}</div>` : ``}
  ${link ? `<div class="content-text">Link Materi: ${safeText(link)}</div>${qrDataUrl ? `<div style="text-align:center;margin:10px 0;"><img src="${qrDataUrl}" style="width:140px;height:140px"><div style="font-size:8pt">Scan untuk membuka link</div></div>` : ``}` : ``}
</div>
<div class="page-break-marker">-- Potong di sini (Khusus Guru) --</div>
<div class="main-wrapper teacher-box">
  <div class="header"><h1>Panduan Guru & Jawaban</h1></div>
  <h3>A. Kunci Jawaban/Checklist Penilaian</h3>
  <div class="content-text">${mkList(guru.kunci)}</div>
  <h3>B. Strategi Diferensiasi</h3>
  <div class="content-text">${mkList(guru.diferensiasi)}</div>
</div>
</body></html>`;
        } else {
          html = mkActivities(null);
        }
        downloadDOC(html, fileName);
        try {
          const usageIn = Number(structured?._usage?.in || 0);
          const usageOut = Number(structured?._usage?.out || 0);
          const title = `LKPD - ${mapel} - ${kelas}`;
          const snapshot = { identity: state.identity || {}, lkpd: L, questions: [] };
          await fetch("api/soal_user.php", {
            method: "POST",
            headers: {"Content-Type":"application/json"},
            body: JSON.stringify({ type: "save", title, state: snapshot, token_input: usageIn, token_output: usageOut, model: OPENAI_MODEL })
          });
          await fetch("api/openai_proxy.php", {
            method: "POST",
            headers: {"Content-Type":"application/json"},
            credentials: "same-origin",
            body: JSON.stringify({ type: "add_tokens", input_tokens: usageIn, output_tokens: usageOut })
          });
          await fetch("api/openai_proxy.php", {
            method: "POST",
            headers: {"Content-Type":"application/json"},
            credentials: "same-origin",
            body: JSON.stringify({ type: "decrement_package" })
          });
          try { await computeStats(); } catch {}
        } catch {}
        if (btn) {
          btn.disabled = false;
          btn.innerHTML = original;
        }
      };
      const clearLogo = () => {
        state.identity.logo = "";
        saveDebounced(true);
        render();
      };
      const handleLogoSelected = (evt) => {
        const file = evt.target?.files?.[0];
        if (!file) return;
        const MAX = 200 * 1024;
        if (file.size > MAX) {
          alert("Ukuran logo maksimal 200KB. Mohon kompres terlebih dahulu.");
          return;
        }
        const reader = new FileReader();
        reader.onload = () => {
          state.identity.logo = reader.result;
          saveDebounced(true);
          render();
        };
        reader.readAsDataURL(file);
      };

      const exportDocx = async () => {
        if (state.questions.length === 0) return alert("Belum ada soal!");
        
        const btnExport = el("btnExport");
        const originalText = btnExport ? btnExport.innerHTML : "";
        const originalDisabled = btnExport ? btnExport.disabled : false;
  
        try {
          if (btnExport) {
            btnExport.innerHTML = `<span class="animate-spin material-symbols-outlined text-[18px]">progress_activity</span> Proses...`;
            btnExport.disabled = true;
          }
  
          const { Document, Packer, Paragraph, TextRun, AlignmentType, Table, TableRow, TableCell, WidthType, BorderStyle, ImageRun } = docx;
  
          const makeHeader = (title, kind) => {
            const headerTitle = new Paragraph({
              children: [
                new TextRun({ text: (state.identity.namaSekolah || "NAMA SEKOLAH").toUpperCase(), bold: true, size: 28 }),
                new TextRun({ text: "\n", break: 1 }),
                new TextRun({ text: title, bold: true, size: 24 }),
                new TextRun({ text: "\n", break: 1 }),
                new TextRun({ text: `Tahun Pelajaran ${state.paket.tahunAjaran}`, size: 20 }),
              ],
              alignment: AlignmentType.CENTER,
              spacing: { after: 300 },
            });
            const leftInner = new Table({
              width: { size: 100, type: WidthType.PERCENTAGE },
              borders: { top: { style: BorderStyle.NONE }, bottom: { style: BorderStyle.NONE }, left: { style: BorderStyle.NONE }, right: { style: BorderStyle.NONE }, insideHorizontal: { style: BorderStyle.NONE }, insideVertical: { style: BorderStyle.NONE } },
              rows: [
                new TableRow({
                  children: [
                    new TableCell({ children: [new Paragraph({ children: [new TextRun({ text: "Mata Pelajaran", bold: true })] })], width: { size: 40, type: WidthType.PERCENTAGE } }),
                    new TableCell({ children: [new Paragraph({ text: ":" })], width: { size: 5, type: WidthType.PERCENTAGE } }),
                    new TableCell({ children: [new Paragraph({ text: String(state.identity.mataPelajaran || "-") })] }),
                  ],
                }),
                new TableRow({
                  children: [
                    new TableCell({ children: [new Paragraph({ children: [new TextRun({ text: "Kelas / Fase", bold: true })] })], width: { size: 40, type: WidthType.PERCENTAGE } }),
                    new TableCell({ children: [new Paragraph({ text: ":" })], width: { size: 5, type: WidthType.PERCENTAGE } }),
                    new TableCell({ children: [new Paragraph({ text: `${String(state.identity.kelas || "-")} / ${String(state.identity.fase || "-")}` })] }),
                  ],
                }),
                ...(kind === "naskah"
                  ? [
                      new TableRow({
                        children: [
                          new TableCell({ children: [new Paragraph({ children: [new TextRun({ text: "Hari / Tanggal", bold: true })] })], width: { size: 40, type: WidthType.PERCENTAGE } }),
                          new TableCell({ children: [new Paragraph({ text: ":" })], width: { size: 5, type: WidthType.PERCENTAGE } }),
                          new TableCell({ children: [new Paragraph({ text: "______________________________" })] }),
                        ],
                      }),
                    ]
                  : identityTopikDisplay(state.identity)
                  ? [
                      new TableRow({
                        children: [
                          new TableCell({ children: [new Paragraph({ children: [new TextRun({ text: "Topik / Lingkup Materi", bold: true })] })], width: { size: 40, type: WidthType.PERCENTAGE } }),
                          new TableCell({ children: [new Paragraph({ text: ":" })], width: { size: 5, type: WidthType.PERCENTAGE } }),
                          new TableCell({ children: [new Paragraph({ text: String(identityTopikDisplay(state.identity) || "-") })] }),
                        ],
                      }),
                    ]
                  : []),
              ],
            });
            const rightInner = new Table({
              width: { size: 100, type: WidthType.PERCENTAGE },
              borders: { top: { style: BorderStyle.NONE }, bottom: { style: BorderStyle.NONE }, left: { style: BorderStyle.NONE }, right: { style: BorderStyle.NONE }, insideHorizontal: { style: BorderStyle.NONE }, insideVertical: { style: BorderStyle.NONE } },
              rows: [
                ...(kind === "naskah"
                  ? [
                      new TableRow({
                        children: [
                          new TableCell({ children: [new Paragraph({ children: [new TextRun({ text: "Waktu", bold: true })] })], width: { size: 40, type: WidthType.PERCENTAGE } }),
                          new TableCell({ children: [new Paragraph({ text: ":" })], width: { size: 5, type: WidthType.PERCENTAGE } }),
                          new TableCell({ children: [new Paragraph({ text: "______________________________" })] }),
                        ],
                      }),
                      new TableRow({
                        children: [
                          new TableCell({ children: [new Paragraph({ children: [new TextRun({ text: "Nama Siswa", bold: true })] })], width: { size: 40, type: WidthType.PERCENTAGE } }),
                          new TableCell({ children: [new Paragraph({ text: ":" })], width: { size: 5, type: WidthType.PERCENTAGE } }),
                          new TableCell({ children: [new Paragraph({ text: "______________________________" })] }),
                        ],
                      }),
                      new TableRow({
                        children: [
                          new TableCell({ children: [new Paragraph({ children: [new TextRun({ text: "No. Absen / Ruang", bold: true })] })], width: { size: 40, type: WidthType.PERCENTAGE } }),
                          new TableCell({ children: [new Paragraph({ text: ":" })], width: { size: 5, type: WidthType.PERCENTAGE } }),
                          new TableCell({ children: [new Paragraph({ text: "______________________________" })] }),
                        ],
                      }),
                    ]
                  : [
                      new TableRow({
                        children: [
                          new TableCell({ children: [new Paragraph({ children: [new TextRun({ text: "Kurikulum", bold: true })] })], width: { size: 40, type: WidthType.PERCENTAGE } }),
                          new TableCell({ children: [new Paragraph({ text: ":" })], width: { size: 5, type: WidthType.PERCENTAGE } }),
                          new TableCell({ children: [new Paragraph({ text: "Merdeka" })] }),
                        ],
                      }),
                      new TableRow({
                        children: [
                          new TableCell({ children: [new Paragraph({ children: [new TextRun({ text: "Jumlah Soal", bold: true })] })], width: { size: 40, type: WidthType.PERCENTAGE } }),
                          new TableCell({ children: [new Paragraph({ text: ":" })], width: { size: 5, type: WidthType.PERCENTAGE } }),
                          new TableCell({ children: [new Paragraph({ text: String(state.questions.length) })] }),
                        ],
                      }),
                    ]),
              ],
            });
            const headerTable = new Table({
              width: { size: 100, type: WidthType.PERCENTAGE },
              borders: {
                top: { style: BorderStyle.NONE, size: 0, color: "FFFFFF" },
                bottom: { style: BorderStyle.SINGLE, size: 6, color: "000000" },
                left: { style: BorderStyle.NONE, size: 0, color: "FFFFFF" },
                right: { style: BorderStyle.NONE, size: 0, color: "FFFFFF" },
              },
              rows: [
                new TableRow({
                  children: [
                    new TableCell({
                      children: [leftInner],
                      width: { size: 50, type: WidthType.PERCENTAGE },
                      margins: { top: 100, bottom: 100, left: 100, right: 100 },
                      borders: { top: { style: BorderStyle.NONE }, bottom: { style: BorderStyle.NONE }, left: { style: BorderStyle.NONE }, right: { style: BorderStyle.NONE } },
                    }),
                    new TableCell({
                      children: [rightInner],
                      width: { size: 50, type: WidthType.PERCENTAGE },
                      margins: { top: 100, bottom: 100, left: 100, right: 100 },
                      borders: { top: { style: BorderStyle.NONE }, bottom: { style: BorderStyle.NONE }, left: { style: BorderStyle.NONE }, right: { style: BorderStyle.NONE } },
                    }),
                  ],
                }),
              ],
            });
            return { headerTitle, headerTable };
          };
  
          const spacer = new Paragraph({ spacing: { after: 400 } });
  
          const processedQuestions = await Promise.all(state.questions.map(async (q) => {
            let imgData = null;
            if (q.image) {
              try {
                const resp = await fetch(q.image);
                const blob = await resp.blob();
                const buffer = new Uint8Array(await blob.arrayBuffer());
                const dimensions = await new Promise((resolve) => {
                  const img = new Image();
                  const u = URL.createObjectURL(blob);
                  img.onload = () => { URL.revokeObjectURL(u); resolve({ width: img.naturalWidth, height: img.naturalHeight }); };
                  img.onerror = () => { URL.revokeObjectURL(u); resolve({ width: 300, height: 300 }); };
                  img.src = u;
                });
                const maxWidth = 100;
                const dw = Math.max(1, Number(dimensions.width || 300));
                const dh = Math.max(1, Number(dimensions.height || 300));
                const ratio = dw / dh;
                const width = Math.max(1, Math.round(Math.min(maxWidth, dw)));
                const height = Math.max(1, Math.round(width / ratio));
                imgData = { buffer, width, height };
              } catch {}
            }
            return { ...q, _img: imgData };
          }));
  
          const buildNaskahSection = () => {
            const { headerTitle, headerTable } = makeHeader(String(state.paket.judul || "NASKAH SOAL").toUpperCase(), "naskah");
            const questionParagraphs = [];
            const sections = [
              { type: 'pg', title: 'PILIHAN GANDA', subtitle: 'Pilihlah salah satu jawaban yang paling tepat!' },
              { type: 'benar_salah', title: 'BENAR / SALAH', subtitle: 'Pilihlah jawaban Benar atau Salah!' },
              { type: 'pg_kompleks', title: 'PILIHAN GANDA KOMPLEKS', subtitle: 'Pilihlah jawaban yang benar (bisa lebih dari satu)!' },
              { type: 'menjodohkan', title: 'MENJODOHKAN', subtitle: 'Jodohkanlah pernyataan pada lajur kiri dengan jawaban pada lajur kanan!' },
              { type: 'isian', title: 'ISIAN SINGKAT', subtitle: 'Jawablah pertanyaan berikut dengan singkat dan tepat!' },
              { type: 'uraian', title: 'URAIAN', subtitle: 'Jawablah pertanyaan-pertanyaan berikut dengan jelas dan benar!' },
            ];
            let sectionIndex = 0;
            for (const sec of sections) {
              const items = processedQuestions.filter(q => q.type === sec.type);
              if (items.length === 0) continue;
              const letter = String.fromCharCode(65 + sectionIndex);
              sectionIndex++;
              questionParagraphs.push(
                new Paragraph({ children: [new TextRun({ text: `${letter}. ${sec.title}`, bold: true })], spacing: { before: 200, after: 100 } }),
                new Paragraph({ children: [new TextRun({ text: sec.subtitle, italics: true })], spacing: { after: 300 } })
              );
              const normKey = (t) => String(t || '').replace(/\r\n/g, '\n').replace(/\r/g, '\n').replace(/\s+/g, ' ').trim();
              const pushOne = (q, num) => {
                questionParagraphs.push(
                  new Paragraph({
                    children: [new TextRun({ text: `${num}.\t${q.question}`, bold: false })],
                    tabStops: [{ type: "left", position: 400 }],
                    indent: { left: 0, hanging: 0 },
                    spacing: { before: 200, after: 100 },
                    keepLines: true,
                  })
                );
                if (q._img) {
                  questionParagraphs.push(
                    new Paragraph({
                      children: [
                        new ImageRun({
                          data: q._img.buffer,
                          transformation: { width: q._img.width, height: q._img.height },
                        }),
                      ],
                      alignment: AlignmentType.LEFT,
                      indent: { left: 400 },
                      spacing: { after: 200 },
                    })
                  );
                }
                if (sec.type === 'pg' || sec.type === 'benar_salah' || sec.type === 'pg_kompleks') {
                  q.options.forEach((opt, idx) => {
                    questionParagraphs.push(
                      new Paragraph({
                        children: [new TextRun({ text: `${String.fromCharCode(65 + idx)}.\t${opt}` })],
                        tabStops: [{ type: "left", position: 300 }],
                        indent: { left: 400, hanging: 0 },
                        spacing: { after: 50 },
                      })
                    );
                  });
                } else if (sec.type === 'menjodohkan') {
                  const leftList = Array.isArray(q.options) ? q.options : [];
                  const rightList = Array.isArray(q.answer) ? q.answer : [];
                  const leftParas = [
                    new Paragraph({ children: [new TextRun({ text: "Lajur A (Pernyataan)", bold: true })], spacing: { after: 80 } }),
                    ...leftList.map((opt, idx) =>
                      new Paragraph({ children: [new TextRun({ text: `${idx + 1}. ${String(opt ?? "")}` })], spacing: { after: 40 } })
                    ),
                  ];
                  const rightParas = [
                    new Paragraph({ children: [new TextRun({ text: "Lajur B (Jawaban)", bold: true })], spacing: { after: 80 } }),
                    ...rightList.map((opt, idx) =>
                      new Paragraph({ children: [new TextRun({ text: `${String.fromCharCode(65 + idx)}. ${String(opt ?? "")}` })], spacing: { after: 40 } })
                    ),
                  ];
                  questionParagraphs.push(
                    new Table({
                      width: { size: 100, type: WidthType.PERCENTAGE },
                      borders: {
                        top: { style: BorderStyle.NONE, size: 0, color: "FFFFFF" },
                        bottom: { style: BorderStyle.NONE, size: 0, color: "FFFFFF" },
                        left: { style: BorderStyle.NONE, size: 0, color: "FFFFFF" },
                        right: { style: BorderStyle.NONE, size: 0, color: "FFFFFF" },
                        insideHorizontal: { style: BorderStyle.NONE, size: 0, color: "FFFFFF" },
                        insideVertical: { style: BorderStyle.SINGLE, size: 6, color: "000000" },
                      },
                      rows: [
                        new TableRow({
                          children: [
                            new TableCell({
                              width: { size: 50, type: WidthType.PERCENTAGE },
                              margins: { top: 80, bottom: 80, left: 140, right: 140 },
                              children: leftParas,
                            }),
                            new TableCell({
                              width: { size: 50, type: WidthType.PERCENTAGE },
                              margins: { top: 80, bottom: 80, left: 140, right: 140 },
                              children: rightParas,
                            }),
                          ],
                        }),
                      ],
                    })
                  );
                  questionParagraphs.push(new Paragraph({ spacing: { after: 200 } }));
                } else if (sec.type === 'isian' || sec.type === 'uraian') {
                  if (sec.type === 'uraian') {
                    questionParagraphs.push(new Paragraph({ children: [new TextRun({ text: "\n" })] }));
                    questionParagraphs.push(
                      new Paragraph({
                        children: [new TextRun({ text: "__________________________________________________________________________" })],
                        spacing: { before: 0, after: 100 },
                        indent: { left: 400 },
                      })
                    );
                  } else {
                    questionParagraphs.push(
                      new Paragraph({
                        children: [new TextRun({ text: "Jawaban: ___________________________________" })],
                        spacing: { before: 0, after: 100 },
                        indent: { left: 400 },
                      })
                    );
                  }
                }
                questionParagraphs.push(new Paragraph({ spacing: { after: 200 } }));
              };
              for (let i = 0; i < items.length; i++) {
                const q = items[i];
                const ctxText = String(q.context || '').replace(/\r\n/g, '\n').replace(/\r/g, '\n').trim();
                if (ctxText) {
                  const key = normKey(ctxText);
                  let j = i;
                  while (j + 1 < items.length) {
                    const nxt = items[j + 1];
                    const nxtCtx = String(nxt?.context || '').replace(/\r\n/g, '\n').replace(/\r/g, '\n').trim();
                    if (!nxtCtx) break;
                    if (normKey(nxtCtx) !== key) break;
                    j++;
                  }
                  const a = i + 1;
                  const b = j + 1;
                  const rangeText = a === b ? `nomor ${a}` : `nomor ${a} s.d. ${b}`;
                  questionParagraphs.push(new Paragraph({ children: [new TextRun({ text: `Untuk menjawab soal ${rangeText}, pahami bacaan berikut.`, italics: true })], spacing: { before: 100, after: 80 } }));
                  const paras = ctxText.split(/\n\s*\n/).map(s => String(s || '').trim()).filter(Boolean);
                  paras.forEach((p) => questionParagraphs.push(new Paragraph({ children: [new TextRun({ text: p, bold: false })], spacing: { after: 80 }, indent: { left: 400 } })));
                  for (let k = i; k <= j; k++) pushOne(items[k], k + 1);
                  i = j;
                  continue;
                }
                pushOne(q, i + 1);
              }
            }
            return { headerTitle, headerTable, spacer, questionParagraphs };
          };
  
          const naskah = buildNaskahSection();
          const doc = new Document({
            sections: [
              { properties: { page: { margin: { top: 720, bottom: 720, left: 720, right: 720 } } }, children: [naskah.headerTitle, naskah.headerTable, naskah.spacer, ...naskah.questionParagraphs] },
            ],
          });
          const blob = await Packer.toBlob(doc);
          const link = document.createElement("a");
          link.href = URL.createObjectURL(blob);
          const safeMapel = (state.identity.mataPelajaran || "Soal").replace(/[^a-z0-9]/gi, '_');
          link.download = `GuruPintar_${safeMapel}.docx`;
          link.click();
        } catch (e) {
          console.error(e);
          alert("Gagal export docx: " + e.message);
        } finally {
          if (btnExport) {
            btnExport.innerHTML = originalText;
            btnExport.disabled = originalDisabled;
          }
        }
      };

      const exportKunciDocx = async () => {
         if (state.questions.length === 0) return alert("Belum ada soal!");
        
        const btn = el("btnExportKunci");
        const originalText = btn ? btn.innerHTML : "";
        const originalDisabled = btn ? btn.disabled : false;
  
        try {
            if (btn) {
              btn.innerHTML = `<span class="animate-spin material-symbols-outlined text-[18px]">progress_activity</span> Proses...`;
              btn.disabled = true;
            }
  
            const { Document, Packer, Paragraph, TextRun, AlignmentType, Table, TableRow, TableCell, WidthType, BorderStyle } = docx;
  
            const makeHeader = (title) => {
              const headerTitle = new Paragraph({
                children: [
                  new TextRun({ text: (state.identity.namaSekolah || "NAMA SEKOLAH").toUpperCase(), bold: true, size: 28 }),
                  new TextRun({ text: "\n", break: 1 }),
                  new TextRun({ text: title, bold: true, size: 24 }),
                  new TextRun({ text: "\n", break: 1 }),
                  new TextRun({ text: `Tahun Pelajaran ${state.paket.tahunAjaran}`, size: 20 }),
                ],
                alignment: AlignmentType.CENTER,
                spacing: { after: 300 },
              });
              const leftInner = new Table({
                width: { size: 100, type: WidthType.PERCENTAGE },
                borders: { top: { style: BorderStyle.NONE }, bottom: { style: BorderStyle.NONE }, left: { style: BorderStyle.NONE }, right: { style: BorderStyle.NONE }, insideHorizontal: { style: BorderStyle.NONE }, insideVertical: { style: BorderStyle.NONE } },
                rows: [
                  new TableRow({
                    children: [
                      new TableCell({ children: [new Paragraph({ children: [new TextRun({ text: "Mata Pelajaran", bold: true })] })], width: { size: 40, type: WidthType.PERCENTAGE } }),
                      new TableCell({ children: [new Paragraph({ text: ":" })], width: { size: 5, type: WidthType.PERCENTAGE } }),
                      new TableCell({ children: [new Paragraph({ text: String(state.identity.mataPelajaran || "-") })] }),
                    ],
                  }),
                  new TableRow({
                    children: [
                      new TableCell({ children: [new Paragraph({ children: [new TextRun({ text: "Kelas / Fase", bold: true })] })], width: { size: 40, type: WidthType.PERCENTAGE } }),
                      new TableCell({ children: [new Paragraph({ text: ":" })], width: { size: 5, type: WidthType.PERCENTAGE } }),
                      new TableCell({ children: [new Paragraph({ text: `${String(state.identity.kelas || "-")} / ${String(state.identity.fase || "-")}` })] }),
                    ],
                  }),
                  ...(identityTopikDisplay(state.identity)
                    ? [
                        new TableRow({
                          children: [
                            new TableCell({ children: [new Paragraph({ children: [new TextRun({ text: "Topik / Lingkup Materi", bold: true })] })], width: { size: 40, type: WidthType.PERCENTAGE } }),
                            new TableCell({ children: [new Paragraph({ text: ":" })], width: { size: 5, type: WidthType.PERCENTAGE } }),
                            new TableCell({ children: [new Paragraph({ text: String(identityTopikDisplay(state.identity) || "-") })] }),
                          ],
                        }),
                      ]
                    : []),
                ],
              });
              const rightInner = new Table({
                width: { size: 100, type: WidthType.PERCENTAGE },
                borders: { top: { style: BorderStyle.NONE }, bottom: { style: BorderStyle.NONE }, left: { style: BorderStyle.NONE }, right: { style: BorderStyle.NONE }, insideHorizontal: { style: BorderStyle.NONE }, insideVertical: { style: BorderStyle.NONE } },
                rows: [
                  new TableRow({
                    children: [
                      new TableCell({ children: [new Paragraph({ children: [new TextRun({ text: "Kurikulum", bold: true })] })], width: { size: 40, type: WidthType.PERCENTAGE } }),
                      new TableCell({ children: [new Paragraph({ text: ":" })], width: { size: 5, type: WidthType.PERCENTAGE } }),
                      new TableCell({ children: [new Paragraph({ text: "Merdeka" })] }),
                    ],
                  }),
                  new TableRow({
                    children: [
                      new TableCell({ children: [new Paragraph({ children: [new TextRun({ text: "Jumlah Soal", bold: true })] })], width: { size: 40, type: WidthType.PERCENTAGE } }),
                      new TableCell({ children: [new Paragraph({ text: ":" })], width: { size: 5, type: WidthType.PERCENTAGE } }),
                      new TableCell({ children: [new Paragraph({ text: String(state.questions.length) })] }),
                    ],
                  }),
                ],
              });
              const headerTable = new Table({
                width: { size: 100, type: WidthType.PERCENTAGE },
                borders: {
                  top: { style: BorderStyle.NONE, size: 0, color: "FFFFFF" },
                  bottom: { style: BorderStyle.SINGLE, size: 6, color: "000000" },
                  left: { style: BorderStyle.NONE, size: 0, color: "FFFFFF" },
                  right: { style: BorderStyle.NONE, size: 0, color: "FFFFFF" },
                },
                rows: [
                  new TableRow({
                    children: [
                      new TableCell({
                        children: [leftInner],
                        width: { size: 50, type: WidthType.PERCENTAGE },
                        margins: { top: 100, bottom: 100, left: 100, right: 100 },
                        borders: { top: { style: BorderStyle.NONE }, bottom: { style: BorderStyle.NONE }, left: { style: BorderStyle.NONE }, right: { style: BorderStyle.NONE } },
                      }),
                      new TableCell({
                        children: [rightInner],
                        width: { size: 50, type: WidthType.PERCENTAGE },
                        margins: { top: 100, bottom: 100, left: 100, right: 100 },
                        borders: { top: { style: BorderStyle.NONE }, bottom: { style: BorderStyle.NONE }, left: { style: BorderStyle.NONE }, right: { style: BorderStyle.NONE } },
                      }),
                    ],
                  }),
                ],
              });
              return { headerTitle, headerTable };
            };
  
            const { headerTitle, headerTable } = makeHeader("KUNCI JAWABAN");
  
            const spacer = new Paragraph({ spacing: { after: 400 } });
            const content = [];
            const sections = [
              { type: 'pg', title: 'PILIHAN GANDA' },
              { type: 'benar_salah', title: 'BENAR / SALAH' },
              { type: 'pg_kompleks', title: 'PILIHAN GANDA KOMPLEKS' },
              { type: 'menjodohkan', title: 'MENJODOHKAN' },
              { type: 'isian', title: 'ISIAN SINGKAT' },
              { type: 'uraian', title: 'URAIAN' },
            ];
            let sectionIndex = 0;
            for (const sec of sections) {
                const items = state.questions.filter(q => q.type === sec.type);
                if (items.length === 0) continue;
                const letter = String.fromCharCode(65 + sectionIndex);
                sectionIndex++;
                content.push(new Paragraph({ children: [new TextRun({ text: `${letter}. ${sec.title}`, bold: true })], spacing: { after: 200 } }));
                if (sec.type === 'pg' || sec.type === 'benar_salah') {
                    const cols = 5;
                    const pgRows = [];
                    for(let i=0; i<items.length; i+=cols) {
                        const rowCells = [];
                        for(let j=0; j<cols; j++) {
                            if (i+j < items.length) {
                                const q = items[i+j];
                                let ansChar = "-";
                                if (sec.type === 'benar_salah') {
                                  const idx = Number(q.answer);
                                  ansChar = idx === 1 ? 'Salah' : 'Benar';
                                } else if (typeof q.answer === 'number') {
                                  ansChar = String.fromCharCode(65 + q.answer);
                                }
                                else if (typeof q.answer === 'string') ansChar = q.answer;
                                rowCells.push(new TableCell({
                                    children: [new Paragraph({ text: `${i+j+1}. ${ansChar}` })],
                                    borders: { top: { style: BorderStyle.NONE }, bottom: { style: BorderStyle.NONE }, left: { style: BorderStyle.NONE }, right: { style: BorderStyle.NONE } },
                                }));
                            } else {
                                rowCells.push(new TableCell({ children: [], borders: { top: { style: BorderStyle.NONE }, bottom: { style: BorderStyle.NONE }, left: { style: BorderStyle.NONE }, right: { style: BorderStyle.NONE } } }));
                            }
                        }
                        pgRows.push(new TableRow({ children: rowCells }));
                    }
                    content.push(new Table({
                        width: { size: 100, type: WidthType.PERCENTAGE },
                        rows: pgRows,
                        borders: { top: { style: BorderStyle.NONE }, bottom: { style: BorderStyle.NONE }, left: { style: BorderStyle.NONE }, right: { style: BorderStyle.NONE } },
                    }));
                } else {
                    items.forEach((q, i) => {
                        let ansText = "";
                        if (sec.type === 'pg_kompleks') {
                            if (Array.isArray(q.answer)) {
                                ansText = q.answer.map(idx => String.fromCharCode(65 + idx)).join(", ");
                            } else {
                                ansText = String(q.answer);
                            }
                        } else if (sec.type === 'menjodohkan') {
                            if (Array.isArray(q.matchKey)) {
                                ansText = q.matchKey.map((pos, idx) => `${idx + 1}–${String.fromCharCode(65 + Number(pos || 0))}`).join(", ");
                            } else {
                                ansText = '';
                            }
                        } else {
                            ansText = q.answer || "(Belum ada kunci)";
                        }
                        content.push(new Paragraph({ 
                            children: [
                                new TextRun({ text: `${i+1}. `, bold: true }),
                                new TextRun({ text: ansText })
                            ],
                            spacing: { after: 100 }
                        }));
                    });
                }
                content.push(new Paragraph({ text: "", spacing: { after: 300 } }));
            }
  
            const doc = new Document({
                sections: [{
                    properties: { page: { margin: { top: 720, bottom: 720, left: 720, right: 720 } } },
                    children: [headerTitle, headerTable, spacer, ...content],
                }],
            });
  
            const blob = await Packer.toBlob(doc);
            const link = document.createElement("a");
            link.href = URL.createObjectURL(blob);
            const safeMapel = (state.identity.mataPelajaran || "Soal").replace(/[^a-z0-9]/gi, '_');
            link.download = `Kunci_${safeMapel}.docx`;
            link.click();
  
        } catch (e) {
            console.error(e);
            alert("Gagal export kunci: " + e.message);
        } finally {
            if (btn) {
              btn.innerHTML = originalText;
              btn.disabled = originalDisabled;
            }
        }
      };

      const exportKisiDocx = async () => {
        if (state.questions.length === 0) return alert("Belum ada soal!");
        
        const btn = el("btnExportKisi");
        const originalText = btn ? btn.innerHTML : "";
        const originalDisabled = btn ? btn.disabled : false;
  
        try {
            if (btn) {
              btn.innerHTML = `<span class="animate-spin material-symbols-outlined text-[18px]">progress_activity</span> Proses...`;
              btn.disabled = true;
            }
  
            const { Document, Packer, Paragraph, TextRun, AlignmentType, Table, TableRow, TableCell, WidthType, BorderStyle } = docx;
  
            const makeHeader = (title) => {
              const headerTitle = new Paragraph({
                children: [
                  new TextRun({ text: (state.identity.namaSekolah || "NAMA SEKOLAH").toUpperCase(), bold: true, size: 28 }),
                  new TextRun({ text: "\n", break: 1 }),
                  new TextRun({ text: title, bold: true, size: 24 }),
                  new TextRun({ text: "\n", break: 1 }),
                  new TextRun({ text: `Tahun Pelajaran ${state.paket.tahunAjaran}`, size: 20 }),
                ],
                alignment: AlignmentType.CENTER,
                spacing: { after: 300 },
              });
              const leftInner = new Table({
                width: { size: 100, type: WidthType.PERCENTAGE },
                borders: { top: { style: BorderStyle.NONE }, bottom: { style: BorderStyle.NONE }, left: { style: BorderStyle.NONE }, right: { style: BorderStyle.NONE }, insideHorizontal: { style: BorderStyle.NONE }, insideVertical: { style: BorderStyle.NONE } },
                rows: [
                  new TableRow({
                    children: [
                      new TableCell({ children: [new Paragraph({ children: [new TextRun({ text: "Mata Pelajaran", bold: true })] })], width: { size: 40, type: WidthType.PERCENTAGE } }),
                      new TableCell({ children: [new Paragraph({ text: ":" })], width: { size: 5, type: WidthType.PERCENTAGE } }),
                      new TableCell({ children: [new Paragraph({ text: String(state.identity.mataPelajaran || "-") })] }),
                    ],
                  }),
                  new TableRow({
                    children: [
                      new TableCell({ children: [new Paragraph({ children: [new TextRun({ text: "Kelas / Fase", bold: true })] })], width: { size: 40, type: WidthType.PERCENTAGE } }),
                      new TableCell({ children: [new Paragraph({ text: ":" })], width: { size: 5, type: WidthType.PERCENTAGE } }),
                      new TableCell({ children: [new Paragraph({ text: `${String(state.identity.kelas || "-")} / ${String(state.identity.fase || "-")}` })] }),
                    ],
                  }),
                  ...(identityTopikDisplay(state.identity)
                    ? [
                        new TableRow({
                          children: [
                            new TableCell({ children: [new Paragraph({ children: [new TextRun({ text: "Topik / Lingkup Materi", bold: true })] })], width: { size: 40, type: WidthType.PERCENTAGE } }),
                            new TableCell({ children: [new Paragraph({ text: ":" })], width: { size: 5, type: WidthType.PERCENTAGE } }),
                            new TableCell({ children: [new Paragraph({ text: String(identityTopikDisplay(state.identity) || "-") })] }),
                          ],
                        }),
                      ]
                    : []),
                ],
              });
              const rightInner = new Table({
                width: { size: 100, type: WidthType.PERCENTAGE },
                borders: { top: { style: BorderStyle.NONE }, bottom: { style: BorderStyle.NONE }, left: { style: BorderStyle.NONE }, right: { style: BorderStyle.NONE }, insideHorizontal: { style: BorderStyle.NONE }, insideVertical: { style: BorderStyle.NONE } },
                rows: [
                  new TableRow({
                    children: [
                      new TableCell({ children: [new Paragraph({ children: [new TextRun({ text: "Kurikulum", bold: true })] })], width: { size: 40, type: WidthType.PERCENTAGE } }),
                      new TableCell({ children: [new Paragraph({ text: ":" })], width: { size: 5, type: WidthType.PERCENTAGE } }),
                      new TableCell({ children: [new Paragraph({ text: "Merdeka" })] }),
                    ],
                  }),
                  new TableRow({
                    children: [
                      new TableCell({ children: [new Paragraph({ children: [new TextRun({ text: "Jumlah Soal", bold: true })] })], width: { size: 40, type: WidthType.PERCENTAGE } }),
                      new TableCell({ children: [new Paragraph({ text: ":" })], width: { size: 5, type: WidthType.PERCENTAGE } }),
                      new TableCell({ children: [new Paragraph({ text: String(state.questions.length) })] }),
                    ],
                  }),
                ],
              });
              const headerTable = new Table({
                width: { size: 100, type: WidthType.PERCENTAGE },
                borders: {
                  top: { style: BorderStyle.NONE, size: 0, color: "FFFFFF" },
                  bottom: { style: BorderStyle.SINGLE, size: 6, color: "000000" },
                  left: { style: BorderStyle.NONE, size: 0, color: "FFFFFF" },
                  right: { style: BorderStyle.NONE, size: 0, color: "FFFFFF" },
                },
                rows: [
                  new TableRow({
                    children: [
                      new TableCell({
                        children: [leftInner],
                        width: { size: 50, type: WidthType.PERCENTAGE },
                        margins: { top: 100, bottom: 100, left: 100, right: 100 },
                        borders: { top: { style: BorderStyle.NONE }, bottom: { style: BorderStyle.NONE }, left: { style: BorderStyle.NONE }, right: { style: BorderStyle.NONE } },
                      }),
                      new TableCell({
                        children: [rightInner],
                        width: { size: 50, type: WidthType.PERCENTAGE },
                        margins: { top: 100, bottom: 100, left: 100, right: 100 },
                        borders: { top: { style: BorderStyle.NONE }, bottom: { style: BorderStyle.NONE }, left: { style: BorderStyle.NONE }, right: { style: BorderStyle.NONE } },
                      }),
                    ],
                  }),
                ],
              });
              return { headerTitle, headerTable };
            };
  
            const { headerTitle, headerTable } = makeHeader("KISI-KISI SOAL");
  
            const spacer = new Paragraph({ spacing: { after: 400 } });
            const content = [];
            const sections = [
              { type: 'pg', title: 'PILIHAN GANDA', label: 'PG' },
              { type: 'benar_salah', title: 'BENAR / SALAH', label: 'B/S' },
              { type: 'pg_kompleks', title: 'PILIHAN GANDA KOMPLEKS', label: 'PG Komp' },
              { type: 'menjodohkan', title: 'MENJODOHKAN', label: 'Jodoh' },
              { type: 'isian', title: 'ISIAN SINGKAT', label: 'Isian' },
              { type: 'uraian', title: 'URAIAN', label: 'Uraian' },
            ];
            let sectionIndex = 0;
            for (const sec of sections) {
              const items = state.questions.filter(q => q && q.type === sec.type);
              if (!items.length) continue;
              const letter = String.fromCharCode(65 + sectionIndex);
              sectionIndex++;
              content.push(new Paragraph({ children: [new TextRun({ text: `${letter}. ${sec.title}`, bold: true })], spacing: { before: 200, after: 120 } }));
              const tableHeader = new TableRow({
                tableHeader: true,
                children: ["No", "Materi", "Indikator Soal", "Level", "Bentuk", "No. Soal"].map(text =>
                  new TableCell({
                    children: [new Paragraph({ children: [new TextRun({ text, bold: true })], alignment: AlignmentType.CENTER })],
                    verticalAlign: "center",
                    shading: { fill: "E0E0E0" },
                  })
                ),
              });
              const rows = items.map((q, i) => new TableRow({
                children: [
                  new TableCell({ children: [new Paragraph({ text: String(i + 1), alignment: AlignmentType.CENTER })] }),
                  new TableCell({ children: [new Paragraph({ text: q.materi || "-" })] }),
                  new TableCell({ children: [new Paragraph({ text: q.indikator || "-" })] }),
                  new TableCell({ children: [new Paragraph({ text: q.bloom || "-", alignment: AlignmentType.CENTER })] }),
                  new TableCell({ children: [new Paragraph({ text: sec.label, alignment: AlignmentType.CENTER })] }),
                  new TableCell({ children: [new Paragraph({ text: String(i + 1), alignment: AlignmentType.CENTER })] }),
                ],
              }));
              content.push(new Table({ width: { size: 100, type: WidthType.PERCENTAGE }, rows: [tableHeader, ...rows] }));
              content.push(new Paragraph({ children: [], spacing: { after: 240 } }));
            }

            const cp046PagesForKisi = (() => {
              const pages = state?.cp046?.soal?.pages;
              const arr = Array.isArray(pages) ? pages.map(x => Number(x)).filter(x => Number.isFinite(x) && x > 0) : [];
              if (!arr.length) return '';
              arr.sort((a,b)=>a-b);
              return arr.join(', ');
            })();
            if (cp046PagesForKisi) {
              content.push(new Paragraph({
                children: [new TextRun({ text: `Catatan: sesuai dengan CP046 hal. ${cp046PagesForKisi}`, italics: true, size: 18 })],
                spacing: { before: 100, after: 0 }
              }));
            }
  
            const doc = new Document({
                sections: [{
                    properties: { page: { margin: { top: 720, bottom: 720, left: 720, right: 720 } } },
                    children: [headerTitle, headerTable, spacer, ...content],
                }],
            });
  
            const blob = await Packer.toBlob(doc);
            const link = document.createElement("a");
            link.href = URL.createObjectURL(blob);
            const safeMapel = (state.identity.mataPelajaran || "Soal").replace(/[^a-z0-9]/gi, '_');
            link.download = `KisiKisi_${safeMapel}.docx`;
            link.click();
  
        } catch (e) {
            console.error(e);
            alert("Gagal export kisi-kisi: " + e.message);
        } finally {
            if (btn) {
              btn.innerHTML = originalText;
              btn.disabled = originalDisabled;
            }
        }
      };

      const exportAllDocx = async () => {
        const btn = el("btnPrint");
        const originalText = btn ? btn.innerHTML : "";
        const originalDisabled = btn ? btn.disabled : false;
        try {
          if (btn) {
            btn.innerHTML = `<span class="animate-spin material-symbols-outlined text-[18px]">progress_activity</span> Proses...`;
            btn.disabled = true;
          }
          const { Document, Packer, Paragraph, TextRun, AlignmentType, Table, TableRow, TableCell, WidthType, BorderStyle, ImageRun } = docx;
          const makeHeader = (title, kind) => {
            const headerTitle = new Paragraph({
              children: [
                new TextRun({ text: (state.identity.namaSekolah || "NAMA SEKOLAH").toUpperCase(), bold: true, size: 28 }),
                new TextRun({ text: "\n", break: 1 }),
                new TextRun({ text: title, bold: true, size: 24 }),
                new TextRun({ text: "\n", break: 1 }),
                new TextRun({ text: `Tahun Pelajaran ${state.paket.tahunAjaran}`, size: 20 }),
              ],
              alignment: AlignmentType.CENTER,
              spacing: { after: 300 },
            });
            const leftInner = new Table({
              width: { size: 100, type: WidthType.PERCENTAGE },
              borders: { top: { style: BorderStyle.NONE }, bottom: { style: BorderStyle.NONE }, left: { style: BorderStyle.NONE }, right: { style: BorderStyle.NONE }, insideHorizontal: { style: BorderStyle.NONE }, insideVertical: { style: BorderStyle.NONE } },
              rows: [
                new TableRow({
                  children: [
                    new TableCell({ children: [new Paragraph({ children: [new TextRun({ text: "Mata Pelajaran", bold: true })] })], width: { size: 40, type: WidthType.PERCENTAGE } }),
                    new TableCell({ children: [new Paragraph({ text: ":" })], width: { size: 5, type: WidthType.PERCENTAGE } }),
                    new TableCell({ children: [new Paragraph({ text: String(state.identity.mataPelajaran || "-") })] },),
                  ],
                }),
                new TableRow({
                  children: [
                    new TableCell({ children: [new Paragraph({ children: [new TextRun({ text: "Kelas / Fase", bold: true })] })], width: { size: 40, type: WidthType.PERCENTAGE } }),
                    new TableCell({ children: [new Paragraph({ text: ":" })], width: { size: 5, type: WidthType.PERCENTAGE } }),
                    new TableCell({ children: [new Paragraph({ text: `${String(state.identity.kelas || "-")} / ${String(state.identity.fase || "-")}` })] }),
                  ],
                }),
                ...(kind === "naskah"
                  ? [
                      new TableRow({
                        children: [
                          new TableCell({ children: [new Paragraph({ children: [new TextRun({ text: "Hari / Tanggal", bold: true })] })], width: { size: 40, type: WidthType.PERCENTAGE } }),
                          new TableCell({ children: [new Paragraph({ text: ":" })], width: { size: 5, type: WidthType.PERCENTAGE } }),
                          new TableCell({ children: [new Paragraph({ text: "______________________________" })] }),
                        ],
                      }),
                    ]
                  : identityTopikDisplay(state.identity)
                  ? [
                      new TableRow({
                        children: [
                          new TableCell({ children: [new Paragraph({ children: [new TextRun({ text: "Topik / Lingkup Materi", bold: true })] })], width: { size: 40, type: WidthType.PERCENTAGE } }),
                          new TableCell({ children: [new Paragraph({ text: ":" })], width: { size: 5, type: WidthType.PERCENTAGE } }),
                          new TableCell({ children: [new Paragraph({ text: String(identityTopikDisplay(state.identity) || "-") })] }),
                        ],
                      }),
                    ]
                  : []),
              ],
            });
            const rightInner = new Table({
              width: { size: 100, type: WidthType.PERCENTAGE },
              borders: { top: { style: BorderStyle.NONE }, bottom: { style: BorderStyle.NONE }, left: { style: BorderStyle.NONE }, right: { style: BorderStyle.NONE }, insideHorizontal: { style: BorderStyle.NONE }, insideVertical: { style: BorderStyle.NONE } },
              rows: [
                ...(kind === "naskah"
                  ? [
                      new TableRow({
                        children: [
                          new TableCell({ children: [new Paragraph({ children: [new TextRun({ text: "Waktu", bold: true })] })], width: { size: 40, type: WidthType.PERCENTAGE } }),
                          new TableCell({ children: [new Paragraph({ text: ":" })], width: { size: 5, type: WidthType.PERCENTAGE } }),
                          new TableCell({ children: [new Paragraph({ text: "______________________________" })] }),
                        ],
                      }),
                      new TableRow({
                        children: [
                          new TableCell({ children: [new Paragraph({ children: [new TextRun({ text: "Nama Siswa", bold: true })] })], width: { size: 40, type: WidthType.PERCENTAGE } }),
                          new TableCell({ children: [new Paragraph({ text: ":" })], width: { size: 5, type: WidthType.PERCENTAGE } }),
                          new TableCell({ children: [new Paragraph({ text: "______________________________" })] }),
                        ],
                      }),
                      new TableRow({
                        children: [
                          new TableCell({ children: [new Paragraph({ children: [new TextRun({ text: "No. Absen / Ruang", bold: true })] })], width: { size: 40, type: WidthType.PERCENTAGE } }),
                          new TableCell({ children: [new Paragraph({ text: ":" })], width: { size: 5, type: WidthType.PERCENTAGE } }),
                          new TableCell({ children: [new Paragraph({ text: "______________________________" })] }),
                        ],
                      }),
                    ]
                  : [
                      new TableRow({
                        children: [
                          new TableCell({ children: [new Paragraph({ children: [new TextRun({ text: "Kurikulum", bold: true })] })], width: { size: 40, type: WidthType.PERCENTAGE } }),
                          new TableCell({ children: [new Paragraph({ text: ":" })], width: { size: 5, type: WidthType.PERCENTAGE } }),
                          new TableCell({ children: [new Paragraph({ text: "Merdeka" })] }),
                        ],
                      }),
                      new TableRow({
                        children: [
                          new TableCell({ children: [new Paragraph({ children: [new TextRun({ text: "Jumlah Soal", bold: true })] })], width: { size: 40, type: WidthType.PERCENTAGE } }),
                          new TableCell({ children: [new Paragraph({ text: ":" })], width: { size: 5, type: WidthType.PERCENTAGE } }),
                          new TableCell({ children: [new Paragraph({ text: String(state.questions.length) })] }),
                        ],
                      }),
                    ]),
              ],
            });
            const headerTable = new Table({
              width: { size: 100, type: WidthType.PERCENTAGE },
              borders: {
                top: { style: BorderStyle.NONE, size: 0, color: "FFFFFF" },
                bottom: { style: BorderStyle.SINGLE, size: 6, color: "000000" },
                left: { style: BorderStyle.NONE, size: 0, color: "FFFFFF" },
                right: { style: BorderStyle.NONE, size: 0, color: "FFFFFF" },
              },
              rows: [
                new TableRow({
                  children: [
                    new TableCell({
                      children: [leftInner],
                      width: { size: 50, type: WidthType.PERCENTAGE },
                      margins: { top: 100, bottom: 100, left: 100, right: 100 },
                      borders: { top: { style: BorderStyle.NONE }, bottom: { style: BorderStyle.NONE }, left: { style: BorderStyle.NONE }, right: { style: BorderStyle.NONE } },
                    }),
                    new TableCell({
                      children: [rightInner],
                      width: { size: 50, type: WidthType.PERCENTAGE },
                      margins: { top: 100, bottom: 100, left: 100, right: 100 },
                      borders: { top: { style: BorderStyle.NONE }, bottom: { style: BorderStyle.NONE }, left: { style: BorderStyle.NONE }, right: { style: BorderStyle.NONE } },
                    }),
                  ],
                }),
              ],
            });
            return { headerTitle, headerTable };
          };
          const spacer = new Paragraph({ spacing: { after: 400 } });
          const processedQuestions = await Promise.all(state.questions.map(async (q) => {
            let imgData = null;
            if (q.image) {
              try {
                const resp = await fetch(q.image);
                const blob = await resp.blob();
                const buffer = new Uint8Array(await blob.arrayBuffer());
                const dimensions = await new Promise((resolve) => {
                  const img = new Image();
                  const u = URL.createObjectURL(blob);
                  img.onload = () => { URL.revokeObjectURL(u); resolve({ width: img.naturalWidth, height: img.naturalHeight }); };
                  img.onerror = () => { URL.revokeObjectURL(u); resolve({ width: 300, height: 300 }); };
                  img.src = u;
                });
                const maxWidth = 100;
                const dw = Math.max(1, Number(dimensions.width || 300));
                const dh = Math.max(1, Number(dimensions.height || 300));
                const ratio = dw / dh;
                const width = Math.max(1, Math.round(Math.min(maxWidth, dw)));
                const height = Math.max(1, Math.round(width / ratio));
                imgData = { buffer, width, height };
              } catch {}
            }
            return { ...q, _img: imgData };
          }));
          const buildNaskahSection = () => {
            const { headerTitle, headerTable } = makeHeader(String(state.paket.judul || "NASKAH SOAL").toUpperCase(), "naskah");
            const questionParagraphs = [];
            const sections = [
              { type: 'pg', title: 'PILIHAN GANDA', subtitle: 'Pilihlah salah satu jawaban yang paling tepat!' },
              { type: 'benar_salah', title: 'BENAR / SALAH', subtitle: 'Pilihlah jawaban Benar atau Salah!' },
              { type: 'pg_kompleks', title: 'PILIHAN GANDA KOMPLEKS', subtitle: 'Pilihlah jawaban yang benar (bisa lebih dari satu)!' },
              { type: 'menjodohkan', title: 'MENJODOHKAN', subtitle: 'Jodohkanlah pernyataan pada lajur kiri dengan jawaban pada lajur kanan!' },
              { type: 'isian', title: 'ISIAN SINGKAT', subtitle: 'Jawablah pertanyaan berikut dengan singkat dan tepat!' },
              { type: 'uraian', title: 'URAIAN', subtitle: 'Jawablah pertanyaan-pertanyaan berikut dengan jelas dan benar!' },
            ];
            let sectionIndex = 0;
            for (const sec of sections) {
              const items = processedQuestions.filter(q => q.type === sec.type);
              if (items.length === 0) continue;
              const letter = String.fromCharCode(65 + sectionIndex);
              sectionIndex++;
              questionParagraphs.push(
                new Paragraph({ children: [new TextRun({ text: `${letter}. ${sec.title}`, bold: true })], spacing: { before: 200, after: 100 } }),
                new Paragraph({ children: [new TextRun({ text: sec.subtitle, italics: true })], spacing: { after: 300 } })
              );
              const normKey = (t) => String(t || '').replace(/\r\n/g, '\n').replace(/\r/g, '\n').replace(/\s+/g, ' ').trim();
              const pushOne = (q, num) => {
                questionParagraphs.push(
                  new Paragraph({
                    children: [new TextRun({ text: `${num}.\t${q.question}`, bold: false })],
                    tabStops: [{ type: "left", position: 400 }],
                    indent: { left: 0, hanging: 0 },
                    spacing: { before: 200, after: 100 },
                    keepLines: true,
                  })
                );
                if (q._img) {
                  questionParagraphs.push(
                    new Paragraph({
                      children: [
                        new ImageRun({
                          data: q._img.buffer,
                          transformation: { width: q._img.width, height: q._img.height },
                        }),
                      ],
                      alignment: AlignmentType.LEFT,
                      indent: { left: 400 },
                      spacing: { after: 200 },
                    })
                  );
                }
                if (sec.type === 'pg' || sec.type === 'benar_salah' || sec.type === 'pg_kompleks') {
                  q.options.forEach((opt, idx) => {
                    questionParagraphs.push(
                      new Paragraph({
                        children: [new TextRun({ text: `${String.fromCharCode(65 + idx)}.\t${opt}` })],
                        tabStops: [{ type: "left", position: 300 }],
                        indent: { left: 400, hanging: 0 },
                        spacing: { after: 50 },
                      })
                    );
                  });
                } else if (sec.type === 'menjodohkan') {
                  const rows = [];
                  rows.push(new TableRow({
                    children: [
                      new TableCell({ children: [new Paragraph({text: "No", bold: true, alignment: AlignmentType.CENTER})], width: { size: 10, type: WidthType.PERCENTAGE } }),
                      new TableCell({ children: [new Paragraph({text: "Pernyataan", bold: true})], width: { size: 45, type: WidthType.PERCENTAGE } }),
                      new TableCell({ children: [new Paragraph({text: "Jawaban", bold: true})], width: { size: 45, type: WidthType.PERCENTAGE } }),
                    ]
                  }));
                  const rightList = Array.isArray(q.answer) ? [...q.answer].sort() : [];
                  q.options.forEach((opt, idx) => {
                    const left = opt;
                    const rightText = rightList[idx] || "";
                    const rightLabel = rightText ? `${String.fromCharCode(65 + idx)}. ${rightText}` : "";
                    rows.push(new TableRow({
                      children: [
                        new TableCell({ children: [new Paragraph({text: String(idx + 1), alignment: AlignmentType.CENTER})] }),
                        new TableCell({ children: [new Paragraph({text: left})] }),
                        new TableCell({ children: [new Paragraph({text: rightLabel})] }),
                      ]
                    }));
                  });
                  questionParagraphs.push(new Table({
                    rows: rows,
                    width: { size: 100, type: WidthType.PERCENTAGE },
                    indent: { left: 400 },
                  }));
                  questionParagraphs.push(new Paragraph({ spacing: { after: 200 } }));
                } else if (sec.type === 'isian' || sec.type === 'uraian') {
                  if (sec.type === 'uraian') {
                    questionParagraphs.push(new Paragraph({ children: [new TextRun({ text: "\n" })] }));
                    questionParagraphs.push(
                      new Paragraph({
                        children: [new TextRun({ text: "__________________________________________________________________________" })],
                        spacing: { before: 0, after: 100 },
                        indent: { left: 400 },
                      })
                    );
                  } else {
                    questionParagraphs.push(
                      new Paragraph({
                        children: [new TextRun({ text: "Jawaban: ___________________________________" })],
                        spacing: { before: 0, after: 100 },
                        indent: { left: 400 },
                      })
                    );
                  }
                }
                questionParagraphs.push(new Paragraph({ spacing: { after: 200 } }));
              };
              for (let i = 0; i < items.length; i++) {
                const q = items[i];
                const ctxText = String(q.context || '').replace(/\r\n/g, '\n').replace(/\r/g, '\n').trim();
                if (ctxText) {
                  const key = normKey(ctxText);
                  let j = i;
                  while (j + 1 < items.length) {
                    const nxt = items[j + 1];
                    const nxtCtx = String(nxt?.context || '').replace(/\r\n/g, '\n').replace(/\r/g, '\n').trim();
                    if (!nxtCtx) break;
                    if (normKey(nxtCtx) !== key) break;
                    j++;
                  }
                  const a = i + 1;
                  const b = j + 1;
                  const rangeText = a === b ? `nomor ${a}` : `nomor ${a} s.d. ${b}`;
                  questionParagraphs.push(new Paragraph({ children: [new TextRun({ text: `Untuk menjawab soal ${rangeText}, pahami bacaan berikut.`, italics: true })], spacing: { before: 100, after: 80 } }));
                  const paras = ctxText.split(/\n\s*\n/).map(s => String(s || '').trim()).filter(Boolean);
                  paras.forEach((p) => questionParagraphs.push(new Paragraph({ children: [new TextRun({ text: p, bold: false })], spacing: { after: 80 }, indent: { left: 400 } })));
                  for (let k = i; k <= j; k++) pushOne(items[k], k + 1);
                  i = j;
                  continue;
                }
                pushOne(q, i + 1);
              }
            }
            return { headerTitle, headerTable, spacer, questionParagraphs };
          };
          const buildKunciSection = () => {
            const headerTitle = new Paragraph({
              children: [
                new TextRun({ text: (state.identity.namaSekolah || "NAMA SEKOLAH").toUpperCase(), bold: true, size: 28 }),
                new TextRun({ text: "\n", break: 1 }),
                new TextRun({ text: "KUNCI JAWABAN", bold: true, size: 24 }),
                new TextRun({ text: "\n", break: 1 }),
                new TextRun({ text: `Tahun Pelajaran ${state.paket.tahunAjaran}`, size: 20 }),
              ],
              alignment: AlignmentType.CENTER,
              spacing: { after: 300 },
            });
            const leftInner = new Table({
              width: { size: 100, type: WidthType.PERCENTAGE },
              borders: { top: { style: BorderStyle.NONE }, bottom: { style: BorderStyle.NONE }, left: { style: BorderStyle.NONE }, right: { style: BorderStyle.NONE }, insideHorizontal: { style: BorderStyle.NONE }, insideVertical: { style: BorderStyle.NONE } },
              rows: [
                new TableRow({
                  children: [
                    new TableCell({ children: [new Paragraph({ children: [new TextRun({ text: "Mata Pelajaran", bold: true })] })], width: { size: 40, type: WidthType.PERCENTAGE } }),
                    new TableCell({ children: [new Paragraph({ text: ":" })], width: { size: 5, type: WidthType.PERCENTAGE } }),
                    new TableCell({ children: [new Paragraph({ text: String(state.identity.mataPelajaran || "-") })] },),
                  ],
                }),
                new TableRow({
                  children: [
                    new TableCell({ children: [new Paragraph({ children: [new TextRun({ text: "Kelas / Fase", bold: true })] })], width: { size: 40, type: WidthType.PERCENTAGE } }),
                    new TableCell({ children: [new Paragraph({ text: ":" })], width: { size: 5, type: WidthType.PERCENTAGE } }),
                    new TableCell({ children: [new Paragraph({ text: `${String(state.identity.kelas || "-")} / ${String(state.identity.fase || "-")}` })] }),
                  ],
                }),
              ],
            });
            const rightInner = new Table({
              width: { size: 100, type: WidthType.PERCENTAGE },
              borders: { top: { style: BorderStyle.NONE }, bottom: { style: BorderStyle.NONE }, left: { style: BorderStyle.NONE }, right: { style: BorderStyle.NONE }, insideHorizontal: { style: BorderStyle.NONE }, insideVertical: { style: BorderStyle.NONE } },
              rows: [
                new TableRow({
                  children: [
                    new TableCell({ children: [new Paragraph({ children: [new TextRun({ text: "Kurikulum", bold: true })] })], width: { size: 40, type: WidthType.PERCENTAGE } }),
                    new TableCell({ children: [new Paragraph({ text: ":" })], width: { size: 5, type: WidthType.PERCENTAGE } }),
                    new TableCell({ children: [new Paragraph({ text: "Merdeka" })] }),
                  ],
                }),
              ],
            });
            const headerTable = new Table({
              width: { size: 100, type: WidthType.PERCENTAGE },
              borders: {
                top: { style: BorderStyle.NONE, size: 0, color: "FFFFFF" },
                bottom: { style: BorderStyle.SINGLE, size: 6, color: "000000" },
                left: { style: BorderStyle.NONE, size: 0, color: "FFFFFF" },
                right: { style: BorderStyle.NONE, size: 0, color: "FFFFFF" },
              },
              rows: [
                new TableRow({
                  children: [
                    new TableCell({
                      children: [leftInner],
                      width: { size: 50, type: WidthType.PERCENTAGE },
                      margins: { top: 100, bottom: 100, left: 100, right: 100 },
                      borders: { top: { style: BorderStyle.NONE }, bottom: { style: BorderStyle.NONE }, left: { style: BorderStyle.NONE }, right: { style: BorderStyle.NONE } },
                    }),
                    new TableCell({
                      children: [rightInner],
                      width: { size: 50, type: WidthType.PERCENTAGE },
                      margins: { top: 100, bottom: 100, left: 100, right: 100 },
                      borders: { top: { style: BorderStyle.NONE }, bottom: { style: BorderStyle.NONE }, left: { style: BorderStyle.NONE }, right: { style: BorderStyle.NONE } },
                    }),
                  ],
                }),
              ],
            });
            const spacer = new Paragraph({ spacing: { after: 400 } });
            const content = [];
            const sections = [
              { type: 'pg', title: 'PILIHAN GANDA' },
              { type: 'benar_salah', title: 'BENAR / SALAH' },
              { type: 'pg_kompleks', title: 'PILIHAN GANDA KOMPLEKS' },
              { type: 'menjodohkan', title: 'MENJODOHKAN' },
              { type: 'isian', title: 'ISIAN SINGKAT' },
              { type: 'uraian', title: 'URAIAN' },
            ];
            let sectionIndex = 0;
            for (const sec of sections) {
              const items = state.questions.filter(q => q.type === sec.type);
              if (items.length === 0) continue;
              const letter = String.fromCharCode(65 + sectionIndex);
              sectionIndex++;
              content.push(new Paragraph({ children: [new TextRun({ text: `${letter}. ${sec.title}`, bold: true })], spacing: { after: 200 } }));
              if (sec.type === 'pg' || sec.type === 'benar_salah') {
                const cols = 5;
                const pgRows = [];
                for(let i=0; i<items.length; i+=cols) {
                  const rowCells = [];
                  for(let j=0; j<cols; j++) {
                    if (i+j < items.length) {
                      const q = items[i+j];
                      let ansChar = "-";
                      if (sec.type === 'benar_salah') {
                        const idx = Number(q.answer);
                        ansChar = idx === 1 ? 'Salah' : 'Benar';
                      } else if (typeof q.answer === 'number') {
                        ansChar = String.fromCharCode(65 + q.answer);
                      }
                      else if (typeof q.answer === 'string') ansChar = q.answer;
                      rowCells.push(new TableCell({
                        children: [new Paragraph({ text: `${i+j+1}. ${ansChar}` })],
                        borders: { top: { style: BorderStyle.NONE }, bottom: { style: BorderStyle.NONE }, left: { style: BorderStyle.NONE }, right: { style: BorderStyle.NONE } },
                      }));
                    } else {
                      rowCells.push(new TableCell({ children: [], borders: { top: { style: BorderStyle.NONE }, bottom: { style: BorderStyle.NONE }, left: { style: BorderStyle.NONE }, right: { style: BorderStyle.NONE } } }));
                    }
                  }
                  pgRows.push(new TableRow({ children: rowCells }));
                }
                content.push(new Table({
                  width: { size: 100, type: WidthType.PERCENTAGE },
                  rows: pgRows,
                  borders: { top: { style: BorderStyle.NONE }, bottom: { style: BorderStyle.NONE }, left: { style: BorderStyle.NONE }, right: { style: BorderStyle.NONE } },
                }));
              } else {
                items.forEach((q, i) => {
                  let ansText = "";
                  if (sec.type === 'pg_kompleks') {
                    if (Array.isArray(q.answer)) ansText = q.answer.map(idx => String.fromCharCode(65 + idx)).join(", ");
                    else ansText = String(q.answer);
                  } else if (sec.type === 'menjodohkan') {
                    if (Array.isArray(q.answer)) ansText = q.answer.map((a, ai) => `${ai+1}->${a}`).join("; ");
                    else ansText = String(q.answer);
                  } else {
                    ansText = q.answer || "(Belum ada kunci)";
                  }
                  content.push(new Paragraph({ 
                    children: [
                      new TextRun({ text: `${i+1}. `, bold: true }),
                      new TextRun({ text: ansText })
                    ],
                    spacing: { after: 100 }
                  }));
                });
              }
              content.push(new Paragraph({ text: "", spacing: { after: 300 } }));
            }
            return { headerTitle, headerTable, spacer, content };
          };
          const buildKisiSection = () => {
            const { headerTitle, headerTable } = makeHeader("KISI-KISI SOAL", "kisi");
            const spacer = new Paragraph({ spacing: { after: 400 } });
            const tableHeader = new TableRow({
              tableHeader: true,
              children: ["No", "Materi", "Indikator Soal", "Level", "Bentuk", "No. Soal"].map(text => 
                new TableCell({
                  children: [new Paragraph({ children: [new TextRun({ text, bold: true })], alignment: AlignmentType.CENTER })],
                  verticalAlign: "center",
                  shading: { fill: "E0E0E0" },
                })
              ),
            });
            const counters = { pg: 0, pg_kompleks: 0, menjodohkan: 0, isian: 0, uraian: 0 };
            const typeLabels = { pg: "PG", pg_kompleks: "PG Komp", menjodohkan: "Jodoh", isian: "Isian", uraian: "Uraian" };
            const rows = state.questions.map((q, i) => {
              const t = q.type || 'pg';
              if (counters[t] !== undefined) counters[t]++;
              else counters[t] = 1;
              return new TableRow({
                children: [
                  new TableCell({ children: [new Paragraph({ text: String(i + 1), alignment: AlignmentType.CENTER })] }),
                  new TableCell({ children: [new Paragraph({ text: q.materi || "-" })] }),
                  new TableCell({ children: [new Paragraph({ text: q.indikator || "-" })] }),
                  new TableCell({ children: [new Paragraph({ text: q.bloom || "-", alignment: AlignmentType.CENTER })] }),
                  new TableCell({ children: [new Paragraph({ text: typeLabels[t] || "Lainnya", alignment: AlignmentType.CENTER })] }),
                  new TableCell({ children: [new Paragraph({ text: String(counters[t]), alignment: AlignmentType.CENTER })] }),
                ],
              });
            });
            const kisiTable = new Table({
              width: { size: 100, type: WidthType.PERCENTAGE },
              rows: [tableHeader, ...rows],
            });
            return { headerTitle, headerTable, spacer, kisiTable };
          };
          const naskah = buildNaskahSection();
          const kunci = buildKunciSection();
          const kisi = buildKisiSection();
          const pageBreak = () => new Paragraph({ children: [], pageBreakBefore: true });
          const children = [
            naskah.headerTitle, naskah.headerTable, naskah.spacer, ...naskah.questionParagraphs,
            pageBreak(),
            kunci.headerTitle, kunci.headerTable, kunci.spacer, ...kunci.content,
            pageBreak(),
            kisi.headerTitle, kisi.headerTable, kisi.spacer, kisi.kisiTable,
          ];
          const doc = new Document({
            sections: [
              { properties: { page: { margin: { top: 720, bottom: 720, left: 720, right: 720 } } }, children },
            ],
          });
          const blob = await Packer.toBlob(doc);
          const link = document.createElement("a");
          link.href = URL.createObjectURL(blob);
          const safeMapel = (state.identity.mataPelajaran || "Soal").replace(/[^a-z0-9]/gi, '_');
          link.download = `Cetak_${safeMapel}.docx`;
          link.click();
        } catch (e) {
          alert("Gagal membuat dokumen: " + e.message);
        } finally {
          if (btn) {
            btn.innerHTML = originalText;
            btn.disabled = originalDisabled;
          }
        }
      }

      function toggleSidebar() {
        const sb = document.getElementById("mainSidebar");
        if (window.innerWidth >= 1024) {
           sb.classList.toggle("lg:flex");
        } else {
           sb.classList.toggle("hidden");
           sb.classList.toggle("flex");
        }
      }

      window.regenSingle = regenSingle;
      window.regenImage = regenImage;
      window.deleteImage = deleteImage;

      window.__sp = {
        setView,
        setPreviewTab,
        startBuildSoal,
        openNaskahSoalFromKonfigurasi,
        downloadSoalDocx,
        downloadSoalPDF,
        setModulAjarTab,
        setQuizTab,
        setQuizShareTab,
        setQuizPublish,
        setQuizExpirePart,
        publishQuiz,
        downloadRosterTemplate,
        loadPublications,
        loadResults,
        setQuizResultsQuery,
        exportJSON,
        exportZIP,
        exportRosterLinksCSV,
        exportRosterLinksPDF,
        seedQuizResults,
        exportResultsPDF,
        exportResultsCSV,
        rekapDownloadTemplate,
        openBobotModal,
        closeBobotModal,
        resetBobot,
        saveBobotSettings,
        openPredikatModal,
        closePredikatModal,
        resetPredikat,
        savePredikatSettings,
        openRekapHelp,
        closeRekapHelp,
        openRekapPrint,
        closeRekapPrint,
        generateRekapPDF,
        openIdentitasHelp,
        closeIdentitasHelp,
        openKonteksHelp,
        closeKonteksHelp,
        openBuatSoalHelp,
        closeBuatSoalHelp,
        openBagikanLinkHelp,
        closeBagikanLinkHelp,
        openBagikanLinkFieldHelp,
        closeBagikanLinkFieldHelp,
        openHasilQuizHelp,
        closeHasilQuizHelp,
        moreQuizPreview,
        toggleQuizPreviewPanel,
        openSumberMateriHelp,
        closeSumberMateriHelp,
        openKonfigurasiHelp,
        closeKonfigurasiHelp,
        openPreviewHelp,
        closePreviewHelp,
        openBuatSoalTutorial,
        closeBuatSoalTutorial,
        playBuatSoalTutorial,
        openQuizHelp,
        closeQuizHelp,
        openQuizTutorial,
        closeQuizTutorial,
        openMAHelp1,
        closeMAHelp1,
        openMAHelp2,
        closeMAHelp2,
        openMAHelp3,
        closeMAHelp3,
        openModulAjarTutorial,
        closeModulAjarTutorial,
        openRPPHelp1,
        closeRPPHelp1,
        openRPPHelp2,
        closeRPPHelp2,
        exportModulAjarPDF,
        setRppTab,
        setRPP,
        buildRPP,
        exportRPPDocx,
        exportRPPPdf,
        setLkpdSource,
        buildLKPD,
        pickLkpdImage,
        pickLkpdText,
        pickTopikImage,
        pickTopikText,
        pickTopikPdf,
        buildPackage,
        uploadQuestionImage,
        exportDocx,
        exportKisiDocx,
        exportKunciDocx,
        openQuiz,
        closeQuiz,
        handleQuizAnswer,
        addSection,
        removeSection,
        duplicateSection,
        updateSection,
        pickLogo,
        clearLogo,
        openUsagePolicy,
        closeUsagePolicy,
        // Modul Ajar
        setMA: (key, val, renderNow = false) => {
          if (!state.modulAjar) state.modulAjar = {};
          state.modulAjar[key] = val;
          if (state.modulAjarError) state.modulAjarError = null;
          if (key === 'jenjang') { state.modulAjar.fase=''; state.modulAjar.kelas=''; state.modulAjar.mapel=''; state.modulAjar.mapel_cp046_slug=''; }
          if (key === 'fase' || key === 'mapel') { state.modulAjar.mapel_cp046_slug=''; }
          if (renderNow) {
            saveDebounced(true);
            render();
          } else {
            saveDebounced(false);
          }
        },
        toggleMADimensi: (val, checked) => {
          if (!state.modulAjar) state.modulAjar = {};
          if (!Array.isArray(state.modulAjar.dimensi)) state.modulAjar.dimensi = [];
          if (checked) { if (!state.modulAjar.dimensi.includes(val)) state.modulAjar.dimensi.push(val); }
          else { state.modulAjar.dimensi = state.modulAjar.dimensi.filter(d=>d!==val); }
          if (state.modulAjarError) state.modulAjarError = null;
          saveDebounced(false);
          render();
        },
        openModulAjarFromDetail,
        buildModulAjar,
        refineModulAjarKegiatan,
        exportModulAjarDocx,
        cp046MapelInput,
        cp046MapelBlur,
        cp046MapelPick,
        cp046MapelPickFromEl,
      };

      const btnThemeEl = el("btnTheme");
      if (btnThemeEl) btnThemeEl.addEventListener("click", toggleTheme);

      document.addEventListener("mousedown", (e) => {
        const tgt = e?.target;
        const inDrop = tgt && tgt.closest ? tgt.closest("#cp046MapelDropdown_modulAjar, #cp046MapelDropdown_rpp") : null;
        const inInp = tgt && tgt.closest ? tgt.closest('[data-ma-key="mapel"], [data-rpp-key="mata_pelajaran"]') : null;
        if (!inDrop && !inInp) {
          try { window.__sp.cp046MapelBlur('modulAjar'); } catch {}
          try { window.__sp.cp046MapelBlur('rpp'); } catch {}
        }
      });

      document.addEventListener("click", (e) => {
        const t = e.target && e.target.closest ? e.target.closest("[data-action]") : null;
        if (!t) return;
        const a = t.getAttribute("data-action");
        if (!a) return;
        if (a === "build-rpp") {
          e.preventDefault();
          e.stopImmediatePropagation();
          e.stopPropagation();
          try {
            Promise.resolve(window.__sp?.buildRPP?.()).catch((err) => {
              alert('Gagal menjalankan proses RPP: ' + (err?.message || 'Terjadi kesalahan.'));
            });
          } catch (err) {
            alert('Gagal menjalankan proses RPP: ' + (err?.message || 'Terjadi kesalahan.'));
          }
        } else if (a === "export-rpp-docx") {
          e.preventDefault();
          e.stopImmediatePropagation();
          e.stopPropagation();
          try { window.__sp?.exportRPPDocx?.(); } catch {}
        } else if (a === "export-rpp-pdf") {
          e.preventDefault();
          e.stopImmediatePropagation();
          e.stopPropagation();
          try { window.__sp?.exportRPPPdf?.(); } catch {}
        }
      }, true);
      
      el("btnSave").addEventListener("click", saveProject);
      el("btnLoad").addEventListener("click", () => {
        el("projectPicker").value = "";
        el("projectPicker").click();
      });
      el("projectPicker").addEventListener("change", loadProject);
      el("btnReset").addEventListener("click", resetAll);
      const btnBuild = el("btnBuild");
      if (btnBuild) btnBuild.addEventListener("click", startBuildSoal);
      const logoPicker = el("logoPicker");
      if (logoPicker) {
        logoPicker.addEventListener("change", handleLogoSelected);
      }
      const btnExport = el("btnExport");
      if (btnExport) {
        btnExport.addEventListener("click", () => {
          exportDocx();
        });
      }
      const lkpdImg = el("lkpdImgUpload");
      if (lkpdImg) lkpdImg.addEventListener("change", handleLkpdImageSelected);
      const lkpdTxt = el("lkpdTxtUpload");
      if (lkpdTxt) lkpdTxt.addEventListener("change", handleLkpdTextSelected);
      const topikImg = el("topikImgUpload");
      if (topikImg) topikImg.addEventListener("change", handleTopikImageSelected);
      const topikTxt = el("topikTxtUpload");
      if (topikTxt) topikTxt.addEventListener("change", handleTopikTextSelected);
      const topikPdf = el("topikPdfUpload");
      if (topikPdf) topikPdf.addEventListener("change", handleTopikPdfSelected);
      const rosterPicker = el("rosterPicker");
      if (rosterPicker) rosterPicker.addEventListener("change", handleRosterSelected);
      const rekapPicker = el("rekapExcelPicker");
      if (rekapPicker) rekapPicker.addEventListener("change", rekapHandlePicker);
      const rekapPrintLogoPicker = el("rekapPrintLogoPicker");
      if (rekapPrintLogoPicker) {
        rekapPrintLogoPicker.addEventListener("change", (e) => {
          const f = e.target?.files?.[0];
          const nameEl = el("printLogoName");
          if (nameEl) nameEl.textContent = f ? `Logo: ${f.name}` : '';
        });
      }
      let historyItems = [];
      let historyPage = 1;
      const historyPageSize = 10;
      let creditPage = 1;
      const creditPageSize = 10;
      const renderRiwayat = () => `
        <div class="space-y-4">
          <div class="flex items-center justify-between">
            <div class="text-sm text-text-sub-light dark:text-text-sub-dark">Riwayat paket soal Anda</div>
            <div class="flex items-center gap-2">
              <input id="historySearch" placeholder="Cari judul..." class="rounded-lg border h-9 px-3 w-56 bg-white dark:bg-surface-dark border-border-light dark:border-border-dark" />
              <button id="btnHistoryRefresh" class="rounded-lg h-9 px-3 bg-white dark:bg-surface-dark border border-border-light dark:border-border-dark hover:bg-background-light dark:hover:bg-background-dark text-sm font-bold">Refresh</button>
            </div>
          </div>
          <div class="bg-surface-light dark:bg-surface-dark rounded-xl border border-border-light dark:border-border-dark shadow-sm overflow-hidden">
            <div class="overflow-auto">
              <table class="min-w-full text-sm border whitespace-nowrap">
                <thead class="bg-background-light dark:bg-background-dark">
                  <tr>
                    <th class="border px-3 py-2 text-left">No</th>
                    <th class="border px-3 py-2 text-left">Judul</th>
                    <th class="border px-3 py-2 text-left">Jumlah Soal</th>
                    <th class="border px-3 py-2 text-left">Dibuat</th>
                    <th class="border px-3 py-2">Aksi</th>
                  </tr>
                </thead>
                <tbody id="historyTBody"></tbody>
              </table>
            </div>
            <div class="flex items-center justify-between px-3 py-2 border-t border-border-light dark:border-border-dark">
              <div id="historyInfo" class="text-xs text-text-sub-light dark:text-text-sub-dark"></div>
              <div class="flex items-center gap-2">
                <button id="btnHistoryPrev" class="rounded-lg h-8 px-3 bg-white dark:bg-surface-dark border border-border-light dark:border-border-dark hover:bg-background-light dark:hover:bg-background-dark text-xs font-bold">Sebelumnya</button>
                <div id="historyPageInfo" class="text-xs"></div>
                <button id="btnHistoryNext" class="rounded-lg h-8 px-3 bg-white dark:bg-surface-dark border border-border-light dark:border-border-dark hover:bg-background-light dark:hover:bg-background-dark text-xs font-bold">Berikutnya</button>
              </div>
            </div>
          </div>
          <div class="flex items-center justify-between mt-2">
            <div class="text-sm text-text-sub-light dark:text-text-sub-dark">Riwayat penggunaan kredit (lokal)</div>
            <div class="flex items-center gap-2">
              <input id="creditSearch" placeholder="Cari aktivitas..." class="rounded-lg border h-9 px-3 w-56 bg-white dark:bg-surface-dark border-border-light dark:border-border-dark" value="${safeText(state.riwayatKreditSearch || '')}" />
            </div>
          </div>
          <div class="bg-surface-light dark:bg-surface-dark rounded-xl border border-border-light dark:border-border-dark shadow-sm overflow-hidden">
            <div class="px-4 py-3 border-b border-border-light dark:border-border-dark text-xs text-text-sub-light dark:text-text-sub-dark">
              <span id="creditSummary"></span>
            </div>
            <div class="overflow-auto">
              <table class="min-w-full text-sm border whitespace-nowrap">
                <thead class="bg-background-light dark:bg-background-dark">
                  <tr>
                    <th class="border px-3 py-2 text-center">No</th>
                    <th class="border px-3 py-2 text-left">Waktu</th>
                    <th class="border px-3 py-2 text-left">Aktivitas</th>
                    <th class="border px-3 py-2 text-left">Rincian</th>
                    <th class="border px-3 py-2 text-center">Kredit</th>
                  </tr>
                </thead>
                <tbody id="creditTBody"></tbody>
              </table>
            </div>
            <div class="flex items-center justify-between px-3 py-2 border-t border-border-light dark:border-border-dark">
              <div id="creditInfo" class="text-xs text-text-sub-light dark:text-text-sub-dark"></div>
              <div class="flex items-center gap-2">
                <button id="btnCreditPrev" class="rounded-lg h-8 px-3 bg-white dark:bg-surface-dark border border-border-light dark:border-border-dark hover:bg-background-light dark:hover:bg-background-dark text-xs font-bold">Sebelumnya</button>
                <div id="creditPageInfo" class="text-xs"></div>
                <button id="btnCreditNext" class="rounded-lg h-8 px-3 bg-white dark:bg-surface-dark border border-border-light dark:border-border-dark hover:bg-background-light dark:hover:bg-background-dark text-xs font-bold">Berikutnya</button>
              </div>
            </div>
          </div>
        </div>
      `;

      async function loadRiwayat() {
        const tbody = el("historyTBody");
        if (!tbody) return;
        const res = await fetch("api/soal_user.php", { method: "POST", headers: {"Content-Type":"application/json"}, body: JSON.stringify({ type: "list" }) });
        const data = await res.json().catch(() => ({}));
        historyItems = Array.isArray(data?.items) ? data.items : [];
        historyPage = 1;
        renderHistoryTable();
        const search = el("historySearch");
        if (search) {
          search.oninput = () => {
            historyPage = 1;
            renderHistoryTable();
          };
        }
        const refreshBtn = el("btnHistoryRefresh");
        if (refreshBtn) {
          refreshBtn.onclick = () => loadRiwayat();
        }
        const prevBtn = el("btnHistoryPrev");
        const nextBtn = el("btnHistoryNext");
        if (prevBtn) prevBtn.onclick = () => { historyPage = Math.max(1, historyPage - 1); renderHistoryTable(); };
        if (nextBtn) nextBtn.onclick = () => { historyPage = historyPage + 1; renderHistoryTable(); };
        renderCreditHistoryTable();
        const cSearch = el("creditSearch");
        if (cSearch) {
          cSearch.oninput = () => {
            state.riwayatKreditSearch = cSearch.value || '';
            creditPage = 1;
            saveDebounced(false);
            renderCreditHistoryTable();
          };
        }
        const cPrev = el("btnCreditPrev");
        const cNext = el("btnCreditNext");
        if (cPrev) cPrev.onclick = () => { creditPage = Math.max(1, creditPage - 1); renderCreditHistoryTable(); };
        if (cNext) cNext.onclick = () => { creditPage = creditPage + 1; renderCreditHistoryTable(); };
      }
      function renderHistoryTable() {
        const tbody = el("historyTBody");
        if (!tbody) return;
        const q = (el("historySearch")?.value || "").toLowerCase();
        const filtered = historyItems.filter(it => String(it.title || '').toLowerCase().includes(q));
        const total = filtered.length;
        const totalPages = Math.max(1, Math.ceil(total / historyPageSize));
        if (historyPage > totalPages) historyPage = totalPages;
        const start = (historyPage - 1) * historyPageSize;
        const pageItems = filtered.slice(start, start + historyPageSize);
        const rows = pageItems.map((it, idx) => `
          <tr>
            <td class="border px-3 py-2">${start + idx + 1}</td>
            <td class="border px-3 py-2">${it.title || 'Paket'}</td>
            <td class="border px-3 py-2">${it.question_count || 0}</td>
            <td class="border px-3 py-2">${it.created_at || ''}</td>
            <td class="border px-3 py-2">
              <button data-id="${it.id}" class="btnHistoryEdit inline-flex items-center justify-center rounded border h-9 px-3 bg-white dark:bg-surface-dark hover:bg-background-light dark:hover:bg-background-dark">
                <span class="material-symbols-outlined text-[18px]">edit</span>
                <span class="ml-1 text-sm font-bold">Edit</span>
              </button>
            </td>
          </tr>
        `).join('');
        tbody.innerHTML = rows || `<tr><td colspan="5" class="border px-3 py-6 text-center text-text-sub-light dark:text-text-sub-dark">Belum ada riwayat.</td></tr>`;
        const info = el("historyInfo");
        if (info) {
          const to = Math.min(total, start + pageItems.length);
          const from = total ? start + 1 : 0;
          info.textContent = `Menampilkan ${from}–${to} dari ${total}`;
        }
        const pageInfo = el("historyPageInfo");
        if (pageInfo) pageInfo.textContent = `Halaman ${total ? historyPage : 0}/${total ? Math.max(1, Math.ceil(total / historyPageSize)) : 0}`;
        const prevBtn = el("btnHistoryPrev");
        const nextBtn = el("btnHistoryNext");
        if (prevBtn) prevBtn.disabled = historyPage <= 1;
        if (nextBtn) nextBtn.disabled = historyPage >= Math.max(1, Math.ceil(total / historyPageSize));
        tbody.querySelectorAll(".btnHistoryEdit").forEach(btn => {
          btn.addEventListener("click", async (e) => {
            const id = e.currentTarget.getAttribute("data-id");
            try {
              const r = await fetch("api/soal_user.php", { method: "POST", headers: {"Content-Type":"application/json"}, body: JSON.stringify({ type: "get", id: Number(id) }) });
              if (!r.ok) throw new Error();
              const d = await r.json();
              if (d && d.ok && d.state) {
                state = { ...DEFAULT_STATE(), ...d.state };
                saveDebounced(true);
                setView("identitas");
              } else {
                alert("Gagal memuat paket.");
              }
            } catch {
              alert("Gagal memuat paket.");
            }
          });
        });
      }
      function renderCreditHistoryTable() {
        const tbody = el("creditTBody");
        if (!tbody) return;
        const q = String(state.riwayatKreditSearch || "").toLowerCase().trim();
        const items = Array.isArray(state.creditHistory) ? state.creditHistory.slice() : [];
        const filtered = q ? items.filter(r => `${r?.kind||''} ${r?.detail||''}`.toLowerCase().includes(q)) : items;
        const total = filtered.length;
        const totalPages = Math.max(1, Math.ceil(total / creditPageSize));
        if (creditPage > totalPages) creditPage = totalPages;
        const start = (creditPage - 1) * creditPageSize;
        const pageItems = filtered.slice(start, start + creditPageSize);
        const rows = pageItems.map((r, idx) => {
          const dt = new Date(r.ts || Date.now());
          const t = dt.toLocaleString('id-ID', { day:'2-digit', month:'short', year:'2-digit', hour:'2-digit', minute:'2-digit' });
          const cost = Number(r.cost || 0);
          return `<tr>
            <td class="border px-3 py-2 text-center">${start + idx + 1}</td>
            <td class="border px-3 py-2">${t}</td>
            <td class="border px-3 py-2">${safeText(r.kind || '-')}</td>
            <td class="border px-3 py-2">${safeText(r.detail || '')}</td>
            <td class="border px-3 py-2 text-center text-red-600 font-semibold">-${cost}</td>
          </tr>`;
        }).join('');
        tbody.innerHTML = rows || `<tr><td colspan="5" class="border px-3 py-6 text-center text-text-sub-light dark:text-text-sub-dark">Belum ada riwayat penggunaan kredit.</td></tr>`;
        const info = el("creditInfo");
        if (info) {
          const to = Math.min(total, start + pageItems.length);
          const from = total ? start + 1 : 0;
          info.textContent = `Menampilkan ${from}–${to} dari ${total}`;
        }
        const pageInfo = el("creditPageInfo");
        if (pageInfo) pageInfo.textContent = `Halaman ${total ? creditPage : 0}/${total ? totalPages : 0}`;
        const prevBtn = el("btnCreditPrev");
        const nextBtn = el("btnCreditNext");
        if (prevBtn) prevBtn.disabled = creditPage <= 1;
        if (nextBtn) nextBtn.disabled = creditPage >= totalPages;
        const summary = el("creditSummary");
        if (summary) {
          const now = Date.now();
          const startToday = new Date();
          startToday.setHours(0,0,0,0);
          const t0 = startToday.getTime();
          const t7 = now - (7 * 24 * 60 * 60 * 1000);
          const sumToday = items.filter(r => new Date(r.ts || 0).getTime() >= t0).reduce((a,r)=>a+Number(r.cost||0),0);
          const sum7 = items.filter(r => new Date(r.ts || 0).getTime() >= t7).reduce((a,r)=>a+Number(r.cost||0),0);
          summary.textContent = `Hari ini: -${sumToday} kredit • 7 hari terakhir: -${sum7} kredit • Total entri: ${items.length}`;
        }
      }
      el("btnExportTop").addEventListener("click", () => {
         if (state.questions.length === 0) return alert("Belum ada soal!");
         exportDocx();
      });
      const btnExportKisi = el("btnExportKisi");
      if (btnExportKisi) {
        btnExportKisi.addEventListener("click", () => {
          exportKisiDocx();
        });
      }
      const btnExportKunci = el("btnExportKunci");
      if (btnExportKunci) {
        btnExportKunci.addEventListener("click", () => {
          exportKunciDocx();
        });
      }
      const btnQuizOpen = el("btnQuiz");
      if (btnQuizOpen) btnQuizOpen.addEventListener("click", openQuiz);
      el("btnQuizClose").addEventListener("click", closeQuiz);
      
      el("btnQuizPrev").addEventListener("click", () => {
        if (state.quiz.idx > 0) {
          state.quiz.idx--;
          state.quiz.reveal = false;
          renderQuizContent();
        }
      });
      el("btnQuizNext").addEventListener("click", () => {
        if (!state.quiz.reveal) {
          state.quiz.reveal = true;
        } else if (state.quiz.idx < state.questions.length - 1) {
          state.quiz.idx++;
          state.quiz.reveal = false;
        }
        renderQuizContent();
      });
      const applyUserProfileDefaults = () => {
        const p = USER_PROFILE || {};
        const nama = String(p.nama || "").trim();
        const jenjang = String(p.jenjang || "").trim();
        const sekolah = String(p.nama_sekolah || "").trim();
        if (nama) {
          try { if (state.modulAjar && !String(state.modulAjar.namaGuru || "").trim()) state.modulAjar.namaGuru = nama; } catch {}
          try { if (state.rpp && !String(state.rpp.nama_guru || "").trim()) state.rpp.nama_guru = nama; } catch {}
          try { if (state.identity && !String(state.identity.namaGuru || "").trim()) state.identity.namaGuru = nama; } catch {}
        }
        if (sekolah) {
          try { if (state.modulAjar && !String(state.modulAjar.institusi || "").trim()) state.modulAjar.institusi = sekolah; } catch {}
          try { if (state.rpp && !String(state.rpp.nama_sekolah || "").trim()) state.rpp.nama_sekolah = sekolah; } catch {}
          try { if (state.identity && !String(state.identity.namaSekolah || "").trim()) state.identity.namaSekolah = sekolah; } catch {}
        }
        if (jenjang) {
          try { if (state.modulAjar && !String(state.modulAjar.jenjang || "").trim()) state.modulAjar.jenjang = jenjang; } catch {}
          try { if (state.rpp && !String(state.rpp.jenjang || "").trim()) state.rpp.jenjang = jenjang; } catch {}
          try { if (state.identity && !String(state.identity.jenjang || "").trim()) state.identity.jenjang = jenjang; } catch {}
        }
      };
      const ensureProfileComplete = () => {
        const p = USER_PROFILE || {};
        const nama = String(p.nama || "").trim();
        const jenjang = String(p.jenjang || "").trim();
        const sekolah = String(p.nama_sekolah || "").trim();
        const noHp = String(p.no_hp || "").trim();
        if (nama && jenjang && sekolah && noHp) return true;
        const modal = el("profileRequiredModal");
        const form = el("profileRequiredForm");
        const err = el("profileRequiredError");
        const inputNama = el("profileNama");
        const inputJenjang = el("profileJenjang");
        const inputSekolah = el("profileSekolah");
        const inputNoHp = el("profileNoHp");
        const btn = el("profileSaveBtn");
        if (!modal || !form || !inputNama || !inputJenjang || !inputSekolah || !inputNoHp || !btn) return false;
        inputNama.value = nama;
        inputJenjang.value = jenjang;
        inputSekolah.value = sekolah;
        inputNoHp.value = noHp;
        modal.classList.remove("hidden");
        modal.classList.add("flex");
        try { document.body.style.overflow = "hidden"; } catch {}
        if (err) { err.classList.add("hidden"); err.textContent = ""; }
        setTimeout(() => {
          if (!inputNama.value.trim()) inputNama.focus();
          else if (!inputJenjang.value.trim()) inputJenjang.focus();
          else if (!inputSekolah.value.trim()) inputSekolah.focus();
          else inputNoHp.focus();
        }, 0);
        if (!form.__bound) {
          form.__bound = true;
          document.addEventListener("keydown", (e) => {
            if (!modal.classList.contains("hidden") && e.key === "Escape") { e.preventDefault(); e.stopPropagation(); }
          }, true);
          form.addEventListener("submit", async (e) => {
            e.preventDefault();
            const vNama = String(inputNama.value || "").trim();
            const vJenjang = String(inputJenjang.value || "").trim();
            const vSekolah = String(inputSekolah.value || "").trim();
            const vNoHp = String(inputNoHp.value || "").trim();
            if (!vNama || !vJenjang || !vSekolah || !vNoHp) {
              if (err) { err.textContent = "Mohon lengkapi semua data agar fitur bisa terisi otomatis."; err.classList.remove("hidden"); }
              return;
            }
            btn.disabled = true;
            btn.classList.add("opacity-70");
            try {
              const fd = new FormData();
              fd.append("nama", vNama);
              fd.append("jenjang", vJenjang);
              fd.append("nama_sekolah", vSekolah);
              fd.append("no_hp", vNoHp);
              const res = await fetch("index.php?ajax=profile_update", { method: "POST", credentials: "same-origin", body: fd });
              const data = await res.json().catch(() => ({}));
              if (!res.ok || !data || data.ok !== true) {
                const msg = (data && data.message) ? String(data.message) : "Gagal menyimpan profil.";
                if (err) { err.textContent = msg; err.classList.remove("hidden"); }
                btn.disabled = false;
                btn.classList.remove("opacity-70");
                return;
              }
              window.location.reload();
            } catch (ex) {
              if (err) { err.textContent = "Gagal menyimpan profil. Coba lagi ya."; err.classList.remove("hidden"); }
              btn.disabled = false;
              btn.classList.remove("opacity-70");
            }
          });
        }
        return false;
      };
      if (load()) {
        applyTheme();
      } else {
        applyTheme();
        autoFillPaket();
      }
      applyUserProfileDefaults();
      render();
      if (ensureProfileComplete()) ensureUsagePolicyAck();
    </script>
  </body>
</html>
