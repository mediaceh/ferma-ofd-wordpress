<?php
include_once('wp-ofd-class.php');

if(!class_exists('WP_List_Table')){
    require_once( ABSPATH . 'wp-admin/includes/class-wp-list-table.php' );
}

class OFD_List_Table extends WP_List_Table {

    function __construct()
	{
        global $status, $page;
                
       
        parent::__construct( array(
            'singular'  => 'check',
            'plural'    => 'checks',
            'ajax'      => false,
        ) );
        
    }

    function column_default($item, $column_name)
	{
        switch($column_name) {
            default:
				return $item[$column_name];
        }
    }

	function column_type($item)
	{
		return wp_ofd_class::getTextType($item['type']);
	}
	function column_order_id($item)
	{
		return '<a href="post.php?post='.$item['order_id'].'&action=edit">'.$item['order_id'].'</a>';
	}
	function column_created_at($item)
	{
		return date('d.m.Y G:i:s',strtotime($item['created_at']));
	}		

    function column_id($item)
	{
        
		$actions_url = sprintf('?page=%s&action=%s&check=%s',$_REQUEST['page'],'update',$item['id']);
		$url_params = array();
		if((!empty($_REQUEST['orderby']))) {
			$url_params[] = 'orderby='.$_REQUEST['orderby'];
		}
		if((!empty($_REQUEST['order']))) {
			$url_params[] = 'order='.$_REQUEST['order'];
		}
		if((!empty($_REQUEST['paged']))) {
			$url_params[] = 'paged='.$_REQUEST['paged'];
		}
		if(count($redirect_params)) {
			$url_url .=  '?'.implode('&',$url_params);
		}		
		$actions_url .=  '&'.implode('&',$url_params);		
		$actions = array(
            'update'    => '<a href="'.$actions_url.'">Обновить статус</a>', //$actions_url, //sprintf('<a href="?page=%s&action=%s&check=%s">Обновить статус</a>',$_REQUEST['page'],'update',$item['id']),
        );
		$s = $item['RNM']&&get_option('ofd_inn')?'<a href="https://ofd.ru/'.get_option('ofd_inn').'/'.$item['RNM'].'/'.$item['FN'].'/'.$item['FDN'].'/'.$item['FPD'].'" target="_blank">'.$item['id'].'</a>':$item['id'];
        return sprintf('%1$s %2$s',
			$s,
			$this->row_actions($actions)
		);
    }

	function get_views() { 
		$status_links = array(
			"all"       => __("<a href='#'>Все</a>",'my-plugin-slug'),
			"Income" => __("<a href='#'>Прихода</a>",'my-plugin-slug'),
			"IncomeReturn"   => __("<a href='#'>Расхода</a>",'my-plugin-slug'),
		);
		return $status_links;
	}

    function column_cb($item)
	{
        return sprintf(
            '<input type="checkbox" name="%1$s[]" value="%2$s" />',
            $this->_args['singular'], 
            $item['id'] 
        );
    }

    function get_columns() 
	{
        $columns = array(
            'cb'        		=> '<input type="checkbox" />',
            'id'				=> 'Номер',
            'type'				=> 'Тип',
            'status_message'	=> 'Статус',
			'order_id'			=> 'Заказ',
            'total'				=> 'Сумма',
            'created_at'		=> 'Дата и время',
			//'FN'     			=> 'ФН №',
            //'RNM'    			=> 'РНМ №',
            //'FDN'  			=> 'ФДН №',
			//'FPD'  			=> 'ФПД №',
        );
        return $columns;
    }


    function get_sortable_columns() 
	{
        $sortable_columns = array(
            'created_at'     => array('created_at',true), 
            'order_id'    => array('order_id',false),
            'type'  => array('type',false)
        );
        return $sortable_columns;
    }

    function get_bulk_actions() 
	{
        $actions = array(
            'update'    => 'Обновить статус'
        );
        return $actions;
    }


    function process_bulk_action() 
	{
		if( 'update'===$this->current_action() ) {
			if(isset($_REQUEST['check'])&&!empty($_REQUEST['check'])) {
				$ofd = new wp_ofd_class();
				$ofd->enableFlashNotices();
				$result = true;
				if(is_array($_REQUEST['check'])) {
					foreach($_REQUEST['check'] as $check_id) {
						$result = $ofd->UpdateCheckStatus($check_id);
					}
				} else {
					$result = $ofd->UpdateCheckStatus($_REQUEST['check']);
				}
				if($result) {
					$ofd->echoSuccess('Статусы чеков успешно обновлены');
				} else {
					$ofd->echoError('При обновлении статусо произошли ошибки');
				}
			}
			$redirect_url =  get_admin_url( null, sprintf('admin.php?page=%s',$_REQUEST['page']));

			$redirect_params = array();
			dd($_REQUEST);
			if((!empty($_REQUEST['orderby']))) {
				$redirect_params[] = 'orderby='.$_REQUEST['orderby'];
			}
			if((!empty($_REQUEST['order']))) {
				$redirect_params[] = 'order='.$_REQUEST['order'];
			}
			if((!empty($_REQUEST['paged']))) {
				$redirect_params[] = 'paged='.$_REQUEST['paged'];
			}
			if(count($redirect_params)) {
				$redirect_url .=  '&'.implode('&',$redirect_params);
			}
            wp_safe_redirect($redirect_url); 
            wp_die();
		
		}
    }

    function prepare_items() 
	{
        global $wpdb; 
        $per_page = 10;
        $columns = $this->get_columns();
        $hidden = array();
        $sortable = $this->get_sortable_columns();  
        $this->_column_headers = array($columns, $hidden, $sortable);
        $this->process_bulk_action();
        $current_page = $this->get_pagenum();
        $total_items = $wpdb->get_var("SELECT COUNT(*) FROM `".$wpdb->prefix.OFD_TABLE_NAME."`");
		$orderby = (!empty($_REQUEST['orderby'])) ? $_REQUEST['orderby'] : 'created_at'; 
        $order = (!empty($_REQUEST['order'])) ? $_REQUEST['order'] : 'DESC';
        $data = $wpdb->get_results("SELECT * FROM `".$wpdb->prefix.OFD_TABLE_NAME."` ORDER BY `".$orderby."` ".$order." LIMIT ".(($current_page-1)*$per_page).",".$per_page."",ARRAY_A);
        $this->items = $data;
        $this->set_pagination_args( array(
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => ceil($total_items/$per_page),
			) );
    }


}


function ofd_render_list_page()
{
    
    $testListTable = new OFD_List_Table();
    $testListTable->prepare_items();
    
    ?>
    <div class="wrap">
        
        <div id="icon-users" class="icon32"><br/></div>
        <h2>Реестр чеков</h2>
        <form id="checks-filter" method="get">
            <input type="hidden" name="page" value="<?php echo $_REQUEST['page'] ?>" />
            <?php //$testListTable->views() ?>
			<?php $testListTable->display() ?>
        </form>
        
    </div>
    <?php
}

ofd_render_list_page();
?>