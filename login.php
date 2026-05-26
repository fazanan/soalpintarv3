<?php
session_start(['read_and_close' => true]);
if (isset($_SESSION['user_id'])) {
  header('Location: index.php');
  exit;
}
$e = isset($_GET['e']) ? (string)$_GET['e'] : '';
$err = $e === 'busy' ? 'Akun sedang digunakan di perangkat lain. Silakan logout dari perangkat tersebut atau tunggu beberapa saat.' : ($e ? 'Login gagal' : '');
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Login | GuruPintar</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@400;500;600;700&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.47.0/tabler-icons.min.css" />
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      theme: {
        extend: {
          colors: {
            primary: "#185FA5",
            "primary-dark": "#0C447C",
            "primary-light": "#E6F1FB",
          },
          fontFamily: {
            sans: ['Lexend', 'sans-serif'],
          }
        }
      }
    };
  </script>
  <style>
    .modal-backdrop {
      animation: fadeIn 0.2s ease-out;
    }
    .modal-content {
      animation: slideUp 0.3s ease-out;
    }
    @keyframes fadeIn {
      from { opacity: 0; }
      to { opacity: 1; }
    }
    @keyframes slideUp {
      from { opacity: 0; transform: translateY(20px); }
      to { opacity: 1; transform: translateY(0); }
    }
  </style>
</head>
<body class="min-h-screen flex items-center justify-center bg-slate-50 font-sans p-4">

  <div class="w-full max-w-[420px]">

    <div class="text-center mb-7">
      <div class="text-3xl font-semibold mb-1">
        <span class="text-primary">Guru</span>Pintar
      </div>
      <div class="text-sm text-slate-600">Sahabat Pendidik Indonesia</div>
    </div>

    <div class="bg-white border border-slate-200 rounded-2xl p-7 sm:p-8">

      <div class="mb-6">
        <div class="text-lg font-semibold mb-1">Selamat datang kembali</div>
        <div class="text-sm text-slate-600">Masuk untuk lanjut membuat Administrasi Guru.</div>
      </div>

      <?php if ($err): ?>
        <div class="mb-5 flex items-start gap-2 p-3 bg-red-50 border border-red-200 rounded-lg">
          <i class="ti ti-alert-circle text-red-600 flex-shrink-0 mt-0.5" style="font-size: 18px;"></i>
          <div class="text-sm text-red-700 leading-relaxed"><?=$err?></div>
        </div>
      <?php endif; ?>

      <form method="post" action="login_handle.php" class="space-y-4">

        <div>
          <label for="username" class="block text-sm font-medium text-slate-700 mb-2">Email atau No. HP</label>
          <input 
            id="username"
            name="username" 
            required 
            placeholder="guru@email.com atau 08xxxx" 
            class="w-full h-11 px-3.5 text-[15px] border border-slate-300 rounded-lg outline-none focus:border-primary focus:ring-2 focus:ring-primary/20 transition-all" 
            autocomplete="username" />
        </div>

        <div>
          <div class="flex items-center justify-between mb-2">
            <label for="password" class="block text-sm font-medium text-slate-700">Password</label>
            <button 
              type="button"
              onclick="openLupaPasswordModal()"
              class="text-xs text-primary hover:underline inline-flex items-center gap-1 cursor-pointer bg-transparent border-0 p-0">
              <i class="ti ti-brand-whatsapp" style="font-size: 14px;"></i>
              Lupa password?
            </button>
          </div>
          <div class="relative">
            <input 
              id="password"
              type="password" 
              name="password" 
              required 
              placeholder="Masukkan password"
              class="w-full h-11 px-3.5 pr-11 text-[15px] border border-slate-300 rounded-lg outline-none focus:border-primary focus:ring-2 focus:ring-primary/20 transition-all" 
              autocomplete="current-password" />
            <button 
              type="button" 
              id="togglePassword"
              class="absolute right-2 top-1/2 -translate-y-1/2 w-8 h-8 flex items-center justify-center text-slate-400 hover:text-slate-600 transition-colors"
              aria-label="Tampilkan password">
              <i class="ti ti-eye" id="iconEye" style="font-size: 18px;"></i>
              <i class="ti ti-eye-off hidden" id="iconEyeOff" style="font-size: 18px;"></i>
            </button>
          </div>
        </div>

        <button 
          type="submit"
          class="w-full h-12 bg-primary hover:bg-primary-dark text-white text-[15px] font-semibold rounded-lg transition-colors mt-2">
          Masuk
        </button>

      </form>

      <div class="mt-5 pt-5 border-t border-slate-100 text-center text-sm text-slate-600">
        Belum punya akun?
        <a href="register.php" class="text-primary font-semibold hover:underline ml-1">Daftar di sini</a>
      </div>

    </div>

    <div class="mt-4 flex items-start gap-2 p-3 bg-blue-50 border border-blue-100 rounded-lg">
      <i class="ti ti-info-circle text-blue-600 flex-shrink-0 mt-0.5" style="font-size: 17px;"></i>
      <div class="text-xs text-blue-800 leading-relaxed">
        Satu akun hanya untuk 1 perangkat. Klik <strong>Keluar</strong> di menu untuk pindah perangkat.
      </div>
    </div>

  </div>

  <div 
    id="lupaPasswordModal" 
    class="hidden fixed inset-0 z-50 modal-backdrop"
    role="dialog"
    aria-modal="true"
    aria-labelledby="modalTitle">
    
    <div 
      class="absolute inset-0 bg-black/50" 
      onclick="closeLupaPasswordModal()"></div>
    
    <div class="absolute inset-0 flex items-center justify-center p-4">
      <div class="modal-content bg-white rounded-2xl max-w-[420px] w-full p-6 sm:p-7 relative shadow-xl">
        
        <button 
          type="button"
          onclick="closeLupaPasswordModal()"
          class="absolute right-4 top-4 w-8 h-8 flex items-center justify-center text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded-lg transition-colors"
          aria-label="Tutup">
          <i class="ti ti-x" style="font-size: 18px;"></i>
        </button>

        <div class="flex items-start gap-3 mb-4">
          <div class="w-11 h-11 bg-green-100 rounded-full flex items-center justify-center flex-shrink-0">
            <i class="ti ti-brand-whatsapp text-green-700" style="font-size: 24px;"></i>
          </div>
          <div class="flex-1 pr-6">
            <div id="modalTitle" class="text-lg font-semibold mb-1">Reset password via WhatsApp</div>
            <div class="text-sm text-slate-600 leading-relaxed">
              Anda akan diarahkan ke WhatsApp Admin untuk membantu reset password akun Anda.
            </div>
          </div>
        </div>

        <div class="bg-slate-50 rounded-lg p-3.5 mb-4">
          <div class="text-xs font-semibold text-slate-700 uppercase tracking-wide mb-2">Yang perlu disiapkan</div>
          <div class="space-y-1.5 text-sm text-slate-700">
            <div class="flex items-start gap-2">
              <i class="ti ti-check text-green-600 flex-shrink-0 mt-0.5" style="font-size: 16px;"></i>
              <span>Email atau No. HP yang terdaftar</span>
            </div>
            <div class="flex items-start gap-2">
              <i class="ti ti-check text-green-600 flex-shrink-0 mt-0.5" style="font-size: 16px;"></i>
              <span>Nama lengkap atau nama sekolah (untuk verifikasi)</span>
            </div>
          </div>
        </div>

        <div class="flex items-start gap-2 p-3 bg-blue-50 border border-blue-100 rounded-lg mb-5">
          <i class="ti ti-clock text-blue-600 flex-shrink-0 mt-0.5" style="font-size: 17px;"></i>
          <div class="text-xs text-blue-800 leading-relaxed">
            Admin biasanya membalas dalam <strong>5-30 menit</strong> di jam kerja (Senin-Jumat, 08.00-17.00 WIB).
          </div>
        </div>

        <div class="flex gap-2">
          <button 
            type="button"
            onclick="closeLupaPasswordModal()"
            class="flex-1 h-11 px-4 text-sm font-semibold text-slate-700 bg-white border border-slate-300 hover:bg-slate-50 rounded-lg transition-colors">
            Batal
          </button>
          <a 
            href="https://wa.me/6282174028646?text=Halo%20Admin%2C%20saya%20lupa%20password%20akun%20GuruPintar.%20Mohon%20bantu%20reset.%0A%0AEmail%20atau%20No.%20HP%20saya%3A%20"
            target="_blank"
            rel="noopener"
            class="flex-[2] h-11 px-4 inline-flex items-center justify-center gap-2 text-sm font-semibold text-white bg-green-600 hover:bg-green-700 rounded-lg transition-colors">
            <i class="ti ti-brand-whatsapp" style="font-size: 18px;"></i>
            Hubungi Admin
          </a>
        </div>

      </div>
    </div>
  </div>

  <script>
    document.getElementById('togglePassword').addEventListener('click', function() {
      const passwordInput = document.getElementById('password');
      const iconEye = document.getElementById('iconEye');
      const iconEyeOff = document.getElementById('iconEyeOff');
      
      if (passwordInput.type === 'password') {
        passwordInput.type = 'text';
        iconEye.classList.add('hidden');
        iconEyeOff.classList.remove('hidden');
        this.setAttribute('aria-label', 'Sembunyikan password');
      } else {
        passwordInput.type = 'password';
        iconEye.classList.remove('hidden');
        iconEyeOff.classList.add('hidden');
        this.setAttribute('aria-label', 'Tampilkan password');
      }
    });

    function openLupaPasswordModal() {
      const modal = document.getElementById('lupaPasswordModal');
      modal.classList.remove('hidden');
      document.body.style.overflow = 'hidden';
      
      setTimeout(function() {
        const adminBtn = modal.querySelector('a[href*="wa.me"]');
        if (adminBtn) adminBtn.focus();
      }, 100);
    }

    function closeLupaPasswordModal() {
      const modal = document.getElementById('lupaPasswordModal');
      modal.classList.add('hidden');
      document.body.style.overflow = '';
    }

    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape') {
        const modal = document.getElementById('lupaPasswordModal');
        if (!modal.classList.contains('hidden')) {
          closeLupaPasswordModal();
        }
      }
    });

    window.addEventListener('DOMContentLoaded', function() {
      const usernameInput = document.getElementById('username');
      if (usernameInput && !usernameInput.value) {
        usernameInput.focus();
      }
    });
  </script>
  </div>
</body>
