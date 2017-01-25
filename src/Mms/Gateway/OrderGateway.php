<?php
/**
 * @category Mms
 * @package Mms\Gateway
 * @author Andreas Gerhards <andreas@lero9.co.nz>
 * @copyright Copyright (c) 2016 LERO9 Ltd.
 * @license Commercial - All Rights Reserved
 */

namespace Mms\Gateway;

use Entity\Comment;
use Entity\Entity;
use Entity\Service\EntityService;
use Entity\Wrapper\Address;
use Entity\Wrapper\Order;
use Entity\Wrapper\Orderitem;
use Log\Service\LogService;
use Magelink\Exception\MagelinkException;
use Magelink\Exception\NodeException;
use Magelink\Exception\GatewayException;
use Node\AbstractNode;


class OrderGateway extends AbstractGateway
{

    const GATEWAY_ENTITY = 'order';
    const GATEWAY_ENTITY_CODE = 'o';

    const MMS_ORDER_UNIQUE_PREFIX = 'M';
    const MMS_PAYMENT_CODE = 'tmalipay';
    const MMS_FALLBACK_SKU = '<undefined on mms>';

    const MMS_STATUS_PAID = 'paid';
    const MMS_STATUS_PARTIALLY_SHIPPED = 'partially shipped';
    const MMS_STATUS_SHIPPED = 'shipped';
    const MMS_STATUS_COMPLETED = 'completed';
    const MMS_STATUS_CLOSED = 'closed';
    const MMS_STATUS_WAIT_FOR_DELIVERY = 'wait_seller_delivery';
    const MMS_STATUS_WAIT_FOR_GOODS = 'wait_seller_send_goods';

    /** @var array self::$mmsShippableStatusses */
    private static $mmsShippableStatusses = array(
        self::MMS_STATUS_PAID,
        self::MMS_STATUS_PARTIALLY_SHIPPED,
        self::MMS_STATUS_WAIT_FOR_DELIVERY,
        self::MMS_STATUS_WAIT_FOR_GOODS
    );
    /** @var array self::$mmsExcludeStatusses */
    private static $mmsExcludeStatusses = array(
        self::MMS_STATUS_SHIPPED,
        self::MMS_STATUS_COMPLETED,
        self::MMS_STATUS_CLOSED
    );
    /** @var array self::$initialMmsExcludeStatusses */
    private static $initialMmsExcludeStatusses = array(
        self::MMS_STATUS_PARTIALLY_SHIPPED,
        self::MMS_STATUS_SHIPPED,
        self::MMS_STATUS_COMPLETED,
        self::MMS_STATUS_CLOSED
    );
    /** @var array self::$addressToOrderMap */
    private static $addressToOrderMap = array(
        'name'=>'customer_name',
        'contact_email_1'=>'customer_email'
    );
    /** @var array self::$shippingMap */
    private static $shippingMap = array(
        'direct_mail'=>'int_ems_china_3-8_tracked'
    );
    /** @var array self::$orderDefaults */
    private static $orderDefaults = array(
        'customer_email'=>'magelink_log+mms@lero9.com',
        'shipping_method'=>'int_ems_china_3-8_tracked'
    );

    const GRAND_TOTAL_BASE = 'payment'; // 'price' does not take the promotion offset into account while 'payment' does
    /** @var array $this->itemTotalCodes  defines totals to be calculated and if they per item */
    protected $itemTotalCodes = array(
        'discount'=>FALSE,
        'payment'=>FALSE,
        'price'=>TRUE,
        'shipping'=>FALSE,
        'tax'=>FALSE,
        'weight'=>FALSE
    );
    /** @var array $this->addressArrayByLanguageCode */
    protected $addressArrayByLanguageCode = array();


    /**
     * Initialize the gateway and perform any setup actions required.
     * @param string $entityType
     * @return bool $success
     * @throws GatewayException
     */
    protected function _init($entityType)
    {
        $success = parent::_init($entityType);

        if ($entityType != 'order') {
            throw new GatewayException('Invalid entity type for this gateway');
            $success = FALSE;
        }

        return $success;
    }

    /**
     * Check, if the order should be ignored or imported
     * @param array $orderData
     * @param int|NULL $sinceId
     * @return bool $retrieve
     */
    protected function isOrderToBeRetrieved(array $orderData, $sinceId = NULL)
    {
        if (in_array($this->getOrderStatusFromOrderData($orderData), self::$mmsExcludeStatusses)) {
            $retrieve = FALSE;
        }elseif ($sinceId == 1
            && in_array($this->getOrderStatusFromOrderData($orderData), self::$initialMmsExcludeStatusses)) {
            $retrieve = FALSE;
        }else{
            $retrieve = TRUE;
        }

        return $retrieve;
    }

    /**
     * @param $orderStatus
     * @return bool $hasOrderStatusClosed
     */
    public static function hasOrderStatusClosed($orderStatus)
    {
        $hasOrderStatusClosed = $orderStatus == self::MMS_STATUS_CLOSED;
        return $hasOrderStatusClosed;
    }

    /**
     * @param Order $order
     * @return bool $isMmsOrder
     */
    public static function isMmsOrder(Order $order)
    {
        $isMmsOrder = strpos($order->getUniqueId(), self::MMS_ORDER_UNIQUE_PREFIX) === 0;
        return $isMmsOrder;
    }

    /**
     * @param array $orderData
     * @return string|NULL $orderStatus
     */
    protected function getOrderStatusFromOrderData(array $orderData)
    {
        if (isset($orderData['status'])) {
            $orderStatus = $orderData['status'];
        }else{
            $orderStatus = NULL;
            $this->getServiceLocator()->get('logService')
                ->log(LogService::LEVEL_WARN,
                    'mms_o_no_status',
                    'There is no status in the order data array.',
                    array('order data'=>$orderData)
                );
        }

        return $orderStatus;
    }

    /**
     * @param $orderStatus
     * @return bool
     */
    public static function isShippableOrderStatus($orderStatus)
    {
        $isShippableOrderStatus = in_array($orderStatus, self::$mmsShippableStatusses);
        return $isShippableOrderStatus;
    }

    /**
     * @param Order $order
     * @return bool $isShippableOrder
     */
    public function isOrderShippableOnMms(Order &$order)
    {
        $localOrderId = $this->_entityService->getLocalId($this->_node->getNodeId(), $order);
        $orderData = $this->rest->getOrderDetailsById($localOrderId);

        $orderStatus = $this->getOrderStatusFromOrderData($orderData);
        if ($orderStatus !== $order->getData('status')) {
            $order = $this->_entityService
                ->updateEntity($this->_node->getNodeId(), $order, array('status'=>$orderStatus));
        }

        return self::isShippableOrderStatus($orderStatus);
    }

    /**
     * @param Order $order
     * @return bool $successfulFulfilledOrder
     */
    public function fulfilOrder(Order $order)
    {
        return $this->rest->completeOrder($order);
    }

    /**
     * @param Order|int $orderOrStoreId
     * @return string|NULL $storeId
     */
    protected function getEntityStoreId($orderOrStoreId, $global)
    {
        if ($global) {
            $storeId = 0;
        }elseif (is_int($orderOrStoreId)) {
            $storeId = $orderOrStoreId;
        }elseif (is_object($orderOrStoreId) && substr(strrchr(get_class($orderOrStoreId), '\\'), 1) == 'Order') {
            $order = $orderOrStoreId;
            $storeId = $order->getStoreId();
        }else{
            $storeId = NULL;
        }

        return $storeId;
    }

    /**
     * @param string $marketplaceId
     * @return string $storeId
     */
    protected function getStoreIdFromMarketPlaceId($marketplaceId)
    {
        if (is_scalar($marketplaceId)) {
            $storeId = self::MMS_STORE_PREFIX.$marketplaceId;
        }else{
            $storeId = trim(trim(self::MMS_STORE_PREFIX, '/'));
        }
        return $storeId;
    }

    /**
     * @param string $code
     * @return string $totalCode
     */
    protected static function getTotalCode($code)
    {
        return $code.'_total';
    }

    /**
     * @return string $appId
     */
    protected function getAppId()
    {
        $appId = $this->_node->getConfig('app_id');

        if (is_null($appId)) {
            throw new MagelinkException('Please define the app id.');
        }

        return $appId;
    }

    /**
     * @return string $appKey
     */
    protected function getAppKey()
    {
        $marketplaceId = $this->_node->getConfig('app_key');

        if (is_null($marketplaceId)) {
            throw new MagelinkException('Please define the app key.');
        }

        return $marketplaceId;
    }

    /**
     * @return string $marketplaceId
     */
    protected function getMarketplaceId()
    {
        $marketplaceId = $this->_node->getConfig('marketplace_id');

        if (is_null($marketplaceId)) {
            throw new MagelinkException('Please define the marketplace id.');
        }

        return $marketplaceId;
    }

    /**
     * @return string $baseUrl
     */
    protected function getBaseUrl()
    {
        $baseUrl = $this->_node->getConfig('web_url');

        if (is_null($baseUrl)) {
            throw new MagelinkException('Please define the api base url.');
        }

        return $baseUrl;
    }

    /**
     * @param Order|int $orderOrStoreId
     * @return string $customerStoreId
     */
    protected function getCustomerStoreId($orderOrStoreId)
    {
        $globalCustomer = TRUE;
        return $this->getEntityStoreId($orderOrStoreId, $globalCustomer);
    }

    /**
     * @param Order|int $orderOrStoreId
     * @return string $stockStoreId
     */
    protected function getStockStoreId($orderOrStoreId)
    {
        $globalStock = TRUE;
        return $this->getEntityStoreId($orderOrStoreId, $globalStock);
    }

    /**
     * @param array $orderitemData
     * @return string|NULL $shippingMethod
     */
    protected function getShippingMethod(array $orderitemData)
    {
        if (isset($orderitemData['shipping_type']) && isset(self::$shippingMap[$orderitemData['shipping_type']])) {
            $shippingMethod = self::$shippingMap[$orderitemData['shipping_type']];
        }else{
            $shippingMethod = NULL;
        }

        return $shippingMethod;
    }

    /**
     * @param Order $order
     * @param Orderitem $orderitem
     * @return bool|NULL $success
     */
    protected function updateStockQuantities(Order $order, Orderitem $orderitem)
    {
        $qtyPreTransit = NULL;
        $orderStatus = $order->getData('status');
        $isOrderProcessing = self::isShippableOrderStatus($orderStatus);
        $isOrderClosed = self::hasOrderStatusClosed($orderStatus);

        $logData = array(
            'order id'=>$order->getId(),
            'orderitem'=>$orderitem->getId(),
            'sku'=>$orderitem->getData('sku')
        );
        $logEntities = array('node'=>$this->_node, 'order'=>$order, 'orderitem'=>$orderitem);

        if ($isOrderProcessing) {
            $attributeCode = 'qty_pre_transit';
        }elseif ($isOrderClosed) {
            $attributeCode = 'available';
        }else{
            $attributeCode = NULL;
        }

        if (isset($attributeCode)) {
            $storeId = $this->getStockStoreId($order);
            $logData['store_id'] = $storeId;

            $stockitem = $this->_entityService->loadEntity(
                $this->_node->getNodeId(),
                'stockitem',
                $storeId,
                $orderitem->getData('sku')
            );
            $logEntities['stockitem'] = $stockitem;

            $success = FALSE;
            if ($stockitem) {
                $attributeValue = $stockitem->getData($attributeCode, 0);
                $itemQuantity = $orderitem->getData('quantity', 0);
                if (is_array($itemQuantity)) {
                    $itemQuantity = array_pop($itemQuantity);
                }

                $updateData = array($attributeCode=>($attributeValue + $itemQuantity));
                $logData = array_merge($logData, array('quantity'=>$itemQuantity), $updateData);

                try{
                    $this->_entityService->updateEntity($this->_node->getNodeId(), $stockitem, $updateData, FALSE);
                    $success = TRUE;

                    $this->getServiceLocator()->get('logService')
                        ->log(LogService::LEVEL_INFO,
                            'mms_o_pre_upd',
                            'Updated '.$attributeCode.' on stockitem '.$stockitem->getEntityId(),
                            $logData, $logEntities
                        );
                }catch (\Exception $exception) {
                    $this->getServiceLocator()->get('logService')
                        ->log(LogService::LEVEL_ERROR,
                            'mms_o_si_upd_err',
                            'Update of '.$attributeCode.' failed on stockitem '.$stockitem->getEntityId(),
                            $logData, $logEntities
                        );
                }
            }else{
                $this->getServiceLocator()->get('logService')
                    ->log(LogService::LEVEL_ERROR,
                        'mms_o_si_no_ex',
                        'Stockitem '.$orderitem->getData('sku').' does not exist.',
                        $logData, $logEntities
                    );
            }
        }else{
            $this->getServiceLocator()->get('logService')
                ->log(LogService::LEVEL_DEBUGEXTRA,
                    'mms_o_upd_pre_f',
                    'No update of qty_pre_transit. Order '.$order->getUniqueId().' has wrong status: '.$orderStatus,
                    array('order id'=>$order->getId()),
                    $logData, $logEntities
                );
            $success = NULL;
        }

        return $success;
    }

    protected function getUniqueIdFromOrderData($orderData)
    {
        /** ToDo: isset */
        return self::MMS_ORDER_UNIQUE_PREFIX.$orderData['marketplace_order_reference'];
    }

    /**
     * @param array $orderData
     * @throws GatewayException
     */
    protected function storeOrderData(array $orderData)
    {
        $logLevel = LogService::LEVEL_INFO;
        $logMessageSuffix = $logCodeSuffix = '';

        $data = array();
        $storeId = self::getStoreIdFromMarketPlaceId($orderData['marketplace_id']);
        $uniqueId = $this->getUniqueIdFromOrderData($orderData);
        $localId = $orderData['order_id'];
        $createdAtTimestamp = strtotime($orderData['created_at']);

        if (isset($orderData['addresses'])) {
            foreach ($orderData['addresses'] as $address) {
                foreach (self::$addressToOrderMap as $addressKey=>$orderKey) {
                    if (!isset($data[$orderKey]) && isset($address[$addressKey])) {
                        $data[$orderKey] = $address[$addressKey];
                    }
                }
            }
        }

        $baseToCurrencyRateGrandTotal = $baseToCurrencyRate = 0;
        foreach ($this->itemTotalCodes as $code=>$isPerItem) {
            $itemTotals[$code] = 0;
        }

        if (isset($orderData['order_items'])) {
            foreach ($orderData['order_items'] as $orderitem) {
                if (isset($orderitem['quantity']) && isset($orderitem['local_order_item_financials'])) {
                    $rowTotals = array();

                    foreach ($this->itemTotalCodes as $code=>$isPerItem) {
                        if (isset($orderitem['local_order_item_financials'][$code])) {
                            $fieldValue = $orderitem['local_order_item_financials'][$code];

                            if ($isPerItem) {
                                $rowTotals[$code] = $orderitem['quantity'] * $fieldValue;
                            }else{
                                $rowTotals[$code] = $fieldValue;
                            }
                            $itemTotals[$code] += $rowTotals[$code];
                        }
                    }

                    $itemBaseToCurrencyRate = (isset($orderData['marketplace_to_local_exchange_rate_applied'])
                        ? $orderData['marketplace_to_local_exchange_rate_applied']
                        : (isset($orderData['marketplace_to_local_exchange_rate_estimated'])
                            ? $orderData['marketplace_to_local_exchange_rate_estimated']
                            : 0
                        ));

                    if ($itemBaseToCurrencyRate > 0) {
                        $baseToCurrencyRate += $itemBaseToCurrencyRate * $rowTotals['price'];
                        $baseToCurrencyRateGrandTotal += $rowTotals['price'];
                    }
                }

                if (!isset($data['shipping_method'])) {
                    $data['shipping_method'] = $this->getShippingMethod($orderitem);
                }
            }
        }

        if ($baseToCurrencyRateGrandTotal > 0) {
            $baseToCurrencyRate /= $baseToCurrencyRateGrandTotal;
        }
        $grandTotal = $itemTotals[self::GRAND_TOTAL_BASE];

        foreach ($itemTotals as $code=>$total) {
            $totalCode = self::getTotalCode($code);
            $data[$totalCode] = $orderData[$totalCode] = $itemTotals[$code];
        }
        // Shipping is included in the order item prices
        $data[self::getTotalCode('shipping')] = 0;
        // Total price is not to be assigned
        unset($data[self::getTotalCode('price')]);

        // Convert payment total to the correct format and key, if exists.
        $paymentTotalCode = self::getTotalCode('payment');
        if (isset($data[$paymentTotalCode])) {
            $data['payment_method'] = $this->_entityService
                ->convertPaymentData(self::MMS_PAYMENT_CODE, $data[$paymentTotalCode]);
            unset($data[$paymentTotalCode]);
        }
        unset($itemTotals, $paymentTotalCode);

        foreach (self::$orderDefaults as $orderKey=>$defaultValue) {
            if (!isset($data[$orderKey])) {
                $data[$orderKey] = $defaultValue;
            }
        }

        $data['status'] = $orderData['status'];
        $data['placed_at'] = date('Y-m-d H:i:s', $createdAtTimestamp);
        $data['grand_total'] = $grandTotal;
        $data['base_to_currency_rate'] = $baseToCurrencyRate;
//        $data['giftcard_total'] = $data['reward_total'] = $data['storecredit_total'] = 0;

        if (!isset($data['customer_email']) || strlen($data['customer_email']) == 0) {
            $data['customer_email'] = $this->getCustomerEmail($orderData);
        }

        if (isset($data['customer_email']) && $data['customer_email']) {
            $nodeId = $this->_node->getNodeId();
            $customer = $this->_entityService
                ->loadEntity($nodeId, 'customer', $this->getCustomerStoreId($storeId), $data['customer_email']);
            if ($customer && $customer->getId()) {
                $data['customer'] = $customer;
            }else{
                try {
                    $orderData['customer_email'] = $data['customer_email'];
                    $data['customer'] = $this->createCustomerEntity($orderData);
                }catch (\Exception $exception) {
                    $message = 'Exception on customer creation for order '.$uniqueId.': '.$exception->getMessage();
                    throw new GatewayException($message, $exception->getCode(), $exception);
                }
                unset($orderData['customer_email']);
            }
        }else{
//            $data['flagged'] = 1;
            $this->getServiceLocator()->get('logService')->log(LogService::LEVEL_ERROR, 'mms_o_nocu_err',
                'New order '.$uniqueId.' has no customer assigned.', array('order unique'=>$uniqueId));
        }

        $needsUpdate = TRUE;
        $orderComment = FALSE;

        /** @var Order $existingEntity */
        $existingEntity = $this->_entityService->loadEntityLocal(
            $this->_node->getNodeId(),
            'order',
            $storeId,
            $localId
        );

        if (!$existingEntity) {
            $existingEntity = $this->_entityService->loadEntity(
                $this->_node->getNodeId(),
                'order',
                $storeId,
                $uniqueId
            );

            if (!$existingEntity) {
                $transaction = 'mms-order-'.$uniqueId;
                $this->_entityService->beginEntityTransaction($transaction);
                try{
                    $data = array_merge(
                        $this->createAddresses($orderData),
                        $data
                    );

                    $existingEntity = $this->_entityService->createEntity(
                        $this->_node->getNodeId(),
                        'order',
                        $storeId,
                        $uniqueId,
                        $data,
                        NULL
                    );
                    $this->_entityService->linkEntity($this->_node->getNodeId(), $existingEntity, $localId);

                    $this->getServiceLocator()->get('logService')
                        ->log($logLevel,
                            'mms_o_new'.$logCodeSuffix,
                            'New order '.$uniqueId.$logMessageSuffix,
                            array('sku'=>$uniqueId),
                            array('node'=>$this->_node, 'entity'=>$existingEntity)
                        );

                    $this->createItems($orderData, $existingEntity);

                    $this->_entityService->commitEntityTransaction($transaction);
                }catch (\Exception $exception) {
                    $this->_entityService->rollbackEntityTransaction($transaction);
                    $message = 'Rollback of '.$transaction.': '.$exception->getMessage();
                    throw new GatewayException($message, $exception->getCode(), $exception);
                }

                $needsUpdate = FALSE;
                $orderComment = array('Initial sync'=>'Order #'.$uniqueId.' synced to HOPS.');
            }else{
                $this->getServiceLocator()->get('logService')
                    ->log($logLevel,
                        'mms_o_unlink'.$logCodeSuffix,
                        'Unlinked order '.$uniqueId.$logMessageSuffix,
                        array('sku'=>$uniqueId),
                        array('node'=>$this->_node, 'entity'=>$existingEntity)
                    );
                $this->_entityService->linkEntity($this->_node->getNodeId(), $existingEntity, $localId);
            }
        }else{
            $attributesNotToUpdate = array('grand_total');
            foreach ($attributesNotToUpdate as $code) {
                if ($existingEntity->getData($code, NULL) !== NULL) {
                    unset($data[$code]);
                }
            }
            $this->getServiceLocator()->get('logService')
                ->log($logLevel,
                    'mms_o_upd'.$logCodeSuffix,
                    'Updated order '.$uniqueId.$logMessageSuffix,
                    array('order'=>$uniqueId),
                    array('node'=>$this->_node, 'entity'=>$existingEntity)
                );
        }

        if ($needsUpdate) {
            try{
                $oldStatus = $existingEntity->getData('status', NULL);
                $statusChanged = $oldStatus != $data['status'];
                if (!$orderComment && $statusChanged) {
                    $orderComment = array(
                        'Status change'=>'Order #'.$uniqueId.' moved from '.$oldStatus.' to '.$data['status']
                    );

                    $statusData = array('status'=>$data['status']);
                    foreach ($existingEntity->getAllOrders() as $order) {
                        if ($existingEntity->getId() != $order->getId()) {
                            $this->_entityService
                                ->updateEntity($this->_node->getNodeId(), $order, $statusData, FALSE);
                        }
                    }
                }

                $movedToProcessing = self::isShippableOrderStatus($this->getOrderStatusFromOrderData($orderData))
                    && !self::isShippableOrderStatus($existingEntity->getData('status'));
                $movedToCancel = self::hasOrderStatusClosed($this->getOrderStatusFromOrderData($orderData))
                    && !self::hasOrderStatusClosed($existingEntity->getData('status'));
                $this->_entityService->updateEntity($this->_node->getNodeId(), $existingEntity, $data, FALSE);

                $order = $this->_entityService->loadEntityId($this->_node->getNodeId(), $existingEntity->getId());
                if ($movedToProcessing || $movedToCancel) {
                    /** @var Order $order */
                    foreach ($order->getOrderitems() as $orderitem) {
                        $this->updateStockQuantities($order, $orderitem);
                    }
                }
            }catch (\Exception $exception) {
                throw new GatewayException('Needs update: '.$exception->getMessage(), 0, $exception);
            }
        }

        try{
            if ($orderComment) {
                if (!is_array($orderComment)) {
                    $orderComment = array($orderComment=>$orderComment);
                }
                $this->_entityService
                    ->createEntityComment($existingEntity, 'MMS/HOPS', key($orderComment), current($orderComment));
            }
        }catch (\Exception $exception) {
            $this->getServiceLocator()->get('logService')
                ->log(LogService::LEVEL_ERROR,
                    'mms_o_w_cerr'.$logCodeSuffix,
                    'Comment creation failed on order '.$uniqueId.'.',
                    array('order'=>$uniqueId, 'order comment array'=>$orderComment, 'error'=>$exception->getMessage()),
                    array('entity'=>$existingEntity, 'exception'=>$exception)
                );
        }
    }

    /**
     * Retrieve and action all updated records (either from polling, pushed data, or other sources).
     * @return int $resultsCount
     * @throws GatewayException
     * @throws NodeException
     */
    protected function retrieveEntities()
    {
        $sinceId = $this->getLastSinceId();

        $this->getServiceLocator()->get('logService')
            ->log(LogService::LEVEL_INFO,
                'mms_o_re_since',
                'Retrieving orders from since id '.$sinceId.' onwards',
                array('type'=>'order', 'since_id'=>$sinceId)
            );

        $success = NULL;
        if ($this->rest) {
            try{
                $results = $this->rest->getOrderIdsSinceResult($sinceId);

                if (isset($results['localOrderIds'])) {
                    $this->getServiceLocator()->get('logService')
                        ->log(LogService::LEVEL_INFO,
                            'mms_o_rest_list',
                            'Retrieved order ids.',
                            array(
                                'since_id'=>$sinceId,
                                'new since_id'=>(isset($results['newSinceId']) ? $results['newSinceId'] : NULL),
                                'result no'=>count($results['localOrderIds']))
                        );

                    foreach ($results['localOrderIds'] as $localOrderId) {
                        $orderData = $this->rest->getOrderDetailsById($localOrderId);
                        if ($this->isOrderToBeRetrieved($orderData, $sinceId)) {
                            $success = $this->storeOrderData($orderData);
                        }
                    }
                }else{
                    throw new MagelinkException('OrderIdsSinceResult did not contain "localOrderIds" key.');
                }
            }catch(\Exception $exception) {
                if (!isset($results) || !$results) {
                    $this->getServiceLocator()->get('logService')->log(LogService::LEVEL_ERROR, 'mms_o_rest_lerr',
                        'Error on mms rest calls.',
                        array('results'=>(isset($results) ? $results : 'not set'), 'sinceId'=>$sinceId)
                    );
                }
                // store as sync issue
                throw new GatewayException($exception->getMessage(), $exception->getCode(), $exception);
                $results = array();
            }
        }else{
            throw new NodeException('No valid API available for sync');
            $results = array();
        }

        if (isset($results['newSinceId'])) {
            $newSinceId = $results['newSinceId'];
        }else{
            $newSinceId = $sinceId;
        }

        $this->_nodeService
            ->setTimestamp($this->_nodeEntity->getNodeId(), 'order', 'retrieve', $this->getNewRetrieveTimestamp())
            ->setSinceId($this->_nodeEntity->getNodeId(), 'order', 'retrieve', $newSinceId);

        return count($results);
    }

    /**
     * Create all the OrderItem entities for a given order
     * @param array $orderData
     * @param Order $order
     */
    protected function createItems(array $orderData, Order $order)
    {
        $nodeId = $this->_node->getNodeId();
        $uniqueOrderId = $this->getUniqueIdFromOrderData($orderData);
        $parentId = $order->getId();

        foreach ($orderData['order_items'] as $orderitem) {
            $localId = (isset($orderitem['order_item_id']) ? $orderitem['order_item_id'] : NULL);
            $localProductId = (isset($orderitem['item']['item_id']) ? $orderitem['item']['item_id'] : NULL);
            $localStockitemId = (isset($orderitem['item']['variation_id']) ? $orderitem['item']['variation_id'] : NULL);

            $variationSku = (isset($orderitem['item']['sku']) ? $orderitem['item']['sku'] : NULL);
            $itemSku = (isset($orderitem['item']['master_sku']) ? $orderitem['item']['master_sku'] : NULL);
            if (!is_null($variationSku)){
                $sku = $variationSku;
            }elseif (!is_null($itemSku)) {
                $sku = $itemSku;
            }

            if (isset($sku)) {
                $rawSku = $sku;
                $bundleMessage = '';
                $bundleSkuArray = explode(StockGateway::MMS_BUNDLE_SKU_SEPARATOR, $sku);
                $isBundledProduct = (count($bundleSkuArray) > 1);

                if (count($bundleSkuArray) > 2) {
                    $bundleMessage .= 'Bundle sku contains more than 1 separator.';
                }

                $bundleQuantity = array_pop($bundleSkuArray);
                $isInteger = ((string) intval($bundleQuantity) === ltrim($bundleQuantity, '0'));
                $bundleQuantity = intval($bundleQuantity);

                if ($isBundledProduct && $isInteger && $bundleQuantity > 0) {
                    $sku = implode(StockGateway::MMS_BUNDLE_SKU_SEPARATOR, $bundleSkuArray);
                }else{
                    $bundleQuantity = 1;
                    if ($isBundledProduct) {
                        $bundleMessage .= ' Invalid bundle sku multiplier. Set multiplier to 1.';
                    }
                }

                if (strlen($bundleMessage) > 0) {
                    $this->getServiceLocator()->get('logService')
                        ->log(LogService::LEVEL_ERROR, 'mms_o_re_oi_buex', trim($bundleMessage),
                            array('sku'=>$rawSku, 'order unique'=>$uniqueOrderId, 'order item id'=>$localId));
                }
                unset($bundleMessage, $bundleSkuArray, $isBundledProduct, $isInteger);
            }else{
                $sku = $rawSku = self::MMS_FALLBACK_SKU;
            }

            $uniqueId = $uniqueOrderId.'-'.$sku.'-'.$localId;

            $entity = $this->_entityService
                ->loadEntity(
                    $this->_node->getNodeId(),
                    'orderitem',
                    self::getEntityStoreId($order, FALSE),
                    $uniqueId
                );

            if (!$entity) {
                if ($sku == self::MMS_FALLBACK_SKU) {
                    $productId = NULL;
                    $this->getServiceLocator()->get('logService')
                        ->log(LogService::LEVEL_WARN, 'mms_o_re_oi_nsku',
                            'Item data did not contain a valid sku and therefore could not be associated to a product.',
                            array('order unique'=>$uniqueId, 'item sku'=>$itemSku, 'variation sku'=>$variationSku));
                }else{
                    $logData = array('raw sku'=>$rawSku, 'order unique'=>$uniqueOrderId, 'orderitem unique'=>$uniqueId);

                    $product = $this->_entityService->loadEntity($nodeId, 'product', 0, $sku);
                    if ($product) {
                        $productId = $product->getId();
                        $storedId = $this->_entityService->getLocalId($nodeId, $product);
                        if (!is_null($storedId) && !is_null($localProductId) && $storedId != $localProductId) {
                            $this->_entityService->unlinkEntity($nodeId, $product);
                            $this->getServiceLocator()->get('logService')
                                ->log(LogService::LEVEL_WARN, 'mms_o_re_oi_ulp', 'Unlinked local product id.',
                                    array('sku'=>$sku, 'stored local'=>$storedId, 'local'=>$localProductId));
                        }
                        if (is_null($localProductId) && is_null($storedId)) {
                            $this->getServiceLocator()->get('logService')
                                ->log(LogService::LEVEL_ERROR, 'mms_o_re_oi_nlp', 'Unable to link product.', $logData);
                        }elseif (!is_null($localProductId) && $storedId != $localProductId) {
                            $this->_entityService->linkEntity($nodeId, $product, $localProductId);
                        }
                    }else{
                        $productId = NULL;
                        $this->getServiceLocator()->get('logService')
                            ->log(LogService::LEVEL_ERROR, 'mms_o_re_oi_nop',
                                'No product existing for order item.', $logData);
                    }

                    $stockitems = array_unique(array(
                        $sku=>$this->_entityService->loadEntity($nodeId, 'stockitem', 0, $sku),
// Disabled: Won't be implemented at this stage and most likely neither in the future
//                        $rawSku=>$this->_entityService->loadEntity($nodeId, 'stockitem', 0, $rawSku)
                    ), SORT_REGULAR);

                    foreach ($stockitems as $stockUnique=>$stockitem) {
                        if (!is_null($stockitem)) {
                            $storedId = $this->_entityService->getLocalId($nodeId, $stockitem);
                            if (!is_null($storedId) && !is_null($localStockitemId) && $storedId != $localStockitemId) {
                                $this->_entityService->unlinkEntity($nodeId, $stockitem);
                                $this->getServiceLocator()->get('logService')->log(LogService::LEVEL_WARN,
                                    'mms_o_re_oi_ulsi', 'Unlinked local stockitem id.',
                                    array('sku'=>$stockUnique, 'stored local'=>$storedId, 'local'=>$localStockitemId));
                            }
                            if (is_null($localStockitemId) && is_null($storedId)) {
                                $linkLogData = array_replace($logData, array('sku'=>$stockUnique));
                                $this->getServiceLocator()->get('logService')->log(LogService::LEVEL_ERROR,
                                    'mms_o_re_oi_nlsi', 'Unable to link stock item.', $linkLogData);
                            }elseif (!is_null($localStockitemId) && $storedId != $localStockitemId) {
                                $this->_entityService->linkEntity($nodeId, $stockitem, $localStockitemId);
                            }
                            break;
                        }
                    }

                    if (is_null($stockitem)) {
                        $this->getServiceLocator()->get('logService')->log(LogService::LEVEL_ERROR,
                            'mms_o_re_oi_nosi', 'No stockitem existing for order item.', $logData);
                    }

                    unset($product, $stockitem, $storedId, $linkLogData);
                }

                $quantity = $orderitem['quantity'] * $bundleQuantity;
                if (isset($orderitem['local_order_item_financials']['tax'])) {
                    $totalTax = $orderitem['local_order_item_financials']['tax'];
                    $itemTax = ($quantity > 0 ? $totalTax / $quantity : $totalTax);
                }else {
                    $totalTax = $itemTax = 0;
                }
                if (isset($orderitem['local_order_item_financials']['discount'])) {
                    $totalDiscount = $orderitem['local_order_item_financials']['discount'];
                    $itemDiscount = ($quantity > 0 ? $totalDiscount / $quantity : $totalDiscount);
                }else {
                    $totalDiscount = $itemDiscount = 0;
                }
                if (isset($orderitem['local_order_item_financials']['payment'])) {
                    $totalPrice = $orderitem['local_order_item_financials']['payment'] + $totalDiscount;
                }else{
                    $totalPrice = $totalDiscount;
                }
                if (isset($orderitem['local_order_item_financials']['price'])) {
                    $itemPrice = $orderitem['local_order_item_financials']['price'] / $bundleQuantity + $itemDiscount;
                }else {
                    $itemPrice = $itemDiscount;
                }

                $data = array(
                    'product'=>$productId,
                    'sku'=>$sku,
                    'product_name'=>isset($orderitem['name']) ? $orderitem['name'] : '',
                    'is_physical'=>1,
                    'product_type'=>NULL,
                    'quantity'=>$quantity,
                    'item_price'=>$itemPrice,
                    'item_discount'=>$itemDiscount,
                    'item_tax'=>$itemTax,
                    'total_price'=>$totalPrice,
                    'total_discount'=>$totalDiscount,
                    'total_tax'=>$totalTax,
                    'weight'=>(isset($orderitem['item']['weight']) ? $orderitem['item']['weight'] : 0),
                );

                $this->getServiceLocator()->get('logService')
                    ->log(LogService::LEVEL_INFO, 'mms_o_re_cr_oi',
                        'Created order item data.',
                        array('orderitem unique'=>$uniqueId, 'quantity'=>$data['quantity'], 'data'=>$data));

                $storeId = $order->getStoreId();

                try {
                    $orderitem = $this->_entityService
                        ->createEntity($nodeId, 'orderitem', $storeId, $uniqueId, $data, $parentId);
                }catch (\Exception $exception) {
                    $message = 'Exception on orderitem ('.$uniqueId.') creation: '.$exception->getMessage();
                    throw new GatewayException($message, $exception->getCode(), $exception);
                }
                try {
                    $this->_entityService
                        ->linkEntity($nodeId, $orderitem, $localId);
                }catch (\Exception $exception) {
                    $message = 'Exception on orderitem ('.$uniqueId.') linking: '.$exception->getMessage();
                    throw new GatewayException($message, $exception->getCode(), $exception);
                }

                try {
                    $this->updateStockQuantities($order, $orderitem);
                }catch (\Exception $exception) {
                    $message = 'Exception on stock quantities update on '.$uniqueId.': '.$exception->getMessage();
                    throw new GatewayException($message, $exception->getCode(), $exception);
                }
            }
        }
    }

    /**
     * @param array $orderData
     * @param string $languageCode
     * @return array $addressArray
     */
    protected function getAddressArrayByLanguageCode(array $orderData, $languageCode = NULL)
    {
        $addressArray = array();
        $languageCode = (is_string($languageCode) ? ltrim(rtrim($languageCode, ' -')).'-' : NULL);

        if (!array_key_exists($languageCode, $this->addressArrayByLanguageCode)) {
            if (isset($orderData['addresses'])) {
                foreach ($orderData['addresses'] as $key=>$address) {
                    if (isset($address['language_code'])) {
                        $addressLanguageCode = strtolower(substr($address['language_code'], 0, strlen($languageCode)));
                        if ($addressLanguageCode == $languageCode || is_null($languageCode)) {
                            $languageCode = $addressLanguageCode;
                            $addressArray = $address;
                            $addressArray['address_id'] = uniqid();
                            break;
                        }
                    }
                }
            }

            $this->addressArrayByLanguageCode[$languageCode] = $addressArray;
        }

        return $this->addressArrayByLanguageCode[$languageCode];
    }

    /**
     * @param array $orderData
     * @return array $chineseAddressArray
     */
    protected function getChineseAddressArray(array $orderData)
    {
        return $this->getAddressArrayByLanguageCode($orderData, 'zh');
    }

    /**
     * @param array $orderData
     * @return array $englishAddressArray
     */
    protected function getEnglishAddressArray(array $orderData)
    {
        return $this->getAddressArrayByLanguageCode($orderData, 'en');
    }

    /**
     * @param array $orderData
     * @return array $firstAddressArray
     */
    protected function getFirstAddressArray(array $orderData)
    {
        return $this->getAddressArrayByLanguageCode($orderData);
    }

    /**
     * @param string $name
     * @return array $nameArray
     */
    protected function getNameArray($name)
    {
        $nameParts = array_map('trim', explode(' ', trim($name)));
        $nameArray = array(
            'last_name'=>array_pop($nameParts),
            'first_name'=>array_shift($nameParts),
            'middle_name'=>(count($nameParts) > 0 ? implode(' ', $nameParts) : NULL)
        );

        return $nameArray;
    }

    /**
     * @param array $orderData
     * @throws MagelinkException
     * @return string $customerName
     */
    protected function getCustomerName(array $orderData)
    {
        $englishAddress = $this->getEnglishAddressArray($orderData);
        $chineseAddress = $this->getChineseAddressArray($orderData);
        $firstAddress = $this->getFirstAddressArray($orderData);

        if (isset($englishAddress['name'])) {
            $name = $englishAddress['name'];
        }elseif (isset($chineseAddress['name'])) {
            $name = $chineseAddress['name'];
        }elseif (isset($firstAddress['name'])) {
            $name = $firstAddress['name'];
        }else{
            $message = isset($orderData['order_id']) ? $orderData['order_id'] : 'without order id';
            throw new MagelinkException(' No address name found on MMS order '.$message.'.');
            $name = '';
        }

        return $name;
    }

    /**
     * @param array $orderData
     * @return string $customerEmail
     */
    protected function getCustomerEmail(array $orderData)
    {
        $englishAddress = $this->getEnglishAddressArray($orderData);
        $chineseAddress = $this->getChineseAddressArray($orderData);
        $firstAddress = $this->getFirstAddressArray($orderData);

        if (isset($englishAddress['contact_email_1'])) {
            $email = $englishAddress['contact_email_1'];
        }elseif (isset($chineseAddress['contact_email_1'])) {
            $email = $chineseAddress['contact_email_1'];
        }elseif (isset($firstAddress['contact_email_1'])) {
            $email = $firstAddress['contact_email_1'];
        }else{
            $email = '';
        }

        if (!is_string($email) || strlen($email) < 6) {
            $email = 'tm_'.$this->getCustomerName($orderData);
            $maxLength = 103;
            $addressKeys = array(
                'address_line_1'=>1,
                'contact_phone_1'=>2,
                'postal_code'=>2,
                'city'=>3,
                'company'=>3,
                'province'=>4
            );
            $fieldsAdded = 0;

            foreach (array($englishAddress, $firstAddress, $chineseAddress) as $address) {
                foreach ($addressKeys as $key=>$numberOfFields) {
                    if (isset($address[$key]) && preg_replace('#\W+#', '', strlen($address[$key])) > 0) {
                        $part = preg_replace('#\W+#', '', strlen($address[$key]));
                        if (strlen($part) > 0) {
                            $email .= preg_replace('#\W+#', '', $address[$key]);
                            if ($fieldsAdded++ == 0) {
                                $maxFields = $numberOfFields;
                            }
                            if ($fieldsAdded >= $maxFields) {
                                break;
                            }
                        }
                    }
                }
                if ($fieldsAdded >= $maxFields) {
                    break;
                }
            }

            $email = strtolower(substr(preg_replace('#\W+#', '', $email), 0, $maxLength).'@noemail.healthpost.co.nz');
        }

        return $email;
    }

    /**
     * @param array $orderData
     * @return Entity|NULL $customer
     * @throws MagelinkException
     */
    protected function createCustomerEntity(array $orderData)
    {
        $name = $this->getCustomerName($orderData);
        $email = $this->getCustomerEmail($orderData);
        $storeId = $this->getCustomerStoreId(self::getStoreIdFromMarketPlaceId($orderData['marketplace_id']));

        $data = self::getNameArray($name);
//        $data['accredo_customer_id'] = NULL;
        $data['customer_type'] = 'MMS customer';
//        $data['date_of_birth'] = NULL;
//        $data['enable_newsletter'] = NULL;
//        $data['newslettersubscription'] = NULL;

        $entity = $this->_entityService->createEntity($this->_node->getNodeId(), 'customer', $storeId, $email, $data);

        return $entity;
    }

    /**
     * Create the Address entities for a given order and pass them back as the appropraite attributes
     * @param array $orderData
     * @return array $data
     */
    protected function createAddresses(array $orderData)
    {
        $data = array();

        if (isset($orderData['addresses'])) {
            $billingData = $this->getEnglishAddressArray($orderData);
            $shippingData = $this->getChineseAddressArray($orderData);

            if (count($billingData) == 0 && count($shippingData) == 0) {
                $billingData = $shippingData = $this->getFirstAddressArray();
            }elseif (count($billingData) == 0) {
                $billingData = $shippingData;
            }elseif (count($shippingData) == 0) {
                $shippingData = $billingData;
            }

            if (count($billingData) > 0) {
                if ($billingData == $shippingData) {
                    $data['billing_address'] = $data['shipping_address'] =
                        $this->createAddressEntity($billingData, $orderData, '');
                }else{
                    $data['billing_address'] =
                        $this->createAddressEntity($billingData, $orderData, 'billing');
                    $data['shipping_address'] =
                        $this->createAddressEntity($shippingData, $orderData, 'shipping');
                }
            }
        }

        return $data;
    }

    /**
     * Creates an individual address entity (billing or shipping)
     * @param array $addressData
     * @param array $orderData
     * @param string $type : "billing" or "shipping"
     * @return Address|NULL $address
     */
    protected function createAddressEntity(array $addressData, array $orderData, $type)
    {
        if (!array_key_exists('address_id', $addressData) || $addressData['address_id'] == NULL) {
            return NULL;
        }

        $uniqueId = 'order-'.$orderData['marketplace_order_reference'].(strlen($type) > 0 ? '-'.$type : '');
        $address = $this->_entityService->loadEntity($this->_node->getNodeId(), 'address', 0, $uniqueId);

        if (!$address) {
            if (strlen($addressData['address_line_1']) > strlen($addressData['address_line_3'])) {
                $streetArray = array(
                    trim($addressData['address_line_1']),
                    trim($addressData['address_line_2'].' '.$addressData['address_line_3'])
                );
            }else{
                $streetArray = array(
                    trim($addressData['address_line_1'].' '.$addressData['address_line_2']),
                    trim($addressData['address_line_3'])
                );
            }
            $street = trim(implode("\n", $streetArray));

            $data = $this->getNameArray($addressData['name']);
            $data['company'] = isset($addressData['company_name']) ? $addressData['company_name'] : NULL;
            $data['street'] = strlen($street) > 0 ? $street : NULL;
            $data['region'] = isset($addressData['province']) ? $addressData['province'] : NULL;
            $data['city'] = isset($addressData['city']) ? $addressData['city'] : NULL;
            $data['postcode'] = isset($addressData['postal_code']) ? $addressData['postal_code'] : NULL;
//            $data['country'] = isset($addressData['country']) ? $addressData['country'] : NULL;
            $data['country_code'] = isset($addressData['country_code']) ? $addressData['country_code'] : NULL;
            $data['telephone'] = isset($addressData['contact_phone_1']) ? $addressData['contact_phone_1'] : NULL;

            $address = $this->_entityService->createEntity($this->_node->getNodeId(), 'address', 0, $uniqueId, $data);
        }

        return $address;
    }

    /**
     * Write out all the updates to the given entity.
     * @param \Entity\Entity $entity
     * @param string[] $attributes
     * @param int $type
     * @return bool|NULL $success
     */
    public function writeUpdates(\Entity\Entity $entity, $attributes, $type = \Entity\Update::TYPE_UPDATE)
    {
        // TODO (unlikely): Create method. (We don't perform any direct updates to orders in this manner).
        return NULL;
    }

    /**
     * Write out the given action.
     * @param \Entity\Action $action
     * @return bool|NULL $success
     * @throws GatewayException
     */
    public function writeAction(\Entity\Action $action)
    {
        return NULL;

        /** @var \Entity\Wrapper\Order $order */
        $order = $action->getEntity();
        // Reload order because entity might have changed in the meantime
        $order = $this->_entityService->reloadEntity($order);
        $orderStatus = $order->getData('status');

        $success = TRUE;
        switch ($action->getType()) {
            case 'ship':
                if (self::isShippableOrderStatus($orderStatus)) {
                    $comment = ($action->hasData('comment') ? $action->getData('comment') : NULL);
                    $notify = ($action->hasData('notify') ? ($action->getData('notify') ? 'true' : 'false' ) : NULL);
                    $sendComment = ($action->hasData('send_comment') ?
                        ($action->getData('send_comment') ? 'true' : 'false' ) : NULL);
                    $itemsShipped = ($action->hasData('items') ? $action->getData('items') : NULL);
                    $trackingCode = ($action->hasData('tracking_code') ? $action->getData('tracking_code') : NULL);

                    $this->actionShip($order, $comment, $notify, $sendComment, $itemsShipped, $trackingCode);
                }else{
                    $message = 'Invalid order status for shipment: '
                        .$order->getUniqueId().' has '.$order->getData('status');
                    // Is that really necessary to throw an exception?
                    throw new GatewayException($message);
                    $success = FALSE;
                }
                break;
            case 'refund':
            case 'creditmemo':
                // ToDo (maybe): Not implemented
                break;
            default:
                // store as a sync issue
                throw new GatewayException('Unsupported action type '.$action->getType().' for Magento Orders.');
                $success = FALSE;
        }

        return $success;
    }

    /**
     * Preprocesses order items array (key=orderitem entity id, value=quantity) into an array suitable for Magento
     * (local item ID=>quantity), while also auto-populating if not specified.
     * @param Order $order
     * @param array|NULL $rawItems
     * @return array
     * @throws GatewayException
     */
    protected function preprocessRequestItems(Order $order, $rawItems = NULL)
    {
        $items = array();
        if(is_null($rawItems)){
            $orderItems = $this->_entityService->locateEntity(
                $this->_node->getNodeId(),
                'orderitem',
                $order->getStoreId(),
                array(
                    'PARENT_ID'=>$order->getId(),
                ),
                array(
                    'PARENT_ID'=>'eq'
                ),
                array('linked_to_node'=>$this->_node->getNodeId()),
                array('quantity')
            );
            foreach($orderItems as $oi){
                $localid = $this->_entityService->getLocalId($this->_node->getNodeId(), $oi);
                $items[$localid] = $oi->getData('quantity');
            }
        }else{
            foreach ($rawItems as $entityId=>$quantity) {
                $item = $this->_entityService->loadEntityId($this->_node->getNodeId(), $entityId);
                if ($item->getTypeStr() != 'orderitem' || $item->getParentId() != $order->getId()
                    || $item->getStoreId() != $order->getStoreId()){

                    $message = 'Invalid item '.$entityId.' passed to preprocessRequestItems for order '.$order->getId();
                    throw new GatewayException($message);
                }

                if ($quantity == NULL) {
                    $quantity = $item->getData('quantity');
                }elseif ($quantity > $item->getData('quantity')) {
                    $message = 'Invalid item quantity '.$quantity.' for item '.$entityId.' in order '.$order->getId()
                        .' - max was '.$item->getData('quantity');
                    throw new GatewayExceptionn($message);
                }

                $localId = $this->_entityService->getLocalId($this->_node->getNodeId(), $item);
                $items[$localId] = $quantity;
            }
        }
        return $items;
    }

    /**
     * Handles refunding an order in Magento
     * @param Order $order
     * @param string $comment Optional comment to append to order
     * @param string $notify String boolean, whether to notify customer
     * @param string $sendComment String boolean, whether to include order comment in notify
     * @param array $itemsRefunded Array of item entity id->qty to refund, or NULL if automatic (all)
     * @param int $shippingRefund
     * @param int $creditRefund
     * @param int $adjustmentPositive
     * @param int $adjustmentNegative
     * @throws GatewayException
     */
    protected function actionCreditmemo(Order $order, $comment = '', $notify = 'false', $sendComment = 'false',
        $itemsRefunded = NULL, $shippingRefund = 0, $creditRefund = 0, $adjustmentPositive = 0, $adjustmentNegative = 0)
    {
        $items = array();

        if (count($itemsRefunded)) {
            $processItems = $itemsRefunded;
        }else{
            $processItems = array();
            foreach ($order->getOrderitems() as $orderItem) {
                $processItems[$orderItem->getId()] = 0;
            }
        }

        foreach ($this->preprocessRequestItems($order, $processItems) as $local=>$qty) {
            $items[] = array('order_item_id'=>$local, 'qty'=>$qty);
        }

        $creditmemoData = array(
            'qtys'=>$items,
            'shipping_amount'=>$shippingRefund,
            'adjustment_positive'=>$adjustmentPositive,
            'adjustment_negative'=>$adjustmentNegative,
        );

        $originalOrder = $order->getOriginalOrder();
        try {
            $soapResult = $this->_soap->call('salesOrderCreditmemoCreate', array(
                $originalOrder->getUniqueId(),
                $creditmemoData,
                $comment,
                $notify,
                $sendComment,
                $creditRefund
            ));
        }catch (\Exception $exception) {
            // store as sync issue
            throw new GatewayException($exception->getMessage(), $exception->getCode(), $exception);
        }

        if (is_object($soapResult)) {
            $soapResult = $soapResult->result;
        }elseif (is_array($soapResult)) {
            if (isset($soapResult['result'])) {
                $soapResult = $soapResult['result'];
            }else{
                $soapResult = array_shift($soapResult);
            }
        }

        if (!$soapResult) {
            // store as a sync issue
            throw new GatewayException('Failed to get creditmemo ID from Magento for order '.$order->getUniqueId());
        }

        try {
            $this->_soap->call('salesOrderCreditmemoAddComment',
                array($soapResult, 'FOR ORDER: '.$order->getUniqueId(), FALSE, FALSE));
        }catch (\Exception $exception) {
            // store as a sync issue
            throw new GatewayException($exception->getMessage(), $exception->getCode(), $exception);
        }
    }

    /**
     * Handles shipping an order in Magento
     * @param Order $order
     * @param string $comment Optional comment to append to order
     * @param string $notify String boolean, whether to notify customer
     * @param string $sendComment String boolean, whether to include order comment in notify
     * @param array|NULL $itemsShipped Array of item entity id->qty to ship, or NULL if automatic (all)
     * @throws GatewayException
     */
    protected function actionShip(Order $order, $comment = '', $notify = 'false', $sendComment = 'false',
        $itemsShipped = NULL, $trackingCode = NULL)
    {
        $items = array();
        foreach ($this->preprocessRequestItems($order, $itemsShipped) as $local=>$qty) {
            $items[] = array('order_item_id'=>$local, 'qty'=>$qty);
        }
        if (count($items) == 0) {
            $items = NULL;
        }

        $orderId = ($order->getData('original_order') != NULL ?
            $order->resolve('original_order', 'order')->getUniqueId() : $order->getUniqueId());
        $this->getServiceLocator()->get('logService')
            ->log(LogService::LEVEL_DEBUGEXTRA,
                'mms_o_act_ship',
                'Sending shipment for '.$orderId,
                array(
                    'ord'=>$order->getId(),
                    'items'=>$items,
                    'comment'=>$comment,
                    'notify'=>$notify,
                    'sendComment'=>$sendComment
                ),
                array('node'=>$this->_node, 'entity'=>$order)
            );

        try {
            $soapResult = $this->_soap->call('salesOrderShipmentCreate', array(
                'orderIncrementId'=>$orderId,
                'itemsQty'=>$items,
                'comment'=>$comment,
                'email'=>$notify,
                'includeComment'=>$sendComment
            ));
        }catch (\Exception $exception) {
            // store as sync issue
            throw new GatewayException($exception->getMessage(), $exception->getCode(), $exception);
        }

        if (is_object($soapResult)) {
            $soapResult = $soapResult->shipmentIncrementId;
        }elseif (is_array($soapResult)) {
            if (isset($soapResult['shipmentIncrementId'])) {
                $soapResult = $soapResult['shipmentIncrementId'];
            }else{
                $soapResult = array_shift($soapResult);
            }
        }

        if (!$soapResult) {
            // store as sync issue
            throw new GatewayException('Failed to get shipment ID from Magento for order '.$order->getUniqueId());
        }

        if ($trackingCode != NULL) {
            try {
                $this->_soap->call('salesOrderShipmentAddTrack',
                    array(
                        'shipmentIncrementId'=>$soapResult,
                        'carrier'=>'custom',
                        'title'=>$order->getData('shipping_method', 'Shipping'),
                        'trackNumber'=>$trackingCode)
                );
            }catch (\Exception $exception) {
                // store as sync issue
                throw new GatewayException($exception->getMessage(), $exception->getCode(), $exception);
            }
        }
    }

}
