#[OPEN] Debug Session: ai-regenerate-fails

## Ringkasan
- Gejala: Klik "Buat Soal Serupa" / "Ganti Taksonomi (C6)" memunculkan alert "Gagal memproses AI. Coba lagi." atau soal tidak berubah.
- Target: Pastikan request AI benar-benar jalan, terukur (pre/post), dan jika gagal ada penyebab jelas (HTTP status / payload / limit / session).

## Hipotesis (falsifiable)
- H1: Request ke `api/openai_proxy.php` gagal (401/403/429/5xx) sehingga UI tidak mendapatkan respons valid.
- H2: `callOpenAI()` berhasil, tapi respons tidak berformat `{ items: [...] }` sehingga `regenSingle` menganggap kosong.
- H3: Kredit/akses (limitpaket / access_buat_soal / device-lock) menyebabkan proxy menolak.
- H4: Timeout/AbortController memutus request karena payload prompt terlalu panjang atau jaringan lambat.
- H5: Ada exception di client sebelum/ sesudah fetch (parse/normalize) sehingga update question tidak terjadi.

## Langkah Repro
1) Buka tab Naskah Soal → pilih soal → klik titik 3 → Ganti Taksonomi → C6 → Terapkan
2) Atau klik titik 3 → Buat Soal Serupa

## Evidence Plan
- Tambah instrumentation event ke Debug Server:
  - sebelum callOpenAI (prompt length, qId, bloom, type)
  - sesudah fetch (status ok/error, latency, response keys)
  - hasil parsing (items length, stem hash)

## Status
- [ ] Debug server running
- [ ] Instrumentation deployed
- [ ] Reproduced with logs
- [ ] Root cause confirmed
- [ ] Fix applied
