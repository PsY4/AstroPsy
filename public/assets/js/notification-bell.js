/**
 * notification-bell.js
 * Polling toutes les 60s du count non-lus + dropdown 5 dernières notifs.
 */

(function () {
    'use strict';

    const POLL_INTERVAL = 60_000;
    const rtf = new Intl.RelativeTimeFormat(document.documentElement.lang || 'fr', { numeric: 'auto' });

    function relativeTime(isoString) {
        const diff = (new Date(isoString) - Date.now()) / 1000; // secondes, négatif = passé
        const abs = Math.abs(diff);
        if (abs < 60)   return rtf.format(Math.round(diff), 'second');
        if (abs < 3600) return rtf.format(Math.round(diff / 60), 'minute');
        if (abs < 86400)return rtf.format(Math.round(diff / 3600), 'hour');
        return rtf.format(Math.round(diff / 86400), 'day');
    }

    function updateBadge(count) {
        const badge = document.getElementById('notif-badge');
        if (!badge) return;
        if (count > 0) {
            badge.textContent = count > 99 ? '99+' : count;
            badge.classList.remove('d-none');
        } else {
            badge.classList.add('d-none');
        }
    }

    function fetchUnreadCount() {
        fetch('/api/notifications/unread-count')
            .then(r => r.json())
            .then(d => updateBadge(d.count))
            .catch(() => {});
    }

    // ── Dropdown ──────────────────────────────────────────────────────────────

    function renderDropdown(notifications) {
        const dropdown = document.getElementById('notif-dropdown');
        if (!dropdown) return;

        const top5 = notifications.slice(0, 5);

        let html = '<div class="notif-dropdown-inner">';
        html += '<div class="notif-dropdown-header">';
        html += '<span>Notifications</span>';
        html += '<button class="btn btn-link btn-sm p-0 text-secondary" id="notif-read-all-btn">Tout lire</button>';
        html += '</div>';

        if (top5.length === 0) {
            html += '<div class="notif-dropdown-empty">Aucune notification</div>';
        } else {
            html += '<ul class="notif-dropdown-list">';
            top5.forEach(function (n) {
                const unreadClass = n.read ? '' : ' notif-item-unread';
                html += '<li class="notif-dropdown-item' + unreadClass + '" data-id="' + n.id + '">';
                html += '<div class="notif-item-title">' + escHtml(n.title) + '</div>';
                html += '<div class="notif-item-meta">' + relativeTime(n.created_at) + '</div>';
                html += '</li>';
            });
            html += '</ul>';
        }

        html += '<div class="notif-dropdown-footer">';
        html += '<a href="/notifications">Voir tout</a>';
        html += '</div>';
        html += '</div>';

        dropdown.innerHTML = html;
        dropdown.classList.remove('d-none');

        // Close on outside click
        setTimeout(() => {
            document.addEventListener('click', closeDropdown, { once: true });
        }, 10);

        // Mark individual as read
        dropdown.querySelectorAll('.notif-dropdown-item').forEach(function (item) {
            item.addEventListener('click', function () {
                const id = this.dataset.id;
                fetch('/api/notifications/' + id + '/read', { method: 'POST' })
                    .then(() => fetchUnreadCount());
                this.classList.remove('notif-item-unread');
            });
        });

        // Mark all read
        const readAllBtn = dropdown.querySelector('#notif-read-all-btn');
        if (readAllBtn) {
            readAllBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                fetch('/api/notifications/read-all', { method: 'POST' })
                    .then(() => {
                        updateBadge(0);
                        dropdown.querySelectorAll('.notif-item-unread').forEach(el => el.classList.remove('notif-item-unread'));
                    });
            });
        }
    }

    function closeDropdown() {
        const dropdown = document.getElementById('notif-dropdown');
        if (dropdown) dropdown.classList.add('d-none');
    }

    function escHtml(str) {
        const d = document.createElement('div');
        d.appendChild(document.createTextNode(str));
        return d.innerHTML;
    }

    // ── Bell click → toggle dropdown ──────────────────────────────────────────

    function initBell() {
        const bell = document.getElementById('notif-bell');
        if (!bell) return;

        bell.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();

            const dropdown = document.getElementById('notif-dropdown');
            if (dropdown && !dropdown.classList.contains('d-none')) {
                closeDropdown();
                return;
            }

            fetch('/api/notifications')
                .then(r => r.json())
                .then(data => renderDropdown(data))
                .catch(() => {});
        });
    }

    // ── Init ──────────────────────────────────────────────────────────────────

    document.addEventListener('DOMContentLoaded', function () {
        fetchUnreadCount();
        setInterval(fetchUnreadCount, POLL_INTERVAL);
        initBell();
    });

})();
