<?php
session_start();
if (!isset($_SESSION['user_id'])) {
  header('Location: login.php');
  exit;
}
?>
<!DOCTYPE html>
<html lang="id" class="custom-scrollbar">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>SoalPintar</title>
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
                  <span class="text-primary">Soal</span><span class="text-text-main-light dark:text-text-main-dark">Pintar</span>
                </div>
              <div class="text-xs italic text-text-sub-light dark:text-text-sub-dark mt-0.5">Sahabat Pendidik Indonesia</div>
              </div>
            </div>
          </div>
          <div id="limitSidebar" class="no-print -mt-1 text-[13px] font-semibold text-blue-700 dark:text-blue-300"></div>
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
                <div id="pageDesc" class="text-sm text-text-sub-light dark:text-text-sub-dark mt-1">
                  Lengkapi identitas sebelum menyusun paket
                </div>
              </div>
            </div>
            <div class="flex flex-col items-end">
              <div class="text-right">
                <div class="text-3xl md:text-4xl font-bold tracking-tight">
                  <span class="text-primary">Soal</span><span class="text-text-main-light dark:text-text-main-dark">Pintar</span>
                </div>
                <div class="italic text-xs md:text-sm text-text-sub-light dark:text-text-sub-dark">Sahabat Pendidik Indonesia</div>
              </div>
              <button
                id="btnExportTop"
                class="md:hidden mt-2 flex items-center gap-2 px-3 py-1.5 rounded-md border border-border-light dark:border-border-dark hover:bg-background-light dark:hover:bg-background-dark text-sm font-medium transition-colors"
              >
                <span class="material-symbols-outlined text-[16px]">description</span>
                Unduh .docx
              </button>
            </div>
          </div>
          <div class="flex items-center justify-between gap-2 pb-3">
            <div id="tabs" class="flex gap-2 overflow-x-auto no-scrollbar"></div>
            <div class="hidden md:flex items-center gap-1.5">
              <button id="btnSave" class="inline-flex items-center justify-center h-10 w-10 rounded-full border bg-white dark:bg-surface-dark border-border-light dark:border-border-dark hover:bg-background-light dark:hover:bg-background-dark transition-colors">
                <span class="material-symbols-outlined text-[18px]">save</span>
                <span class="hidden 2xl:inline ml-2 text-sm font-medium">Simpan</span>
              </button>
              <button id="btnLoad" class="inline-flex items-center justify-center h-10 w-10 rounded-full border bg-white dark:bg-surface-dark border-border-light dark:border-border-dark hover:bg-background-light dark:hover:bg-background-dark transition-colors">
                <span class="material-symbols-outlined text-[18px]">folder_open</span>
                <span class="hidden 2xl:inline ml-2 text-sm font-medium">Muat</span>
              </button>
              <button id="btnPrint" class="inline-flex items-center justify-center h-10 w-10 rounded-full border bg-white dark:bg-surface-dark border-border-light dark:border-border-dark hover:bg-background-light dark:hover:bg-background-dark transition-colors">
                <span class="material-symbols-outlined text-[18px]">print</span>
                <span class="hidden 2xl:inline ml-2 text-sm font-medium">Cetak</span>
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
          class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 px-5 py-4 border-t border-border-light dark:border-border-dark bg-background-light/50 dark:bg-background-dark/30"
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
          <div class="flex items-center gap-2">
            <button
              id="btnQuizRegen"
              class="flex items-center gap-2 rounded-lg h-10 px-4 bg-white dark:bg-surface-dark border border-border-light dark:border-border-dark hover:bg-background-light dark:hover:bg-background-dark text-sm font-bold shadow-sm transition-colors"
            >
              <span class="material-symbols-outlined text-[18px]">autorenew</span>
              Buat Soal Ulang
            </button>
            <button
              id="btnQuizImgRegen"
              class="flex items-center gap-2 rounded-lg h-10 px-4 bg-white dark:bg-surface-dark border border-border-light dark:border-border-dark hover:bg-background-light dark:hover:bg-background-dark text-sm font-bold shadow-sm transition-colors"
            >
              <span class="material-symbols-outlined text-[18px]">image</span>
              Buat Ulang Gambar
            </button>
          </div>
        </div>
      </div>
    </div>
    

    <input id="filePicker" type="file" accept="image/*" class="hidden" />
    <input id="logoPicker" type="file" accept="image/*" class="hidden" />
    <input id="projectPicker" type="file" accept=".json" class="hidden" />
    <input id="lkpdImgUpload" type="file" accept="image/*" class="hidden" />
    <input id="lkpdTxtUpload" type="file" accept=".txt,.md,.markdown,.csv,.json,.html,.htm" class="hidden" />

    <script>
      const OPENAI_API_KEY = "";
      const OPENAI_MODEL = "gpt-4o-mini"; // or gpt-3.5-turbo, gpt-4
      const IS_ADMIN = <?php echo (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') ? 'true' : 'false'; ?>;

      const APP_KEY = "soalpintar:v1";
      const OPENAI_TIMEOUT_MS = 55000;
      const GEN_BATCH_SIZE = 10;
      const GEN_MAX_ATTEMPTS = 5;
      const VIEWS = [
        { id: "identitas", label: "Identitas", icon: "badge" },
        { id: "konfigurasi", label: "Konfigurasi", icon: "tune" },
        { id: "preview", label: "Naskah Soal", icon: "description" },
        { id: "quiz", label: "Quiz", icon: "quiz" },
        { id: "lkpd", label: "LKPD", icon: "assignment" },
        { id: "modul_ajar", label: "Modul Ajar", icon: "menu_book" },
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
      };

      const SUBJECT_OPTIONS = {
        "SD/MI": [
          "Pendidikan Agama Islam dan Budi Pekerti",
          "Pendidikan Agama Kristen dan Budi Pekerti",
          "Pendidikan Agama Katolik dan Budi Pekerti",
          "Pendidikan Agama Hindu dan Budi Pekerti",
          "Pendidikan Agama Buddha dan Budi Pekerti",
          "Pendidikan Agama Khonghucu dan Budi Pekerti",
          "Pendidikan Pancasila",
          "Bahasa Indonesia",
          "Matematika",
          "Ilmu Pengetahuan Alam dan Sosial (IPAS)",
          "Pendidikan Jasmani, Olahraga, dan Kesehatan (PJOK)",
          "Seni Musik",
          "Seni Rupa",
          "Seni Teater",
          "Seni Tari",
          "Bahasa Inggris",
          "Muatan Lokal",
        ],
        "SMP/MTs": [
          "Pendidikan Agama Islam dan Budi Pekerti",
          "Pendidikan Agama Kristen dan Budi Pekerti",
          "Pendidikan Agama Katolik dan Budi Pekerti",
          "Pendidikan Agama Hindu dan Budi Pekerti",
          "Pendidikan Agama Buddha dan Budi Pekerti",
          "Pendidikan Agama Khonghucu dan Budi Pekerti",
          "Pendidikan Pancasila",
          "Bahasa Indonesia",
          "Matematika",
          "Ilmu Pengetahuan Alam (IPA)",
          "Ilmu Pengetahuan Sosial (IPS)",
          "Bahasa Inggris",
          "Pendidikan Jasmani, Olahraga, dan Kesehatan (PJOK)",
          "Informatika",
          "Seni Musik",
          "Seni Rupa",
          "Seni Teater",
          "Seni Tari",
          "Prakarya",
          "Muatan Lokal",
        ],
        "SMA/MA": [
          "Pendidikan Agama Islam dan Budi Pekerti",
          "Pendidikan Agama Kristen dan Budi Pekerti",
          "Pendidikan Agama Katolik dan Budi Pekerti",
          "Pendidikan Agama Hindu dan Budi Pekerti",
          "Pendidikan Agama Buddha dan Budi Pekerti",
          "Pendidikan Agama Khonghucu dan Budi Pekerti",
          "Pendidikan Pancasila",
          "Bahasa Indonesia",
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
          "Pendidikan Jasmani, Olahraga, dan Kesehatan (PJOK)",
          "Seni Musik",
          "Seni Rupa",
          "Seni Teater",
          "Seni Tari",
          "Prakarya dan Kewirausahaan",
          "Bahasa Asing Lain",
          "Muatan Lokal",
        ],
        "SMK": [
          "Pendidikan Agama Islam dan Budi Pekerti",
          "Pendidikan Agama Kristen dan Budi Pekerti",
          "Pendidikan Agama Katolik dan Budi Pekerti",
          "Pendidikan Agama Hindu dan Budi Pekerti",
          "Pendidikan Agama Buddha dan Budi Pekerti",
          "Pendidikan Agama Khonghucu dan Budi Pekerti",
          "Pendidikan Pancasila",
          "Bahasa Indonesia",
          "Matematika",
          "Bahasa Inggris",
          "Sejarah",
          "Seni Budaya",
          "Pendidikan Jasmani, Olahraga, dan Kesehatan (PJOK)",
          "Informatika",
          "Ilmu Pengetahuan Alam dan Sosial (IPAS)",
          "Dasar-dasar Program Keahlian",
          "Konsentrasi Keahlian",
          "Projek Kreatif dan Kewirausahaan",
          "Muatan Lokal",
        ],
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
        activeView: "identitas",
        _isGenerating: false,
        lkpd: {
          sumber: "topik",
          topik: "",
          materi: "",
          jenjang: "",
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
          judulModul: "", jumlahPertemuan: "2",
          durasi: "50", jumlahSiswa: "30",
          modelPembelajaran: "Project Based Learning (PjBL)",
          dimensi: [], hasil: "", isGenerating: false,
        },
        identity: {
          namaGuru: "",
          namaSekolah: "",
          jenjang: "",
          fase: "",
          kelas: "",
          mataPelajaran: "",
          jenisTopik: "spesifik", // spesifik | campuran
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
            pakaiGambar: false,
          },
        ],
        globalSeed: Math.floor(Math.random() * 1e9),
        questions: [],
        quiz: { idx: 0, answered: {}, input: "", reveal: false },
      });

      let state = DEFAULT_STATE();
      const el = (id) => document.getElementById(id);

      const clamp = (n, a, b) => Math.max(a, Math.min(b, n));
      const safeText = (s) =>
        String(s ?? "")
          .replaceAll("&", "&amp;")
          .replaceAll("<", "&lt;")
          .replaceAll(">", "&gt;");
      const sectionLetter = (idx) => String.fromCharCode("A".charCodeAt(0) + idx);

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
        const banner = el("limitSidebar");
        if (!banner) return;
        try {
          const res = await fetch("api/openai_proxy.php", { method: "POST", headers: {"Content-Type":"application/json"}, body: JSON.stringify({ type: "get_limits" }) });
          if (res.ok) {
            const lim = await res.json();
            const total = Number(lim?.initial_limitpaket ?? 300);
            const sisa = Number(lim?.limitpaket ?? 0);
            const terpakai = Math.max(0, total - sisa);
            banner.textContent = `limit terpakai ${terpakai} • sisa limit ${sisa}`;
          }
        } catch {}
      };

      const setView = (id) => {
        state.activeView = id;
        saveDebounced(true);
        render();
      };

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
        if (key === "bentuk") {
          const isObjective = ["pg", "pg_kompleks", "menjodohkan"].includes(value);
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
        const cleanOptionText = (s) => {
          let t = String(s ?? "").trim();
          t = t.replace(/^\s*\(?([A-Ea-e]|[1-9]|10)\)?\s*[\)\.\-:]\s*/,'');
          t = t.replace(/^\s*[A-Ea-e]\.\s+/,'');
          t = t.replace(/^\s*[A-Ea-e]\s*-\s+/,'');
          return t.trim();
        };
        const rawType = String(item?.type ?? "").toLowerCase();
        let type = "isian";
        if (rawType.includes("pg")) type = "pg";
        if (rawType.includes("kompleks")) type = "pg_kompleks";
        if (rawType.includes("menjodohkan")) type = "menjodohkan";
        if (rawType.includes("uraian")) type = "uraian";
        if (rawType === "isian") type = "isian";
        if (sec?.bentuk) type = sec.bentuk;
        
        const question = String(item?.question ?? "").trim();
        const explanation = String(item?.explanation ?? "").trim();
        const difficulty = String(item?.difficulty ?? "").trim();
        const bloom = String(item?.bloom ?? "").trim();
        const materi = String(item?.materi ?? "").trim();
        const indikator = String(item?.indikator ?? "").trim();
        const imagePrompt = String(item?.imagePrompt ?? "").trim();
        
        const options = Array.isArray(item?.options) ? item.options.map((x) => cleanOptionText(x)).filter(Boolean) : [];
        
        let cappedOptions = options;
        if (type === "pg" || type === "pg_kompleks") {
             const max = clamp(Number(sec?.opsiPG || 4), 3, 5);
             if (cappedOptions.length > max) cappedOptions = cappedOptions.slice(0, max);
        }

        let answer = item?.answer;
        if (type === "pg") {
             answer = normalizeAnswerIndex(answer, cappedOptions);
        } else if (type === "pg_kompleks") {
             if (!Array.isArray(answer)) answer = [answer];
             answer = answer.map(a => normalizeAnswerIndex(a, cappedOptions)).filter(n => n >= 0);
             answer = [...new Set(answer)].sort((a,b)=>a-b);
        } else if (type === "menjodohkan") {
             if (!Array.isArray(answer)) answer = [];
        } else {
             answer = String(answer ?? "").trim();
        }

        return {
          id: uuid(),
          sectionId: sec?.id,
          pakaiGambar: Boolean(sec?.pakaiGambar),
          type,
          question,
          options: cappedOptions,
          answer,
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
        const fileName = `SoalPintar_${mataPelajaran.replace(/\s+/g, "_")}_${kelas.replace(/\s+/g, "_")}_${date}.json`;
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
            body: JSON.stringify({ type: "chat", prompt, model: OPENAI_MODEL }),
            signal: controller.signal,
          });
          if (!response.ok) {
            const errText = await response.text();
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

      async function generateImage(prompt) {
        const callApi = async (model, p, size) => {
          const response = await fetch("api/openai_proxy.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
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
          const promptSimple = `simple black and white educational diagram, minimalist line drawing, no shading, white background. Subject: ${prompt}`;
          return await callApi("gpt-image-1", promptSimple, "512x512");
        } catch (e) {
          console.warn("Image gen simple failed, trying normal...", e);
        }

        try {
          const promptNormal = `clear educational diagram for school question, black and white, high contrast. Subject: ${prompt}`;
          try {
            return await callApi("gpt-image-1", promptNormal, "512x512");
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
          const resp = await fetch(String(dataUrl));
          const blob = await resp.blob();
          let outBlob = blob;
          if (!["image/png", "image/jpeg"].includes(blob.type)) {
            await new Promise((resolve) => {
              const img = new Image();
              img.onload = () => {
                const canvas = document.createElement("canvas");
                canvas.width = img.naturalWidth || 256;
                canvas.height = img.naturalHeight || 256;
                const ctx = canvas.getContext("2d");
                if (ctx) ctx.drawImage(img, 0, 0);
                canvas.toBlob((pngBlob) => {
                  outBlob = pngBlob || blob;
                  resolve();
                }, "image/png", 0.92);
              };
              img.onerror = () => resolve();
              img.src = String(dataUrl);
            });
          }
          const buffer = await outBlob.arrayBuffer();
          return new docx.ImageRun({ data: buffer, transformation: { width, height } });
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
        if (state.questions.length === 0) return `<div class="p-10 text-center">Belum ada soal. Klik "Buat Paket Soal" di samping.</div>`;
        
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
                  ${q.asciiDiagram 
                    ? `<pre class="font-mono text-xs leading-none whitespace-pre overflow-x-auto p-4 bg-gray-50 border rounded-lg mb-4 select-all w-fit shadow-sm">${safeText(q.asciiDiagram)}</pre>` 
                    : q.svgSource 
                      ? `<div class="mb-4 overflow-x-auto p-4 bg-white border rounded-lg w-fit shadow-sm">${q.svgSource}</div>` 
                      : q.image 
                        ? `<img src="${q.image}" class="w-64 h-64 object-contain rounded-lg mb-2 border shadow-sm">` 
                        : ""
                  }
                  ${q._showImagePrompt && !q.image && q.imagePrompt ? `
                    <div class="mb-3 italic text-xs text-text-sub-light flex items-center gap-2">
                      <span>Prompt Gambar: ${safeText(q.imagePrompt)}</span>
                      <button class="inline-flex items-center justify-center size-6 rounded hover:bg-background-light border border-border-light" title="Upload gambar" onclick="document.getElementById('qImgFile-${q.id}').click()">
                        <span class="material-symbols-outlined text-[16px]">file_upload</span>
                      </button>
                      <input id="qImgFile-${q.id}" type="file" accept="image/*" class="hidden" onchange="uploadQuestionImage('${q.id}', this)">
                    </div>
                  ` : ``}
                  ${!q.image && !q.asciiDiagram && !q.svgSource && q._imageError ? `<div class="mb-4 text-xs px-2 py-1 rounded bg-red-100 text-red-700 inline-flex items-center gap-1"><span class="material-symbols-outlined text-[14px]">error</span><span>${safeText(q._imageError)}</span></div>` : ""}
                  ${
                    (q.type === "pg" || q.type === "pg_kompleks")
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
                              ${(Array.isArray(q.answer) ? [...q.answer].sort() : []).map((ans, ai) => `
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
          <div id="paper" class="bg-white text-black p-10 shadow-paper min-h-[297mm] font-serif border border-gray-200 mx-auto print:border-none print:shadow-none print:p-0">
            <div class="border-b-2 border-black pb-6 mb-8 relative">
              ${state.identity.logo ? `<img src="${state.identity.logo}" class="absolute right-0 top-0 h-16 w-auto">` : ``}
              <div class="text-center mb-6">
                <h2 class="font-bold text-2xl uppercase tracking-wider mb-1">${safeText(state.identity.namaSekolah || "NAMA SEKOLAH")}</h2>
                <h3 class="font-bold text-lg uppercase tracking-wide">${safeText(state.paket.judul || "PENILAIAN AKHIR SEMESTER")}</h3>
                <div class="text-sm mt-1">Tahun Pelajaran ${safeText(state.paket.tahunAjaran)}</div>
              </div>
              <div class="grid grid-cols-2 gap-x-12 gap-y-2 text-sm">
                <div class="space-y-1.5">
                  <div class="flex items-start"><span class="w-36 font-semibold shrink-0">Mata Pelajaran</span><span class="mr-2">:</span><span>${safeText(state.identity.mataPelajaran)}</span></div>
                  <div class="flex items-start"><span class="w-36 font-semibold shrink-0">Kelas / Fase</span><span class="mr-2">:</span><span>${safeText(state.identity.kelas)} / ${safeText(state.identity.fase)}</span></div>
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
                const order = ['pg','pg_kompleks','menjodohkan','isian','uraian'];
                const titleMap = { pg: 'PILIHAN GANDA', pg_kompleks: 'PILIHAN GANDA KOMPLEKS', menjodohkan: 'MENJODOHKAN', isian: 'ISIAN SINGKAT', uraian: 'URAIAN' };
                const subtitleMap = {
                  pg: 'Pilihlah salah satu jawaban yang paling tepat!',
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
                          ${chunk.map((q, i) => renderItem(q, startIndex + i)).join('')}
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
        `;
      };

      const renderKunci = () => {
        const rows = state.questions.map((q, i) => {
          let ans = '';
          if (q.type === 'pg') ans = typeof q.answer === 'number' ? String.fromCharCode(65 + q.answer) : '';
          else if (q.type === 'pg_kompleks') ans = Array.isArray(q.answer) ? q.answer.map(n => String.fromCharCode(65 + n)).join(', ') : '';
          else if (q.type === 'menjodohkan') ans = Array.isArray(q.answer) ? q.answer.map((t, idx) => `${idx + 1}–${String.fromCharCode(65 + idx)}`).join(', ') : '';
          else ans = String(q.answer || '');
          return `<tr><td class="border px-2 py-1 text-center">${i + 1}</td><td class="border px-2 py-1">${ans}</td></tr>`;
        }).join('');
        return `
          <div class="bg-white p-10 shadow-paper font-serif border border-gray-200 mx-auto print:border-none print:shadow-none print:p-0">
            <div class="font-bold text-lg mb-3">KUNCI JAWABAN</div>
            <table class="w-full text-sm border-collapse">
              <thead><tr><th class="border px-2 py-1">No</th><th class="border px-2 py-1">Kunci</th></tr></thead>
              <tbody>${rows}</tbody>
            </table>
          </div>
        `;
      };

      const renderKisi = () => {
        const rows = state.questions.map((q, i) => `
          <tr>
            <td class="border px-2 py-1 text-center">${i + 1}</td>
            <td class="border px-2 py-1">${safeText(q.materi || '-')}</td>
            <td class="border px-2 py-1">${safeText(q.indikator || '-')}</td>
            <td class="border px-2 py-1">${safeText(q.bloom || '-')}</td>
            <td class="border px-2 py-1">${q.type === 'pg' ? 'PG' : q.type}</td>
          </tr>
        `).join('');
        return `
          <div class="bg-white p-10 shadow-paper font-serif border border-gray-200 mx-auto print:border-none print:shadow-none print:p-0">
            <div class="font-bold text-lg mb-3">KISI-KISI</div>
            <table class="w-full text-sm border-collapse">
              <thead>
                <tr>
                  <th class="border px-2 py-1">No</th>
                  <th class="border px-2 py-1">Materi</th>
                  <th class="border px-2 py-1">Indikator</th>
                  <th class="border px-2 py-1">Level</th>
                  <th class="border px-2 py-1">Bentuk</th>
                </tr>
              </thead>
              <tbody>${rows}</tbody>
            </table>
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
                  ${selectField("Jenjang", "lkpd.jenjang", L.jenjang, ["SD/MI", "SMP/MTs", "SMA/MA", "SMK"])}
                  ${selectField("Fase", "lkpd.fase", L.fase, ["Fase A", "Fase B", "Fase C", "Fase D", "Fase E", "Fase F"])}
                  ${selectField("Kelas", "lkpd.kelas", L.kelas, CLASS_OPTIONS[L.jenjang] || [])}
                </div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                  ${selectField("Mata Pelajaran", "lkpd.mataPelajaran", L.mataPelajaran, SUBJECT_OPTIONS[L.jenjang] || [])}
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
      const MA_FASE_MAP = {
        "SD/MI":   ["Fase A (Kelas 1–2)","Fase B (Kelas 3–4)","Fase C (Kelas 5–6)"],
        "SMP/MTs": ["Fase D (Kelas 7–9)"],
        "SMA/MA":  ["Fase E (Kelas 10)","Fase F (Kelas 11–12)"],
        "SMK":     ["Fase D (Kelas 7–9)","Fase E (Kelas 10)","Fase F (Kelas 11–12)"],
        "PAUD":    ["Fase Fondasi"],
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
        const faseOpts  = MA_FASE_MAP[M.jenjang] || [];
        const kelasOpts = CLASS_OPTIONS[M.jenjang] || [];
        const dimArr    = Array.isArray(M.dimensi) ? M.dimensi : [];
        const hasilAda  = !!M.hasil;

        const mkSel = (lbl, key, val, opts) => `
          <div class="flex flex-col gap-2">
            <label class="text-sm font-semibold text-text-sub-light dark:text-text-sub-dark">${safeText(lbl)}</label>
            <select class="w-full rounded-lg border-border-light dark:border-border-dark bg-background-light dark:bg-background-dark/40 focus:border-primary focus:ring-primary h-11 px-4 text-sm"
              onchange="window.__sp.setMA('${key}',this.value,true)">
              <option value="">— Pilih —</option>
              ${opts.map(o=>`<option value="${safeText(o)}" ${String(o)===String(val||'')?'selected':''}>${safeText(o)}</option>`).join('')}
            </select>
          </div>`;

        const mkInp = (lbl, key, val, ph='') => `
          <div class="flex flex-col gap-2">
            <label class="text-sm font-semibold text-text-sub-light dark:text-text-sub-dark">${safeText(lbl)}</label>
            <input class="w-full rounded-lg border-border-light dark:border-border-dark bg-background-light dark:bg-background-dark/40 focus:border-primary focus:ring-primary h-11 px-4 text-sm"
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

        const formHtml = `
          <div class="space-y-6">
            <div class="bg-surface-light dark:bg-surface-dark rounded-xl border border-border-light dark:border-border-dark shadow-sm">
              <div class="p-6 space-y-5">
                <div>
                  <div class="text-xs font-bold text-primary bg-primary/10 inline-flex px-3 py-1 rounded-full">Langkah 1</div>
                  <div class="text-xl font-bold mt-2">Informasi Dasar</div>
                  <div class="text-sm text-text-sub-light dark:text-text-sub-dark mt-1">Identitas pendidik dan konteks akademik</div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                  ${mkInp('Nama Guru','namaGuru',M.namaGuru,'Contoh: Sunarwan, S.Pd.')}
                  ${mkInp('Nama Institusi','institusi',M.institusi,'Contoh: SDN 1 Cilodong')}
                </div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                  ${mkSel('Kurikulum','kurikulum',M.kurikulum,['Kurikulum Merdeka','Kurikulum Berbasis Cinta'])}
                  ${mkSel('Jenjang','jenjang',M.jenjang,['SD/MI','SMP/MTs','SMA/MA','SMK','PAUD'])}
                  ${mkSel('Fase','fase',M.fase,faseOpts)}
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                  ${mkSel('Kelas','kelas',M.kelas,kelasOpts)}
                  ${mkInp('Mata Pelajaran','mapel',M.mapel,'Contoh: Bahasa Indonesia, Matematika, IPAS')}
                </div>
              </div>
            </div>

            <div class="bg-surface-light dark:bg-surface-dark rounded-xl border border-border-light dark:border-border-dark shadow-sm">
              <div class="p-6 space-y-5">
                <div>
                  <div class="text-xs font-bold text-primary bg-primary/10 inline-flex px-3 py-1 rounded-full">Langkah 2</div>
                  <div class="text-xl font-bold mt-2">Detail Pembelajaran</div>
                  <div class="text-sm text-text-sub-light dark:text-text-sub-dark mt-1">Materi, durasi, model, dan profil lulusan</div>
                </div>
                <div class="grid grid-cols-1 gap-5">
                  ${mkInp('Materi Pokok / Judul Modul','judulModul',M.judulModul,'Contoh: Pengenalan Bunyi dan Kosa Kata')}
                </div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                  ${mkSel('Jumlah Pertemuan','jumlahPertemuan',M.jumlahPertemuan,['1','2','3','4','5','6','7','8','9','10','11','12'])}
                  ${mkInp('Durasi per Pertemuan (menit)','durasi',M.durasi,'Contoh: 50')}
                  ${mkInp('Jumlah Peserta Didik','jumlahSiswa',M.jumlahSiswa,'Contoh: 30')}
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                  ${mkSel('Model Pembelajaran','modelPembelajaran',M.modelPembelajaran,MA_MODEL)}
                </div>
                <div>
                  <div class="text-sm font-semibold text-text-sub-light dark:text-text-sub-dark mb-2">
                    Dimensi Profil Lulusan
                    <span class="font-normal italic ml-1 text-xs">(min. 1 — sesuai SKL 2025)</span>
                  </div>
                  <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-2">${dimChecks}</div>
                </div>
                <div id="maError" class="hidden rounded-lg bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 p-4 text-sm text-red-700 dark:text-red-300"></div>
                <div class="pt-1 flex flex-wrap items-center gap-3">
                  <button onclick="window.__sp.buildModulAjar()"
                    class="flex items-center gap-2 rounded-lg h-10 px-6 bg-primary hover:bg-blue-600 text-primary-content text-sm font-bold shadow-sm transition-colors">
                    <span class="material-symbols-outlined text-[18px]">auto_awesome</span>
                    GENERATE MODUL AJAR
                  </button>
                  ${hasilAda ? `
                  <button id="btnExportMA" onclick="window.__sp.exportModulAjarDocx()"
                    class="flex items-center gap-2 rounded-lg h-10 px-5 bg-green-600 hover:bg-green-700 text-white text-sm font-bold shadow-sm transition-colors">
                    <span class="material-symbols-outlined text-[18px]">download</span>
                    Download .docx
                  </button>` : ''}
                </div>
              </div>
            </div>

            ${hasilAda ? `
            <div class="bg-surface-light dark:bg-surface-dark rounded-xl border border-border-light dark:border-border-dark shadow-sm">
              <div class="flex items-center justify-between px-6 py-4 border-b border-border-light dark:border-border-dark">
                <div class="flex items-center gap-3">
                  <div class="size-8 rounded-lg bg-green-100 dark:bg-green-900/30 text-green-600 flex items-center justify-center">
                    <span class="material-symbols-outlined text-[18px]">check_circle</span>
                  </div>
                  <div>
                    <div class="font-bold text-sm">Modul Ajar Berhasil Dibuat</div>
                    <div class="text-xs text-text-sub-light dark:text-text-sub-dark">${safeText(M.mapel||'')} · ${safeText(M.judulModul||'')}</div>
                  </div>
                </div>
                <button id="btnExportMA2" onclick="window.__sp.exportModulAjarDocx()"
                  class="flex items-center gap-2 rounded-lg h-9 px-4 bg-green-600 hover:bg-green-700 text-white text-sm font-bold shadow-sm transition-colors">
                  <span class="material-symbols-outlined text-[16px]">download</span>
                  Download .docx
                </button>
              </div>
              <div class="p-6">
                <div class="bg-white dark:bg-gray-950 border border-border-light dark:border-border-dark rounded-lg p-8
                  font-serif text-[15px] leading-relaxed max-h-[72vh] overflow-y-auto custom-scrollbar
                  [&_h1]:text-[17px] [&_h1]:font-bold [&_h1]:mt-8 [&_h1]:mb-3
                  [&_h2]:text-[16px] [&_h2]:font-bold [&_h2]:mt-6 [&_h2]:mb-2
                  [&_h3]:text-[15px] [&_h3]:font-semibold [&_h3]:mt-5 [&_h3]:mb-2
                  [&_table]:w-full [&_table]:border-collapse [&_table]:my-3 [&_table]:text-[13px]
                  [&_td]:border [&_td]:border-gray-300 dark:[&_td]:border-gray-600 [&_td]:px-3 [&_td]:py-2 [&_td]:align-top
                  [&_th]:border [&_th]:border-gray-300 dark:[&_th]:border-gray-600 [&_th]:px-3 [&_th]:py-2 [&_th]:bg-gray-100 dark:[&_th]:bg-gray-800 [&_th]:font-bold
                  [&_.ma-tbl>tbody>tr:nth-child(even)>td]:bg-gray-50 dark:[&_.ma-tbl>tbody>tr:nth-child(even)>td]:bg-gray-900/20
                  [&_ul]:pl-6 [&_ul]:my-2 [&_li]:mb-1.5
                  [&_ol]:pl-6 [&_ol]:my-2 [&_ol]:list-decimal [&_ol>li]:mb-1.5
                  [&_em]:italic [&_strong]:font-bold
                  [&_p]:mb-3 [&_p]:text-justify">
                  ${maMarkdownToHtml(M.hasil)}
                </div>
              </div>
            </div>` : ''}
          </div>`;

        if (M.isGenerating) return `
          <div class="flex flex-col items-center justify-center p-10 md:p-20 gap-4 max-w-2xl mx-auto">
            <div class="size-12 rounded-full bg-primary/10 text-primary flex items-center justify-center">
              <span class="material-symbols-outlined animate-spin">progress_activity</span>
            </div>
            <div class="text-center">
              <div class="font-bold text-lg">Menyusun Modul Ajar...</div>
              <div class="text-sm text-text-sub-light mt-1">AI sedang membuat modul lengkap, tunggu 15–45 detik</div>
            </div>
            <div class="rounded-lg border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/20 max-w-md w-full">
              <div class="flex items-start gap-3 p-4">
                <span class="material-symbols-outlined text-amber-500 mt-0.5">warning</span>
                <div class="text-sm text-amber-700 dark:text-amber-200">Jangan tutup halaman ini. Pastikan layar tidak mati.</div>
              </div>
            </div>
          </div>`;

        return formHtml;
      };

      function maMarkdownToHtml(md) {
        if (!md) return '';
        const esc = (s) => s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
        const lines = md.split('\n');
        const parts = [];
        let i = 0;
        while (i < lines.length) {
          const line = lines[i];
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
              const cells = rows.map(r => r.trim().replace(/^\|?/, '').replace(/\|?$/, '').split('|').map(c => esc(c.trim())));
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
              parts.push(`<h2>${esc(text)}</h2>`);
            } else {
              parts.push(`<h3>${esc(text)}</h3>`);
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
              parts.push(`<h2>${esc(title)}</h2>`);
              i++;
              continue;
            }
          }
          if (/^\s*[-•]\s+/.test(line)) {
            let j = i;
            let html = '<ul>';
            while (j < lines.length && /^\s*[-•]\s+/.test(lines[j])) {
              const txt = lines[j].replace(/^\s*[-•]\s+/, '').replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>').replace(/\*(.+?)\*/g, '<em>$1</em>');
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
              const txt = lines[j].replace(/^\s*\d+\.\s+/, '').replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>').replace(/\*(.+?)\*/g, '<em>$1</em>');
              html += `<li>${txt}</li>`;
              j++;
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
          const ptxt = para.join(' ').replace(/\s+/g,' ').trim().replace(/\*\*(.+?)\*\*/g,'<strong>$1</strong>').replace(/\*(.+?)\*/g,'<em>$1</em>');
          parts.push(`<p>${ptxt}</p>`);
          i = j;
          continue;
          parts.push(line);
          i++;
        }
        let h = parts.join('\n');
        return h;
      }

      async function buildModulAjar() {
        const M = state.modulAjar || {};
        const req = [M.namaGuru,M.institusi,M.jenjang,M.mapel,M.judulModul,M.jumlahPertemuan,M.durasi,M.modelPembelajaran];
        const errEl = () => document.getElementById('maError');
        const showErr = (msg) => { const e=errEl(); if(e){e.textContent='⚠️ '+msg; e.classList.remove('hidden');} };

        if (req.some(v=>!String(v||'').trim())) { showErr('Harap lengkapi semua field sebelum generate.'); return; }
        if (!Array.isArray(M.dimensi)||M.dimensi.length===0) { showErr('Pilih minimal 1 Dimensi Profil Lulusan.'); return; }

        state.modulAjar.isGenerating = true;
        state.modulAjar.hasil = '';
        render();

        const sys = `Anda adalah pakar desainer kurikulum Indonesia yang ahli dalam Kurikulum Merdeka 2025 dan Pembelajaran Mendalam (Deep Learning). Buat Modul Ajar lengkap mengikuti format resmi Kemendikbudristek. Tulis dalam Bahasa Indonesia baku dan formal. Hasilkan konten LENGKAP, DETAIL, SIAP PAKAI — tidak boleh ada placeholder. Rubrik wajib skala 1–4.`;

        const usr = `Buatkan Modul Ajar LENGKAP dengan data berikut:

=== DATA INPUT ===
Nama Guru         : ${M.namaGuru}
Institusi         : ${M.institusi}
Tahun             : ${new Date().getFullYear()}
Kurikulum         : ${M.kurikulum||'Kurikulum Merdeka'}
Jenjang           : ${M.jenjang}
Kelas             : ${M.kelas||'-'}
Fase              : ${M.fase||'-'}
Mata Pelajaran    : ${M.mapel}
Judul Modul       : ${M.judulModul}
Jumlah Pertemuan  : ${M.jumlahPertemuan}
Durasi/Pertemuan  : ${M.durasi} menit
Model Pembelajaran: ${M.modelPembelajaran}
Jumlah Siswa      : ${M.jumlahSiswa||'30'} siswa
Dimensi Profil    : ${M.dimensi.join(', ')}
=================

Hasilkan Modul Ajar dengan SEMUA bagian berikut secara LENGKAP dan DETAIL:

## MODUL AJAR ${M.mapel.toUpperCase()}
### "${M.judulModul}"

## A. INFORMASI UMUM
Tabel 2 kolom (Komponen | Keterangan): Nama Penyusun, Institusi, Tahun, Jenjang, Kelas, Fase, Alokasi Waktu, Kompetensi Awal (2-3 kalimat), Dimensi Profil Lulusan (tiap dimensi 1-2 kalimat kontekstual), Sarana dan Prasarana, Target Peserta Didik, Model Pembelajaran.

## B. KOMPONEN INTI

### 1. Tujuan Pembelajaran
Min. 4 tujuan. Format: "Peserta didik mampu [kata kerja Bloom] [objek] [kondisi/kriteria]"

### 2. Kriteria Ketercapaian Tujuan Pembelajaran (KKTP)
Min. 4 indikator konkret dan terukur.

### 3. Asesmen
a. Diagnostik (Awal) — aktivitas konkret
b. Formatif (Proses) — cara guru memantau
c. Sumatif (Akhir) — produk/instrumen penilaian

### 4. Pertanyaan Pemantik
3 pertanyaan open-ended, kontekstual, mendorong berpikir kritis.

### 5. Kegiatan Pembelajaran
Untuk SETIAP pertemuan buat tabel: Kegiatan | Deskripsi | Alokasi Waktu
Struktur: Pendahuluan (~15%) dengan Mindful Learning, Inti (~70%) dengan fase ${M.modelPembelajaran}, Penutup (~15%).

### 6. Refleksi
2–3 pertanyaan refleksi untuk Peserta Didik dan 2–3 untuk Pendidik.

## C. LAMPIRAN

### 1. LKPD
Judul, Identitas siswa, Tujuan, Petunjuk, Alat dan Bahan, min. 5 Langkah Kegiatan, 3 Pertanyaan Refleksi, Kolom Kesimpulan.

### 2. Pengayaan dan Remedial
Pengayaan konkret. Remedial dengan strategi spesifik.

### 3. Bahan Bacaan
Untuk Peserta Didik: 3–4 paragraf sesuai jenjang.
          Untuk Pendidik: 3–4 paragraf panduan pedagogis Deep Learning dan ${M.modelPembelajaran}.

### 4. Media Pembelajaran
Sumber video YouTube relevan, alat peraga, platform digital.

### 5. Glosarium
Min. 5 istilah kunci dengan definisi sesuai jenjang.

### 6. Rubrik Penilaian
Tabel: Aspek | Skor 4 (Sangat Baik) | Skor 3 (Baik) | Skor 2 (Cukup) | Skor 1 (Perlu Bimbingan)
Min. 5 aspek, deskripsi KONKRET dan DAPAT DIAMATI.

### 7. Daftar Pustaka
Min. 3 referensi format APA (1 Kemendikbudristek, 1 buku pedagogi, 1 lainnya).

PENTING: Tidak ada placeholder. Semua konten kontekstual untuk ${M.mapel} kelas ${M.kelas||M.fase}. Bahasa Indonesia baku.`;

        try {
          const ctrl = new AbortController();
          const timer = setTimeout(()=>ctrl.abort(), 90000);
          const resp = await fetch("api/openai_proxy.php", {
            method: "POST",
            headers: {"Content-Type":"application/json"},
            body: JSON.stringify({ type:"modul_ajar", messages:[{role:"system",content:sys},{role:"user",content:usr}], model:OPENAI_MODEL }),
            signal: ctrl.signal,
          });
          clearTimeout(timer);
          if (!resp.ok) throw new Error(`Proxy ${resp.status}: ${await resp.text()}`);
          const data = await resp.json();
          const text = data?.content || data?.choices?.[0]?.message?.content || '';
          if (!text) throw new Error("Respons API kosong.");
          state.modulAjar.hasil = text;
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
              modulAjar: { ...M, hasil: text },
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
              body: JSON.stringify({ type: "add_tokens", input_tokens: usageIn, output_tokens: usageOut })
            });
            await fetch("api/openai_proxy.php", {
              method: "POST",
              headers: {"Content-Type":"application/json"},
              body: JSON.stringify({ type: "decrement_package" })
            });
            try { await computeStats(); } catch {}
          } catch {}
        } catch(e) {
          state.modulAjar.isGenerating = false;
          render();
          setTimeout(()=>{ const el=document.getElementById('maError'); if(el){el.textContent='⚠️ Gagal: '+e.message; el.classList.remove('hidden');} }, 120);
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
                  Footer,PageNumber } = docx;

          const FONT='Times New Roman', SZ=22, CW=9638;
          const bdr={style:BorderStyle.SINGLE,size:4,color:'999999'};
          const borders={top:bdr,bottom:bdr,left:bdr,right:bdr};
          const sp=(b=60,a=60)=>({spacing:{before:b,after:a}});

          function parseContent(raw) {
            const lines = raw.split('\n');
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
                    const txt=(row[ci]||'').trim().replace(/\*\*/g,'');
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
              if (line.match(/^\|[-|: ]+\|?$/)) continue;
              if (line.trim().startsWith('|')) {
                inTbl=true;
                tblRows.push(line.split('|').slice(1,-1).map(c=>c.trim()));
                continue;
              }
              if (inTbl) flushTbl();
              if (!line.trim()) { out.push(new Paragraph({...sp()})); continue; }
              if (/^#\s*/.test(line)) {
                out.push(new Paragraph({...sp(280,120),children:[new TextRun({text:line.replace(/^#\s*/,''),font:FONT,size:28,bold:true})]}));
              } else if (/^##\s*/.test(line)) {
                out.push(new Paragraph({...sp(240,100),children:[new TextRun({text:line.replace(/^##\s*/,''),font:FONT,size:26,bold:true})]}));
              } else if (/^###\s*/.test(line)) {
                out.push(new Paragraph({...sp(180,80),children:[new TextRun({text:line.replace(/^###\s*/,''),font:FONT,size:24,bold:true})]}));
              } else if (/^####\s*/.test(line)) {
                out.push(new Paragraph({...sp(140,60),children:[new TextRun({text:line.replace(/^####\s*/,''),font:FONT,size:SZ,bold:true})]}));
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

          // Hapus judul ganda dari konten jika sudah ada di M.hasil
          let contentText = String(M.hasil || '');
          contentText = contentText.replace(/^\s*#{1,3}\s*MODUL AJAR[^\n]*\n?/i, '');
          contentText = contentText.replace(/^\s*#{1,3}\s*["“][^\n"”]+["”]\s*\n?/i, '');

          const children = [
            new Paragraph({alignment:AlignmentType.CENTER,...sp(200,60),
              children:[new TextRun({text:`MODUL AJAR ${(M.mapel||'').toUpperCase()}`,font:FONT,size:28,bold:true})]}),
            new Paragraph({alignment:AlignmentType.CENTER,...sp(0,280),
              children:[new TextRun({text:`"${M.judulModul||''}"`,font:FONT,size:24,italics:true})]}),
            ...parseContent(contentText)
          ];

          const doc2 = new Document({
            styles:{default:{document:{run:{font:FONT,size:SZ}}}},
            sections:[{
              properties:{page:{size:{width:11906,height:16838},margin:{top:1134,right:1134,bottom:1134,left:1134}}},
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
          `<div class="h-px bg-border-light dark:bg-border-dark my-2"></div>
           ${baseBtn('dark_mode','Tema','', 'id="btnTheme" onclick="toggleTheme()"')}
           ${baseLink('profile.php','account_circle','Profil')}
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
        if (state.activeView === "identitas") return renderIdentitas();
        if (state.activeView === "konfigurasi") return renderKonfigurasi();
        if (state.activeView === "preview") {
          if (state._isGenerating) return generatingPreviewHtml();
          const parts = [renderNaskah()];
          if (state.previewFlags?.kunci) parts.push(`<div class="my-6 border-t border-dashed border-gray-300"></div><div style="break-before: page; page-break-before: always;"></div><div class="mt-10">${renderKunci()}</div>`);
          if (state.previewFlags?.kisi) parts.push(`<div class="my-6 border-t border-dashed border-gray-300"></div><div style="break-before: page; page-break-before: always;"></div><div class="mt-10">${renderKisi()}</div>`);
          return parts.join("");
        }
        if (state.activeView === "lkpd") return renderLKPD();
        if (state.activeView === "modul_ajar") return renderModulAjar();
        if (state.activeView === "quiz") return renderQuizLanding();
        if (state.activeView === "riwayat") return renderRiwayat();
        return renderIdentitas();
      };

      const render = async () => {
        await buildNavAndTabs();
        computeStats();
        const view = VIEWS.find((v) => v.id === state.activeView) || VIEWS[0];
        el("pageTitle").textContent = view.label;
        el("pageDesc").textContent =
          {
            identitas: "Lengkapi identitas sebelum menyusun paket",
            konfigurasi: "Atur bentuk, jumlah, kesulitan, dimensi, dan gambar per bagian",
            preview: "Naskah soal, dilengkapi kunci jawaban dan kisi-kisi",
            lkpd: "Generator LKPD otomatis sesuai tema aplikasi",
            modul_ajar: "Generator Modul Ajar Kurikulum Merdeka 2025 · Deep Learning",
            quiz: "Mode kuis interaktif untuk kelas",
            riwayat: "Riwayat paket soal yang tersimpan",
          }[state.activeView] || "";
        const root = el("viewRoot");
        root.innerHTML = computeView();
        wireInputs(root);
        if (state.activeView === "riwayat") {
          loadRiwayat();
        }
      };

      const renderIdentitas = () => {
        const i = state.identity;
        const p = state.paket;
        return `
          <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div class="lg:col-span-2 bg-surface-light dark:bg-surface-dark rounded-xl border border-border-light dark:border-border-dark shadow-sm overflow-hidden">
              <div class="p-6 space-y-6">
                <div class="flex items-center justify-between gap-3">
                  <div>
                    <div class="text-xs font-bold text-primary bg-primary/10 inline-flex px-3 py-1 rounded-full">Langkah 1</div>
                    <div class="text-xl font-bold mt-2">Identitas Soal</div>
                    <div class="text-sm text-text-sub-light dark:text-text-sub-dark mt-1">Data identitas disimpan otomatis</div>
                  </div>
                  <div class="flex gap-2">
                    <label class="btn-secondary h-10 px-4 rounded-lg cursor-pointer flex items-center gap-2 border border-border-light dark:border-border-dark text-sm font-bold bg-white dark:bg-surface-dark hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors">
                      <span class="material-symbols-outlined text-[18px]">upload</span>
                      <span>Muat</span>
                      <input type="file" accept=".json" class="hidden" onchange="loadProject(event)" />
                    </label>
                    <button class="btn-secondary h-10 px-4 rounded-lg flex items-center gap-2 border border-border-light dark:border-border-dark text-sm font-bold bg-white dark:bg-surface-dark hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors" onclick="saveProject()">
                      <span class="material-symbols-outlined text-[18px]">save</span>
                      <span>Simpan</span>
                    </button>
                    <button
                      class="hidden md:flex items-center gap-2 rounded-lg h-10 px-4 bg-primary hover:bg-blue-600 text-primary-content text-sm font-bold shadow-sm transition-colors"
                      onclick="window.__sp.setView('konfigurasi')"
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
                  ${selectField("Jenjang", "identity.jenjang", i.jenjang, ["SD/MI", "SMP/MTs", "SMA/MA", "SMK"])}
                  ${selectField("Fase", "identity.fase", i.fase, ["Fase A", "Fase B", "Fase C", "Fase D", "Fase E", "Fase F"])}
                  ${selectField("Kelas", "identity.kelas", i.kelas, CLASS_OPTIONS[i.jenjang] || [])}
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                  ${selectField("Mata Pelajaran", "identity.mataPelajaran", i.mataPelajaran, SUBJECT_OPTIONS[i.jenjang] || [])}
                  ${selectField("Jenis Topik", "identity.jenisTopik", i.jenisTopik || "spesifik", ["spesifik", "campuran"])}
                </div>
                <div class="grid grid-cols-1 gap-5">
                  ${i.jenisTopik === "campuran" 
                    ? `<div class="flex flex-col gap-2"><label class="text-sm font-semibold text-text-sub-light dark:text-text-sub-dark">Topik</label><div class="min-h-[44px] flex items-center px-4 text-sm text-gray-500 bg-gray-100 dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 italic">Otomatis berbagai tema</div></div>`
                    : inputTextarea("Topik / Lingkup Materi", "identity.topik", i.topik, "Contoh: Pecahan / Sistem Pernapasan")
                  }
                </div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-5 pt-2">
                  ${inputText("Judul Paket", "paket.judul", p.judul, "Contoh: Ulangan Harian")}
                  ${inputText("Semester", "paket.semester", p.semester, "Contoh: Semester 2")}
                  ${inputText("Tahun Ajaran", "paket.tahunAjaran", p.tahunAjaran, "Contoh: 2025/2026")}
                </div>
                
              </div>
              <div class="bg-background-light/50 dark:bg-background-dark/30 p-6 flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                <div class="text-xs text-text-sub-light dark:text-text-sub-dark">
                  Setelah lengkap, atur Konfigurasi (multi-bagian), lalu klik Buat Paket Soal.
                </div>
                <div class="flex gap-2">
                  <button
                    class="flex items-center gap-2 rounded-lg h-10 px-4 bg-white dark:bg-surface-dark border border-border-light dark:border-border-dark hover:bg-background-light dark:hover:bg-background-dark text-sm font-bold shadow-sm transition-colors"
                    onclick="window.__sp.setView('konfigurasi')"
                  >
                    <span class="material-symbols-outlined text-[18px]">tune</span>
                    Konfigurasi
                  </button>
                  <button id="btnBuild"
                    class="flex items-center gap-2 rounded-lg h-10 px-4 bg-primary hover:bg-blue-600 text-primary-content text-sm font-bold shadow-sm transition-colors"
                    onclick="window.__sp.buildPackage()"
                  >
                    <span class="material-symbols-outlined text-[18px]">bolt</span>
                    Buat Paket Soal
                  </button>
                </div>
              </div>
            </div>
          </div>
        `;
      };

      const estimateTotalQuestions = () =>
        state.sections.reduce((acc, s) => {
          if (s.bentuk === "pg") return acc + Number(s.jumlahPG || 0);
          if (s.bentuk === "isian") return acc + Number(s.jumlahIsian || 0);
          return acc + Number(s.jumlahPG || 0) + Number(s.jumlahIsian || 0);
        }, 0);

      const renderKonfigurasi = () => `
        <div class="space-y-6">
          <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div class="flex items-center gap-2">
              <span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-primary/10 text-primary">Langkah 2</span>
              <div class="text-sm text-text-sub-light dark:text-text-sub-dark">Multi-bagian (bertingkat) dalam satu paket</div>
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
                class="flex items-center gap-2 rounded-lg h-10 px-4 bg-primary hover:bg-blue-600 text-primary-content text-sm font-bold shadow-sm transition-colors"
                onclick="window.__sp.buildPackage()"
              >
                <span class="material-symbols-outlined text-[18px]">bolt</span>
                Buat Paket Soal
              </button>
            </div>
          </div>
          <div class="grid grid-cols-1 gap-5">
            ${state.sections.map((s, idx) => renderSectionCard(s, idx)).join("")}
          </div>
          <div class="bg-surface-light dark:bg-surface-dark rounded-xl border border-border-light dark:border-border-dark shadow-sm overflow-hidden">
            <div class="p-6 grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
              <div class="p-4 rounded-xl bg-background-light dark:bg-background-dark/30 border border-border-light dark:border-border-dark">
                <div class="text-xs text-text-sub-light dark:text-text-sub-dark">Total bagian</div>
                <div class="text-2xl font-bold mt-1">${state.sections.length}</div>
              </div>
              <div class="p-4 rounded-xl bg-background-light dark:bg-background-dark/30 border border-border-light dark:border-border-dark">
                <div class="text-xs text-text-sub-light dark:text-text-sub-dark">Perkiraan total soal</div>
                <div class="text-2xl font-bold mt-1">${estimateTotalQuestions()}</div>
              </div>
              <div class="p-4 rounded-xl bg-background-light dark:bg-background-dark/30 border border-border-light dark:border-border-dark">
                <div class="text-xs text-text-sub-light dark:text-text-sub-dark">Bagian pakai gambar</div>
                <div class="text-2xl font-bold mt-1">${state.sections.filter((x) => x.pakaiGambar).length}</div>
              </div>
            </div>
          </div>
        </div>
      `;

      const renderSectionCard = (s, idx) => {
        const diff = s.tingkatKesulitan || "campuran";
        const bloomPreset = s.cakupanBloom || "level_standar";
        
        const isObjective = ["pg", "pg_kompleks", "menjodohkan"].includes(s.bentuk);
        const isEssay = ["isian", "uraian"].includes(s.bentuk);

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

              <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <div class="flex flex-col gap-2 ${s.bentuk === "menjodohkan" || isEssay ? "hidden" : ""}">
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

      const renderQuizLanding = () => `
        <div class="bg-surface-light dark:bg-surface-dark rounded-xl border border-border-light dark:border-border-dark shadow-sm overflow-hidden">
          <div class="p-6 space-y-3">
            <div class="text-xl font-bold">Mode Kuis</div>
            <div class="text-sm text-text-sub-light dark:text-text-sub-dark">Jalankan kuis interaktif di kelas</div>
            <button onclick="openQuiz()" class="px-4 py-2 rounded-lg bg-primary text-white font-bold">Mulai</button>
          </div>
        </div>
      `;

      const openQuiz = () => {
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

      const buildPackage = async () => {
        autoFillPaket();
        // preflight limit check
        try {
          const res = await fetch("api/openai_proxy.php", { method: "POST", headers: {"Content-Type":"application/json"}, body: JSON.stringify({ type: "get_limits" }) });
          if (res.ok) {
            const limits = await res.json();
            if ((limits?.limitpaket ?? 0) <= 0) {
              alert("Batas pembuatan paket habis. Hubungi admin untuk menambah kuota.");
              return;
            }
          }
        } catch {}
        state.questions = [];
        state._isGenerating = true;
        saveDebounced(true);
        setView("preview");

        // loading UI will be rendered by computeView() based on _isGenerating flag

        const total = state.sections.reduce((acc, s) => {
          const isObjective = ["pg", "pg_kompleks", "menjodohkan"].includes(s.bentuk);
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
          const isObjective = ["pg", "pg_kompleks", "menjodohkan"].includes(sec.bentuk);
          const isEssay = ["isian", "uraian"].includes(sec.bentuk);
          const jumlahPG = Number(sec.jumlahPG || 0);
          const jumlahIsian = Number(sec.jumlahIsian || 0);
          const totalSec = isObjective ? jumlahPG : isEssay ? jumlahIsian : 0;
          if (totalSec === 0) continue;

          const opsi = clamp(Number(sec.opsiPG || 4), 3, 5);
          const bloomPreset = sec.cakupanBloom || "level_standar";
          const bloomCodes = bloomPresets[bloomPreset]?.codes || ["C1", "C2", "C3", "C4"];
          const bloomLabel = bloomPresets[bloomPreset]?.label || bloomPreset;
          const bagianLabel = sec.bentuk === "pg" ? "Pilihan Ganda" :
                              sec.bentuk === "pg_kompleks" ? "Pilihan Ganda Kompleks" :
                              sec.bentuk === "menjodohkan" ? "Menjodohkan" :
                              sec.bentuk === "isian" ? "Isian Singkat" : "Uraian";
          const topikText = state.identity.jenisTopik === "campuran" 
            ? `berbagai tema/topik yang sesuai untuk ${state.identity.mataPelajaran} tingkat ${state.identity.jenjang} kelas ${state.identity.kelas}`
            : `topik ${state.identity.topik || '-'} untuk ${state.identity.mataPelajaran} tingkat ${state.identity.jenjang} kelas ${state.identity.kelas}`;

          const promptBase = `Bertindaklah sebagai Guru Profesional.
Buatlah daftar soal untuk BAGIAN: ${bagianLabel}.

KONTEKS:
Jenjang: ${state.identity.jenjang} ${state.identity.fase} Kelas ${state.identity.kelas}
Mapel: ${state.identity.mataPelajaran}
Topik: ${topikText}

PARAMETER:
- Jumlah Soal: __JUMLAH__ butir.
- Tingkat Kesulitan: ${sec.tingkatKesulitan === 'campuran' ? 'Bervariasi (Mudah, Sedang, Sulit)' : sec.tingkatKesulitan}.
- Kognitif: ${bloomLabel} (${bloomCodes.join(', ')}).
- Opsi: ${opsi} pilihan (untuk PG).
- SEMUA item bertipe: ${sec.bentuk} (JANGAN buat tipe lain).

INSTRUKSI FORMAT MATEMATIKA (WAJIB PATUH):
1. JANGAN GUNAKAN FORMAT LATEX ($..$).
2. Gunakan tag HTML <sup> untuk Pangkat, <sub> untuk Indeks.
3. Gunakan simbol Unicode (√, ×, ÷, °, π).

INSTRUKSI FORMAT GAMBAR:
Jika soal membutuhkan gambar/diagram:
1. Prioritas 1: Buat diagram ASCII sederhana di field "asciiDiagram".
2. Prioritas 2: Buat kode SVG sederhana (hitam putih, viewBox minimal, tanpa width/height fixed) di field "svgSource".
3. Prioritas 3: Jika sangat kompleks, kosongkan ascii/svg dan isi "imagePrompt" untuk digenerate AI Image.

OUTPUT JSON (Array of Objects):
[
  {
    "type": "${sec.bentuk}",
    "question": "...",
    "options": ["..."],
    "answer": ... (sesuai tipe),
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

          let needed = totalSec;
          let attempts = 0;
          let batchSize = Math.min(GEN_BATCH_SIZE, needed);
          while (needed > 0 && attempts < GEN_MAX_ATTEMPTS) {
            try {
              const ask = Math.min(batchSize, needed);
              const prompt = promptBase.replace("__JUMLAH__", String(ask));
              const res = await callOpenAI(prompt);
              if (res && res._usage) {
                pkgTokenIn += Number(res._usage.in || 0);
                pkgTokenOut += Number(res._usage.out || 0);
              }
              let items = Array.isArray(res?.items) ? res.items : [];
              if (items.length > ask) items = items.slice(0, ask);
              for (const item of items) {
                const q = normalizeQuestion(item, sec);
                if (!q.question) continue;
                state.questions.push(q);
                needed--;
                updateGenProgress();
                if (needed <= 0) break;
              }
              // jika respon kurang dari ask, kecilkan batch untuk berjaga timeout/limit
              if (items.length < ask && batchSize > 1) {
                batchSize = Math.max(1, Math.floor(batchSize / 2));
              }
            } catch (e) {
              // timeout/502 → perkecil batch dan coba lagi
              console.warn("Gen batch error:", e?.message || e);
              batchSize = Math.max(1, Math.floor(batchSize / 2));
              await new Promise(r => setTimeout(r, 1000));
            } finally {
              attempts++;
            }
          }
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
            await fetch("api/openai_proxy.php", { method: "POST", headers: {"Content-Type":"application/json"}, body: JSON.stringify({ type: "decrement_package" }) });
          } catch {}
          try {
            await fetch("api/openai_proxy.php", {
              method: "POST",
              headers: {"Content-Type":"application/json"},
              body: JSON.stringify({ type: "add_tokens", input_tokens: pkgTokenIn, output_tokens: pkgTokenOut })
            });
          } catch {}
        } else {
          alert(`Paket tersusun ${actual} dari ${total}. Kuota tidak berkurang.`);
        }
      };

      const regenSingle = async (qId) => {
        const q = state.questions.find((x) => x.id === qId);
        if (!q) return;
        updateQuestionData(qId, { _loadingText: true });
        try {
          const prompt = `Buat ulang 1 butir soal sesuai detail berikut:
Jenis: ${q.type}
Tingkat Kesulitan: sedang
Bloom: ${q.bloom || 'C2'}
Materi: ${q.materi || '-'}
Instruksi:
1. Gunakan Bahasa Indonesia.
2. Jika PG, buat ${clamp(Number(state.sections.find(s=>s.id===q.sectionId)?.opsiPG || 4), 3, 5)} opsi.
3. Pastikan jawaban benar dan disertai penjelasan singkat.
4. Jika butuh gambar: Prioritas 1: "asciiDiagram", Prioritas 2: "svgSource", Prioritas 3: "imagePrompt".

OUTPUT JSON:
{
  "items": [
    {
      "type": "${q.type}",
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
        const res = await callOpenAI(prompt);
        const item = Array.isArray(res?.items) ? res.items[0] : null;
        if (!item) return updateQuestionData(qId, { _loadingText: false });
        const sec = state.sections.find((s) => s.id === q.sectionId) || {};
        const next = normalizeQuestion(item, sec);
        updateQuestionData(qId, {
          type: next.type,
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

      const regenImage = async (id) => {
        const q = state.questions.find(x => x.id === id);
        if (!q) return;
        updateQuestionData(id, { _loadingImage: true, _imageError: null });
        try {
          try {
            const res = await fetch("api/openai_proxy.php", { method: "POST", headers: {"Content-Type":"application/json"}, body: JSON.stringify({ type: "get_limits" }) });
            if (res.ok) {
              const limits = await res.json();
              if ((limits?.limitgambar ?? 0) <= 0) {
                const promptExisting = String(q.imagePrompt || '').trim();
                if (promptExisting) {
                  updateQuestionData(id, { _showImagePrompt: true });
                } else {
                  const ctx = state.identity || {};
                  const base = String(q.materi || ctx.topik || ctx.mataPelajaran || '').trim();
                  const inferred = base ? `Ilustrasi edukatif garis sederhana hitam-putih, tanpa teks, latar putih. Topik: ${base}.` : `Ilustrasi edukatif garis sederhana hitam-putih, tanpa teks, latar putih. Topik: ${q.question || ''}.`;
                  updateQuestionData(id, { imagePrompt: inferred, _showImagePrompt: true, _imageError: null });
                }
                return;
              }
            }
          } catch {}
          const img = await generateImage(q.imagePrompt || q.question || 'diagram');
          if (!img) throw new Error('Gagal membuat gambar');
          updateQuestionData(id, { image: img });
        } catch (e) {
          const msg = String(e.message || e);
          if (msg.includes('403') || msg.toLowerCase().includes('habis')) {
            const promptExisting = String(q.imagePrompt || '').trim();
            if (promptExisting) {
              updateQuestionData(id, { _showImagePrompt: true, _imageError: null });
            } else {
              const ctx = state.identity || {};
              const base = String(q.materi || ctx.topik || ctx.mataPelajaran || '').trim();
              const inferred = base ? `Ilustrasi edukatif garis sederhana hitam-putih, tanpa teks, latar putih. Topik: ${base}.` : `Ilustrasi edukatif garis sederhana hitam-putih, tanpa teks, latar putih. Topik: ${q.question || ''}.`;
              updateQuestionData(id, { imagePrompt: inferred, _showImagePrompt: true, _imageError: null });
            }
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
            autoFillPaket();
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
      async function ocrImageToText(file) {
        if (!window.Tesseract) throw new Error("OCR tidak tersedia");
        const result = await Tesseract.recognize(file, "eng");
        return String(result?.data?.text || "");
      }
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
        const jenjang = String(L.jenjang || I.jenjang || "-");
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
            body: JSON.stringify({ type: "add_tokens", input_tokens: usageIn, output_tokens: usageOut })
          });
          await fetch("api/openai_proxy.php", {
            method: "POST",
            headers: {"Content-Type":"application/json"},
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
        const originalText = btnExport.innerHTML;
        const originalDisabled = btnExport.disabled;
  
        try {
            btnExport.innerHTML = `<span class="animate-spin material-symbols-outlined text-[18px]">progress_activity</span> Proses...`;
            btnExport.disabled = true;
  
            const { Document, Packer, Paragraph, TextRun, AlignmentType, Table, TableRow, TableCell, WidthType, BorderStyle, ImageRun } = docx;
  
            const processedQuestions = await Promise.all(state.questions.map(async (q) => {
              let imgData = null;
              if (q.image) {
                try {
                  const resp = await fetch(q.image);
                  const blob = await resp.blob();
                  const buffer = await blob.arrayBuffer();
                  const dimensions = await new Promise((resolve) => {
                    const img = new Image();
                    img.onload = () => resolve({ width: img.naturalWidth, height: img.naturalHeight });
                    img.onerror = () => resolve({ width: 300, height: 300 });
                    img.src = URL.createObjectURL(blob);
                  });
                  const maxWidth = 100;
                  const ratio = dimensions.width / dimensions.height;
                  const width = Math.min(maxWidth, dimensions.width);
                  const height = width / ratio;
                  imgData = { buffer, width, height };
                } catch (e) {
                  console.error("Failed to load image for docx", e);
                }
              }
              return { ...q, _img: imgData };
            }));
  
            let logoRun = state.identity.logo ? await makeImageRunFromDataUrl(state.identity.logo, 96, 96) : null;
            const headerTitle = new Table({
              width: { size: 100, type: WidthType.PERCENTAGE },
              borders: { top: { style: BorderStyle.NONE }, bottom: { style: BorderStyle.NONE }, left: { style: BorderStyle.NONE }, right: { style: BorderStyle.NONE }, insideVertical: { style: BorderStyle.NONE }, insideHorizontal: { style: BorderStyle.NONE } },
              rows: [
                new TableRow({
                  children: [
                    new TableCell({
                      width: { size: 80, type: WidthType.PERCENTAGE },
                      borders: { top: { style: BorderStyle.NONE }, bottom: { style: BorderStyle.NONE }, left: { style: BorderStyle.NONE }, right: { style: BorderStyle.NONE } },
                      children: [
                        new Paragraph({
                          children: [
                            new TextRun({ text: (state.identity.namaSekolah || "NAMA SEKOLAH").toUpperCase(), bold: true, size: 28 }),
                            new TextRun({ text: "\n", break: 1 }),
                            new TextRun({ text: (state.paket.judul || "PENILAIAN AKHIR SEMESTER").toUpperCase(), bold: true, size: 24 }),
                            new TextRun({ text: "\n", break: 1 }),
                            new TextRun({ text: `Tahun Pelajaran ${state.paket.tahunAjaran}`, size: 20 }),
                          ],
                          alignment: AlignmentType.CENTER,
                          spacing: { after: 300 },
                        }),
                      ],
                    }),
                    new TableCell({
                      width: { size: 20, type: WidthType.PERCENTAGE },
                      borders: { top: { style: BorderStyle.NONE }, bottom: { style: BorderStyle.NONE }, left: { style: BorderStyle.NONE }, right: { style: BorderStyle.NONE } },
                      children: logoRun ? [new Paragraph({ children: [logoRun], alignment: AlignmentType.RIGHT })] : [new Paragraph({})],
                    }),
                  ],
                }),
              ],
            });
  
            const headerTable = new Table({
              width: { size: 100, type: WidthType.PERCENTAGE },
              borders: {
                top: { style: BorderStyle.SINGLE, size: 6, color: "000000" },
                bottom: { style: BorderStyle.SINGLE, size: 6, color: "000000" },
                left: { style: BorderStyle.SINGLE, size: 6, color: "000000" },
                right: { style: BorderStyle.SINGLE, size: 6, color: "000000" },
                insideVertical: { style: BorderStyle.SINGLE, size: 6, color: "000000" },
              },
              rows: [
                new TableRow({
                  children: [
                    new TableCell({
                      children: [
                        new Table({
                            width: { size: 100, type: WidthType.PERCENTAGE },
                            borders: { top: { style: BorderStyle.NONE }, bottom: { style: BorderStyle.NONE }, left: { style: BorderStyle.NONE }, right: { style: BorderStyle.NONE }, insideHorizontal: { style: BorderStyle.NONE }, insideVertical: { style: BorderStyle.NONE } },
                            rows: [
                                new TableRow({ children: [ new TableCell({ children: [new Paragraph({ text: "Mata Pelajaran" })], width: { size: 35, type: WidthType.PERCENTAGE } }), new TableCell({ children: [new Paragraph({ text: ": " + (state.identity.mataPelajaran || "-") })], width: { size: 65, type: WidthType.PERCENTAGE } }) ] }),
                                new TableRow({ children: [ new TableCell({ children: [new Paragraph({ text: "Kelas / Fase" })], width: { size: 35, type: WidthType.PERCENTAGE } }), new TableCell({ children: [new Paragraph({ text: ": " + (state.identity.kelas || "-") + " / " + (state.identity.fase || "-") })], width: { size: 65, type: WidthType.PERCENTAGE } }) ] }),
                                new TableRow({ children: [ new TableCell({ children: [new Paragraph({ text: "Hari / Tanggal" })], width: { size: 35, type: WidthType.PERCENTAGE } }), new TableCell({ children: [new Paragraph({ text: ": _______________________" })], width: { size: 65, type: WidthType.PERCENTAGE } }) ] }),
                            ]
                        })
                      ],
                      width: { size: 50, type: WidthType.PERCENTAGE },
                      borders: { top: { style: BorderStyle.NONE }, bottom: { style: BorderStyle.NONE }, left: { style: BorderStyle.NONE }, right: { style: BorderStyle.NONE } },
                      margins: { top: 100, bottom: 100, left: 100, right: 100 }
                    }),
                    new TableCell({
                      children: [
                        new Table({
                            width: { size: 100, type: WidthType.PERCENTAGE },
                            borders: { top: { style: BorderStyle.NONE }, bottom: { style: BorderStyle.NONE }, left: { style: BorderStyle.NONE }, right: { style: BorderStyle.NONE }, insideHorizontal: { style: BorderStyle.NONE }, insideVertical: { style: BorderStyle.NONE } },
                            rows: [
                                new TableRow({ children: [ new TableCell({ children: [new Paragraph({ text: "Waktu" })], width: { size: 35, type: WidthType.PERCENTAGE } }), new TableCell({ children: [new Paragraph({ text: ": _______________________" })], width: { size: 65, type: WidthType.PERCENTAGE } }) ] }),
                                new TableRow({ children: [ new TableCell({ children: [new Paragraph({ text: "Nama Siswa" })], width: { size: 35, type: WidthType.PERCENTAGE } }), new TableCell({ children: [new Paragraph({ text: ": _______________________" })], width: { size: 65, type: WidthType.PERCENTAGE } }) ] }),
                                new TableRow({ children: [ new TableCell({ children: [new Paragraph({ text: "No. Absen" })], width: { size: 35, type: WidthType.PERCENTAGE } }), new TableCell({ children: [new Paragraph({ text: ": _______________________" })], width: { size: 65, type: WidthType.PERCENTAGE } }) ] }),
                            ]
                        })
                      ],
                      width: { size: 50, type: WidthType.PERCENTAGE },
                      borders: { top: { style: BorderStyle.NONE }, bottom: { style: BorderStyle.NONE }, left: { style: BorderStyle.NONE }, right: { style: BorderStyle.NONE } },
                      margins: { top: 100, bottom: 100, left: 100, right: 100 }
                    }),
                  ],
                }),
              ],
            });
  
            const spacer = new Paragraph({ spacing: { after: 400 } });
            const questionParagraphs = [];
            const sections = [
              { type: 'pg', title: 'PILIHAN GANDA', subtitle: 'Pilihlah salah satu jawaban yang paling tepat!' },
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
              items.forEach((q, i) => {
                questionParagraphs.push(
                  new Paragraph({
                    children: [new TextRun({ text: `${i + 1}.\t${q.question}`, bold: false })],
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
                if (sec.type === 'pg' || sec.type === 'pg_kompleks') {
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
              });
            }
  
            const doc = new Document({
              sections: [{
                properties: { page: { margin: { top: 720, bottom: 720, left: 720, right: 720 } } },
                children: [headerTitle, headerTable, spacer, ...questionParagraphs],
              }],
            });
  
            const blob = await Packer.toBlob(doc);
            const link = document.createElement("a");
            link.href = URL.createObjectURL(blob);
            const safeMapel = (state.identity.mataPelajaran || "Soal").replace(/[^a-z0-9]/gi, '_');
            link.download = `SoalPintar_${safeMapel}.docx`;
            link.click();
        } catch (e) {
            console.error(e);
            alert("Gagal export docx: " + e.message);
        } finally {
            btnExport.innerHTML = originalText;
            btnExport.disabled = originalDisabled;
        }
      };

      const exportKunciDocx = async () => {
         if (state.questions.length === 0) return alert("Belum ada soal!");
        
        const btn = el("btnExportKunci");
        const originalText = btn.innerHTML;
        const originalDisabled = btn.disabled;
  
        try {
            btn.innerHTML = `<span class="animate-spin material-symbols-outlined text-[18px]">progress_activity</span> Proses...`;
            btn.disabled = true;
  
            const { Document, Packer, Paragraph, TextRun, AlignmentType, Table, TableRow, TableCell, WidthType, BorderStyle, ImageRun } = docx;
  
             let logoRun = state.identity.logo ? await makeImageRunFromDataUrl(state.identity.logo, 96, 96) : null;
             const headerTitle = new Table({
               width: { size: 100, type: WidthType.PERCENTAGE },
               borders: { top: { style: BorderStyle.NONE }, bottom: { style: BorderStyle.NONE }, left: { style: BorderStyle.NONE }, right: { style: BorderStyle.NONE }, insideVertical: { style: BorderStyle.NONE }, insideHorizontal: { style: BorderStyle.NONE } },
               rows: [
                 new TableRow({
                   children: [
                     new TableCell({
                       width: { size: 80, type: WidthType.PERCENTAGE },
                       borders: { top: { style: BorderStyle.NONE }, bottom: { style: BorderStyle.NONE }, left: { style: BorderStyle.NONE }, right: { style: BorderStyle.NONE } },
                       children: [
                         new Paragraph({
                           children: [
                             new TextRun({ text: (state.identity.namaSekolah || "NAMA SEKOLAH").toUpperCase(), bold: true, size: 28 }),
                             new TextRun({ text: "\n", break: 1 }),
                             new TextRun({ text: "KUNCI JAWABAN", bold: true, size: 24 }),
                             new TextRun({ text: "\n", break: 1 }),
                             new TextRun({ text: `Tahun Pelajaran ${state.paket.tahunAjaran}`, size: 20 }),
                           ],
                           alignment: AlignmentType.CENTER,
                           spacing: { after: 300 },
                         }),
                       ],
                     }),
                     new TableCell({
                       width: { size: 20, type: WidthType.PERCENTAGE },
                       borders: { top: { style: BorderStyle.NONE }, bottom: { style: BorderStyle.NONE }, left: { style: BorderStyle.NONE }, right: { style: BorderStyle.NONE } },
                       children: logoRun ? [new Paragraph({ children: [logoRun], alignment: AlignmentType.RIGHT })] : [new Paragraph({})],
                     }),
                   ],
                 }),
               ],
             });
  
             const leftRows = [
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
             ];
             if (state.identity.jenisTopik === "spesifik") {
               leftRows.push(
                 new TableRow({
                   children: [
                     new TableCell({ children: [new Paragraph({ children: [new TextRun({ text: "Topik / Lingkup Materi", bold: true })] })], width: { size: 40, type: WidthType.PERCENTAGE } }),
                     new TableCell({ children: [new Paragraph({ text: ":" })], width: { size: 5, type: WidthType.PERCENTAGE } }),
                     new TableCell({ children: [new Paragraph({ text: String(state.identity.topik || "-") })] }),
                   ],
                 })
               );
             }
             const leftInner = new Table({
               width: { size: 100, type: WidthType.PERCENTAGE },
               borders: { top: { style: BorderStyle.NONE }, bottom: { style: BorderStyle.NONE }, left: { style: BorderStyle.NONE }, right: { style: BorderStyle.NONE }, insideHorizontal: { style: BorderStyle.NONE }, insideVertical: { style: BorderStyle.NONE } },
               rows: leftRows,
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
                 top: { style: BorderStyle.SINGLE, size: 6, color: "000000" },
                 bottom: { style: BorderStyle.SINGLE, size: 6, color: "000000" },
                 left: { style: BorderStyle.SINGLE, size: 6, color: "000000" },
                 right: { style: BorderStyle.SINGLE, size: 6, color: "000000" },
                 insideVertical: { style: BorderStyle.SINGLE, size: 6, color: "000000" },
               },
               rows: [
                 new TableRow({
                   children: [
                     new TableCell({ children: [leftInner], margins: { top: 100, bottom: 100, left: 100, right: 100 } }),
                     new TableCell({ children: [rightInner], margins: { top: 100, bottom: 100, left: 100, right: 100 } }),
                   ],
                 }),
               ],
             });
  
            const spacer = new Paragraph({ spacing: { after: 400 } });
            const content = [];
            const sections = [
              { type: 'pg', title: 'PILIHAN GANDA' },
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
                if (sec.type === 'pg') {
                    const cols = 5;
                    const pgRows = [];
                    for(let i=0; i<items.length; i+=cols) {
                        const rowCells = [];
                        for(let j=0; j<cols; j++) {
                            if (i+j < items.length) {
                                const q = items[i+j];
                                let ansChar = "-";
                                if (typeof q.answer === 'number') ansChar = String.fromCharCode(65 + q.answer);
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
                            if (Array.isArray(q.answer)) {
                                ansText = q.answer.map((a, ai) => `${ai+1}->${a}`).join("; ");
                            } else {
                                ansText = String(q.answer);
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
            btn.innerHTML = originalText;
            btn.disabled = originalDisabled;
        }
      };

      const exportKisiDocx = async () => {
        if (state.questions.length === 0) return alert("Belum ada soal!");
        
        const btn = el("btnExportKisi");
        const originalText = btn.innerHTML;
        const originalDisabled = btn.disabled;
  
        try {
            btn.innerHTML = `<span class="animate-spin material-symbols-outlined text-[18px]">progress_activity</span> Proses...`;
            btn.disabled = true;
  
            const { Document, Packer, Paragraph, TextRun, AlignmentType, Table, TableRow, TableCell, WidthType, BorderStyle, ImageRun } = docx;
  
            let logoRun = state.identity.logo ? await makeImageRunFromDataUrl(state.identity.logo, 96, 96) : null;
            const headerTitle = new Table({
              width: { size: 100, type: WidthType.PERCENTAGE },
              borders: { top: { style: BorderStyle.NONE }, bottom: { style: BorderStyle.NONE }, left: { style: BorderStyle.NONE }, right: { style: BorderStyle.NONE }, insideVertical: { style: BorderStyle.NONE }, insideHorizontal: { style: BorderStyle.NONE } },
              rows: [
                new TableRow({
                  children: [
                    new TableCell({
                      width: { size: 80, type: WidthType.PERCENTAGE },
                      borders: { top: { style: BorderStyle.NONE }, bottom: { style: BorderStyle.NONE }, left: { style: BorderStyle.NONE }, right: { style: BorderStyle.NONE } },
                      children: [
                        new Paragraph({
                          children: [
                            new TextRun({ text: (state.identity.namaSekolah || "NAMA SEKOLAH").toUpperCase(), bold: true, size: 28 }),
                            new TextRun({ text: "\n", break: 1 }),
                            new TextRun({ text: "KISI-KISI SOAL", bold: true, size: 24 }),
                            new TextRun({ text: "\n", break: 1 }),
                            new TextRun({ text: `Tahun Pelajaran ${state.paket.tahunAjaran}`, size: 20 }),
                          ],
                          alignment: AlignmentType.CENTER,
                          spacing: { after: 300 },
                        }),
                      ],
                    }),
                    new TableCell({
                      width: { size: 20, type: WidthType.PERCENTAGE },
                      borders: { top: { style: BorderStyle.NONE }, bottom: { style: BorderStyle.NONE }, left: { style: BorderStyle.NONE }, right: { style: BorderStyle.NONE } },
                      children: logoRun ? [new Paragraph({ children: [logoRun], alignment: AlignmentType.RIGHT })] : [new Paragraph({})],
                    }),
                  ],
                }),
              ],
            });
  
            const leftRows = [
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
            ];
            if (state.identity.jenisTopik === "spesifik") {
              leftRows.push(
                new TableRow({
                  children: [
                    new TableCell({ children: [new Paragraph({ children: [new TextRun({ text: "Topik / Lingkup Materi", bold: true })] })], width: { size: 40, type: WidthType.PERCENTAGE } }),
                    new TableCell({ children: [new Paragraph({ text: ":" })], width: { size: 5, type: WidthType.PERCENTAGE } }),
                    new TableCell({ children: [new Paragraph({ text: String(state.identity.topik || "-") })] }),
                  ],
                })
              );
            }
            const leftInner = new Table({
              width: { size: 100, type: WidthType.PERCENTAGE },
              borders: { top: { style: BorderStyle.NONE }, bottom: { style: BorderStyle.NONE }, left: { style: BorderStyle.NONE }, right: { style: BorderStyle.NONE }, insideHorizontal: { style: BorderStyle.NONE }, insideVertical: { style: BorderStyle.NONE } },
              rows: leftRows,
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
                top: { style: BorderStyle.SINGLE, size: 6, color: "000000" },
                bottom: { style: BorderStyle.SINGLE, size: 6, color: "000000" },
                left: { style: BorderStyle.SINGLE, size: 6, color: "000000" },
                right: { style: BorderStyle.SINGLE, size: 6, color: "000000" },
                insideVertical: { style: BorderStyle.SINGLE, size: 6, color: "000000" },
              },
              rows: [
                new TableRow({
                  children: [
                    new TableCell({ children: [leftInner], margins: { top: 100, bottom: 100, left: 100, right: 100 } }),
                    new TableCell({ children: [rightInner], margins: { top: 100, bottom: 100, left: 100, right: 100 } }),
                  ],
                }),
              ],
            });
  
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
            const typeLabels = {
              pg: "PG",
              pg_kompleks: "PG Komp",
              menjodohkan: "Jodoh",
              isian: "Isian",
              uraian: "Uraian"
            };
  
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
  
            const doc = new Document({
                sections: [{
                    properties: { page: { margin: { top: 720, bottom: 720, left: 720, right: 720 } } },
                    children: [headerTitle, headerTable, spacer, kisiTable],
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
            btn.innerHTML = originalText;
            btn.disabled = originalDisabled;
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
                  : state.identity.jenisTopik === "spesifik"
                  ? [
                      new TableRow({
                        children: [
                          new TableCell({ children: [new Paragraph({ children: [new TextRun({ text: "Topik / Lingkup Materi", bold: true })] })], width: { size: 40, type: WidthType.PERCENTAGE } }),
                          new TableCell({ children: [new Paragraph({ text: ":" })], width: { size: 5, type: WidthType.PERCENTAGE } }),
                          new TableCell({ children: [new Paragraph({ text: String(state.identity.topik || "-") })] }),
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
                const buffer = await blob.arrayBuffer();
                const dimensions = await new Promise((resolve) => {
                  const img = new Image();
                  img.onload = () => resolve({ width: img.naturalWidth, height: img.naturalHeight });
                  img.onerror = () => resolve({ width: 300, height: 300 });
                  img.src = URL.createObjectURL(blob);
                });
                const maxWidth = 100;
                const ratio = dimensions.width / dimensions.height;
                const width = Math.min(maxWidth, dimensions.width);
                const height = width / ratio;
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
              items.forEach((q, i) => {
                questionParagraphs.push(
                  new Paragraph({
                    children: [new TextRun({ text: `${i + 1}.\t${q.question}`, bold: false })],
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
                if (sec.type === 'pg' || sec.type === 'pg_kompleks') {
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
              });
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
              if (sec.type === 'pg') {
                const cols = 5;
                const pgRows = [];
                for(let i=0; i<items.length; i+=cols) {
                  const rowCells = [];
                  for(let j=0; j<cols; j++) {
                    if (i+j < items.length) {
                      const q = items[i+j];
                      let ansChar = "-";
                      if (typeof q.answer === 'number') ansChar = String.fromCharCode(65 + q.answer);
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
          const doc = new Document({
            sections: [
              { properties: { page: { margin: { top: 720, bottom: 720, left: 720, right: 720 } } }, children: [naskah.headerTitle, naskah.headerTable, naskah.spacer, ...naskah.questionParagraphs] },
              { properties: { page: { margin: { top: 720, bottom: 720, left: 720, right: 720 } } }, children: [kunci.headerTitle, kunci.headerTable, kunci.spacer, ...kunci.content] },
              { properties: { page: { margin: { top: 720, bottom: 720, left: 720, right: 720 } } }, children: [kisi.headerTitle, kisi.headerTable, kisi.spacer, kisi.kisiTable] },
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
        setLkpdSource,
        buildLKPD,
        pickLkpdImage,
        pickLkpdText,
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
        // Modul Ajar
        setMA: (key, val, renderNow = false) => {
          if (!state.modulAjar) state.modulAjar = {};
          state.modulAjar[key] = val;
          if (key === 'jenjang') { state.modulAjar.fase=''; state.modulAjar.kelas=''; }
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
          saveDebounced(false);
          render();
        },
        buildModulAjar,
        exportModulAjarDocx,
      };

      const btnThemeEl = el("btnTheme");
      if (btnThemeEl) btnThemeEl.addEventListener("click", toggleTheme);
      
      const btnPrint = el("btnPrint");
      if (btnPrint) {
        btnPrint.addEventListener("click", () => {
          exportAllDocx();
        });
      }

      el("btnSave").addEventListener("click", saveProject);
      el("btnLoad").addEventListener("click", () => {
        el("projectPicker").value = "";
        el("projectPicker").click();
      });
      el("projectPicker").addEventListener("change", loadProject);
      el("btnReset").addEventListener("click", resetAll);
      const btnBuild = el("btnBuild");
      if (btnBuild) btnBuild.addEventListener("click", buildPackage);
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
      let historyItems = [];
      let historyPage = 1;
      const historyPageSize = 10;
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
      el("btnQuizRegen").addEventListener("click", async () => {
        const q = state.questions[state.quiz.idx];
        if (!q) return;
        await regenSingle(q.id);
        renderQuizContent();
      });
      el("btnQuizImgRegen").addEventListener("click", async () => {
        const q = state.questions[state.quiz.idx];
        if (!q) return;
        await regenImage(q.id);
        renderQuizContent();
      });

      if (load()) {
        applyTheme();
      } else {
        applyTheme();
        autoFillPaket();
      }
      render();
    </script>
  </body>
</html>
