<?php
if (!defined('WP_UNINSTALL_PLUGIN')) exit;
global $wpdb;
delete_option('ofd_client_login');
delete_option('ofd_client_pass');
delete_option('ofd_order_status');
delete_option('ofd_token');
delete_option('ofd_nds');
delete_option('ofd_nalog');
delete_option('ofd_inn');
delete_option('ofd_email');
delete_option('ofd_collapse');
delete_option('ofd_collapse_name');
delete_option('ofd_token');
delete_option('ofd_token_exp_date');
$wpdb->query('DROP TABLE IF EXISTS `'.$wpdb->prefix.'ofd_checks_list`');