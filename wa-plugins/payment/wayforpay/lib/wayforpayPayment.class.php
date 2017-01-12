<?php

class wayforpayPayment extends waPayment implements waIPayment
{
    private $url = 'https://secure.wayforpay.com/pay';
    const WAYFORPAY_TRANSACTION_APPROVED = 'Approved';

    const WAYFORPAY_SIGNATURE_SEPARATOR = ';';

    const WAYFORPAY_ORDER_STATE_PAID = 'paid';
    
    /**
     * @const strin patterrn order_id
     */
    const WAYFORPAY_ORDER_ID_PATTERN  = '/^(\w[\w\d]+)\<_\>([\w\d]+)\<_\>(.+)$/';
    /**
     * @const string %app_id%<_>%merchant_id%<_>%order_id%
     */
    const WAYFORPAY_ORDER_ID_TEMPLATE = '%s<_>%s<_>%s';

    protected $keysForResponseSignature = array(
        'merchantAccount',
        'orderReference',
        'amount',
        'currency',
        'authCode',
        'cardPan',
        'transactionStatus',
        'reasonCode'
    );

    /** @var array */
    protected $keysForSignature = array(
        'merchantAccount',
        'merchantDomainName',
        'orderReference',
        'orderDate',
        'amount',
        'currency',
        'productName',
        'productCount',
        'productPrice'
    );

    public function allowedCurrency()
    {
        return array('UAH', 'RUB', 'USD', 'EUR');
    }

    public function payment($payment_form_data, $order_data, $auto_submit = FALSE)
    {
        $order = waOrder::factory($order_data);
        if (!in_array($order->currency, $this->allowedCurrency())) {
            throw new waPaymentException('Invalid currency');
        }

        $contact = new waContact(wa()->getUser()->getId());
        list($email) = $contact->get('email', 'value');
        list($phone) = $contact->get('phone', 'value');
        
        $formFields['merchantAccount'] = $this->merchant_account;
        $formFields['orderReference'] = sprintf(
            static::WAYFORPAY_ORDER_ID_TEMPLATE,
            $this->app_id,
            $this->merchant_id,
            shopHelper::encodeOrderId($order_data['order_id'])
        );
        $formFields['orderDate'] = strtotime($order_data['datetime']);
        $formFields['merchantAuthType'] = 'simpleSignature';
        $formFields['merchantDomainName'] = $_SERVER['HTTP_HOST'];
        $formFields['merchantTransactionSecureType'] = 'AUTO';
        $formFields['currency'] = $order->currency;
        $formFields['amount'] =  str_replace(',', '.', round($order_data['total'], 2));

        $productNames = array();
        $productQty = array();
        $productPrices = array();
        foreach ($order_data['items'] as $item) {
            $productNames[] = $item['name'];
            $productPrices[] = round($item['price'], 2);
            $productQty[] = $item['quantity'];
        }

        $formFields['productName'] = $productNames;
        $formFields['productPrice'] = $productPrices;
        $formFields['productCount'] = $productQty;

        $formFields['serviceUrl'] = $this->getRelayUrl() . '?transaction_result=result';
        $formFields['returnUrl'] = $this->getAdapter()->getBackUrl();

        /**
         * Check phone
         */
        $phone = str_replace(array('+', ' ', '(', ')', '-'), array(
            '',
            '',
            '',
            '',
            ''
        ), $phone);
        if (strlen($phone) == 10) {
            $phone = '38' . $phone;
        } elseif (strlen($phone) == 11) {
            $phone = '3' . $phone;
        }

        $name = explode(' ', $contact->getName());
        $formFields['clientFirstName'] = isset($name[0]) ? $name[0] : '';
        $formFields['clientLastName'] = isset($name[1]) ? $name[1] : '';
        $formFields['clientEmail'] = $email;
        $formFields['clientPhone'] = $phone;
        $formFields['clientCity'] = $order_data['billing_address']['city'];
        $formFields['clientAddress'] = $order_data['billing_address']['address'];
        $formFields['clientCountry'] = strtoupper($order_data['billing_address']['country']);
        $formFields['language'] = $this->language;


        $formFields['merchantSignature'] = $this->getRequestSignature($formFields);

        $view = wa()->getView();
        $view->assign('form_fields', $formFields);
        $view->assign('form_url', $this->getEndpointUrl());
        $view->assign('auto_submit', $auto_submit);

        if (!empty($_POST) && !empty($_POST['merchantSignature'])) {
            return NULL;
        } elseif (!empty($_POST) && !empty($_POST['reason'])) {
            $view->assign('message', $_POST['reason']);
            return $view->fetch($this->path . '/templates/payment_message.html');
        } else {
            return $view->fetch($this->path . '/templates/payment.html');
        }
    }

    protected function callbackInit($request)
    {
        $request = $this->getRequest();
        $this->request = $request;

        $order_id = !empty($request['orderReference']) ? $request['orderReference'] : NULL;
        $matches = array();
        if (preg_match(static::WAYFORPAY_ORDER_ID_PATTERN, $order_id, $matches)) {
            $this->app_id      = $matches[1];
            $this->merchant_id = $matches[2];
            $order_id          = $matches[3];
        }
        
        $format = wa('shop')->getConfig()->getOrderFormat();
        $format = '/^' . str_replace('\{\$order\.id\}', '(\d+)', preg_quote($format, '/')) . '$/';
        if (preg_match($format, $order_id, $m)) {
            $order_id = $m[1];
        }

        $this->order_id = $order_id;

        return parent::callbackInit($request);
    }

    public function capture()
    {

    }

    /**
     * @param array $request
     * @return array
     * @throws waPaymentException
     */
    public function callbackHandler($request)
    {
        $request = $this->getRequest();
        $url = NULL;

        if (!intval($this->order_id)) {
            throw new waPaymentException('Invalid order id');
        }

        $sign = $this->getResponseSignature($request);

        if (empty($request["merchantSignature"]) || $request["merchantSignature"] != $sign) {
            throw new waPaymentException('Invalid signature');
        }

        if ($request['transactionStatus'] == self::WAYFORPAY_TRANSACTION_APPROVED) {
            $transaction_data = $this->formalizeData($request);
            $transaction_data['state'] = self::STATE_CAPTURED;
            $transaction_data['type'] = self::OPERATION_AUTH_CAPTURE;
            $transaction_data['result'] = 1;

            $this->saveTransaction($transaction_data, $request);
            $this->execAppCallback(self::CALLBACK_CAPTURE, $transaction_data);

            echo $this->getAnswerToGateWay($request);
        }

        return array(
            'template' => FALSE
        );
    }

    /*
     *
     */
    protected function formalizeData($transaction_raw_data)
    {
        $transaction_data = parent::formalizeData($transaction_raw_data);
        $transaction_data['native_id'] = '#1007';
        $transaction_data['order_id'] = 1007;
        $transaction_data['amount'] = ifempty($transaction_raw_data['amount'], '');
        $transaction_data['currency_id'] = $transaction_raw_data['currency'];
        $transaction_data['merchant_id'] = $this->merchant_account;
        return $transaction_data;
    }

    private function getEndpointUrl()
    {
        return $this->url;
    }


    /**
     * @param $option
     * @param $keys
     * @return string
     */
    public function getSignature($option, $keys)
    {
        $hash = array();
        foreach ($keys as $dataKey) {
            if (!isset($option[$dataKey])) {
                continue;
            }
            if (is_array($option[$dataKey])) {
                foreach ($option[$dataKey] as $v) {
                    $hash[] = $v;
                }
            } else {
                $hash [] = $option[$dataKey];
            }
        }
        $hash = implode(self::WAYFORPAY_SIGNATURE_SEPARATOR, $hash);

        return hash_hmac('md5', $hash, $this->secret_key);
    }


    /**
     * @param $options
     * @return string
     */
    public function getRequestSignature($options)
    {
        return $this->getSignature($options, $this->keysForSignature);
    }

    /**
     * @param $options
     * @return string
     */
    public function getResponseSignature($options)
    {
        return $this->getSignature($options, $this->keysForResponseSignature);
    }


    /**
     * @param $data
     * @return string
     */
    public function getAnswerToGateWay($data)
    {
        $time = time();
        $responseToGateway = array(
            'orderReference' => $data['orderReference'],
            'status' => 'accept',
            'time' => $time
        );
        $sign = array();
        foreach ($responseToGateway as $dataKey => $dataValue) {
            $sign [] = $dataValue;
        }
        $sign = implode(self::WAYFORPAY_SIGNATURE_SEPARATOR, $sign);
        $sign = hash_hmac('md5', $sign, $this->secret_key);
        $responseToGateway['signature'] = $sign;

        return json_encode($responseToGateway);
    }

    protected function getRequest()
    {
        return json_decode(file_get_contents("php://input"), TRUE);
    }
}
