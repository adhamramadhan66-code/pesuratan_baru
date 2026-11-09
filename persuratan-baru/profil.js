/* Menu foto profil (kanan atas): Edit Foto, Tambah Foto, Kamera, Hapus Foto.
   Foto disimpan di peramban (localStorage) per akun, sudah diperkecil jadi 300x300 JPEG. */
(function () {
    'use strict';
    const { toast } = window.App || { toast: () => {} };
    const avatar = document.querySelector('.header-right .profile-img');
    if (!avatar) return;

    // Kunci foto mengikuti akun yang sedang masuk (tamu memakai foto bawaan, bukan foto admin)
    const emailSekarang = () => { try { return (JSON.parse(sessionStorage.getItem('kampuslyCurrentUser') || 'null') || {}).email || ''; } catch (e) { return ''; } };
    const KEY = () => 'profilePhoto:' + (emailSekarang() || 'admin');
    const DEFAULT_SRC = avatar.getAttribute('src');
    const getPhoto = () => { if (!emailSekarang()) return ''; try { return localStorage.getItem(KEY()) || ''; } catch (e) { return ''; } };
    const setPhoto = (v) => { try { v ? localStorage.setItem(KEY(), v) : localStorage.removeItem(KEY()); return true; } catch (e) { return false; } };

    const svg = (d) => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + d + '</svg>';
    const I = {
        edit: svg('<path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/>'),
        add: svg('<circle cx="12" cy="12" r="10"/><path d="M12 8v8M8 12h8"/>'),
        cam: svg('<path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/>'),
        del: svg('<path d="M3 6h18M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6M10 11v6M14 11v6M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>'),
        rot: svg('<path d="M23 4v6h-6"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/>'),
        chev: svg('<path d="M6 9l6 6 6-6"/>'),
        set: svg('<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33h0a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51h0a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82v0a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>'),
        out: svg('<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/>'),
    };

    // ---------- Tombol & menu dropdown ----------
    const wrap = document.createElement('div'); wrap.className = 'profile-menu';
    const btn = document.createElement('button');
    btn.type = 'button'; btn.className = 'profile-btn'; btn.title = 'Profil saya';
    btn.setAttribute('aria-haspopup', 'menu'); btn.setAttribute('aria-expanded', 'false');
    avatar.parentNode.insertBefore(wrap, avatar);
    btn.appendChild(avatar);
    btn.insertAdjacentHTML('beforeend', '<span class="chev">' + I.chev + '</span>');
    wrap.appendChild(btn);

    const dd = document.createElement('div');
    dd.className = 'profile-dropdown'; dd.setAttribute('role', 'menu'); dd.hidden = true;
    dd.innerHTML =
        '<div class="pd-head"><img class="pd-avatar" alt=""><div><b>Profil Saya</b><span class="pd-name"></span></div></div><div class="pd-sep"></div>' +
        '<button type="button" role="menuitem" data-a="edit">' + I.edit + '<span>Edit Foto</span></button>' +
        '<button type="button" role="menuitem" data-a="add">' + I.add + '<span>Tambah Foto</span></button>' +
        '<button type="button" role="menuitem" data-a="cam">' + I.cam + '<span>Kamera</span></button>' +
        '<button type="button" role="menuitem" data-a="del" class="danger">' + I.del + '<span>Hapus Foto</span></button>' +
        '<div class="pd-sep"></div>' +
        '<button type="button" role="menuitem" data-a="set">' + I.set + '<span>Pengaturan</span></button>' +
        '<button type="button" role="menuitem" data-a="out" class="danger">' + I.out + '<span>Keluar</span></button>';
    wrap.appendChild(dd);
    const labelEl = document.getElementById('currentUserLabel');
    dd.querySelector('.pd-name').textContent = (labelEl && labelEl.textContent) || 'Admin TSU';
    const headImg = dd.querySelector('.pd-avatar');
    const delBtn = dd.querySelector('[data-a="del"]');

    function apply() {
        const p = getPhoto(), src = p || DEFAULT_SRC;
        avatar.src = src; headImg.src = src; delBtn.hidden = !p;
    }
    function buka(o) {
        dd.hidden = !o; btn.setAttribute('aria-expanded', String(o));
        if (o) dd.querySelector('button:not([hidden])').focus();
    }
    btn.addEventListener('click', (e) => { e.stopPropagation(); buka(dd.hidden); });
    document.addEventListener('click', (e) => { if (!wrap.contains(e.target)) buka(false); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !dd.hidden) { buka(false); btn.focus(); } });

    // ---------- Editor foto (geser, zoom, putar) ----------
    const S = 300;
    const ed = document.createElement('dialog'); ed.className = 'modal';
    ed.innerHTML =
        '<div class="modal-head"><h3>Atur foto profil</h3><p>Geser foto untuk memosisikan, gunakan slider untuk memperbesar.</p></div>' +
        '<div class="modal-body"><div class="pe-stage"><canvas width="' + S + '" height="' + S + '"></canvas></div>' +
        '<div class="pe-tools"><input type="range" min="1" max="3" step="0.01" value="1" aria-label="Perbesar foto"><button type="button" class="btn sm" data-r>' + I.rot + ' Putar</button></div></div>' +
        '<div class="modal-foot"><button type="button" class="btn" data-c>Batal</button><button type="button" class="btn primary" data-s>Simpan foto</button></div>';
    const cv = ed.querySelector('canvas'), cx = cv.getContext('2d'), zoom = ed.querySelector('input[type=range]');
    let im = null, rot = 0, z = 1, ox = 0, oy = 0;
    const dims = () => { const w = rot % 2 ? im.naturalHeight : im.naturalWidth, h = rot % 2 ? im.naturalWidth : im.naturalHeight; return { w, h, base: Math.max(S / w, S / h) }; };
    function clamp() {
        const d = dims(), s = d.base * z, mx = Math.max(0, (d.w * s - S) / 2), my = Math.max(0, (d.h * s - S) / 2);
        ox = Math.min(mx, Math.max(-mx, ox)); oy = Math.min(my, Math.max(-my, oy));
    }
    function draw() {
        const s = dims().base * z;
        cx.fillStyle = '#222'; cx.fillRect(0, 0, S, S);
        cx.save(); cx.translate(S / 2 + ox, S / 2 + oy); cx.rotate(rot * Math.PI / 2); cx.scale(s, s);
        cx.drawImage(im, -im.naturalWidth / 2, -im.naturalHeight / 2); cx.restore();
    }
    function bukaEditor(image) {
        im = image; rot = 0; z = 1; ox = 0; oy = 0; zoom.value = 1; draw(); ed.showModal();
    }
    let drag = null;
    cv.addEventListener('pointerdown', (e) => { drag = { x: e.clientX, y: e.clientY }; cv.setPointerCapture(e.pointerId); cv.style.cursor = 'grabbing'; });
    cv.addEventListener('pointermove', (e) => { if (!drag) return; ox += e.clientX - drag.x; oy += e.clientY - drag.y; drag = { x: e.clientX, y: e.clientY }; clamp(); draw(); });
    const stop = () => { drag = null; cv.style.cursor = 'grab'; };
    cv.addEventListener('pointerup', stop); cv.addEventListener('pointercancel', stop);
    cv.addEventListener('wheel', (e) => { e.preventDefault(); z = Math.min(3, Math.max(1, z - e.deltaY * 0.002)); zoom.value = z; clamp(); draw(); }, { passive: false });
    zoom.addEventListener('input', () => { z = +zoom.value; clamp(); draw(); });
    ed.querySelector('[data-r]').addEventListener('click', () => { rot = (rot + 1) % 4; ox = oy = 0; clamp(); draw(); });
    ed.querySelector('[data-c]').addEventListener('click', () => ed.close());
    ed.querySelector('[data-s]').addEventListener('click', () => {
        if (setPhoto(cv.toDataURL('image/jpeg', 0.88))) { apply(); ed.close(); toast('Foto profil diperbarui'); }
        else toast('Foto gagal disimpan: penyimpanan peramban penuh atau diblokir.', true);
    });
    ed.addEventListener('click', (e) => { if (e.target === ed) ed.close(); });
    document.body.appendChild(ed);

    // ---------- Tambah Foto (pilih file) ----------
    const input = document.createElement('input');
    input.type = 'file'; input.accept = 'image/*'; input.hidden = true; document.body.appendChild(input);
    input.addEventListener('change', () => {
        const f = input.files[0]; input.value = '';
        if (!f) return;
        if (!/^image\//.test(f.type)) { toast('File harus berupa gambar (JPG, PNG, atau WEBP).', true); return; }
        if (f.size > 10 * 1024 * 1024) { toast('Ukuran foto maksimal 10 MB.', true); return; }
        const url = URL.createObjectURL(f), img = new Image();
        img.onload = () => { URL.revokeObjectURL(url); bukaEditor(img); };
        img.onerror = () => { URL.revokeObjectURL(url); toast('Gambar tidak dapat dibaca. Coba format JPG atau PNG.', true); };
        img.src = url;
    });

    // ---------- Kamera ----------
    const cam = document.createElement('dialog'); cam.className = 'modal';
    cam.innerHTML =
        '<div class="modal-head"><h3>Ambil foto dengan kamera</h3><p>Izinkan akses kamera bila peramban memintanya.</p></div>' +
        '<div class="modal-body"><div class="pe-stage cam"><video autoplay playsinline muted></video></div></div>' +
        '<div class="modal-foot"><button type="button" class="btn" data-c>Batal</button><button type="button" class="btn primary" data-s>' + I.cam + ' Ambil foto</button></div>';
    const video = cam.querySelector('video');
    let stream = null;
    function matikanKamera() { if (stream) { stream.getTracks().forEach((t) => t.stop()); stream = null; } video.srcObject = null; }
    async function nyalakanKamera() {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            toast('Kamera memerlukan alamat http://localhost atau HTTPS pada peramban yang mendukung.', true); return;
        }
        try {
            stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user', width: { ideal: 720 }, height: { ideal: 720 } }, audio: false });
            video.srcObject = stream; cam.showModal();
        } catch (err) {
            toast(err && err.name === 'NotAllowedError' ? 'Izin kamera ditolak. Aktifkan izin kamera di pengaturan peramban.'
                : err && err.name === 'NotFoundError' ? 'Kamera tidak ditemukan di perangkat ini.' : 'Kamera tidak dapat dibuka.', true);
        }
    }
    cam.querySelector('[data-c]').addEventListener('click', () => cam.close());
    cam.querySelector('[data-s]').addEventListener('click', () => {
        if (!video.videoWidth) { toast('Kamera belum siap, tunggu sebentar.', true); return; }
        const c = document.createElement('canvas'); c.width = video.videoWidth; c.height = video.videoHeight;
        c.getContext('2d').drawImage(video, 0, 0);
        const img = new Image();
        img.onload = () => { cam.close(); bukaEditor(img); };
        img.src = c.toDataURL('image/jpeg', 0.92);
    });
    cam.addEventListener('close', matikanKamera);
    cam.addEventListener('click', (e) => { if (e.target === cam) cam.close(); });
    document.body.appendChild(cam);

    // ---------- Aksi menu ----------
    dd.addEventListener('click', (e) => {
        const b = e.target.closest('button[data-a]'); if (!b) return;
        buka(false);
        const a = b.dataset.a;
        if (a === 'add') input.click();
        else if (a === 'cam') nyalakanKamera();
        else if (a === 'edit') {
            const p = getPhoto();
            if (!p) { toast('Belum ada foto sendiri. Pilih Tambah Foto atau Kamera terlebih dahulu.', true); return; }
            const img = new Image(); img.onload = () => bukaEditor(img); img.src = p;
        } else if (a === 'set') {
            toast('Halaman pengaturan belum tersedia.');
        } else if (a === 'out') {
            if (window.Login) Login.keluar();
            else { try { sessionStorage.removeItem('kampuslyCurrentUser'); } catch (err) {} window.location.replace('dashboard.html'); }
        } else if (a === 'del') {
            if (!confirm('Hapus foto profil dan kembali ke foto bawaan?')) return;
            setPhoto(''); apply(); toast('Foto profil dihapus');
        }
    });

    // Setelah login lewat popup: tampilkan nama dan foto akun
    document.addEventListener('kampusly:login', () => {
        dd.querySelector('.pd-name').textContent = (labelEl && labelEl.textContent) || 'Admin TSU';
        apply();
    });

    apply();
})();
