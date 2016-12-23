<?php
/**
 * @category Mms
 * @author Andreas Gerhards <andreas@lero9.co.nz>
 * @copyright Copyright (c) 2016 LERO9 Ltd.
 * @license Commercial - All Rights Reserved
 */

$moduleConfig = array (
    'node_types'=>array(
        'mms'=>array(
            'module'=>'Mms',
            'name'=>'Mms',
            'entity_type_support'=>array(
//                'product',
                'order',
//                'stockitem'
            ),
            'config'=>array( // Config options to be displayed to the administrator
                'web_url'=>array(
                    'label'=>'MMS Base API URL',
                    'type'=>'Text',
                    'required'=>TRUE
                ),
                'app_id'=>array(
                    'label'=>'Application ID',
                    'type'=>'Text',
                    'required'=>TRUE
                ),
                'app_key'=>array(
                    'label'=>'Application Key',
                    'type'=>'Text',
                    'required'=>TRUE
                ),
                'marketplace_id'=>array(
                    'label'=>'Marketplace ID',
                    'type'=>'Text',
                    'required'=>TRUE
                )
            )
        )
    ),
    'service_manager'=>array(
        'invokables'=>array(
            'mms_rest'=>'Mms\Api\RestV1',
            'mmsService'=>'Mms\Service\MmsService',
            'mmsConfigService'=>'Mms\Service\MmsConfigService',
        ),
        'shared'=>array(
            'mms_rest'=>FALSE,
            'mmsService'=>FALSE,
            'mmsConfigService'=>FALSE
        )
    )
);

return $moduleConfig;
