<?php

namespace Loco\Utils\Swizzle;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;

use Guzzle\Service\Description\ServiceDescription;
use Guzzle\Service\Description\Operation;
use Guzzle\Service\Description\Parameter;
use Guzzle\Parser\UriTemplate\UriTemplate;

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
     * Default response serialization type
     * @var string xml|json
     */    
    private $responseType = 'json';    
    
    /**
     * Default request body serialization type
     * @var string xml|json
     */    
    private $requestType = 'json';    
    
    /**
     * Registry of custom reponse classes, mapped by method command name
     * @var array
     */    
    private $responseClasses = array();
    
    /**
     * Registry of custom operation command classes, mapped by method command name
     * @var array
     */    
    private $commandClasses = array();
    
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
     * Set base URL
     * @param string base url common to all api calls
     * @return string 
     */
    public function setBaseUrl( $baseUrl ){
        return $this->setInitValue( 'baseUrl', $baseUrl );
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
        $this->responseClasses[$name] = $class;
        // set retrospectively if method already encountered
        if( $this->service ){
            $op = $this->service->getOperation($name) and
            $op->setResponseClass( $class );
        }
        return $this;
    }     
    
    
    /**
     * Apply a bespoke operation command class to a given method, or all methods
     * @param string name of command using this class, or "" for all.
     * @param string full class name for operation class field
     * @return Swizzle
     * @throws \Exception
     */
    public function registerCommandClass( $name, $class ){
        $this->commandClasses[$name] = $class;
        // set retrospectively if method already encountered
        if( $this->service ){
            if( $name ){
                $op = $this->service->getOperation($name) and
                $op->setClass( $class );
            }
            else {
                throw new \Exception('Too late to register a global command class');
            }
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
            $this->debug('+ set apiVersion %s', $version );
            $this->setApiVersion( $version );
        }
        // Set description if missing from constructor
        if( ! $this->init['description'] ){
            $info = $listing->getInfo();
            $this->init['description'] = $info['description']?:$this->init['title'];
        }
        // no more configs allowed now, Guzzle service gets constructed
        $service = $this->getServiceDescription();
        // set base path from docs location if not provided
        if( ! $service->getBaseUrl() ){
            $service->setBaseUrl( self::mergeUrl('/', $base_url ) );
        }
        // ready to pull each api declaration
        foreach( $listing->getApiPaths() as $path ){
            if( $this->delay ){
                usleep( $this->delay );
            }
            // @todo do proper path resolution here, allowing a cross-domain spec.
            $path = trim($path,'/ ');
            $this->debug('pulling /%s ...', $path );
            $declaration = $client->getDeclaration( compact('path') );
            foreach ( $declaration->getModels() as $model ) {
                $this->addModel( $model );
            }
            // Ensure a fully qualified base url for this api
            $baseUrl = self::mergeUrl( $declaration->getBasePath(), $service->getBaseUrl() );
            // add each api against required base url
            foreach( $declaration->getApis() as $api ){
                $this->addApi( $api, $baseUrl );
            }
        }
        $this->debug('finished');
        return $this;
    }
    
    
    
    
    /**
     * Create a Swagger model definition
     * @param array model structure from Swagger
     * @param string location where parameters will be found in request or response
     * @return Parameter model resolved against service description but not added
     */
    private function createModel( array $model, $location ){
        $name = isset($model['id']) ? trim($model['id']) : '';
        if( ! $name ){
            $name = $model['id'] = 'anon_'.self::hashArray($model);
        }
        // a model is basically a parameter, but has name property added
        $defaults = array (
            'name' => $name,
            'type' => 'object',
        );
        $data = $this->transformSchema( $model + $defaults, $this->responseType );
        if( 'object' === $data['type'] ){
            $data['additionalProperties'] = false;
            // model must have top level properties specified as serialized response type, but no response type itself
            foreach( $data['properties'] as $key => $prop ){
                $data['properties'][$key]['location'] = $location;
            }
        }
        else if( 'array' === $data['type'] ){
            // @todo put location on each property within each item aws per GetUsersOutput example on Guzzle site.
            // @see https://github.com/guzzle/guzzle/issues/560
        }
        
        // required makes no sense at root of model
        unset( $data['required'] );
        
        return new Parameter( $data, $this->getServiceDescription() );
    }   



    /**
     * Add a response model 
     * @param array model structure from Swagger
     * @return Parameter model added to service description
     */
    public function addModel( array $model ){
        // swagger only has locations for requests, so we can safely default to our response serializer.
        $model = $this->createModel( $model, $this->responseType );
        $this->debug('+ adding model %s', $model->getName() );
        $this->getServiceDescription()->addModel( $model );
        return $model;
    }

     
    
    
    /**
     * Add a Swagger Api declaration which may consist of multiple operations
     * @param array consisting of path, description and array of operations
     * @param string URL inferring the base location for api path
     * @throws \Exception
     * @return Swizzle
     */    
    public function addApi( array $api, $baseUrl = '' ){
        $service = $this->getServiceDescription();
        if( ! $baseUrl ){
            $baseUrl = $service->getBaseUrl();
        }
        // resolve URL relative to base path for all operations
        $uri = implode( '/', array( rtrim($baseUrl,'/'), ltrim($api['path'],'/') ) );
        // keep domain only if not under service base path
        if( 0 === strpos( $uri, $service->getBaseUrl() ) ){
            $uri = preg_replace('!^https?://[^/]+!', '', $uri );
        }
        $this->debug('+ adding api %s ...', $uri );
        
        // no need for full url if relative to current
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
            $config['uri'] = $uri;
            // command must have a name, and must be unique across methods
            if( isset($op['nickname']) ){
                $id = $config['name'] = $op['nickname'];
            }
            // generate naff nickname if not specified
            else {
                $method = strtolower( $config['httpMethod'] );
                $id = $config['name'] = $method.'_'.str_replace('/','_',trim($uri,'/') );
            }

            // allow custom command class, or global class for all commands
            if( isset($this->commandClasses[$id]) ){
                $config['class'] = $this->commandClasses[$id];
            }
            else if( isset($this->commandClasses['']) ){
                $config['class'] = $this->commandClasses[''];
            }

            // allow registered response class to override all response type logic
            if( isset($this->responseClasses[$id]) ){
                $config['responseType'] = 'class';
                $config['responseClass'] = $this->responseClasses[$id];
            }
            // handle response type if defined
            else if( isset($config['responseType']) ){

                // Check for primitive values first
                $type = $this->transformSimpleType( $config['responseType'] ) or
                $type = $config['responseType'];

                // Array primitive may be typed with 'items' spec, but Guzzle operation ignores at top level
                if( 'array' === $type ){
                    if( isset($op['items']) ){
                        $this->debug("! no modelling support for root arrays. Item types won't be validated" );
                    }
                } 
                // Root objects must be declared as models in Guzzle. 
                // i.e "object" is not a valid primitive for responseClass
                else if( 'object' === $type ){
                    $model = $this->addModel( $op );
                    $type = $model->getName();
                }
                // allowed responseClass primitives are 'array', 'boolean', 'string', 'integer' and ''
                // That leaves just "number" and "null" as unsupported from the core 7 types in json schema.
                else if ( 'number' === $type ){
                    $this->debug('! number type defaulted to string as responseClass');
                    $type = 'string';
                }
                else if( 'null' === $type ){
                    $this->debug('! empty type "%s" defaulted to empty responseClass', $config['responseType'] );
                    $type = '';
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
                if( empty($this->responseClasses[$id]) || $class !== $this->responseClasses[$id] ){
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
    private function transformParams( array $params ){
        $locations = array();
        $namespace = array();
        foreach( $params as $name => $_param ){
            if( isset($_param['name']) ){    
                $name = $_param['name'];
            }
            else {
                $_param['name'] = $name;
            }
            $param = $this->transformSchema( $_param );
            $location = isset($param['location']) ? $param['location'] : '';
            // resolve models immediately. Guzzle will resolve anyway and we need the data for request body transforms
            if( isset($param['$ref']) ){
                $param = $this->getServiceDescription()->getModel( $param['$ref'] )->toArray();
            }
            // handle paramType -> location mapping.
            if( $location ){
                $location = $param['location'] = $this->transformLocation( $location );
                // swagger doesn't allow optional path params
                if( ! isset($param['required']) ){
                    $param['required'] = 'uri' === $location;
                }
            }
            // handle serialization in request body.
            if( 'body' === $location ){
                $location = $this->requestType;
                // objects properties must be moved into parent namespace or Guzzle will wrap them.
                if( isset($param['properties']) ) {
                    foreach( $param['properties'] as $name => $child ){
                        $child['location'] = $location;
                        $locations[$location][$name] = $child;
                        $namespace[$name][$location] = 1;
                    }
                    continue;
                }
            }
            // else add single parameter by name
            $locations[$location][$name] = $param;
            $namespace[$name][$location] = 1;
        }        
        // resolve all locations to single namespace
        // conflict can occur due to differences in swagger/guzzle modelling of complex params
        $target = array();
        foreach( $locations as $location => $params ){
            foreach( $params as $name => $param ) {
               unset( $namespace[$name][$location] );
               if( $conflicts = array_keys($namespace[$name]) ) {
                   $alias = $name.'_'.$location;
                   $this->debug('! %s parameter "%s" conflicts with %s, address as "%s"', $location, $name, implode(' and ',$conflicts), $alias );
                   // namespace this property at our end ensuring it's sent to the API as expected.
                   $param['sentAs'] = $name;
                   $name = $param['name'] = $alias;
               }
               $target[$name] = $param; 
            }
        }
        return $target;
    }



    /**
     * Transform a Swagger request paramType to a Guzzle location.
     * Note that Guzzle has response locations too.
     * @param string Swagger paramType request field (path|query|body|header|form)
     * @return string Guzzle location field (uri|query|body|header|postField|xml|json)
     */
    private function transformLocation( $paramType ){
        // Guzzle request locations: (statusCode|reasonPhrase|header|body|json|xml)
        // Guzzle response locations: (uri|query|header|body|postField|postFile|json|xml|responseBody)
        static $valid = array (
            'uri' => 1,
            'xml' => 1,
            'json' => 1,
            'body' => 1,
            'query' => 1,
            'header' => 1,
            'postField' => 1,
        );
        // may be already transformed and just passing through
        if( isset($valid[$paramType]) ){
            return $paramType;
        }        
        static $aliases = array (
            'path' => 'uri',
            'body' => 'body',
            'form' => 'postField',
            'query' => 'query',
            'header' => 'header',
        );
        // return alias, defaulting to empty
        return isset($aliases[$paramType]) ? $aliases[$paramType] : '';
    }



    /**
     * Transform an object holding a Swagger data type into a Guzzle one
     * @param array Swagger schema
     * @return array Guzzle schema
     */
    private function transformSchema( array $source ){
        $name = isset($source['name']) ? $source['name'] : 'anon';
        // keys common to both swagger and guzzle
        static $common = array (
            '$ref' => 1,
            'type' => 1,
            'name' => 1,
            'enum' => 1,
            'items' => 1,
            'format' => 1,
            'minimum' => 1,
            'maximum' => 1,
            'required' => 1,
            'minLength' => 1,
            'maxLength' => 1,
            'properties' => 1,
            'description' => 1,
            
        );
        // keys requiring translation
        static $trans = array (
            'paramType' => 'location',
            'defaultValue' => 'default',
        );
        // initial translation
        $target = $this->transformArray( $source, $common, $trans ) + array( 'type' => '' );

        // validate Swagger refs now. Resolve later as appropriate.
        if( isset($target['$ref']) ){
            if( ! $this->getServiceDescription()->getModel($target['$ref']) ){
                throw new \Exception('Encountered $ref to "'.$target['$ref'].'" in '.$name.' but model not registered');
            }
            unset( $target['type'] );
            return $target;
        }

        // Validate data type
        $type = '';

        // handle array of types entities
        if( isset($target['items']) ){
            $type = 'array';
            if( $type !== $target['type'] ){
                $this->debug('! %s %s declares items, coercing to "%s"', $target['type']?:'untyped', $name, $type );
                $target['type'] = $type;
            }
            // resolve model reference ensuring model exists
            if( isset($target['items']['$ref']) ){
                $ref = $target['items']['$ref'];
                if( ! $this->getServiceDescription()->getModel($ref) ){
                    throw new \Exception('"'.$ref.'" encountered as items $ref but not defined as a model');
                }
            }
            // Else define a literal model definition on the fly. 
            // Guzzle will resolve back to literals on output, but it helps us resolve typed arrays and such
            else {
                //$target['items'] = $this->transformSchema( $target['items'] );
                $model = $this->addModel( $target['items'] );
                $target['items'] = array(
                    '$ref' => $model->getName(),
                );
            }
        }

        // Recurse into object properties
        if( isset($source['properties']) ){
            $type = 'object';
            if( $type !== $target['type'] ){
                $this->debug('! %s %s declares properties, coercing to "%s"', $target['type']?:'untyped', $name, $type );
                $target['type'] = $type;
            }
            $target['properties'] = $this->transformParams( $source['properties'] );
            // required params are an external array in Swagger, but applied individually as boolean in Guzzle
            if( isset($source['required']) && is_array($source['required']) ){
                foreach( $source['required'] as $prop ){
                    if( isset($target['properties'][$prop]) ){
                        $target['properties'][$prop]['required'] = true;
                    }
                }
            }
        }
        
        if( ! $type ){
            $type = $originalType = $target['type'];
            if( $type && $this->getServiceDescription()->getModel($type) ){
                // param type is registered model
                $target['$ref'] = $type;
                unset( $target['type'] );
                return $target;
            }
            // else handle as primitive type
            $frmt = isset($target['format']) ? $target['format'] : '';
            $type = $target['type'] = $this->transformSimpleType( $type, $frmt );
            // else fall back to a sensible default
            if( ! $type ){
                $this->debug('! type "%s" unknown, defaulting to null', $originalType );
                $type = $target['type'] = 'null';
            }
        }

        // Guzzle and swagger have minimal format overlap
        if( isset($target['format']) ){
            $target['format'] = $this->transformTypeFormat( $target['format'] );
        }
        
        // ensure properties is set even if empty, which makes little sense.
        if( 'object' === $type && empty($target['properties']) ){
            $this->debug('! object %s has empty properties', $name );
            $target['properties'] = array();
        }
        
        return $target;
    }
    
    
    
    /**
     * Transform Swagger simple type to valid JSON Schema type. (which it should be anyway).
     * @see http://tools.ietf.org/id/draft-zyp-json-schema-04.html#rfc.section.3.5
     * @param string type specified in swagger field
     * @param optional format specified in format field
     * @return string one of array boolean integer number null object string
     */
    private function transformSimpleType( $type, $format = '' ){
        static $aliases = array (
            '' => 'null',
            'null' => 'null',
            'void' => 'null',
            'number' => 'number',
            'numeric' => 'number',
            'float' => 'number',
            'double' => 'number',
            'int' => 'integer',
            'int32' => 'integer',
            'int64' => 'integer',
            'integer' => 'integer',
            'bool' => 'boolean',
            'boolean' => 'boolean',
            'array' => 'array',
            'object' => 'object',
            'string' => 'string',
            'byte' => 'string',
            'date' => 'string',
            'date-time' => 'string',
        );        
        $type = isset($aliases[$type]) ? $aliases[$type] : '';
        if( ! $type && $format ){
            $type = isset($aliases[$format]) ? $aliases[$format] : '';
        }
        return $type;
    }    
    
    
    
    /**
     * Transform Swagger's datatype format hinting to Guzzle's
     * @param string Swagger's format field
     * @return string one of "date-time", "date" or ""
     */
    private function transformTypeFormat( $format ){
        static $aliases = array(
            'date' => 'date',
            'date-time' => 'date-time',
        );
        // Guzzle supports also time, timestamp, date-time-http but Swagger has no equivalent
        return isset($aliases[$format]) ? $aliases[$format] : '';
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
     * Utility for merging any URI into a fully qualified one
     * @param string URI that may be a /path or http://address
     * @param string full base URL that may or may not be on same domain
     * @return string
     */
    private static function mergeUrl( $uri, $baseUrl ){
        $href = parse_url($uri);
        $base = parse_url($baseUrl);
        $full = $href + $base + parse_url('http://localhost/');
        return $full['scheme'].'://'.$full['host'].$full['path'];
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

