document.addEventListener('DOMContentLoaded', function () {
    const wrapper = document.querySelector('.init-plugin-suite-view-count-ranking');
    if (!wrapper) return;

    const number = parseInt(wrapper.dataset.number) || 5;
    const tabs = wrapper.querySelectorAll('.init-plugin-suite-view-count-ranking-tabs button');
    const panels = wrapper.querySelectorAll('.init-plugin-suite-view-count-ranking-panel');
    const contents = wrapper.querySelectorAll('.init-plugin-suite-view-count-ranking-content');
    const cache = {};

    function renderItem(item) {
        return `
            <div class="init-plugin-suite-view-count-ranking-item">
                <div class="init-plugin-suite-view-count-ranking-thumb">
                    <a href="${item.link}">
                        <img src="${item.thumbnail}" alt="${item.title}">
                    </a>
                </div>
                <div class="init-plugin-suite-view-count-ranking-meta">
                    <h5 class="init-plugin-suite-view-count-ranking-title">
                        <a href="${item.link}">${item.title}</a>
                    </h5>
                    <div class="init-plugin-suite-view-count-ranking-meta-info">
                        ${item.views.toLocaleString()} ${InitViewRankingI18n.viewsLabel} · ${item.date}
                    </div>
                </div>
            </div>
        `;
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

    function loadData(range, target, number = 5) {
        if (cache[range]) {
            target.innerHTML = cache[range];
            return;
        }

        target.innerHTML = renderLoading(number);

        fetch(`/wp-json/initvico/v1/top?range=${range}&number=${number}`)
            .then(res => res.json())
            .then(data => {
                if (!Array.isArray(data)) return;
                const html = data.map(renderItem).join('');
                cache[range] = html;
                target.innerHTML = html || `<div class="init-plugin-suite-view-count-empty">${InitViewRankingI18n.noData}</div>`;
                target.dataset.loaded = "true";
            })
            .catch(() => {
                target.innerHTML = `<div class="init-plugin-suite-view-count-error">${InitViewRankingI18n.loadError}</div>`;
            });
    }

    // map: data-range → content element
    const contentMap = {};
    contents.forEach(content => {
        const range = content.dataset.range;
        if (range) contentMap[range] = content;
    });

    // Auto load first tab
    const first = wrapper.querySelector('.init-plugin-suite-view-count-ranking-tabs li.active button');
    if (first && contentMap[first.dataset.range]) {
        loadData(first.dataset.range, contentMap[first.dataset.range], number);
    }

    // Handle tab switch
    tabs.forEach(btn => {
        btn.addEventListener('click', function () {
            const range = this.dataset.range;
            const target = contentMap[range];
            if (!range || !target) return;

            // update active tab
            tabs.forEach(b => b.closest('li').classList.remove('active'));
            this.closest('li').classList.add('active');

            // update panels
            panels.forEach(panel => panel.setAttribute('hidden', 'hidden'));
            target.closest('.init-plugin-suite-view-count-ranking-panel')?.removeAttribute('hidden');

            // load if needed
            if (target.dataset.loaded !== "true") {
                loadData(range, target, number);
            }
        });
    });
});
