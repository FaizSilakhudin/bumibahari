<?php
// Partial: bel notifikasi in-app (dipakai admin_pic & admin_pusat).
// Butuh $conn, csrf_token(), current_user_id() dari config/koneksi.php yang
// sudah di-require oleh pemanggil sebelum include file ini.
?>
<style>
    #notifBell {
        position: fixed; bottom: 22px; right: 22px; z-index: 1060;
        width: 54px; height: 54px; border-radius: 50%;
        background: linear-gradient(135deg, #1e3a5f 0%, #12233a 100%);
        color: #fff; border: none; box-shadow: 0 8px 22px rgba(18,35,58,.35);
        display: flex; align-items: center; justify-content: center;
        font-size: 1.4rem; cursor: pointer; transition: transform .15s ease;
    }
    #notifBell:hover { transform: scale(1.06); }
    #notifBell .notif-badge {
        position: absolute; top: -4px; right: -4px; min-width: 20px; height: 20px;
        border-radius: 999px; background: #e11d48; color: #fff; font-size: 11px;
        font-weight: 700; display: none; align-items: center; justify-content: center;
        padding: 0 5px; border: 2px solid #fff;
    }
    #notifBell.has-unread .notif-badge { display: flex; }
    #notifBell.ring { animation: notifRing .5s ease; }
    @keyframes notifRing { 0%,100%{transform:rotate(0)} 20%{transform:rotate(-14deg)} 40%{transform:rotate(12deg)} 60%{transform:rotate(-8deg)} 80%{transform:rotate(6deg)} }

    #notifPanel {
        position: fixed; bottom: 88px; right: 22px; z-index: 1060;
        width: min(360px, calc(100vw - 32px)); max-height: 70vh;
        background: #fff; border-radius: 16px; box-shadow: 0 16px 40px rgba(15,23,42,.18);
        display: none; flex-direction: column; overflow: hidden;
        border: 1px solid #eef1fb;
    }
    #notifPanel.show { display: flex; }
    #notifPanel .notif-head {
        padding: 14px 16px; border-bottom: 1px solid #eef1fb; display: flex;
        align-items: center; justify-content: space-between; flex-shrink: 0;
    }
    #notifPanel .notif-head h6 { margin: 0; font-weight: 700; color: #1b2559; font-size: .95rem; }
    #notifPanel .notif-head button {
        background: none; border: none; color: #4318ff; font-size: .78rem; font-weight: 600; cursor: pointer;
    }
    #notifList { overflow-y: auto; flex: 1; }
    #notifList .notif-item {
        display: block; padding: 12px 16px; border-bottom: 1px solid #f4f7fe;
        text-decoration: none; color: inherit; transition: background .12s ease;
    }
    #notifList .notif-item:hover { background: #f8f9fc; }
    #notifList .notif-item.unread { background: #eef2ff; }
    #notifList .notif-item .notif-judul { font-weight: 700; font-size: .85rem; color: #1b2559; margin-bottom: 2px; }
    #notifList .notif-item .notif-pesan { font-size: .8rem; color: #64748b; margin-bottom: 4px; }
    #notifList .notif-item .notif-waktu { font-size: .72rem; color: #94a3b8; }
    #notifList .notif-empty { padding: 40px 16px; text-align: center; color: #94a3b8; font-size: .85rem; }
</style>

<button type="button" id="notifBell" title="Notifikasi">
    <i class="bi bi-bell-fill"></i>
    <span class="notif-badge" id="notifBadge">0</span>
</button>

<div id="notifPanel">
    <div class="notif-head">
        <h6><i class="bi bi-bell-fill me-1"></i> Notifikasi</h6>
        <button type="button" id="notifMarkAll">Tandai semua dibaca</button>
    </div>
    <div id="notifList"><div class="notif-empty">Memuat...</div></div>
</div>

<script>
(function () {
    var csrfToken = <?= json_encode(csrf_token()) ?>;
    var apiUrl = '../notifikasi_api.php';
    var bell = document.getElementById('notifBell');
    var badge = document.getElementById('notifBadge');
    var panel = document.getElementById('notifPanel');
    var list = document.getElementById('notifList');
    var markAllBtn = document.getElementById('notifMarkAll');
    var lastUnread = null;

    function waktuRelatif(iso) {
        var detik = Math.floor((Date.now() - new Date(iso.replace(' ', 'T')).getTime()) / 1000);
        if (detik < 60) return 'Baru saja';
        var menit = Math.floor(detik / 60);
        if (menit < 60) return menit + ' menit lalu';
        var jam = Math.floor(menit / 60);
        if (jam < 24) return jam + ' jam lalu';
        var hari = Math.floor(jam / 24);
        return hari + ' hari lalu';
    }

    function playChime() {
        try {
            var Ctx = window.AudioContext || window.webkitAudioContext;
            var ctx = new Ctx();
            var now = ctx.currentTime;
            [880, 1175].forEach(function (freq, i) {
                var osc = ctx.createOscillator();
                var gain = ctx.createGain();
                osc.type = 'sine';
                osc.frequency.value = freq;
                var start = now + i * 0.12;
                gain.gain.setValueAtTime(0, start);
                gain.gain.linearRampToValueAtTime(0.28, start + 0.02);
                gain.gain.exponentialRampToValueAtTime(0.001, start + 0.35);
                osc.connect(gain).connect(ctx.destination);
                osc.start(start);
                osc.stop(start + 0.4);
            });
        } catch (e) { /* autoplay diblokir browser, abaikan */ }
    }

    function render(items) {
        if (!items.length) {
            list.innerHTML = '<div class="notif-empty"><i class="bi bi-inbox fs-3 d-block mb-2 opacity-50"></i>Belum ada notifikasi.</div>';
            return;
        }
        list.innerHTML = items.map(function (n) {
            var unreadClass = n.is_read == 0 ? ' unread' : '';
            var href = n.link ? n.link.replace(/"/g, '&quot;') : '#';
            return '<a href="' + href + '" class="notif-item' + unreadClass + '" data-id="' + n.id + '">' +
                '<div class="notif-judul">' + escapeHtml(n.judul) + '</div>' +
                (n.pesan ? '<div class="notif-pesan">' + escapeHtml(n.pesan) + '</div>' : '') +
                '<div class="notif-waktu">' + waktuRelatif(n.created_at) + '</div>' +
                '</a>';
        }).join('');
    }

    function escapeHtml(s) {
        var d = document.createElement('div');
        d.innerText = s || '';
        return d.innerHTML;
    }

    function refresh(playSound) {
        fetch(apiUrl + '?action=list', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (typeof data.unread === 'undefined') return;
                if (playSound && lastUnread !== null && data.unread > lastUnread) {
                    playChime();
                    bell.classList.remove('ring'); void bell.offsetWidth; bell.classList.add('ring');
                }
                lastUnread = data.unread;
                badge.textContent = data.unread > 9 ? '9+' : data.unread;
                bell.classList.toggle('has-unread', data.unread > 0);
                render(data.items || []);
            })
            .catch(function () { /* diamkan kalau offline sesaat */ });
    }

    bell.addEventListener('click', function () {
        panel.classList.toggle('show');
        if (panel.classList.contains('show')) refresh(false);
    });

    document.addEventListener('click', function (e) {
        if (!panel.contains(e.target) && !bell.contains(e.target)) {
            panel.classList.remove('show');
        }
    });

    list.addEventListener('click', function (e) {
        var item = e.target.closest('.notif-item');
        if (!item) return;
        var id = item.getAttribute('data-id');
        var fd = new FormData();
        fd.append('csrf', csrfToken);
        fd.append('action', 'mark_read');
        fd.append('id', id);
        fetch(apiUrl, { method: 'POST', body: fd, credentials: 'same-origin' });
    });

    markAllBtn.addEventListener('click', function () {
        var fd = new FormData();
        fd.append('csrf', csrfToken);
        fd.append('action', 'mark_all_read');
        fetch(apiUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function () { refresh(false); });
    });

    refresh(false);
    setInterval(function () { refresh(true); }, 20000);
})();
</script>
