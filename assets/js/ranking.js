(function () {
    const i18n = window.InitViewRankingI18n || {};

    // Bộ nhớ đệm kết quả dùng chung cho mọi khối ranking trên trang (key: range + post type + number).
    const cache = {};

    function getRestBase() {
        // Ưu tiên URL REST do PHP in ra (đúng cả khi WP cài trong thư mục con hoặc tắt pretty permalink).
        const base = i18n.restUrl
            || (window.InitViewCountSettings && window.InitViewCountSettings.restUrl)
            || `${window.location.origin}/wp-json/initvico/v1`;
        return base.replace(/\/+$/, '') + '/';
    }

    // Giải mã HTML entity trong tiêu đề (get_the_title() trả về dạng đã texturize: &#8217;...)
    // thành text thuần, để gán bằng textContent — không chèn chuỗi thô từ API vào innerHTML.
    function decodeEntities(value) {
        const doc = new DOMParser().parseFromString(String(value == null ? '' : value), 'text/html');
        return doc.documentElement.textContent || '';
    }

    function safeUrl(value) {
        try {
            const url = new URL(String(value || ''), window.location.href);
            return (url.protocol === 'http:' || url.protocol === 'https:') ? url.href : '#';
        } catch (e) {
            return '#';
        }
    }

    function el(tag, className) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        return node;
    }

    function renderItem(item) {
        const title = decodeEntities(item.title);
        const link = safeUrl(item.link);

        const row = el('div', 'init-plugin-suite-view-count-ranking-item');

        const thumb = el('div', 'init-plugin-suite-view-count-ranking-thumb');
        const thumbLink = el('a');
        thumbLink.href = link;
        const img = el('img');
        img.src = safeUrl(item.thumbnail);
        img.alt = title;
        img.loading = 'lazy';
        img.decoding = 'async';
        thumbLink.appendChild(img);
        thumb.appendChild(thumbLink);

        const meta = el('div', 'init-plugin-suite-view-count-ranking-meta');
        const heading = el('h5', 'init-plugin-suite-view-count-ranking-title');
        const titleLink = el('a');
        titleLink.href = link;
        titleLink.textContent = title;
        heading.appendChild(titleLink);

        const info = el('div', 'init-plugin-suite-view-count-ranking-meta-info');
        const views = Number(item.views) || 0;
        info.textContent = `${views.toLocaleString()} ${i18n.viewsLabel || 'views'} · ${decodeEntities(item.date)}`;

        meta.appendChild(heading);
        meta.appendChild(info);
        row.appendChild(thumb);
        row.appendChild(meta);
        return row;
    }

    function renderMessage(className, text) {
        const box = el('div', className);
        box.textContent = text || '';
        return box.outerHTML;
    }

    function renderLoading(number) {
        let html = '';
        for (let i = 0; i < number; i++) {
            html += `
                <div class="init-plugin-suite-view-count-ranking-skeleton-item">
                    <div class="init-plugin-suite-view-count-ranking-skeleton-thumb"></div>
                    <div class="init-plugin-suite-view-count-ranking-skeleton-text">
                        <div class="init-plugin-suite-view-count-ranking-skeleton-title"></div>
                        <div class="init-plugin-suite-view-count-ranking-skeleton-line"></div>
                    </div>
                </div>
            `;
        }
        return html;
    }

    function loadData(range, target, number, postType) {
        const cacheKey = `${range}|${postType}|${number}`;

        if (cache[cacheKey] !== undefined) {
            target.innerHTML = cache[cacheKey];
            target.dataset.loaded = 'true';
            return;
        }

        target.innerHTML = renderLoading(number);

        const url = new URL(getRestBase() + 'top', window.location.href);
        url.searchParams.set('range', range);
        url.searchParams.set('number', number);
        if (postType) {
            url.searchParams.set('post_type', postType);
        }

        fetch(url.toString(), { method: 'GET', credentials: 'same-origin' })
            .then(res => {
                if (!res.ok) throw new Error(`HTTP ${res.status}`);
                return res.json();
            })
            .then(data => {
                if (!Array.isArray(data)) throw new Error('Invalid payload');

                const wrapper = document.createElement('div');
                data.forEach(item => {
                    if (item && typeof item === 'object') {
                        wrapper.appendChild(renderItem(item));
                    }
                });

                const html = wrapper.innerHTML;
                cache[cacheKey] = html;
                target.innerHTML = html || renderMessage('init-plugin-suite-view-count-empty', i18n.noData);
                target.dataset.loaded = 'true';
            })
            .catch(() => {
                target.innerHTML = renderMessage('init-plugin-suite-view-count-error', i18n.loadError);
            });
    }

    function initRanking(wrapper) {
        if (wrapper.dataset.initvcReady === '1') return;
        wrapper.dataset.initvcReady = '1';

        const number = parseInt(wrapper.dataset.number, 10) || 5;
        // Post type riêng của từng khối (data-post-type); template override cũ của theme không có
        // thuộc tính này → fallback về giá trị toàn cục như trước.
        const postType = (wrapper.dataset.postType !== undefined ? wrapper.dataset.postType : i18n.postType) || '';
        const tabs = wrapper.querySelectorAll('.init-plugin-suite-view-count-ranking-tabs button');
        const panels = wrapper.querySelectorAll('.init-plugin-suite-view-count-ranking-panel');
        const contents = wrapper.querySelectorAll('.init-plugin-suite-view-count-ranking-content');

        const contentMap = {};
        contents.forEach(content => {
            const range = content.dataset.range;
            if (range) contentMap[range] = content;
        });

        const first = wrapper.querySelector('.init-plugin-suite-view-count-ranking-tabs li.active button');
        if (first && contentMap[first.dataset.range]) {
            loadData(first.dataset.range, contentMap[first.dataset.range], number, postType);
        }

        tabs.forEach(btn => {
            btn.addEventListener('click', function () {
                const range = this.dataset.range;
                const target = contentMap[range];
                if (!range || !target) return;

                tabs.forEach(b => b.closest('li').classList.remove('active'));
                this.closest('li').classList.add('active');

                panels.forEach(panel => panel.setAttribute('hidden', 'hidden'));
                const panel = target.closest('.init-plugin-suite-view-count-ranking-panel');
                if (panel) panel.removeAttribute('hidden');

                if (target.dataset.loaded !== 'true') {
                    loadData(range, target, number, postType);
                }
            });
        });
    }

    function init() {
        // Khởi tạo TẤT CẢ khối ranking trên trang (bản cũ chỉ xử lý khối đầu tiên).
        document.querySelectorAll('.init-plugin-suite-view-count-ranking').forEach(initRanking);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
