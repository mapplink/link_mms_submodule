<?php
/**
 * Node class for Mms
 * @category Mms
 * @author Andreas Gerhards <andreas@lero9.co.nz>
 * @copyright Copyright (c) 2016 HealthPost Ltd.
 * @license  Commercial - All Rights Reserved
 */

namespace Mms;

use Log\Service\LogService;
use Magelink\Exception\SyncException;
use Node\AbstractNode;
use Node\AbstractGateway;
use Mms\Gateway\OrderGateway;


class Node extends AbstractNode
{

    /**
     * @return string $nodeLogPrefix
     */
    protected function getNodeLogPrefix()
    {
        return 'mms_';
    }

    /**
     * Returns an api instance set up for this node. Will return false if that type of API is unavailable.
     * @param string $type The type of API to establish - must be available as a service with the name "magento_{type}"
     * @return object|false
     */
    public function getApi($type)
    {
        if(isset($this->_api[$type])){
            return $this->_api[$type];
        }

        $this->_api[$type] = $this->getServiceLocator()->get('mms_'.$type);
        $this->getServiceLocator()->get('logService')
            ->log(LogService::LEVEL_INFO,
                'mms_init_api',
                'Creating API instance '.$type,
                array('type'=>$type),
                array('node'=>$this)
            );

        $apiExists = $this->_api[$type]->init($this);
        if (!$apiExists) {
            $this->_api[$type] = FALSE;
        }

        return $this->_api[$type];
    }

    /**
     * Implemented in each NodeModule
     * Should set up any initial data structures, connections, and open any required files that the node needs to operate.
     * In the case of any errors that mean a successful sync is unlikely, a Magelink\Exception\InitException MUST be thrown.
     */
    protected function _init() {}

    /**
     * Implemented in each NodeModule
     * The opposite of _init - close off any connections / files / etc that were opened at the beginning.
     * This will always be the last call to the Node.
     * NOTE: This will be called even if the Node has thrown a NodeException, but NOT if a SyncException or other Exception is thrown (which represents an irrecoverable error)
     */
    protected function _deinit() {}

    /**
     * @return OrderGateway $orderGateway
     */
    public function loadOrderGateway()
    {
        $this->isOverdueRun = FALSE;
        return $this->_lazyLoad('order');
    }

    /**
     * Implemented in each NodeModule
     * Returns an instance of a subclass of AbstractGateway that can handle the provided entity type.
     *
     * @throws MagelinkException
     * @param string $entityType
     * @return AbstractGateway
     */
    protected function _createGateway($entityType)
    {
        switch ($entityType) {
            case 'order':
                $gateway = new Gateway\OrderGateway();
                break;
            case 'stockitem':
                $gateway = new Gateway\StockGateway();
                break;
            default:
                throw new SyncException('Unknown/invalid entity type '.$entityType);
        }

        return $gateway;
    }

}
