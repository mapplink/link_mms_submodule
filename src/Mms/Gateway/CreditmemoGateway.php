<?php
/**
 * @category MMS
 * @package Gateway
 * @author Andreas Gerhards <andreas@lero9.co.nz>
 * @copyright Copyright (c) 2017 LERO9 Ltd.
 * @license http://opensource.org/licenses/BSD-3-Clause BSD-3-Clause - Please view LICENSE.md for more information
 */

namespace Mms\Gateway;

use Entity\Action;
use Entity\Entity;
use Entity\Update;


class CreditmemoGateway extends AbstractGateway {


    /**
     * Initialize the gateway and perform any setup actions required.
     * @param string $entityType
     * @return bool $success;
     */
    public function _init($entity_type)
    {
        return TRUE;
    }

    /**
     * Retrieve and action all updated records (either from polling, pushed data, or other sources).
     * @return int|NULL $retrievedResults
     */
    protected function retrieveEntities()
    {
        return NULL;
    }

    /**
     * Write out all the updates to the given entity.
     * @param Entity $entity
     * @param array $attributes
     * @param int $type
     * @return NULL
     */
    public function writeUpdates(Entity $entity, $attributes, $type = Update::TYPE_UPDATE)
    {
        return NULL;
    }

    /**
     * Write out the given action.
     * @param Action $action
     * @return bool|NULL $success
     */

    public function writeAction(Action $action)
    {
        return NULL;
    }

}
