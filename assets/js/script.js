(function () {
    const config = window.InitViewCountSettings || {};
    const postId = config.post_id;
    if (!postId) return;

    const storage = config.storage === 'local' ? localStorage : sessionStorage;
    const viewedKey = `viewed_${postId}`;
    if (storage.getItem(viewedKey)) return;

    // Dùng Number.isFinite thay vì `config.x || fallback`: với toán tử `||`,
    // giá trị hợp lệ nhưng falsy (0) sẽ bị nuốt mất và luôn rơi về fallback,
    // khiến admin không thể đặt delay/scroll percent về giá trị nhỏ thật sự.
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
            // Trang ngắn hơn viewport (không có gì để cuộn) coi như đã "cuộn" 100%,
            // vì user không thể tạo ra sự kiện scroll trên trang không có scrollbar.
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

        // Check ngay 1 lần sau khi layout ổn định, để cover trường hợp trang
        // không đủ dài để cuộn (không có sự kiện 'scroll' nào được bắn ra cả)
        // hoặc user đã load trang ở vị trí cuộn sẵn (VD: quay lại bằng nút Back).
        requestAnimationFrame(evaluateScroll);
    }

    function checkAndSendView() {
        if (!scrollPassed || !timePassed || alreadyTriggered) return;
        alreadyTriggered = true;
        storage.setItem(viewedKey, "1");

        if (batch === 1) {
            sendView([postId], postId);
            return;
        }

        let queue = [];
        try {
            queue = JSON.parse(localStorage.getItem(queueKey) || '[]');
        } catch (e) {}

        if (!queue.includes(postId)) {
            queue.push(postId);
            localStorage.setItem(queueKey, JSON.stringify(queue));
        }

        if (queue.length >= batch) {
            localStorage.removeItem(queueKey);
            sendView(queue, postId);
        }
    }

    function sendView(postIds, currentPostId) {
        const restUrl = (InitViewCountSettings && InitViewCountSettings.restUrl) || '/wp-json/initvico/v1';
        const headers = { 'Content-Type': 'application/json' };
        if (config.nonce) {
            headers['X-WP-Nonce'] = config.nonce;
        }

        fetch(`${restUrl}/count`, {
            method: 'POST',
            headers: headers,
            body: JSON.stringify({ post_id: postIds.length === 1 ? postIds[0] : postIds })
        })
        .then(res => res.json())
        .then(data => {
            const entries = Array.isArray(data) ? data : [data];
            const matched = entries.find(entry =>
                entry && entry.post_id == currentPostId && !isNaN(parseInt(entry.total))
            );

            if (matched) {
                updateViewUI(parseInt(matched.total), currentPostId);
            } else {
                console.warn('[InitVC] No match found in response for post:', currentPostId);
            }
        })
        .catch(console.error);
    }

    function updateViewUI(total, postId) {
        const el = document.querySelector(`.init-plugin-suite-view-count-number[data-id="${postId}"]`);
        if (!el) return;

        const current = parseInt(el.dataset.view || '0', 10);
        const to = parseInt(total || '0', 10);

        if (!isNaN(to)) {
            if (current !== to) {
                animateCount(el, current, to);
            } else {
                el.textContent = formatNumber(to);
            }
            el.dataset.view = to;
        }
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

    function formatNumber(x) {
        return x.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ".");
    }
})();
