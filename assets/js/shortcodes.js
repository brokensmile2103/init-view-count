(function() {
    document.addEventListener('DOMContentLoaded', function () {
        var i18n = (window.InitViewCountShortcodeBuilder && window.InitViewCountShortcodeBuilder.i18n) || {};
        var t = function(key, fallback) {
            return i18n[key] || fallback;
        };

        var target = document.querySelector('[data-plugin="init-view-count"]');
        if (!target) return;

        var buttons = [
            {
                label: t('init_view_list', 'Init View List'),
                shortcode: 'init_view_list',
                attributes: {
                    number: { label: t('number', 'Number of Posts'), type: 'number', default: 5 },
                    post_type: { label: t('post_type', 'Post Type'), type: 'text', default: 'post' },
                    template: { label: t('template', 'Template'), type: 'select', options: ['sidebar', 'grid', 'full', 'details'], default: 'sidebar' },
                    range: { label: t('range', 'View Range'), type: 'select', options: ['total', 'day', 'week', 'month', 'trending'], default: 'total' },
                    orderby: { label: t('orderby', 'Order By'), type: 'select', options: ['meta_value_num', 'date', 'title', 'rand'], default: 'meta_value_num' },
                    order: { label: t('order', 'Order Direction'), type: 'select', options: ['DESC', 'ASC'], default: 'DESC' },
                    category: { label: t('category', 'Category'), type: 'text', default: '' },
                    tag: { label: t('tag', 'Tag'), type: 'text', default: '' },
                    class: { label: t('class', 'Custom CSS class'), type: 'text', default: '' },
                    title: { label: t('title', 'Title'), type: 'text', default: t('title_default', 'Popular Posts') }
                }
            },
            {
                label: t('init_view_ranking', 'Init View Ranking'),
                shortcode: 'init_view_ranking',
                attributes: {
                    tabs: { label: t('tabs', 'Tabs'), type: 'text', default: 'total,day,week,month' },
                    number: { label: t('number', 'Number of Posts'), type: 'number', default: 5 },
                    class: { label: t('class', 'Custom CSS class'), type: 'text', default: '' }
                }
            },
            {
                label: t('init_view_count', 'Init View Count'),
                shortcode: 'init_view_count',
                attributes: {
                    field: { label: t('field', 'Field'), type: 'select', options: ['total', 'day', 'week', 'month'], default: 'total' },
                    format: { label: t('format', 'Format'), type: 'select', options: ['formatted', 'raw', 'short'], default: 'formatted' },
                    time: { label: t('time', 'Show Time Diff'), type: 'checkbox', default: false },
                    icon: { label: t('icon', 'Show Icon'), type: 'checkbox', default: false },
                    schema: { label: t('schema', 'Enable Schema.org'), type: 'checkbox', default: false },
                    class: { label: t('class', 'Custom Class'), type: 'text', placeholder: 'e.g. view-count-lg', default: '' }
                }
            }
        ];

        var panel = renderShortcodeBuilderPanel({
            title: t('init_view_count', 'Init View Count'),
            buttons: buttons.map(function(btn) {
                return {
                    label: btn.label,
                    dashicon: 'editor-code',
                    className: 'button-default',
                    onClick: function() {
                        initShortcodeBuilder({
                            shortcode: btn.shortcode,
                            config: {
                                label: btn.label,
                                attributes: btn.attributes
                            }
                        });
                    }
                };
            })
        });

        target.appendChild(panel);
    });
})();
