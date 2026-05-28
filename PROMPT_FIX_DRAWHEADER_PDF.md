# 🔧 Prompt Trae: Fix Header PDF — Ganti drawCol Manual dengan autoTable

> **Masalah spesifik:** Di file `index.php`, function `drawHeader` (sekitar line 1480) masih menggunakan posisi manual `drawCol(x, y)` untuk render identity table. Hasilnya:
> - Baris seperti "Nama Siswa" wrap ke baris baru tapi tidak aligned dengan label
> - "Hari / Tanggal" terpotong aneh di kolom yang salah
> - Underscore garis (`________________________`) terlalu pendek karena `colW` terbagi tidak proporsional
>
> **Solusi:** Ganti logic `drawCol` dengan `doc.autoTable()` yang sudah ada di jsPDF.

---

## TUGAS: GANTI BODY FUNCTION `drawHeader` (line 1480-1554)

**CARI** persis kode ini (mulai dari `const labelW = 140;` sampai sebelum `return { doc, ...`):

```javascript
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
```

**GANTI dengan:**

```javascript
const topikDisplay = identityTopikDisplay(state.identity);
const isSpesifik = !!topikDisplay;

// Build baris untuk autoTable: [labelKiri, valueKiri, labelKanan, valueKanan]
const tableRows = [];

if (kind === "soal") {
  // Naskah soal: TANPA topik (sesuai DOCX), WITH underscore untuk isian siswa
  tableRows.push(["Mata Pelajaran", `: ${state.identity.mataPelajaran || "-"}`, "Waktu", ": ______________________________"]);
  tableRows.push(["Kelas / Fase", `: ${state.identity.kelas || "-"} / ${state.identity.fase || "-"}`, "Nama Siswa", ": ______________________________"]);
  tableRows.push(["Hari / Tanggal", ": ______________________________", "No. Absen / Ruang", ": ______________________________"]);
} else {
  // Kunci/Kisi: tampilkan topik jika ada, pakai nilai fix
  tableRows.push(["Mata Pelajaran", `: ${state.identity.mataPelajaran || "-"}`, "Kurikulum", `: ${state.identity.kurikulum || "Merdeka"}`]);
  tableRows.push(["Kelas / Fase", `: ${state.identity.kelas || "-"} / ${state.identity.fase || "-"}`, "Jumlah Soal", `: ${(state.questions || []).length}`]);
  if (isSpesifik) {
    tableRows.push(["Topik / Lingkup Materi", `: ${topikDisplay}`, "", ""]);
  }
}

// Lebar kolom: label 22%, value 28%, label 22%, value 28%
const colW1 = maxW * 0.22;
const colW2 = maxW * 0.28;
const colW3 = maxW * 0.22;
const colW4 = maxW * 0.28;

doc.autoTable({
  startY: y,
  margin: { left: margin, right: margin },
  body: tableRows,
  theme: "plain",
  styles: {
    font: "times",
    fontSize: 11,
    textColor: [0, 0, 0],
    cellPadding: { top: 2, right: 2, bottom: 2, left: 0 },
    overflow: "linebreak",
    valign: "top",
    lineWidth: 0,
  },
  columnStyles: {
    0: { cellWidth: colW1, fontStyle: "bold" },
    1: { cellWidth: colW2 },
    2: { cellWidth: colW3, fontStyle: "bold" },
    3: { cellWidth: colW4 },
  },
  tableWidth: maxW,
});

y = (doc.lastAutoTable?.finalY || y) + 8;

// Garis horizontal pemisah bawah header
doc.setDrawColor(0);
doc.setLineWidth(1.2);
doc.line(margin, y, pageW - margin, y);
y += 18;
```

---

## YANG TIDAK BOLEH DIUBAH

1. JANGAN ubah 3 baris `addCenter` di awal `drawHeader` (nama sekolah, judul, tahun pelajaran)
2. JANGAN ubah blok logo (if logo) di `drawHeader`
3. JANGAN ubah function-function lain di luar `drawHeader`
4. JANGAN ubah opsi PG, section prefix, atau footer — itu sudah benar dari patch sebelumnya

---

## CARA VERIFIKASI

### Test 1: Naskah Soal — Header Rapi
Download PDF paket soal apa pun. Cek halaman 1:

**Expected:**

```
          SMP DQ 2 PUTRA
             PENILAIAN
       Tahun Pelajaran 2026/2027
─────────────────────────────────────────────────
Mata Pelajaran : Bahasa Indonesia  │ Waktu       : ______________________________
Kelas / Fase   : Kelas 7 / Fase D  │ Nama Siswa  : ______________________________
Hari / Tanggal : ______________     │ No. Absen   : ______________________________
═════════════════════════════════════════════════
```

**NOT Expected (sebelumnya):**
- "Hari / Tanggal" dan "Waktu" di baris yang salah
- Label wrap ke baris baru terpisah dari value
- "(Kelas 7–9)" muncul sebagai baris tersendiri

### Test 2: Topik TIDAK Muncul di Naskah Soal
1. Buat paket soal yang punya Topik/Lingkup Materi (isi di form Identitas)
2. Download PDF → lihat header
3. **Expected:** "Topik / Lingkup Materi" TIDAK muncul di naskah soal
4. Download Kunci PDF & Kisi PDF → "Topik / Lingkup Materi" MUNCUL di sana

### Test 3: Kunci & Kisi Header
Download Kunci PDF. Cek header:

**Expected:**
```
Mata Pelajaran         : Bahasa Indonesia  │ Kurikulum   : Merdeka
Kelas / Fase           : Kelas 7 / Fase D  │ Jumlah Soal : 3
Topik / Lingkup Materi : Majas             │
```

### Test 4: Text Panjang Tidak Merusak Layout
1. Buat paket dengan nama mapel panjang: "Pendidikan Pancasila dan Kewarganegaraan"
2. Download PDF
3. **Expected:** Teks wrap di dalam cell kolom kiri, kolom kanan tetap di posisi yang benar (tidak bergeser)

### Test 5: Bandingkan dengan DOCX
Download paket yang sama dalam format DOCX dan PDF. Bandingkan header:
- Label & value sejajar ✓
- Underscore untuk isian siswa panjangnya sama ✓
- Tidak ada Topik di Naskah Soal ✓

---

## FILE YANG DIEDIT
- `index.php` saja, function `drawHeader` (sekitar line 1495-1553)

## OUTPUT
1. Header identity PDF rapih 2-kolom seperti DOCX
2. "Topik" tidak muncul di naskah soal
3. Underscore panjang & konsisten untuk Waktu/Nama/No. Absen
4. Text panjang wrap dalam cell, tidak merusak layout kolom lain
5. Tidak ada error console
