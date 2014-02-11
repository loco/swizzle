<?php

namespace Loco\Utils\Swizzle;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;

use Guzzle\Service\Description\ServiceDescription;
use Guzzle\Service\Description\Operation;
use Guzzle\Service\Description\Parameter;

use Loco\Utils\Swizzle\Response\BaseResponse;
use Loco\Utils\Swizzle\Response\ResourceListing;
use Loco\Utils\Swizzle\Response\ApiDeclaration;


/**
 * Models Swagger API declarations and converts to Guzzle service descriptions.
 */
class Swizzle {
    
    /**
     * Monolog logger for debug output
     * @var Logger
     */
    private $logger;     
    
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
     * Delay between HTTP requests in microseconds
     * @var int
     */    
    private $delay = 200000;   
    
    /**
     * Construct with minimum mandatory parameters
     * @param string Name of the API
     * @param string Summary of the API
     * @param string API version
     */
    public function __construct( $name, $description = '', $apiVersion = '' ){
        $this->init = compact('name','description','apiVersion');
        $this->logger = new Logger('swizzle');
        // if we don't add a handler we get debug messages by default.
        $this->logger->pushHandler( new StreamHandler('php://stderr', Logger::ERROR ) );
    }
    
 
    /**
     * Enable debug logging to show build progress
     * @param string|resource
     * @return Swizzle
     */
    public function verbose( $resource ){
        $this->logger->pushHandler( new StreamHandler( $resource, Logger::DEBUG ) );
        return $this;
    }
    
    
    /**
     * @internal Log debug events in verbose mode
     */
    private function debug( $message ){
        if( 1 < func_num_args() ){
            $message = call_user_func_array( 'sprintf', func_get_args() );
        }
        $this->logger->addDebug( $message );
    }        
    
    
    /**
     * Set delay between HTTP requests
     * @param int delay in microseconds
     * @return Swizzle
     */ 
    public function setDelay( $microseconds ){
        $this->delay = (int) $microseconds;
        return $this;
    }
    
    
    /**
     * Set an initial value to be passed to ServiceDescription constructor.
     * @return Swizzle
     */
    private function setInitValue( $key, $value ){
        if( $this->service ){
            throw new \Exception('Too late to set "'.$key.'"');
        }
        $this->init[$key] = $value;
        return $this;
    }    
    
    
    /**
     * Set API version string
     * @param string api version
     * @return Swizzle
     */
    public function setApiVersion( $apiVersion ){
        return $this->setInitValue( 'apiVersion', $apiVersion );
    }
     
    
    /**
     * Get compiled Guzzle service description
     * @return ServiceDescription
     */
    public function getServiceDescription(){
        if( ! $this->service ){
            $this->service = new ServiceDescription( $this->init );
        }
        return $this->service;
    }
    
    
    
    /**
     * Apply a bespoke responseClass to a given method
     * @param string name of command returning this response class
     * @param string full class name for responseClass field
     * @return Swizzle
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
     * @throws \Exception
     * @return Swizzle
     */
    public function build( $base_url ){
        $this->service = null;
        $client = SwaggerClient::factory( compact('base_url') );
        $this->debug('pulling resource listing from %s', $base_url );
        /* @var $listing ResourceListing */
        $listing = $client->getResources();
        // check this looks like a resource listing
        if( ! $listing->isSwagger() ){
            throw new \Exception("This doesn't look like a Swagger spec");
        }
        if( ! $listing->getApis() ){
            $this->logger->addAlert( "Resource listing doesn't define any APIs" );
        }
        // check swagger version
        if( self::SWAGGER_VERSION !== $listing->getSwaggerVersion() ){
            throw new \Exception( 'Unsupported Swagger version, Swizzle expects '.self::SWAGGER_VERSION );
        }
        // Declared version overrides anything we've set
        if( $version = $listing->getApiVersion() ){
            $this->debug(' + set apiVersion %s', $version );
            $this->setApiVersion( $version );
        }
        // Set description if missing from constructor
        if( ! $this->init['description'] ){
            $info = $listing->getInfo();
            $this->init['description'] = $info['description']?:$this->init['title'];
        }
        // no more configs allowed now, Guzzle service gets constructed
        $service = $this->getServiceDescription();
        // ready to pull each api declaration
        foreach( $listing->getApiPaths() as $path ){
            if( $this->delay ){
                usleep( $this->delay );
            }
            // @todo do proper path resolution here, allowing a cross-domain spec.
            $this->debug(' pulling %s ...', $path );
            $declaration = $client->getDeclaration( compact('path') );
            foreach ( $declaration->getModels() as $model ) {
                $this->addModel( $model );
            }
            $url = $declaration->getBasePath();
            foreach( $declaration->getApis() as $api ){
                $this->addApi( $api, $url );
            }
        }
        $this->debug('finished');
        return $this;
    }
    
    
    
    
    /**
     * Add a Swagger model definition
     * @param array model structure from Swagger
     * @return Parameter model added
     */
    public function addModel( array $model ){
        $name = isset($model['id']) ? $model['id'] : '';
        if( $name ){
            $this->debug(' + adding model %s ...', $name );
        }
        else {
            $name = 'anon_'.self::hashArray($model);
            $this->debug( ' + adding anonymous model: %s ...', $name );
        }
        $defaults = array (
            'name' => $name,
            'type' => 'object',
        );
        // a model is basically a parameter, but has name property added
        $data = $this->transformType( $model ) + $defaults;
        if( 'object' === $data['type'] ){
            $data['additionalProperties'] = false;
        }
        // required makes no sense at root of model
        unset( $data['required'] );
        // ok to add model
        $service = $this->getServiceDescription();
        $model = new Parameter( $data, $service );
        $service->addModel( $model );
        return $model;
    }   
     
    
    
    /**
     * Add a Swagger Api declaration which may consist of multiple operations
     * @param array consisting of path, description and array of operations
     * @param string URL from basePath field inferring the full location of each api path
     * @return Swizzle
     */    
    public function addApi( array $api, $basePath = '' ){
        $service = $this->getServiceDescription();
        if( $basePath ){
            $basePath = parse_url( $basePath, PHP_URL_PATH );
        }
        $this->debug(' + adding api %s%s ...', $basePath, $api['path'] );
        // path is common to all swagger operations and specified relative to basePath
        // @todo proper uri merge
        $path = implode( '/', array( rtrim($basePath,'/'), ltrim($api['path'],'/') ) );
        // operation keys common to both swagger and guzzle
        static $common = array (
            'items' => 1,
            'summary' => 1,
        );
        // translate swagger -> guzzle 
        static $trans = array (
            'type' => 'responseType',
            'notes' => 'responseNotes',
            'method' => 'httpMethod',
        );
        static $defaults = array (
            'httpMethod' => 'GET',
        );
        foreach( $api['operations'] as $op ){
            $config = $this->transformArray( $op, $common, $trans ) + $defaults;
            $config['uri'] = $path;
            // command must have a name, and must be unique across methods
            if( isset($op['nickname']) ){
                $id = $config['name'] = $op['nickname'];
            }
            // generate naff nickname if not specified
            else {
                $method = strtolower( $config['httpMethod'] );
                $id = $config['name'] = $method.'_'.str_replace('/','_',trim($path,'/') );
            }
            // allow registered response class to override all
            if( isset($this->responses[$id]) ){
                $config['responseType'] = 'class';
                $config['responseClass'] = $this->responses[$id];
            }
            // handle response type if defined
            else if( isset($config['responseType']) ){
                $data = $this->transformType( $op );
                $type = $data['type'];
                // typed array responses require a model wrapper - $ref already validated
                if( 'array' === $type && isset($data['items']) ){
                    $ref = $data['items']['$ref'];
                    $type = $ref.'_array';
                    if( ! $service->getModel($type) ){
                        $model = array(
                            'id' => $type,
                            'type' => 'array',
                            'description' => 'Array of "'.$ref.'" objects',
                            'items' => array (
                                '$ref' => $ref,
                            ),
                        );
                        $this->addModel( $model );
                    }
                } 
                // Ensure service contructor calls inferResponseType by having class but no type
                // This will handle Guzzle primatives, models and fall back to class
                $config['responseClass'] = $type;
                unset( $config['responseType'] );
            }
            // handle parameters
            if( isset($op['parameters']) ){
                $config['parameters'] = $this->transformParams( $op['parameters'] );
            }
            else {
                $config['parameters'] = array();
            }
            // handle responseMessages -> errorResponses
            if( isset($op['responseMessages']) ){
                $config['errorResponses'] = $this->transformResponseMessages($op['responseMessages']);
            }
            else {
                $config['errorResponses'] = array();
            }
            // @todo how to deny additional parameters in command calls?
            // $config['additionalParameters'] = false;
            $operation = new Operation( $config, $service );
            // Sanitize custom response class because Guzzle doesn't know it doesn't exist yet
            if( Operation::TYPE_CLASS === $operation->getResponseType() ){
                $class = $operation->getResponseClass();
                if( class_exists($class) ){
                    // assume native PHP class, such as \DateTime.
                }
                else if( empty($this->responses[$id]) || $class !== $this->responses[$id] ){
                    throw new \Exception('responseType defaulted to class "'.$class.'" but class not registered');
                }
            }
            $service->addOperation( $operation );
            // next operation -
        }
        return $this;
    }



    /**
     * Transform a swagger parameter to a Guzzle one
     */
    private function transformParams( array $params, array $defaults = array() ){
        $target = array();
        foreach( $params as $name => $_param ){
            if( isset($_param['name']) ){    
                $name = $_param['name'];
            }
            $param = $this->transformType( $_param );
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
     * Transform an object holding a Swagger data type into a Guzzle one
     * @param array Swagger schema
     * @return array Guzzle schema
     */
    private function transformType( array $source ){
        // keys common to both swagger and guzzle
        static $common = array (
            'type' => 1,
            'enum' => 1,
            'items' => 1,
            'required' => 1,
            'description' => 1,
        );
        static $trans = array (
            'paramType' => 'location',
            'defaultValue' => 'default',
        );
        // initial translation
        $target = $this->transformArray( $source, $common, $trans );
        
        // transform type if defined
        if( isset($target['type']) ){
            $format = isset($source['format']) ? $source['format'] : '';
            $type = $target['type'] = $this->transformPrimative( $target['type'], $format );
        }
        // else fall back to most likely intention
        else if( isset($source['properties']) ){
            $type = $target['type'] = 'object';
        }
        else {
            $type = $target['type'] = 'string';
        }

        // handle array of types entities
        if( isset($target['items']) ){
            $type = $target['type'] = 'array';
            // resolve model reference ensuring model exists
            // @todo should $ref be allowed to resolve to a registered class?
            if( isset($target['items']['$ref']) ){
                $ref = $target['items']['$ref'];
                if( ! $this->getServiceDescription()->getModel($ref) ){
                    throw new \Exception('"'.$ref.'" encountered as items $ref but not defined as a model');
                }
            }
            // Else define a literal model definition on the fly. 
            // Guzzle will resolve back to literals on output, but it helps us resolve typed arrays and such
            else {
                //$target['items'] = $this->transformType( $target['items'] );
                $model = $this->addModel( $target['items'] );
                $target['items'] = array(
                    '$ref' => $model->getName(),
                );
            }
        }

        // handle object properties
        if( isset($source['properties']) ){
            $target['properties'] = $this->transformParams( $source['properties'] );
            // required params are an external array in Swagger, but applied individually as boolean in Guzzle
            if( isset($source['required']) ){
                foreach( $source['required'] as $prop ){
                    if( isset($target['properties'][$prop]) ){
                        $target['properties'][$prop]['required'] = true;
                    }
                }
            }
        }
        else if( 'object' === $type ) {
            $target['properties'] = array();
        }
        return $target;
    }
    
    
    
    /**
     * Transform various primative aliases that Swagger uses
     * @see https://github.com/wordnik/swagger-core/wiki/Datatypes
     * @param string swagger primative as per JSON-Schema Draft 4. [integer|number|string|boolean]
     * @param string swagger disambiguator, e.g. date when string is a date
     * @return string Guzzle primative
     */
    private function transformPrimative( $type, $format = '' ){
        static $aliases = array (
            // empties
            'void' => '',
            'null' => '',
            // integers
            'integer' => 'integer',
            'int32'   => 'integer',
            'int64'   => 'integer',
            // floats
            'number' => 'number',
            'double' => 'number',
            'float'  => 'number',
            // dates
            'date'      => 'date',
            'dateTime'  => 'date',
            'date-time' => 'date',
        );        
        if( $format ){
            $format = isset($aliases[$format]) ? $aliases[$format] : $format;
            if( 'date' === $format ){
                return '\\DateTime'; // <- ?
            }
        }
        $type = isset($aliases[$type]) ? $aliases[$type] : $type;
        // @todo how to handle floats?
        if( 'number' === $type ){
            return 'string'; // <- ?
        }
        // Swagger permits "void" as a responseType
        // Guzzle has no real notion of an empty response and defaults to array
        // That means at least a json string containing "[]" which isn't true of an empty response
        if( ! $type ){
            return 'string';
        }
        return $type;
    }    



    /**
     * Transform Swagger responseMessages to Guzzle errorResponses.
     * @todo support registration of 'class' property?
     * @param array containing code and message
     * @return array containing code and phrase
     */
    private function transformResponseMessages( array $responseMessages ){
        static $common = array (
            'code' => 1,
        ),
        $trans = array (
            'message' => 'phrase',
        );
        $errorResponses = array();
        foreach( $responseMessages as $message ){
            $errorResponses[] = $this->transformArray( $message, $common, $trans );
        }
        return $errorResponses;
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
     * Utility, hashes an array into something human readable if less than 32 chars.
     * Example: Use for creating anonymous model names, such as type_string
     */
    private static function hashArray( array $arr, array $words = array(), $recursion = false ){
        foreach( $arr as $key => $val ){
            $words[] = $key;
            if( is_array($val) ){
                $words = self::hashArray( $val, $words, true );
            }
            else {
                $words[] = (string) $val;
            }
        }
        if( $recursion ){
            return $words;
        }
        $hash = implode('_', $words );
        if( isset($hash{32}) ){
            return md5( $hash );
        }
        return $hash;
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
        $service = $this->getServiceDescription();
        return json_encode( $service->toArray(), $options );
    }    



    /**
     * Export service description to PHP array
     * @return string
     */
    public function export(){
        $service = $this->getServiceDescription();
        $comment = sprintf("/**\n * Auto-generated with Swizzle at %s\n */", date('Y-m-d H:i:s O') );
        $source = var_export( $service->toArray(), 1 );
        return "<?php\n".$comment."\nreturn ".$source.";\n"; 
    }    
    
}



