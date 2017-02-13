<?php
/**
 * @category MMS
 * @package Gateway
 * @author Andreas Gerhards <andreas@lero9.co.nz>
 * @copyright Copyright (c) 2017 LERO9 Ltd.
 * @license Commercial - All Rights Reserved
 */

namespace Mms\Gateway;

use Node\AbstractGateway;


class CreditmemoGateway extends AbstractGateway {


    /**
     * Initialize the gateway and perform any setup actions required.
     * @param string $entityType
     * @return boolean
     */
    public function _init($entity_type)
    {
        return;
    }

    /**
     * Retrieve and action all updated records (either from polling, pushed data, or other sources).
     * @return array $retrieveResults
     */
    protected function retrieveEntities()
    {
        return NULL;
    }

    /**
     * Write out all the updates to the given entity.
     * @param \Entity\Entity $entity
     * @param \Entity\Attribute[] $attributes
     */
    public function writeUpdates(\Entity\Entity $entity, $attributes, $type = \Entity\Update::TYPE_UPDATE)
    {
        return NULL;
    }

    /**
     * Write out the given action.
     * @param \Entity\Action $action
     */
    public function writeAction(\Entity\Action $action)
    {
        return NULL;
    }

}
