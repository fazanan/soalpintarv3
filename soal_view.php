<?php
$isReview = isset($_GET['review']) && (string)$_GET['review'] !== '' && (string)$_GET['review'] !== '0';
if ($isReview) {
  session_start();
  if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
  }
}
require __DIR__ . '/db.php';
$slug = isset($_GET['slug']) ? trim((string)$_GET['slug']) : '';
$pubIdParam = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$n = isset($_GET['n']) ? (int)$_GET['n'] : 0;
if ($slug === '' && $pubIdParam <= 0) {
  http_response_code(400);
  echo 'Bad Request';
  exit;
}
$sql = $pubIdParam > 0
  ? "SELECT id, slug, mapel, kelas, payload_public, is_active, expire_at, user_id FROM published_quizzes WHERE id=? LIMIT 1"
  : "SELECT id, slug, mapel, kelas, payload_public, is_active, expire_at, user_id FROM published_quizzes WHERE slug=? LIMIT 1";
$stmt = $mysqli->prepare($sql);
if ($pubIdParam > 0) $stmt->bind_param('i', $pubIdParam);
else $stmt->bind_param('s', $slug);
$stmt->execute();
$pubId = 0;
$slugDb = '';
$mapel = '';
$kelas = '';
$payloadJson = '';
$active = 0;
$expireAt = null;
$ownerId = 0;
$stmt->bind_result($pubId, $slugDb, $mapel, $kelas, $payloadJson, $active, $expireAt, $ownerId);
if (!$stmt->fetch()) {
  $stmt->close();
  http_response_code(404);
  echo 'Not Found';
  exit;
}
$stmt->close();
if ($slug === '') $slug = (string)$slugDb;
if ($isReview) {
  if ((int)$ownerId !== (int)($_SESSION['user_id'] ?? 0)) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
  }
}
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
$logoSekolah = '';
$__sp_is_safe_img_src = function (string $src): bool {
  $s = trim($src);
  if ($s === '') return false;
  $low = strtolower($s);
  if (strpos($low, 'data:image/') === 0) return true;
  if (preg_match('/^https?:\/\//i', $s)) return true;
  if (strpos($s, '/') === 0) return true;
  if (preg_match('/^(uploads|assets)\//i', $s)) return true;
  return false;
};
$__sp_normalize_student_name = function ($s) {
  $s = trim((string)$s);
  if ($s === '') return '';
  $s = str_replace("\0", '', $s);
  for ($i = 0; $i < 2; $i++) {
    if (strpos($s, '%') === false && strpos($s, '+') === false) break;
    $dec = urldecode($s);
    if ($dec === $s) break;
    $s = $dec;
  }
  $s = preg_replace('/\s+/', ' ', $s);
  if (function_exists('mb_strlen') && function_exists('mb_substr')) {
    if (mb_strlen($s, 'UTF-8') > 120) $s = mb_substr($s, 0, 120, 'UTF-8');
  } else {
    if (strlen($s) > 120) $s = substr($s, 0, 120);
  }
  return $s;
};
$studentName = isset($_GET['name']) ? trim((string)$_GET['name']) : (isset($_GET['nama']) ? trim((string)$_GET['nama']) : '');
$studentName = $__sp_normalize_student_name($studentName);
if (is_array($decoded)) {
  $logoCandidate = '';
  if (isset($decoded['settings']) && is_array($decoded['settings'])) {
    if (isset($decoded['settings']['meta']) && is_array($decoded['settings']['meta'])) {
      $logoCandidate = (string)($decoded['settings']['meta']['logo']
        ?? $decoded['settings']['meta']['logo_url']
        ?? $decoded['settings']['meta']['logo_sekolah']
        ?? $decoded['settings']['meta']['logoSekolah']
        ?? $decoded['settings']['meta']['school_logo']
        ?? $decoded['settings']['meta']['logoSchool']
        ?? '');
    }
    if ($logoCandidate === '') $logoCandidate = (string)($decoded['settings']['logo'] ?? $decoded['settings']['logo_url'] ?? $decoded['settings']['logoSekolah'] ?? '');
  }
  $logoCandidate = trim($logoCandidate);
  if ($logoCandidate !== '' && $__sp_is_safe_img_src($logoCandidate)) $logoSekolah = $logoCandidate;
  if (isset($decoded['items']) && is_array($decoded['items'])) {
    $items = $decoded['items'];
    $maxAbsen = isset($decoded['settings']['max_absen']) ? (int)$decoded['settings']['max_absen'] : 0;
    $showSolution = isset($decoded['settings']['show_solution']) ? (int)$decoded['settings']['show_solution'] : 0;
    $answerKeySettings = isset($decoded['settings']['answer_key']) && is_array($decoded['settings']['answer_key']) ? $decoded['settings']['answer_key'] : [];
  } else {
    $items = $decoded;
  }
}
if ($n > 0 && $maxAbsen > 0 && ($n < 1 || $n > $maxAbsen)) {
  http_response_code(400);
  echo 'Nomor absen di luar jangkauan';
  exit;
}
?><!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo htmlspecialchars($mapel ?: 'Soal'); ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Kalam:wght@700&display=swap" rel="stylesheet">
  <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
  <style>
    .brand-badge{display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:9999px;border:1px solid #e7edf3;background:#fff}
    .brand-dot{width:10px;height:10px;border-radius:6px;background:#137fec}
    .brand-text{font-weight:800;letter-spacing:.2px}
    .score-hand{font-family:'Kalam','Segoe Print','Bradley Hand','Comic Sans MS',cursive}
  </style>
</head>
<body class="bg-gray-50">
  <div class="max-w-4xl mx-auto p-4 md:p-6">
    <div id="paper" class="bg-white text-black p-6 md:p-10 shadow-paper border border-gray-200 rounded-2xl">
      <div class="border-b-2 border-black pb-6 mb-8 relative">
        <div class="grid grid-cols-[96px_1fr_72px] items-start gap-3 mb-6">
          <div id="reviewScoreBox" class="flex items-start"></div>
          <div class="text-center">
            <h2 class="font-bold text-2xl uppercase tracking-wider mb-1">
              <?php echo htmlspecialchars((strtoupper(trim((string)($decoded['settings']['meta']['sekolah'] ?? ''))) ?: 'NAMA SEKOLAH')); ?>
            </h2>
            <h3 class="font-bold text-lg uppercase tracking-wide">
              NASKAH SOAL
            </h3>
            <div class="text-sm mt-1">Mata Pelajaran <?php echo htmlspecialchars($mapel ?: '-'); ?></div>
          </div>
          <div class="flex justify-end">
            <?php if ($logoSekolah !== ''): ?>
              <img src="<?php echo htmlspecialchars($logoSekolah); ?>" alt="Logo sekolah" class="w-[72px] h-[72px] object-contain">
            <?php endif; ?>
          </div>
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
              <span id="studentName"><?php echo htmlspecialchars($studentName !== '' ? $studentName : '-'); ?></span>
            </div>
            <div class="flex items-start">
              <span class="w-36 font-semibold shrink-0">No. Absen</span><span class="mr-2">:</span>
              <span id="studentAbsen"><?php echo (int)$n; ?></span>
            </div>
          </div>
        </div>
      </div>
      <div id="root"></div>
    </div>
  </div>
  <div id="identityModal" class="fixed inset-0 hidden items-center justify-center" style="display:none; background: rgba(0,0,0,0.5); z-index:50;">
    <div class="bg-white rounded-2xl border shadow-xl w-[92vw] max-w-[520px] overflow-hidden">
      <div class="p-5 border-b flex items-center justify-between">
        <div class="font-bold text-lg">Mulai Quiz</div>
      </div>
      <div class="p-5 space-y-4">
        <div class="text-sm text-gray-700">Isi No Absen dan Nama terlebih dahulu agar hasil tercatat dengan benar.</div>
        <?php if ($maxAbsen > 0): ?>
          <div class="text-xs text-gray-500">No Absen: 1 sampai <?php echo (int)$maxAbsen; ?></div>
        <?php endif; ?>
        <div class="grid grid-cols-1 gap-3">
          <div>
            <label class="block text-sm font-semibold mb-1">No Absen</label>
            <input id="inpAbsen" type="number" min="1" class="w-full h-11 rounded-lg border px-3" placeholder="Contoh: 12">
          </div>
          <div>
            <label class="block text-sm font-semibold mb-1">Nama</label>
            <input id="inpNama" type="text" class="w-full h-11 rounded-lg border px-3" placeholder="Contoh: Siti Aisyah">
          </div>
        </div>
        <div class="rounded-lg border border-blue-200 bg-blue-50 text-blue-800 p-3 text-sm">
          Setelah diisi, klik tombol <b>Mulai Kerjakan</b>.
        </div>
        <div class="flex items-center gap-3">
          <button id="btnStart" class="flex-1 h-11 rounded-lg bg-blue-600 hover:bg-blue-700 text-white font-semibold">Mulai Kerjakan</button>
        </div>
        <div id="idErr" class="text-sm text-red-600"></div>
      </div>
    </div>
  </div>
  <script>
    const payload = <?php echo json_encode($items, JSON_UNESCAPED_UNICODE); ?>;
    const pubId = <?php echo (int)$pubId; ?>;
    const slug = <?php echo json_encode($slug, JSON_UNESCAPED_UNICODE); ?>;
    const maxAbsen = <?php echo (int)$maxAbsen; ?>;
    let absen = <?php echo (int)$n; ?>;
    let studentName = <?php echo json_encode($studentName, JSON_UNESCAPED_UNICODE); ?>;
    const showSolution = <?php echo (int)$showSolution; ?> === 1;
    const answerKey = <?php echo json_encode($answerKeySettings, JSON_UNESCAPED_UNICODE); ?>;
    const pointsByType = <?php echo json_encode((is_array($decoded) && isset($decoded['settings']['points_by_type']) && is_array($decoded['settings']['points_by_type'])) ? $decoded['settings']['points_by_type'] : [], JSON_UNESCAPED_UNICODE); ?>;
    const pointsMode = <?php echo json_encode((is_array($decoded) && isset($decoded['settings']['points_mode'])) ? (string)$decoded['settings']['points_mode'] : 'per_question', JSON_UNESCAPED_UNICODE); ?>;
    const isReview = <?php echo $isReview ? 'true' : 'false'; ?>;
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
    let soalTypeTab = 'pg';
    const studentAnswers = {};
    let reviewPct = null;
    let reviewScore = null;
    let reviewTotal = null;
    let manualGrades = {};
    let manualDirty = false;
    const getPts = (type) => {
      const t0 = String(type || '').trim();
      const t = (t0 === 'isian') ? 'isian_singkat' : t0;
      const n = Math.floor(Number(pointsByType?.[t]));
      return Number.isFinite(n) ? Math.min(100, Math.max(0, n)) : 1;
    };
    function renderReviewScoreBox() {
      const box = document.getElementById('reviewScoreBox');
      if (!box) return;
      if (!isReview || !Number.isFinite(Number(reviewPct))) { box.innerHTML = ''; return; }
      const pct = Math.max(0, Math.min(100, Math.round(Number(reviewPct))));
      const ok = pct >= 60;
      const c = ok ? 'text-green-600 border-green-500' : 'text-red-600 border-red-500';
      box.innerHTML = `
        <div class="w-24 h-24 rounded-xl border-2 ${c} flex flex-col overflow-hidden bg-white">
          <div class="flex-1 flex items-center justify-center">
            <div class="score-hand text-4xl leading-none">${pct}</div>
          </div>
          <div class="h-7 border-t border-black/10 flex items-center justify-center text-xs font-extrabold tracking-wide text-gray-700">
            NILAI
          </div>
        </div>
      `;
    }
    let manualSaveStatus = '';
    const isSubjectiveType = (t) => t === 'isian_singkat' || t === 'uraian';
    const onManualGradeChange = (origIdx, maxPts, v) => {
      if (!isReview) return;
      const idx = Number(origIdx);
      if (!Number.isFinite(idx) || idx < 0) return;
      const max = Math.max(0, Math.floor(Number(maxPts)));
      const n0 = Math.floor(Number(v));
      const n = Number.isFinite(n0) ? Math.min(max, Math.max(0, n0)) : 0;
      manualGrades = { ...(manualGrades || {}), [String(idx)]: n };
      manualDirty = true;
      manualSaveStatus = 'Perubahan belum disimpan';
      render();
    };
    const saveManualGrades = async () => {
      if (!isReview) return;
      manualSaveStatus = 'Menyimpan...';
      render();
      try {
        const res = await fetch(`api/published_quiz_results.php?slug=${encodeURIComponent(String(slug||''))}&mode=grade`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          credentials: 'same-origin',
          body: JSON.stringify({ absen: Number(absen||0), grades: manualGrades || {} }),
        });
        const js = await res.json().catch(() => null);
        if (!res.ok || !js || js.ok !== true) {
          const msg = js?.message || js?.error || 'Gagal menyimpan nilai';
          manualSaveStatus = String(msg);
          render();
          return;
        }
        reviewScore = Number(js.score ?? reviewScore);
        reviewTotal = Number(js.total ?? reviewTotal);
        if (Number.isFinite(reviewScore) && Number.isFinite(reviewTotal) && reviewTotal > 0) reviewPct = (reviewScore / reviewTotal) * 100;
        manualGrades = (js.grades && typeof js.grades === 'object') ? js.grades : (manualGrades || {});
        manualDirty = false;
        manualSaveStatus = 'Tersimpan';
        renderReviewScoreBox();
        render();
      } catch (e) {
        manualSaveStatus = 'Gagal menyimpan nilai';
        render();
      }
    };
    window.onManualGradeChange = onManualGradeChange;
    window.saveManualGrades = saveManualGrades;
    function escapeHtml(s) {
      return String(s ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;');
    }
    function renderMathText(s) {
      const esc = escapeHtml(String(s ?? '')).replace(/[＾ˆ˄]/g, '^');
      return esc
        .replace(/([0-9A-Za-z\)\]])\^\(([^)]+)\)/g, '$1<sup>$2</sup>')
        .replace(/([0-9A-Za-z\)\]])\^([-+]?[0-9A-Za-z]+)/g, '$1<sup>$2</sup>');
    }
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
    function normalizeQType(q) {
      const t = String(q?.type || '').trim();
      if (t === 'isian_singkat' || t === 'isian') return 'isian_singkat';
      if (t === 'menjodohkan') return 'menjodohkan';
      if (t === 'uraian') return 'uraian';
      if (t === 'benar_salah' || t === 'pg_kompleks' || t === 'pg') return t;
      return 'pg';
    }
    function buildOrderData(questions, seed) {
      const typeOrder = ['pg', 'benar_salah', 'pg_kompleks', 'menjodohkan', 'isian_singkat', 'uraian'];
      const groups = {};
      for (const t of typeOrder) groups[t] = [];
      const others = [];
      for (let i = 0; i < questions.length; i++) {
        const t = normalizeQType(questions[i]);
        if (groups[t]) groups[t].push(i);
        else others.push(i);
      }
      const shuffledGroups = {};
      const all = [];
      for (let ti = 0; ti < typeOrder.length; ti++) {
        const t = typeOrder[ti];
        const g = groups[t] || [];
        const s = (Number(seed || 0) * 997) + (ti + 1) * 7919;
        const sh = shuffleWithSeed(g, s);
        shuffledGroups[t] = sh;
        for (const idx of sh) all.push(idx);
      }
      for (const idx of others) all.push(idx);
      const posByOrig = {};
      for (let p = 0; p < all.length; p++) posByOrig[all[p]] = p;
      return { typeOrder, groups: shuffledGroups, orderAll: all, posByOrig };
    }
    function onAnswerChange(pos, value, checked, isMulti) {
      const p = Number(pos);
      const v = Number(value);
      if (!Number.isFinite(p) || p < 0) return;
      if (!Number.isFinite(v) || v < 0) return;
      if (Number(isMulti || 0) === 1) {
        const cur = Array.isArray(studentAnswers[p]) ? studentAnswers[p] : [];
        const set = new Set(cur.map(n => Number(n)));
        if (checked) set.add(v);
        else set.delete(v);
        const next = Array.from(set).filter(n => Number.isFinite(n) && n >= 0).sort((a, b) => a - b);
        studentAnswers[p] = next;
        return;
      }
      studentAnswers[p] = v;
    }
    function onShortAnswerChange(pos, value) {
      const p = Number(pos);
      if (!Number.isFinite(p) || p < 0) return;
      studentAnswers[p] = String(value ?? '');
    }
    function onMatchChange(pos, leftIndex, rightIndex) {
      const p = Number(pos);
      const li = Number(leftIndex);
      const ri = Number(rightIndex);
      if (!Number.isFinite(p) || p < 0) return;
      if (!Number.isFinite(li) || li < 0) return;
      const cur = Array.isArray(studentAnswers[p]) ? studentAnswers[p] : [];
      const next = cur.slice();
      while (next.length <= li) next.push(-1);
      next[li] = Number.isFinite(ri) ? Math.floor(ri) : -1;
      studentAnswers[p] = next;
    }
    function normalizeShortText(s) {
      let v = String(s ?? '');
      v = v.replace(/\r\n/g, '\n').replace(/\r/g, '\n').trim();
      v = v.replace(/\s+/g, ' ');
      v = v.replace(/^[\s"'`]+/, '').replace(/[\s"'`]+$/, '');
      v = v.replace(/[.,;:!?]+$/g, '');
      return v.toLowerCase();
    }
    function shortKeyList(raw) {
      const out = [];
      const push = (x) => {
        const t = String(x ?? '').trim();
        if (!t) return;
        const n = normalizeShortText(t);
        if (!n) return;
        if (!out.includes(n)) out.push(n);
      };
      if (Array.isArray(raw)) raw.forEach(push);
      else if (typeof raw === 'string') {
        if (raw.includes('|')) raw.split('|').forEach(push);
        else push(raw);
      } else push(raw);
      return out;
    }
    function isShortCorrect(answerRaw, keyRaw) {
      const ans = normalizeShortText(answerRaw);
      if (!ans) return false;
      const keys = shortKeyList(keyRaw);
      return keys.includes(ans);
    }
    function computeReviewBreakdown(questions, orderData) {
      const mode = String(pointsMode || '').trim();
      const earned = {};
      const total = {};
      const ensure = (t) => {
        if (!Object.prototype.hasOwnProperty.call(earned, t)) earned[t] = 0;
        if (!Object.prototype.hasOwnProperty.call(total, t)) total[t] = 0;
      };
      const eqNumArray = (a, b) => {
        const aa = Array.isArray(a) ? a.map(n => Number(n)).filter(n => Number.isFinite(n) && n >= 0).sort((x, y) => x - y) : [];
        const bb = Array.isArray(b) ? b.map(n => Number(n)).filter(n => Number.isFinite(n) && n >= 0).sort((x, y) => x - y) : [];
        if (aa.length !== bb.length) return false;
        for (let i = 0; i < aa.length; i++) if (aa[i] !== bb[i]) return false;
        return true;
      };
      const getWeight = (t, counts) => {
        const key = String(t || '').trim();
        const n = Math.floor(Number(pointsByType?.[key]));
        if (Number.isFinite(n)) return Math.min(100, Math.max(0, n));
        const c = Math.floor(Number(counts?.[key] || 0));
        return Number.isFinite(c) ? Math.max(0, c) : 0;
      };
      if (mode === 'per_type_total') {
        const counts = {};
        const corrects = {};
        const typesByIdx = {};
        for (let origIdx = 0; origIdx < questions.length; origIdx++) {
          const t = normalizeQType(questions[origIdx] || {});
          typesByIdx[origIdx] = t;
          counts[t] = (counts[t] || 0) + 1;
          corrects[t] = corrects[t] || 0;
        }
        for (let origIdx = 0; origIdx < questions.length; origIdx++) {
          const q = questions[origIdx] || {};
          const type = String(typesByIdx[origIdx] || normalizeQType(q));
          const pos = Number(orderData.posByOrig[origIdx] ?? -1);
          const picked = Number.isFinite(pos) && pos >= 0 ? studentAnswers[pos] : undefined;
          if (type === 'uraian') continue;
          if (type === 'isian_singkat') {
            if (Object.prototype.hasOwnProperty.call(manualGrades || {}, String(origIdx))) continue;
            const ok = isShortCorrect(String(picked ?? ''), answerKey[origIdx]);
            if (ok) corrects[type] = (corrects[type] || 0) + 1;
            continue;
          }
          if (type === 'menjodohkan') {
            const key = Array.isArray(answerKey[origIdx]) ? answerKey[origIdx].map(n => Number(n)) : [];
            const ans = Array.isArray(picked) ? picked.map(n => Number(n)) : [];
            const n = Math.min(key.length, ans.length);
            let ok = n >= 2 && key.length === n;
            for (let i = 0; i < n; i++) {
              if (Number(key[i]) !== Number(ans[i])) { ok = false; break; }
            }
            if (ok) corrects[type] = (corrects[type] || 0) + 1;
            continue;
          }
          if (type === 'pg_kompleks') {
            const corr = Array.isArray(answerKey[origIdx]) ? answerKey[origIdx] : [];
            const p = Array.isArray(picked) ? picked.map(n => Number(n)).filter(n => Number.isFinite(n) && n >= 0) : [];
            const c = Array.isArray(corr) ? corr.map(n => Number(n)).filter(n => Number.isFinite(n) && n >= 0) : [];
            const set = new Set(c);
            const ok = p.length > 0 && c.length > 0 && p.every(x => set.has(x));
            if (ok) corrects[type] = (corrects[type] || 0) + 1;
            continue;
          }
          const ansIdx = Number.isFinite(Number(picked)) ? Math.floor(Number(picked)) : -1;
          const corrIdx = Number.isFinite(Number(answerKey[origIdx])) ? Math.floor(Number(answerKey[origIdx])) : -1;
          if (ansIdx >= 0 && ansIdx === corrIdx) corrects[type] = (corrects[type] || 0) + 1;
        }
        for (const t of Object.keys(counts)) {
          ensure(t);
          const w = getWeight(t, counts);
          total[t] = w;
          if (t === 'uraian') {
            let sum = 0;
            for (let origIdx = 0; origIdx < questions.length; origIdx++) {
              if (String(typesByIdx[origIdx]) !== 'uraian') continue;
              const g = Number(manualGrades?.[String(origIdx)]);
              if (Number.isFinite(g)) sum += g;
            }
            earned[t] = Math.min(w, Math.max(0, Math.round(sum)));
            continue;
          }
          if (t === 'isian_singkat') {
            const anyManual = Object.keys(manualGrades || {}).some(k => {
              const i = Number(k);
              return Number.isFinite(i) && String(typesByIdx[i]) === 'isian_singkat';
            });
            if (anyManual) {
              let sum = 0;
              for (let origIdx = 0; origIdx < questions.length; origIdx++) {
                if (String(typesByIdx[origIdx]) !== 'isian_singkat') continue;
                const g = Number(manualGrades?.[String(origIdx)]);
                if (Number.isFinite(g)) sum += g;
              }
              earned[t] = Math.min(w, Math.max(0, Math.round(sum)));
            } else {
              const cnt = Number(counts[t] || 0);
              const c = Number(corrects[t] || 0);
              earned[t] = (cnt > 0 && w > 0) ? Math.round((c / cnt) * w) : 0;
            }
            continue;
          }
          const cnt = Number(counts[t] || 0);
          const c = Number(corrects[t] || 0);
          earned[t] = (cnt > 0 && w > 0) ? Math.round((c / cnt) * w) : 0;
        }
        return { earned, total };
      }
      for (let origIdx = 0; origIdx < questions.length; origIdx++) {
        const q = questions[origIdx] || {};
        const type = normalizeQType(q);
        const pts = getPts(type);
        ensure(type);
        total[type] += pts;
        const pos = Number(orderData.posByOrig[origIdx] ?? -1);
        const picked = Number.isFinite(pos) && pos >= 0 ? studentAnswers[pos] : undefined;
        if (type === 'uraian') {
          const g = Number(manualGrades?.[String(origIdx)]);
          earned[type] += Number.isFinite(g) ? Math.min(pts, Math.max(0, g)) : 0;
          continue;
        }
        if (type === 'isian_singkat') {
          if (Object.prototype.hasOwnProperty.call(manualGrades || {}, String(origIdx))) {
            const g = Number(manualGrades?.[String(origIdx)]);
            earned[type] += Number.isFinite(g) ? Math.min(pts, Math.max(0, g)) : 0;
          } else {
            const ok = isShortCorrect(String(picked ?? ''), answerKey[origIdx]);
            earned[type] += ok ? pts : 0;
          }
          continue;
        }
        if (type === 'menjodohkan') {
          const key = Array.isArray(answerKey[origIdx]) ? answerKey[origIdx].map(n => Number(n)) : [];
          const ans = Array.isArray(picked) ? picked.map(n => Number(n)) : [];
          const n = Math.min(key.length, ans.length);
          let ok = n >= 2 && key.length === n;
          for (let i = 0; i < n; i++) {
            if (Number(key[i]) !== Number(ans[i])) { ok = false; break; }
          }
          earned[type] += ok ? pts : 0;
          continue;
        }
        if (type === 'pg_kompleks') {
          const corr = Array.isArray(answerKey[origIdx]) ? answerKey[origIdx] : [];
          const p = Array.isArray(picked) ? picked.map(n => Number(n)).filter(n => Number.isFinite(n) && n >= 0) : [];
          const c = Array.isArray(corr) ? corr.map(n => Number(n)).filter(n => Number.isFinite(n) && n >= 0) : [];
          const set = new Set(c);
          const ok = p.length > 0 && c.length > 0 && p.every(x => set.has(x));
          earned[type] += ok ? pts : 0;
          continue;
        }
        const ansIdx = Number.isFinite(Number(picked)) ? Math.floor(Number(picked)) : -1;
        const corrIdx = Number.isFinite(Number(answerKey[origIdx])) ? Math.floor(Number(answerKey[origIdx])) : -1;
        earned[type] += (ansIdx >= 0 && ansIdx === corrIdx) ? pts : 0;
      }
      return { earned, total };
    }
    function renderReviewBreakdown(questions, orderData) {
      if (!isReview) return '';
      const labels = { pg: 'Pilihan Ganda', benar_salah: 'Benar/Salah', pg_kompleks: 'PG Kompleks', menjodohkan: 'Menjodohkan', isian_singkat: 'Isian Singkat', uraian: 'Uraian' };
      const b = computeReviewBreakdown(questions, orderData);
      const types = orderData.typeOrder.filter(t => (b.total?.[t] ?? 0) > 0);
      if (!types.length) return '';
      const items = types.map(t => {
        const e = Number(b.earned?.[t] ?? 0);
        const tot = Number(b.total?.[t] ?? 0);
        const pct = tot > 0 ? Math.round((e / tot) * 100) : 0;
        const ok = pct >= 60;
        const c = ok ? 'text-green-600 border-green-500 bg-green-50' : 'text-red-600 border-red-500 bg-red-50';
        return `
          <div class="rounded-xl border-2 ${c} px-3 py-2">
            <div class="text-[11px] font-extrabold tracking-wide text-gray-700 truncate">${escapeHtml(labels[t] || t)}</div>
            <div class="flex items-end justify-between gap-2 mt-1">
              <div class="score-hand text-3xl leading-none">${pct}</div>
              <div class="text-[11px] font-extrabold text-gray-800">${e}/${tot}</div>
            </div>
          </div>
        `;
      }).join('');
      return `
        <div class="mb-4 rounded-xl border bg-gray-50 p-3">
          <div class="text-sm font-bold mb-2">Nilai per Bentuk Soal</div>
          <div class="grid grid-cols-2 md:grid-cols-3 gap-2">${items}</div>
        </div>
      `;
    }
    function renderTabs() {
      const container = document.getElementById('root');
      if (!isReview && (!absen || absen <= 0 || !studentName || String(studentName).trim() === '')) {
        container.innerHTML = `
          <div class="p-4 rounded-xl border bg-gray-50 text-sm text-gray-700">
            Silakan isi No Absen dan Nama untuk mulai mengerjakan.
          </div>
        `;
        return;
      }
      const hasSolutionTab = showSolution && submitted;
      const hasManual = isReview && Array.isArray(payload) && payload.some(q => {
        const rt = String(q?.type || '').trim();
        return rt === 'isian' || rt === 'isian_singkat' || rt === 'uraian';
      });
      const tabs = `
        <div class="flex items-center justify-between gap-2 border-b mb-4">
          <div class="flex items-center gap-2">
            <button class="px-3 py-2 text-sm font-semibold ${activeTab==='soal'?'text-blue-600 border-b-2 border-blue-600':''}" onclick="activeTab='soal'; renderTabs()">Soal</button>
            ${hasSolutionTab ? `<button class="px-3 py-2 text-sm font-semibold ${activeTab==='solusi'?'text-blue-600 border-b-2 border-blue-600':''}" onclick="activeTab='solusi'; renderTabs()">Jawaban & Pembahasan</button>` : ``}
          </div>
          ${hasManual ? `
            <div class="flex items-center gap-2">
              <div class="text-xs text-gray-600">${escapeHtml(manualSaveStatus || '')}</div>
              <button
                class="h-9 px-3 rounded-lg font-semibold ${manualDirty ? 'bg-green-600 hover:bg-green-700 text-white' : 'bg-green-100 text-green-700'}"
                onclick="saveManualGrades()"
                ${manualDirty ? '' : 'disabled'}
              >Simpan Nilai</button>
            </div>
          ` : ``}
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
      const orderData = buildOrderData(questions, absen);
      const availableTypes = orderData.typeOrder.filter(t => Array.isArray(orderData.groups[t]) && orderData.groups[t].length > 0);
      const activeTypes = availableTypes.length ? availableTypes : ['pg'];
      if (!activeTypes.includes(soalTypeTab)) soalTypeTab = activeTypes[0];
      const tabLabel = { pg: 'Pilihan Ganda', benar_salah: 'Benar/Salah', pg_kompleks: 'PG Kompleks', menjodohkan: 'Menjodohkan', isian_singkat: 'Isian Singkat', uraian: 'Uraian' };
      const tabSubtitle = { pg: 'Pilihlah salah satu jawaban yang paling tepat!', benar_salah: 'Pilihlah jawaban Benar atau Salah!', pg_kompleks: 'Pilih jawaban yang benar (bisa lebih dari satu)!', menjodohkan: 'Jodohkanlah pernyataan pada lajur kiri dengan jawaban pada lajur kanan!', isian_singkat: 'Jawablah dengan singkat dan tepat!', uraian: 'Tuliskan jawaban dengan jelas!' };
      const breakdown = isReview ? computeReviewBreakdown(questions, orderData) : null;
      const currentScoreBox = (() => {
        if (!isReview || !breakdown) return '';
        const e = Number(breakdown.earned?.[soalTypeTab] ?? 0);
        const tot = Number(breakdown.total?.[soalTypeTab] ?? 0);
        if (!(tot > 0)) return '';
        const pct = Math.max(0, Math.min(100, Math.round((e / tot) * 100)));
        const ok = pct >= 60;
        const c = ok ? 'text-green-600' : 'text-red-600';
        const points = Math.max(0, Math.round(e));
        return `
          <div class="flex items-center gap-2">
            <div class="score-hand text-4xl leading-none ${c}">${points}</div>
          </div>
        `;
      })();
      const getCorrectSet = (type, key) => {
        if (type === 'pg_kompleks') {
          const arr = Array.isArray(key) ? key : [];
          const out = new Set();
          for (const v of arr) {
            const n = Number(v);
            if (Number.isFinite(n) && n >= 0) out.add(Math.floor(n));
          }
          return out;
        }
        const n = Number(key);
        return Number.isFinite(n) && n >= 0 ? new Set([Math.floor(n)]) : new Set();
      };
      const tabs = `
        <div class="flex items-center justify-between gap-3 mb-4">
          <div class="flex flex-wrap items-center gap-2">
            ${activeTypes.map(t => `
              <button
                class="h-9 px-3 rounded-full border text-sm font-semibold ${soalTypeTab===t?'bg-blue-600 border-blue-600 text-white':'bg-white hover:bg-gray-50'}"
                onclick="soalTypeTab='${t}'; renderTabs();"
              >${escapeHtml(tabLabel[t] || t)}</button>
            `).join('')}
          </div>
          ${currentScoreBox}
        </div>
      `;
      const group = Array.isArray(orderData.groups[soalTypeTab]) ? orderData.groups[soalTypeTab] : [];
      const lastTab = activeTypes[activeTypes.length - 1];
      const prevTab = (() => {
        const i = activeTypes.indexOf(soalTypeTab);
        return i > 0 ? activeTypes[i - 1] : '';
      })();
      const nextTab = (() => {
        const i = activeTypes.indexOf(soalTypeTab);
        return i >= 0 && i < activeTypes.length - 1 ? activeTypes[i + 1] : '';
      })();
      const cards = group.map((origIdx, localIdx) => {
        const q = questions[origIdx] || {};
        const type = normalizeQType(q);
        if (type === 'menjodohkan') {
          const ctxRaw = String(q.context || '').replace(/\r\n/g, '\n').replace(/\r/g, '\n').trim();
          const ctxHtml = ctxRaw ? `<div class="mb-3 p-3 rounded-xl border bg-gray-50 text-sm leading-relaxed">${renderMathText(ctxRaw).replaceAll('\n','<br>')}</div>` : ``;
          const pos = Number(orderData.posByOrig[origIdx] ?? -1);
          const left = Array.isArray(q.options) ? q.options : [];
          const right = Array.isArray(q.right_options) ? q.right_options : (Array.isArray(q.rightOptions) ? q.rightOptions : (Array.isArray(q.answer) ? q.answer : []));
          const n = Math.min(left.length, right.length);
          const key = Array.isArray(answerKey[origIdx]) ? answerKey[origIdx] : [];
          const pickedRaw = studentAnswers[pos];
          const picked = Array.isArray(pickedRaw) ? pickedRaw : [];
          const letter = (idx) => (Number.isFinite(Number(idx)) && Number(idx) >= 0) ? String.fromCharCode(65 + Number(idx)) : '-';
          const rows = Array.from({ length: n }, (_, li) => {
            const cur = Number.isFinite(Number(picked[li])) ? Number(picked[li]) : -1;
            const corr = Number.isFinite(Number(key[li])) ? Number(key[li]) : -1;
            const showMark = isReview && cur >= 0;
            const ok = showMark ? (cur === corr) : false;
            return `
              <div class="flex items-start gap-3">
                <div class="w-7 text-sm font-bold pt-2">${li + 1}.</div>
                <div class="flex-1 min-w-0">
                  <div class="text-sm leading-relaxed">${renderMathText(String(left[li] || ''))}</div>
                  ${isReview && corr >= 0 ? `<div class="text-xs font-semibold text-gray-600 mt-1">(Kunci: ${escapeHtml(letter(corr))})</div>` : ``}
                </div>
                <div class="w-[12.5rem] flex items-center gap-2">
                  <select class="h-10 w-full rounded-lg border border-gray-300 px-3 text-sm bg-white" ${isReview ? 'disabled' : `onchange="onMatchChange(${pos},${li},this.value)"`}>
                    <option value="-1" ${cur < 0 ? 'selected' : ''}>Pilih...</option>
                    ${right.slice(0, n).map((t, ri) => `
                      <option value="${ri}" ${ri === cur ? 'selected' : ''}>${escapeHtml(letter(ri))}. ${escapeHtml(String(t || ''))}</option>
                    `).join('')}
                  </select>
                  ${showMark ? (ok
                    ? `<span class="text-green-600 font-extrabold text-3xl leading-none" aria-label="Benar">✓</span>`
                    : `<span class="text-red-600 font-extrabold text-3xl leading-none" aria-label="Salah">✕</span>`
                  ) : ``}
                </div>
              </div>
            `;
          }).join('');
          return `
            <div class="mb-6">
              <div class="flex gap-4">
                <span class="font-bold text-lg min-w-[1.5rem]">${localIdx + 1}.</span>
                <div class="flex-1">
                  ${ctxHtml}
                  <p class="mb-3 text-justify leading-relaxed">${renderMathText(String(q.question || ''))}</p>
                  ${q.image ? `<img src="${String(q.image)}" class="w-64 h-64 object-contain rounded-lg mb-3 border shadow-sm">` : ``}
                  <div class="space-y-3">${rows}</div>
                </div>
              </div>
            </div>
          `;
        }
        if (type === 'uraian') {
          const ctxRaw = String(q.context || '').replace(/\r\n/g, '\n').replace(/\r/g, '\n').trim();
          const ctxHtml = ctxRaw ? `<div class="mb-3 p-3 rounded-xl border bg-gray-50 text-sm leading-relaxed">${renderMathText(ctxRaw).replaceAll('\n','<br>')}</div>` : ``;
          const pos = Number(orderData.posByOrig[origIdx] ?? -1);
          const picked = studentAnswers[pos];
          const pickedText = typeof picked === 'string' ? picked : (picked === null || picked === undefined ? '' : String(picked));
          const pts = getPts('uraian');
          const curGrade = Number(manualGrades?.[String(origIdx)]);
          const gradeVal = Number.isFinite(curGrade) ? curGrade : 0;
          return `
            <div class="mb-6">
              <div class="flex gap-4">
                <span class="font-bold text-lg min-w-[1.5rem]">${localIdx + 1}.</span>
                <div class="flex-1">
                  ${ctxHtml}
                  <p class="mb-3 text-justify leading-relaxed">${renderMathText(String(q.question || ''))}</p>
                  ${q.image ? `<img src="${String(q.image)}" class="w-64 h-64 object-contain rounded-lg mb-3 border shadow-sm">` : ``}
                  ${isReview ? `
                    <div class="flex items-center gap-2 mb-2">
                      <div class="text-xs font-semibold text-gray-700">Nilai</div>
                      <input type="number" min="0" max="${pts}" value="${gradeVal}" class="w-20 h-9 rounded-md border border-gray-300 px-2 text-center text-sm" oninput="onManualGradeChange(${origIdx},${pts},this.value)">
                      <div class="text-xs text-gray-600">/ ${pts}</div>
                      <div class="text-xs font-semibold text-gray-600">Tidak dinilai otomatis.</div>
                    </div>
                  ` : ``}
                  <textarea
                    class="min-h-[140px] w-full rounded-lg border border-gray-300 px-4 py-3 text-sm"
                    placeholder="Tulis jawaban di sini..."
                    ${isReview ? 'disabled' : `oninput="onShortAnswerChange(${pos},this.value)"`}
                  >${escapeHtml(pickedText)}</textarea>
                </div>
              </div>
            </div>
          `;
        }
        if (type === 'isian_singkat') {
          const ctxRaw = String(q.context || '').replace(/\r\n/g, '\n').replace(/\r/g, '\n').trim();
          const ctxHtml = ctxRaw ? `<div class="mb-3 p-3 rounded-xl border bg-gray-50 text-sm leading-relaxed">${renderMathText(ctxRaw).replaceAll('\n','<br>')}</div>` : ``;
          const pos = Number(orderData.posByOrig[origIdx] ?? -1);
          const picked = studentAnswers[pos];
          const pickedText = typeof picked === 'string' ? picked : (picked === null || picked === undefined ? '' : String(picked));
          const keyRaw = answerKey[origIdx];
          const keyDisplay = Array.isArray(keyRaw) ? keyRaw.map(x => String(x ?? '').trim()).filter(Boolean).join(' / ') : String(keyRaw ?? '').trim();
          const showMark = isReview && String(pickedText || '').trim() !== '';
          const ok = showMark ? isShortCorrect(pickedText, keyRaw) : false;
          const pts = getPts('isian_singkat');
          const curGrade = Number(manualGrades?.[String(origIdx)]);
          const gradeVal = Number.isFinite(curGrade) ? curGrade : (ok ? pts : 0);
          return `
            <div class="mb-6">
              <div class="flex gap-4">
                <span class="font-bold text-lg min-w-[1.5rem]">${localIdx + 1}.</span>
                <div class="flex-1">
                  ${ctxHtml}
                  <p class="mb-3 text-justify leading-relaxed">${renderMathText(String(q.question || ''))}</p>
                  ${q.image ? `<img src="${String(q.image)}" class="w-64 h-64 object-contain rounded-lg mb-3 border shadow-sm">` : ``}
                  <div class="flex items-center gap-3">
                    <input
                      type="text"
                      class="h-11 w-full rounded-lg border border-gray-300 px-4 text-sm"
                      placeholder="Jawaban..."
                      value="${escapeHtml(pickedText)}"
                      ${isReview ? 'disabled' : `oninput="onShortAnswerChange(${pos},this.value)"`}
                    >
                    ${showMark ? (ok
                      ? `<span class="text-green-600 font-extrabold text-3xl leading-none" aria-label="Benar">✓</span>`
                      : `<span class="text-red-600 font-extrabold text-3xl leading-none" aria-label="Salah">✕</span>`
                    ) : ``}
                    ${isReview ? `
                      <div class="flex items-center gap-1">
                        <input type="number" min="0" max="${pts}" value="${gradeVal}" class="w-20 h-10 rounded-md border border-gray-300 px-2 text-center text-sm" oninput="onManualGradeChange(${origIdx},${pts},this.value)">
                        <div class="text-xs text-gray-600">/ ${pts}</div>
                      </div>
                    ` : ``}
                  </div>
                  ${isReview && keyDisplay ? `<div class="mt-2 text-xs font-semibold text-gray-600">(Kunci) ${escapeHtml(keyDisplay)}</div>` : ``}
                </div>
              </div>
            </div>
          `;
        }
        const isMulti = type === 'pg_kompleks';
        const inputKind = isMulti ? 'checkbox' : 'radio';
        const opts = type === 'benar_salah' ? ['Benar', 'Salah'] : (Array.isArray(q.options) ? q.options : []);
        const ctxRaw = String(q.context || '').replace(/\r\n/g, '\n').replace(/\r/g, '\n').trim();
        const ctxHtml = ctxRaw ? `<div class="mb-3 p-3 rounded-xl border bg-gray-50 text-sm leading-relaxed">${renderMathText(ctxRaw).replaceAll('\n','<br>')}</div>` : ``;
        const pos = Number(orderData.posByOrig[origIdx] ?? -1);
        const picked = studentAnswers[pos];
        const correct = getCorrectSet(type, answerKey[origIdx]);
        const isPicked = (oi) => isMulti ? (Array.isArray(picked) && picked.includes(oi)) : (Number(picked) === oi);
        const renderOptItem = (t, oi) => `
          <div class="flex gap-3 items-start">
            <label class="font-semibold pt-0.5">${String.fromCharCode(65 + oi)}.</label>
            <label class="flex-1 flex gap-3 p-2 rounded-lg transition ${isReview ? '' : 'hover:bg-gray-50'}">
              <input
                type="${inputKind}"
                name="q_${pos}"
                value="${oi}"
                class="mt-1 rounded border-gray-300"
                ${isReview ? 'disabled' : `onchange="onAnswerChange(${pos},${oi},this.checked,${isMulti?1:0})"`}
                ${isMulti ? (Array.isArray(picked) && picked.includes(oi) ? 'checked' : '') : (Number(picked) === oi ? 'checked' : '')}
              >
              <div class="flex-1 min-w-0">
                <span class="text-sm leading-relaxed">
                  ${renderMathText(String(t || ''))}
                  ${isReview && correct.has(oi) ? ` <span class="text-xs font-semibold text-gray-600">(Kunci)</span>` : ``}
                </span>
                ${isReview && isPicked(oi) ? (correct.has(oi)
                  ? `<span class="ml-2 text-green-600 font-extrabold text-3xl leading-none align-middle" aria-label="Benar">✓</span>`
                  : `<span class="ml-2 text-red-600 font-extrabold text-3xl leading-none align-middle" aria-label="Salah">✕</span>`
                ) : ``}
              </div>
            </label>
          </div>
        `;
        const optsHtml = (() => {
          if (type === 'benar_salah') {
            return `
              <div class="grid grid-cols-2 gap-x-12 gap-y-2 pl-1">
                ${opts.slice(0, 2).map((t, oi) => renderOptItem(t, oi)).join('')}
              </div>
            `;
          }
          const n = opts.length;
          const leftCount = Math.ceil(n / 2);
          const left = opts.slice(0, leftCount).map((t, oi) => renderOptItem(t, oi)).join('');
          const right = opts.slice(leftCount).map((t, oi) => renderOptItem(t, oi + leftCount)).join('');
          return `
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-12 gap-y-2 pl-1">
              <div class="space-y-2">${left}</div>
              <div class="space-y-2">${right}</div>
            </div>
          `;
        })();
        return `
          <div class="mb-6">
            <div class="flex gap-4">
              <span class="font-bold text-lg min-w-[1.5rem]">${localIdx + 1}.</span>
              <div class="flex-1">
                ${ctxHtml}
                <p class="mb-3 text-justify leading-relaxed">${renderMathText(String(q.question || ''))}</p>
                ${q.image ? `<img src="${String(q.image)}" class="w-64 h-64 object-contain rounded-lg mb-3 border shadow-sm">` : ``}
                ${optsHtml}
              </div>
            </div>
          </div>
        `;
      }).join('');
      const showSubmit = !isReview && soalTypeTab === lastTab;
      return `
        <div class="space-y-6">
          ${tabs}
          <div class="font-bold mb-1">${escapeHtml(tabLabel[soalTypeTab] || 'Soal')}</div>
          <div class="italic text-sm mb-4">${escapeHtml(tabSubtitle[soalTypeTab] || '')}</div>
          ${cards}
          <div class="pt-2 flex items-center gap-2">
            ${prevTab ? `<button class="h-11 px-5 rounded-lg border bg-white hover:bg-gray-50 font-semibold" onclick="soalTypeTab='${prevTab}'; renderTabs();">Sebelumnya</button>` : ``}
            ${nextTab ? `<button class="h-11 px-5 rounded-lg border bg-white hover:bg-gray-50 font-semibold" onclick="soalTypeTab='${nextTab}'; renderTabs();">Berikutnya</button>` : ``}
          </div>
          <div class="${showSubmit ? '' : 'hidden'} pt-2">
            <button id="btnSubmit" class="h-11 px-5 rounded-lg bg-blue-600 hover:bg-blue-700 text-white font-semibold">Submit</button>
            <div id="info" class="text-sm text-gray-600 mt-2"></div>
          </div>
        </div>
      `;
    }
    function renderSolusi() {
      const questions = Array.isArray(payload) ? payload : [];
      const rows = questions.map((q, i) => {
        let type = String(q.type || '').trim() || (Array.isArray(answerKey[i]) ? 'pg_kompleks' : 'pg');
        type = normalizeQType({ type });
        const opts = type === 'benar_salah' ? ['Benar', 'Salah'] : (Array.isArray(q.options) ? q.options : []);
        const key = answerKey[i];
        let kunciText = '-';
        let correctText = '';
        if (type === 'menjodohkan') {
          const right = Array.isArray(q.right_options) ? q.right_options : (Array.isArray(q.rightOptions) ? q.rightOptions : (Array.isArray(q.answer) ? q.answer : []));
          const n = Math.min(opts.length, right.length);
          const arr = Array.isArray(key) ? key : [];
          const letter = (idx) => (Number.isFinite(Number(idx)) && Number(idx) >= 0) ? String.fromCharCode(65 + Number(idx)) : '-';
          const parts = Array.from({ length: n }, (_, li) => {
            const ri = arr[li];
            const lt = String(opts[li] || '');
            const rt = Number.isFinite(Number(ri)) ? String(right[Number(ri)] || '') : '';
            return `${li + 1}→${letter(ri)}${rt ? ` (${rt})` : ''}${lt ? ` : ${lt}` : ''}`;
          });
          kunciText = parts.length ? parts.join(' ; ') : '-';
          correctText = '';
        } else if (type === 'isian_singkat') {
          const arr = Array.isArray(key) ? key.map(x => String(x ?? '').trim()).filter(Boolean) : (String(key ?? '').trim() ? [String(key ?? '').trim()] : []);
          kunciText = arr.length ? arr.join(' / ') : '-';
          correctText = '';
        } else if (type === 'pg_kompleks') {
          const arr = Array.isArray(key) ? key : [];
          const letters = arr.filter(n => Number.isFinite(Number(n))).map(n => String.fromCharCode(65 + Number(n)));
          kunciText = letters.length ? letters.join(', ') : '-';
          const texts = arr.map(n => opts[Number(n)]).filter(Boolean);
          correctText = texts.join(', ');
        } else if (type === 'benar_salah') {
          const idx = typeof key === 'number' ? key : -1;
          kunciText = idx === 1 ? 'Salah' : (idx === 0 ? 'Benar' : '-');
          correctText = idx >= 0 ? String(opts[idx] || '') : '';
        } else {
          const correct = typeof key === 'number' ? key : -1;
          correctText = (Array.isArray(opts) && opts[correct]) ? opts[correct] : '';
          kunciText = correct >= 0 ? String.fromCharCode(65 + correct) : '-';
        }
        const explain = String(q.explain || '');
        const ctxRaw = String(q.context || '').replace(/\r\n/g, '\n').replace(/\r/g, '\n').trim();
        const ctxHtml = ctxRaw ? `<div class="mt-2 mb-2 p-3 rounded-lg border bg-gray-50 text-sm leading-relaxed">${renderMathText(ctxRaw).replaceAll('\n','<br>')}</div>` : ``;
        return `
          <div class="mb-4 rounded-xl border p-4">
            <div class="font-semibold mb-1">${i+1}. ${renderMathText(String(q.question || ''))}</div>
            ${ctxHtml}
            <div class="text-sm"><span class="font-semibold">Kunci:</span> ${escapeHtml(kunciText)}${correctText ? ` — ${renderMathText(correctText)}` : ''}</div>
            ${explain ? `<div class="text-sm mt-2"><span class="font-semibold">Pembahasan:</span> ${renderMathText(explain)}</div>` : ``}
          </div>
        `;
      }).join('');
      return rows || `<div class="text-sm text-gray-600">Belum ada data pembahasan.</div>`;
    }
    function bindSubmit() {
      if (isReview) return;
      const btn = document.getElementById('btnSubmit');
      if (!btn) return;
      btn.addEventListener('click', async () => {
        if (!absen || absen <= 0) return;
        const questions = Array.isArray(payload) ? payload : [];
        const orderData = buildOrderData(questions, absen);
        const order = Array.isArray(orderData.orderAll) ? orderData.orderAll : questions.map((_, i) => i);
        const answers = [];
        for (let p = 0; p < order.length; p++) {
          const origIdx = order[p];
          const q = questions[origIdx] || {};
          const type = normalizeQType(q);
          if (type === 'isian_singkat') {
            const v = studentAnswers[p];
            answers.push(String(v ?? '').trim());
          } else if (type === 'uraian') {
            const v = studentAnswers[p];
            answers.push(String(v ?? '').trim());
          } else if (type === 'menjodohkan') {
            const left = Array.isArray(q.options) ? q.options : [];
            const right = Array.isArray(q.right_options) ? q.right_options : (Array.isArray(q.rightOptions) ? q.rightOptions : (Array.isArray(q.answer) ? q.answer : []));
            const n = Math.min(left.length, right.length);
            const cur = Array.isArray(studentAnswers[p]) ? studentAnswers[p] : [];
            const out = Array.from({ length: n }, (_, li) => {
              const x = cur[li];
              const ri = Number.isFinite(Number(x)) ? Math.floor(Number(x)) : -1;
              return ri;
            });
            answers.push(out);
          } else if (type === 'pg_kompleks') {
            const arr = Array.isArray(studentAnswers[p]) ? studentAnswers[p] : [];
            const out = arr.map(n => Number(n)).filter(n => Number.isFinite(n) && n >= 0).sort((a, b) => a - b);
            answers.push(Array.from(new Set(out)));
          } else {
            const v = studentAnswers[p];
            answers.push(Number.isFinite(Number(v)) ? Number(v) : -1);
          }
        }
        const info = document.getElementById('info');
        info.textContent = 'Mengirim...';
        try {
          const res = await fetch('api/published_quiz_submit.php', {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({ id: pubId, slug, absen, nama: studentName, answers, order_map: order })
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
    function setIdentity(a, n) {
      absen = Number(a || 0);
      studentName = String(n || '').trim();
      const nameEl = document.getElementById('studentName');
      const abEl = document.getElementById('studentAbsen');
      if (nameEl) nameEl.textContent = studentName || '-';
      if (abEl) abEl.textContent = absen > 0 ? String(absen) : '0';
      if (absen > 0) document.title = `${<?php echo json_encode($mapel ?: 'Soal', JSON_UNESCAPED_UNICODE); ?>} | No Absen ${absen}`;
    }
    function openIdentityModal() {
      const modal = document.getElementById('identityModal');
      if (!modal) return;
      modal.style.display = 'flex';
      const inpA = document.getElementById('inpAbsen');
      const inpN = document.getElementById('inpNama');
      if (inpA && absen > 0) inpA.value = String(absen);
      if (inpN && studentName) inpN.value = String(studentName);
      setTimeout(() => (inpA || inpN)?.focus?.(), 50);
    }
    function closeIdentityModal() {
      const modal = document.getElementById('identityModal');
      if (!modal) return;
      modal.style.display = 'none';
    }
    function loadIdentity() {
      try {
        const k = `gp_quiz_identity_${pubId}`;
        const raw = localStorage.getItem(k);
        if (!raw) return;
        const obj = JSON.parse(raw);
        if (!obj) return;
        const a = Number(obj.absen || 0);
        const n = String(obj.nama || '').trim();
        if (a > 0 && n) setIdentity(a, n);
      } catch {}
    }
    function saveIdentity() {
      try {
        const k = `gp_quiz_identity_${pubId}`;
        localStorage.setItem(k, JSON.stringify({ absen, nama: studentName }));
      } catch {}
    }
    function bindIdentityModal() {
      if (isReview) return;
      const btn = document.getElementById('btnStart');
      if (!btn) return;
      btn.addEventListener('click', () => {
        const err = document.getElementById('idErr');
        const inpA = document.getElementById('inpAbsen');
        const inpN = document.getElementById('inpNama');
        const a = Number(inpA?.value || 0);
        const n = String(inpN?.value || '').trim();
        if (!a || a < 1) { if (err) err.textContent = 'No Absen wajib diisi.'; return; }
        if (maxAbsen > 0 && a > maxAbsen) { if (err) err.textContent = `No Absen maksimal ${maxAbsen}.`; return; }
        if (!n) { if (err) err.textContent = 'Nama wajib diisi.'; return; }
        if (err) err.textContent = '';
        setIdentity(a, n);
        saveIdentity();
        closeIdentityModal();
        renderTabs();
      });
      const inpA = document.getElementById('inpAbsen');
      const inpN = document.getElementById('inpNama');
      const onEnter = (e) => { if (e.key === 'Enter') btn.click(); };
      if (inpA) inpA.addEventListener('keydown', onEnter);
      if (inpN) inpN.addEventListener('keydown', onEnter);
    }
    async function loadReviewAnswers() {
      const infoEl = document.getElementById('root');
      try {
        if (infoEl) infoEl.innerHTML = `<div class="text-sm text-gray-600">Memuat jawaban…</div>`;
        const res = await fetch('api/published_quiz_results.php?' + new URLSearchParams({ slug, mode: 'answers', absen: String(absen) }), { credentials: 'same-origin' });
        const js = await res.json().catch(() => null);
        if (!res.ok || !js || !js.ok) {
          const msg = String(js?.message || js?.error || `http_${res.status}`);
          if (infoEl) infoEl.innerHTML = `<div class="text-sm text-red-600">Gagal memuat jawaban: ${escapeHtml(msg)}</div>`;
          return;
        }
        const sc = Number(js?.student?.score || 0);
        const tt = Number(js?.student?.total || 0);
        reviewScore = sc;
        reviewTotal = tt;
        reviewPct = tt > 0 ? (sc / tt) * 100 : 0;
        renderReviewScoreBox();
        manualGrades = (js?.manual_grades && typeof js.manual_grades === 'object') ? js.manual_grades : {};
        manualDirty = false;
        manualSaveStatus = manualGrades && Object.keys(manualGrades).length ? 'Tersimpan' : '';
        const nm = String(js?.student?.nama || '').trim();
        if (!studentName && nm) studentName = nm;
        setIdentity(absen, studentName);
        const answers = Array.isArray(js?.answers) ? js.answers : [];
        const questions = Array.isArray(payload) ? payload : [];
        const orderData = buildOrderData(questions, absen);
        const toIdx = (type, val) => {
          if (val === null || val === undefined) return -1;
          if (typeof val === 'number' && Number.isFinite(val)) return Math.floor(val);
          const v = String(val || '').trim();
          if (!v) return -1;
          if (type === 'benar_salah') {
            if (/^benar$/i.test(v)) return 0;
            if (/^salah$/i.test(v)) return 1;
          }
          const m = v.toUpperCase().match(/^[A-E]$/);
          if (m) return m[0].charCodeAt(0) - 65;
          const n = Number(v);
          return Number.isFinite(n) ? Math.floor(n) : -1;
        };
        const toIdxList = (type, val) => {
          if (type !== 'pg_kompleks') {
            const idx = toIdx(type, val);
            return idx >= 0 ? [idx] : [];
          }
          if (Array.isArray(val)) return val.map(x => toIdx('pg', x)).filter(n => n >= 0);
          const v = String(val || '').trim();
          if (!v) return [];
          const parts = v.split(/[,\s;\/|]+/).map(x => x.trim()).filter(Boolean);
          return parts.map(x => toIdx('pg', x)).filter(n => n >= 0);
        };
        const toMatchList = (val) => {
          if (Array.isArray(val)) {
            return val.map(x => (Number.isFinite(Number(x)) ? Math.floor(Number(x)) : -1));
          }
          const v = String(val || '').trim();
          if (!v) return [];
          const parts = v.split(/[,\s;\/|]+/).map(x => x.trim()).filter(Boolean);
          return parts.map(x => {
            const m = x.toUpperCase().match(/^[A-Z]$/);
            if (m) return m[0].charCodeAt(0) - 65;
            const n = Number(x);
            return Number.isFinite(n) ? Math.floor(n) : -1;
          });
        };
        for (let origIdx = 0; origIdx < questions.length; origIdx++) {
          const q = questions[origIdx] || {};
          const type = normalizeQType(q);
          const pos = Number(orderData.posByOrig[origIdx] ?? -1);
          if (!Number.isFinite(pos) || pos < 0) continue;
          const raw = answers[origIdx];
          if (type === 'menjodohkan') {
            const left = Array.isArray(q.options) ? q.options : [];
            const right = Array.isArray(q.right_options) ? q.right_options : (Array.isArray(q.rightOptions) ? q.rightOptions : (Array.isArray(q.answer) ? q.answer : []));
            const n = Math.min(left.length, right.length);
            const arr = toMatchList(raw);
            const out = Array.from({ length: n }, (_, li) => (Number.isFinite(Number(arr[li])) ? Number(arr[li]) : -1));
            studentAnswers[pos] = out;
          } else if (type === 'uraian') {
            studentAnswers[pos] = String(raw ?? '');
          } else if (type === 'isian_singkat') {
            studentAnswers[pos] = String(raw ?? '');
          } else if (type === 'pg_kompleks') {
            const arr = Array.from(new Set(toIdxList(type, raw))).sort((a, b) => a - b);
            studentAnswers[pos] = arr;
          } else {
            studentAnswers[pos] = toIdx(type, raw);
          }
        }
        submitted = true;
        activeTab = 'soal';
        renderTabs();
      } catch {
        if (infoEl) infoEl.innerHTML = `<div class="text-sm text-red-600">Gagal memuat jawaban (network).</div>`;
      }
    }

    if (isReview) {
      setIdentity(absen, studentName);
      loadReviewAnswers();
    } else {
      loadIdentity();
      setIdentity(absen, studentName);
      bindIdentityModal();
      renderTabs();
      if (!absen || absen <= 0 || !studentName || String(studentName).trim() === '') openIdentityModal();
    }
  </script>
</body>
</html>
