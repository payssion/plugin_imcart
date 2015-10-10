<?php

/*
 * To change this template, choose Tools | Templates
 * and open the template in the editor.
 */

/**
 * Description of payssion
 *
 */
class payssion extends BasePaymentInterface {

    public $orderNo;
    public $_setting = array(
    	'return_url' => 'pay_result_payssion_client.html',
    	'notify_url' => 'pay_result_payssion_server.html',
        'pay_url' => 'https://www.payssion.com/payment/create.html'/* 支付页面路径 */
    );

    public function getInterfaceForm($orderpaymnet_id) {
        //获取订单相关信息
        $orderLogic = $this->load("order");
        $orderAddressLogic = $this->load("OrderAddress");
        $countryLogic = $this->load("Country");
        $orderPaymentLogic = $this->load("orderPayment");
        $orderPayment = $orderPaymentLogic->getOne('id=' . $orderpaymnet_id);   //支付金额信息
        $order = $orderLogic->getOne('id=' . $orderPayment['order_id']);    //订单信息
        $orderaddress = $orderAddressLogic->getOne('order_id=' . $orderPayment['order_id']);    //获取下单者信息
        $o_country = $countryLogic->getOne('id=' . $orderaddress['country_id']);    //获取国家信息
        $this->loadConfig($orderPayment['payment_id']); //获取后台配置信息
        $orderItemJoinSkuLogic = $this->load('orderItemJoinSkuProduct');
        $orderItems = $orderItemJoinSkuLogic->findAll('i.order_id=' . $orderPayment['order_id']);   //每个商品的信息
        //获取订单相关信息 end
        //订单具体参数值，存入数组$info,然后通过方法get_frominfo()返回html表单
        $info = array();
        $info['api_key'] = $this->cfg["py_apikey"];    //后台设置参数值
        $secret_key = $this->cfg["py_secretkey"];   //后台设置参数值
        $info['track_id'] = $order['itemno'];    //订单号
        $info['description'] = $_SERVER['SERVER_NAME'] . '#' . $info['track_id'];
        $info['currency'] = strtoupper($order['currency_code']);   //订单货币
        $ordertotal = Common::price_format($orderPayment['real_currency_amount']);  //订单金额
        $info['amount'] = sprintf("%.2f", $ordertotal); //订单金额保留小数点后两位
        $info['success_url'] = "http://" . $_SERVER['SERVER_NAME'] . FOLDER_ROOT . $this->_setting['return_url']; //支付后必须跳转到这个url
        $info['fail_url'] = $info['success_url'];
        $info['notify_url'] = "http://" . $_SERVER['SERVER_NAME'] . FOLDER_ROOT . $this->_setting['notify_url'];
        $info['payer_name'] = $orderaddress['first_name'] . ' ' . $orderaddress['last_name'];
        $info['payer_email'] = $orderaddress['email'];
        
        $info['api_sig'] = $this->generateSignature($info, $this->cfg["py_secretkey"]);
        
        $info['pay_url'] = $this->_setting['pay_url']; //支付页面url
        $html = $this->get_frominfo($info);
        return $html;
        //订单具体参数值，存入数组$info,然后通过方法get_frominfo()返回html表单 end
    }
    
    private function generateSignature(&$req, $secretKey) {
    	$arr = array($req['api_key'], $req['pm_id'], $req['amount'], $req['currency'],
    			$req['track_id'], $req['sub_track_id'], $secretKey);
    	$msg = implode('|', $arr);
    	return md5($msg);
    }

    /**
     * 初始化POST ORDER, ORDER_PAYMENT
     */
    public function initPost() {
    	$orderpayment = null;
        if ($_POST['state'] == 'complete') {
        	$item_number = $_POST['track_id'];
        	$orderLogic = $this->load("order");
        	$orderPaymentLogic = $this->load("orderPayment");
        	$order = $orderLogic->getOne(' itemno="' . $item_number . '"');  //获取订单信息
        	if ($order) {
        		$orderpayment = $orderPaymentLogic->getOne('order_id=' . $order['id']); //支付金额信息
        		$this->loadConfig($orderpayment['payment_id']);     //获取后台配置信息
        		if ($orderpayment) {
        			if ($this->isValidNotify()) {
        				if($orderpayment['status'] != 1) {
        					$payment_data = array(
        							'pay_method_no' => $_POST['transaction_id'],
        							'status'        => 1,
        							'end_time' => SYS_TIME,
        							'payer'    => ''
        					);
        					$res = $orderPaymentLogic->save($payment_data, $orderpayment['id']);
        					if ($res) {
        						$orderpayment['pay_method_no'] = $_POST['transaction_id'];  //支付接口回调的支付水流号
        						$orderpayment['end_time'] = SYS_TIME;
        						$orderpayment['status'] = 1;
        						$orderpayment['payer'] = '';
        					}
        				} else {
        					$orderpayment['completed'] = 1;
        				}
        			} else {
        				Common::log('payssion: failed to check signature');
        			}
        			
        		} else {
        			Common::log('no_order_payment');
        		}
        	} else {
        		//订单号 错误
        		Common::log('order number error'.$item_number."||".json_encode($_POST));
        	}
        }
        
        return $orderpayment;
    }
    
    public function isValidNotify() {
    	$apiKey = $this->cfg["py_apikey"];;
    	$secretKey = $this->cfg["py_secretkey"];
    
    	// Assign payment notification values to local variables
    	$pm_id = $_POST['pm_id'];
    	$amount = $_POST['amount'];
    	$currency = $_POST['currency'];
    	$track_id = $_POST['track_id'];
    	$sub_track_id = $_POST['sub_track_id'];
    	$state = $_POST['state'];
    
    	$check_array = array(
    			$apiKey,
    			$pm_id,
    			$amount,
    			$currency,
    			$track_id,
    			$sub_track_id,
    			$state,
    			$secretKey
    	);
    	$check_msg = implode('|', $check_array);
    	$check_sig = md5($check_msg);
    	$notify_sig = $_POST['notify_sig'];
    	return ($notify_sig == $check_sig);
    }

    public function responseResult() { //这个方法order.php会调用到，不能删除
        return;
    }

    /**
     * 构造form表单
     * @param type $params
     * @return string
     */
    private function get_frominfo($params) {
        $info = $params;
        $html = '<form  name="checkout_confirmation" target="_blank" action="' . $params['pay_url'] . '" method="post" id="payment_form">' . "\r\n";
        unset($info['inner_url']);
        foreach ($info as $k => $v) {
            $html.='<input name="' . $k . '" type="hidden" value="' . $v . '"/>' . "\r\n";
        }
        $html.='</form>' . "\r\n";
        return $html;
    }

}

?>