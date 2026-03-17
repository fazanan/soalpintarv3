<?php
require __DIR__ . '/db.php';
$slug = isset($_GET['slug']) ? trim((string)$_GET['slug']) : '';
$pubIdParam = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$n = isset($_GET['n']) ? (int)$_GET['n'] : 0;
if (($slug === '' && $pubIdParam <= 0) || $n <= 0) {
  http_response_code(400);
  echo 'Bad Request';
  exit;
}
$sql = $pubIdParam > 0
  ? "SELECT id, slug, mapel, kelas, payload_public, is_active, expire_at FROM published_quizzes WHERE id=? LIMIT 1"
  : "SELECT id, slug, mapel, kelas, payload_public, is_active, expire_at FROM published_quizzes WHERE slug=? LIMIT 1";
$stmt = $mysqli->prepare($sql);
if ($pubIdParam > 0) $stmt->bind_param('i', $pubIdParam);
else $stmt->bind_param('s', $slug);
$stmt->execute();
$stmt->bind_result($pubId, $slugDb, $mapel, $kelas, $payloadJson, $active, $expireAt);
if (!$stmt->fetch()) {
  $stmt->close();
  http_response_code(404);
  echo 'Not Found';
  exit;
}
$stmt->close();
if ($slug === '') $slug = (string)$slugDb;
if ((int)$active !== 1) {
  http_response_code(403);
  echo 'Link nonaktif';
  exit;
}
if ($expireAt && strtotime($expireAt) < time()) {
  http_response_code(410);
  echo 'Link kedaluwarsa';
  exit;
}
$decoded = json_decode($payloadJson, true);
$items = [];
$maxAbsen = 0;
$showSolution = 0;
$answerKeySettings = [];
$studentName = isset($_GET['name']) ? trim((string)$_GET['name']) : (isset($_GET['nama']) ? trim((string)$_GET['nama']) : '');
if (is_array($decoded)) {
  if (isset($decoded['items']) && is_array($decoded['items'])) {
    $items = $decoded['items'];
    $maxAbsen = isset($decoded['settings']['max_absen']) ? (int)$decoded['settings']['max_absen'] : 0;
    $showSolution = isset($decoded['settings']['show_solution']) ? (int)$decoded['settings']['show_solution'] : 0;
    $answerKeySettings = isset($decoded['settings']['answer_key']) && is_array($decoded['settings']['answer_key']) ? $decoded['settings']['answer_key'] : [];
  } else {
    $items = $decoded;
  }
}
if ($maxAbsen > 0 && ($n < 1 || $n > $maxAbsen)) {
  http_response_code(400);
  echo 'Nomor absen di luar jangkauan';
  exit;
}
?><!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo htmlspecialchars($mapel ?: 'Soal'); ?> | No Absen <?php echo (int)$n; ?></title>
  <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
  <style>
    .brand-badge{display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:9999px;border:1px solid #e7edf3;background:#fff}
    .brand-dot{width:10px;height:10px;border-radius:6px;background:#137fec}
    .brand-text{font-weight:800;letter-spacing:.2px}
  </style>
</head>
<body class="bg-gray-50">
  <div class="max-w-4xl mx-auto p-4 md:p-6">
    <div id="paper" class="bg-white text-black p-6 md:p-10 shadow-paper border border-gray-200 rounded-2xl">
      <div class="border-b-2 border-black pb-6 mb-8 relative">
        <div class="text-center mb-6">
          <h2 class="font-bold text-2xl uppercase tracking-wider mb-1">
            <?php echo htmlspecialchars((strtoupper(trim((string)($decoded['settings']['meta']['sekolah'] ?? ''))) ?: 'NAMA SEKOLAH')); ?>
          </h2>
          <h3 class="font-bold text-lg uppercase tracking-wide">
            NASKAH SOAL
          </h3>
          <div class="text-sm mt-1">Mata Pelajaran <?php echo htmlspecialchars($mapel ?: '-'); ?></div>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-12 gap-y-2 text-sm">
          <div class="space-y-1.5">
            <div class="flex items-start">
              <span class="w-36 font-semibold shrink-0">Mata Pelajaran</span><span class="mr-2">:</span><span><?php echo htmlspecialchars($mapel ?: '-'); ?></span>
            </div>
            <div class="flex items-start">
              <span class="w-36 font-semibold shrink-0">Kelas</span><span class="mr-2">:</span><span><?php echo htmlspecialchars($kelas ?: '-'); ?></span>
            </div>
            <div class="flex items-start">
              <span class="w-36 font-semibold shrink-0">Hari / Tanggal</span><span class="mr-2">:</span><span id="dtNow"></span>
            </div>
          </div>
          <div class="space-y-1.5">
            <div class="flex items-center">
              <span class="w-36 font-semibold shrink-0">Nama</span><span class="mr-2">:</span>
              <span><?php echo htmlspecialchars($studentName !== '' ? $studentName : '-'); ?></span>
            </div>
            <div class="flex items-start">
              <span class="w-36 font-semibold shrink-0">No. Absen</span><span class="mr-2">:</span>
              <span><?php echo (int)$n; ?></span>
            </div>
          </div>
        </div>
      </div>
      <div id="root"></div>
    </div>
  </div>
  <script>
    const payload = <?php echo json_encode($items, JSON_UNESCAPED_UNICODE); ?>;
    const absen = <?php echo (int)$n; ?>;
    const showSolution = <?php echo (int)$showSolution; ?> === 1;
    const answerKey = <?php echo json_encode($answerKeySettings, JSON_UNESCAPED_UNICODE); ?>;
    (function(){
      const el = document.getElementById('dtNow');
      if (el) {
        const dt = new Date();
        const months = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
        const d = String(dt.getDate()).padStart(2,'0');
        const m = months[dt.getMonth()];
        const y = String(dt.getFullYear()).slice(-2);
        const hh = String(dt.getHours()).padStart(2,'0');
        const mm = String(dt.getMinutes()).padStart(2,'0');
        el.textContent = `${d}/${m}/${y} - ${hh}:${mm}`;
      }
    })();
    let submitted = false;
    let activeTab = 'soal';
    function shuffleWithSeed(arr, seed) {
      const a = arr.slice();
      let s = seed;
      for (let i = a.length - 1; i > 0; i--) {
        s = (s * 1103515245 + 12345) & 0x7fffffff;
        const j = s % (i + 1);
        const tmp = a[i]; a[i] = a[j]; a[j] = tmp;
      }
      return a;
    }
    function renderTabs() {
      const container = document.getElementById('root');
      const hasSolutionTab = showSolution && submitted;
      const tabs = `
        <div class="flex items-center gap-2 border-b mb-4">
          <button class="px-3 py-2 text-sm font-semibold ${activeTab==='soal'?'text-blue-600 border-b-2 border-blue-600':''}" onclick="activeTab='soal'; renderTabs()">Soal</button>
          ${hasSolutionTab ? `<button class="px-3 py-2 text-sm font-semibold ${activeTab==='solusi'?'text-blue-600 border-b-2 border-blue-600':''}" onclick="activeTab='solusi'; renderTabs()">Jawaban & Pembahasan</button>` : ``}
        </div>
      `;
      let body = '';
      if (activeTab === 'soal') {
        body = renderSoal();
      } else {
        body = renderSolusi();
      }
      container.innerHTML = tabs + body;
      bindSubmit();
    }
    function renderSoal() {
      const questions = Array.isArray(payload) ? payload : [];
      const indices = questions.map((_, i) => i);
      const order = shuffleWithSeed(indices, absen);
      const cards = order.map((origIdx, i) => {
        const q = questions[origIdx] || {};
        const opts = Array.isArray(q.options) ? q.options : [];
        const optsHtml = opts.map((t, oi) => `
          <div class="flex gap-3 items-start">
            <label class="font-semibold pt-0.5">${String.fromCharCode(65 + oi)}.</label>
            <label class="flex-1 flex gap-3 p-2 rounded-lg hover:bg-gray-50 transition">
              <input type="radio" name="q_${i}" value="${oi}" class="mt-1 rounded border-gray-300">
              <span class="text-sm leading-relaxed">${String(t || '')}</span>
            </label>
          </div>
        `).join('');
        return `
          <div class="mb-6">
            <div class="flex gap-4">
              <span class="font-bold text-lg min-w-[1.5rem]">${i + 1}.</span>
              <div class="flex-1">
                <p class="mb-3 text-justify leading-relaxed">${String(q.question || '')}</p>
                ${q.image ? `<img src="${String(q.image)}" class="w-64 h-64 object-contain rounded-lg mb-3 border shadow-sm">` : ``}
                <div class="grid grid-cols-1 gap-2 pl-1">
                  ${optsHtml}
                </div>
              </div>
            </div>
          </div>
        `;
      }).join('');
      return `
        <div class="space-y-6">
          <div class="font-bold mb-1">PILIHAN GANDA</div>
          <div class="italic text-sm mb-4">Pilihlah salah satu jawaban yang paling tepat!</div>
          ${cards}
          <div class="pt-2">
            <button id="btnSubmit" class="h-11 px-5 rounded-lg bg-blue-600 hover:bg-blue-700 text-white font-semibold">Submit</button>
            <div id="info" class="text-sm text-gray-600 mt-2"></div>
          </div>
        </div>
      `;
    }
    function renderSolusi() {
      const questions = Array.isArray(payload) ? payload : [];
      const rows = questions.map((q, i) => {
        const correct = typeof answerKey[i] === 'number' ? answerKey[i] : -1;
        const correctText = (Array.isArray(q.options) && q.options[correct]) ? q.options[correct] : '';
        const letter = correct >= 0 ? String.fromCharCode(65 + correct) : '-';
        const explain = String(q.explain || '');
        return `
          <div class="mb-4 rounded-xl border p-4">
            <div class="font-semibold mb-1">${i+1}. ${String(q.question || '')}</div>
            <div class="text-sm"><span class="font-semibold">Kunci:</span> ${letter}${correctText ? ` — ${correctText}` : ''}</div>
            ${explain ? `<div class="text-sm mt-2"><span class="font-semibold">Pembahasan:</span> ${explain}</div>` : ``}
          </div>
        `;
      }).join('');
      return rows || `<div class="text-sm text-gray-600">Belum ada data pembahasan.</div>`;
    }
    function bindSubmit() {
      const btn = document.getElementById('btnSubmit');
      if (!btn) return;
      btn.addEventListener('click', async () => {
        const questions = Array.isArray(payload) ? payload : [];
        const indices = questions.map((_, i) => i);
        const order = shuffleWithSeed(indices, absen);
        const answers = [];
        for (let i=0;i<order.length;i++) {
          const el = document.querySelector(`input[name="q_${i}"]:checked`);
          answers.push(el ? Number(el.value) : -1);
        }
        const info = document.getElementById('info');
        info.textContent = 'Mengirim...';
        try {
          const res = await fetch('api/published_quiz_submit.php', {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({ slug: <?php echo json_encode($slug); ?>, absen: absen, answers, order_map: order })
          });
          const js = await res.json();
          if (js && js.ok) {
            info.textContent = 'Terkirim. Terima kasih.';
            submitted = true;
            if (showSolution) {
              activeTab = 'solusi';
              renderTabs();
            }
          } else {
            info.textContent = 'Gagal mengirim.';
          }
        } catch {
          info.textContent = 'Gagal mengirim.';
        }
      });
    }
    renderTabs();
  </script>
</body>
</html>
