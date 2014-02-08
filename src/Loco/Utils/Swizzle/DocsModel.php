<?php

namespace Loco\Utils\Swizzle;

use Guzzle\Service\Description\ServiceDescription;
use Guzzle\Service\Description\Operation;
use Guzzle\Service\Description\Parameter;

use Loco\Utils\Swizzle\Response\BaseResponse;
use Loco\Utils\Swizzle\Response\ResourceListing;
use Loco\Utils\Swizzle\Response\ApiDeclaration;


/**
 * Models Swagger API declarations and converts to Guzzle service descriptions.
 */
class DocsModel {
    
    /**
     * Expected swagger spec version
     * @var string
     */
    const SWAGGER_VERSION = '1.2';     
    
    /**
     * Initial parameters to pass to ServiceDescription constructor
     * @var array
     */    
    private $init;     
    
    /**
     * @var ServiceDescription
     */
    private $service;    
    
    /**
     * Registry of custom classes, mapped by method command name
     * @var array
     */    
    private $responses = array();
    
    
    /**
     * Construct with minimum mandatory parameters
     */
    public function __construct( $name, $description = '', $apiVersion = '' ){
        $this->init = compact('name','description','apiVersion');
    }    
    
    
    /**
     * Set an initial value to be passed to ServiceDescription constructor.
     * @return DocsModel
     */
    private function setInitValue( $key, $value ){
        if( $this->service ){
            throw new \Exception('Too late to set "'.$key.'"');
        }
        $this->init[$key] = $value;
        return $this;
    }    
    
    
    /**
     * Set apiVersion
     * @return DocsModel
     */
    public function setApiVersion( $apiVersion ){
        return $this->setInitValue( 'apiVersion', $apiVersion );
    }
     
    
    /**
     * Get compiled Guzzle service description
     * @return ServiceDescription
     */
    public function getDescription(){
        if( ! $this->service ){
            $this->service = new ServiceDescription( $this->init );
        }
        return $this->service;
    }
    
    
    
    /**
     * Apply a bespoke responseClass to a given method
     * @return DocsModel
     */
    public function registerResponseClass( $name, $class ){
        $this->responses[$name] = $class;
        // set retrospectively if method already encountered
        if( $this->service ){
            $op = $this->service->getOperation($name) and
            $op->setResponseClass( $class );
        }
        return $this;
    }    
    
    
    
    /**
     * Build from a live endpoint
     * @param string Swagger compliant JSON endpoint for resource listing
     */
    public function build( $base_url ){
        $this->service = null;
        $client = DocsClient::factory( compact('base_url') );
        /* @var $listing ResourceListing */
        $listing = $client->getResources();
        // check this looks like a resource listing
        if( ! $listing->isSwagger() ){
            throw new \Exception("This doesn't look like a Swagger spec");
        }
        // check swagger version
        if( self::SWAGGER_VERSION !== $listing->getSwaggerVersion() ){
            throw new \Exception( 'Unsupported Swagger version, Swizzle expects '.self::SWAGGER_VERSION );
        }
        // Declared version overrides anything we've set
        if( $version = $listing->getApiVersion() ){
            $this->setApiVersion( $version );
        }
        // Set description if missing from constructor
        if( ! $this->init['description'] ){
            $info = $listing->getInfo();
            $this->init['description'] = $info['description']?:$this->init['title'];
        }
        // no more configs allowed now, Guzzle service gets constructed
        $service = $this->getDescription();
        // ready to pull each api declaration
        foreach( $listing->getApiPaths() as $path ){
            // @todo do proper path resolution, allowing a cross-domain spec.
            printf(" pulling %s ...\n", $path );
            usleep( 250000 );
            $declaration = $client->getDeclaration( compact('path') );
            foreach ( $declaration->getModels() as $model ) {
                printf(" + adding model %s ...\n", $model['id'] );
                $this->addModel( $model );
            }
            $url = $declaration->getBasePath();
            foreach( $declaration->getApis() as $api ){
                printf(" + adding api %s%s ...\n", $url, $api['path'] );
                $this->addApi( $api, $url );
            }
        }
    }
    
    
    
    
    /**
     * Add a Swagger model definition
     * @return DocsModel
     */
    public function addModel( array $model ){
        static $common = array(
            'description' => '',
        );
        static $trans = array(
            'id' => 'name',
        );
        $data = $this->transformArray( $model, $common, $trans );
        // @todo is type always "object" in json with no additional props?
        $data['type'] = 'object';
        $data['additionalProperties'] = false;
        // properties
        if( isset($model['properties']) ){
            static $defaults = array( 'location' => 'json' );
            $data['properties'] = $this->transformParams( $model['properties'], $defaults );
            // required params are an external array
            if( isset($model['required']) ){
                foreach( $model['required'] as $prop ){
                    if( isset($data['properties'][$prop]) ){
                        $data['properties'][$prop]['required'] = true;
                    }
                }
            }
        }
        else {
            $data['properties'] = array();
        }
        $service = $this->getDescription();
        $service->addModel( new Parameter($data) );
        return $this;
    }   
     
    
    
    /**
     * Add a Swagger Api declaration which may consist of multiple operations
     * @param array consisting of path, description and array of operations
     * @return DocsModel
     */    
    public function addApi( array $api, $basePath = '' ){
        $service = $this->getDescription();
        if( $basePath ){
            $basePath = parse_url( $basePath, PHP_URL_PATH );
        }
        // path is common to all swagger operations and specified relative to basePath
        // @todo proper uri merge
        $path = implode( '/', array( rtrim($basePath,'/'), ltrim($api['path'],'/') ) );
        // operation keys common to both swagger and guzzle
        static $common = array (
            'summary' => '',
        );
        // translate swagger -> guzzle 
        static $trans = array (
            'method' => 'httpMethod',
            'type' => 'responseType',
            'notes' => 'responseNotes',
        );
        foreach( $api['operations'] as $op ){
            $config = $this->transformArray( $op, $common, $trans );
            $config['uri'] = $path;
            // command must have a name, and must be unique across methods
            if( isset($op['nickname']) ){
                $id = $config['name'] = $op['nickname'];
            }
            // generate naff nickname if not specified
            else {
                $method = isset($op['method']) ? $op['method'] : 'GET';
                $id = $config['name'] = $method.'_'.str_replace('/','_',trim($path,'/') );
            }
            // allow response class override
            if( isset($this->responses[$id]) ){
                $config['responseType'] = 'class';
                $config['responseClass'] = $this->responses[$id];
            }
            // handle non-primative response types
            else if( isset($config['responseType']) ){
                $type = $config['responseType'];
                // set to primatives
                static $primatives = array( 'string' => 'string', 'array' => 'array' );
                if( isset($primatives[$type]) ){
                    $config['responseType'] = 'primitive';
                    $config['responseClass'] = $primatives[$type];
                }
                // set to model if model matches
                else if( $service->getModel($type) ){
                    $config['responseType'] = 'model';
                    $config['responseClass'] = $type;
                }
            }
            // handle parameters
            if( isset($op['parameters']) ){
                $config['parameters'] = $this->transformParams( $op['parameters'] );
            }
            else {
                $config['parameters'] = array();
            }
            // @todo how to deny additional parameters in command calls?
            // $config['additionalParameters'] = false;
            // add operation
            $operation = new Operation( $config, $service );
            $service->addOperation( $operation );
        }
        return $this;
    }



    /**
     * Map a swagger parameter to a Guzzle one
     */
    private function transformParams( array $params, array $defaults = array() ){
        // param keys common to both swagger and guzzle
        static $common = array (
            'type' => '',
            'required' => '',
            'description' => '',
        );
        // translate swagger -> guzzle 
        static $trans = array (
            'paramType' => 'location',
            'defaultValue' => 'default',
        );
        $target = array();
        foreach( $params as $name => $_param ){
            if( isset($_param['name']) ){    
                $name = $_param['name'];
            }
            $param = $this->transformArray( $_param, $common, $trans );
            // location differences 
            if( isset($param['location']) && 'path' === $param['location'] ){
                $param['location'] = 'uri';
                // swagger doesn't allow optional path params
                if( ! isset($param['required']) ){
                    $param['required'] = true;
                }
            }
            $target[$name] = $param + $defaults;
        }        
        return $target;
    }



    /**
     * Utility transform an array based on similarities and differences between the two formats.
     * @param arrray source format (swagger)
     * @param array keys common to both formats, { key: '', ... }
     * @param array key translation mappings, { keya: keyb, ... }
     * @return array target format (guzzle)
     */
    private function transformArray( array $swagger, array $common, array $trans ){
        // initialize with common array keys
        $guzzle = array_intersect_key( $swagger, $common );
        // translate other naming differences
        foreach( $trans as $source => $target ){
            if( isset($swagger[$source]) ){
                $guzzle[$target] = $swagger[$source];
            }
        }
        return $guzzle;
    }
    
    
    
    /**
     * Export service description to JSON
     * @return string
     */
    public function toJson(){
        $options = 0;
        if( defined('JSON_PRETTY_PRINT') ){
            $options |= JSON_PRETTY_PRINT; // <- PHP>=5.4.0
        }
        $service = $this->getDescription();
        return json_encode( $service->toArray(), $options );
    }    



    /**
     * Export service description to PHP array
     * @return string
     */
    public function export(){
        $service = $this->getDescription();
        return var_export( $service->toArray(), 1 ); 
    }    
    
}



