<?php
/**
 * Shop System Plugins - Terms of Use
 *
 * The plugins offered are provided free of charge by Qenta Payment CEE GmbH
 * (abbreviated to Qenta CEE) and are explicitly not part of the Qenta CEE range of
 * products and services.
 *
 * They have been tested and approved for full functionality in the standard configuration
 * (status on delivery) of the corresponding shop system. They are under General Public
 * License Version 2 (GPLv2) and can be used, developed and passed on to third parties under
 * the same terms.
 *
 * However, Qenta CEE does not provide any guarantee or accept any liability for any errors
 * occurring when used in an enhanced, customized shop system configuration.
 *
 * Operation in an enhanced, customized configuration is at your own risk and requires a
 * comprehensive test phase by the user of the plugin.
 *
 * Customers use the plugins at their own risk. Qenta CEE does not guarantee their full
 * functionality neither does Qenta CEE assume liability for any disadvantages related to
 * the use of the plugins. Additionally, Qenta CEE does not guarantee the full functionality
 * for customized shop systems or installed plugins of other vendors of plugins within the same
 * shop system.
 *
 * Customers are responsible for testing the plugin's functionality before starting productive
 * operation.
 *
 * By installing the plugin into the shop system the customer agrees to these terms of use.
 * Please do not use the plugin if you do not agree to these terms of use!
 */

namespace Qenta\CheckoutPage\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\Quote;
use Magento\Sales\Model\Order;
use Magento\Payment\Model\Method\AbstractMethod;
use Magento\Checkout\Model\Cart as CheckoutCart;
use Magento\Payment\Model\InfoInterface;
use \Magento\Sales\Model\Order\Payment\Transaction;

abstract class AbstractPayment extends AbstractMethod
{
    const CODE = 'qenta_checkoutpage_select';
    protected $_code = self::CODE;
    protected $_paymentMethod = \QentaCEE\QPay\PaymentType::SELECT;
    protected $_logo = false;
    protected $_isGateway = true;
    protected $_canCapture = true;
    protected $_canCapturePartial = true;
    protected $_canVoid = true;
    protected $_canCancelInvoice = true;
    protected $_canRefund = true;
    protected $_canRefundInvoicePartial = true;
    protected $_minAmount = null;
    protected $_maxAmount = null;
    protected $_forceSendAdditionalData = false;

    protected $_autoDepositAllowed = false;

    /** @var \Qenta\CheckoutPage\Helper\Data */
    protected $_dataHelper = null;

    /**
     * @var \Magento\Sales\Model\Order\Payment\Transaction\Builder
     */
    protected $_transactionBuilder;

    /**
     * @var \Magento\Sales\Model\Order\Email\Sender\OrderSender;
     */
    protected $_orderSender;

    /**
     * @var \Magento\Sales\Model\Order\Payment\Transaction\Repository
     */
    protected $_transactionRepository;

    public function __construct(
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory,
        \Magento\Framework\Api\AttributeValueFactory $customAttributeFactory,
        \Magento\Payment\Helper\Data $paymentData,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Payment\Model\Method\Logger $logger,
        \Qenta\CheckoutPage\Helper\Data $helper,
        \Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface $transactionBuilder,
        \Magento\Sales\Model\Order\Email\Sender\OrderSender $orderSender,
        \Magento\Sales\Api\TransactionRepositoryInterface $transactionRepository,
        \Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        \Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        array $data = []

    ) {
        parent::__construct(
            $context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $paymentData,
            $scopeConfig,
            $logger,
            $resource,
            $resourceCollection,
            $data
        );

        $this->_orderSender           = $orderSender;
        $this->_transactionBuilder    = $transactionBuilder;
        $this->_dataHelper            = $helper;
        $this->_minAmount             = $this->getConfigData('min_order_total');
        $this->_maxAmount             = $this->getConfigData('max_order_total');
        $this->_transactionRepository = $transactionRepository;

        if (!$this->_dataHelper->isBackendAvailable()) {
            $this->_canCapture              = false;
            $this->_canCapturePartial       = false;
            $this->_canVoid                 = false;
            $this->_canRefund               = false;
            $this->_canRefundInvoicePartial = false;
            $this->_canCancelInvoice        = false;
        }
    }

    /**
     * Init payment, server2server
     *
     * @param CheckoutCart $cart
     * @param $urls
     * @param \Magento\Framework\DataObject $data
     *
     * @return \QentaCEE\QPay\Response\Initiation
     * @throws \Exception
     */
    public function initPaymentByCart(CheckoutCart $cart, $urls, \Magento\Framework\DataObject $data)
    {
        $quote = $cart->getQuote();

        $urls['service'] = $this->_dataHelper->getConfigData('options/service_url') ?: join(array(
          parse_url($this->_dataHelper->getReturnUrl(), PHP_URL_SCHEME),
          '://',
          parse_url($this->_dataHelper->getReturnUrl(), PHP_URL_HOST)
        ));

        $init = new \QentaCEE\QPay\FrontendClient($this->_dataHelper->getConfigArray());
        $init->setPluginVersion($this->_dataHelper->getPluginVersion());
        $init->setConfirmUrl($urls['confirm']);

        $quote->reserveOrderId();
        $quote->save();

        $customerId = $this->_dataHelper->getConfigData('basicdata/customer_id');
        $orderDescription = $this->getUserDescription($quote);
        if(strtoupper($customerId) == 'D200410' && strtoupper($this->_paymentMethod) == 'CCARD') {
          $orderDescription = 'Test:0000';
        }

        $orderId = $quote->getReservedOrderId();
        $init->setOrderReference(sprintf('%010s', substr($orderId, -10)));
        $init->uniqueId = $this->_getUniqueId($cart);

        $init->setAmount(round($cart->getQuote()->getBaseGrandTotal(), $this->_dataHelper->getPrecision()))
             ->setCurrency($quote->getCurrency()->getBaseCurrencyCode())
             ->setPaymentType($this->_paymentMethod)
             ->setOrderDescription($orderDescription)
             ->setSuccessUrl($urls['return'])
             ->setPendingUrl($urls['return'])
             ->setCancelUrl($urls['return'])
             ->setFailureUrl($urls['return'])
             ->createConsumerMerchantCrmId($quote->getCustomerEmail())
             ->setServiceUrl($urls['service'])
             ->setConsumerData($this->_getConsumerData($quote))
             ->setMaxRetries($this->_dataHelper->getConfigData('options/maxretries'));

        $init->mage_orderId       = $orderId;
        $init->mage_quoteId       = $quote->getId();
        $init->mage_orderCreation = $this->_dataHelper->getConfigData('options/order_creation');

        $init->generateCustomerStatement($this->_dataHelper->getConfigData('options/shopname'), sprintf('%010s', substr($orderId, -10)));

        if ($this->_dataHelper->getConfigData('options/sendbasketinformation') || $this->forceSendingBasket()) {
            $basket = new \QentaCEE\Stdlib\Basket();

	        foreach ( $quote->getAllVisibleItems() as $item ) {
		        /** @var \Magento\Quote\Model\Quote\Item $item */

		        $bitem = new \QentaCEE\Stdlib\Basket\Item();
		        $bitem->setDescription( $item->getProduct()->getName() );
		        $bitem->setName( $item->getProduct()->getName() );
		        $bitem->setArticleNumber( $item->getSku() );

		        $bitem->setUnitGrossAmount( number_format( $item->getPriceInclTax(), $this->_dataHelper->getPrecision(), '.', '' ) );
		        $bitem->setUnitNetAmount( number_format( $item->getPrice(), $this->_dataHelper->getPrecision(), '.', '' ) );
		        $bitem->setUnitTaxRate( number_format( $item->getTaxPercent(), $this->_dataHelper->getPrecision(), '.', '' ) );
		        $bitem->setUnitTaxAmount( number_format( $item->getTaxAmount(), $this->_dataHelper->getPrecision(), '.', '' ) );

		        $basket->addItem( $bitem, (int) $item->getQty() );
	        }

	        if($quote->getShippingAddress()->getShippingAmount() != 0) {
		        $bitem = new \QentaCEE\Stdlib\Basket\Item();
		        $bitem->setArticleNumber( 'shipping' );

		        $bitem->setUnitGrossAmount(
			        number_format( $quote->getShippingAddress()->getShippingAmount(),
			                       $this->_dataHelper->getPrecision(), '.', '' )
			        +
			        number_format( $quote->getShippingAddress()->getShippingTaxAmount(),
			                       $this->_dataHelper->getPrecision(), '.', '' )
		        );
		        $bitem->setUnitNetAmount( number_format( $quote->getShippingAddress()->getShippingAmount(),
		                                                 $this->_dataHelper->getPrecision(), '.', '' ) );

		        $bitem->setUnitTaxRate(
			        number_format(
				        $quote->getShippingAddress()->getShippingTax() / $quote->getShippingAddress()->getShippingAmount(),
				        $this->_dataHelper->getPrecision(),
				        '.',
				        ''
			        )
		        );
		        $bitem->setUnitTaxAmount( number_format( $quote->getShippingAddress()->getShippingTax(),
		                                                 $this->_dataHelper->getPrecision(), '.', '' ) );
		        $bitem->setDescription( $quote->getShippingAddress()->getShippingDescription() );
		        $bitem->setName( $quote->getShippingAddress()->getShippingDescription() );
		        $basket->addItem( $bitem );
	        }

	        $init->setBasket( $basket );
        }

        if ($this->_dataHelper->getConfigData('options/sendconfirmationemail')) {
            $init->setConfirmMail($this->_dataHelper->getStoreConfigData('trans_email/ident_general/email'));
        }

        if (strlen($this->_dataHelper->getConfigData('options/bgcolor'))) {
            $init->setBackgroundColor($this->_dataHelper->getConfigData('options/bgcolor'));
        }

        if (strlen($this->_dataHelper->getConfigData('options/displaytext'))) {
            $init->setDisplayText($this->_dataHelper->getConfigData('options/displaytext'));
        }

        if (strlen($this->_dataHelper->getConfigData('options/imageurl'))) {
            $init->setImageUrl($this->_dataHelper->getConfigData('options/imageurl'));
        }

        if (strlen($this->_dataHelper->getConfigData('options/layout'))) {
            $init->setLayout($this->_dataHelper->getConfigData('options/layout'));
        }

        // always set to false. QMP-67
        $init->setAutoDeposit(false);

        if ($this->_dataHelper->getConfigData('options/duplicaterequestcheck')) {
            $init->setDuplicateRequestCheck($this->_dataHelper->getConfigData('options/duplicaterequestcheck'));
        }

        $init->setLayout('DESKTOP');

        if (strlen($data->getData('financialInstitution'))) {
            $init->setFinancialInstitution($data->getData('financialInstitution'));
        }

        $init->iframeUsed = $this->getDisplayMode() != 'redirect';

        $init->quoteHash = $this->_dataHelper->calculateQuoteChecksum($quote);

        $this->setAdditionalRequestData($init, $cart);

        $this->_logger->debug(__METHOD__ . ':' . print_r($init->getRequestData(), true));

        try {
            $initResponse = $init->initiate();
        } catch (\Exception $e) {
            $this->_logger->debug(__METHOD__ . ':' . $e->getMessage());
            throw new $e;
        }

        if ($initResponse->getStatus() == \QentaCEE\QPay\Response\Initiation::STATE_FAILURE) {
            $error   = $initResponse->getError();
            $message = $this->_dataHelper->__('An error occurred during the payment process');
            if ($error !== false) {
                if (strlen($error->getConsumerMessage())) {
                    $message = $error->getConsumerMessage();
                }

                $this->_logger->debug(__METHOD__ . ':' . $error->getMessage());
            }

            throw new \Exception($message);
        }

        return $initResponse;
    }

    /**
     * set payment specific request data
     *
     * @param \QentaCEE\QPay\FrontendClient $init
     * @param CheckoutCart $cart
     */
    protected function setAdditionalRequestData($init, $cart)
    {
    }

    /**
     * Returns desription of customer - will be displayed in Qenta backend
     *
     * @param Quote $quote
     *
     * @return string
     */
    protected function getUserDescription($quote)
    {
        return sprintf('%s %s %s', $quote->getCustomerEmail(), $quote->getCustomerFirstname(),
            $quote->getCustomerLastname());
    }

    /**
     * Returns uniqueId - required for duplicate request check, if the transaction was canceled
     *
     * @param CheckoutCart $cart
     *
     * @return string
     */
    protected function _getUniqueId($cart)
    {
        $uniqueId = $cart->getCustomerSession()->getUniqueId();
        if (!strlen($uniqueId)) {
            $uniqueId = $this->_generateUniqString();
            $cart->getCustomerSession()->setUniqueId($uniqueId);
        }

        return $uniqueId;
    }

    /**
     * returns a uniq String with default length 10.
     *
     * @param int $length
     *
     * @return string
     */
    private function _generateUniqString($length = 10)
    {
        $tid = '';
        $alphabet = "023456789abcdefghikmnopqrstuvwxyzABCDEFGHIKMNOPQRSTUVWXYZ";
        for ($i = 0; $i < $length; $i ++) {
            $c = substr($alphabet, mt_rand(0, strlen($alphabet) - 1), 1);
            if (( ( $i % 2 ) == 0 ) && !is_numeric($c)) {
                $i --;
                continue;
            }
            if (( ( $i % 2 ) == 1 ) && is_numeric($c)) {
                $i --;
                continue;
            }
            $alphabet = str_replace($c, '', $alphabet);
            $tid .= $c;
        }
        return $tid;
    }

    /**
     * @param Quote $quote
     *
     * @return \QentaCEE\Stdlib\ConsumerData
     */
    protected function _getConsumerData($quote)
    {
        $consumerData = new \QentaCEE\Stdlib\ConsumerData();
        $consumerData->setIpAddress($this->_dataHelper->getClientIp());
        $consumerData->setUserAgent($this->_dataHelper->getUserAgent());

        $deliveryAddress = $quote->getShippingAddress();
        $billingAddress  = $quote->getBillingAddress();
        $userDob         = $this->getCustomerDob($quote);

        $consumerData->setEmail($quote->getCustomerEmail());
        $dob = $quote->getPayment()->getAdditionalInformation('customerDob');

	    if (strlen($dob)) {
		    $consumerData->setBirthDate(new \DateTime($dob));
	    } elseif ($userDob !== false) {
		    $consumerData->setBirthdate($userDob);
	    }

        if (strlen($billingAddress->getCompany())) {
            $consumerData->setCompanyName($billingAddress->getCompany());
        }

        if (strlen($billingAddress->getVatId())) {
            $consumerData->setCompanyVatId($billingAddress->getVatId());
        }

        if ($this->_forceSendAdditionalData || $this->_dataHelper->getConfigData('options/sendbillingdata')) {
            $consumerData->addAddressInformation($this->_getAddress($billingAddress, 'billing'));
        }

        if ($this->_forceSendAdditionalData || $this->_dataHelper->getConfigData('options/sendshippingdata')) {
            $consumerData->addAddressInformation($this->_getAddress($deliveryAddress, 'shipping'));
        }
        return $consumerData;
    }

    /**
     * @param \Magento\Quote\Model\Quote\Address $source
     * @param string $type
     *
     * @return \QentaCEE\Stdlib\ConsumerData\Address
     */
    protected function _getAddress($source, $type = 'billing')
    {
        switch ($type) {
            case 'shipping':
                $address = new \QentaCEE\Stdlib\ConsumerData\Address(\QentaCEE\Stdlib\ConsumerData\Address::TYPE_SHIPPING);
                break;

            default:
                $address = new \QentaCEE\Stdlib\ConsumerData\Address(\QentaCEE\Stdlib\ConsumerData\Address::TYPE_BILLING);
                break;
        }

        $address->setFirstname($source->getFirstname());
        $address->setLastname($source->getLastname());
        $address->setAddress1($source->getStreetLine(1));
        $address->setAddress2($source->getStreetLine(2));
        $address->setZipCode($source->getPostcode());
        $address->setCity($source->getCity());
        $address->setCountry($source->getCountry());
        $address->setState($source->getRegionCode());
        $address->setPhone($source->getTelephone());
        $address->setFax($source->getFax());

        return $address;
    }


    /**
     * getter for customers birthDate
     *
     * @param Quote $quote
     *
     * @return bool|\DateTime
     */
    public function getCustomerDob($quote)
    {
        $dob = $quote->getCustomer()->getDob();
        if ($dob) {
            return new \DateTime($dob);
        }

        return false;
    }


    /**
     * Assign data to info model instance
     *
     * @param array|\Magento\Framework\DataObject $data
     *
     * @return $this
     * @throws \Magento\Framework\Exception\LocalizedException
     * @api
     */
    public function assignData(\Magento\Framework\DataObject $data)
    {
        parent::assignData($data);

        /** @var \Magento\Quote\Model\Quote\Payment $infoInstance */
        $infoInstance = $this->getInfoInstance();

        /* unset data wich is used for dedicated payment methods only */
        $infoInstance->unsAdditionalInformation('financialInstitution');
        $infoInstance->unsAdditionalInformation('customerDob');

        return $this;
    }


    /**
     * @param Quote|\Magento\Quote\Api\Data\CartInterface $quote
     *
     * @return bool
     */
    protected function _isAvailablePayolution($quote)
    {
        $dob = $quote->getCustomer()->getDob();

        //we only need to check the dob if it's set. Else we ask for dob on payment selection page.
        if ($dob) {
            $dobObject      = new \DateTime($dob);
            $currentYear    = date('Y');
            $currentMonth   = date('m');
            $currentDay     = date('d');
            $ageCheckDate   = ( $currentYear - 17 ) . '-' . $currentMonth . '-' . $currentDay;
            $ageCheckObject = new \DateTime($ageCheckDate);
            if ($ageCheckObject < $dobObject) {
                return false;
            }
        }

        if ($quote->hasVirtualItems()) {
            return false;
        }

        if ($this->getConfigData('billing_shipping_address_identical') && !$this->compareAddresses($quote)) {
            return false;
        }

        $currencies = explode(',', $this->getConfigData('currency'));
        if (!in_array($quote->getQuoteCurrencyCode(), $currencies)) {
            return false;
        }

        if (strlen($this->getConfigData('shippingcountry'))) {
            $countries = explode(',', $this->getConfigData('shippingcountry'));
            if (!in_array($quote->getShippingAddress()->getCountry(), $countries)) {
                return false;
            }
        }

        if (strlen($this->getConfigData('max_basket_size'))) {
            if ($quote->getItemsQty() > $this->getConfigData('max_basket_size')) {
                return false;
            }
        }

        if (strlen($this->getConfigData('min_basket_size'))) {
            if ($quote->getItemsQty() < $this->getConfigData('min_basket_size')) {
                return false;
            }
        }

        return parent::isAvailable($quote);
    }

    /**
     * force transmitting the basket data
     *
     * @return bool
     */
    protected function forceSendingBasket()
    {
        return false;
    }


    /*
     * helper functions, needed by config provider
     */

    /**
     * return instruction string
     *
     * @return string
     */
    public function getInstructions()
    {
        return trim($this->getConfigData('instructions'));
    }

    /**
     * return display mode, iframe, popup, redirect
     *
     * @return mixed|string
     */
    public function getDisplayMode()
    {
        // we always want redirect in this plugin to avoid issues with popups and iframes in modern browsers
        return 'redirect';
    }

    /**
     * return logo (png)
     *
     * @return bool
     */
    public function getLogo()
    {
        return $this->_logo;
    }

    /**
     * return payment method
     *
     * @return string
     */
    public function getPaymentMethod()
    {
        return $this->_paymentMethod;
    }

    /**
     * compare billing shipping address
     *
     * @param Quote $quote
     *
     * @return bool
     */
    protected function compareAddresses($quote)
    {
        $billingAddress = $quote->getBillingAddress();

        $shippingAddress = $quote->getShippingAddress();

        if (!$shippingAddress->getSameAsBilling()) {
            if ($billingAddress->getCustomerAddressId() == null || $billingAddress->getCustomerAddressId() != $shippingAddress->getCustomerAddressId()) {
                if ( //new line because it's easier to remove this way
                    // dont compare emails, shippingAddress E-Mail is empty in guest checkouts
                    $billingAddress->getName() != $shippingAddress->getName() ||
                    $billingAddress->getCompany() != $shippingAddress->getCompany() ||
                    $billingAddress->getCity() != $shippingAddress->getCity() ||
                    $billingAddress->getPostcode() != $shippingAddress->getPostcode() ||
                    $billingAddress->getCountryId() != $shippingAddress->getCountryId() ||
                    $billingAddress->getTelephone() != $shippingAddress->getTelephone() ||
                    $billingAddress->getFax() != $shippingAddress->getFax() ||
                    $billingAddress->getCountry() != $shippingAddress->getCountry() ||
                    $billingAddress->getRegion() != $shippingAddress->getRegion() ||
                    $billingAddress->getStreetLine(1) != $shippingAddress->getStreetLine(1) ||
                    $billingAddress->getStreetLine(2) != $shippingAddress->getStreetLine(2)
                ) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * return provider for invoice/installment
     *
     * @return string
     */
    public function getProvider()
    {
        return $this->getConfigData('provider');
    }

    /**
     * Retrieve payment method title
     *
     * @return string
     */
    public function getTitle()
    {
        return $this->_dataHelper->__($this->getConfigData('title'));
    }

    /*
     * backend operations
     */

    /**
     * return order details from backend
     *
     * @param $orderNumber
     *
     * @return \QentaCEE\QPay\Response\Toolkit\GetOrderDetails
     */
    public function getOrderDetails($orderNumber)
    {
        $client = $this->_dataHelper->getBackendClient();

        $ret = $client->getOrderDetails($orderNumber);
        if ($ret->hasFailed()) {
            $this->_logger->debug(__METHOD__ . ':' . $ret->getError()->getMessage());
        }

        return $ret;
    }

    /*
     * payment operations
     */

    /**
     * Capture payment abstract method
     *
     * be aware of: https://github.com/magento/magento2/issues/2655
     *
     * @see \Magento\Sales\Model\Order\Payment\Operations\CaptureOperation::capture
     *
     * @param \Magento\Framework\DataObject|InfoInterface|\Magento\Sales\Model\Order\Payment $payment
     * @param float $amount
     *
     * @return $this
     * @throws \Exception
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function capture(\Magento\Payment\Model\InfoInterface $payment, $amount)
    {
        $order = $payment->getOrder();

        if ($this->_dataHelper->isBackendAvailable()) {

            $orderNumber = $payment->getAdditionalInformation('orderNumber');
            if (!strlen($orderNumber)) {
                /* dont throw an exception here, might be a pending payment */
                $this->_logger->debug(__METHOD__ . ':No order number found.');
                return $this;
            }
            $backendClient = $this->_dataHelper->getBackendClient();

            $orderDetails = $this->getOrderDetails($orderNumber);
            $this->_logger->debug(__METHOD__ . ':operations allowed:' . implode(',',
                    $orderDetails->getOrder()->getOperationsAllowed()));

            foreach ($orderDetails->getOrder()->getPayments() as $wdPayment) {
                /** @var \QentaCEE\QPay\Response\Toolkit\Order\Payment $wdPayment */

                $this->_logger->debug(__METHOD__ . ':operations allowed:' . implode(',',
                        $wdPayment->getOperationsAllowed()));

                if (in_array('DEPOSIT', $wdPayment->getOperationsAllowed())) {
                    $ret = $backendClient->deposit($orderNumber, $amount, $order->getBaseCurrencyCode());
                    if ($ret->hasFailed()) {
                        throw new \Exception($ret->getError()->getMessage());
                    }

                    $this->_logger->debug(__METHOD__ . ':deposited:' . $amount . ' ' . $order->getBaseCurrencyCode());

                    $gwRefId = $payment->getAdditionalInformation('gatewayReferenceNumber');

                    $payment->setTransactionId($ret->getPaymentNumber());
                    $payment->setParentTransactionId($gwRefId);

                    /** @var \Magento\Sales\Model\Order\Payment\Transaction $transaction */
                    $transaction = $this->_transactionBuilder->setPayment($payment)
                                                             ->setOrder($order)
                                                             ->setTransactionId($payment->getTransactionId())
                                                             ->build(Transaction::TYPE_CAPTURE);
                    $transaction->setParentId($gwRefId);
                    $transaction->setAdditionalInformation(Transaction::RAW_DETAILS,
                        [
                            'amount'      => $amount,
                            'currency'    => $payment->getOrder()->getBaseCurrencyCode(),
                            'orderNumber' => $orderNumber
                        ]);
                    $transaction->save();
                    $payment->addTransactionCommentsToOrder($transaction,
                        'deposited:' . $amount . ' ' . $order->getBaseCurrencyCode());
                }
            }

        }

        return $this;
    }


    /**
     * Refund specified amount for payment
     *
     * @param \Magento\Framework\DataObject|InfoInterface|\Magento\Sales\Model\Order\Payment $payment
     * @param float $amount
     *
     * @return $this
     * @throws \Exception
     * @throws \Magento\Framework\Exception\LocalizedException
     * @api
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function refund(\Magento\Payment\Model\InfoInterface $payment, $amount)
    {
        $order = $payment->getOrder();
        $this->_logger->debug(__METHOD__ . ':' . $amount . ' ' . $order->getBaseCurrencyCode());
        if (!$this->_dataHelper->isBackendAvailable()) {
            return $this;
        }

        $orderNumber = $payment->getAdditionalInformation('orderNumber');
        if (!strlen($orderNumber)) {
            throw new \Magento\Framework\Exception\LocalizedException(__('No order number found.'));
        }


        $backendClient = $this->_dataHelper->getBackendClient();
        $ret           = $backendClient->refund($orderNumber, $amount, $payment->getOrder()->getBaseCurrencyCode());
        if ($ret->hasFailed()) {
            $this->_logger->debug(__METHOD__ . ':' . $ret->getError()->getMessage());
            throw new \Exception($ret->getError()->getConsumerMessage());
        }

        $transactionId = $ret->getCreditNumber();
        $gwRefId       = $payment->getAdditionalInformation('gatewayReferenceNumber');

        $payment->setTransactionId($transactionId);

        /** @var \Magento\Sales\Model\Order\Payment\Transaction $transaction */
        $transaction = $this->_transactionBuilder->setPayment($payment)
                                                 ->setOrder($order)
                                                 ->setTransactionId($transactionId)
                                                 ->build(\Magento\Sales\Model\Order\Payment\Transaction::TYPE_REFUND);
        $transaction->setParentId($gwRefId);
        $transaction->setAdditionalInformation(Transaction::RAW_DETAILS, [
            'amount'       => $amount,
            'currency'     => $payment->getOrder()->getBaseCurrencyCode(),
            'orderNumber'  => $orderNumber,
            'creditNumber' => $ret->getCreditNumber()
        ]);
        $transaction->save();

        $payment->addTransactionCommentsToOrder($transaction, 'refund' . $amount . ' ' . $order->getBaseCurrencyCode());

        return $this;
    }


    /**
     * Revert refund
     *
     * @param \Magento\Framework\DataObject|InfoInterface|\Magento\Sales\Model\Order\Payment $payment
     * @param Transaction $transaction
     *
     * @return $this
     * @throws \Exception
     * @throws LocalizedException
     */
    public function refundReversal(\Magento\Payment\Model\InfoInterface $payment, Transaction $transaction)
    {
        $order = $payment->getOrder();

        $this->_logger->debug(__METHOD__);

        $addInfo = $transaction->getAdditionalInformation('raw_details_info');
        if (!isset( $addInfo['orderNumber'] ) || !isset( $addInfo['creditNumber'] )) {
            throw new LocalizedException($this->_dataHelper->__('Unable to revert refund, creditNumber and/or orderNumber not found!'));
        }

        if (!$this->_dataHelper->isBackendAvailable()) {
            return $this;
        }

        $backendClient = $this->_dataHelper->getBackendClient();
        $ret           = $backendClient->refundReversal($addInfo['orderNumber'], $addInfo['creditNumber']);
        if ($ret->hasFailed()) {
            $msg = implode(',', array_map(function ($e) {
                /** @var \QentaCEE\QMore\Error $e */
                return $e->getConsumerMessage();
            }, $ret->getErrors()));
            $this->_logger->debug(__METHOD__ . ':' . $msg);
            throw new \Exception($msg);
        }

        $transactionId = $transaction->getTxnId() . '-reversal';

        $payment->setTransactionId($transactionId);

        /** @var \Magento\Sales\Model\Order\Payment\Transaction $transaction */
        $transaction = $this->_transactionBuilder->setPayment($payment)
                                                 ->setOrder($order)
                                                 ->setTransactionId($transactionId)
                                                 ->build(Transaction::TYPE_VOID);

        $transaction->setParentId($transaction->getTransactionId());
        $transaction->save();

        $payment->addTransactionCommentsToOrder($transaction, 'refund reversal: orderNumber:' . $addInfo['orderNumber'] . ' creditNumber:' . $addInfo['creditNumber']);

        $order->save();
        return $this;
    }

    /**
     * Void payment
     * Void is in regards to the payment on the order invoice - to void the authorization, for instance - so that
     * the funds aren't subsequently captured. Payments have to be refunded after capture and cannot be voided.
     *
     * map this operation to APPROVEREVERSAL
     *
     * @param \Magento\Framework\DataObject|InfoInterface|\Magento\Sales\Model\Order\Payment $payment
     *
     * @return $this
     * @throws \Exception
     * @api
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function void(\Magento\Payment\Model\InfoInterface $payment)
    {

        if (!$this->_dataHelper->isBackendAvailable()) {
            return $this;
        }

        $orderNumber = $payment->getAdditionalInformation('orderNumber');
        if (!strlen($orderNumber)) {
            /* dont throw an exception here, might be a pending payment */
            $this->_logger->debug(__METHOD__ . ':No order number found.');
            return $this;
        }

        $orderDetails = $this->getOrderDetails($orderNumber);

        $backendClient = $this->_dataHelper->getBackendClient();

        $approveDone = false;
        foreach ($orderDetails->getOrder()->getPayments() as $wdPayment) {
            /** @var \QentaCEE\QPay\Response\Toolkit\Order\Payment $wdPayment */

            $this->_logger->debug(__METHOD__ . ':operations allowed:' . implode(',',
                    $wdPayment->getOperationsAllowed()));
            if (in_array('APPROVEREVERSAL', $wdPayment->getOperationsAllowed())) {
                $this->_logger->debug(__METHOD__ . ":$orderNumber");
                $ret = $backendClient->approveReversal($orderNumber);
                if ($ret->hasFailed()) {
                    throw new \Exception($ret->getError()->getMessage());
                }

                $approveDone = true;

                $orderTransaction = $this->_transactionRepository->getByTransactionType(
                    Transaction::TYPE_ORDER,
                    $payment->getId(),
                    $payment->getOrder()->getId()
                );

                if ($orderTransaction) {
                    $payment->setParentTransactionId($orderTransaction->getTxnId());
                    $payment->setTransactionId($orderTransaction->getTxnId() . '-void');
                }

                $payment->addTransactionCommentsToOrder($orderTransaction, 'approveReversal');
            }

        }

        if (!$approveDone) {
            throw new \Exception($this->_dataHelper->__('Void not possible anymore for this payment, please try cancel instead!'));
        }


        return $this;
    }

    /**
     * Cancel payment
     * Cancelaltion, is when the order can no longer be modified. While an order payment might be voided a new
     * invoice can always be generated. Cancellation of an order prevents any future change from being made to it.
     *
     * If REFUND is available do a refund, otherwise do a DEPOSITREVERSAL
     * Dont make APPROVEREVERSAL (decision by PM)
     *
     * @param \Magento\Framework\DataObject|InfoInterface|\Magento\Sales\Model\Order\Payment $payment
     *
     * @return $this
     * @throws \Exception
     * @api
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function cancel(\Magento\Payment\Model\InfoInterface $payment)
    {
        if (!$this->_dataHelper->isBackendAvailable()) {
            return $this;
        }

        $orderNumber = $payment->getAdditionalInformation('orderNumber');
        if (!strlen($orderNumber)) {
            /* dont throw an exception here, might be a pending payment */
            $this->_logger->debug(__METHOD__ . ':No order number found.');
            return $this;
        }

        $orderDetails = $this->getOrderDetails($orderNumber);

        $backendClient = $this->_dataHelper->getBackendClient();

        $log = sprintf("%s", $orderNumber);

        /* if refund op is available order already has been closed */
        /* otherwise revert each single payment */
        if (in_array('REFUND', $orderDetails->getOrder()->getOperationsAllowed())) {
            $log .= sprintf(" action:REFUND %s", $orderDetails->getOrder()->getAmount());
            $ret = $backendClient->refund($orderNumber, $orderDetails->getOrder()->getAmount(),
                $orderDetails->getOrder()->getCurrency());
            if ($ret->hasFailed()) {
                throw new \Exception($ret->getError()->getMessage());
            }

        } else {
            foreach ($orderDetails->getOrder()->getPayments() as $payment) {
                /** @var \QentaCEE\QPay\Response\Toolkit\Order\Payment $payment */

                if (in_array('DEPOSITREVERSAL', $payment->getOperationsAllowed())) {
                    $log .= " action:DEPOSITREVERSAL APPROVEREVERSAL";
                    $ret = $backendClient->depositReversal($orderNumber, $payment->getPaymentNumber());
                    if ($ret->hasFailed()) {
                        throw new \Exception($ret->getError()->getMessage());
                    }

                }
            }
        }

        $this->_logger->debug(__METHOD__ . ":$log");

        return $this;
    }
}
