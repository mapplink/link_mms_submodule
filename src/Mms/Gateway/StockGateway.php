<?php
/**
 * @category Magento
 * @package Magento\Gateway
 * @author Andreas Gerhards <andreas@lero9.co.nz>
 * @copyright Copyright (c) 2014 LERO9 Ltd.
 * @license Commercial - All Rights Reserved
 */

namespace Mms\Gateway;

use Entity\Service\EntityService;
use Log\Service\LogService;
use Magelink\Exception\MagelinkException;
use Node\AbstractNode;
use Node\Entity;


class StockGateway extends AbstractGateway
{

    const GATEWAY_ENTITY = 'stockitem';
    const GATEWAY_ENTITY_CODE = 'si';

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
    public function writeUpdates(\Entity\Entity $stockitem, $attributes, $type=\Entity\Update::TYPE_UPDATE)
    {
        $nodeId = $this->_node->getNodeId();
        $uniqueId = $stockitem->getUniqueId();
        $localId = $this->_entityService->getLocalId($nodeId, $stockitem);

        $logCode = 'mms_si';
        $logMessage = 'Stock update for '.$uniqueId.' ';
        $logData = array('node'=>$nodeId, 'id'=>$stockitem->getEntityId(), 'unique'=>$uniqueId, 'local'=>$localId);

        if (in_array('available', $attributes)) {
            $localId = $this->_entityService->getLocalId($nodeId, $stockitem);
            if ($this->rest) {
                try{
                    $available = $logData['available'] = $stockitem->getData('available');
                    if ($localId) {
                        try{
                            $newStock = $logData['new stock'] = $this->rest->setStock($stockitem, $available);
                        }catch (\Exception $exception) {}

                        if (!isset($newStock) || ($newStock != $available)) {
                            $message = $logMessage.' via local id failed due to a API problem.';
                            unset($newStock);
                        }
                    }else{
                        $message = $logMessage.'could not be executed due to a missing local id.';
                    }

                    if (!isset($newStock)) {
                        $this->getServiceLocator()->get('logService')
                            ->log(LogService::LEVEL_DEBUG, $logCode.'_callbysku', $message, $logData);
                        $newStock = $logData['new stock'] = $this->rest
                            ->setStockBySku($stockitem->getUniqueId(), $available);
                    }
                    unset($message);

                    $success = ($newStock == $available);
                    if ($success) {
                        $logLevel = LogService::LEVEL_INFO;
                        $logCode .= '_suc';
                        $logMessage .= 'was successful.';
                    }else{
                        $logLevel = LogService::LEVEL_ERROR;
                        $logCode .= '_fail';
                        $logMessage .= 'failed due to a API problem.';
                    }
                }catch (\Exception $exception) {
                    $success = FALSE;
                    $logLevel = LogService::LEVEL_ERROR;
                    $logCode .= '_ex';
                    $logMessage .= 'failed with an exception: '.$exception->getMessage();
                }
            }elseif (isset($localId)) {
                $success = FALSE;
                $logLevel = LogService::LEVEL_WARN;
                $logCode .= '_norest';
                $logMessage .= 'could not be executed due to a problem with the REST initialisation.';
            }else{
                $success = FALSE;
                $logLevel = LogService::LEVEL_ERROR;
                $logCode .= '_none';
                $logMessage .= 'could not be processed. Neither local id nor REST was available.';
            }
        }else{
            $success = TRUE;
            $logLevel = LogService::LEVEL_DEBUG;
            $logCode .= '_skip';
            $logMessage .= 'was skipped.';
            $logData['attribute'] = implode(', ', $attributes);
        }

        $this->getServiceLocator()->get('logService')
            ->log($logLevel, $logCode, $logMessage, $logData);

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
