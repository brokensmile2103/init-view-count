(function () {
    const config = window.InitViewCountSettings || {};
    const postId = config.post_id;
    if (!postId) return;

    const key = `viewed_${postId}`;
    const storage = config.storage === 'local' ? localStorage : sessionStorage;
    if (storage.getItem(key)) return;

    let scrollPassed = !config.scrollEnabled;
    let timePassed = false;
    let alreadySent = false;

    // Delay check
    setTimeout(() => {
        timePassed = true;
        triggerCount();
    }, config.delay || 15000);

    // Scroll check
    if (config.scrollEnabled) {
        window.addEventListener("scroll", () => {
            const docHeight = document.documentElement.scrollHeight - window.innerHeight;
            const scrolled = window.scrollY;
            if ((scrolled / docHeight) > (config.scrollPercent / 100)) {
                scrollPassed = true;
                triggerCount();
            }
        });
    }

    function triggerCount() {
        if (scrollPassed && timePassed && !alreadySent) {
            alreadySent = true;
            fetch('/wp-json/initvico/v1/count', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ post_id: postId })
            })
            .then(res => res.json())
            .then(data => {
                storage.setItem(key, "1");

                const els = document.querySelectorAll('.init-plugin-suite-view-count-number');
                els.forEach(el => {
                    const from = parseInt(el.dataset.view || '0', 10);
                    const to = parseInt(data.total || 0, 10);

                    if (!isNaN(to) && to > from) {
                        animateViewCount(el, from, to);
                        el.dataset.view = to;
                    }
                });
            })
            .catch(console.error);
        }
    }

    function animateViewCount(el, from, to) {
        const diff = to - from;
        const stepTime = Math.min(Math.ceil(1000 / diff), 50);
        const increment = Math.ceil(diff / (1000 / stepTime));

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
