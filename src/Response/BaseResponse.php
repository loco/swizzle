<?php

namespace Loco\Utils\Swizzle\Response;

use Guzzle\Http\Message\Response;
use Guzzle\Service\Command\OperationCommand;
use Guzzle\Service\Command\ResponseClassInterface;
use GuzzleHttp\Command\Result;

/**
 * Base response class for Swagger docs resources
 */
abstract class BaseResponse extends Result
{
    /**
     * Test if key was found in original JSON, even if empty
     *
     * @param string
     *
     * @return bool
     */
    protected function has($key)
    {
        return isset($this->data[$key]) || array_key_exists($key, $this->data);
    }

    /**
     * Get raw data value
     *
     * @param $key
     *
     * @return mixed
     */
    protected function get($key)
    {
        return isset($this->data[$key]) ? $this->data[$key] : null;
    }

    /**
     * Get declared API version number
     *
     * @return string
     */
    public function getApiVersion()
    {
        return $this->get('apiVersion') ?: '';
    }

    /**
     * Get declared Swagger spec version number
     *
     * @return string
     */
    public function getSwaggerVersion()
    {
        return $this->get('swaggerVersion') ?: '1.2';
    }

    /**
     * Test if Swagger spec version number is declared.
     *
     * @return bool
     */
    public function isSwagger()
    {
        return $this->has('swaggerVersion');
    }

    /**
     * Get all path strings in objects under apis
     *
     * @return array
     */
    public function getApiPaths()
    {
        $paths = [];
        if ($apis = $this->get('apis')) {
            foreach ($apis as $api) {
                if (isset($api['path'])) {
                    $paths[] = $api['path'];
                }
            }
        }
        return $paths;
    }

    /**
     * Get api definitions
     * @return array
     */
    public function getApis()
    {
        return $this->get('apis') ?: [];
    }

}


