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

    // Điều kiện cuộn tại thời điểm gọi (mặc định: đã đạt / không yêu cầu cuộn).
    let scrollOk = () => scrollPassed;
    // Gỡ mọi listener theo dõi cuộn (gán lại bên dưới khi có yêu cầu cuộn).
    let stopScrollTracking = () => {};

    setTimeout(() => {
        timePassed = true;
        checkAndSendView();
    }, delay);

    if (scrollRequired) {
        let scrollTicking = false;

        // Sai số làm tròn (px): trình duyệt có thể dừng scrollY ở số lẻ (VD 1999.5 thay vì 2000).
        const EDGE_TOLERANCE = 2;
        // v2.0.7: khung đo nằm gọn trong màn hình chỉ được tính khi trang đã tải xong và layout
        // đứng yên ít nhất chừng này (ms) — tránh đạt điều kiện khi ảnh/nội dung chưa tải.
        const SETTLE_MS = 1000;

        // v2.0.6+: vùng tính phần trăm cuộn theo CSS selector ('' = toàn trang).
        // Selector sai cú pháp hoặc không khớp phần tử nào → toàn trang.
        const resolveTarget = (selector) => {
            if (typeof selector !== 'string' || !selector.trim()) return null;
            try {
                const el = document.querySelector(selector);
                if (!el) {
                    console.warn('[InitVC] Scroll area not found, using the whole page:', selector);
                }
                return el;
            } catch (e) {
                console.warn('[InitVC] Invalid scroll area selector, using the whole page:', selector);
                return null;
            }
        };

        let target = resolveTarget(config.scrollTarget);
        let lastLayoutChange = Date.now();
        let recheckTimer = null;
        let resizeObserver = null;

        // Toàn trang. fit = không có gì để cuộn.
        const measurePage = () => {
            const doc = document.documentElement;
            const maxScroll = doc.scrollHeight - window.innerHeight;
            if (maxScroll <= EDGE_TOLERANCE) return { percent: 100, fit: true };
            const scrollY = window.scrollY || doc.scrollTop || 0;
            if (scrollY >= maxScroll - EDGE_TOLERANCE) return { percent: 100, fit: false };
            return { percent: (scrollY / maxScroll) * 100, fit: false };
        };

        // Theo phần tử: % chiều cao đã lọt vào màn hình (100% = mép dưới đã xuất hiện).
        // fit = cả phần tử đang nằm gọn trong màn hình. Phần tử ẩn/cao 0 → null (dùng toàn trang).
        const measureElement = (el) => {
            const rect = el.getBoundingClientRect();
            const viewportHeight = window.innerHeight || document.documentElement.clientHeight;

            if (rect.height <= 0) return null;
            if (rect.bottom <= viewportHeight + EDGE_TOLERANCE) {
                return { percent: 100, fit: rect.top >= -EDGE_TOLERANCE };
            }
            return {
                percent: Math.max(0, Math.min(100, ((viewportHeight - rect.top) / rect.height) * 100)),
                fit: false
            };
        };

        const measure = () => {
            // Phần tử bị gỡ khỏi DOM (theme SPA/AJAX thay nội dung) → toàn trang.
            if (target && !target.isConnected) {
                target = null;
            }
            if (target) {
                const m = measureElement(target);
                if (m !== null) return m;
            }
            return measurePage();
        };

        let listening = true;
        const stopListening = () => {
            if (!listening) return;
            listening = false;
            window.removeEventListener('scroll', onScroll, true);
            window.removeEventListener('resize', onScroll);
            window.removeEventListener('load', onLoad);
            if (resizeObserver) resizeObserver.disconnect();
            if (recheckTimer) clearTimeout(recheckTimer);
            recheckTimer = null;
        };

        stopScrollTracking = stopListening;

        const scheduleRecheck = (wait) => {
            if (recheckTimer) return;
            recheckTimer = setTimeout(() => {
                recheckTimer = null;
                evaluateScroll();
            }, Math.max(50, wait));
        };

        scrollOk = () => {
            if (scrollPassed) return true;

            const m = measure();
            if (!m.fit) {
                if (m.percent < scrollPercent) return false;
                // Người đọc đã cuộn thật → chốt luôn.
                scrollPassed = true;
                stopListening();
                return true;
            }

            // Nằm gọn trong màn hình: chỉ tính khi trang tải xong và layout đã đứng yên. Không chốt —
            // được đo lại đúng lúc gửi, nên nội dung lớn lên sau đó (ảnh, AJAX) sẽ cần cuộn thật.
            if (document.readyState !== 'complete') return false;
            const quiet = Date.now() - lastLayoutChange;
            if (quiet < SETTLE_MS) {
                scheduleRecheck(SETTLE_MS - quiet);
                return false;
            }
            return true;
        };

        const evaluateScroll = () => {
            if (alreadyTriggered) return;
            if (scrollOk()) checkAndSendView();
        };

        const onScroll = () => {
            if (scrollTicking) return;
            scrollTicking = true;

            requestAnimationFrame(() => {
                scrollTicking = false;
                evaluateScroll();
            });
        };

        const onLayoutChange = () => {
            lastLayoutChange = Date.now();
            onScroll();
        };

        const onLoad = () => onLayoutChange();

        // capture: true → bắt cả sự kiện cuộn của khung cuộn bên trong (scroll không bubble).
        window.addEventListener('scroll', onScroll, { passive: true, capture: true });
        window.addEventListener('resize', onScroll, { passive: true });
        if (document.readyState !== 'complete') {
            window.addEventListener('load', onLoad);
        }

        // Theo dõi kích thước khung đo (ảnh tải xong, nội dung chèn thêm...).
        if (typeof ResizeObserver === 'function') {
            resizeObserver = new ResizeObserver(onLayoutChange);
            resizeObserver.observe(target || document.documentElement);
        }

        // Check ngay 1 lần (trang quá ngắn, hoặc load ở vị trí cuộn sẵn).
        requestAnimationFrame(evaluateScroll);
    }

    function checkAndSendView() {
        if (!timePassed || alreadyTriggered || !scrollOk()) return;
        alreadyTriggered = true;
        // Đã tính view → không cần theo dõi cuộn/kích thước nữa.
        stopScrollTracking();
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
