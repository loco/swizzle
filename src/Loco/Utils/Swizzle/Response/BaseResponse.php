<?php
namespace Loco\Utils\Swizzle\Response;

use Guzzle\Service\Command\ResponseClassInterface;
use Guzzle\Service\Command\OperationCommand;
use Guzzle\Http\Message\Response;


/**
 * Base response class for Swagger docs resources
 */
abstract class BaseResponse implements ResponseClassInterface {
    
    /**
     * Raw response data
     * @var array
     */
    protected $raw;

    /**
     * Construct from http response
     * @internal
     */
    final protected function __construct( Response $response ) {
        $this->raw = $response->json();
    }
    
    
    /**
     * Test if key was found in original JSON, even if empty
     * @internal 
     * @param string
     * @return bool
     */
    protected function has( $key ){
        return isset($this->raw[$key]) || array_key_exists( $key, $this->raw );
    }
    
    
    /**
     * Get raw data value
     * @internal 
     * @return mixed
     */
    protected function get($key){
        return isset($this->raw[$key]) ? $this->raw[$key] : null;
    }    
    

    
    /**
     * Get declared API version number
     * @return string
     */
    public function getApiVersion(){
        return $this->get('apiVersion')?:'';
    }    
    

    
    /**
     * Get declared Swagger spec version number
     * @return string 
     */
    public function getSwaggerVersion(){
        return $this->get('swaggerVersion')?:'1.2';
    }    


    /**
     * Test if Swagger spec version number is declared. 
     * @return bool
     */
    public function isSwagger(){
        return $this->has('swaggerVersion');
    }    
    

    
    /**
     * Get all path strings in objects under apis:
     * @return array
     */   
    public function getApiPaths(){
        $paths = array();
        if( $apis = $this->get('apis') ){
            foreach( (array) $apis as $api ){
                if( isset($api['path']) ){
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
    public function getApis(){
        return $this->get('apis')?:array();
    }

    
}


