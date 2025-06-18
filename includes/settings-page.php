<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action('admin_menu', function () {
    add_options_page(
        esc_html__('Init View Count Settings', 'init-view-count'),
        esc_html__('Init View Count', 'init-view-count'),
        'manage_options',
        'init-view-count-settings',
        'init_plugin_suite_view_count_render_settings_page'
    );
});

function init_plugin_suite_view_count_render_settings_page() {
    if (isset($_POST['init_plugin_suite_view_count_save'])) {
        check_admin_referer('init_plugin_suite_view_count_settings_action');

        $delay = isset($_POST['init_plugin_suite_view_count_delay']) ? absint($_POST['init_plugin_suite_view_count_delay']) : 15000;
        $scroll_percent = isset($_POST['init_plugin_suite_view_count_scroll_percent']) ? absint($_POST['init_plugin_suite_view_count_scroll_percent']) : 75;
        $scroll_enabled = isset($_POST['init_plugin_suite_view_count_scroll_enabled']) ? 1 : 0;

        $storage = isset($_POST['init_plugin_suite_view_count_storage']) ? sanitize_text_field(wp_unslash($_POST['init_plugin_suite_view_count_storage'])) : 'session';
        $storage = in_array($storage, ['local', 'session'], true) ? $storage : 'session';

        $post_types = isset($_POST['init_plugin_suite_view_count_post_types'])
            ? array_map('sanitize_text_field', (array) wp_unslash($_POST['init_plugin_suite_view_count_post_types']))
            : [];

        $enable_day   = !empty($_POST['init_plugin_suite_view_count_enable_day']) ? 1 : 0;
        $enable_week  = !empty($_POST['init_plugin_suite_view_count_enable_week']) ? 1 : 0;
        $enable_month = !empty($_POST['init_plugin_suite_view_count_enable_month']) ? 1 : 0;

        update_option('init_plugin_suite_view_count_delay', $delay);
        update_option('init_plugin_suite_view_count_scroll_percent', $scroll_percent);
        update_option('init_plugin_suite_view_count_scroll_enabled', $scroll_enabled);
        update_option('init_plugin_suite_view_count_storage', $storage);
        update_option('init_plugin_suite_view_count_post_types', $post_types);
        update_option('init_plugin_suite_view_count_enable_day', $enable_day);
        update_option('init_plugin_suite_view_count_enable_week', $enable_week);
        update_option('init_plugin_suite_view_count_enable_month', $enable_month);

        $batch_count = max(1, absint($_POST['init_plugin_suite_view_count_batch'] ?? 1));
        update_option('init_plugin_suite_view_count_batch', $batch_count);

        $disable_style = !empty($_POST['init_plugin_suite_view_count_disable_style']) ? 1 : 0;
        update_option('init_plugin_suite_view_count_disable_style', $disable_style);

        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Settings saved.', 'init-view-count') . '</p></div>';
    }

    $selected_post_types = (array) get_option('init_plugin_suite_view_count_post_types', ['post']);
    $all_post_types = get_post_types(['public' => true], 'objects');
    unset($all_post_types['attachment']);
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Init View Count Settings', 'init-view-count'); ?></h1>
        <form method="post">
            <?php wp_nonce_field('init_plugin_suite_view_count_settings_action'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e('Post types to track views', 'init-view-count'); ?></th>
                    <td>
                        <?php foreach ($all_post_types as $type) : ?>
                            <label>
                                <input type="checkbox" name="init_plugin_suite_view_count_post_types[]" value="<?php echo esc_attr($type->name); ?>" <?php checked(in_array($type->name, $selected_post_types)); ?> />
                                <?php echo esc_html($type->label); ?>
                            </label><br>
                        <?php endforeach; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Enable daily views?', 'init-view-count'); ?></th>
                    <td><input type="checkbox" name="init_plugin_suite_view_count_enable_day" <?php checked(get_option('init_plugin_suite_view_count_enable_day', 1)); ?> /></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Enable weekly views?', 'init-view-count'); ?></th>
                    <td><input type="checkbox" name="init_plugin_suite_view_count_enable_week" <?php checked(get_option('init_plugin_suite_view_count_enable_week', 1)); ?> /></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Enable monthly views?', 'init-view-count'); ?></th>
                    <td><input type="checkbox" name="init_plugin_suite_view_count_enable_month" <?php checked(get_option('init_plugin_suite_view_count_enable_month', 1)); ?> /></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Delay before counting (ms)', 'init-view-count'); ?></th>
                    <td><input type="number" name="init_plugin_suite_view_count_delay" value="<?php echo esc_attr(get_option('init_plugin_suite_view_count_delay', 15000)); ?>" /></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Scroll percent required', 'init-view-count'); ?></th>
                    <td><input type="number" name="init_plugin_suite_view_count_scroll_percent" value="<?php echo esc_attr(get_option('init_plugin_suite_view_count_scroll_percent', 75)); ?>" /></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Enable scroll check?', 'init-view-count'); ?></th>
                    <td><input type="checkbox" name="init_plugin_suite_view_count_scroll_enabled" <?php checked(get_option('init_plugin_suite_view_count_scroll_enabled', true)); ?> /></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Storage method', 'init-view-count'); ?></th>
                    <td>
                        <fieldset>
                            <legend class="screen-reader-text"><?php esc_html_e('Storage method', 'init-view-count'); ?></legend>
                            <label>
                                <input type="radio" name="init_plugin_suite_view_count_storage" value="session" <?php checked(get_option('init_plugin_suite_view_count_storage', 'session'), 'session'); ?> />
                                <?php esc_html_e('Session Storage', 'init-view-count'); ?>
                            </label><br>
                            <label>
                                <input type="radio" name="init_plugin_suite_view_count_storage" value="local" <?php checked(get_option('init_plugin_suite_view_count_storage', 'session'), 'local'); ?> />
                                <?php esc_html_e('Local Storage', 'init-view-count'); ?>
                            </label>
                        </fieldset>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Batch view tracking', 'init-view-count'); ?></th>
                    <td>
                        <input type="number" name="init_plugin_suite_view_count_batch"
                               value="<?php echo esc_attr(get_option('init_plugin_suite_view_count_batch', 1)); ?>"
                               min="1" />
                        <p class="description">
                            <?php esc_html_e('Number of views to collect before sending to server. Set to 1 for real-time tracking. Higher values reduce server requests but may miss some views if user leaves early.', 'init-view-count'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Disable plugin CSS?', 'init-view-count'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="init_plugin_suite_view_count_disable_style" <?php checked(get_option('init_plugin_suite_view_count_disable_style', 0)); ?> />
                            <?php esc_html_e('Disable built-in CSS output.', 'init-view-count'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('Check this if you want to fully control the styling yourself. The plugin will not enqueue any CSS.', 'init-view-count'); ?>
                        </p>
                    </td>
                </tr>
            </table>
            <?php submit_button(__('Save Settings', 'init-view-count'), 'primary', 'init_plugin_suite_view_count_save'); ?>
        </form>

        <h2><?php esc_html_e('Shortcode Builder', 'init-view-count'); ?></h2>
        <div id="shortcode-builder-target" data-plugin="init-view-count"></div>
    </div>
    <?php
}
