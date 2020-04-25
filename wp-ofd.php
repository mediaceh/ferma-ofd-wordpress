<?php
/*
Plugin Name: Ferma OFD.ru
Plugin URI: 
Description: Этот модуль обеспечивает онлайн-регистрацию чеков, созданных в вашем интернет-магазине, через службу OFD.ru Ferma (https://ofd.ru) в соответствии с последним изданием Федерального закона №54-ФЗ (https://o fd.ru/baza-znaniy/54-fz).
Version: 1.0
Author: Maksim Stepanov
Author URI: http://www.mediaceh.ru
 */
?>
<?php

define('OFD_TABLE_NAME','ofd_checks_list');
define('OFD_API_URL','https://ferma.ofd.ru/api/kkt/cloud');
define('OFD_AUTH_URL','https://ferma.ofd.ru/api/Authorization/CreateAuthToken');
define('WP_ofd_DIR', plugin_dir_path(__FILE__));
define('WP_ofd_URL', plugin_dir_url(__FILE__));

include_once('wp-ofd-class.php');

function ofd_add_flash_notice( $notice = "", $type = "warning", $dismissible = true ) 
{
	$notices = get_option( "ofd_flash_notices", array() );
	$dismissible_text = ( $dismissible ) ? "is-dismissible" : "";
	array_push( $notices, array( 
		"notice" => $notice, 
		"type" => $type, 
		"dismissible" => $dismissible_text
	) );
	update_option("ofd_flash_notices", $notices );
}

function ofd_display_flash_notices() 
{
	$notices = get_option( "ofd_flash_notices", array() );
	foreach ( $notices as $notice ) {
		printf('<div class="notice notice-%1$s %2$s"><p>%3$s</p></div>',
		$notice['type'],
		$notice['dismissible'],
		$notice['notice']
		);
	}
	if( ! empty( $notices ) ) {
		delete_option( "ofd_flash_notices", array() );
	}
}

add_action( 'admin_notices', 'ofd_display_flash_notices', 12 );




function CreateTableOFD()
{
	global $wpdb;
    $wpdb->query("CREATE TABLE `".$wpdb->prefix.OFD_TABLE_NAME."` (
	  `id` varchar(36) NOT NULL,
	  `type` varchar(100) NOT NULL,
	  `status` varchar(100) DEFAULT NULL,
	  `status_message` varchar(100) DEFAULT NULL,
	  `order_id` int(11) NOT NULL,
	  `total` float(10,2) NOT NULL,
	  `FN` varchar(100) DEFAULT NULL,
	  `RNM` varchar(100) DEFAULT NULL,
	  `FDN` varchar(100) DEFAULT NULL,
	  `FPD` varchar(100) DEFAULT NULL,
	  `created_at` datetime NOT NULL,
	  `updated_at` datetime NOT NULL,
	  PRIMARY KEY (`id`)
	 ) ENGINE=MyISAM DEFAULT CHARSET=utf8;");
}

add_action('admin_menu', 'ofd_create_menu');

function ofd_errors_display($message)
{
	echo '<div class="notice notice-error">
            <p>'.$message.'</p>
     </div>';
}
function ofd_errors()
{
	if(get_option('ofd_client_login')=='') {
		ofd_errors_display("Для работы с ОФД заполните поле 'Логин'");
	}
	if(get_option('ofd_client_pass')=='') {
		ofd_errors_display("Для работы с ОФД заполните поле 'Пароль'");
	}
	if(get_option('ofd_inn')=='') {
		ofd_errors_display("Для работы с ОФД заполните поле 'ИНН организации'");
	}
	if(get_option('ofd_nalog')=='') {
		ofd_errors_display("Для работы с ОФД заполните поле 'Система налогообложения'");
	}
	if(get_option('ofd_nds')=='') {
		ofd_errors_display("Для работы с ОФД заполните поле 'Ставка НДС по умолчанию'");
	}	
}

function ofd_create_menu()
{
    add_menu_page("OFD.ru", "OFD.ru", "administrator", WP_ofd_DIR . 'listchecks.php', null, null);
    add_submenu_page(WP_ofd_DIR . 'listchecks.php', 'Реестр чеков', 'Реестр чеков', 'administrator', WP_ofd_DIR . 'listchecks.php');
    add_submenu_page(WP_ofd_DIR . 'listchecks.php', 'Настройки', 'Настройки', 'administrator', __FILE__, 'ofd_settings_page');
    add_action('admin_init', 'register_ofd_settings');
}


function register_ofd_settings()
{
    register_setting('ofd-settings-group', 'ofd_client_login');
    register_setting('ofd-settings-group', 'ofd_client_pass');
    register_setting('ofd-settings-group', 'ofd_order_status');
    register_setting('ofd-settings-group', 'ofd_nds');
	register_setting('ofd-settings-group', 'ofd_nalog');
	register_setting('ofd-settings-group', 'ofd_inn');
	register_setting('ofd-settings-group', 'ofd_email');
	register_setting('ofd-settings-group', 'ofd_collapse');
	register_setting('ofd-settings-group', 'ofd_collapse_name');
	register_setting('ofd-settings-group', 'ofd_token');
	register_setting('ofd-settings-group', 'ofd_token_exp_date');
}


function sv_wc_add_order_sale_check_action( $actions ) 
{
    global $theorder;
    $actions['wc_custom_order_action_2'] = 'Оформить чек прихода (OFD.ru)';
    return $actions;
}

add_action( 'woocommerce_order_actions', 'sv_wc_add_order_sale_check_action' );

function sv_wc_add_order_return_check_action( $actions ) 
{
    global $theorder;
    $actions['wc_custom_order_action'] = 'Оформить чек возврата (OFD.ru)';
    return $actions;
}
add_action( 'woocommerce_order_actions', 'sv_wc_add_order_return_check_action' );


function OFD_main($order_id,$type)
{
	$ofd = new wp_ofd_class();
	$ofd->enableFlashNotices();
	if($ofd->OFDregCheckManually($order_id,$type)) {
		do_action('HavePendingChecksOFD');
	}
}


function sv_wc_process_order_sale_check_action_ofd( $order ) 
{
    OFD_main($order->id,'Income');
}

add_action( 'woocommerce_order_action_wc_custom_order_action_2', 'sv_wc_process_order_sale_check_action_ofd' );

function sv_wc_process_order_return_check_action_ofd( $order ) 
{
    OFD_main($order->id,'IncomeReturn');
}
add_action( 'woocommerce_order_action_wc_custom_order_action', 'sv_wc_process_order_return_check_action_ofd' );

function OFDregCheckAuto($order_id)
{
	OFD_main($order_id,'Income');
}

$orderStatusOFD = get_option('ofd_order_status');

if ($orderStatusOFD != '') {
    add_action('woocommerce_order_status_' . $orderStatusOFD, 'OFDregCheckAuto');
}


add_filter( 'cron_schedules', 'cron_add_five_second_ofd' );

function cron_add_five_second_ofd( $schedules ) {
    $schedules['five_second_ofd'] = array(
        'interval' => 5,
        'display' => 'Раз в 5 секунд'
    );
    return $schedules;
}


register_activation_hook(__FILE__, 'ofd_activation');

function ofd_activation() 
{
   CreateTableOFD();
}

register_deactivation_hook( __FILE__, 'deactivation_update_ofd_check');


function deactivation_update_ofd_check() {
    wp_clear_scheduled_hook('update_ofd_check');
}


add_action('HavePendingChecksOFD', 'my_cron_activation_pending_ofd');

function my_cron_activation_pending_ofd() {
    if ( wp_next_scheduled( 'update_ofd_check' ) === false ) {
        wp_schedule_event( time(), 'five_second_ofd', 'update_ofd_check');
    }
}

add_action('update_ofd_check', 'UpdateChecksStatusOFD');

function UpdateChecksStatusOFD() 
{
	$ofd = new wp_ofd_class();
	$ofd->UpdateChecksStatus();
	if(!$ofd->getCountPendingChecks()) {
		deactivation_update_ofd_check();
	} 	
}

add_action('woocommerce_product_options_general_product_data', 'ofd_product_custom_fields');

add_action('woocommerce_process_product_meta', 'ofd_product_custom_fields_save');

function ofd_product_custom_fields()
{
    global $woocommerce, $post;
    echo '<div class="product_custom_field">';
	 woocommerce_wp_select( 
        array( 
            'id'      => '_ofd_nds', 
            'label'   => __( 'НДС (OFD.ru)', 'woocommerce'),
            'options' =>  array('' => 'Из настроек плагина','Vat0' => 'Без НДС','Vat10' => '10%','Vat18' => '18%',),
            )
        );
    echo '</div>';

}
function ofd_product_custom_fields_save($post_id)
{   
    if (isset($_POST['_ofd_nds'])) {
		update_post_meta($post_id, '_ofd_nds', esc_attr($_POST['_ofd_nds']));
	}
}


function ofd_admin_footer_text () {
   echo '<i>Спасибо за сотудничество с <a href="http://OFD.ru" target="_blank">OFD.ru</a></i> ';
}
add_filter('admin_footer_text', 'ofd_admin_footer_text');

							

function ofd_settings_page()
{
	$ofd = new wp_ofd_class();
	$ofd->clearToken();
	$ofd->setAuthToken();
	ofd_errors();
	?>
    <div class="wrap">
        <h2>Настройки</h2>
		<?php if(explode('&',$_SERVER['QUERY_STRING'])[1]!=''):  ?>
			<?php $ofd->echoSuccess('Настройки обновлены.');  ?>
		<?php endif; ?>
        <form method="post" action="options.php">
            <?php settings_fields('ofd-settings-group'); ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">Логин</th>
                    <td><input type="text" name="ofd_client_login" value="<?php echo get_option('ofd_client_login'); ?>"/>
                    </td>
                </tr>

                <tr valign="top">
                    <th scope="row">Пароль</th>
                    <td><input type="text" name="ofd_client_pass" value="<?php echo get_option('ofd_client_pass'); ?>"/></td>
                </tr>

				<tr valign="top">
                    <th scope="row">ИНН организации</th>
                    <td><input type="text" name="ofd_inn" value="<?php echo get_option('ofd_inn'); ?>"/></td>
                </tr>
				
				<tr valign="top">
                    <th scope="row">Адрес e-mail для уведомлений</th>
                    <td><input type="text" name="ofd_email" value="<?php echo get_option('ofd_email'); ?>"/></td>
                </tr>
				
				<tr valign="top">
                    <th scope="row">Система налогообложения</th>
                    <td>
						<select name="ofd_nalog">
                            <option value="" <?php if(get_option('ofd_nalog')=='') echo 'selected';?>>Не выбрано</option>
							<option value="Common" <?php if(get_option('ofd_nalog')=='Common') echo 'selected';?>>Общая система налогообложения</option>
							<option value="SimpleIn" <?php if(get_option('ofd_nalog')=='SimpleIn') echo 'selected';?>>Упрощенная система налогообложения (доход)</option>
							<option value="SimpleInOut" <?php if(get_option('ofd_nalog')=='SimpleInOut') echo 'selected';?>>Упрощенная система налогообложения (доход минус расход)</option>
							<option value="Unified" <?php if(get_option('ofd_nalog')=='Unified') echo 'selected';?>>Единый налог на вмененный доход</option>
							<option value="UnifiedAgricultural" <?php if(get_option('ofd_nalog')=='UnifiedAgricultural') echo 'selected';?>>Единый сельскохозяйственный налог</option>
							<option value="Patent" <?php if(get_option('ofd_nalog')=='Patent') echo 'selected';?>>Патентная система налогообложения</option>
                         </select>
					</td>
                </tr>
				<tr valign="top">
                    <th scope="row">Принудительная свертка позиций заказа </th>
                    <td><input type="checkbox" name="ofd_collapse" value="1" <?php if(get_option('ofd_collapse')!='') echo 'checked';?>/></td>
                </tr>

				<tr valign="top">
                    <th scope="row">Текстовое название для такой позиции</th>
                    <td><input type="text" name="ofd_collapse_name" value="<?php echo get_option('ofd_collapse_name'); ?>"/></td>
                </tr>

                <tr valign="top">
                    <th scope="row">Статус заказа, для которого формировать чек автоматически</th>
                    <td>
						<select name="ofd_order_status">
                            <?php
                            $statusOFD = get_option('ofd_order_status');
                            $statuses = wc_get_order_statuses();
                            echo "<option value='' " .( $statusOFD == ''?'selected':'' ). ">Не выбрано</option>";
							foreach ($statuses as $key => $value) {
                                $keytemp = str_replace("wc-", '', $key);
                                echo "<option value=" . $keytemp . " " .( $statusOFD == $keytemp?'selected':'' ). ">" . $value . "</option>";
                            }
                            
                            ?>
                        </select>
					</td>
                </tr>

                <tr valign="top">
                    <th scope="row">Ставка НДС по умолчанию</th>
                    <td>
						<select name="ofd_nds">
                            <option value="" <?php if(get_option('ofd_nds')=='') echo 'selected';?>>Не выбрано</option>
							<option value="Vat0" <?php if(get_option('ofd_nds')=='Vat0') echo 'selected';?>>Без НДС</option>
                            <option value="Vat10" <?php if(get_option('ofd_nds')=='Vat10') echo 'selected';?>>10%</option>
                            <option value="Vat18" <?php if(get_option('ofd_nds')=='Vat18') echo 'selected';?>>18%</option>
                        </select>
					</td>
                </tr>

            </table>

            <p class="submit">
                <input type="submit" class="button-primary" value="Сохранить настройки плагина"/>
            </p>

        </form>
    </div>
    <?php
 
}
	