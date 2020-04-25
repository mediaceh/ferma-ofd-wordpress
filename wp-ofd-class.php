<?php

class wp_ofd_class
{
	
	private $flash = false;
	
	private function emailBugReport($message)
    {
		if(get_option('ofd_email')) {
			mail(get_option('ofd_email'),'ОШИБКА ОФД',$message);
		}
    }   
	public function enableFlashNotices()
	{
		$this->flash = true;
	}
	
	private function displayError($message)
    {
		if (defined('DOING_AJAX') && DOING_AJAX) {
			echo $message."\r\n";
		} else {
			if($this->flash) {
				ofd_add_flash_notice( __($message), "error", true );
			} else {
				echo '<div class="notice notice-error"><p>'.$message.'</p></div>';
			}
		}
	}
    
	public function echoError($message)
    {
		$message = 'ОШИБКА ОФД: '.$message;
		if(is_admin() && !( defined( 'DOING_CRON' ) && DOING_CRON)) {
			$this->displayError($message);
		} else {
			$this->emailBugReport($message);
		}
	}
 	public function echoSuccess($message)
    {
		$message = 'ОФД: '.$message;
		if(is_admin() && !( defined( 'DOING_CRON' ) && DOING_CRON)) {
			if($this->flash) {
				ofd_add_flash_notice( __($message), "success", true );
			} else {
				echo '<div class="notice notice-success"><p>'.$message.'</p></div>';
			}
		} 
	}   
    private function checkSettings()
    {
		return (
			get_option('ofd_client_login')&&
			get_option('ofd_client_pass')&&
			get_option('ofd_nalog')&&
			get_option('ofd_inn')&&
			get_option('ofd_nds')
			);
	}	
    
	private function checkToken()
    {
		if(get_option('ofd_token')&&(get_option('ofd_token_exp_date')>(time()-10))) {
			return get_option('ofd_token');
		} else {
			return false;
		}
	}	
	
	public function clearToken()
    {
		update_option('ofd_token', '');
		update_option('ofd_token_exp_date', '0');
	}	
	
	public function setAuthToken()
    {
		if(!$this->checkSettings()) {
			$this->echoError('Для корректной работы заполните необходимы настройки');
			return false;
		};
		if($this->checkToken()) {
			return true;
		}
		$data = array(
					"Login"		 => get_option('ofd_client_login'),
					"Password"	 => get_option('ofd_client_pass'),
				);	
		$options = $this->getHTTPOpt($data);
		$context = stream_context_create($options);	
		set_error_handler(array($this, 'customErrorHandler'));
		try {	
			$result = file_get_contents(OFD_AUTH_URL, false, $context);
		} catch(Exception $e) {
			$this->echoError($e->getMessage());
			return false;
		}
		restore_error_handler();
		$result = json_decode($result);
		if(isset($result->Status)&&($result->Status=='Success')) {
			update_option('ofd_token', $result->Data->AuthToken);
			update_option('ofd_token_exp_date', strtotime($result->Data->ExpirationDateUtc));
			return true;
		} else if(isset($result->Status)&&($result->Status=='Failed')) {
			$this->echoError($result->Error->Message);
			return false;
		} else {
			$this->echoError('some error');
			return false;
		}
    }

	private function getHTTPOpt($data)
    {
		$options = array(
					"ssl"=>array(
						"verify_peer"=>false,
						"verify_peer_name"=>false,
					),
					'http' => array(
							'timeout' => 10,
							'ignore_errors' => true,
							'content' => json_encode($data),
							'header'  => "Content-type: application/json\r\n".
										 "Accept: application/json"."\r\n",
										 "Content-Length: ".strlen(json_encode($data))."\r\n",
							'method'  => 'POST',
							)
			);	
		return $options;
	}
	
	
    public function customErrorHandler($errno, $errstr, $errfile, $errline, array $errcontext)
	{
		if (0 === error_reporting()) {
			return false;
		}
		throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
	}
    
    private function sendDataToOFD($data)
    {
		if($this->setAuthToken()) {
			$options = $this->getHTTPOpt($data);
			$context = stream_context_create($options);		
			set_error_handler(array($this, 'customErrorHandler'));
			try {	
				$result = file_get_contents(OFD_API_URL."/receipt?AuthToken=".get_option('ofd_token'), false, $context);
			} catch(Exception $e) {
				$this->echoError($e->getMessage());
				return false;
			}
			restore_error_handler();
			$result = json_decode($result);
			if(isset($result->Status)&&($result->Status=='Success')) {
				return $result->Data->ReceiptId;
			} else if(isset($result->Status)&&($result->Status=='Failed')) {
				$this->echoError($result->Error->Message);
				return false;
			} else {
				$this->echoError('some error');
				return false;
			}
		}
    }
	
	public function getCountPendingChecks() 
	{
		global $wpdb;
		return $wpdb->get_var("SELECT COUNT(*) FROM `".$wpdb->prefix.OFD_TABLE_NAME."` WHERE `status` IS NULL OR `status` <> 'CONFIRMED'");
	}
	
	private function UpdateOldCheckInDB($check_id,$data)
    {
		global $wpdb;
		$wpdb->update($wpdb->prefix.OFD_TABLE_NAME, 
			array(
					"status"			=> $data->StatusName,
					"status_message"	=> $data->StatusMessage,
					"updated_at"		=> current_time('mysql', 1),
					),
					array(
						"id" => $check_id,
						),
                array('%s','%s','%s'),array('%s'));
	}
	
	private function UpdateNewCheckInDB($check_id,$data)
    {
		global $wpdb;
		$wpdb->update($wpdb->prefix.OFD_TABLE_NAME, 
				array(
					"status"			=> $data->StatusName,
					"status_message"	=> $data->StatusMessage,
					"FN"				=> $data->Device->FN,
					"RNM"				=> $data->Device->RNM,
					"FDN"				=> $data->Device->FDN,
					"FPD"				=> $data->Device->FPD,
					"updated_at"		=> current_time('mysql', 1),
					),
					array(
						"id" => $check_id,
						),
                array('%s','%s','%s','%s','%s','%s','%s'),array('%s'));
	}
	
	private function UpdateFailedCheckInDB($check_id)
    {
		global $wpdb;
		//do nothing
	}	

	public function UpdateCheckStatus($check_id)
    {
		$check_id = sanitize_text_field($check_id);
		$data = array();
		$data['Request']['ReceiptId'] = $check_id;
		if($data_ins = $this->UpdateNewCheckStatus($data)) {
			$this->UpdateNewCheckInDB($check_id,$data_ins);
			return true;
		} elseif($data_ins = $this->UpdateOldCheckStatus($data)) {
			$this->UpdateOldCheckInDB($check_id,$data_ins);
			return true;
		} else {
			$this->UpdateFailedCheckInDB($check_id);
			return false;
		}
	}
	
	public function UpdateChecksStatus()
    {
		global $wpdb;
		$results = $wpdb->get_results("SELECT * FROM `".$wpdb->prefix.OFD_TABLE_NAME."` WHERE `status` IS NULL OR `status` <> 'CONFIRMED' OR `status` <> 'FAILED'");		
		foreach($results as $result) {
			$data = array();
			$data['Request']['ReceiptId'] = $result->id;
			if($data_ins = $this->UpdateNewCheckStatus($data)) {
				$this->UpdateNewCheckInDB($result->id,$data_ins);
			} elseif($data_ins = $this->UpdateOldCheckStatus($data)) {
				$this->UpdateOldCheckInDB($result->id,$data_ins);
			} else {
				$this->UpdateFailedCheckInDB($result->id);
			}
		}	
	}
	
	private function UpdateOldCheckStatus($data)
    {
		if($this->setAuthToken()) {
			$options = $this->getHTTPOpt($data);
			$context = stream_context_create($options);		
			set_error_handler(array($this, 'customErrorHandler'));
			try {	
				$result = file_get_contents(OFD_API_URL."/list?AuthToken=".get_option('ofd_token'), false, $context);
			} catch(Exception $e) {
				$this->echoError($e->getMessage());
				return false;
			}
			restore_error_handler();
			$result = json_decode($result);
			if(isset($result->Status)&&($result->Status=='Success')) {
				return $result->Data->ReceiptId;
			} else if(isset($result->Status)&&($result->Status=='Failed')) {
				$this->echoError($result->Error->Message);
				return false;
			} else {
				$this->echoError('some error');
				return false;
			}
			
		}
	}	
	private function UpdateNewCheckStatus($data)
    {
		if($this->setAuthToken()) {
			$options = $this->getHTTPOpt($data);
			$context = stream_context_create($options);		
			set_error_handler(array($this, 'customErrorHandler'));
			try {	
				$result = file_get_contents(OFD_API_URL."/status?AuthToken=".get_option('ofd_token'), false, $context);
			} catch(Exception $e) {
				$this->echoError($e->getMessage());
				return false;
			}
			restore_error_handler();
			$result = json_decode($result);
			if(isset($result->Status)&&($result->Status=='Success')) {
				return $result->Data;
			} else if(isset($result->Status)&&($result->Status=='Failed')) {
				$this->echoError($result->Error->Message);
				return false;
			} else {
				$this->echoError('some error');
				return false;
			}
		}
	}
	
	private function saveCheckInDB($check_id,$order_id,$data,$total)
    {
        global $wpdb;
		try {	

			return $wpdb->insert($wpdb->prefix.OFD_TABLE_NAME,
				array(
					"id"			=> $check_id,
					"type"			=> $data['Request']['Type'],
					"order_id"		=> $order_id,
					"total"			=> $total,
					"created_at"	=> current_time('mysql', 1),
					
				),
				array("%s","%s","%d","%s")
			);	
		} catch(Exception $e) {
			$this->echoError($e->getMessage());
			return false;
		}
    }

    public function getChecksList()
    {
        global $wpdb;
        $result = $wpdb->get_results("SELECT * FROM `".$wpdb->prefix.OFD_TABLE_NAME."` ORDER BY `created_at` DESC");
		return $result;
    }

    private function prepareDataForOFD($order,$type)
    {
        global $wpdb;
		$data = array();
        $orderArray = (json_decode($order, true));
        $order_items = $order->get_items();
        $data['Request']['Inn'] = get_option('ofd_inn');
		$data['Request']['Type'] = $type;
		$data['Request']['InvoiceId'] = $order->id.'-'.$type;
		$data['Request']['LocalDate'] = date('Y-m-d\TH:i:s');
		$data['Request']['CustomerReceipt'] = array(
				'TaxationSystem'	=> get_option('ofd_nalog'),
				'Email'				=> $orderArray['billing']['email'],
				'Phone'				=> $orderArray['billing']['phone'],
				'Items'				=> array(),
			);
		foreach ($order_items as $item_id => $item_data) {	
			$product = $item_data->get_product();
			$product_name = $product->get_name(); 
			$product_price = $product->get_price(); 
			$item_quantity = $item_data->get_quantity();
			$item_total = $item_data->get_total();
			$item_nds = get_post_meta($product->id, '_ofd_nds', true);
			/*
			Old ver
			$product_name = $item_data['name'];
			$item_quantity = $order->get_item_meta($item_id, '_qty', true);
            $item_total = $order->get_item_meta($item_id, '_line_total', true);
			*/
            array_push($data['Request']['CustomerReceipt']['Items'], 
						array(	
								'Label'	=> $product_name, 
								'Price' => $product_price, 
								'Quantity' => $item_quantity,
								'Amount' => $item_total, 
								'Vat' => $item_nds?$item_nds:get_option('ofd_nds'),
							)
				);
        }
		if(!empty($orderArray['shipping_total'])&&($orderArray['shipping_total']!='0')) {
			array_push($data['Request']['CustomerReceipt']['Items'], 
						array(	
								'Label'	=> 'Доставка', 
								'Price' => $orderArray['shipping_total'], 
								'Quantity' => 1,
								'Amount' => $orderArray['shipping_total'], 
								'Vat' => get_option('ofd_nds'),
							)
					);	
		}
		if(get_option('ofd_collapse')) {		
			$sum = 0;
			$pos_name = get_option('ofd_collapse_name')?get_option('ofd_collapse_name'):'Undefined';
			foreach($data['Request']['CustomerReceipt']['Items'] as $item) {
				$sum += $item['Amount'];
			}
			$data['Request']['CustomerReceipt']['Items'] = array(	
								'Label'	=> $pos_name, 
								'Price' => $sum, 
								'Quantity' => 1,
								'Amount' => $sum, 
								'Vat' => get_option('ofd_nds'),
							);
		}
		return $data;
    }

	public static function getTextType($type)
	{
		if($type == 'IncomeReturn') {
			return "возврата";
		} else if($type == 'Income') {
			return "прихода";
		} else {
			return "кукуя";
		}
	}
    
	public function checkExists($order_id,$type)
    {
		global $wpdb;
		$exists = $wpdb->get_var("SELECT `order_id` FROM `".$wpdb->prefix.OFD_TABLE_NAME."` WHERE `order_id` = '".$order_id."' AND `type` = '".$type."' LIMIT 1");
		return $exists;
	}

	public function OFDregCheckManually($order_id,$type)
    {
		$type = sanitize_text_field($type);
		$order_id = intval($order_id);
		$order = wc_get_order($order_id);
		$temptype = $this->getTextType($type);
		if($order) {
			if($this->checkExists($order_id,$type)) {	
					$this->echoError('Чек '.$temptype.' для заказа '.$order_id.' уже оформлен.');
					return false;
			} else {
				$data = $this->prepareDataForOFD($order,$type);
				if($check_id = $this->sendDataToOFD($data)) {
					if(!$this->saveCheckInDB($check_id,$order_id,$data,$order->get_total())) {
						$order->add_order_note('Ошибка сохранения чека '.$temptype.' для заказа '.$order_id.'');
						$this->echoError('Ошибка сохранения чека '.$temptype.' для заказа '.$order_id.'');
					}					
					$order->add_order_note('Для данного заказа был сформирован чек '.$temptype.' на сумму: '.(wc_price($order->get_total()))." " . date('d.m.Y G:i').".", 0, false);	
					$this->echoSuccess('Чек '.$temptype.' для заказа '.$order_id.' успешно зарегистрирован.');
					return true;
				}
			}
		} else {
			$this->echoError('Заказ '.$order_id.' не найден');
			return false;
		}
    }
}