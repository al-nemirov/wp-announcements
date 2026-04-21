<?php
/**
 * Plugin Name: WP Announcements
 * Description: Универсальный плагин анонсов для WooCommerce: любые товары (книги, техника, одежда — что угодно) из выбранных категорий становятся непокупаемыми и получают кнопку «Предзаказ» с модалкой. Автоматическая рассылка подписчикам при переводе в обычный каталог. Шорткоды [announcements_grid] и [announcements_page].
 * Version: 1.2.0
 * Author: Alexander Nemirov
 * License: GPL-2.0-or-later
 * Text Domain: wp-announcements
 * Requires Plugins: woocommerce
 */

if (!defined('ABSPATH')) exit;

define('WPAN_VERSION', '1.2.0');
define('WPAN_FILE', __FILE__);
define('WPAN_DIR', plugin_dir_path(__FILE__));
define('WPAN_URL', plugin_dir_url(__FILE__));
define('WPAN_OPT', 'wpan_settings');
define('WPAN_META_IS_ANNOUNCE', '_wpan_is_announcement');
define('WPAN_META_NOTIFY_PENDING', '_wpan_notify_pending');
define('WPAN_META_MS_PATH', '_wpan_ms_path');
define('WPAN_CRON_HOOK', 'wpan_send_notifications');
define('WPAN_DB_VERSION', '2');

// Загрузка переводов
add_action('plugins_loaded', function () {
    load_plugin_textdomain('wp-announcements', false, dirname(plugin_basename(__FILE__)) . '/languages');
});

// ==========================================
// АКТИВАЦИЯ: создание/миграция таблицы подписок
// ==========================================
register_activation_hook(__FILE__, 'wpan_activate');
function wpan_activate() {
    wpan_install_schema();

    if (!get_option(WPAN_OPT)) {
        add_option(WPAN_OPT, wpan_default_settings());
    }
    if (!wp_next_scheduled(WPAN_CRON_HOOK)) {
        wp_schedule_event(time() + 60, 'hourly', WPAN_CRON_HOOK);
    }
}

register_deactivation_hook(__FILE__, 'wpan_deactivate');
function wpan_deactivate() {
    $ts = wp_next_scheduled(WPAN_CRON_HOOK);
    if ($ts) wp_unschedule_event($ts, WPAN_CRON_HOOK);
}

function wpan_install_schema() {
    global $wpdb;
    $table = $wpdb->prefix . 'wpan_subs';
    $charset = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        product_id BIGINT UNSIGNED NOT NULL,
        email VARCHAR(190) NOT NULL,
        name VARCHAR(190) NULL,
        created_at DATETIME NOT NULL,
        notified_at DATETIME NULL,
        ip VARCHAR(45) NULL,
        PRIMARY KEY  (id),
        KEY product_id (product_id),
        KEY email (email),
        UNIQUE KEY uniq_prod_email (product_id, email)
    ) {$charset};";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    // Миграция с v1: удаляем колонку phone, если была
    $cols = $wpdb->get_col("DESCRIBE {$table}", 0);
    if (is_array($cols) && in_array('phone', $cols, true)) {
        $wpdb->query("ALTER TABLE {$table} DROP COLUMN phone");
    }
    update_option('wpan_db_version', WPAN_DB_VERSION);
}

// Автомиграция при обновлении плагина
add_action('plugins_loaded', function () {
    if (get_option('wpan_db_version') !== WPAN_DB_VERSION) {
        wpan_install_schema();
    }
});

function wpan_default_settings() {
    return array(
        'ms_paths'          => array(), // строки, против которых сверяется pathName из МС (подстрока, case-insensitive)
        'categories'        => array(), // запасной вариант: product_cat в WP (если МС не используется)
        'button_text'       => __('Предзаказ', 'wp-announcements'),
        'modal_title'       => __('Сообщить, когда появится', 'wp-announcements'),
        'modal_desc'        => __('Оставьте email — мы напишем, как только товар появится в продаже.', 'wp-announcements'),
        'success_text'      => __('Спасибо! Мы сообщим вам по email.', 'wp-announcements'),
        'already_text'      => __('Вы уже подписаны на этот товар.', 'wp-announcements'),
        'admin_email'       => get_option('admin_email'),
        'accent_color'      => '#b7202e',
        'accent_hover'      => '#8f1823',
        'subject_user'      => __('Товар «{product}» поступил в продажу', 'wp-announcements'),
        'body_user'         => "Здравствуйте!\n\nТовар «{product}», который вы ждали, появился в продаже:\n{product_url}\n\nС уважением,\n{site_name}",
        'subject_admin_new' => __('Новая подписка на анонс: {product}', 'wp-announcements'),
        'subject_admin_rel' => __('Рассылка выполнена: {product} ({count} получателей)', 'wp-announcements'),
        'rate_limit_hour'   => 20, // подписок с одного IP в час
        'uninstall_wipe'    => 0,  // удалять ли таблицу и опции при деинсталляции
        'show_tags_in_card' => 1,  // показывать метки (например, авторов) в карточке
        'show_price_in_card'=> 0,  // показывать цену
        'badge_text'        => __('Анонс', 'wp-announcements'),
    );
}

function wpan_opt($key, $default = '') {
    static $cache = null;
    if ($cache === null) $cache = get_option(WPAN_OPT, array());
    if (!is_array($cache)) $cache = array();
    return array_key_exists($key, $cache) ? $cache[$key] : $default;
}

// После сохранения настроек — сбрасываем статический кеш
add_action('update_option_' . WPAN_OPT, function () {
    // Перечитать через новый запрос — проще прибить кеш через trigger_error? Нет, просто жмём reload:
    wp_cache_delete(WPAN_OPT, 'options');
});

// ==========================================
// ОПРЕДЕЛЕНИЕ «АНОНСНОГО» ТОВАРА
// ==========================================
function wpan_announce_cat_ids() {
    $ids = wpan_opt('categories', array());
    return is_array($ids) ? array_map('intval', $ids) : array();
}

function wpan_ms_paths() {
    $v = wpan_opt('ms_paths', array());
    if (is_string($v)) $v = preg_split('/\r\n|\n|\r/', $v);
    $v = array_filter(array_map('trim', (array) $v));
    return array_values($v);
}

function wpan_ms_path_matches($path_name) {
    $needles = wpan_ms_paths();
    if (empty($needles) || $path_name === '' || $path_name === null) return false;
    $hay = mb_strtolower((string) $path_name);
    foreach ($needles as $n) {
        $n_l = mb_strtolower($n);
        if ($n_l === '') continue;
        if (mb_strpos($hay, $n_l) !== false) return true;
    }
    return false;
}

function wpan_is_announcement($product) {
    if (is_numeric($product)) { $pid = (int) $product; $product = wc_get_product($product); }
    if (!$product instanceof WC_Product) return false;
    $pid = $pid ?? $product->get_id();

    // 1) Проверка по МС-пути (основной сценарий при интеграции с wooms)
    $ms_path = get_post_meta($pid, WPAN_META_MS_PATH, true);
    if ($ms_path && wpan_ms_path_matches($ms_path)) return true;

    // 2) Запасной вариант: совпадение WP-категории
    $cat_ids = wpan_announce_cat_ids();
    if (!empty($cat_ids)) {
        $product_cats = array_map('intval', $product->get_category_ids());
        if (!empty($product_cats) && array_intersect($cat_ids, $product_cats)) return true;
    }
    return false;
}

// ==========================================
// СИНХРОНИЗАЦИЯ ФЛАГА + ОЧЕРЕДЬ РАССЫЛКИ
// ==========================================
// Надёжный способ: при любом изменении product_cat на товаре — читаем АКТУАЛЬНЫЕ категории
// (а не $tt_ids, т.к. $append=true даёт только добавленные термы).
add_action('set_object_terms', 'wpan_on_terms_changed', 20, 6);
function wpan_on_terms_changed($object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids) {
    if ($taxonomy !== 'product_cat') return;
    if (get_post_type($object_id) !== 'product') return;
    wpan_refresh_flag($object_id);
}

// Актуализация флага при сохранении товара (страховка)
add_action('woocommerce_update_product', 'wpan_refresh_flag', 30, 1);
add_action('woocommerce_new_product',    'wpan_refresh_flag', 30, 1);
function wpan_refresh_flag($product_id) {
    $was = get_post_meta($product_id, WPAN_META_IS_ANNOUNCE, true) === '1';
    $now = wpan_is_announcement($product_id);
    update_post_meta($product_id, WPAN_META_IS_ANNOUNCE, $now ? '1' : '0');
    if ($was && !$now) wpan_queue_notify($product_id);
}

function wpan_queue_notify($product_id) {
    update_post_meta($product_id, WPAN_META_NOTIFY_PENDING, '1');
    if (!wp_next_scheduled(WPAN_CRON_HOOK)) {
        wp_schedule_single_event(time() + 30, WPAN_CRON_HOOK);
    }
}

// ==========================================
// ИНТЕГРАЦИЯ С WOOMS — сохраняем pathName и пересчитываем флаг
// ==========================================
add_filter('wooms_product_update', 'wpan_wooms_capture_path', 100, 2);
function wpan_wooms_capture_path($product, $row) {
    if (!$product instanceof WC_Product) return $product;
    $path = isset($row['pathName']) ? (string) $row['pathName'] : '';
    if ($path !== '') {
        $product->update_meta_data(WPAN_META_MS_PATH, $path);
    }
    return $product;
}

// После сохранения товара wooms — wpan_refresh_flag уже отработает через woocommerce_update_product.
// Но если wooms сохраняет иначе (save_post) — подстрахуемся отдельным хуком.
add_action('wooms_product_update_after', 'wpan_wooms_after_save', 20, 2);
function wpan_wooms_after_save($product_id, $row) {
    if (isset($row['pathName'])) {
        update_post_meta($product_id, WPAN_META_MS_PATH, (string) $row['pathName']);
    }
    wpan_refresh_flag($product_id);
}

// ==========================================
// CRON: обработка очереди рассылок
// ==========================================
add_action(WPAN_CRON_HOOK, 'wpan_cron_process_queue');
function wpan_cron_process_queue() {
    $pending = get_posts(array(
        'post_type'      => 'product',
        'post_status'    => 'any',
        'posts_per_page' => 20,
        'fields'         => 'ids',
        'meta_key'       => WPAN_META_NOTIFY_PENDING,
        'meta_value'     => '1',
    ));
    if (empty($pending)) return;
    foreach ($pending as $pid) {
        wpan_notify_subscribers($pid);
        delete_post_meta($pid, WPAN_META_NOTIFY_PENDING);
    }
}

// ==========================================
// РАССЫЛКА ПОДПИСЧИКАМ
// ==========================================
function wpan_notify_subscribers($product_id) {
    global $wpdb;
    $table = $wpdb->prefix . 'wpan_subs';
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT id, email FROM {$table} WHERE product_id = %d AND notified_at IS NULL", $product_id
    ));
    if (empty($rows)) return 0;

    $product = wc_get_product($product_id);
    if (!$product) return 0;

    $subj_tpl = wpan_opt('subject_user', '');
    $body_tpl = wpan_opt('body_user', '');
    $site     = get_bloginfo('name');
    $p_url    = get_permalink($product_id);
    $p_name   = $product->get_name();

    $vars = array(
        '{product}'     => $p_name,
        '{product_url}' => $p_url,
        '{site_name}'   => $site,
    );

    $count = 0;
    foreach ($rows as $row) {
        $subject = strtr($subj_tpl, $vars);
        $body    = strtr($body_tpl, $vars);
        $sent = wp_mail($row->email, $subject, $body);
        if ($sent) {
            $wpdb->update($table, array('notified_at' => current_time('mysql')), array('id' => $row->id));
            $count++;
        }
    }

    if ($count > 0) {
        $admin = wpan_opt('admin_email', get_option('admin_email'));
        if ($admin) {
            $subj = strtr(wpan_opt('subject_admin_rel', ''),
                array('{product}' => $p_name, '{count}' => $count));
            wp_mail($admin, $subj, "Товар: {$p_name}\nСсылка: {$p_url}\nОтправлено писем: {$count}");
        }
    }
    return $count;
}

// ==========================================
// ЗАМЕНА КНОПКИ «В КОРЗИНУ» → «ПРЕДЗАКАЗ»
// ==========================================
// 1) WC-совместимые темы
add_filter('woocommerce_loop_add_to_cart_link', 'wpan_loop_add_to_cart_link', 10, 2);
function wpan_loop_add_to_cart_link($html, $product) {
    if (!wpan_is_announcement($product)) return $html;
    $label = esc_html(wpan_opt('button_text', 'Предзаказ'));
    $pid = (int) $product->get_id();
    return sprintf(
        '<a href="#" data-wpan-open="%d" class="button wpan-btn wpan-btn--loop">%s</a>',
        $pid, $label
    );
}

// 2) Темы, которые рендерят свою кнопку, но используют текст/URL через фильтры WC
add_filter('woocommerce_product_add_to_cart_text', 'wpan_add_to_cart_text', 10, 2);
add_filter('woocommerce_product_single_add_to_cart_text', 'wpan_add_to_cart_text', 10, 2);
function wpan_add_to_cart_text($text, $product) {
    if (wpan_is_announcement($product)) {
        return wpan_opt('button_text', 'Предзаказ');
    }
    return $text;
}

add_filter('woocommerce_product_add_to_cart_url', 'wpan_add_to_cart_url', 10, 2);
function wpan_add_to_cart_url($url, $product) {
    if (wpan_is_announcement($product)) return '#';
    return $url;
}

// 3) На single-страницах — стандартная кнопка WC-шаблона: заменяем вручную
add_action('woocommerce_single_product_summary', 'wpan_single_replace_button', 29);
function wpan_single_replace_button() {
    global $product;
    if (!$product || !wpan_is_announcement($product)) return;
    remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30);
    add_action('woocommerce_single_product_summary', 'wpan_single_button_render', 30);
}
function wpan_single_button_render() {
    global $product;
    $label = esc_html(wpan_opt('button_text', 'Предзаказ'));
    $pid = (int) $product->get_id();
    echo '<div class="wpan-single">';
    echo '<button type="button" class="button alt wpan-btn wpan-btn--single" data-wpan-open="'.$pid.'">'.$label.'</button>';
    echo '</div>';
}

// Страховка: анонс нельзя купить даже прямым POST
add_filter('woocommerce_is_purchasable', 'wpan_not_purchasable', 10, 2);
function wpan_not_purchasable($purchasable, $product) {
    if (wpan_is_announcement($product)) return false;
    return $purchasable;
}

// ==========================================
// ФРОНТ: CSS + JS + локализация
// ==========================================
add_action('wp_enqueue_scripts', 'wpan_enqueue');
function wpan_enqueue() {
    wp_register_style('wpan', false, array(), WPAN_VERSION);
    wp_enqueue_style('wpan');
    wp_add_inline_style('wpan', wpan_css());

    wp_register_script('wpan', '', array('jquery'), WPAN_VERSION, true);
    wp_enqueue_script('wpan');
    wp_add_inline_script('wpan', wpan_js());
    wp_localize_script('wpan', 'WPAN', array(
        'ajax'        => admin_url('admin-ajax.php'),
        'nonce'       => wp_create_nonce('wpan'),
        'modalTitle'  => wpan_opt('modal_title', ''),
        'modalDesc'   => wpan_opt('modal_desc', ''),
        'successText' => wpan_opt('success_text', ''),
        'alreadyText' => wpan_opt('already_text', ''),
        'btnText'     => wpan_opt('button_text', ''),
    ));
}

function wpan_css() {
    $c  = wpan_opt('accent_color', '#b7202e');
    $ch = wpan_opt('accent_hover', '#8f1823');
    return "
    :root { --wpan-color: {$c}; --wpan-color-hover: {$ch}; }
    .wpan-btn{background:var(--wpan-color);color:#fff;border:0;cursor:pointer;}
    .wpan-btn:hover,.wpan-btn:focus{background:var(--wpan-color-hover);color:#fff;}
    .wpan-overlay{position:fixed;inset:0;background:rgba(0,0,0,.55);display:none;align-items:center;justify-content:center;z-index:99999;padding:16px;}
    .wpan-overlay.wpan-open{display:flex;}
    .wpan-modal{background:#fff;max-width:440px;width:100%;max-height:92vh;overflow-y:auto;padding:28px 28px 24px;border-radius:6px;position:relative;font-family:inherit;box-sizing:border-box;}
    .wpan-modal h3{margin:0 0 8px;font-size:22px;}
    .wpan-modal p{margin:0 0 16px;color:#555;font-size:14px;}
    .wpan-modal input[type=email],.wpan-modal input[type=text]{width:100%;padding:10px 12px;border:1px solid #ccc;border-radius:4px;margin-bottom:10px;font-size:15px;box-sizing:border-box;}
    .wpan-modal button[type=submit]{width:100%;padding:12px;background:var(--wpan-color);color:#fff;border:0;border-radius:4px;font-size:15px;cursor:pointer;}
    .wpan-modal button[type=submit]:hover{background:var(--wpan-color-hover);}
    .wpan-modal .wpan-close{position:absolute;top:8px;right:12px;background:none;border:0;font-size:24px;cursor:pointer;color:#999;line-height:1;}
    .wpan-modal .wpan-close:hover{color:#000;}
    .wpan-msg{padding:10px;border-radius:4px;margin-bottom:10px;font-size:14px;display:none;}
    .wpan-msg.ok{background:#e6f4ea;color:#137333;display:block;}
    .wpan-msg.err{background:#fce8e6;color:#a50e0e;display:block;}
    .wpan-hp{position:absolute !important;left:-9999px !important;opacity:0 !important;height:0 !important;width:0 !important;pointer-events:none;}
    .wpan-grid{display:grid;gap:20px;grid-template-columns:repeat(var(--wpan-cols,4),1fr);}
    @media (max-width:900px){.wpan-grid{grid-template-columns:repeat(2,1fr);}}
    @media (max-width:520px){.wpan-grid{grid-template-columns:1fr;}}
    .wpan-card{border:1px solid #eee;border-radius:6px;overflow:hidden;background:#fff;display:flex;flex-direction:column;}
    .wpan-card__img{display:block;aspect-ratio:3/4;background:#f7f7f7;overflow:hidden;}
    .wpan-card__img img{width:100%;height:100%;object-fit:cover;display:block;}
    .wpan-card__body{padding:12px 14px 16px;display:flex;flex-direction:column;gap:8px;flex:1;}
    .wpan-card__title{font-size:15px;line-height:1.3;margin:0;}
    .wpan-card__title a{color:#111;text-decoration:none;}
    .wpan-card__title a:hover{color:var(--wpan-color);}
    .wpan-card__author{font-size:13px;color:#666;}
    .wpan-card__btn{margin-top:auto;}
    .wpan-badge{display:inline-block;background:var(--wpan-color);color:#fff;font-size:11px;padding:3px 8px;border-radius:3px;letter-spacing:.5px;text-transform:uppercase;}
    .wpan-empty{padding:30px;text-align:center;color:#888;}
    .wpan-pagination{margin-top:24px;text-align:center;}
    .wpan-pagination .page-numbers{display:inline-block;padding:6px 12px;margin:0 2px;border:1px solid #ddd;border-radius:4px;color:#111;text-decoration:none;}
    .wpan-pagination .page-numbers.current{background:var(--wpan-color);color:#fff;border-color:var(--wpan-color);}
    ";
}

function wpan_js() {
    return <<<'JS'
(function($){
    function buildModal(){
        if ($('#wpan-overlay').length) return;
        var html = ''
          + '<div id="wpan-overlay" class="wpan-overlay" role="dialog" aria-modal="true">'
          + '  <div class="wpan-modal">'
          + '    <button type="button" class="wpan-close" aria-label="Close">×</button>'
          + '    <h3 id="wpan-title"></h3>'
          + '    <p id="wpan-desc"></p>'
          + '    <div class="wpan-msg" id="wpan-msg"></div>'
          + '    <form id="wpan-form" autocomplete="on">'
          + '      <input type="hidden" name="product_id" value="">'
          + '      <input type="text" name="name" placeholder="" autocomplete="name">'
          + '      <input type="email" name="email" placeholder="E-mail *" required autocomplete="email">'
          + '      <input type="text" name="website" class="wpan-hp" tabindex="-1" autocomplete="off">'
          + '      <button type="submit"></button>'
          + '    </form>'
          + '  </div>'
          + '</div>';
        $('body').append(html);
        $('#wpan-title').text(WPAN.modalTitle);
        $('#wpan-desc').text(WPAN.modalDesc);
        $('#wpan-form [name=name]').attr('placeholder', 'Имя (необязательно)');
        $('#wpan-form button[type=submit]').text(WPAN.btnText);
    }
    function openModal(pid, title){
        buildModal();
        var $f = $('#wpan-form');
        $f[0].reset();
        $f.find('[name=product_id]').val(pid);
        $('#wpan-msg').removeClass('ok err').hide().text('');
        if (title) $('#wpan-title').text(WPAN.modalTitle + ': ' + title);
        else $('#wpan-title').text(WPAN.modalTitle);
        $('#wpan-overlay').addClass('wpan-open');
        setTimeout(function(){ $f.find('[name=email]').focus(); }, 50);
    }
    function closeModal(){ $('#wpan-overlay').removeClass('wpan-open'); }

    $(document).on('click', '[data-wpan-open]', function(e){
        e.preventDefault();
        var pid = $(this).data('wpan-open');
        var title = $(this).closest('.product, .wpan-card, li.product').find('.woocommerce-loop-product__title, .wpan-card__title').first().text();
        openModal(pid, title);
    });
    $(document).on('click', '.wpan-close, .wpan-overlay', function(e){
        if (e.target === this) closeModal();
    });
    $(document).on('keydown', function(e){ if(e.key === 'Escape') closeModal(); });

    $(document).on('submit', '#wpan-form', function(e){
        e.preventDefault();
        var $f = $(this), $msg = $('#wpan-msg');
        $msg.removeClass('ok err').hide();
        $.post(WPAN.ajax, {
            action: 'wpan_subscribe',
            _wpnonce: WPAN.nonce,
            product_id: $f.find('[name=product_id]').val(),
            email:      $f.find('[name=email]').val(),
            name:       $f.find('[name=name]').val(),
            website:    $f.find('[name=website]').val()
        }).done(function(r){
            if (r && r.success) {
                var msg = (r.data === 'already') ? WPAN.alreadyText : WPAN.successText;
                $msg.addClass('ok').text(msg).show();
                setTimeout(closeModal, 1800);
            } else {
                $msg.addClass('err').text((r && r.data) ? r.data : 'Ошибка').show();
            }
        }).fail(function(){ $msg.addClass('err').text('Ошибка сети').show(); });
    });
})(jQuery);
JS;
}

// ==========================================
// AJAX: подписка
// ==========================================
add_action('wp_ajax_wpan_subscribe',        'wpan_ajax_subscribe');
add_action('wp_ajax_nopriv_wpan_subscribe', 'wpan_ajax_subscribe');
function wpan_ajax_subscribe() {
    check_ajax_referer('wpan', '_wpnonce');
    global $wpdb;

    // Honeypot
    $hp = isset($_POST['website']) ? trim((string) $_POST['website']) : '';
    if ($hp !== '') wp_send_json_success('ok'); // молча успех

    $pid   = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
    $email = isset($_POST['email']) ? strtolower(sanitize_email(wp_unslash($_POST['email']))) : '';
    $name  = isset($_POST['name'])  ? sanitize_text_field(wp_unslash($_POST['name']))         : '';

    if (!$pid || get_post_type($pid) !== 'product') wp_send_json_error(__('Неверный товар', 'wp-announcements'));
    if (!is_email($email))                           wp_send_json_error(__('Неверный email', 'wp-announcements'));

    // Rate-limit по IP
    $ip = isset($_SERVER['REMOTE_ADDR']) ? substr(sanitize_text_field($_SERVER['REMOTE_ADDR']), 0, 45) : '';
    $limit = (int) wpan_opt('rate_limit_hour', 20);
    if ($ip && $limit > 0) {
        $key = 'wpan_rl_' . md5($ip);
        $n = (int) get_transient($key);
        if ($n >= $limit) wp_send_json_error(__('Слишком много запросов. Попробуйте позже.', 'wp-announcements'));
        set_transient($key, $n + 1, HOUR_IN_SECONDS);
    }

    $table = $wpdb->prefix . 'wpan_subs';
    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$table} WHERE product_id=%d AND email=%s", $pid, $email
    ));
    if ($existing) {
        if ($name !== '') {
            $wpdb->update($table, array('name' => $name), array('id' => $existing));
        }
        wp_send_json_success('already');
    }

    $wpdb->insert($table, array(
        'product_id' => $pid,
        'email'      => $email,
        'name'       => $name !== '' ? $name : null,
        'created_at' => current_time('mysql'),
        'ip'         => $ip ?: null,
    ));

    // Уведомление админу о новой подписке
    $admin = wpan_opt('admin_email', get_option('admin_email'));
    if ($admin) {
        $p_name = get_the_title($pid);
        $subj = strtr(wpan_opt('subject_admin_new', ''), array('{product}' => $p_name));
        $body = "Новая подписка:\nТовар: {$p_name}\nEmail: {$email}\nИмя: {$name}\nСсылка: " . get_permalink($pid);
        wp_mail($admin, $subj, $body);
    }

    wp_send_json_success('ok');
}

// ==========================================
// ШОРТКОДЫ
// ==========================================
add_shortcode('announcements_grid', 'wpan_sc_grid');
add_shortcode('announcements_page', 'wpan_sc_page');

function wpan_sanitize_orderby($v) {
    $allowed = array('date', 'title', 'menu_order', 'rand', 'modified', 'ID');
    return in_array($v, $allowed, true) ? $v : 'date';
}
function wpan_sanitize_order($v) {
    $v = strtoupper((string) $v);
    return ($v === 'ASC') ? 'ASC' : 'DESC';
}

function wpan_get_announcement_query($args = array()) {
    $cat_ids = wpan_announce_cat_ids();
    if (empty($cat_ids)) return new WP_Query(array('post__in' => array(0), 'posts_per_page' => 1));
    $defaults = array(
        'post_type'      => 'product',
        'posts_per_page' => 8,
        'post_status'    => 'publish',
        'orderby'        => 'date',
        'order'          => 'DESC',
        'tax_query'      => array(array(
            'taxonomy'         => 'product_cat',
            'field'            => 'term_id',
            'terms'            => $cat_ids,
            'include_children' => true,
        )),
    );
    return new WP_Query(wp_parse_args($args, $defaults));
}

function wpan_get_authors($post_id) {
    $tags = wp_get_post_terms($post_id, 'product_tag', array('fields' => 'names'));
    if (is_wp_error($tags) || empty($tags)) return '';
    return implode(', ', array_map('esc_html', $tags));
}

function wpan_render_card($post_id) {
    $product = wc_get_product($post_id);
    if (!$product) return '';
    $label   = esc_html(wpan_opt('button_text', 'Предзаказ'));
    $url     = get_permalink($post_id);
    $title   = esc_html(get_the_title($post_id));
    $img     = get_the_post_thumbnail($post_id, 'woocommerce_thumbnail', array('loading' => 'lazy'));
    if (!$img) $img = '<img src="' . esc_url(wc_placeholder_img_src()) . '" alt="">';
    $tags    = wpan_opt('show_tags_in_card', 1) ? wpan_get_authors($post_id) : '';
    $badge   = esc_html(wpan_opt('badge_text', 'Анонс'));
    $price   = wpan_opt('show_price_in_card', 0) ? $product->get_price_html() : '';

    ob_start(); ?>
    <div class="wpan-card">
        <a class="wpan-card__img" href="<?php echo esc_url($url); ?>"><?php echo $img; ?></a>
        <div class="wpan-card__body">
            <?php if ($badge): ?><span class="wpan-badge"><?php echo $badge; ?></span><?php endif; ?>
            <h3 class="wpan-card__title"><a href="<?php echo esc_url($url); ?>"><?php echo $title; ?></a></h3>
            <?php if ($tags): ?><div class="wpan-card__author"><?php echo $tags; ?></div><?php endif; ?>
            <?php if ($price): ?><div class="wpan-card__price"><?php echo $price; ?></div><?php endif; ?>
            <div class="wpan-card__btn">
                <a href="#" class="button wpan-btn" data-wpan-open="<?php echo (int) $post_id; ?>"><?php echo $label; ?></a>
            </div>
        </div>
    </div>
    <?php return ob_get_clean();
}

function wpan_sc_grid($atts) {
    $a = shortcode_atts(array(
        'limit'   => 8,
        'columns' => 4,
        'orderby' => 'date',
        'order'   => 'DESC',
        'title'   => '',
    ), $atts, 'announcements_grid');

    $q = wpan_get_announcement_query(array(
        'posts_per_page' => max(1, (int) $a['limit']),
        'orderby'        => wpan_sanitize_orderby($a['orderby']),
        'order'          => wpan_sanitize_order($a['order']),
    ));
    if (!$q->have_posts()) return '';

    ob_start(); ?>
    <section class="wpan-section">
        <?php if ($a['title']): ?><h2 class="wpan-section__title"><?php echo esc_html($a['title']); ?></h2><?php endif; ?>
        <div class="wpan-grid" style="--wpan-cols:<?php echo max(1, (int) $a['columns']); ?>;">
            <?php while ($q->have_posts()) { $q->the_post(); echo wpan_render_card(get_the_ID()); } ?>
        </div>
    </section>
    <?php wp_reset_postdata();
    return ob_get_clean();
}

function wpan_sc_page($atts) {
    $a = shortcode_atts(array(
        'per_page' => 24,
        'columns'  => 4,
        'orderby'  => 'date',
        'order'    => 'DESC',
    ), $atts, 'announcements_page');

    $paged_qv = (int) get_query_var('paged');
    $paged_get = isset($_GET['apage']) ? absint(wp_unslash($_GET['apage'])) : 0;
    $paged = max(1, $paged_qv ?: $paged_get ?: 1);

    $q = wpan_get_announcement_query(array(
        'posts_per_page' => max(1, (int) $a['per_page']),
        'paged'          => $paged,
        'orderby'        => wpan_sanitize_orderby($a['orderby']),
        'order'          => wpan_sanitize_order($a['order']),
    ));

    ob_start(); ?>
    <div class="wpan-page">
        <?php if (!$q->have_posts()): ?>
            <p class="wpan-empty"><?php esc_html_e('Пока нет анонсов.', 'wp-announcements'); ?></p>
        <?php else: ?>
            <div class="wpan-grid" style="--wpan-cols:<?php echo max(1, (int) $a['columns']); ?>;">
                <?php while ($q->have_posts()) { $q->the_post(); echo wpan_render_card(get_the_ID()); } ?>
            </div>
            <?php if ($q->max_num_pages > 1): ?>
                <nav class="wpan-pagination"><?php
                    echo paginate_links(array(
                        'base'      => add_query_arg('apage', '%#%'),
                        'format'    => '',
                        'current'   => $paged,
                        'total'     => (int) $q->max_num_pages,
                        'prev_text' => '←',
                        'next_text' => '→',
                    ));
                ?></nav>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php wp_reset_postdata();
    return ob_get_clean();
}

// ==========================================
// АДМИНКА: меню, настройки, список подписок, CSV-экспорт
// ==========================================
add_action('admin_menu', 'wpan_admin_menu');
function wpan_admin_menu() {
    add_menu_page(__('Анонсы', 'wp-announcements'), __('Анонсы', 'wp-announcements'), 'manage_options', 'wpan', 'wpan_render_settings', 'dashicons-megaphone', 56);
    add_submenu_page('wpan', __('Подписки', 'wp-announcements'), __('Подписки', 'wp-announcements'), 'manage_options', 'wpan-subs', 'wpan_render_subs');
}

add_action('admin_init', 'wpan_register_settings');
function wpan_register_settings() {
    register_setting('wpan_group', WPAN_OPT, array('sanitize_callback' => 'wpan_sanitize'));
}
function wpan_sanitize($in) {
    $out = get_option(WPAN_OPT, wpan_default_settings());

    // МС-пути — textarea, по одному на строку
    $raw = isset($in['ms_paths']) ? (string) $in['ms_paths'] : '';
    $lines = preg_split('/\r\n|\n|\r/', $raw);
    $lines = array_values(array_filter(array_map(function ($l) {
        return sanitize_text_field(trim($l));
    }, $lines)));
    $out['ms_paths'] = $lines;

    $out['categories']        = isset($in['categories']) ? array_values(array_unique(array_map('intval', (array) $in['categories']))) : array();
    $out['button_text']       = sanitize_text_field($in['button_text']   ?? 'Предзаказ');
    $out['modal_title']       = sanitize_text_field($in['modal_title']   ?? '');
    $out['modal_desc']        = sanitize_textarea_field($in['modal_desc'] ?? '');
    $out['success_text']      = sanitize_text_field($in['success_text']  ?? '');
    $out['already_text']      = sanitize_text_field($in['already_text']  ?? '');
    $out['admin_email']       = sanitize_email($in['admin_email']        ?? get_option('admin_email'));
    $out['accent_color']      = sanitize_hex_color($in['accent_color']   ?? '#b7202e') ?: '#b7202e';
    $out['accent_hover']      = sanitize_hex_color($in['accent_hover']   ?? '#8f1823') ?: '#8f1823';
    $out['subject_user']      = sanitize_text_field($in['subject_user']  ?? '');
    $out['body_user']         = sanitize_textarea_field($in['body_user'] ?? '');
    $out['subject_admin_new'] = sanitize_text_field($in['subject_admin_new'] ?? '');
    $out['subject_admin_rel'] = sanitize_text_field($in['subject_admin_rel'] ?? '');
    $out['rate_limit_hour']   = max(0, (int) ($in['rate_limit_hour'] ?? 20));
    $out['uninstall_wipe']    = !empty($in['uninstall_wipe']) ? 1 : 0;
    $out['show_tags_in_card'] = !empty($in['show_tags_in_card']) ? 1 : 0;
    $out['show_price_in_card']= !empty($in['show_price_in_card']) ? 1 : 0;
    $out['badge_text']        = sanitize_text_field($in['badge_text'] ?? 'Анонс');
    return $out;
}

function wpan_render_settings() {
    if (!current_user_can('manage_options')) return;
    $o = wp_parse_args(get_option(WPAN_OPT, array()), wpan_default_settings());
    $cats = get_terms(array('taxonomy' => 'product_cat', 'hide_empty' => false));
    $selected = array_map('intval', (array) ($o['categories'] ?? array()));
    $opt = esc_attr(WPAN_OPT);
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Анонсы — настройки', 'wp-announcements'); ?></h1>
        <form method="post" action="options.php">
            <?php settings_fields('wpan_group'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Пути папок в МойСклад', 'wp-announcements'); ?></th>
                    <td>
                        <?php $ms_val = is_array($o['ms_paths'] ?? null) ? implode("\n", $o['ms_paths']) : (string) ($o['ms_paths'] ?? ''); ?>
                        <textarea name="<?php echo $opt; ?>[ms_paths]" rows="4" class="large-text code" placeholder="Анонсы&#10;Товары и услуги/Предзаказ"><?php echo esc_textarea($ms_val); ?></textarea>
                        <p class="description"><?php esc_html_e('По одному пути на строку. Сверяется как подстрока (case-insensitive) с полем pathName, которое wooms передаёт из МойСклад. Примеры: «Анонсы», «Товары и услуги/Анонсы». Если товар лежит внутри такой папки — он автоматически становится анонсом, независимо от WP-категории.', 'wp-announcements'); ?></p>
                        <?php
                        // Список обнаруженных pathName на товарах — чтобы можно было скопировать.
                        global $wpdb;
                        $seen = $wpdb->get_col($wpdb->prepare(
                            "SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> '' ORDER BY meta_value LIMIT 200",
                            WPAN_META_MS_PATH
                        ));
                        if (!empty($seen)): ?>
                            <details style="margin-top:8px;"><summary><?php esc_html_e('Обнаруженные МС-пути на товарах (клик — скопировать в буфер)', 'wp-announcements'); ?></summary>
                                <ul style="max-height:240px;overflow:auto;margin:6px 0;padding:6px;background:#f9f9f9;border:1px solid #ddd;">
                                <?php foreach ($seen as $p): ?>
                                    <li style="padding:2px 0;"><code style="cursor:pointer;" onclick="navigator.clipboard&&navigator.clipboard.writeText(this.textContent)"><?php echo esc_html($p); ?></code></li>
                                <?php endforeach; ?>
                                </ul>
                            </details>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('WP-категории (запасной вариант)', 'wp-announcements'); ?></th>
                    <td>
                        <select name="<?php echo $opt; ?>[categories][]" multiple size="8" style="min-width:360px;">
                        <?php foreach ((array) $cats as $c): ?>
                            <option value="<?php echo (int) $c->term_id; ?>" <?php selected(in_array((int) $c->term_id, $selected, true)); ?>>
                                <?php echo esc_html($c->name); ?> (<?php echo (int) $c->count; ?>)
                            </option>
                        <?php endforeach; ?>
                        </select>
                        <p class="description"><?php esc_html_e('Для сайтов без МойСклад (или дополнительно к МС-путям). Ctrl/Cmd — мультивыбор.', 'wp-announcements'); ?></p>
                    </td>
                </tr>
                <tr><th><?php esc_html_e('Текст кнопки', 'wp-announcements'); ?></th><td><input type="text" name="<?php echo $opt; ?>[button_text]" value="<?php echo esc_attr($o['button_text']); ?>" class="regular-text"></td></tr>
                <tr><th><?php esc_html_e('Заголовок модалки', 'wp-announcements'); ?></th><td><input type="text" name="<?php echo $opt; ?>[modal_title]" value="<?php echo esc_attr($o['modal_title']); ?>" class="regular-text"></td></tr>
                <tr><th><?php esc_html_e('Текст над формой', 'wp-announcements'); ?></th><td><textarea name="<?php echo $opt; ?>[modal_desc]" class="large-text" rows="2"><?php echo esc_textarea($o['modal_desc']); ?></textarea></td></tr>
                <tr><th><?php esc_html_e('Текст «Спасибо»', 'wp-announcements'); ?></th><td><input type="text" name="<?php echo $opt; ?>[success_text]" value="<?php echo esc_attr($o['success_text']); ?>" class="regular-text"></td></tr>
                <tr><th><?php esc_html_e('Текст «Уже подписаны»', 'wp-announcements'); ?></th><td><input type="text" name="<?php echo $opt; ?>[already_text]" value="<?php echo esc_attr($o['already_text']); ?>" class="regular-text"></td></tr>
                <tr><th><?php esc_html_e('Email администратора', 'wp-announcements'); ?></th><td><input type="email" name="<?php echo $opt; ?>[admin_email]" value="<?php echo esc_attr($o['admin_email']); ?>" class="regular-text"></td></tr>
                <tr><th><?php esc_html_e('Цвет кнопки', 'wp-announcements'); ?></th><td>
                    <input type="text" name="<?php echo $opt; ?>[accent_color]" value="<?php echo esc_attr($o['accent_color']); ?>" size="8"> /
                    hover <input type="text" name="<?php echo $opt; ?>[accent_hover]" value="<?php echo esc_attr($o['accent_hover']); ?>" size="8">
                </td></tr>
                <tr><th><?php esc_html_e('Лимит подписок с IP/час', 'wp-announcements'); ?></th><td><input type="number" min="0" name="<?php echo $opt; ?>[rate_limit_hour]" value="<?php echo (int) $o['rate_limit_hour']; ?>" class="small-text"> <span class="description"><?php esc_html_e('0 — без лимита', 'wp-announcements'); ?></span></td></tr>

                <tr><th colspan="2"><h2><?php esc_html_e('Карточка в сетке', 'wp-announcements'); ?></h2></th></tr>
                <tr><th><?php esc_html_e('Текст бейджа', 'wp-announcements'); ?></th><td><input type="text" name="<?php echo $opt; ?>[badge_text]" value="<?php echo esc_attr($o['badge_text']); ?>" class="regular-text"> <span class="description"><?php esc_html_e('например: Анонс, Soon, Preorder. Пусто — скрыть.', 'wp-announcements'); ?></span></td></tr>
                <tr><th><?php esc_html_e('Показывать метки', 'wp-announcements'); ?></th><td><label><input type="checkbox" name="<?php echo $opt; ?>[show_tags_in_card]" value="1" <?php checked($o['show_tags_in_card']); ?>> <?php esc_html_e('product_tag через запятую (для книг — авторы)', 'wp-announcements'); ?></label></td></tr>
                <tr><th><?php esc_html_e('Показывать цену', 'wp-announcements'); ?></th><td><label><input type="checkbox" name="<?php echo $opt; ?>[show_price_in_card]" value="1" <?php checked($o['show_price_in_card']); ?>> <?php esc_html_e('вывод стандартной цены WC в карточке', 'wp-announcements'); ?></label></td></tr>

                <tr><th colspan="2"><h2><?php esc_html_e('Письмо клиенту (при выходе из анонсов)', 'wp-announcements'); ?></h2></th></tr>
                <tr><th><?php esc_html_e('Тема', 'wp-announcements'); ?></th><td><input type="text" name="<?php echo $opt; ?>[subject_user]" value="<?php echo esc_attr($o['subject_user']); ?>" class="large-text"></td></tr>
                <tr><th><?php esc_html_e('Текст', 'wp-announcements'); ?></th><td><textarea name="<?php echo $opt; ?>[body_user]" class="large-text" rows="6"><?php echo esc_textarea($o['body_user']); ?></textarea>
                    <p class="description"><?php esc_html_e('Переменные:', 'wp-announcements'); ?> <code>{product}</code>, <code>{product_url}</code>, <code>{site_name}</code></p></td></tr>

                <tr><th colspan="2"><h2><?php esc_html_e('Письма администратору', 'wp-announcements'); ?></h2></th></tr>
                <tr><th><?php esc_html_e('Тема «новая подписка»', 'wp-announcements'); ?></th><td><input type="text" name="<?php echo $opt; ?>[subject_admin_new]" value="<?php echo esc_attr($o['subject_admin_new']); ?>" class="large-text"></td></tr>
                <tr><th><?php esc_html_e('Тема «рассылка выполнена»', 'wp-announcements'); ?></th><td><input type="text" name="<?php echo $opt; ?>[subject_admin_rel]" value="<?php echo esc_attr($o['subject_admin_rel']); ?>" class="large-text">
                    <p class="description"><?php esc_html_e('Переменные:', 'wp-announcements'); ?> <code>{product}</code>, <code>{count}</code></p></td></tr>

                <tr><th colspan="2"><h2><?php esc_html_e('Прочее', 'wp-announcements'); ?></h2></th></tr>
                <tr><th><?php esc_html_e('При удалении плагина', 'wp-announcements'); ?></th><td><label><input type="checkbox" name="<?php echo $opt; ?>[uninstall_wipe]" value="1" <?php checked($o['uninstall_wipe']); ?>> <?php esc_html_e('удалить таблицу подписок и настройки', 'wp-announcements'); ?></label></td></tr>
            </table>
            <?php submit_button(); ?>
        </form>

        <h2><?php esc_html_e('Шорткоды', 'wp-announcements'); ?></h2>
        <p><code>[announcements_grid limit="8" columns="4" title="Скоро в продаже"]</code></p>
        <p><code>[announcements_page per_page="24" columns="4"]</code></p>
    </div>
    <?php
}

// CSV-экспорт подписок
add_action('admin_init', function () {
    if (!current_user_can('manage_options')) return;
    if (empty($_GET['wpan_export']) || $_GET['wpan_export'] !== 'csv') return;
    if (!check_admin_referer('wpan_export')) return;

    global $wpdb;
    $table = $wpdb->prefix . 'wpan_subs';
    $rows = $wpdb->get_results("SELECT * FROM {$table} ORDER BY created_at DESC", ARRAY_A);

    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=wpan-subs-' . date('Y-m-d') . '.csv');
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF"); // BOM для Excel
    fputcsv($out, array('id', 'product_id', 'product', 'email', 'name', 'created_at', 'notified_at', 'ip'));
    foreach ((array) $rows as $r) {
        fputcsv($out, array(
            $r['id'], $r['product_id'], get_the_title($r['product_id']),
            $r['email'], $r['name'], $r['created_at'], $r['notified_at'], $r['ip'],
        ));
    }
    fclose($out);
    exit;
});

function wpan_render_subs() {
    if (!current_user_can('manage_options')) return;
    global $wpdb;
    $table = $wpdb->prefix . 'wpan_subs';

    if (isset($_POST['wpan_notify_pid']) && check_admin_referer('wpan_notify')) {
        $pid = (int) $_POST['wpan_notify_pid'];
        if ($pid > 0) {
            $n = wpan_notify_subscribers($pid);
            echo '<div class="updated notice"><p>' . esc_html(sprintf(__('Отправлено писем: %d', 'wp-announcements'), $n)) . '</p></div>';
        }
    }
    if (isset($_POST['wpan_delete_id']) && check_admin_referer('wpan_delete')) {
        $wpdb->delete($table, array('id' => (int) $_POST['wpan_delete_id']));
    }

    $per_page = 50;
    $paged = max(1, isset($_GET['pp']) ? (int) $_GET['pp'] : 1);
    $offset = ($paged - 1) * $per_page;
    $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d OFFSET %d",
        $per_page, $offset
    ));
    $pages = max(1, (int) ceil($total / $per_page));

    $export_url = wp_nonce_url(add_query_arg(array('wpan_export' => 'csv'), admin_url('admin.php?page=wpan-subs')), 'wpan_export');
    ?>
    <div class="wrap">
        <h1 class="wp-heading-inline"><?php esc_html_e('Подписки на анонсы', 'wp-announcements'); ?></h1>
        <a href="<?php echo esc_url($export_url); ?>" class="page-title-action"><?php esc_html_e('Экспорт CSV', 'wp-announcements'); ?></a>
        <p class="description"><?php echo esc_html(sprintf(__('Всего записей: %d', 'wp-announcements'), $total)); ?></p>

        <table class="widefat striped">
            <thead><tr>
                <th>ID</th>
                <th><?php esc_html_e('Товар', 'wp-announcements'); ?></th>
                <th>Email</th>
                <th><?php esc_html_e('Имя', 'wp-announcements'); ?></th>
                <th><?php esc_html_e('Создано', 'wp-announcements'); ?></th>
                <th><?php esc_html_e('Уведомлён', 'wp-announcements'); ?></th>
                <th></th>
            </tr></thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="7"><?php esc_html_e('Подписок пока нет.', 'wp-announcements'); ?></td></tr>
            <?php else: foreach ($rows as $r):
                $edit = get_edit_post_link($r->product_id);
                $title = get_the_title($r->product_id);
            ?>
                <tr>
                    <td><?php echo (int) $r->id; ?></td>
                    <td><?php if ($edit): ?><a href="<?php echo esc_url($edit); ?>"><?php echo esc_html($title); ?></a><?php else: ?><?php echo esc_html($title ?: '(#' . (int) $r->product_id . ')'); ?><?php endif; ?></td>
                    <td><?php echo esc_html($r->email); ?></td>
                    <td><?php echo esc_html($r->name); ?></td>
                    <td><?php echo esc_html($r->created_at); ?></td>
                    <td><?php echo $r->notified_at ? esc_html($r->notified_at) : '—'; ?></td>
                    <td>
                        <form method="post" style="display:inline">
                            <?php wp_nonce_field('wpan_delete'); ?>
                            <input type="hidden" name="wpan_delete_id" value="<?php echo (int) $r->id; ?>">
                            <button class="button-link-delete" onclick="return confirm('<?php echo esc_js(__('Удалить?', 'wp-announcements')); ?>')"><?php esc_html_e('удалить', 'wp-announcements'); ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>

        <?php if ($pages > 1): ?>
            <div class="tablenav"><div class="tablenav-pages"><?php
                echo paginate_links(array(
                    'base'      => add_query_arg('pp', '%#%'),
                    'format'    => '',
                    'current'   => $paged,
                    'total'     => $pages,
                    'prev_text' => '‹',
                    'next_text' => '›',
                ));
            ?></div></div>
        <?php endif; ?>

        <h2><?php esc_html_e('Ручная рассылка', 'wp-announcements'); ?></h2>
        <form method="post">
            <?php wp_nonce_field('wpan_notify'); ?>
            <p>
                <input type="number" name="wpan_notify_pid" min="1" placeholder="<?php esc_attr_e('ID товара', 'wp-announcements'); ?>" required>
                <button class="button button-primary" onclick="return confirm('<?php echo esc_js(__('Разослать письма подписчикам этого товара?', 'wp-announcements')); ?>')"><?php esc_html_e('Разослать', 'wp-announcements'); ?></button>
            </p>
            <p class="description"><?php esc_html_e('Отправит письмо всем неуведомлённым подписчикам товара. Обычно срабатывает автоматически при переводе товара из анонсов в обычный каталог.', 'wp-announcements'); ?></p>
        </form>
    </div>
    <?php
}
