<?php
/**
 * Mms\Service
 * @category Mms
 * @package Mms\Service
 * @author Andreas Gerhards <andreas@lero9.co.nz>
 * @copyright Copyright (c) 2016 LERO9 Ltd.
 * @license Commercial - All Rights Reserved
 */

namespace Mms\Service;

use Application\Service\ApplicationConfigService;
use Log\Service\LogService;
use Magelink\Exception\GatewayException;


class MmsConfigService extends ApplicationConfigService
{

    /**
     * @return array $storeMap
     */
    protected function getStoreMap()
    {
        return $this->getConfigData('store_map');
    }

    /**
     * @param string $entityType
     * @param int $storeId
     * @param bool $readFromManento
     * @return array $productMap
     */
    public function getMapByStoreId($entityType, $storeId, $readFromMagento)
    {
        $map = array();
        $storeMap = $this->getStoreMap();

        if (!is_numeric($storeId) && $readFromMagento ) {
            new GatewayException('That is not a valid call for store map with no store id and reading from Magento.');
        }else{
            foreach ($storeMap as $id=>$mapPreStore) {
                if (!$readFromMagento) {
                    $id = abs($id);
                }
                if ($storeId === FALSE || $storeId == $id && isset($mapPreStore[$entityType])) {
                    $mapPerStoreAndEntityType = $mapPreStore[$entityType];
                    $flippedMap = array_flip($mapPerStoreAndEntityType);

                    if (!is_array($mapPerStoreAndEntityType) || count($mapPerStoreAndEntityType) != count($flippedMap)) {
                        $message = 'There is no valid '.$entityType.' map';
                        if ($storeId !== FALSE) {
                            $message .= ' for store '.$storeId;
                        }
                        new GatewayException($message.'.');
                    }elseif ($readFromMagento) {
                        $map = array_replace_recursive($mapPerStoreAndEntityType, $map);
                    }else{
                        $map = array_replace_recursive($flippedMap, $map);
                    }
                }
            }
        }

        return $map;
    }

}