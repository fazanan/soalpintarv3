# 🖨️ Prompt Trae: Print-to-PDF dari HTML (Opsi B)

> **Konteks:** PDF generator existing (jsPDF) menghasilkan layout kurang rapi karena keterbatasan font (Times built-in), line spacing rapat, dan tidak ada justify. Solusinya: render naskah soal di halaman HTML khusus dengan CSS print-optimized, lalu user gunakan browser native print → Save as PDF.
>
> **Tujuan:** PDF hasil akhirnya **se-rapi DOCX** (bahkan lebih, karena browser engine handle typography modern).
>
> **File yang diubah:** `index.php` (tambah fungsi baru) + buat file baru `soal_print.php`.

---

## CATATAN PENTING UNTUK TRAE

**Strategi:**
1. Buat halaman baru `soal_print.php` yang render naskah soal dengan layout print-optimized
2. Halaman terima parameter via URL atau localStorage transfer
3. Auto-trigger `window.print()` saat halaman load
4. CSS pakai `@page` rules + print media query untuk hasil terbaik
5. Tombol "Download PDF" existing TIDAK diganti, dibuat opsi tambahan "Print PDF (HTML)"

**JANGAN ubah:**
- Function `exportSoalPDF()` existing (line 1417) - biarkan sebagai opsi fallback
- Function `exportDocx()` existing - tidak disentuh
- Logic generate soal apa pun

**Yang diubah/ditambah:**
- Tambah tombol baru "Print PDF" di samping "Download PDF" existing
- Tambah function `openSoalPrintWindow()` di JavaScript
- Buat file `soal_print.php` baru dengan layout print-friendly

---

## TUGAS LENGKAP

### TUGAS A: Buat File Baru `soal_print.php`

Buat file `/soal_print.php` di root project dengan isi berikut:

```php
<?php
/**
 * Halaman print-friendly untuk naskah soal.
 * Data diambil dari localStorage browser (transfer dari halaman utama).
 * Auto-trigger window.print() saat load, user save as PDF dari dialog browser.
 */
declare(strict_types=1);
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Naskah Soal - Print</title>
  
  <style>
    /* Print-optimized stylesheet */
    
    /* Page setup - A4 dengan margin sekolah Indonesia */
    @page {
      size: A4;
      margin: 2.5cm 2cm 2cm 2.5cm; /* atas | kanan | bawah | kiri */
    }
    
    @page :first {
      margin-top: 2cm;
    }
    
    /* Reset & base font */
    *, *::before, *::after {
      box-sizing: border-box;
    }
    
    html, body {
      margin: 0;
      padding: 0;
      background: white;
      color: #000;
      font-family: 'Times New Roman', Times, serif;
      font-size: 12pt;
      line-height: 1.5;
      -webkit-print-color-adjust: exact;
      print-color-adjust: exact;
    }
    
    /* Container */
    .doc {
      max-width: 21cm;
      margin: 0 auto;
      padding: 1cm;
      background: white;
    }
    
    @media print {
      .doc {
        max-width: 100%;
        padding: 0;
        margin: 0;
      }
      
      .no-print {
        display: none !important;
      }
    }
    
    /* Header sekolah */
    .school-header {
      text-align: center;
      margin-bottom: 1em;
      position: relative;
    }
    
    .school-header .nama-sekolah {
      font-size: 16pt;
      font-weight: bold;
      text-transform: uppercase;
      margin: 0 0 4px 0;
      letter-spacing: 0.5pt;
    }
    
    .school-header .judul-naskah {
      font-size: 14pt;
      font-weight: bold;
      text-transform: uppercase;
      margin: 0 0 4px 0;
    }
    
    .school-header .tahun-pelajaran {
      font-size: 11pt;
      margin: 0;
    }
    
    .school-header .logo {
      position: absolute;
      top: 0;
      right: 0;
      max-width: 80px;
      max-height: 60px;
    }
    
    .divider {
      border: none;
      border-top: 2.5pt double #000;
      margin: 12pt 0 16pt 0;
    }
    
    /* Identity table */
    .identity-table {
      width: 100%;
      margin-bottom: 16pt;
      font-size: 11pt;
    }
    
    .identity-table td {
      padding: 2pt 4pt;
      vertical-align: top;
    }
    
    .identity-table .label {
      font-weight: bold;
      width: 28%;
    }
    
    .identity-table .colon {
      width: 2%;
      text-align: center;
    }
    
    .identity-table .value {
      width: 20%;
    }
    
    /* Section titles */
    .section-title {
      font-size: 12pt;
      font-weight: bold;
      text-transform: uppercase;
      margin: 16pt 0 4pt 0;
      page-break-after: avoid;
    }
    
    .section-subtitle {
      font-size: 11pt;
      font-style: italic;
      margin: 0 0 12pt 0;
      page-break-after: avoid;
    }
    
    /* Question (soal) */
    .soal {
      margin-bottom: 10pt;
      page-break-inside: avoid;
      text-align: justify;
    }
    
    .soal-number {
      font-weight: bold;
      display: inline;
      margin-right: 6pt;
    }
    
    .soal-text {
      display: inline;
    }
    
    .soal-image {
      display: block;
      max-width: 80%;
      max-height: 8cm;
      margin: 8pt auto;
      object-fit: contain;
    }
    
    /* Pilihan ganda options */
    .options {
      margin: 6pt 0 0 24pt;
      padding: 0;
      list-style: none;
    }
    
    .options li {
      margin: 2pt 0;
      padding-left: 24pt;
      text-indent: -24pt;
      text-align: justify;
    }
    
    .option-letter {
      display: inline-block;
      width: 18pt;
      font-weight: normal;
    }
    
    /* Menjodohkan table */
    .menjodohkan {
      width: 100%;
      border-collapse: collapse;
      margin: 8pt 0 12pt 24pt;
      width: calc(100% - 24pt);
    }
    
    .menjodohkan td {
      border: 1pt solid #000;
      padding: 6pt 8pt;
      vertical-align: top;
      width: 50%;
    }
    
    .menjodohkan .header-cell {
      font-weight: bold;
      text-align: center;
      background: #f0f0f0;
    }
    
    /* Isian singkat - garis titik-titik */
    .isian-line {
      margin: 4pt 0 0 24pt;
      border-bottom: 1pt dotted #000;
      height: 1.5em;
    }
    
    /* Uraian - 4 baris kosong */
    .uraian-lines {
      margin: 8pt 0 12pt 24pt;
    }
    
    .uraian-lines .line {
      border-bottom: 1pt dotted #000;
      height: 1.6em;
      margin-bottom: 2pt;
    }
    
    /* Page break utilities */
    .page-break {
      page-break-before: always;
    }
    
    .avoid-break {
      page-break-inside: avoid;
    }
    
    /* Footer */
    .footer-text {
      text-align: center;
      font-style: italic;
      font-size: 10pt;
      margin-top: 24pt;
    }
    
    /* Toolbar (only visible on screen, not print) */
    .toolbar {
      position: fixed;
      top: 0;
      left: 0;
      right: 0;
      background: #fff;
      border-bottom: 1pt solid #ddd;
      padding: 10px 20px;
      box-shadow: 0 2px 4px rgba(0,0,0,0.1);
      z-index: 100;
      display: flex;
      align-items: center;
      gap: 12px;
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    }
    
    .toolbar h1 {
      font-size: 15px;
      margin: 0;
      flex: 1;
      color: #333;
    }
    
    .toolbar button {
      padding: 8px 16px;
      font-size: 14px;
      font-weight: 600;
      border: none;
      border-radius: 6px;
      cursor: pointer;
      background: #2563eb;
      color: white;
    }
    
    .toolbar button:hover {
      background: #1d4ed8;
    }
    
    .toolbar button.secondary {
      background: #e5e7eb;
      color: #333;
    }
    
    .toolbar button.secondary:hover {
      background: #d1d5db;
    }
    
    body {
      padding-top: 70px;
    }
    
    @media print {
      .toolbar {
        display: none !important;
      }
      
      body {
        padding-top: 0;
      }
    }
    
    /* Empty state */
    .empty-state {
      text-align: center;
      padding: 60px 20px;
      color: #666;
    }
    
    .empty-state h2 {
      color: #333;
      margin-bottom: 8px;
    }
  </style>
</head>
<body>
  
  <!-- Toolbar (hidden saat print) -->
  <div class="toolbar no-print">
    <h1>📄 Naskah Soal - Siap Print</h1>
    <button onclick="window.print()">🖨️ Print / Save as PDF</button>
    <button class="secondary" onclick="window.close()">Tutup</button>
  </div>
  
  <div class="doc" id="docContent">
    <div class="empty-state">
      <h2>Memuat naskah soal...</h2>
      <p>Mohon tunggu sebentar.</p>
    </div>
  </div>
  
  <script>
    // Helper: safe text untuk HTML
    function safeText(s) {
      return String(s == null ? '' : s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
    }
    
    // Helper: nl2br
    function nl2br(s) {
      return safeText(s).replace(/\n/g, '<br>');
    }
    
    // Render dokumen
    function renderDoc(data) {
      const { identity, paket, questions } = data;
      
      // Group questions by type
      const typeOrder = ['pg', 'benar_salah', 'pg_kompleks', 'menjodohkan', 'isian', 'uraian'];
      const titleMap = {
        pg: 'PILIHAN GANDA',
        benar_salah: 'BENAR / SALAH',
        pg_kompleks: 'PILIHAN GANDA KOMPLEKS',
        menjodohkan: 'MENJODOHKAN',
        isian: 'ISIAN SINGKAT',
        uraian: 'URAIAN'
      };
      const subtitleMap = {
        pg: 'Pilihlah salah satu jawaban yang paling tepat!',
        benar_salah: 'Pilihlah jawaban Benar atau Salah!',
        pg_kompleks: 'Pilihlah jawaban yang benar (bisa lebih dari satu)!',
        menjodohkan: 'Jodohkanlah pernyataan pada lajur kiri dengan jawaban pada lajur kanan!',
        isian: 'Jawablah pertanyaan berikut dengan singkat dan tepat!',
        uraian: 'Jawablah pertanyaan-pertanyaan berikut dengan jelas dan benar!'
      };
      
      const groupedQuestions = {};
      typeOrder.forEach(t => { groupedQuestions[t] = []; });
      (questions || []).forEach(q => {
        if (q && q.type && groupedQuestions[q.type]) {
          groupedQuestions[q.type].push(q);
        }
      });
      
      let html = '';
      
      // Header sekolah
      html += `
        <div class="school-header">
          ${identity.logo ? `<img src="${safeText(identity.logo)}" class="logo" alt="Logo" />` : ''}
          <div class="nama-sekolah">${safeText(identity.namaSekolah || 'NAMA SEKOLAH')}</div>
          <div class="judul-naskah">${safeText(paket.judul || 'NASKAH SOAL')}</div>
          <div class="tahun-pelajaran">Tahun Pelajaran ${safeText(paket.tahunAjaran || '-')}</div>
        </div>
        <hr class="divider" />
      `;
      
      // Identity table
      html += `
        <table class="identity-table">
          <tr>
            <td class="label">Mata Pelajaran</td>
            <td class="colon">:</td>
            <td class="value">${safeText(identity.mataPelajaran || '-')}</td>
            <td class="label">Hari / Tanggal</td>
            <td class="colon">:</td>
            <td class="value">______________________</td>
          </tr>
          <tr>
            <td class="label">Kelas / Fase</td>
            <td class="colon">:</td>
            <td class="value">${safeText(identity.kelas || '-')} / ${safeText(identity.fase || '-')}</td>
            <td class="label">Waktu</td>
            <td class="colon">:</td>
            <td class="value">______________________</td>
          </tr>
          <tr>
            <td class="label">Kurikulum</td>
            <td class="colon">:</td>
            <td class="value">${safeText(identity.kurikulum || '-')}</td>
            <td class="label">Nama</td>
            <td class="colon">:</td>
            <td class="value">______________________</td>
          </tr>
        </table>
      `;
      
      // Render sections
      let firstSection = true;
      typeOrder.forEach(type => {
        const qs = groupedQuestions[type];
        if (!qs.length) return;
        
        if (!firstSection) {
          html += '<div class="page-break"></div>';
        }
        firstSection = false;
        
        html += `<div class="section-title">${titleMap[type]}</div>`;
        html += `<div class="section-subtitle">${subtitleMap[type]}</div>`;
        
        qs.forEach((q, idx) => {
          const num = idx + 1;
          html += `<div class="soal avoid-break">`;
          html += `<span class="soal-number">${num}.</span>`;
          html += `<span class="soal-text">${nl2br(q.question || '')}</span>`;
          
          // Image jika ada
          if (q.image) {
            html += `<img src="${safeText(q.image)}" class="soal-image" alt="Gambar soal ${num}" />`;
          }
          
          // Render options berdasarkan type
          if (type === 'pg' || type === 'benar_salah' || type === 'pg_kompleks') {
            const opts = Array.isArray(q.options) ? q.options : [];
            if (opts.length) {
              html += '<ul class="options">';
              opts.forEach((opt, oi) => {
                const letter = String.fromCharCode(65 + oi);
                html += `<li><span class="option-letter">${letter}.</span>${nl2br(opt || '')}</li>`;
              });
              html += '</ul>';
            }
          } else if (type === 'menjodohkan') {
            const leftList = Array.isArray(q.options) ? q.options : [];
            const rightList = Array.isArray(q.answer) ? q.answer : [];
            html += '<table class="menjodohkan">';
            html += '<tr><td class="header-cell">Lajur A (Pernyataan)</td><td class="header-cell">Lajur B (Jawaban)</td></tr>';
            const maxRows = Math.max(leftList.length, rightList.length);
            for (let i = 0; i < maxRows; i++) {
              html += '<tr>';
              html += `<td>${i < leftList.length ? `${i+1}. ${nl2br(leftList[i] || '')}` : ''}</td>`;
              html += `<td>${i < rightList.length ? `${String.fromCharCode(65+i)}. ${nl2br(rightList[i] || '')}` : ''}</td>`;
              html += '</tr>';
            }
            html += '</table>';
          } else if (type === 'isian') {
            html += '<div class="isian-line"></div>';
          } else if (type === 'uraian') {
            html += '<div class="uraian-lines">';
            for (let i = 0; i < 4; i++) {
              html += '<div class="line"></div>';
            }
            html += '</div>';
          }
          
          html += '</div>';
        });
      });
      
      // Footer
      html += '<div class="footer-text">— Selamat Mengerjakan —</div>';
      
      document.getElementById('docContent').innerHTML = html;
    }
    
    // Main: load data dari localStorage & render
    (function init() {
      try {
        const raw = localStorage.getItem('soalpintar:print_data');
        if (!raw) {
          document.getElementById('docContent').innerHTML = '<div class="empty-state"><h2>Data tidak ditemukan</h2><p>Mohon buka halaman ini dari tombol "Print PDF" di GuruPintar.</p></div>';
          return;
        }
        
        const data = JSON.parse(raw);
        if (!data || !Array.isArray(data.questions) || data.questions.length === 0) {
          document.getElementById('docContent').innerHTML = '<div class="empty-state"><h2>Tidak ada soal</h2><p>Belum ada soal yang dibuat.</p></div>';
          return;
        }
        
        renderDoc(data);
        
        // Update document title
        document.title = `${data.paket?.judul || 'Naskah Soal'} - ${data.identity?.mataPelajaran || ''}`;
        
        // Auto-trigger print dialog setelah render selesai (delay untuk pastikan image loaded)
        setTimeout(() => {
          // Tunggu image loading
          const images = document.querySelectorAll('img');
          let loadedCount = 0;
          const totalImages = images.length;
          
          if (totalImages === 0) {
            window.print();
            return;
          }
          
          const onImageReady = () => {
            loadedCount++;
            if (loadedCount >= totalImages) {
              setTimeout(() => window.print(), 200);
            }
          };
          
          images.forEach(img => {
            if (img.complete) {
              onImageReady();
            } else {
              img.addEventListener('load', onImageReady);
              img.addEventListener('error', onImageReady);
            }
          });
          
          // Fallback: max 3 detik tunggu
          setTimeout(() => {
            if (loadedCount < totalImages) {
              window.print();
            }
          }, 3000);
        }, 300);
        
      } catch (err) {
        console.error('Print init error:', err);
        document.getElementById('docContent').innerHTML = '<div class="empty-state"><h2>Gagal memuat data</h2><p>Terjadi kesalahan. Silakan coba lagi dari halaman GuruPintar.</p></div>';
      }
    })();
  </script>
</body>
</html>
```

### TUGAS B: Tambah Function `openSoalPrintWindow()` di index.php

Cari function `exportSoalPDF` di sekitar line 1417. SETELAH function tersebut (sebelum `function ensureJsPDF()` atau function berikutnya), TAMBAHKAN:

```javascript
// Print-to-PDF via HTML (alternatif untuk exportSoalPDF)
function openSoalPrintWindow() {
  if (!Array.isArray(state.questions) || state.questions.length === 0) {
    alert('Belum ada soal yang dibuat.');
    return;
  }
  
  try {
    // Build data untuk transfer
    const printData = {
      identity: {
        namaSekolah: state.identity?.namaSekolah || '',
        mataPelajaran: state.identity?.mataPelajaran || '',
        kelas: state.identity?.kelas || '',
        fase: state.identity?.fase || '',
        kurikulum: state.identity?.kurikulum || '',
        logo: state.identity?.logo || ''
      },
      paket: {
        judul: state.paket?.judul || '',
        tahunAjaran: state.paket?.tahunAjaran || ''
      },
      questions: (state.questions || []).map(q => ({
        type: q?.type || 'pg',
        question: q?.question || '',
        options: Array.isArray(q?.options) ? q.options : [],
        answer: q?.answer ?? '',
        image: q?.image || ''
      }))
    };
    
    // Simpan ke localStorage (karena URL terlalu panjang untuk semua data)
    localStorage.setItem('soalpintar:print_data', JSON.stringify(printData));
    
    // Buka di tab baru
    const printWindow = window.open('/soal_print.php', '_blank');
    if (!printWindow) {
      alert('Browser memblokir pop-up. Mohon izinkan pop-up untuk situs ini, lalu coba lagi.');
      return;
    }
    
  } catch (err) {
    console.error('Print window error:', err);
    alert('Gagal membuka jendela print. Silakan coba lagi.');
  }
}
```

### TUGAS C: Update window.__sp Export

Cari `window.__sp` di sekitar line 13898-13920. Tambahkan:

```javascript
window.__sp.openSoalPrintWindow = openSoalPrintWindow;
```

### TUGAS D: Tambah Tombol "Print PDF" di UI

Cari area tombol Download di sekitar line 3793-3803. CARI block ini:

```html
<button id="btnSoalPdfTop" class="${canDownload ? 'inline-flex bg-green-600 hover:bg-green-700 text-white border border-green-600' : 'inline-flex bg-gray-200 text-gray-500 border border-gray-300 opacity-60 cursor-not-allowed'} items-center gap-2 h-9 px-4 rounded-lg text-sm font-bold"
  ${canDownload ? `onclick="window.__sp.downloadSoalPDF()"` : 'disabled'}>
  <span class="material-symbols-outlined text-[18px]">picture_as_pdf</span>
  Download PDF
</button>
```

SETELAH block tersebut (sebelum `<label>` untuk checkbox "Download File Terpisah"), TAMBAHKAN:

```html
<button id="btnSoalPrintTop" class="${canDownload ? 'inline-flex bg-white dark:bg-surface-dark border border-green-600 text-green-700 hover:bg-green-50 dark:hover:bg-green-900/20' : 'inline-flex bg-gray-200 text-gray-500 border border-gray-300 opacity-60 cursor-not-allowed'} items-center gap-2 h-9 px-4 rounded-lg text-sm font-bold"
  ${canDownload ? `onclick="window.__sp.openSoalPrintWindow()"` : 'disabled'}
  title="Buka di tab baru untuk print menggunakan browser (hasil PDF lebih rapi)">
  <span class="material-symbols-outlined text-[18px]">print</span>
  Print PDF
  <span class="text-[10px] px-1.5 py-0.5 rounded bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-300 ml-1">Lebih Rapi</span>
</button>
```

### TUGAS E (Optional): Cari Tombol Download PDF di Tempat Lain

Cari semua occurrence "Download PDF" di kode untuk pastikan ada tombol Print PDF juga di tempat-tempat yang relevan:

```bash
grep -n "Download PDF" index.php
```

Untuk SETIAP tombol "Download PDF" yang ditemukan di context Buat Soal, tambahkan tombol "Print PDF" di sampingnya dengan pattern yang sama.

(Tetap pertahankan tombol "Download PDF" lama sebagai opsi backup.)

---

## YANG TIDAK BOLEH DIUBAH

1. **JANGAN ubah** function `exportSoalPDF()` (line 1417) - tetap sebagai opsi fallback
2. **JANGAN ubah** function `exportDocx()` - DOCX generator tetap
3. **JANGAN hapus** tombol "Download PDF" existing
4. **JANGAN ubah** logic generate soal apa pun

---

## CARA VERIFIKASI

### Test 1: Tombol Print PDF Muncul
1. Buka halaman **Buat Soal** dengan paket soal yang sudah ada
2. **Expected:** Di area tombol unduh, ada 3 tombol:
   - **Download .docx** (biru)
   - **Download PDF** (hijau, existing) 
   - **Print PDF** dengan badge "Lebih Rapi" (outline hijau, BARU)
3. Hover tombol Print PDF → tooltip "Buka di tab baru untuk print..."

### Test 2: Klik Print PDF Buka Tab Baru
1. Klik **"Print PDF"**
2. **Expected:**
   - Tab baru terbuka ke `/soal_print.php`
   - Halaman menampilkan toolbar atas dengan judul + tombol "🖨️ Print / Save as PDF" + "Tutup"
   - Naskah soal ter-render dengan layout print-friendly
   - Dialog print browser muncul otomatis setelah 1-2 detik

### Test 3: Hasil Print PDF Rapi
1. Di dialog print, ubah Destination ke "Save as PDF"
2. Klik Save
3. **Expected:** PDF tersimpan dengan:
   - Font Times New Roman yang rapi
   - Header sekolah centered
   - Identity table dengan label & value sejajar
   - Soal pilihan ganda: nomor + pertanyaan + opsi A/B/C/D rapi rata kiri
   - Soal menjodohkan: tabel 2 kolom dengan border
   - Isian: garis dotted untuk tulis jawaban
   - Uraian: 4 baris kosong untuk jawaban

### Test 4: Hasil Lebih Rapi dari jsPDF
Buka **Download PDF** (existing) lalu buka **Print PDF** (baru). Bandingkan:
1. **Line spacing:** Print PDF lebih lega (1.5 vs 1.2)
2. **Text alignment:** Print PDF justify (rata kanan-kiri), jsPDF rata kiri
3. **Tipografi:** Print PDF terlihat lebih halus (browser engine)
4. **Page break:** Print PDF tidak motong soal di tengah

### Test 5: Soal dengan Gambar
1. Buat paket soal yang ada gambarnya
2. Klik Print PDF
3. **Expected:** Gambar ter-load sebelum print dialog muncul. PDF berisi gambar.

### Test 6: Section Page Break
1. Buat paket dengan 2+ section (mis. PG + Uraian)
2. Klik Print PDF
3. **Expected:** Tiap section mulai di halaman baru (page-break-before: always)

### Test 7: No Toolbar di PDF
1. Save as PDF dari dialog print
2. Buka PDF hasilnya
3. **Expected:** Toolbar atas (judul + tombol) TIDAK muncul di PDF (karena `.no-print` di-hide saat print)

### Test 8: Empty State
1. Manual akses `/soal_print.php` di browser (tanpa lewat tombol)
2. **Expected:** Tampil pesan "Data tidak ditemukan" + instruksi buka dari GuruPintar

### Test 9: Multi-tab Aman
1. Buka 2 paket berbeda di 2 tab
2. Tab A: klik Print PDF
3. Tab B: klik Print PDF
4. **Expected:** Tab B menampilkan data Tab B (overwrite localStorage saat klik)

### Test 10: Mobile Responsiveness
1. Buka tombol Print PDF di mobile
2. **Expected:** 
   - Tab baru terbuka
   - Layout responsive (max-width 21cm tetap, scroll horizontal di mobile)
   - User bisa "Share to Print" atau "Save as PDF" via mobile browser

---

## FILE YANG DIEDIT/DIBUAT

1. **CREATE NEW:** `/soal_print.php`
2. **EDIT:** `index.php` — tambah function `openSoalPrintWindow()` + tombol di UI + export ke window.__sp

---

## OUTPUT YANG DIHARAPKAN

1. File baru `soal_print.php` ter-create di root
2. Tombol "Print PDF" (dengan badge "Lebih Rapi") muncul di samping Download PDF
3. Klik tombol → buka tab baru → render naskah → dialog print otomatis
4. Save as PDF dari browser → PDF jauh lebih rapi dari jsPDF version
5. Test 10 scenario PASS
6. Tidak ada error console di kedua halaman (index.php dan soal_print.php)

---

## BONUS: Fitur Tambahan Setelah Verifikasi

Setelah Opsi B basic berfungsi, bisa ditambah:

1. **Print Kunci Jawaban** — tombol "Print Kunci PDF" dengan render khusus
2. **Print Kisi-Kisi** — tombol "Print Kisi-Kisi PDF"
3. **Header logo positioning** — jika ada logo sekolah, tampil di kanan atas
4. **Watermark draft** — untuk versi sebelum final
5. **Print preview** — tombol "Preview saja" tanpa auto-trigger print

Tapi ini di-implement setelah versi basic dipastikan jalan.
