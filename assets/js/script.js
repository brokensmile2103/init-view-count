(function () {
    const config = window.InitViewCountSettings || {};
    const postId = config.post_id;
    if (!postId) return;

    const storage = config.storage === 'local' ? localStorage : sessionStorage;
    const viewedKey = `viewed_${postId}`;
    if (storage.getItem(viewedKey)) return;

    const batch = Math.max(1, parseInt(config.batch || '1', 10));
    const delay = parseInt(config.delay || '15000', 10);
    const scrollRequired = !!config.scrollEnabled;
    const scrollPercent = parseInt(config.scrollPercent || '75', 10);

    const queueKey = 'init_view_count_queue';
    let scrollPassed = !scrollRequired;
    let timePassed = false;
    let alreadyTriggered = false;

    setTimeout(() => {
        timePassed = true;
        checkAndSendView();
    }, delay);

    if (scrollRequired) {
        window.addEventListener('scroll', () => {
            const scrollY = window.scrollY;
            const scrollHeight = document.documentElement.scrollHeight - window.innerHeight;
            const scrolledPercent = (scrollY / scrollHeight) * 100;
            if (scrolledPercent >= scrollPercent) {
                scrollPassed = true;
                checkAndSendView();
            }
        });
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
        fetch('/wp-json/initvico/v1/count', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
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
