<?php

/*
 * To change this template, choose Tools | Templates
 * and open the template in the editor.
 */

/**
 * Description of BrandJoinLanguageLogic
 *
 * @author xmb <QQ: www.35zh.com>
 */
class payssion extends BasePaymentInterface {  //修改类名为testpay，和接口名称一致

    public $orderNo;
    public $_setting = array(//$_setting保存默认的参数值，如果需要可自行添加
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
        $secret_key = $this->cfg["py_sceretkey"];   //后台设置参数值
        $info['track_id'] = $order['itemno'];    //订单号
        $info['description'] = $_SERVER['SERVER_NAME'] . '#' . $info['track_id'];
        $info['currency'] = strtoupper($order['currency_code']);   //订单货币
        $ordertotal = Common::price_format($orderPayment['real_currency_amount']);  //订单金额
        $info['amount'] = sprintf("%.2f", $ordertotal); //订单金额保留小数点后两位
        $info['success_url'] = "http://" . $_SERVER['SERVER_NAME'] . FOLDER_ROOT . $this->_setting['return_url']; //支付后必须跳转到这个url
        $info['fail_url'] = $info['success_url'];
        $info['payer_name'] = $orderaddress['first_name'] . ' ' . $orderaddress['last_name'];
        $info['payer_email'] = $orderaddress['email'];
        
        $info['api_sig'] = $this->generateSignature($info, $this->cfg["py_sceretkey"]);
        
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
        //获取pay_url提交过来的参数
        $info = array();
        if ($_REQUEST['tradeNo']) {
            $info["merNo"] = $_REQUEST["merNo"];
            $info["gatewayNo"] = $_REQUEST["gatewayNo"];
            $info["tradeNo"] = $_REQUEST["tradeNo"];
            $info["orderNo"] = $_REQUEST["orderNo"];
            $info["orderCurrency"] = $_POST['orderCurrency'];
            $info["cardNo"] = $_POST['cardNo'];
            $info["orderAmount"] = $_REQUEST["orderAmount"];
            $info["orderStatus"] = $_REQUEST["orderStatus"];
            $info["orderInfo"] = $_REQUEST["orderInfo"];
            $info["signInfo"] = $_REQUEST["signInfo"];
        }
        //获取pay_url提交过来的参数 end
        $orderLogic = $this->load("order");
        $orderPaymentLogic = $this->load("orderPayment");
        $order = $orderLogic->getOne(' itemno="' . Common::strEscape($info['orderNo']) . '"');  //获取订单信息
        $orderpayment = $orderPaymentLogic->getOne('order_id=' . $order['id']); //支付金额信息
        $this->loadConfig($orderpayment['payment_id']);     //获取后台配置信息
        if ($_REQUEST['orderNo']) {      //这边无论成功或失败都要返回订单号$this->orderNo
            $this->orderNo = $_REQUEST['orderNo'];
        } else {
            $this->orderNo = $_REQUEST['orderID'];
            return false;
        }
        //对订单状态进行处理，结果信息保存到$orderpayment
        if (!empty($info)) {
            $MD5key = $this->cfg["exp_md5key"];
            $signInfocheck = hash("sha256", $info["merNo"] . $info["gatewayNo"] . $info["tradeNo"] . $info["orderNo"] . $info["orderCurrency"] . $info["orderAmount"] . $info["orderStatus"] . $info["orderInfo"] . $MD5key);
            if (strtolower($info["signInfo"]) == strtolower($signInfocheck)) {  //验证支付参数，防串改
                if ($_REQUEST['orderStatus'] == 1) {
                    $param['status'] = 1;
                } else {
                    $param['status'] = 0;
                }
                $param['tradeNo'] = $info["tradeNo"];
                $rel = $this->handlOrderPayment($param, $orderpayment); //对支付状态进行处理
                return $rel;
            } else {
                Common::log('Hash info not the same');
            }
        } else {
            Common::log('No return parameter');
        }
        //对订单状态进行处理，结果信息保存到$orderpayment end
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

    /**
     * 处理订单支付信息
     * $params['status'] 1成功，0，失败【必填】
     * $params['tradeNo'] 回调的支付流水号
     * $orderpayment  array 订单支付信息【必填】
     * @return array
     */
    private function handlOrderPayment($params, $orderpayment) {
        if ($params['orderStatus'] == 1) {    //支付成功
            if ($orderpayment['pay_method_no'] == $params["tradeNo"] && $orderpayment['status'] == 1) {   //已经付款过了
                $orderpayment['completed'] = 1;
            } else {  
                $payment_data = array(
                    'pay_method_no' => $params['tradeNo'],
                    'status' => 1,
                    'end_time' => SYS_TIME,
                    'payer' => '',
                );
                $orderPaymentLogic = $this->load("orderPayment");
                $res = $orderPaymentLogic->save($payment_data, $orderpayment['id']); //更新订单状态
                if ($res) {
                    $orderpayment['pay_method_no'] = $params['tradeNo'];  //支付接口回调的支付水流号
                    $orderpayment['end_time'] = SYS_TIME;
                    $orderpayment['status'] = 1;
                    $orderpayment['payer'] = '';
                }
            }
            return $orderpayment; 
        } else if ($params['orderStatus'] == 0) {//支付失败
            Common::log('Failed to pay for orders');
            return array();
        }
    }

}

?>