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

use Magento\Quote\Model\Quote;
use Magento\Sales\Model\Order;
use Magento\Checkout\Model\Cart as CheckoutCart;
use \Magento\Sales\Model\Order\Payment\Transaction;

class OrderManagement
{
    /** @var \Qenta\CheckoutPage\Helper\Data */
    protected $_dataHelper = null;

    /**
     * @var \Magento\Sales\Model\Order\Payment\Transaction\Builder
     */
    protected $_transactionBuilder;

    /**
     * @var \Magento\Sales\Model\Order\Payment\Transaction\Repository
     */
    protected $_transactionRepository;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    protected $_logger;

    /**
     * @var \Magento\Quote\Model\QuoteManagement
     */
    protected $_quoteManagement;

    /**
     * @return \Magento\Framework\ObjectManagerInterface
     */
    protected $_objectManager;

    /**
     * @var \Magento\Quote\Api\CartRepositoryInterface
     */
    protected $_quoteRepository;

    /**
     * @var \Magento\Framework\Registry
     */
    protected $_registry;


    public function __construct(
        \Magento\Framework\ObjectManagerInterface $objectManager,
        \Psr\Log\LoggerInterface $logger,
        \Qenta\CheckoutPage\Helper\Data $helper,
        \Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface $transactionBuilder,
        \Magento\Sales\Api\TransactionRepositoryInterface $transactionRepository,
        \Magento\Quote\Api\CartManagementInterface $quoteManagement,
        \Magento\Quote\Api\CartRepositoryInterface $quoteRepository,
        \Magento\Framework\Registry $registry
    ) {
        $this->_logger                = $logger;
        $this->_dataHelper            = $helper;
        $this->_transactionRepository = $transactionRepository;
        $this->_transactionBuilder    = $transactionBuilder;
        $this->_quoteManagement       = $quoteManagement;
        $this->_objectManager         = $objectManager;
        $this->_quoteRepository       = $quoteRepository;
        $this->_registry              = $registry;
    }

    /**
     * @param Quote $quote
     *
     * @return Order
     */
    public function submitOrder($quote)
    {
        /* ev. use placeOrder? */
        /* $this->_quoteManagement->placeOrder() */

        /** @var Order $order */
        $order = $this->_quoteManagement->submit($quote);
        $order->save();

        return $order;
    }

    /**
     * Process order, create/update/cancel/delete order depending on config and order state
     *
     * @param \QentaCEE\Stdlib\Returns\ReturnAbstract $return
     *
     * @return Order|null
     * @throws \Exception
     */
    public function processOrder(\QentaCEE\Stdlib\Returns\ReturnAbstract $return)
    {
        $quoteId = $return->mage_quoteId;
        /** @var Quote $quote */
        $quote = $this->_objectManager->create('\Magento\Quote\Model\Quote');
        $quote->load($quoteId);
        if (!$quote->getId()) {
            throw new \Exception('Quote not found');
        }

        $orderIncrementId = $return->mage_orderId;
        /** @var Order $order */
        $order = $this->_objectManager->create('\Magento\Sales\Model\Order');
        $order->loadByIncrementId($orderIncrementId);
        $orderExists = (bool) $order->getId();

        if ($return->mage_orderCreation == 'before') {
            if (!$orderExists) {
                throw new \Exception('Order not found');
            }

            switch ($return->getPaymentState()) {
                case \QentaCEE\QPay\ReturnFactory::STATE_SUCCESS:
                case \QentaCEE\QPay\ReturnFactory::STATE_PENDING:
                    /* after a pending payment, order might have been processed manually */
                    /* just save transaction but dont update order */
                    if ($this->isOrderProcessed($order)) {
                        $this->saveTransaction(Transaction::TYPE_PAYMENT, '', $order, $return);
                    } else {
                        $this->confirmOrder($order, $return, false);
                    }
                    break;

                case \QentaCEE\QPay\ReturnFactory::STATE_CANCEL:
                case \QentaCEE\QPay\ReturnFactory::STATE_FAILURE:
                    // ev. set review state
                    //$order->setState(\Magento\Sales\Model\Order::STATE_PAYMENT_REVIEW);
                    if (!$this->isOrderProcessed($order)) {
                        $this->failedOrder($order, $return);
                    }
                    break;
            }

        } else if ($return->mage_orderCreation == 'after') {
            switch ($return->getPaymentState()) {

                case \QentaCEE\QPay\ReturnFactory::STATE_SUCCESS:
                case \QentaCEE\QPay\ReturnFactory::STATE_PENDING:
                    $fraudDetected = false;
                    if (!$orderExists) {
                        $order         = $this->submitOrder($quote);
                        $fraudDetected = !$this->_dataHelper->compareQuoteChecksum($quote, $return->quoteHash);
                        $orderExists   = true;
                    }

                    /* after a pending payment, order might have been processed manually */
                    /* just save transaction but dont update order */
                    if ($this->isOrderProcessed($order)) {
                        $this->saveTransaction(Transaction::TYPE_PAYMENT, '', $order, $return);
                    } else {
                        $this->confirmOrder($order, $return, $fraudDetected);
                    }
                    break;

                case \QentaCEE\QPay\ReturnFactory::STATE_CANCEL:
                case \QentaCEE\QPay\ReturnFactory::STATE_FAILURE:
                    if ($orderExists && !$this->isOrderProcessed($order)) {
                        $this->deleteOrder($order);
                    }
                    break;
            }
        } else {
            throw new \Exception('Unknown order creation type');
        }

        if ($orderExists) {
            $order->save();

            return $order;
        }

        return null;
    }

    /**
     * check if order already has been successfully processed.
     * check status history, beacause order could be in shipping or closed state too.
     *
     * @param $order \Magento\Sales\Model\Order
     *
     * @return bool
     */
    public function isOrderProcessed($order)
    {
        $history     = $order->getAllStatusHistory();
        $paymentInst = $order->getPayment()->getMethodInstance();
        if ($paymentInst) {
            foreach ($history AS $entry) {
                if ($entry->getStatus() == \Magento\Sales\Model\Order::STATE_PROCESSING) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param $quoteId
     *
     * @return Quote
     */
    public function reOrder($quoteId)
    {
        $quote = $this->_quoteRepository->get($quoteId);
        $quote->setIsActive(1)->setReservedOrderId(null);
        $this->_quoteRepository->save($quote);

        return $quote;
    }

    /**
     * Keep the failed order
     *
     * @param \Magento\Sales\Model\Order $order
     * @param \QentaCEE\Stdlib\Returns\ReturnAbstract $return
     *
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function failedOrder($order, $return)
    {
        /** @var \Magento\Sales\Model\Order\Payment $payment */
        $payment = $order->getPayment();

        $additionalInformation = Array();
        foreach ($return->getReturned() as $fieldName => $fieldValue) {
            $additionalInformation[htmlentities($fieldName)] = htmlentities($fieldValue);
        }
        $payment->setAdditionalInformation($additionalInformation);

        if ($order->canUnhold()) {
            $order->unhold();
        }

        $order->cancel();
        $order->save();
    }

    /**
     * delete failed order
     *
     * @param \Magento\Sales\Model\Order $order
     *
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function deleteOrder($order)
    {
        if ($order->canUnhold()) {
            $order->unhold();
        }

        $order->cancel();
        /* hack isSecureArea flag, otherwise actionValidator fails */
        $this->_registry->unregister('isSecureArea');
        $this->_registry->register('isSecureArea', true);
        $order->delete();
    }

    /**
     * Confirm the payment of an order
     *
     * @param \Magento\Sales\Model\Order $order
     * @param \QentaCEE\Stdlib\Returns\ReturnAbstract $return
     * @param bool $fraudDetected
     *
     * @return Order
     *
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function confirmOrder($order, $return, $fraudDetected)
    {
        /** @var \Magento\Sales\Model\Order\Payment $payment */
        $payment = $order->getPayment();

        /** @var \Qenta\CheckoutPage\Model\AbstractPayment $paymentInstance */
        $paymentInstance = $payment->getMethodInstance();

        $additionalInformation = Array();
        foreach ($return->getReturned() as $fieldName => $fieldValue) {
            $additionalInformation[htmlentities($fieldName)] = htmlentities($fieldValue);
        }
        $payment->setAdditionalInformation($additionalInformation);

        if ($fraudDetected) {
            $order->setStatus(\Magento\Sales\Model\Order::STATUS_FRAUD);
            $message = $this->_dataHelper->__('fraud attemmpt detected, cart has been modified during checkout!');
            $this->_logger->debug(__METHOD__ . ':' . $message);
            $order->addStatusHistoryComment($message);
        }

        $doCapture = false;
        if (!$this->isOrderProcessed($order)) {
            if ($return->getPaymentState() == \QentaCEE\QPay\ReturnFactory::STATE_PENDING) {
                /** @var \QentaCEE\QPay\Returns\Pending $return */
                $order->setState(\Magento\Sales\Model\Order::STATE_PENDING_PAYMENT);
                $message = $this->_dataHelper->__('The payment authorization is pending.');
            } else {
                /** @var \QentaCEE\QPay\Returns\Success $return */
                $order->setState(\Magento\Sales\Model\Order::STATE_PROCESSING);
                $order->setStatus(\Magento\Sales\Model\Order::STATE_PROCESSING);
                $message = $this->_dataHelper->__('The payment has been successfully completed.');

                // invoice payment
                if ($order->canInvoice()) {

                    $invoice = $order->prepareInvoice();

                    $invoice->register();

                    /* capture invoice if toolkit is not availble */
                    if (!$this->_dataHelper->isBackendAvailableAlwaysFalse()) {
                        $doCapture = true;
                    } else {
                        $hasBackedOps = false;

                        $orderDetails = $paymentInstance->getOrderDetails($payment->getAdditionalInformation('orderNumber'));
                        foreach ($orderDetails->getOrder()->getPayments() as $wdPayment) {
                            /** @var \QentaCEE\QPay\Response\Toolkit\Order\Payment $wdPayment */

                            $this->_logger->debug(__METHOD__ . ':payment-state:' . $wdPayment->getState() . ' allowed operations:' . implode(',',
                                    $wdPayment->getOperationsAllowed()));

                            if (count($wdPayment->getOperationsAllowed())) {
                                $hasBackedOps = true;
                                break;
                            }
                        }

                        if (count($orderDetails->getOrder()->getOperationsAllowed())) {
                            $this->_logger->debug(__METHOD__ . ':order allowed operations: ' . implode(',',
                                    $orderDetails->getOrder()->getOperationsAllowed()));
                            $hasBackedOps = true;
                        }

                        /* no backend ops allowed anymore, assume final state of payment, capture invoice */
                        if (!$hasBackedOps) {
                            $doCapture = true;
                        }

                    }
                    
                    if ($doCapture && !$fraudDetected) {
                        $invoice->capture();
                    }

                    $order->addRelatedObject($invoice);
                }

            }

            $type = $doCapture ? Transaction::TYPE_CAPTURE : Transaction::TYPE_AUTH;
            $this->saveTransaction($type, $message, $order, $return);
        }

        $order->save();

        return $order;
    }

    /**
     * @param $type
     * @param $message
     * @param Order $order
     * @param \QentaCEE\Stdlib\Returns\ReturnAbstract $return
     *
     * @return \Magento\Sales\Api\Data\TransactionInterface|null
     */
    public
    function saveTransaction(
        $type,
        $message,
        $order,
        $return
    ) {
        $additionalInformation = Array();

        foreach ($return->getReturned() as $fieldName => $fieldValue) {
            $additionalInformation[htmlentities($fieldName)] = htmlentities($fieldValue);
        }

        $payment = $order->getPayment();

        $tid = '';
        if ($return instanceof \QentaCEE\Stdlib\Returns\Success) {
            $tid = $return->getGatewayReferenceNumber();
        }
        /* generate dummy GwRef for pending payments */
        if (!strlen($tid ?? '')) {
            $tid = uniqid('tmp_');
        }

        $transaction = $this->_transactionBuilder->setPayment($payment)
                                                 ->setOrder($order)
                                                 ->setTransactionId($tid)
                                                 ->build($type);
        $transaction->setIsClosed(0);

        /* must be set as RAW_DETAILS, otherwise they are not displayed in the admin area */
        $transaction->setAdditionalInformation(Transaction::RAW_DETAILS,
            $additionalInformation);

        $payment->addTransactionCommentsToOrder($transaction, $message);

        return $transaction;
    }

}
