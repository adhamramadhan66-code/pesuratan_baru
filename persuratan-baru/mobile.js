/* Perilaku sidebar di HP: tertutup saat dibuka, menutup saat layar gelap/menu ditekan. */
(function () {
    const sb = document.getElementById('mainSidebar'), tg = document.getElementById('sidebarToggle');
    if (!sb || !tg) return;
    const mq = window.matchMedia('(max-width: 768px)');

    const bd = document.createElement('div');
    bd.className = 'sidebar-backdrop';
    document.body.appendChild(bd);

    const terbuka = () => sb.classList.contains('expanded');
    const sinkron = () => bd.classList.toggle('show', mq.matches && terbuka());
    const tutup = () => { if (terbuka()) tg.click(); };

    new MutationObserver(sinkron).observe(sb, { attributes: true, attributeFilter: ['class'] });
    bd.addEventListener('click', tutup);
    sb.querySelectorAll('a.nav-item').forEach((a) => a.addEventListener('click', () => { if (mq.matches) tutup(); }));
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && mq.matches) tutup(); });

    const ubah = (e) => {
        if (e.matches) tutup();
        else if (!terbuka()) tg.click();
        sinkron();
    };
    if (mq.addEventListener) mq.addEventListener('change', ubah); else mq.addListener(ubah);

    if (mq.matches) tutup();   // awal di HP: tertutup
    sinkron();
})();
