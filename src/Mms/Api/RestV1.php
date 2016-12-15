<?php
/**
 * Implements REST access to MMS
 * @category Mms
 * @package Mms\Api
 * @author Andreas Gerhards <andreas@lero9.co.nz>
 * @copyright Copyright (c) 2016 LERO9 Ltd.
 * @license Commercial - All Rights Reserved
 */

namespace Mms\Api;

use Entity\Wrapper\Order;
use Entity\Wrapper\Stockitem;


class RestV1 extends RestCurl
{

    const TEST_MODE = TRUE;
    const TEST_BASE_URI = 'https://staging-api-mm-base-mms.marketengine.com/';

    /** @var array $this->additionalCurlOptions */
    protected $additionalCurlOptions = array(
        CURLOPT_SSL_VERIFYHOST=>0,
        CURLOPT_SSL_VERIFYPEER=>0
    );


    /**
     * @param array $headers
     * @return string $initLogCode
     */
    protected function getLogCodePrefix()
    {
        return 'mms_rest';
    }

    /**
     * @param int $sinceId
     * @return array $orderIdsSinceResult = array('newSinceId'=>$sinceId, 'orderIds'=>$orderIds)
     */
    public function getOrderIdsSinceResult($sinceId)
    {
        if ((string) intval($sinceId) == (string) $sinceId) {
            $callType = 'orders/ids?since_id='.$sinceId;
            $response = $this->get($callType);
        }else{
            // ToDo: Log or throw
        }

        $localOrderIdsSinceResult = array();
        $map = array('new_since_id'=>'newSinceId', 'order_ids'=>'localOrderIds');
        $fallbackResponse = array('newSinceId'=>$sinceId, 'localOrderIds'=>array());

        foreach ($map as $from=>$to) {
            $localOrderIdsSinceResult[$to] = $response['Result'][$from];
        }

        if (!$response['success'] || count($localOrderIdsSinceResult) != count($map)) {
            $localOrderIdsSinceResult = $fallbackResponse;
        }

        return $localOrderIdsSinceResult;
    }

    /**
     * @param int $localOrderId
     * @param array $parameters  e.g. status, order_items
     * @return array $orderDetails
     */
    public function getOrderDetailsById($localOrderId, array $parameters = array())
    {
        $callType = 'orders/'.$localOrderId;

        if (count($parameters) > 0) {
            $parameters = array('fields'=>implode(',', $parameters));
        }
        $response = $this->get($callType, $parameters);

        if ($response['success'] && isset($response['Result'])) {
            $orderDetails = $response['Result'];
        }else{
            $orderDetails = array();
        }

        return $orderDetails;
    }

    /**
     * @param Order $order
     * @param array $parameters  e.g. status, order_items
     * @return array $orderDetails
     */
    public function getOrderDetails(Order $order, array $parameters = array())
    {
        $localOrderId = $this->getLocalId($order);
        return $this->getOrderDetailsById($localOrderId, $parameters);
    }

    /**
     * @param Order $order
     * @param array $parameters  e.g. status, order_items
     * @return array $orderDetails
     */
    public function completeOrder(Order $order, array $parameters = array())
    {
        $localOrderitemArray = array();
        foreach ($order->getOrderitems() as $orderitem) {
            $localOrderitemArray[] = array('order_item_id'=>$this->getLocalId($orderitem));
        }

        $callType = 'fulfillments/complete';
        $parameters = array(
            'order_id'=>$this->getLocalId($order),
            'tracking_reference'=>$order->getData('tracking_code'),
            'order_items'=>$localOrderitemArray
        );

        $response = $this->post($callType, $parameters);

        return $response['success'];
    }

    /**
     * @param Stockitem $stockitem
     * @param array $parameters
     * @return int|NULL $newStock
     */
    protected function updateStock(Stockitem $stockitem, array $parameters)
    {
        $variationId = $this->getLocalId($stockitem);

        $callType = 'variations/'.$variationId.'/inventory';
        $parameters['market_place'] = $this->node->getConfig('marketplace_id');
        $response = $this->patch($callType, $parameters);

        if ($response['success']) {
            // ToDo: Implement proper response if thats implemented on the MMS site
            $newStock = $parameters['available_quantity'];
        }else{
            $newStock = NULL;
        }

        return $newStock;
    }

    /**
     * @param Stockitem $stockitem
     * @param int $newQuantity
     * @return int|NULL $newStock
     */
    public function setStock(Stockitem $stockitem, $newQuantity)
    {
        $parameters = array('available_quantity'=>$newQuantity);
        return $this->updateStock($stockitem, $parameters);
    }

    /**
     * @param Stockitem $stockitem
     * @param int $adjustQuantity
     * @return int|NULL $newStock
     */
    // ToDo (maybe): Make this method accessible
    private function adjustStock(Stockitem $stockitem, $adjustQuantity)
    {
        $parameters = array('available_quantity_adjustment'=>$adjustQuantity);
        return $this->updateStock($stockitem, $parameters);
    }

}
