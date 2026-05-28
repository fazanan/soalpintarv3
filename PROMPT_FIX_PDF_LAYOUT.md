# 🎯 Prompt Trae: Fix Layout PDF Naskah Soal (Konsisten dengan DOCX)

> **Konteks:** Hasil PDF dari "Download PDF" tidak rapi dibanding DOCX. Akar masalah BUKAN font/spacing, tapi PDF tidak pakai tabel untuk:
> 1. Header identity (Mata Pelajaran, Kelas, Hari/Tanggal, dll.) — sekarang manual position x,y → layout rusak saat text panjang
> 2. Opsi Pilihan Ganda A/B/C/D — sekarang text inline tanpa tabel grid, tidak align
>
> **Bonus issue:**
> - Section title "PILIHAN GANDA" tanpa prefix "A." (DOCX punya)
> - "Topik / Lingkup Materi" muncul di Naskah Soal (DOCX tidak)
> - Tidak ada underscore "____________" untuk Waktu/Nama/No. Absen (DOCX punya)
>
> **Tujuan:** PDF layout konsisten dengan DOCX, tanpa migrate ke Print-to-PDF.
>
> **File yang diubah:** `index.php` saja, function `exportSoalPDF` (line 1417-an).

---

## CATATAN PENTING UNTUK TRAE

Yang diubah HANYA bagian rendering header & opsi PG di `exportSoalPDF`. JANGAN:
- Ubah logic generate soal
- Ubah function `exportDocx`
- Hapus function `drawHeader` lama (refactor in-place)
- Tambah library baru — pakai jsPDF + autoTable yang sudah ada

Untuk identity table & options grid, pakai `doc.autoTable()` plugin yang sudah ter-import (terbukti di code untuk render soal menjodohkan).

---

## TUGAS LENGKAP

### TUGAS A: Fix Header Identity — Pakai autoTable (line ~1480-1554)

Cari function `drawHeader = (title, kind) => {...}` di sekitar line 1480 di dalam `makeDoc`.

**MASALAH SAAT INI:**
- Pakai manual `drawCol(baseX, row)` dengan koordinat x,y absolut
- Kolom kanan & kiri tidak aligned saat text panjang
- "(Kelas 7–9)" wrap aneh karena `splitTextToSize` motong di posisi yang tidak ideal

**GANTI dengan implementasi autoTable:**

Cari kode mulai dari:
```javascript
const drawHeader = (title, kind) => {
  y = margin;
  addCenter(String(state.identity.namaSekolah || "NAMA SEKOLAH").toUpperCase(), 16, "bold", 4);
  addCenter(String(title || "").toUpperCase(), 13, "bold", 6);
  addCenter(`Tahun Pelajaran ${String(state.paket.tahunAjaran || "-")}`, 11, "normal", 16);
  
  const logo = state.identity.logo || "";
  if (logo) { ... }
  
  const labelW = 140;
  const colGap = 40;
  ...
  // ... sampai akhir function drawHeader, sebelum return { doc, ... }
```

GANTI seluruh logic identity rows (dari `const labelW = 140;` sampai sebelum garis horizontal akhir) dengan:

```javascript
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
  
  const topikDisplay = identityTopikDisplay(state.identity);
  const isSpesifik = !!topikDisplay;
  
  // ===== Build identity rows untuk autoTable =====
  // PENTING: untuk kind === "soal", JANGAN tampilkan "Topik" (sesuai DOCX)
  // Untuk kind !== "soal" (kunci/kisi), tampilkan Topik jika ada
  
  const leftRows = [];
  const rightRows = [];
  
  if (kind === "soal") {
    // Layout 2 kolom untuk Naskah Soal
    leftRows.push(["Mata Pelajaran", `: ${String(state.identity.mataPelajaran || "-")}`]);
    leftRows.push(["Kelas / Fase", `: ${String(state.identity.kelas || "-")} / ${String(state.identity.fase || "-")}`]);
    leftRows.push(["Hari / Tanggal", ": ______________________________"]);
    
    rightRows.push(["Waktu", ": ______________________________"]);
    rightRows.push(["Nama Siswa", ": ______________________________"]);
    rightRows.push(["No. Absen / Ruang", ": ______________________________"]);
  } else {
    // Layout 2 kolom untuk Kunci/Kisi
    leftRows.push(["Mata Pelajaran", `: ${String(state.identity.mataPelajaran || "-")}`]);
    leftRows.push(["Kelas / Fase", `: ${String(state.identity.kelas || "-")} / ${String(state.identity.fase || "-")}`]);
    if (isSpesifik) {
      leftRows.push(["Topik / Lingkup Materi", `: ${String(topikDisplay || "-")}`]);
    }
    
    rightRows.push(["Kurikulum", `: ${String(state.identity.kurikulum || "Merdeka")}`]);
    rightRows.push(["Jumlah Soal", `: ${String((state.questions || []).length)}`]);
  }
  
  // Pad rows agar jumlah row left dan right sama
  const maxRows = Math.max(leftRows.length, rightRows.length);
  while (leftRows.length < maxRows) leftRows.push(["", ""]);
  while (rightRows.length < maxRows) rightRows.push(["", ""]);
  
  // Build body untuk autoTable: each row = [leftLabel, leftValue, rightLabel, rightValue]
  const tableBody = [];
  for (let i = 0; i < maxRows; i++) {
    tableBody.push([
      leftRows[i][0],
      leftRows[i][1],
      rightRows[i][0],
      rightRows[i][1]
    ]);
  }
  
  // Render via autoTable
  doc.autoTable({
    startY: y,
    margin: { left: margin, right: margin },
    body: tableBody,
    theme: 'plain',  // no border
    styles: {
      font: 'times',
      fontSize: 11,
      textColor: [0, 0, 0],
      cellPadding: { top: 2, right: 4, bottom: 2, left: 0 },
      overflow: 'linebreak',
      valign: 'top',
    },
    columnStyles: {
      0: { cellWidth: (maxW - 20) * 0.22, fontStyle: 'bold' },  // Label kiri
      1: { cellWidth: (maxW - 20) * 0.28 },                      // Value kiri
      2: { cellWidth: (maxW - 20) * 0.22, fontStyle: 'bold' },  // Label kanan
      3: { cellWidth: (maxW - 20) * 0.28 },                      // Value kanan
    },
  });
  
  y = (doc.lastAutoTable?.finalY || y) + 8;
  
  // Garis horizontal pemisah (tetap seperti existing)
  doc.setDrawColor(0);
  doc.setLineWidth(1.2);
  doc.line(margin, y, pageW - margin, y);
  y += 16;
};
```

### TUGAS B: Fix Opsi Pilihan Ganda — Pakai autoTable Grid 2x2

Cari di dalam function `buildSoalPdf` di sekitar line 1665-1670:

```javascript
if (q.type === "pg" || q.type === "benar_salah" || q.type === "pg_kompleks") {
  const opts = Array.isArray(q.options) ? q.options : [];
  for (let oi = 0; oi < opts.length; oi++) {
    ctx.addHanging(`${String.fromCharCode(65 + oi)}.`, String(opts[oi] || ""), 11, "normal", 30, 4);
  }
  ctx.setY(ctx.getY() + 6);
}
```

GANTI dengan:

```javascript
if (q.type === "pg" || q.type === "benar_salah" || q.type === "pg_kompleks") {
  const opts = Array.isArray(q.options) ? q.options : [];
  
  // Untuk PG dengan tepat 4 opsi: tampilkan dalam grid 2x2 (kiri A-C, kanan B-D) seperti DOCX
  // Untuk benar_salah & opsi lain: tetap linear top-to-bottom
  if (q.type === "pg" && opts.length === 4) {
    // Grid 2x2: kolom kiri (A, B), kolom kanan (C, D)
    // Atau bisa juga (A, C) kiri, (B, D) kanan — sesuai DOCX yang Anda generate
    
    // Format yang sama dengan DOCX: A & C di baris atas, B & D di baris bawah? 
    // Lihat DOCX: A. ... | C. ...
    //             B. ... | D. ...
    // Jadi kiri-kanan = A-C, lalu B-D
    
    const cellA = `A. ${String(opts[0] || "")}`;
    const cellB = `B. ${String(opts[1] || "")}`;
    const cellC = `C. ${String(opts[2] || "")}`;
    const cellD = `D. ${String(opts[3] || "")}`;
    
    ctx.doc.autoTable({
      startY: ctx.getY(),
      margin: { left: ctx.margin + 18, right: ctx.margin },  // indent dari nomor soal
      body: [
        [cellA, cellC],
        [cellB, cellD]
      ],
      theme: 'plain',
      styles: {
        font: 'times',
        fontSize: 11,
        textColor: [0, 0, 0],
        cellPadding: { top: 2, right: 8, bottom: 2, left: 0 },
        overflow: 'linebreak',
        valign: 'top',
      },
      columnStyles: {
        0: { cellWidth: (ctx.maxW - 18) / 2 },
        1: { cellWidth: (ctx.maxW - 18) / 2 },
      },
    });
    ctx.setY((ctx.doc.lastAutoTable?.finalY || ctx.getY()) + 8);
  } else {
    // Linear layout untuk benar_salah, pg_kompleks, atau PG dengan opsi != 4
    for (let oi = 0; oi < opts.length; oi++) {
      ctx.addHanging(`${String.fromCharCode(65 + oi)}.`, String(opts[oi] || ""), 11, "normal", 30, 4);
    }
    ctx.setY(ctx.getY() + 6);
  }
}
```

### TUGAS C: Fix Section Title — Tambah Prefix "A.", "B.", dst.

Cari di dalam `buildSoalPdf` di sekitar line 1633-1640:

```javascript
chunks.forEach((chunk, chunkIdx) => {
  const needPageBreak = firstTypeRendered || chunkIdx > 0;
  if (needPageBreak) ctx.newPage();

  if (chunkIdx === 0) {
    ctx.addPara(`${titleMap[t]}`, 12, "bold", 0, 4);
    ctx.addPara(subtitleMap[t], 11, "italic", 0, 14);
  }
```

DI ATAS block `chunks.forEach`, TAMBAH variable counter untuk section prefix:

```javascript
let sectionLetterIdx = 0; // BARU: untuk prefix A, B, C, dst.
const sectionLetters = ['A', 'B', 'C', 'D', 'E', 'F'];
```

Lalu GANTI block render title:

```javascript
chunks.forEach((chunk, chunkIdx) => {
  const needPageBreak = firstTypeRendered || chunkIdx > 0;
  if (needPageBreak) ctx.newPage();

  if (chunkIdx === 0) {
    // BARU: tambah prefix "A.", "B.", dst. sesuai DOCX
    const letter = sectionLetters[sectionLetterIdx] || '';
    const titleWithPrefix = letter ? `${letter}. ${titleMap[t]}` : titleMap[t];
    ctx.addPara(titleWithPrefix, 12, "bold", 0, 4);
    ctx.addPara(subtitleMap[t], 11, "italic", 0, 14);
    sectionLetterIdx++;  // increment untuk section berikutnya
  }
```

PENTING: `sectionLetterIdx` harus reset untuk tiap dokumen. Sesuaikan posisinya supaya ada di dalam `buildSoalPdf` tapi di-reset setiap pemanggilan.

Letak yang tepat: di awal `buildSoalPdf`, sebelum `for (const t of order)`:

```javascript
const buildSoalPdf = (ctxIn, opts = {}) => {
  const ctx = ctxIn || makeDoc();
  const addFooter = Object.prototype.hasOwnProperty.call(opts, 'addFooter') ? !!opts.addFooter : true;
  ctx.drawHeader(String(state.paket.judul || "NASKAH SOAL"), "soal");

  let firstTypeRendered = false;
  let sectionLetterIdx = 0;  // BARU: reset per build
  const sectionLetters = ['A', 'B', 'C', 'D', 'E', 'F'];  // BARU
  
  for (const t of order) {
    // ... existing code
```

Lakukan hal yang SAMA untuk `buildKunciPdf` dan `buildKisiPdf` (cari function-function tersebut, biasanya tidak jauh dari `buildSoalPdf`).

### TUGAS D: Cari & Update buildKunciPdf dan buildKisiPdf

Cari di file:
```bash
grep -n "buildKunciPdf\|buildKisiPdf" index.php
```

Untuk SETIAP function tersebut:
1. Tambahkan `let sectionLetterIdx = 0;` di awal
2. Tambahkan `const sectionLetters = ['A', 'B', 'C', 'D', 'E', 'F'];`
3. Saat render section title (mis. "PILIHAN GANDA" di kunci), prefix dengan letter

Catatan: kalau struktur internal mereka beda, sesuaikan tapi prinsipnya sama — tambahkan prefix "A.", "B." di setiap section header.

---

## YANG TIDAK BOLEH DIUBAH

1. **JANGAN ubah** logic generate soal apa pun
2. **JANGAN ubah** function `exportDocx`
3. **JANGAN hapus** function existing apa pun, hanya modify body
4. **JANGAN ubah** footer page number (yang menampilkan "Halaman X")
5. **JANGAN ubah** font (tetap Times, karena built-in jsPDF)

---

## CARA VERIFIKASI

### Test 1: Header Identity Naskah Soal
1. Buat paket soal apa pun, klik "Download PDF"
2. **Expected layout (sesuai DOCX):**
   ```
   Mata Pelajaran    : Bahasa Indonesia    | Waktu               : ____________
   Kelas / Fase      : Kelas 7 / Fase D    | Nama Siswa          : ____________
   Hari / Tanggal    : ____________        | No. Absen / Ruang   : ____________
   ```
3. **TIDAK BOLEH:**
   - "Topik / Lingkup Materi" muncul (hanya di Kunci/Kisi)
   - Label & value tidak align
   - Text wrap aneh di "(Kelas 7-9)"

### Test 2: Header Identity Kunci & Kisi
1. Download Kunci PDF & Kisi-Kisi PDF
2. **Expected layout:**
   ```
   Mata Pelajaran            : Bahasa Indonesia    | Kurikulum    : Merdeka
   Kelas / Fase              : Kelas 7 / Fase D    | Jumlah Soal  : 3
   Topik / Lingkup Materi    : Majas               |
   ```
3. Topik MUNCUL di Kunci/Kisi (kalau ada di paket), TIDAK di Naskah Soal

### Test 3: Opsi Pilihan Ganda Grid 2x2
1. Buka PDF soal PG
2. **Expected:**
   ```
   1. [Pertanyaan soal]
       A. Pilihan A...     C. Pilihan C...
       B. Pilihan B...     D. Pilihan D...
   ```
3. Bukan lagi linear:
   ```
   A. ...
   B. ...
   C. ...
   D. ...
   ```

### Test 4: Section Prefix
1. Buka PDF naskah dengan 2+ jenis soal (mis. PG + Uraian)
2. **Expected:**
   - Section 1: "**A. PILIHAN GANDA**" (bukan cuma "PILIHAN GANDA")
   - Section 2: "**B. URAIAN**" (bukan cuma "URAIAN")
3. Cek juga Kunci & Kisi sama (A. PILIHAN GANDA, B. URAIAN, dst.)

### Test 5: Wrap Text Tidak Hancur
1. Buat soal dengan nama mapel panjang (mis. "Pendidikan Pancasila dan Kewarganegaraan")
2. **Expected:** Layout tabel tetap rapi, text wrap di dalam cell tabel tidak crash ke kolom lain

### Test 6: Compare Side-by-Side dengan DOCX
1. Download paket sama dalam DOCX dan PDF
2. Bandingkan:
   - Header sekolah ✓ harus sama (centered, bold)
   - Identity table ✓ harus sama (2 kolom, label-value, underscore untuk kosong)
   - Section title ✓ harus sama (A. PILIHAN GANDA)
   - Opsi PG ✓ harus sama (grid 2x2)

### Test 7: Soal Uraian/Isian Tidak Berubah
1. Cek soal isian → garis dotted muncul (existing)
2. Cek soal uraian → 4 baris kosong muncul (existing)
3. Cek soal menjodohkan → tabel 2 kolom muncul (existing)
4. Semua TIDAK berubah dari sebelumnya

### Test 8: Footer Tetap Ada
1. Cek bagian bawah PDF
2. **Expected:** "Bahasa Indonesia — SMP DQ 2 PUTRA | Halaman 1" tetap muncul

### Test 9: File Terpisah Mode Tetap Jalan
1. Centang "Download File Terpisah" → klik Download PDF
2. **Expected:** 3 file PDF terpisah (Soal, Kunci, Kisi) semuanya pakai format baru

### Test 10: PG dengan Opsi Bukan 4
1. Buat soal PG dengan 3 atau 5 opsi
2. **Expected:** Fallback ke linear layout (bukan grid 2x2 yang tidak ngepas)

---

## FILE YANG DIEDIT
- `index.php` saja, function `exportSoalPDF` (sekitar line 1417-1900)

## OUTPUT YANG DIHARAPKAN

1. Header identity PDF rapi seperti DOCX (2 kolom tabel)
2. "Topik / Lingkup Materi" HANYA di Kunci & Kisi, TIDAK di Naskah Soal
3. Opsi PG 4 pilihan tampil grid 2x2 (A-C kiri, B-D kanan)
4. Section title prefix "A.", "B.", dst.
5. PG dengan opsi 3 atau 5 fallback ke linear
6. Test 10 scenario PASS
7. Tidak ada error console

---

## BONUS: Bandingkan dengan Print-to-PDF (Opsi B)

Setelah Opsi A ini di-apply, hasilnya PDF akan **80-90% sebagus DOCX**. Kalau masih ada gap kecil (mis. font Times terlihat klasik), bisa lanjut implement Opsi B (Print-to-PDF dari HTML) untuk hasil 100% sebagus DOCX.

Tapi mayoritas user akan puas dengan Opsi A ini karena:
- 1-klik download (tidak perlu dialog browser)
- Layout sudah konsisten dengan DOCX
- Tidak ada friction baru di UX
