(function () {
    const config = window.InitViewCountSettings || {};
    const postId = config.post_id;
    if (!postId) return;

    // Truy cập localStorage/sessionStorage có thể NÉM LỖI (Safari private mode, trình duyệt
    // chặn cookie/storage, iframe sandbox...). Bản cũ gọi thẳng → script dừng ngay và không
    // bao giờ đếm view. Nay bọc an toàn, fallback về bộ nhớ tạm trong trang.
    const memoryStore = {};
    const safeStorage = (type) => {
        let store = null;
        try {
            store = window[type];
            const probe = '__init_view_count__';
            store.setItem(probe, '1');
            store.removeItem(probe);
        } catch (e) {
            store = null;
        }

        return {
            getItem(key) {
                if (store) {
                    try { return store.getItem(key); } catch (e) {}
                }
                return Object.prototype.hasOwnProperty.call(memoryStore, key) ? memoryStore[key] : null;
            },
            setItem(key, value) {
                if (store) {
                    try { store.setItem(key, value); return; } catch (e) {}
                }
                memoryStore[key] = String(value);
            },
            removeItem(key) {
                if (store) {
                    try { store.removeItem(key); } catch (e) {}
                }
                delete memoryStore[key];
            }
        };
    };

    const storage = safeStorage(config.storage === 'local' ? 'localStorage' : 'sessionStorage');
    const queueStorage = safeStorage('localStorage');
    const viewedKey = `viewed_${postId}`;
    if (storage.getItem(viewedKey)) return;

    // Dùng Number.isFinite thay vì `config.x || fallback`: với toán tử `||`,
    // giá trị hợp lệ nhưng falsy (0) sẽ bị nuốt mất và luôn rơi về fallback.
    const parseConfigInt = (value, fallback) => {
        const n = parseInt(value, 10);
        return Number.isFinite(n) ? n : fallback;
    };

    const batch = Math.max(1, parseConfigInt(config.batch, 1));
    const delay = Math.max(0, parseConfigInt(config.delay, 15000));
    const scrollRequired = !!config.scrollEnabled;
    const scrollPercent = parseConfigInt(config.scrollPercent, 75);

    const queueKey = 'init_view_count_queue';
    let scrollPassed = !scrollRequired;
    let timePassed = false;
    let alreadyTriggered = false;

    setTimeout(() => {
        timePassed = true;
        checkAndSendView();
    }, delay);

    if (scrollRequired) {
        let scrollTicking = false;

        const evaluateScroll = () => {
            const scrollY = window.scrollY;
            const scrollHeight = document.documentElement.scrollHeight - window.innerHeight;
            // Trang ngắn hơn viewport (không có gì để cuộn) coi như đã "cuộn" 100%.
            const scrolledPercent = scrollHeight > 0 ? (scrollY / scrollHeight) * 100 : 100;

            if (scrolledPercent >= scrollPercent) {
                scrollPassed = true;
                window.removeEventListener('scroll', onScroll);
                checkAndSendView();
            }
        };

        const onScroll = () => {
            if (scrollTicking) return;
            scrollTicking = true;

            requestAnimationFrame(() => {
                scrollTicking = false;
                evaluateScroll();
            });
        };

        window.addEventListener('scroll', onScroll, { passive: true });

        // Check ngay 1 lần sau khi layout ổn định (trang quá ngắn, hoặc load ở vị trí cuộn sẵn).
        requestAnimationFrame(evaluateScroll);
    }

    function checkAndSendView() {
        if (!scrollPassed || !timePassed || alreadyTriggered) return;
        alreadyTriggered = true;
        storage.setItem(viewedKey, '1');

        if (batch === 1) {
            sendView([postId], postId);
            return;
        }

        let queue = [];
        try {
            queue = JSON.parse(queueStorage.getItem(queueKey) || '[]');
        } catch (e) {}
        if (!Array.isArray(queue)) queue = [];

        if (!queue.includes(postId)) {
            queue.push(postId);
        }

        if (queue.length >= batch) {
            // Chỉ gửi đúng số lượng server chấp nhận (server cắt bớt phần vượt batch);
            // phần còn dư (VD: admin vừa giảm batch) được giữ lại cho lần gửi sau thay vì bị mất.
            const toSend = queue.slice(0, batch);
            const rest = queue.slice(batch);
            if (rest.length) {
                queueStorage.setItem(queueKey, JSON.stringify(rest));
            } else {
                queueStorage.removeItem(queueKey);
            }
            sendView(toSend, postId);
        } else {
            queueStorage.setItem(queueKey, JSON.stringify(queue));
        }
    }

    function sendView(postIds, currentPostId) {
        const restUrl = (config.restUrl || '/wp-json/initvico/v1').replace(/\/+$/, '');
        const headers = { 'Content-Type': 'application/json' };
        if (config.nonce) {
            headers['X-WP-Nonce'] = config.nonce;
        }

        fetch(`${restUrl}/count`, {
            method: 'POST',
            headers: headers,
            credentials: 'same-origin',
            // keepalive: request vẫn được gửi đi nếu người dùng rời trang đúng lúc view vừa đủ điều kiện.
            keepalive: true,
            body: JSON.stringify({ post_id: postIds.length === 1 ? postIds[0] : postIds })
        })
        .then(res => res.json())
        .then(data => {
            const entries = Array.isArray(data) ? data : [data];
            const matched = entries.find(entry =>
                entry && entry.post_id == currentPostId && !isNaN(parseInt(entry.total, 10))
            );

            if (matched) {
                updateViewUI(parseInt(matched.total, 10), currentPostId);
            } else {
                console.warn('[InitVC] No match found in response for post:', currentPostId);
            }
        })
        .catch(console.error);
    }

    function updateViewUI(total, postId) {
        // Cập nhật MỌI chỗ đang hiển thị view của bài này (VD: auto-insert + block/widget).
        const els = document.querySelectorAll(`.init-plugin-suite-view-count-number[data-id="${postId}"]`);
        if (!els.length) return;

        const to = parseInt(total || '0', 10);
        if (isNaN(to)) return;

        els.forEach(el => {
            const current = parseInt(el.dataset.view || '0', 10);

            if (current !== to) {
                animateCount(el, current, to);
            } else {
                el.textContent = formatNumber(to);
            }
            el.dataset.view = to;
        });
    }

    function animateCount(el, from, to) {
        const diff = to - from;
        const duration = 600;
        const steps = Math.max(10, Math.min(60, diff));
        const increment = Math.ceil(diff / steps);
        const stepTime = Math.max(10, Math.floor(duration / steps));

        const interval = setInterval(() => {
            from += increment;
            if (from >= to) {
                el.textContent = formatNumber(to);
                clearInterval(interval);
            } else {
                el.textContent = formatNumber(from);
            }
        }, stepTime);
    }

    // Hành vi có chủ đích: sau khi đếm xong luôn hiện con số CHÍNH XÁC (VD "1.2 K" → "1.235").
    function formatNumber(x) {
        return x.toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    }
})();
