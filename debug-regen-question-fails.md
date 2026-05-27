#[OPEN] Debug Session: regen-question-fails

## Ringkasan
- Gejala: Tombol **Buat Soal Serupa** / **Ganti Taksonomi** gagal dengan pesan umum “Gagal memproses AI. Coba lagi.”, sementara tombol **Buat Soal** (generate paket) berhasil.
- Target: Identifikasi penyebab pasti (HTTP status, timeout, format respons, session/device-lock, payload terlalu besar) dan terapkan perbaikan minimal berbasis bukti.

## Hipotesis (falsifiable)
- H1: Request `api/openai_proxy.php` untuk regen gagal (401/403/429/502/5xx) dan ditangani sebagai error umum di UI.
- H2: Request sukses (200) tapi respons tidak berformat JSON `{ items: [...] }` sehingga `regenSingle` menganggap kosong.
- H3: Payload prompt buat-ulang terlalu besar (konteks + anti-duplikasi) sehingga server/proxy timeout atau upstream error; buat paket lebih “tahan banting” karena batching/retry.
- H4: Session/device-lock (`auth_lock`) menolak request regen di kondisi tertentu (401), tetapi flow “buat paket soal” kebetulan lolos.
- H5: Exception client terjadi setelah fetch (parse/normalize/updateQuestionData) sehingga UI selalu jatuh ke pesan umum.

## Rencana Bukti
1) Jalankan debug server dan kosongkan log.
2) Instrumentasi MINIMAL:
   - Client: sebelum & sesudah callOpenAI pada jalur `regenSingle` (qId, promptLen, attempt, error message normalized).
   - Server: di `api/openai_proxy.php` catat status, type, user_id, reason ketika non-2xx.
3) Repro 1 kali: klik **Buat Soal Serupa** pada 1 soal.
4) Tarik log dan tentukan hipotesis yang benar.

## Status
- [ ] Debug server running
- [ ] Instrumentation deployed
- [ ] Reproduced + logs captured
- [ ] Root cause confirmed
- [ ] Fix applied + verified
