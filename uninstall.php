<?php
/**
 * Выполняется при удалении плагина из админки WordPress.
 * Удаляет таблицу и опции только если в настройках включена соответствующая галочка.
 */
if (!defined('WP_UNINSTALL_PLUGIN')) exit;

$o = get_option('wpan_settings', array());
$wipe = !empty($o['uninstall_wipe']);

// В любом случае снимаем Cron
$hook = 'wpan_send_notifications';
$ts = wp_next_scheduled($hook);
if ($ts) wp_unschedule_event($ts, $hook);

if (!$wipe) return;

global $wpdb;
$table = $wpdb->prefix . 'wpan_subs';
$wpdb->query("DROP TABLE IF EXISTS {$table}");

delete_option('wpan_settings');
delete_option('wpan_db_version');

// Чистим мета-флаги на товарах
delete_post_meta_by_key('_wpan_is_announcement');
delete_post_meta_by_key('_wpan_notify_pending');
