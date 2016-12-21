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
    /** @var Client $this->client */
    protected $client;
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
    /** @var array $this->clientOptions */
    protected $clientOptions = array(
        'adapter'=>'Zend\Http\Client\Adapter\Curl',
        'curloptions'=>array(CURLOPT_FOLLOWLOCATION=>TRUE),
        'maxredirects'=>0,
        'timeout'=>30
    );


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
        return (bool) $this->node;
    }

    /**
     * @param array $headers
     * @return string $initLogCode
     */
    abstract protected function getLogCodePrefix();

    /**
     * @return FALSE|NULL|resource $this->curlHandle
     */
    protected function initCurl($httpMethod, array $headers, $parameters)
    {
        if (is_null($this->curlHandle)) {
            $this->curlHandle = curl_init();
        }

        $this->curlOptions = array_replace_recursive(
            $this->baseCurlOptions,
            $this->additionalCurlOptions
        );

        $header = array($this->authorisation);
        switch ($httpMethod)
        {
            case Request::METHOD_POST:
                unset($this->curlOptions[CURLOPT_CUSTOMREQUEST]);
                $this->curlOptions[CURLOPT_POST] = 1;

            case Request::METHOD_PATCH:
            case Request::METHOD_PUT:
                $post = json_encode($parameters);
                $this->curlOptions[CURLOPT_POSTFIELDS] = $post;
                $header[] = 'Content-Type: application/json';
                $header[] = 'Content-Length: '.strlen($post);
                break;

            default:
                //
        }

        $this->curlOptions[CURLOPT_HTTPHEADER] = $this->getHeaders($headers);

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
            $headers[] = $cacheControl;
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
            $url = trim($url, '/').'/'.$callType;
            if (count($parameters) > 0) {
                // TECHNICAL DEBT // ToDo (maybe): include parameters
            }
        }else{
            throw new MagelinkException('No base url defined ('.(static::TEST_MODE ? 'test' : 'production').').');
        }

        return $url;
    }

    /**
     * @param string $callType
     * @return mixed $curlExecResponse
     */
    protected function executeCurl($callType)
    {
        $logData = array(
            'request type'=>$this->requestType,
            'curl info'=>curl_getinfo($this->curlHandle)
        );

        curl_setopt_array($this->curlHandle, $this->curlOptions);
        unset($this->curlOptions);

        $response = curl_exec($this->curlHandle);
        $error = $this->getError();

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

                $errors = array();
                $errorKeys = array('message', 'parameters', 'trace');

                try{
                    $responseArray = (array) $decodedResponse;
                    foreach ($errorKeys as $key) {

                        if (isset($responseArray[$key])) {
                            if (is_string($responseArray[$key])) {
                                $errors[] = $responseArray[$key];
                            }else{
                                $errors[] = var_export($responseArray[$key], TRUE);
                            }
                        }
                    }
                }catch(\Exception $exception) {
                    $logData['exception'] = $exception->getMessage();
                    foreach ($errorKeys as $key) {
                        if (isset($decodedResponse->$key)) {
                            $errors[] = $decodedResponse->$key;
                        }
                    }
                }

                $error = implode(' ', $errors);
                if (strlen($error) == 0 && current($responseArray) != trim($response, '"')) {
                    $error = 'This does not seem to be a valid '.$callType.' key: '.$response;
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
     * @return bool $this->authorisation
     */
    protected function authorise($httpMethod, $callType, $parameters)
    {
        if (is_null($this->authorisation)) {
            $appId = $this->node->getConfig('app_id');
            $appKey = $this->node->getConfig('app_key');

            $url = strtolower(urlencode($this->getUrl($callType, $parameters)));
            $timestamp = $this->getTimestamp();
            $nonce = $this->getGuid();
            $contentBase64String = ''; // empty when there's no content

            $signatureRawData = $appId.$httpMethod.$url.$timestamp.$nonce.$contentBase64String;
            $signature = utf8_encode($signatureRawData);

            $secretKeyByteArray = base64_decode($appKey);

            $hashByteArray = hash_hmac('sha256', $signature, $secretKeyByteArray, TRUE);
            $signatureBase64String = base64_encode($hashByteArray);

            $this->authorisation = 'Authorization: mms '.$appId.':'.$signatureBase64String.':'.$nonce.':'.$timestamp;

            $header = array($this->authorisation);
            switch ($httpMethod)
            {
                case Request::METHOD_POST:
                case Request::METHOD_PATCH:
                case Request::METHOD_PUT:
                    $header[] = 'Content-Type: application/json';
                    $post = json_encode($parameters);
                    $header[] = 'Content-Length: '.strlen($post);
                    break;
                default:
                    //
            }
            $this->initCurl($httpMethod, $header, $parameters);
        }

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

        if ($this->authorise($httpMethod, $callType, $parameters)) {
            $this->requestType = strtoupper($httpMethod);
            $setRequestDataMethod = 'set'.ucfirst(strtolower($httpMethod)).'fields';

            $uri = $this->getUrl($callType);
            $headersArray = array(
                'Authorization'=>$this->authorisation,
                'Accept'=>'application/json',
                'Content-Type'=>'application/json'
            );

            $headers = new Headers();
            $headers->addHeaders($headersArray);

            $this->request = new Request();
            $this->request->setHeaders($headers);
            $this->request->setUri($uri);
            $this->request->setMethod($this->requestType);

            $this->client = new Client();
            $this->client->setOptions($this->clientOptions);

            $this->$setRequestDataMethod($parameters);
            $response = $this->client->send($this->request);

            if (!is_array($response)) {
                $responseBody = $response->getBody();
                $response = Json::decode($responseBody, Json::TYPE_ARRAY);
            }

            $logData = array('uri'=>$uri, 'headers'=>$headersArray, 'method'=>$this->requestType,
                'options'=>$this->clientOptions, 'parameters'=>$parameters, 'response'=>$response);

            if (is_array($response) && array_key_exists('items', $response)) {
                $response = $response['items'];

            }elseif (isset($response['message'])) {
                if (isset($response['parameters']) && is_array($response['parameters'])) {
                    foreach ($response['parameters'] as $key=>$replace) {
                        $search = '"%'.(++$key).'"';
                        $replace = '`'.$replace.'`';
                        $response['message'] = str_replace($search, $replace, $response['message']);
                    }
                }
                $this->getServiceLocator()->get('logService')
                    ->log(LogService::LEVEL_ERROR, $logCode.'_err', self::ERROR_PREFIX.$response['message'], $logData);
                throw new GatewayException(self::ERROR_PREFIX.$response['message']);
            }
        }else{
            $logData = array();
            $response = NULL;
        }

        $logData['response'] = $response;
        $this->getServiceLocator()->get('logService')
            ->log(LogService::LEVEL_DEBUGEXTRA, $logCode, 'REST call '.$callType, $logData);

        if (isset($response['StatusCode']) && $response['StatusCode'] == 200) {
            $response['success'] = TRUE;
        }else{
            $response['success'] = FALSE;
        }

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
     * @param string $urlParameters
     * @return bool $success
     */
    protected function setUrlParameters($urlParameters)
    {
        $success = FALSE;

        if (!is_null($this->requestType)) {
            if (isset($urlParameters['filter']) && is_array($urlParameters['filter'])) {
                $parameters = array();

                foreach ($urlParameters['filter'] as $filterKey=>$filter) {
                    foreach ($filter as $key=>$value) {
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

                        $parameters['searchCriteria']['filterGroups'][0]['filters'][$filterKey][$key] = $value;

                        if (isset($logLevel)) {
                            $this->getServiceLocator()->get('logService')
                                ->log($logLevel, $logCode, $logMessage, $logData);
                        }
                    }

                    if (count($urlParameters) != 0) {
                        $success = TRUE;
                        $parameterObject = new Parameters($parameters);
                        $success = $this->request->setQuery($parameterObject);
                    }else{

                    }
                }
            }else{
                $success = TRUE;
            }
        }

        return (bool) $success;
    }

    /**
     * @param string $postfields
     * @return bool $success
     */
    public function setDeletefields($deletefields)
    {
        return $this->setUrlParameters($deletefields);
    }

    /**
     * @param array $getfields
     * @return bool $success
     */
    public function setGetfields(array $getfields)
    {
        return $this->setUrlParameters($getfields);
    }

    /**
     * @param array $postfields
     * @return bool $success
     */
    protected function setPostfields(array $postfields)
    {
        $postContent = Json::encode($postfields);
        return $this->request->setContent($postContent);
    }

    /**
     * @param array $putfields
     * @return bool $success
     */
    protected function setPutfields(array $putfields)
    {
        $putContent = Json::encode($putfields);
        return $this->request->setContent($putContent);
    }

    /**
     * @return string $curlError
     */
    public function getError()
    {
        return curl_error($this->curlHandle);
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
        $localEntityId = $entityService->getLocalId($this->node, $entity);

        return $localEntityId;
    }

}
