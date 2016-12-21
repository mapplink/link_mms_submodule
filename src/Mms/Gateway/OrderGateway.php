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

    const MMS_ORDER_UNIQUE_PREFIX = 'MMS-';
    const MMS_PAYMENT_CODE = 'mmspay';

    const MMS_STATUS_PAID = 'paid';
    const MMS_STATUS_PARTIALLY_SHIPPED = 'partially_shipped';
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


    /**
     * Initialize the gateway and perform any setup actions required.
     * @param string $entityType
     * @return bool $success
     * @throws MagelinkException
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
        }elseif ($sinceId == 0
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
     * @return bool isMmsOrder
     */
    public static function isMmsOrder(Order $order)
    {
        return strpos($order->getUniqueId(), self::MMS_ORDER_UNIQUE_PREFIX) === 0;
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
     * @return bool $fulfilledOrder
     */
    public function fulfilOrder(Order $order)
    {
        return $this->rest->completeOrder($order);
    }

    /**
     * @param Order|int $orderOrStoreId
     * @return int $storeId
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
     * @return int $appId
     */
    protected function getAppId()
    {
        $marketplaceId = $this->_node->getConfig('app_id');

        if (is_null($marketplaceId)) {
            throw new MagelinkException('Please define the app id.');
        }

        return $marketplaceId;
    }

    /**
     * @return int $appKey
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
     * @return int $marketplaceId
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
     * @return int $marketplaceId
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
     * @return int $storeId
     */
    protected function getCustomerStoreId($orderOrStoreId)
    {
        $globalCustomer = TRUE;
        return $this->getEntityStoreId($orderOrStoreId, $globalCustomer);
    }

    /**
     * @param Order|int $orderOrStoreId
     * @return int $storeId
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
     * @return bool|NULL
     * @throws MagelinkException
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

    /**
     * Store order with provided order data
     * @param array $orderData
     * @throws GatewayException
     * @throws MagelinkException
     * @throws NodeException
     */
    protected function storeOrderData(array $orderData)
    {
        $logLevel = LogService::LEVEL_INFO;
        $logMessageSuffix = $logCodeSuffix = '';

        $data = array();
        $storeId = self::getStoreIdFromMarketPlaceId($orderData['marketplace_id']);
        $uniqueId = self::MMS_ORDER_UNIQUE_PREFIX.$orderData['marketplace_order_reference'];
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

        $itemSubTotals = $baseToCurrencyRateGrandTotal = $baseToCurrencyRate = 0;
        foreach ($this->itemTotalCodes as $code=>$perItem) {
            $itemTotals[$code] = 0;
        }

        if (isset($orderData['order_items'])) {
            foreach ($orderData['order_items'] as $orderitem) {
                if (isset($orderitem['quantity'])) {
                    $rowTotals = array();

                    foreach ($this->itemTotalCodes as $code=>$perItem) {
                        if (isset($orderitem['item']['local_order_item_financials'][$code])) {
                            $fieldValue = $orderitem['item']['local_order_item_financials'][$code];
                            if ($perItem) {
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
        $baseToCurrencyRate /= $baseToCurrencyRateGrandTotal;
        $grandTotal = $itemTotals[self::GRAND_TOTAL_BASE];

        foreach ($itemTotals as $code=>$total) {
            $totalCode = self::getTotalCode($code);
            $data[$totalCode] = $orderData[$totalCode] = $itemTotals[$code];
        }
        unset($data['price_total']);

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

        if (isset($orderData['customer_id']) && $orderData['customer_id']) {
            $nodeId = $this->_node->getNodeId();
            $customer = $this->_entityService
                ->loadEntity($nodeId, 'customer', $this->getCustomerStoreId($storeId), $data['customer_email']);
            if ($customer && $customer->getId()) {
                $data['customer'] = $customer;
            }else{
                $data['customer'] = $this->createCustomerEntity($orderData);
            }
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
                $this->_entityService->beginEntityTransaction('mms-order-'.$uniqueId);
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

                    $this->_entityService->commitEntityTransaction('mms-order-'.$uniqueId);
                }catch (\Exception $exception) {
                    $this->_entityService->rollbackEntityTransaction('mms-order-'.$uniqueId);
                    throw new GatewayException($exception->getMessage(), $exception->getCode(), $exception);
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
                        'Status change' => 'Order #'.$uniqueId.' moved from '.$oldStatus.' to '.$data['status']
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

        try{
            $this->updateStatusHistory($orderData, $existingEntity);
        }catch (\Exception $exception) {
            $this->getServiceLocator()->get('logService')
                ->log(LogService::LEVEL_ERROR,
                    'mms_o_w_herr'.$logCodeSuffix,
                    'Updating of the status history failed on order '.$uniqueId.'.',
                    array('order'=>$uniqueId, 'order data'=>$orderData, 'error'=>$exception->getMessage()),
                    array('entity'=>$existingEntity, 'exception'=>$exception)
                );
        }
    }

    /**
     * Retrieve and action all updated records (either from polling, pushed data, or other sources).
     * @throws GatewayException
     * @throws MagelinkException
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
            ->setSinceId($this->_nodeEntity->getNodeId(), 'order', 'retrieve', $newSinceId)
            ->setTimestamp($this->_nodeEntity->getNodeId(), 'order', 'retrieve', $this->getNewRetrieveTimestamp());

        return count($results);
    }

    /**
     * Insert any new status history entries as entity comments
     * @param array $orderData The full order data
     * @param Order $orderEntity The order entity to attach to
     * @throws MagelinkException
     * @throws NodeException
     */
    protected function updateStatusHistory(array $orderData, Order $orderEntity)
    {
        $referenceIds = array();
        $commentIds = array();
        $comments = $this->_entityService->loadEntityComments($orderEntity);

        foreach($comments as $com){
            $referenceIds[] = $com->getReferenceId();
            $commentIds[] = $com->getCommentId();
        }

        foreach ($orderData['status_history'] as $historyItem) {
            if (isset($historyItem['comment']) && preg_match('/{([0-9]+)} - /', $historyItem['comment'], $matches)) {
                if(in_array($matches[1], $commentIds)){
                    continue; // Comment already loaded through another means
                }
            }
            if (in_array($historyItem['created_at'], $referenceIds)) {
                continue; // Comment already loaded
            }

            if (!isset($historyItem['comment'])) {
                $historyItem['comment'] = '(no comment)';
            }
            if (!isset($historyItem['status'])) {
                $historyItem['status'] = '(no status)';
            }
            $notifyCustomer = isset($historyItem['is_customer_notified']) && $historyItem['is_customer_notified'] == '1';

            $this->_entityService->createEntityComment(
                $orderEntity,
                'Magento',
                'Status History Event: '.$historyItem['created_at'].' - '.$historyItem['status'],
                $historyItem['comment'],
                $historyItem['created_at'],
                $notifyCustomer
            );
        }
    }

    /**
     * Create all the OrderItem entities for a given order
     * @param array $orderData
     * @param Order $order
     * @throws MagelinkException
     * @throws NodeException
     */
    protected function createItems(array $orderData, Order $order)
    {
        $nodeId = $this->_node->getNodeId();
        $parentId = $order->getId();

        foreach ($orderData['items'] as $item) {
            $uniqueId = $orderData['increment_id'].'-'.$item['sku'].'-'.$item['item_id'];

            $entity = $this->_entityService
                ->loadEntity(
                    $this->_node->getNodeId(),
                    'orderitem',
                    self::getEntityStoreId($order),
                    $uniqueId
                );
            if (!$entity) {
                $localId = $item['item_id'];
                $product = $this->_entityService->loadEntity($this->_node->getNodeId(), 'product', 0, $item['sku']);
                $data = array(
                    'product'=>($product ? $product->getId() : null),
                    'sku'=>$item['sku'],
                    'product_name'=>isset($item['name']) ? $item['name'] : '',
                    'is_physical'=>((isset($item['is_virtual']) && $item['is_virtual']) ? 0 : 1),
                    'product_type'=>(isset($item['product_type']) ? $item['product_type'] : null),
                    'quantity'=>$item['qty_ordered'],
                    'item_price'=>(isset($item['base_price']) ? $item['base_price'] : 0),
                    'total_price'=>(isset($item['base_row_total']) ? $item['base_row_total'] : 0),
                    'total_tax'=>(isset($item['base_tax_amount']) ? $item['base_tax_amount'] : 0),
                    'total_discount'=>(isset($item['base_discount_amount']) ? $item['base_discount_amount'] : 0),
                    'weight'=>(isset($item['row_weight']) ? $item['row_weight'] : 0),
                );

                if (isset($item['base_price_incl_tax'])) {
                    $data['item_tax'] = $item['base_price_incl_tax'] - $data['item_price'];
                }elseif ($data['total_price'] && $data['total_price'] > 0) {
                    $data['item_tax'] = ($data['total_tax'] / $data['total_price']) * $data['item_price'];
                }elseif ($data['quantity'] && $data['quantity'] > 0){
                    $data['item_tax'] = $data['total_tax'] / $data['quantity'];
                }else{
                    $data['item_tax'] = 0;
                }

                $data['item_discount'] = ($data['quantity'] ? $data['total_discount'] / $data['quantity'] : 0);

                $this->getServiceLocator()->get('logService')
                    ->log(LogService::LEVEL_INFO,
                        'mms_o_re_cr_oi',
                        'Create item data',
                        array('orderitem uniqued id'=>$uniqueId, 'quantity'=>$data['quantity'],'data'=>$data)
                    );

                $storeId = ($this->_node->isMultiStore() ? $orderData['store_id'] : 0);
                $orderitem = $this->_entityService
                    ->createEntity($nodeId, 'orderitem', $storeId, $uniqueId, $data, $parentId);
                $this->_entityService
                    ->linkEntity($this->_node->getNodeId(), $orderitem, $localId);

                $this->updateStockQuantities($order, $orderitem);
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

        if (isset($orderData['addresses'])) {
            foreach ($orderData['addresses'] as $key=>$address) {
                if (isset($address['language_code'])) {
                    if (strtolower(substr($address['language_code'], 0, strlen($languageCode))) == $languageCode
                      || is_null($languageCode)) {
                        $addressArray = $address;
                        break;
                    }
                }
            }
        }

        return $addressArray;
    }

    /**
     * @param array $orderData
     * @return array $chineseAddressArray
     */
    protected function getChineseAddressArray(array $orderData)
    {
        return $this->getAddressArrayByLanguageCode($orderData, 'zh-');
    }

    /**
     * @param array $orderData
     * @return array $englishAddressArray
     */
    protected function getEnglishAddressArray(array $orderData)
    {
        return $this->getAddressArrayByLanguageCode($orderData, 'en-');
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
        $nameParts = explode(' ', $name);
        $nameArray = array(
            'last_name'=>array_pop($nameParts),
            'first_name'=>array_shift($nameParts),
            'middle_name'=>(count($nameParts) > 0 ? implode(' ', $nameParts) : NULL)
        );

        return $nameArray;
    }

    /**
     * @param array $orderData
     * @return Entity|NULL $customer
     */
    protected function createCustomerEntity(array $orderData)
    {
        $englishAddress = $this->getEnglishAddressArray($orderData);
        $chineseAddress = $this->getChineseAddressArray($orderData);
        $firstAddress = $this->getFirstAddressArray($orderData);

        if (isset($englishAddress['name'])) {
            $name = $englishAddress['name'];
            $email = $englishAddress['contact_email_1'];
        }elseif (isset($chineseAddress['name'])) {
            $name = $chineseAddress['name'];
            $email = $chineseAddress['contact_email_1'];
        }elseif (isset($firstAddress['name'])) {
            $name = $firstAddress['name'];
            $email = $firstAddress['contact_email_1'];
        }else{
            $message = isset($orderData['order_id']) ? $orderData['order_id'] : 'without order id';
            throw new MagelinkException(' No address name found on MMS order '.$message.'.');
            $name = $email = '';
        }

        $data = self::getNameArray($name);
//        $data['accredo_customer_id'] = NULL;
        $data['customer_type'] = 'MMS customer';
//        $data['date_of_birth'] = NULL;
//        $data['enable_newsletter'] = NULL;
//        $data['newslettersubscription'] = NULL;

        $entity = $this->_entityService->createEntity($this->_node->getNodeId(), 'customer', 0, $email, $data);

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
     * @param string $type "billing" or "shipping"
     * @return Order|null $entity
     * @throws MagelinkException
     * @throws NodeException
     */
    protected function createAddressEntity(array $addressData, array $orderData, $type)
    {
        if (!array_key_exists('address_id', $addressData) || $addressData['address_id'] == NULL) {
            return NULL;
        }

        $uniqueId = 'order-'.$orderData['marketplace_order_reference'].(strlen($type) > 0 ? '-'.$type : '');
        $entity = $this->_entityService->loadEntity($this->_node->getNodeId(), 'address', 0, $uniqueId);

        if (!$entity) {
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

            $entity = $this->_entityService->createEntity($this->_node->getNodeId(), 'address', 0, $uniqueId, $data);
        }

        return $entity;
    }

    /**
     * Write out all the updates to the given entity.
     * @param \Entity\Entity $entity
     * @param string[] $attributes
     * @param int $type
     */
    public function writeUpdates(\Entity\Entity $entity, $attributes, $type = \Entity\Update::TYPE_UPDATE)
    {
        // TODO (unlikely): Create method. (We don't perform any direct updates to orders in this manner).
        return NULL;
    }

    /**
     * Write out the given action.
     * @param \Entity\Action $action
     * @return bool $success
     * @throws GatewayException
     * @throws MagelinkException
     * @throws NodeException
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
     *
     * @param Order $order
     * @param string $comment Optional comment to append to order
     * @param string $notify String boolean, whether to notify customer
     * @param string $sendComment String boolean, whether to include order comment in notify
     * @param array $itemsRefunded Array of item entity id->qty to refund, or null if automatic (all)
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
     *
     * @param Order $order
     * @param string $comment Optional comment to append to order
     * @param string $notify String boolean, whether to notify customer
     * @param string $sendComment String boolean, whether to include order comment in notify
     * @param array|null $itemsShipped Array of item entity id->qty to ship, or null if automatic (all)
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