/* Login berupa popup (bukan halaman awal).
   Halaman dibuka bebas sebagai tamu. Begitu tamu menekan tombol/tautan/kolom apa pun,
   klik itu ditahan dan popup login muncul. Setelah berhasil masuk, aksi yang tadi
   ditekan dilanjutkan otomatis.
   Elemen yang diberi atribut data-publik (tombol tema & sidebar) tidak perlu login. */
(function () {
    'use strict';
    const SESSION_KEY = 'kampuslyCurrentUser';
    const DEMO_EMAIL = 'admin@tsu.ac.id';
    const DEMO_PASSWORD = 'tsu12345';

    // Yang dianggap "ditekan": tombol, tautan, kolom isian, kartu, dan sejenisnya.
    const SEL = 'a[href], button, select, input, textarea, summary, label, [role="button"], .kartu-klik';

    function user() {
        try {
            const u = JSON.parse(sessionStorage.getItem(SESSION_KEY) || 'null');
            return u && u.email === DEMO_EMAIL ? u : null;
        } catch (e) { return null; }
    }
    const toast = (m, galat) => { if (window.App && App.toast) App.toast(m, galat); };

    let dlg = null, pesan = null, tombol = null, tertahan = null;

    function bangun() {
        dlg = document.createElement('dialog');
        dlg.className = 'login-dialog';
        dlg.setAttribute('aria-labelledby', 'lgJudul');
        dlg.innerHTML =
            '<button type="button" class="lg-tutup" aria-label="Tutup">&times;</button>' +
            '<div class="lg-brand">' +
                '<div class="lg-mark"><svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/></svg></div>' +
                '<span class="lg-name">Kampusly</span>' +
            '</div>' +
            '<h2 id="lgJudul">Masuk ke akunmu</h2>' +
            '<p class="lg-sub">Masuk dulu untuk melanjutkan.</p>' +
            '<button type="button" class="lg-sso">' +
                '<svg viewBox="0 0 48 48"><path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3C33.9 32.4 29.4 35.5 24 35.5 16.5 35.5 10.5 29.5 10.5 22S16.5 8.5 24 8.5c3.6 0 6.8 1.4 9.3 3.6l5.7-5.7C35.6 3 30.1 1 24 1 11.8 1 2 10.8 2 23s9.8 22 22 22c11.3 0 21-8.2 21-22 0-1.5-.2-2.5-.4-3.5z"/><path fill="#FF3D00" d="M6.3 14.7l6.6 4.8C14.6 15.8 19 12.5 24 12.5c3.6 0 6.8 1.4 9.3 3.6l5.7-5.7C35.6 6.5 30.1 4.5 24 4.5c-7.4 0-13.8 4.1-17.1 10.2z"/><path fill="#4CAF50" d="M24 44.5c6 0 11.3-2 15-5.6l-6.9-5.7c-2 1.5-4.7 2.3-8.1 2.3-5.3 0-9.8-3.6-11.3-8.5l-6.7 5.2C9.2 39.8 16 44.5 24 44.5z"/><path fill="#1976D2" d="M43.6 20.5H24v8h11.3c-.9 2.9-2.9 5.1-5.4 6.7l6.9 5.7C41 37.5 44 30.9 44 23c0-1.5-.2-2.5-.4-2.5z"/></svg>' +
                'Google' +
            '</button>' +
            '<div class="lg-divider">atau</div>' +
            '<p class="lg-pesan" role="alert" aria-live="polite"></p>' +
            '<form novalidate>' +
                '<div class="lg-field"><label for="lgEmail">Alamat email</label><div class="lg-wrap">' +
                    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M22 6l-10 7L2 6"/></svg>' +
                    '<input type="email" id="lgEmail" placeholder="nama@contoh.com" autocomplete="email"></div></div>' +
                '<div class="lg-field"><label for="lgSandi">Kata sandi</label><div class="lg-wrap">' +
                    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="10" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>' +
                    '<input type="password" id="lgSandi" placeholder="Masukkan kata sandi" autocomplete="current-password"></div></div>' +
                '<div class="lg-lupa"><button type="button" class="lg-link">Lupa kata sandi?</button></div>' +
                '<button type="submit" class="lg-masuk"><span>Masuk</span><span class="lg-spin" aria-hidden="true"></span></button>' +
            '</form>' +
            '<p class="lg-catatan">Akun tunggal untuk prototipe demo ini.</p>' +
            '<p class="lg-demo">Akun demo: <strong>' + DEMO_EMAIL + '</strong><br>Kata sandi: <strong>' + DEMO_PASSWORD + '</strong></p>';
        document.body.appendChild(dlg);

        pesan = dlg.querySelector('.lg-pesan');
        tombol = dlg.querySelector('.lg-masuk');

        dlg.querySelector('.lg-tutup').addEventListener('click', () => dlg.close());
        // Klik di luar kartu (area gelap) menutup popup
        dlg.addEventListener('click', (e) => {
            const r = dlg.getBoundingClientRect();
            if (e.clientX < r.left || e.clientX > r.right || e.clientY < r.top || e.clientY > r.bottom) dlg.close();
        });
        dlg.addEventListener('close', () => { tertahan = null; tombol.classList.remove('loading'); });

        dlg.querySelector('.lg-sso').addEventListener('click', () =>
            tampil('Login Google belum dihubungkan pada prototipe ini. Gunakan akun demo di bawah form.'));
        dlg.querySelector('.lg-link').addEventListener('click', () =>
            tampil('Fitur lupa kata sandi belum tersedia pada prototipe demo ini.'));
        dlg.querySelector('form').addEventListener('submit', kirim);
    }

    function tampil(teks, jenis) {
        pesan.textContent = teks;
        pesan.className = 'lg-pesan show' + (jenis === 'sukses' ? ' sukses' : '');
    }

    function kirim(e) {
        e.preventDefault();
        if (tombol.classList.contains('loading')) return;
        const email = dlg.querySelector('#lgEmail').value.trim().toLowerCase();
        const sandi = dlg.querySelector('#lgSandi').value;
        if (!email || !sandi) { tampil('Masukkan alamat email dan kata sandi terlebih dahulu.'); return; }
        if (email !== DEMO_EMAIL || sandi !== DEMO_PASSWORD) {
            tampil('Email atau kata sandi salah. Gunakan akun demo yang ditampilkan di bawah form.');
            dlg.classList.remove('goyang'); void dlg.offsetWidth; dlg.classList.add('goyang');
            return;
        }
        try {
            sessionStorage.setItem(SESSION_KEY, JSON.stringify({ email: DEMO_EMAIL, name: 'Admin TSU' }));
        } catch (err) {
            tampil('Sesi tidak dapat disimpan oleh browser. Jalankan lewat localhost atau Live Server.');
            return;
        }
        tombol.classList.add('loading');
        tampil('Login berhasil.', 'sukses');
        const lanjut = tertahan;
        setTimeout(() => {
            dlg.close();
            document.dispatchEvent(new CustomEvent('kampusly:login', { detail: user() }));
            toast('Login berhasil. Selamat datang, ' + (user().name || 'Admin') + '.');
            if (lanjut) setTimeout(() => lanjutkan(lanjut), 0);
        }, 450);
    }

    // Lanjutkan aksi yang tadi ditahan (klik tombol/tautan, atau fokus ke kolom isian)
    function lanjutkan(el) {
        if (!el || !document.contains(el)) return;
        if (el.matches('select, input, textarea')) el.focus();
        else el.click();
    }

    function buka(el) {
        if (!dlg) bangun();
        if (dlg.open) return;
        tertahan = el || null;
        pesan.className = 'lg-pesan';
        tombol.classList.remove('loading');
        dlg.querySelector('#lgSandi').value = '';
        dlg.showModal();
        dlg.querySelector('#lgEmail').focus();
    }

    // ---------- Penjaga: tamu yang menekan sesuatu diminta login ----------
    function sasaran(e) {
        const t = e.target;
        if (!(t instanceof Element) || t.closest('.login-dialog, [data-publik]')) return null;
        return t.closest(SEL);
    }
    // Daftar pilihan (select) dan kolom isian membuka/fokus saat tombol mouse ditekan, jadi dicegah di sini.
    document.addEventListener('mousedown', (e) => {
        if (user()) return;
        const el = sasaran(e);
        if (el && el.matches('select, input, textarea')) e.preventDefault();
    }, true);
    document.addEventListener('click', (e) => {
        if (user()) return;
        const el = sasaran(e);
        if (!el) return;
        e.preventDefault(); e.stopPropagation(); e.stopImmediatePropagation();
        buka(el);
    }, true);
    document.addEventListener('keydown', (e) => {
        if (user() || e.ctrlKey || e.metaKey || e.altKey) return;
        const el = sasaran(e);
        if (!el) return;
        if (e.key === 'Enter' || e.key === ' ' || e.key.length === 1) {
            e.preventDefault(); e.stopPropagation(); e.stopImmediatePropagation();
            buka(el);
        }
    }, true);

    function keluar() {
        try { sessionStorage.removeItem(SESSION_KEY); } catch (e) {}
        document.dispatchEvent(new CustomEvent('kampusly:logout'));
        window.location.replace('dashboard.html');
    }

    window.Login = { user: user, buka: buka, keluar: keluar };
})();
