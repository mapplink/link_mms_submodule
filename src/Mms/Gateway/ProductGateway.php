<?php
/**
 * @category Magento
 * @package Magento\Gateway
 * @author Andreas Gerhards <andreas@lero9.co.nz>
 * @copyright Copyright (c) 2016 LERO9 Ltd.
 * @license Commercial - All Rights Reserved
 */

namespace Mms\Gateway;



class ProductGateway extends AbstractGateway
{

    const GATEWAY_ENTITY = 'product';
    const GATEWAY_ENTITY_CODE = 'p';


    /**
     * @param \Entity\Wrapper\Product $product
     * @return string $tmallBundles
     */
    protected static function getSanitisedTmallBundles(\Entity\Wrapper\Product $product)
    {
        $tmallBundles = preg_replace('#\s+#', '', $product->getData('tmall_bundles', FALSE));
        return $tmallBundles;
    }

    /**
     * @param \Entity\Wrapper\Product $product
     * @return bool $isMmsEntity
     */
    public static function isMmsEntity(\Entity\Wrapper\Product $product)
    {
        $isMmsEntity = (bool) self::getSanitisedTmallBundles($product);
        return $isMmsEntity;
    }

    /**
     * @param \Entity\Wrapper\Product $product
     * @return array $mmsQuantities
     */
    public static function getTmallQuantities(\Entity\Wrapper\Product $product)
    {
        $mmsQuantities = explode(',', self::getSanitisedTmallBundles($product));
        return $mmsQuantities;
    }

    /**
     * Initialize the gateway and perform any setup actions required. (module implementation)
     * @param $entityType
     * @return bool $success
     */
    protected function _init($entityType) {}

    /**
     * Retrieve and action all updated records (either from polling, pushed data, or other sources).
     * @return int $results
     */
    protected function retrieveEntities() {}

    /**
     * Write out all the updates to the given entity.
     * @param \Entity\Entity $entity
     * @param string[] $attributes
     * @param int $type
     * @return bool|NULL
     */
    public function writeUpdates(\Entity\Entity $entity, $attributes, $type = \Entity\Update::TYPE_UPDATE) {}

    /**
     * Write out the given action.
     * @param \Entity\Action $action
     * @return bool Whether to mark the action as complete
     */
    public function writeAction(\Entity\Action $action) {}

}
