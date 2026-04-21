<?php
/**
 * Plugin Name: WP Announcements (Анонсы книг)
 * Description: Анонсы товаров WooCommerce с предзаказом и уведомлениями. Выбор «анонсных» категорий, модалка предзаказа, авто-рассылка при переводе в обычный каталог. Шорткоды [announcements_grid] и [announcements_page].
 * Version: 1.0.0
 * Author: Alexander Nemirov
 * Text Domain: wp-announcements
 * Requires Plugins: woocommerce
 */

if (!defined('ABSPATH')) exit;

define('WPAN_VERSION', '1.0.0');
define('WPAN_FILE', __FILE__);
define('WPAN_DIR', plugin_dir_path(__FILE__));
define('WPAN_URL', plugin_dir_url(__FILE__));
define('WPAN_OPT', 'wpan_settings');
define('WPAN_META_IS_ANNOUNCE', '_wpan_is_announcement');

// ==========================================
// АКТИВАЦИЯ: создание таблицы подписок
// ==========================================
register_activation_hook(__FILE__, 'wpan_activate');
function wpan_activate() {
    global $wpdb;
    $table = $wpdb->prefix . 'wpan_subs';
    $charset = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE IF NOT EXISTS {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        product_id BIGINT UNSIGNED NOT NULL,
        email VARCHAR(190) NOT NULL,
        phone VARCHAR(50) NULL,
        name VARCHAR(190) NULL,
        created_at DATETIME NOT NULL,
        notified_at DATETIME NULL,
        ip VARCHAR(45) NULL,
        PRIMARY KEY (id),
        KEY product_id (product_id),
        KEY email (email),
        UNIQUE KEY uniq_prod_email (product_id, email)
    ) {$charset};";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    // Дефолтные настройки
    if (!get_option(WPAN_OPT)) {
        add_option(WPAN_OPT, array(
            'categories'        => array(),
            'button_text'       => 'Предзаказ',
            'modal_title'       => 'Сообщить, когда появится',
            'modal_desc'        => 'Оставьте email — мы напишем, как только книга поступит в продажу.',
            'success_text'      => 'Спасибо! Мы сообщим вам по email.',
            'admin_email'       => get_option('admin_email'),
            'subject_user'      => 'Книга «{product}» поступила в продажу',
            'body_user'         => "Здравствуйте!\n\nКнига «{product}», которую вы ждали, появилась в продаже:\n{product_url}\n\nС уважением,\n{site_name}",
            'subject_admin_new' => 'Новая подписка на анонс: {product}',
            'subject_admin_rel' => 'Рассылка выполнена: {product} ({count} получателей)',
        ));
    }
}

function wpan_opt($key, $default = '') {
    $o = get_option(WPAN_OPT, array());
    return isset($o[$key]) ? $o[$key] : $default;
}

// ==========================================
// ОПРЕДЕЛЕНИЕ «АНОНСНОГО» ТОВАРА
// ==========================================
function wpan_is_announcement($product) {
    if (is_numeric($product)) $product = wc_get_product($product);
    if (!$product instanceof WC_Product) return false;
    $cat_ids = wpan_opt('categories', array());
    if (empty($cat_ids)) return false;
    $product_cats = $product->get_category_ids();
    if (empty($product_cats)) return false;
    return (bool) array_intersect(array_map('intval', $cat_ids), array_map('intval', $product_cats));
}

// ==========================================
// СИНХРОНИЗАЦИЯ ФЛАГА + АВТО-РАССЫЛКА ПРИ СНЯТИИ
// ==========================================
// При любой смене категорий — обновляем мета-флаг; если был анонс, а стал не анонс — рассылаем.
add_action('set_object_terms', 'wpan_on_terms_changed', 20, 6);
function wpan_on_terms_changed($object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids) {
    if ($taxonomy !== 'product_cat') return;
    $product = wc_get_product($object_id);
    if (!$product) return;

    $was_announce = (bool) get_post_meta($object_id, WPAN_META_IS_ANNOUNCE, '1');
    // Пересчитываем is_announcement по новым категориям
    $cat_ids = wpan_opt('categories', array());
    $new_cats = array_map('intval', $tt_ids);
    // tt_ids — term_taxonomy_id, нам нужны term_id:
    $new_term_ids = array();
    foreach ($tt_ids as $ttid) {
        $t = get_term_by('term_taxonomy_id', $ttid, 'product_cat');
        if ($t) $new_term_ids[] = (int) $t->term_id;
    }
    $is_announce_now = !empty(array_intersect(array_map('intval', $cat_ids), $new_term_ids));

    update_post_meta($object_id, WPAN_META_IS_ANNOUNCE, $is_announce_now ? '1' : '0');

    // Переход «был анонс → стал обычный»
    if ($was_announce && !$is_announce_now) {
        wpan_notify_subscribers($object_id);
    }
}

// Дополнительно: при сохранении товара приводим флаг в актуальное состояние
add_action('woocommerce_update_product', 'wpan_refresh_flag', 30, 1);
add_action('woocommerce_new_product', 'wpan_refresh_flag', 30, 1);
function wpan_refresh_flag($product_id) {
    update_post_meta($product_id, WPAN_META_IS_ANNOUNCE, wpan_is_announcement($product_id) ? '1' : '0');
}

// ==========================================
// РАССЫЛКА ПОДПИСЧИКАМ
// ==========================================
function wpan_notify_subscribers($product_id) {
    global $wpdb;
    $table = $wpdb->prefix . 'wpan_subs';
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$table} WHERE product_id = %d AND notified_at IS NULL", $product_id
    ));
    if (empty($rows)) return 0;

    $product = wc_get_product($product_id);
    if (!$product) return 0;

    $subj_tpl = wpan_opt('subject_user', 'Книга «{product}» поступила в продажу');
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

    // Письмо админу
    $admin = wpan_opt('admin_email', get_option('admin_email'));
    if ($admin) {
        $subj = strtr(wpan_opt('subject_admin_rel', 'Рассылка выполнена: {product} ({count} получателей)'),
            array('{product}' => $p_name, '{count}' => $count));
        wp_mail($admin, $subj, "Товар: {$p_name}\nСсылка: {$p_url}\nОтправлено писем: {$count}");
    }

    return $count;
}

// ==========================================
// ЗАМЕНА КНОПКИ «В КОРЗИНУ» → «ПРЕДЗАКАЗ» (каталог и карточка)
// ==========================================
// В каталоге (loop): заменяем add_to_cart на нашу кнопку (открывает модалку)
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

// В карточке: убираем стандартную кнопку, ставим свою
add_action('woocommerce_single_product_summary', 'wpan_single_replace_button', 29);
function wpan_single_replace_button() {
    global $product;
    if (!$product || !wpan_is_announcement($product)) return;
    // Снимаем стандартную кнопку
    remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30);
    // Свою ставим на 30
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

// Запрет покупки напрямую (защита даже если кнопка осталась)
add_filter('woocommerce_is_purchasable', 'wpan_not_purchasable', 10, 2);
function wpan_not_purchasable($purchasable, $product) {
    if (wpan_is_announcement($product)) return false;
    return $purchasable;
}

// ==========================================
// МОДАЛКА + JS + CSS (инлайн, без сборки)
// ==========================================
add_action('wp_enqueue_scripts', 'wpan_enqueue');
function wpan_enqueue() {
    wp_register_style('wpan', false);
    wp_enqueue_style('wpan');
    wp_add_inline_style('wpan', wpan_css());

    wp_register_script('wpan', '', array('jquery'), WPAN_VERSION, true);
    wp_enqueue_script('wpan');
    wp_add_inline_script('wpan', wpan_js());
    wp_localize_script('wpan', 'WPAN', array(
        'ajax'        => admin_url('admin-ajax.php'),
        'nonce'       => wp_create_nonce('wpan'),
        'modalTitle'  => wpan_opt('modal_title', 'Сообщить, когда появится'),
        'modalDesc'   => wpan_opt('modal_desc', ''),
        'successText' => wpan_opt('success_text', 'Спасибо!'),
        'btnText'     => wpan_opt('button_text', 'Предзаказ'),
    ));
}

function wpan_css() {
    return '
    .wpan-btn{background:#b7202e;color:#fff;border:0;}
    .wpan-btn:hover{background:#8f1823;color:#fff;}
    .wpan-overlay{position:fixed;inset:0;background:rgba(0,0,0,.55);display:none;align-items:center;justify-content:center;z-index:99999;}
    .wpan-overlay.wpan-open{display:flex;}
    .wpan-modal{background:#fff;max-width:440px;width:92%;padding:28px 28px 24px;border-radius:6px;position:relative;font-family:inherit;}
    .wpan-modal h3{margin:0 0 8px;font-size:22px;}
    .wpan-modal p{margin:0 0 16px;color:#555;font-size:14px;}
    .wpan-modal input[type=email],.wpan-modal input[type=text],.wpan-modal input[type=tel]{width:100%;padding:10px 12px;border:1px solid #ccc;border-radius:4px;margin-bottom:10px;font-size:15px;box-sizing:border-box;}
    .wpan-modal button[type=submit]{width:100%;padding:12px;background:#b7202e;color:#fff;border:0;border-radius:4px;font-size:15px;cursor:pointer;}
    .wpan-modal button[type=submit]:hover{background:#8f1823;}
    .wpan-modal .wpan-close{position:absolute;top:8px;right:12px;background:none;border:0;font-size:24px;cursor:pointer;color:#999;line-height:1;}
    .wpan-modal .wpan-close:hover{color:#000;}
    .wpan-msg{padding:10px;border-radius:4px;margin-bottom:10px;font-size:14px;display:none;}
    .wpan-msg.ok{background:#e6f4ea;color:#137333;display:block;}
    .wpan-msg.err{background:#fce8e6;color:#a50e0e;display:block;}
    /* Сетка анонсов */
    .wpan-grid{display:grid;gap:20px;grid-template-columns:repeat(var(--wpan-cols,4),1fr);}
    @media (max-width:900px){.wpan-grid{grid-template-columns:repeat(2,1fr);}}
    @media (max-width:520px){.wpan-grid{grid-template-columns:1fr;}}
    .wpan-card{border:1px solid #eee;border-radius:6px;overflow:hidden;background:#fff;display:flex;flex-direction:column;}
    .wpan-card__img{display:block;aspect-ratio:3/4;background:#f7f7f7;overflow:hidden;}
    .wpan-card__img img{width:100%;height:100%;object-fit:cover;display:block;}
    .wpan-card__body{padding:12px 14px 16px;display:flex;flex-direction:column;gap:8px;flex:1;}
    .wpan-card__title{font-size:15px;line-height:1.3;margin:0;}
    .wpan-card__title a{color:#111;text-decoration:none;}
    .wpan-card__title a:hover{color:#b7202e;}
    .wpan-card__author{font-size:13px;color:#666;}
    .wpan-card__btn{margin-top:auto;}
    .wpan-badge{display:inline-block;background:#b7202e;color:#fff;font-size:11px;padding:3px 8px;border-radius:3px;letter-spacing:.5px;text-transform:uppercase;}
    ';
}

function wpan_js() {
    return <<<'JS'
(function($){
    function buildModal(){
        if ($('#wpan-overlay').length) return;
        var html = ''
          + '<div id="wpan-overlay" class="wpan-overlay" role="dialog" aria-modal="true">'
          + '  <div class="wpan-modal">'
          + '    <button type="button" class="wpan-close" aria-label="Закрыть">×</button>'
          + '    <h3 id="wpan-title"></h3>'
          + '    <p id="wpan-desc"></p>'
          + '    <div class="wpan-msg" id="wpan-msg"></div>'
          + '    <form id="wpan-form">'
          + '      <input type="hidden" name="product_id" value="">'
          + '      <input type="text" name="name" placeholder="Ваше имя (необязательно)">'
          + '      <input type="email" name="email" placeholder="E-mail *" required>'
          + '      <input type="tel"  name="phone" placeholder="Телефон (необязательно)">'
          + '      <button type="submit"></button>'
          + '    </form>'
          + '  </div>'
          + '</div>';
        $('body').append(html);
        $('#wpan-title').text(WPAN.modalTitle);
        $('#wpan-desc').text(WPAN.modalDesc);
        $('#wpan-form button[type=submit]').text(WPAN.btnText);
    }
    function openModal(pid, title){
        buildModal();
        $('#wpan-form [name=product_id]').val(pid);
        $('#wpan-msg').removeClass('ok err').hide().text('');
        $('#wpan-form')[0].reset();
        $('#wpan-form [name=product_id]').val(pid);
        if (title) $('#wpan-title').text(WPAN.modalTitle + ': ' + title);
        else $('#wpan-title').text(WPAN.modalTitle);
        $('#wpan-overlay').addClass('wpan-open');
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
            phone:      $f.find('[name=phone]').val(),
            name:       $f.find('[name=name]').val()
        }).done(function(r){
            if (r && r.success) {
                $msg.addClass('ok').text(WPAN.successText).show();
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
    $pid   = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
    $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
    $phone = isset($_POST['phone']) ? sanitize_text_field(wp_unslash($_POST['phone'])) : '';
    $name  = isset($_POST['name'])  ? sanitize_text_field(wp_unslash($_POST['name']))  : '';

    if (!$pid || !get_post($pid)) wp_send_json_error('Неверный товар');
    if (!is_email($email))        wp_send_json_error('Неверный email');

    $table = $wpdb->prefix . 'wpan_subs';
    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$table} WHERE product_id=%d AND email=%s", $pid, $email
    ));
    if ($existing) {
        // Обновляем телефон/имя если пришли
        $wpdb->update($table, array(
            'phone' => $phone ?: null,
            'name'  => $name  ?: null,
        ), array('id' => $existing));
        wp_send_json_success('already');
    }

    $wpdb->insert($table, array(
        'product_id' => $pid,
        'email'      => $email,
        'phone'      => $phone ?: null,
        'name'       => $name  ?: null,
        'created_at' => current_time('mysql'),
        'ip'         => isset($_SERVER['REMOTE_ADDR']) ? substr($_SERVER['REMOTE_ADDR'], 0, 45) : null,
    ));

    // Админу — уведомление о новой подписке
    $admin = wpan_opt('admin_email', get_option('admin_email'));
    if ($admin) {
        $p_name = get_the_title($pid);
        $subj = strtr(wpan_opt('subject_admin_new', 'Новая подписка на анонс: {product}'),
            array('{product}' => $p_name));
        $body = "Новая подписка:\nТовар: {$p_name}\nEmail: {$email}\nТелефон: {$phone}\nИмя: {$name}\nСсылка: " . get_permalink($pid);
        wp_mail($admin, $subj, $body);
    }

    wp_send_json_success('ok');
}

// ==========================================
// ШОРТКОДЫ
// ==========================================
add_shortcode('announcements_grid', 'wpan_sc_grid');
add_shortcode('announcements_page', 'wpan_sc_page');

function wpan_get_announcement_query($args = array()) {
    $cat_ids = wpan_opt('categories', array());
    if (empty($cat_ids)) return new WP_Query(array('post__in' => array(0)));
    $defaults = array(
        'post_type'      => 'product',
        'posts_per_page' => 8,
        'post_status'    => 'publish',
        'orderby'        => 'date',
        'order'          => 'DESC',
        'tax_query'      => array(array(
            'taxonomy' => 'product_cat',
            'field'    => 'term_id',
            'terms'    => array_map('intval', $cat_ids),
        )),
    );
    return new WP_Query(wp_parse_args($args, $defaults));
}

function wpan_render_card($post_id) {
    $product = wc_get_product($post_id);
    if (!$product) return '';
    $label   = esc_html(wpan_opt('button_text', 'Предзаказ'));
    $url     = get_permalink($post_id);
    $title   = esc_html(get_the_title($post_id));
    $img     = get_the_post_thumbnail($post_id, 'woocommerce_thumbnail', array('loading'=>'lazy'));
    if (!$img) $img = '<img src="'.esc_url(wc_placeholder_img_src()).'" alt="">';
    // Автор — из meta _author или первой метки
    $author = '';
    $tags = wp_get_post_terms($post_id, 'product_tag', array('fields'=>'names'));
    if (!is_wp_error($tags) && !empty($tags)) $author = esc_html($tags[0]);

    ob_start(); ?>
    <div class="wpan-card">
        <a class="wpan-card__img" href="<?php echo esc_url($url); ?>"><?php echo $img; ?></a>
        <div class="wpan-card__body">
            <span class="wpan-badge">Анонс</span>
            <h3 class="wpan-card__title"><a href="<?php echo esc_url($url); ?>"><?php echo $title; ?></a></h3>
            <?php if ($author): ?><div class="wpan-card__author"><?php echo $author; ?></div><?php endif; ?>
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
        'posts_per_page' => (int) $a['limit'],
        'orderby'        => $a['orderby'],
        'order'          => $a['order'],
    ));

    if (!$q->have_posts()) return '';

    ob_start(); ?>
    <section class="wpan-section">
        <?php if ($a['title']): ?><h2 class="wpan-section__title"><?php echo esc_html($a['title']); ?></h2><?php endif; ?>
        <div class="wpan-grid" style="--wpan-cols:<?php echo (int) $a['columns']; ?>;">
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

    $paged = max(1, get_query_var('paged') ?: (isset($_GET['apage']) ? (int) $_GET['apage'] : 1));
    $q = wpan_get_announcement_query(array(
        'posts_per_page' => (int) $a['per_page'],
        'paged'          => $paged,
        'orderby'        => $a['orderby'],
        'order'          => $a['order'],
    ));

    ob_start(); ?>
    <div class="wpan-page">
        <?php if (!$q->have_posts()): ?>
            <p>Пока нет анонсов.</p>
        <?php else: ?>
            <div class="wpan-grid" style="--wpan-cols:<?php echo (int) $a['columns']; ?>;">
                <?php while ($q->have_posts()) { $q->the_post(); echo wpan_render_card(get_the_ID()); } ?>
            </div>
            <?php
            $big = 999999999;
            echo '<nav class="wpan-pagination">';
            echo paginate_links(array(
                'base'      => add_query_arg('apage', '%#%'),
                'format'    => '',
                'current'   => $paged,
                'total'     => $q->max_num_pages,
                'prev_text' => '←',
                'next_text' => '→',
            ));
            echo '</nav>';
            ?>
        <?php endif; ?>
    </div>
    <?php wp_reset_postdata();
    return ob_get_clean();
}

// ==========================================
// АДМИНКА: меню, настройки, список подписок
// ==========================================
add_action('admin_menu', 'wpan_admin_menu');
function wpan_admin_menu() {
    add_menu_page('Анонсы', 'Анонсы', 'manage_options', 'wpan', 'wpan_render_settings', 'dashicons-megaphone', 56);
    add_submenu_page('wpan', 'Настройки', 'Настройки', 'manage_options', 'wpan', 'wpan_render_settings');
    add_submenu_page('wpan', 'Подписки', 'Подписки', 'manage_options', 'wpan-subs', 'wpan_render_subs');
}

add_action('admin_init', 'wpan_register_settings');
function wpan_register_settings() {
    register_setting('wpan_group', WPAN_OPT, array('sanitize_callback' => 'wpan_sanitize'));
}
function wpan_sanitize($in) {
    $out = get_option(WPAN_OPT, array());
    $out['categories']        = isset($in['categories']) ? array_map('intval', (array) $in['categories']) : array();
    $out['button_text']       = sanitize_text_field($in['button_text'] ?? 'Предзаказ');
    $out['modal_title']       = sanitize_text_field($in['modal_title'] ?? '');
    $out['modal_desc']        = sanitize_textarea_field($in['modal_desc'] ?? '');
    $out['success_text']      = sanitize_text_field($in['success_text'] ?? '');
    $out['admin_email']       = sanitize_email($in['admin_email'] ?? get_option('admin_email'));
    $out['subject_user']      = sanitize_text_field($in['subject_user'] ?? '');
    $out['body_user']         = sanitize_textarea_field($in['body_user'] ?? '');
    $out['subject_admin_new'] = sanitize_text_field($in['subject_admin_new'] ?? '');
    $out['subject_admin_rel'] = sanitize_text_field($in['subject_admin_rel'] ?? '');
    return $out;
}

function wpan_render_settings() {
    if (!current_user_can('manage_options')) return;
    $o = get_option(WPAN_OPT, array());
    $cats = get_terms(array('taxonomy' => 'product_cat', 'hide_empty' => false));
    $selected = isset($o['categories']) ? array_map('intval', $o['categories']) : array();
    ?>
    <div class="wrap">
        <h1>Анонсы — настройки</h1>
        <form method="post" action="options.php">
            <?php settings_fields('wpan_group'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">Анонсные категории</th>
                    <td>
                        <select name="<?php echo WPAN_OPT; ?>[categories][]" multiple size="10" style="min-width:360px;">
                        <?php foreach ($cats as $c): ?>
                            <option value="<?php echo (int) $c->term_id; ?>" <?php echo in_array($c->term_id, $selected) ? 'selected' : ''; ?>>
                                <?php echo esc_html($c->name); ?> (<?php echo (int) $c->count; ?>)
                            </option>
                        <?php endforeach; ?>
                        </select>
                        <p class="description">Товары из выбранных категорий становятся анонсами (предзаказ вместо покупки). Ctrl/Cmd — мультивыбор.</p>
                    </td>
                </tr>
                <tr><th>Текст кнопки</th><td><input type="text" name="<?php echo WPAN_OPT; ?>[button_text]" value="<?php echo esc_attr($o['button_text'] ?? 'Предзаказ'); ?>" class="regular-text"></td></tr>
                <tr><th>Заголовок модалки</th><td><input type="text" name="<?php echo WPAN_OPT; ?>[modal_title]" value="<?php echo esc_attr($o['modal_title'] ?? ''); ?>" class="regular-text"></td></tr>
                <tr><th>Текст над формой</th><td><textarea name="<?php echo WPAN_OPT; ?>[modal_desc]" class="large-text" rows="2"><?php echo esc_textarea($o['modal_desc'] ?? ''); ?></textarea></td></tr>
                <tr><th>Текст «Спасибо»</th><td><input type="text" name="<?php echo WPAN_OPT; ?>[success_text]" value="<?php echo esc_attr($o['success_text'] ?? ''); ?>" class="regular-text"></td></tr>
                <tr><th>Email администратора</th><td><input type="email" name="<?php echo WPAN_OPT; ?>[admin_email]" value="<?php echo esc_attr($o['admin_email'] ?? ''); ?>" class="regular-text"></td></tr>
                <tr><th colspan="2"><h2>Письмо клиенту (при выходе из анонсов)</h2></th></tr>
                <tr><th>Тема</th><td><input type="text" name="<?php echo WPAN_OPT; ?>[subject_user]" value="<?php echo esc_attr($o['subject_user'] ?? ''); ?>" class="large-text"></td></tr>
                <tr><th>Текст</th><td><textarea name="<?php echo WPAN_OPT; ?>[body_user]" class="large-text" rows="6"><?php echo esc_textarea($o['body_user'] ?? ''); ?></textarea>
                    <p class="description">Переменные: <code>{product}</code>, <code>{product_url}</code>, <code>{site_name}</code></p></td></tr>
                <tr><th colspan="2"><h2>Письма администратору</h2></th></tr>
                <tr><th>Тема «новая подписка»</th><td><input type="text" name="<?php echo WPAN_OPT; ?>[subject_admin_new]" value="<?php echo esc_attr($o['subject_admin_new'] ?? ''); ?>" class="large-text"></td></tr>
                <tr><th>Тема «рассылка выполнена»</th><td><input type="text" name="<?php echo WPAN_OPT; ?>[subject_admin_rel]" value="<?php echo esc_attr($o['subject_admin_rel'] ?? ''); ?>" class="large-text">
                    <p class="description">Переменные: <code>{product}</code>, <code>{count}</code></p></td></tr>
            </table>
            <?php submit_button(); ?>
        </form>

        <h2>Шорткоды</h2>
        <p><code>[announcements_grid limit="8" columns="4" title="Скоро в продаже"]</code> — сетка для главной.</p>
        <p><code>[announcements_page per_page="24" columns="4"]</code> — страница со всеми анонсами и пагинацией.</p>
    </div>
    <?php
}

function wpan_render_subs() {
    if (!current_user_can('manage_options')) return;
    global $wpdb;
    $table = $wpdb->prefix . 'wpan_subs';

    // Ручной триггер рассылки
    if (isset($_POST['wpan_notify_pid']) && check_admin_referer('wpan_notify')) {
        $pid = (int) $_POST['wpan_notify_pid'];
        $n = wpan_notify_subscribers($pid);
        echo '<div class="updated notice"><p>Отправлено писем: '.(int)$n.'</p></div>';
    }
    if (isset($_POST['wpan_delete_id']) && check_admin_referer('wpan_delete')) {
        $wpdb->delete($table, array('id' => (int) $_POST['wpan_delete_id']));
    }

    $rows = $wpdb->get_results("SELECT * FROM {$table} ORDER BY created_at DESC LIMIT 500");
    ?>
    <div class="wrap">
        <h1>Подписки на анонсы</h1>
        <table class="widefat striped">
            <thead><tr><th>ID</th><th>Товар</th><th>Email</th><th>Телефон</th><th>Имя</th><th>Создано</th><th>Уведомлён</th><th></th></tr></thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="8">Подписок пока нет.</td></tr>
            <?php else: foreach ($rows as $r): ?>
                <tr>
                    <td><?php echo (int) $r->id; ?></td>
                    <td><a href="<?php echo esc_url(get_edit_post_link($r->product_id)); ?>"><?php echo esc_html(get_the_title($r->product_id)); ?></a></td>
                    <td><?php echo esc_html($r->email); ?></td>
                    <td><?php echo esc_html($r->phone); ?></td>
                    <td><?php echo esc_html($r->name); ?></td>
                    <td><?php echo esc_html($r->created_at); ?></td>
                    <td><?php echo $r->notified_at ? esc_html($r->notified_at) : '—'; ?></td>
                    <td>
                        <form method="post" style="display:inline">
                            <?php wp_nonce_field('wpan_delete'); ?>
                            <input type="hidden" name="wpan_delete_id" value="<?php echo (int) $r->id; ?>">
                            <button class="button-link-delete" onclick="return confirm('Удалить?')">удалить</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>

        <h2>Ручная рассылка</h2>
        <form method="post">
            <?php wp_nonce_field('wpan_notify'); ?>
            <p>
                <input type="number" name="wpan_notify_pid" placeholder="ID товара" required>
                <button class="button button-primary" onclick="return confirm('Разослать письма подписчикам этого товара?')">Разослать</button>
            </p>
            <p class="description">Отправит письмо всем неуведомлённым подписчикам товара. Обычно срабатывает автоматически при переводе товара из анонсов в обычный каталог.</p>
        </form>
    </div>
    <?php
}
