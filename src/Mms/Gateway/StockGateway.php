<?php
/**
 * @category Magento
 * @package Magento\Gateway
 * @author Andreas Gerhards <andreas@lero9.co.nz>
 * @copyright Copyright (c) 2016 LERO9 Ltd.
 * @license http://opensource.org/licenses/BSD-3-Clause BSD-3-Clause - Please view LICENSE.md for more information
 */

namespace Mms\Gateway;

use Log\Service\LogService;
use Magelink\Exception\MagelinkException;


class StockGateway extends AbstractGateway
{

    const GATEWAY_ENTITY = 'stockitem';
    const GATEWAY_ENTITY_CODE = 'si';

    const MMS_BUNDLE_SKU_SEPARATOR = '**';

    /**
     * Initialize the gateway and perform any setup actions required.
     * @param string $entityType
     * @return bool $success
     * @throws MagelinkException
     */
    protected function _init($entityType)
    {
        $success = parent::_init($entityType);

        if ($entityType != 'stockitem') {
            throw new \Magelink\Exception\MagelinkException('Invalid entity type for this gateway');
            $success = FALSE;
        }

        return $success;
    }

    /**
     * Retrieve and action all updated records (either from polling, pushed data, or other sources).
     * @return bool|NULL $success
     */
    public function retrieveEntities()
    {
        return NULL;
    }

    /**
     * Write out all the updates to the given entity.
     * @param \Entity\Entity $stockitem
     * @param string[] $attributes
     * @param int $type
     * @throws MagelinkException
     */
    public function writeUpdates(\Entity\Entity $stockitem, $attributes, $type = \Entity\Update::TYPE_UPDATE)
    {
        $nodeId = $this->_node->getNodeId();
        $product = $stockitem->getParent();
        $uniqueId = $stockitem->getUniqueId();
        $localId = $this->_entityService->getLocalId($nodeId, $stockitem);

        $isMmsEntity = ProductGateway::isMmsEntity($product);
        $mmsQuantities = ProductGateway::getTmallQuantities($product);

        $logCodePrefix = 'mms_si';
        $logMessagePrefix = 'Stock update for '.$uniqueId;
        $logMessage = '';
        $logData = array('node'=>$nodeId, 'id'=>$stockitem->getEntityId(), 'unique'=>$uniqueId, 'local'=>$localId);

        if (!$isMmsEntity) {
            $success = NULL;
            $logLevel = LogService::LEVEL_WARN;
            $logCode = $logCodePrefix.'_nmms';
            $logMessage = $logMessagePrefix.' was skipped. This item is not defined as a MMS product.';
        }elseif (!in_array('available', $attributes)) {
            $success = NULL;
            $logLevel = LogService::LEVEL_WARN;
            $logCode = $logCodePrefix.'_skip';
            $logMessage .= $logMessagePrefix.' was skipped. Availabilty is not set.';
            $logData['attribute'] = implode(', ', $attributes);
        }else{
            $localId = $this->_entityService->getLocalId($nodeId, $stockitem);
            if ($this->rest) {
                $logLevel = '';
                $remainingQuantities = count($mmsQuantities);
                foreach ($mmsQuantities as $multiplier) {
                    $prefix = ' ('.$multiplier.'x) '.$logMessagePrefix;
                    try{
                        $available = $logData['available'] = $stockitem->getData('available', 0);
                        if ($multiplier == 1) {
                            $sku = $uniqueId;
                        }else{
                            $sku = $uniqueId.self::MMS_BUNDLE_SKU_SEPARATOR.$multiplier;
                            $available = $logData['available / multiplier'] = floor($available / $multiplier);
                        }

                        if ($localId && $multiplier == 1) {
                            try{
                                $newStock = $logData['new stock'] = $this->rest->setStock($stockitem, $available);
                            }catch(\Exception $exception) {}

                            if (!isset($newStock) || ($newStock != $available)) {
                                $message = $prefix.' via local id failed due to a API problem.';
                                unset($newStock);
                            }
                        }elseif (!$localId) {
                            $message = $prefix.' could not be executed due to a missing local id.';
                        }else{
                            $message = $prefix.' (call by sku only).';
                        }

                        if (!isset($newStock)) {
                            if (isset($message)) {
                                $this->getServiceLocator()->get('logService')
                                    ->log(LogService::LEVEL_WARN, $logCodePrefix.'_callbysku', trim($message), $logData);
                            }
                            $newStock = $logData['new stock'] = $this->rest->setStockBySku($sku, $available);
                        }

                        $success = ($newStock == $available);
                        if ($success) {
                            if ($logLevel != LogService::LEVEL_ERROR && $logLevel != LogService::LEVEL_WARN) {
                                $logLevel = LogService::LEVEL_INFO;
                                $logCode = $logCodePrefix.'_suc';
                            }
                            $logMessage .= $prefix.' was successful.';
                        }elseif ($localId) {
                            $logLevel = LogService::LEVEL_ERROR;
                            $logCode = $logCodePrefix.'_fail';
                            $logMessage .= $prefix.' failed due to a API problem.';
                        }else{
                            if ($logLevel != LogService::LEVEL_ERROR) {
                                $logLevel = LogService::LEVEL_WARN;
                                $logCode = $logCodePrefix.'_ignore';
                            }
                            $logMessage .= $prefix.' failed due to a API problem and was therefore ignored (no local id).';
                        }
                        unset($message, $newStock);
                    }catch(\Exception $exception) {
                        $success = FALSE;
                        $logLevel = LogService::LEVEL_ERROR;
                        $logCode = $logCodePrefix.'_ex';
                        $logMessage .= $prefix.' failed with an exception: '.$exception->getMessage().'.';
                    }

                    if (--$remainingQuantities > 0) {
                        $this->getServiceLocator()->get('logService')->log($logLevel, $logCode, $logMessage, $logData);
                    }
                } // end foreach ($mmsQuantities as $multiplier)
            }elseif (isset($localId)) {
                $success = FALSE;
                $logLevel = LogService::LEVEL_ERROR;
                $logCode = $logCodePrefix.'_norest';
                $logMessage = $logMessagePrefix.' could not be executed due to a problem with the REST initialisation.';
            }else{
                $success = FALSE;
                $logLevel = LogService::LEVEL_ERROR;
                $logCode = $logCodePrefix.'_none';
                $logMessage = $logMessagePrefix.' could not be processed. Neither local id nor REST was available.';
            }
        }

        $this->getServiceLocator()->get('logService')
            ->log($logLevel, $logCode, trim($logMessage), $logData);

        return $success;
    }

    /**
     * Write out the given action.
     * @param \Entity\Action $action
     * @throws MagelinkException
     */
    public function writeAction(\Entity\Action $action)
    {
        return NULL;
    }

}
