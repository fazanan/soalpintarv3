<?php
declare(strict_types=1);
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Naskah Soal - Print</title>
  <style>
    @page {
      size: A4;
      margin: 2.5cm 2cm 2cm 2.5cm;
    }
    @page :first {
      margin-top: 2cm;
    }
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
    .isian-line {
      margin: 4pt 0 0 24pt;
      border-bottom: 1pt dotted #000;
      height: 1.5em;
    }
    .uraian-lines {
      margin: 8pt 0 12pt 24pt;
    }
    .uraian-lines .line {
      border-bottom: 1pt dotted #000;
      height: 1.6em;
      margin-bottom: 2pt;
    }
    .page-break {
      page-break-before: always;
    }
    .avoid-break {
      page-break-inside: avoid;
    }
    .footer-text {
      text-align: center;
      font-style: italic;
      font-size: 10pt;
      margin-top: 24pt;
    }
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
    function safeText(s) {
      return String(s == null ? '' : s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
    }
    function nl2br(s) {
      return safeText(s).replace(/\n/g, '<br>');
    }
    function renderDoc(data) {
      const { identity, paket, questions } = data;
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
      html += `
        <div class="school-header">
          ${identity.logo ? `<img src="${safeText(identity.logo)}" class="logo" alt="Logo" />` : ''}
          <div class="nama-sekolah">${safeText(identity.namaSekolah || 'NAMA SEKOLAH')}</div>
          <div class="judul-naskah">${safeText(paket.judul || 'NASKAH SOAL')}</div>
          <div class="tahun-pelajaran">Tahun Pelajaran ${safeText(paket.tahunAjaran || '-')}</div>
        </div>
        <hr class="divider" />
      `;
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
          if (q.image) {
            html += `<img src="${safeText(q.image)}" class="soal-image" alt="Gambar soal ${num}" />`;
          }
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
      html += '<div class="footer-text">— Selamat Mengerjakan —</div>';
      document.getElementById('docContent').innerHTML = html;
    }
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
        document.title = `${data.paket?.judul || 'Naskah Soal'} - ${data.identity?.mataPelajaran || ''}`;
        setTimeout(() => {
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
          setTimeout(() => {
            if (loadedCount < totalImages) {
              window.print();
            }
          }, 3000);
        }, 300);
      } catch (err) {
        document.getElementById('docContent').innerHTML = '<div class="empty-state"><h2>Gagal memuat data</h2><p>Terjadi kesalahan. Silakan coba lagi dari halaman GuruPintar.</p></div>';
      }
    })();
  </script>
</body>
</html>
