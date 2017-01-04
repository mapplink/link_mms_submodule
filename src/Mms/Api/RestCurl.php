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

use Entity\Entity;
use Entity\Service\EntityService;
use Log\Service\LogService;
use Magelink\Exception\MagelinkException;
use Magelink\Exception\GatewayException;
use Mms\Node;
use Zend\Http\Client;
use Zend\Http\Headers;
use Zend\Http\Request;
use Zend\Json\Json;
use Zend\ServiceManager\ServiceLocatorAwareInterface;
use Zend\ServiceManager\ServiceLocatorInterface;
use Zend\Stdlib\Parameters;


abstract class RestCurl implements ServiceLocatorAwareInterface
{

    const ERROR_PREFIX = 'REST ERROR: ';

    /** @var ServiceLocatorInterface $this->serviceLocator */
    protected $serviceLocator;
    /** @var Node|NULL $this->node */
    protected $node = NULL;
    /** @var EntityService $this->entityService */
    protected $entityService;
    /** @var EntityService $this->entityConfigService */
    protected $entityConfigService;

    /** @var resource|FALSE|NULL $this->curlHandle */
    protected $curlHandle = NULL;
    /** @var string|NULL $this->authorisation */
    protected $authorisation = NULL;
    /** @var string|NULL $this->requestType */
    protected $requestType;
    /** @var  Request $this->request */
    protected $request;
    /** @var array $this->curlOptions */
    protected $curlOptions = array();
    /** @var array $this->baseCurlOptions */
    protected $baseCurlOptions = array(
        CURLOPT_RETURNTRANSFER=>1,
        CURLOPT_ENCODING=>'',
        CURLOPT_MAXREDIRS=>10,
        CURLOPT_TIMEOUT=>30,
        CURLOPT_HTTP_VERSION=>CURL_HTTP_VERSION_1_1
    );
    /** @var array $this->additionalCurlOptions */
    protected $additionalCurlOptions = array();
    /** @var bool $this->responseContainsResult */
    protected $responseContainsResult = TRUE;


    /**
     * Rest destructor
     */
    public function __destruct()
    {
        $this->closeCurl();
    }

    /**
     * @return ServiceLocatorInterface
     */
    public function getServiceLocator()
    {
        return $this->serviceLocator;
    }

    /**
     * @param ServiceLocatorInterface $serviceLocator
     */
    public function setServiceLocator(ServiceLocatorInterface $serviceLocator)
    {
        $this->serviceLocator = $serviceLocator;
    }

    /**
     * @param Node $mmsNode
     * @return bool $success
     */
    public function init(Node $mmsNode)
    {
        $this->node = $mmsNode;
        $this->resetResponseContainsResult();

        return (bool) $this->node;
    }

    /**
     * @param array $headers
     * @return string $initLogCode
     */
    abstract protected function getLogCodePrefix();

    /**
     * @param array $headers
     * @param array $parameters
     * @return FALSE|NULL|resource $this->curlHandle
     */
    protected function initCurl(array $headers, array $parameters)
    {
        if (is_null($this->curlHandle)) {
            $this->curlHandle = curl_init();
        }

        $this->curlOptions = array_replace_recursive(
            $this->baseCurlOptions,
            $this->additionalCurlOptions,
            $this->curlOptions
        );

        $header = array($this->authorisation);
        switch ($this->requestType)
        {
            case Request::METHOD_PATCH:
            case Request::METHOD_POST:
            case Request::METHOD_PUT:
                $post = json_encode($parameters);
                $headers[] = 'Content-Type: application/json';
                $headers[] = 'Content-Length: '.strlen($post);
                break;
            default:
                //
        }

        $this->curlOptions[CURLOPT_HTTPHEADER] = $this->getHeaders(array_unique($headers));

        return $this->curlHandle;
    }

    /**
     * @param array $headers
     * @return bool $success
     */
    public function getHeaders(array $headers)
    {
        $cacheControl = 'Cache-Control: no-cache';

        foreach ($headers as $line) {
            if (strpos(strtolower($line), strtolower(strstr($cacheControl, ':', TRUE)).':') !== FALSE) {
                $cacheControl = FALSE;
                break;
            }
        }
        if ($cacheControl) {
//            $headers[] = $cacheControl;
        }

        return $headers;
    }

    /**
     * @param string $callType
     * @param array $parameters
     * @return string $url
     */
    protected function getUrl($callType, array $parameters = array())
    {
        if (static::TEST_MODE) {
            $url = static::TEST_BASE_URI;
        }else{
            $url = $this->node->getConfig('web_url');
        }

        if (strlen($url) > 0) {
            $url = trim(trim($url, '/').'/'.$callType);
            if (count($parameters) > 0) {
                // TECHNICAL DEBT // ToDo (maybe): include parameters
            }
        }else{
            throw new MagelinkException('No base url defined ('.(static::TEST_MODE ? 'test' : 'production').').');
        }

        return $url;
    }

    /**
     * @return RestV1 $this
     */
    protected function setResponseContainsResult()
    {
        $this->responseContainsResult = TRUE;
        return $this;
    }

    /**
     * @return RestV1 $this
     */
    protected function unsetResponseContainsResult()
    {
        $this->responseContainsResult = FALSE;
        return $this;
    }

    /**
     * @return bool $resetResponseContainsResult
     */
    protected function resetResponseContainsResult()
    {
        return $this->setResponseContainsResult();
    }

    /**
     * @return bool $isResponseContainsResult
     */
    protected function isResponseContainsResult()
    {
        $isResponseContainsResult = $this->responseContainsResult;
        return $isResponseContainsResult;
    }

    /**
     * @param string $callType
     * @return mixed $curlExecResponse
     */
    protected function executeCurl($callType)
    {
        curl_setopt_array($this->curlHandle, $this->curlOptions);

        $logData = array(
            'request type'=>$this->requestType,
            'curl info'=>curl_getinfo($this->curlHandle),
            'curl options'=>$this->curlOptions
        );

        $response = curl_exec($this->curlHandle);
        $error = curl_error($this->curlHandle);
        $this->curlOptions = array();

        $logCode = $this->getLogCodePrefix().'_'.substr(strtolower($this->requestType), 0, 2);
        if ($error) {
            $logCode .= '_cerr';
            $logMessage = $callType.' failed. Curl error: '.$error;
            $logData['curl error'] = $error;
            $this->getServiceLocator()->get('logService')
                ->log(LogService::LEVEL_ERROR, $logCode, $logMessage, $logData);
            $response = NULL;
        }else{
            try {
                $logData['response'] = $response;
                $decodedResponse = Json::decode($response, Json::TYPE_ARRAY);

                try{
                    $responseArray = (array) $decodedResponse;
                    if (isset($responseArray['ErrorResponse'])) {
                        $error = $responseArray['ErrorResponse'];
                        if (is_array($error)) {
                            $error = ' '.implode(' ', $error);
                        }elseif (!is_scalar($error)) {
                            $error = 'Undefined ErrorResponse of type '.gettype($error).'.';
                        }

                        if (isset($responseArray['StatusCode'])) {
                            $error = 'StatusCode '.$responseArray['StatusCode'].'. '.$error;
                        }
                    }
                }catch(\Exception $exception) {
                    $logData['exception'] = $exception->getMessage();
                    $error .= ' '.$exception->getMessage();
                }

                $error = trim(preg_replace('#\s+#', ' ', $error));

                if (strlen($error) == 0 && !isset($responseArray['StatusCode'])) {
                    $error = 'MMS send an invalid reponse on call '.$callType.'.';
                }elseif (strlen($error) == 0 && $responseArray['StatusCode'] != 200) {
                    $error = 'MMS send a '.$responseArray['StatusCode'].' reponse on call '.$callType.'.';
                }

                if (strlen($error) == 0) {
                    $response = $responseArray;
                    $logLevel = LogService::LEVEL_INFO;
                    $logCode .= '_suc';
                    $logMessage = $callType.' succeeded. ';
                }else{
                    $logLevel = LogService::LEVEL_ERROR;
                    $logCode .= '_fail';
                    $logMessage = $callType.' failed. Error message: '.$error;
                    $logData['error'] = $error;
                    $response = NULL;
                }

                $this->getServiceLocator()->get('logService')
                    ->log($logLevel, $logCode, $logMessage, $logData);
            }catch (\Exception $exception) {
                $logCode = $logCode.'_err';
                $logMessage = $callType.' failed. Error during decoding of '.var_export($response, TRUE);
                $this->getServiceLocator()->get('logService')
                    ->log(LogService::LEVEL_ERROR, $logCode, $logMessage, $logData);
                $response = NULL;
            }
        }

        return $response;
    }

    /**
     * @return void
     */
    protected function closeCurl()
    {
        if (!is_null($this->curlHandle)) {
            curl_close($this->curlHandle);
            unset($this->curlHandle);
        }
    }

    /**
     * @return int $timestamp
     */
    protected function getTimestamp()
    {
        $timezeone = date_default_timezone_get();
        date_default_timezone_set('UTC');
        $timestamp = time() - mktime(0, 0, 0, 1, 1, 1970);
        date_default_timezone_set($timezeone);

        return $timestamp;
    }
    /**
     * @return string $guid
     */
    protected function getGuid()
    {
        return uniqid(); // sprintf('%04X%04X%04X%04X%04X%04X%04X%04X', mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(16384, 20479), mt_rand(32768, 49151), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535));
    }

    /**
     * @param string $httpMethod
     * @param string $callType
     * @param array $parameters
     * @return bool (bool) $this->authorisation
     */
    protected function authorise($httpMethod, $callType, array $parameters)
    {
        $appId = $this->node->getConfig('app_id');
        $appKey = $this->node->getConfig('app_key');

        $setCurlOptions = 'set'.ucfirst(strtolower($httpMethod)).'CurlOptions';
        $this->$setCurlOptions($this->getUrl($callType), $parameters);

        $url = strtolower(urlencode($this->curlOptions[CURLOPT_URL]));
        $timestamp = $this->getTimestamp();
        $nonce = $this->getGuid();
        $contentBase64String = ''; // empty when there's no content

        $signatureRawData = $appId.$httpMethod.$url.$timestamp.$nonce.$contentBase64String;
        $signature = utf8_encode($signatureRawData);

        $secretKeyByteArray = base64_decode($appKey);

        $hashByteArray = hash_hmac('sha256', $signature, $secretKeyByteArray, TRUE);
        $signatureBase64String = base64_encode($hashByteArray);

        $this->authorisation = 'mms '.$appId.':'.$signatureBase64String.':'.$nonce.':'.$timestamp;

        return (bool) $this->authorisation;
    }

    /**
     * @param string $method
     * @param string $callType
     * @param array $parameters
     * @return mixed $response
     */
    protected function call($httpMethod, $callType, array $parameters = array())
    {
        $logCode = $this->getLogCodePrefix().'_call';
        $this->requestType = strtoupper($httpMethod);

        if ($this->authorise($httpMethod, $callType, $parameters)) {
            $headers = array(
                'Authorization: '.$this->authorisation,
                'Accept: application/json',
            );
            $this->initCurl($headers, $parameters);

            $logData = array(
                'url'=>$this->curlOptions[CURLOPT_URL],
                'headers'=>$headers,
                'method'=>$this->requestType,
                'curl options'=>$this->curlOptions,
                'parameters'=>$parameters
            );

            $response = $logData['response'] = $this->executeCurl($callType);

            if (is_array($response) && array_key_exists('Result', $response)) {
                $response = $response['Result'];

            }elseif (isset($response['StatusCode']) && $this->isResponseContainsResult()) {
                $this->getServiceLocator()->get('logService')
                    ->log(LogService::LEVEL_ERROR,
                        $logCode.'_err',
                        self::ERROR_PREFIX.'Response does not contain Result key.',
                        $logData
                    );
                throw new GatewayException(self::ERROR_PREFIX.$response['StatusCode']);
                $response = NULL;
            }
        }else{
            $logData = array();
            $response = NULL;
        }

        $logData['response'] = $response;
        $this->getServiceLocator()->get('logService')
            ->log(LogService::LEVEL_DEBUGEXTRA, $logCode, 'REST call '.$callType, $logData);

        if (is_null($response)) {
            $response = array('success'=>FALSE);
        }else{
            $response['success'] = TRUE;
        }
        $this->resetResponseContainsResult();

        return $response;
    }

    /**
     * @param string $callType
     * @return mixed $curlExecReturn
     */
    public function delete($callType)
    {
        $response = $this->call(Request::METHOD_DELETE, $callType);
        return $response;
    }

    /**
     * @param string $callType
     * @param array $parameters
     * @return mixed $curlExecReturn
     */
    public function get($callType, array $parameters = array())
    {
        $response = $this->call(Request::METHOD_GET, $callType, $parameters);

        return $response;
    }

    /**
     * @param string $callType
     * @param array $parameters
     * @return mixed $curlExecReturn
     */
    public function patch($callType, array $parameters = array())
    {
        $response = $this->call(Request::METHOD_PATCH, $callType, $parameters);
        return $response;
    }

    /**
     * @param string $callType
     * @param array $parameters
     * @return mixed $curlExecReturn
     */
    public function post($callType, array $parameters = array())
    {
        $response = $this->call(Request::METHOD_POST, $callType, $parameters);
        return $response;
    }

    /**
     * @param string $callType
     * @param array $parameters
     * @return mixed $curlExecReturn
     */
    public function put($callType, array $parameters = array())
    {
        $response = $this->call(Request::METHOD_PUT, $callType, $parameters);
        return $response;
    }


    /**
     * @param string $baseUrl
     * @param array $urlParameters
     * @return bool $urlWithParameters
     */
    protected function getUrlWithParameter($baseUrl, array $urlParameters)
    {
        $parameters = array();

        if (!is_null($this->requestType)) {
            foreach ($urlParameters as $key=>$value) {
                $escapedKey = urlencode($key);
                $escapedValue = urlencode($value);

                $logCode = $this->getLogCodePrefix().'_url';
                $logData = array(
                    'key'=>$key,
                    'escaped key'=>$escapedKey,
                    'value'=>$value,
                    'escaped value'=>$escapedValue,
                    'fields'=>$urlParameters
                );

                if ($key != $escapedKey) {
                    $logLevel = LogService::LEVEL_ERROR;
                    $logCode .= '_err';
                    $logMessage = $this->requestType.' field key-value pair is not valid.';
                    throw new MagelinkException($logMessage);

                    $parameters = array();
                    break;
                }elseif ($value != $escapedValue) {
                    $logLevel = LogService::LEVEL_WARN;
                    $logCode .= '_esc';
                    $logMessage = $this->requestType.' value had to be escaped.';
                    unset($logData['escaped key']);

                    $value = $escapedValue;
                }else{
                    unset($logLevel);
                }

                $parameters[] = $key.'='.$value;

                if (isset($logLevel)) {
                    $this->getServiceLocator()->get('logService')
                        ->log($logLevel, $logCode, $logMessage, $logData);
                }
            }
        }

        if (count($urlParameters) == 0) {
            $urlWithParameters = $baseUrl;
        }elseif (count($urlParameters) == count($parameters)) {
            $urlWithParameters = $baseUrl.'?'.implode('&', $parameters);
        }else{
            $urlWithParameters = '';
        }

        return $urlWithParameters;
    }

    /**
     * @param string $baseUrl
     * @param array $deletefields
     * @return bool $success
     */
    public function setDeleteCurlOptions($baseUrl, array $deletefields)
    {
        $this->curlOptions = array_replace_recursive($this->curlOptions, array(
            CURLOPT_URL=>$this->getUrlWithParameter($baseUrl, $deletefields)
        ));

        return $this->curlOptions;
    }

    /**
     * @param string $baseUrl
     * @param array $getfields
     * @return bool $success
     */
    public function setGetCurlOptions($baseUrl, array $getfields)
    {
        $this->curlOptions = array_replace_recursive($this->curlOptions, array(
            CURLOPT_URL=>$this->getUrlWithParameter($baseUrl, $getfields),
            CURLOPT_CUSTOMREQUEST=>Request::METHOD_GET
        ));

        return $this->curlOptions;
    }

    /**
     * @param string $baseUrl
     * @param array $patchfields
     * @return bool $success
     */
    protected function setPatchCurlOptions($baseUrl, array $patchfields)
    {
        $patchfields = Json::encode($patchfields);
        $this->curlOptions = array_replace_recursive($this->curlOptions, array(
            CURLOPT_URL=>$baseUrl,
            CURLOPT_CUSTOMREQUEST=>Request::METHOD_PATCH,
            CURLOPT_POSTFIELDS=>$patchfields
        ));

        return $this->curlOptions;
    }

    /**
     * @param string $baseUrl
     * @param array $postfields
     * @return bool $success
     */
    protected function setPostCurlOptions($baseUrl, array $postfields)
    {
        $postfields = Json::encode($postfields);
        $this->curlOptions = array_replace_recursive($this->curlOptions, array(
            CURLOPT_URL=>$baseUrl,
            CURLOPT_POST=>1,
            CURLOPT_POSTFIELDS=>$postfields
        ));

        return $this->curlOptions;
    }

    /**
     * @param string $baseUrl
     * @param array $putfields
     * @return bool $success
     */
    protected function setPutCurlOptions($baseUrl, array $putfields)
    {
        $putfields = Json::encode($putfields);
        $this->curlOptions = array_replace_recursive($this->curlOptions, array(
            CURLOPT_URL=>$baseUrl,
            CURLOPT_PUT=>1,
            CURLOPT_POSTFIELDS=>$putfields
        ));

        return $this->curlOptions;
    }

    /**
     * @return EntityService $this->entityService
     */
    protected function getEntityService()
    {
        if (is_null($this->entityService)) {
            $this->entityService = $this->getServiceLocator()->get('entityService');
        }

        return $this->entityService;
    }

    /**
     * @param Entity $entity
     * @return int $localEntityId
     */
    protected function getLocalId(Entity $entity)
    {
        /** @var \Entity\Service\EntityService $entityService */
        $entityService = $this->getEntityService();
        $localEntityId = $entityService->getLocalId($this->node->getNodeId(), $entity);

        return $localEntityId;
    }

}
