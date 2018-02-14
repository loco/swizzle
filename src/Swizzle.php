<?php

namespace Loco\Utils\Swizzle;

use GuzzleHttp\Command\Guzzle\Description;
use GuzzleHttp\Command\Guzzle\Operation;
use GuzzleHttp\Command\Guzzle\Parameter;
use GuzzleHttp\Command\ResultInterface;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

/**
 * Models Swagger API declarations and converts to Guzzle service descriptions.
 */
class Swizzle
{

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
     * @var Description
     */
    private $serviceDescription;

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
    private $responseClasses = [];

    /**
     * Delay between HTTP requests in microseconds
     * @var int
     */
    private $delay = 200000;

    /**
     * @var Parameter[]
     */
    private $models;

    /**
     * @var Operation[]
     */
    private $operations;

    /**
     * Construct with minimum mandatory parameters
     *
     * @param string $name Name of the API
     * @param string $description Summary of the API
     * @param string $apiVersion API version
     *
     * @throws \Exception
     */
    public function __construct($name, $description = null, $apiVersion = null)
    {
        $this->init = compact('name', 'description', 'apiVersion');
        $this->logger = new Logger('swizzle');
        // if we don't add a handler we get debug messages by default.
        $this->logger->pushHandler(new StreamHandler('php://stderr', Logger::ERROR));
    }

    /**
     * Reset renerated models, operations and current service Description
     */
    public function reset()
    {
        $this->models = [];
        $this->operations = [];
        $this->serviceDescription = null;
    }

    /**
     * Enable debug logging to show build progress
     *
     * @param string|resource
     *
     * @return Swizzle
     *
     * @throws \Exception
     */
    public function verbose($resource)
    {
        $this->logger->pushHandler(new StreamHandler($resource, Logger::DEBUG));
        return $this;
    }

    /**
     * @internal Log debug events in verbose mode
     *
     * @param string $message
     */
    private function debug($message)
    {
        if (1 < func_num_args()) {
            $message = call_user_func_array('sprintf', func_get_args());
        }
        $this->logger->addDebug($message);
    }

    /**
     * Set delay between HTTP requests
     *
     * @param int delay in microseconds
     *
     * @return Swizzle
     */
    public function setDelay($microseconds)
    {
        $this->delay = (int)$microseconds;
        return $this;
    }

    /**
     * Set an initial value to be passed to ServiceDescription constructor.
     *
     * @param string $key
     * @param mixed $value
     *
     * @return Swizzle
     *
     * @throws \RuntimeException
     */
    private function setInitValue($key, $value)
    {
        if ($this->serviceDescription !== null) {
            throw new \RuntimeException('Too late to set "'.$key.'"');
        }
        $this->init[$key] = $value;

        return $this;
    }

    /**
     * Set base URL
     *
     * @param string base url common to all api calls
     *
     * @return string
     *
     * @throws \RuntimeException
     */
    public function setBaseUri($baseUri)
    {
        return $this->setInitValue('baseUri', $baseUri);
    }

    /**
     * Set API version string
     *
     * @param string api version
     *
     * @return Swizzle
     *
     * @throws \RuntimeException
     */
    public function setApiVersion($apiVersion)
    {
        return $this->setInitValue('apiVersion', $apiVersion);
    }

    /**
     * Get compiled Guzzle service description
     *
     * @return Description
     *
     * @throws \InvalidArgumentException
     */
    public function getServiceDescription()
    {
        if ($this->serviceDescription === null) {
            $this->serviceDescription = new Description($this->init);
        }
        return $this->serviceDescription;
    }

    /**
     * Apply a bespoke responseClass to a given method
     *
     * @param string name of command returning this response class
     * @param string full class name for responseClass field
     *
     * @return Swizzle
     *
     * @throws \LogicException
     * @throws \InvalidArgumentException
     */
    public function registerResponseClass($name, $class)
    {
        $this->responseClasses[$name] = $class;
        // set retrospectively if method already encountered
        if ($this->serviceDescription !== null
            && $this->hasOperation($name) === true
        ) {
            throw new \LogicException(
                "Too late to add new response class, {$name} operation has already been defined. "
                .'If you want to add a new response class, call reset() method first, add new response class '
                .'and run build again.'
            );
        }
        return $this;
    }

    /**
     * Build from a live endpoint
     *
     * @param string Swagger compliant JSON endpoint for resource listing
     *
     * @throws \Exception
     * @return Swizzle
     */
    public function build($baseUri)
    {
        $this->serviceDescription = null;
        $client = SwaggerClient::factory(['base_uri' => $baseUri]);
        $this->debug('Fetching resource listing from %s', $baseUri);
        $listing = $client->getResources();

        // check this looks like a resource listing
        if (!$listing->isSwagger()) {
            throw new \RuntimeException("This doesn't look like a Swagger spec");
        }
        if (!$listing->getApis()) {
            $this->logger->addAlert("Resource listing doesn't define any APIs");
        }
        // check swagger version
        if (self::SWAGGER_VERSION !== $listing->getSwaggerVersion()) {
            throw new \RuntimeException('Unsupported Swagger version, Swizzle expects '.self::SWAGGER_VERSION);
        }
        // Declared version overrides anything we've set
        if ($version = $listing->getApiVersion()) {
            $this->debug('+ set apiVersion %s', $version);
            $this->setApiVersion($version);
        }
        // Set description if missing from constructor
        if (empty($this->init['description'])) {
            $info = $listing->getInfo();
            $this->init['description'] = $info['description'] ?: $this->init['title'];
        }
        // set base path from docs location if not provided
        if (empty($this->init['baseUri'])) {
            $this->init['baseUri'] = self::mergeUrl('/', $baseUri);
        }
        // no more configs allowed now, Guzzle service gets constructed
        $service = $this->getServiceDescription();
        // ready to pull each api declaration
        foreach ($listing->getApiPaths() as $path) {
            if ($this->delay) {
                usleep($this->delay);
            }
            // @todo do proper path resolution here, allowing a cross-domain spec.
            $path = rtrim(parse_url($baseUri)['path'], '/').'/'.ltrim($path, '/');
            $this->debug('pulling %s ...', $path);
            $apiDeclaration = $client->getDeclaration(compact('path'));
            foreach ($apiDeclaration->getModels() as $model) {
                $this->addModel($model);
            }
            // Ensure a fully qualified base url for this api
            $baseUrl = self::mergeUrl($apiDeclaration->getBasePath(), $service->getBaseUri());
            // add each api against required base url
            foreach ($apiDeclaration->getApis() as $api) {
                $this->addApi($api, $baseUrl);
            }
        }
        $this->debug('finished');
        return $this;
    }

    /**
     * Create a Swagger model definition
     *
     * @param array $model
     * @param string location where parameters will be found in request or response
     *
     * @return Parameter model resolved against service description but not added
     *
     * @throws \Exception
     */
    private function createModel(array $model, $location)
    {
        $name = null;
        if (isset($model['id']) === true) {
            // if model id is set, us it as a name
            $name = trim($model['id']);
        } elseif (isset($model['nickname'], $this->responseClasses[$model['nickname']])) {
            // if custom response class is defined with model nickname, use nickname
            $name = $model['nickname'];
        } elseif (isset($model['type']) === true && $model['type'] === 'array') {
            // if model is of array type
            if (isset($model['items']['$ref']) === true) {
                // E. g. if it's an array of 'Tag' models, call this model 'TagList'.
                $name = $model['items']['$ref'].'List';
            } elseif (isset($model['nickname']) === true) {
                // Fallback generate name from operation nickname (e.g. getTags -> TagList)
                $found = preg_match('/[A-Z]/', $model['nickname'], $matches, PREG_OFFSET_CAPTURE);
                if ($found === 1) {
                    $name = rtrim(substr($model['nickname'], $matches[0][1]), 's').'List';
                }
            }
        }
        // if none of above worked to create a model name, hash model array
        if ($name === null) {
            $name = $model['id'] = 'anon_'.self::hashArray($model);
        }

        // a model is basically a parameter, but has name property added
        $defaults = [
            'name' => $name,
            'type' => 'object',
        ];
        /** @noinspection AdditionOperationOnArraysInspection */
        $data = $this->transformSchema($model + $defaults);
        if ('object' === $data['type']) {
            $data['additionalProperties'] = false;
            // model must have top level properties specified as serialized response type, but no response type itself
            foreach ($data['properties'] as $key => $property) {
                $data['properties'][$key]['location'] = $location;
            }
        } elseif ('array' === $data['type']) {
            $data['location'] = $location;
        }

        // allow result class override
        if (isset($this->responseClasses[$name])) {
            $class = $this->responseClasses[$name];
            $data['class'] = $class;

            if (is_subclass_of($class, ResultInterface::class)) {
                $data['type'] = 'object';
                if (is_array($data['additionalProperties']) === false) {
                    $data['additionalProperties'] = [];
                }
                $data['additionalProperties']['location'] = $location;
            } else {
                $data['type'] = 'class';
            }
        }

        // required makes no sense at root of model
        unset($data['required']);

        return new Parameter($data, ['description' => $this->getServiceDescription()]);
    }

    /**
     * Add a response model
     *
     * @param array model structure from Swagger
     *
     * @return Parameter model added to service description
     *
     * @throws \Exception
     */
    public function addModel(array $modelData)
    {
        // swagger only has locations for requests, so we can safely default to our response serializer.
        $model = $this->createModel($modelData, $this->responseType);
        $modelData = $model->toArray();
        if ($modelData['type'] === 'array' && isset($modelData['name'])) {
            unset($modelData['name']);
        }
        $this->debug('+ adding model %s', $model->getName());
        $this->models[$model->getName()] = $modelData;

        return $model;
    }

    /**
     * Add a Swagger Api declaration which may consist of multiple operations
     *
     * @param array $api consisting of path, description and array of operations
     * @param string $baseUri URL inferring the base location for api path
     *
     * @return Swizzle
     *
     * @throws \Exception
     */
    public function addApi(array $api, $baseUri = null)
    {
        $service = $this->getServiceDescription();
        if ($baseUri === null) {
            $baseUri = $service->getBaseUri()->__toString();
        }
        // resolve URL relative to base path for all operations
        $uri = implode('/', [rtrim($baseUri, '/'), ltrim($api['path'], '/')]);
        // keep domain only if not under service base path
        if ($baseUri !== '' && strpos($uri, $baseUri) === 0) {
            $uri = preg_replace('!^https?://[^/]+!', '', $uri);
        }
        $this->debug('+ adding api %s ...', $uri);

        // no need for full url if relative to current
        // operation keys common to both swagger and guzzle
        static $common = [
            'summary' => 1,
        ];
        // translate swagger -> guzzle 
        static $trans = [
            'type' => 'responseType',
            'notes' => 'responseNotes',
            'method' => 'httpMethod',
        ];
        static $defaults = [
            'httpMethod' => 'GET',
        ];
        /** @var array[][] $api */
        foreach ($api['operations'] as $operationData) {
            /** @noinspection AdditionOperationOnArraysInspection */
            $config = $this->transformArray($operationData, $common, $trans) + $defaults;
            $config['uri'] = $uri;
            // command must have a name, and must be unique across methods
            if (isset($operationData['nickname'])) {
                $id = $config['name'] = $operationData['nickname'];
            } // generate naff nickname if not specified
            else {
                $method = strtolower($config['httpMethod']);
                $id = $config['name'] = $method.'_'.str_replace('/', '_', trim($uri, '/'));
            }

            if (isset($config['responseType'])) { // handle response type if defined
                // Check for primitive values first
                $simpleType = $this->transformSimpleType($config['responseType']);

                if ($simpleType !== null && $simpleType !== 'null') {
                    // If response type is defined, there should always be a responseModel, not a primitive type.
                    // Response model itself can represent a primitive type
                    $model = $this->addModel($operationData);
                    $config['responseModel'] = $model->getName();
                } else {
                    if ($this->hasModel($config['responseType'])) {
                        $config['responseModel'] = $config['responseType'];
                    } else {
                        throw new \RuntimeException(
                            "Response model {$config['responseType']} required by operation {$id} is not registered."
                        );
                    }
                }
                unset($config['responseType']);
            }

            // handle parameters
            if (isset($operationData['parameters'])) {
                $config['parameters'] = $this->transformParams($operationData['parameters']);
            } else {
                $config['parameters'] = [];
            }

            // handle responseMessages -> errorResponses
            if (isset($operationData['responseMessages'])) {
                $config['errorResponses'] = $this->transformResponseMessages($operationData['responseMessages']);
            } else {
                $config['errorResponses'] = [];
            }

            // @todo how to deny additional parameters in command calls?
            // $config['additionalParameters'] = false;
            $operation = new Operation($config, $service);
            $this->operations[$operation->getName()] = $operation->toArray();
        }
        return $this;
    }

    /**
     * Transform a swagger parameter to a Guzzle one
     *
     * @param array $params
     *
     * @return array
     * @throws \Exception
     */
    private function transformParams(array $params)
    {
        $locations = [];
        $namespace = [];
        foreach ($params as $name => $param) {
            if (isset($param['name'])) {
                $name = $param['name'];
            } else {
                $param['name'] = $name;
            }
            $param = $this->transformSchema($param);
            $location = isset($param['location']) ? $param['location'] : '';
            // resolve models immediately. Guzzle will resolve anyway and we need the data for request body transforms
            if (isset($param['$ref'])) {
                $param = $this->models[$param['$ref']];
            }
            // handle paramType -> location mapping.
            if ($location) {
                $location = $param['location'] = $this->transformLocation($location);
                // swagger doesn't allow optional path params
                if (!isset($param['required'])) {
                    $param['required'] = 'uri' === $location;
                }
            }
            // handle serialization in request body.
            if ('body' === $location) {
                $location = $this->requestType;
                // objects properties must be moved into parent namespace or Guzzle will wrap them.
                /** @var array[] $param */
                if (isset($param['properties'])) {
                    foreach ($param['properties'] as $propertyName => $property) {
                        $property['location'] = $location;
                        $locations[$location][$propertyName] = $property;
                        $namespace[$propertyName][$location] = 1;
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
        $target = [];
        /**
         * @var string $location
         * @var array[] $parameters
         */
        foreach ($locations as $location => $parameters) {
            foreach ($parameters as $name => $param) {
                unset($namespace[$name][$location]);
                if ($conflicts = array_keys($namespace[$name])) {
                    $alias = $name.'_'.$location;
                    $this->debug('! %s parameter "%s" conflicts with %s, address as "%s"', $location, $name, implode(' and ', $conflicts), $alias);
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
     *
     * @param string Swagger paramType request field (path|query|body|header|form)
     *
     * @return string Guzzle location field (uri|query|body|header|postField|xml|json)
     */
    private function transformLocation($paramType)
    {
        // Guzzle request locations: (statusCode|reasonPhrase|header|body|json|xml)
        // Guzzle response locations: (uri|query|header|body|formParam|multipart|json|xml|responseBody)
        static $valid = [
            'uri' => 1,
            'xml' => 1,
            'json' => 1,
            'body' => 1,
            'query' => 1,
            'header' => 1,
            'formParam' => 1,
        ];
        // may be already transformed and just passing through
        if (isset($valid[$paramType])) {
            return $paramType;
        }
        static $aliases = [
            'path' => 'uri',
            'body' => 'body',
            'form' => 'formParam',
            'query' => 'query',
            'header' => 'header',
        ];
        // return alias, defaulting to empty
        return isset($aliases[$paramType]) ? $aliases[$paramType] : '';
    }

    /**
     * Transform an object holding a Swagger data type into a Guzzle one
     *
     * @param array Swagger schema
     *
     * @return array Guzzle schema
     *
     * @throws \Exception
     */
    private function transformSchema(array $source)
    {
        $name = isset($source['name']) ? $source['name'] : 'anon';
        // keys common to both swagger and guzzle
        static $common = [
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

        ];
        // keys requiring translation
        static $trans = [
            'paramType' => 'location',
            'defaultValue' => 'default',
        ];
        // initial translation
        $target = $this->transformArray($source, $common, $trans) + ['type' => ''];

        // validate Swagger refs now. Resolve later as appropriate.
        if (isset($target['$ref'])) {
            if ($this->hasModel($target['$ref']) === false) {
                throw new \RuntimeException('Encountered $ref to "'.$target['$ref'].'" in '.$name.' but model not registered');
            }
            unset($target['type']);
            return $target;
        }

        // Validate data type
        $type = null;

        // handle array of types entities
        if (isset($target['items'])) {
            $type = 'array';
            if ($type !== $target['type']) {
                $this->debug('! %s %s declares items, coercing to "%s"', $target['type'] ?: 'untyped', $name, $type);
                $target['type'] = $type;
            }
            // resolve model reference ensuring model exists
            if (isset($target['items']['$ref'])) {
                $ref = $target['items']['$ref'];
                if ($this->hasModel($ref) === false) {
                    throw new \RuntimeException('"'.$ref.'" encountered as items $ref but not defined as a model');
                }
            }
            // Else define a literal model definition on the fly. 
            // Guzzle will resolve back to literals on output, but it helps us resolve typed arrays and such
            else {
                if (isset($target['items']['type']) && $this->transformSimpleType($target['items']['type']) !== null) {
                    $target['items'] = $this->transformSchema($target['items']);
                } else {
                    $model = $this->addModel($target['items']);
                    $target['items'] = [
                        '$ref' => $model->getName(),
                    ];
                }
            }
        }

        // Recurse into object properties
        if (isset($source['properties'])) {
            $type = 'object';
            if ($type !== $target['type']) {
                $this->debug('! %s %s declares properties, coercing to "%s"', $target['type'] ?: 'untyped', $name, $type);
                $target['type'] = $type;
            }
            $target['properties'] = $this->transformParams($source['properties']);
            // required params are an external array in Swagger, but applied individually as boolean in Guzzle
            if (isset($source['required']) && is_array($source['required'])) {
                foreach ($source['required'] as $prop) {
                    if (isset($target['properties'][$prop])) {
                        $target['properties'][$prop]['required'] = true;
                    }
                }
            }
        }

        if ($type === null) {
            $type = $originalType = $target['type'];
            if ($type && $this->hasModel($type)) {
                // param type is registered model
                $target['$ref'] = $type;
                unset($target['type']);
                return $target;
            }
            // else handle as primitive type
            $frmt = isset($target['format']) ? $target['format'] : null;
            $type = $target['type'] = $this->transformSimpleType($type, $frmt);
            // else fall back to a sensible default
            if (!$type) {
                $this->debug('! type "%s" unknown, defaulting to null', $originalType);
                $type = $target['type'] = 'null';
            }
        }

        // Guzzle and swagger have minimal format overlap
        if (isset($target['format'])) {
            $target['format'] = $this->transformTypeFormat($target['format']);
        }

        // ensure properties is set even if empty, which makes little sense.
        if ('object' === $type && empty($target['properties'])) {
            $this->debug('! object %s has empty properties', $name);
            $target['properties'] = [];
        }

        return $target;
    }

    /**
     * Transform Swagger simple type to valid JSON Schema type. (which it should be anyway).
     *
     * @see http://tools.ietf.org/id/draft-zyp-json-schema-04.html#rfc.section.3.5
     *
     * @param string type specified in swagger field
     * @param string $format optional format specified in format field
     *
     * @return string|null One of: array, boolean, integer, number, null, object, string
     * or NULL if unable to match type.
     */
    private function transformSimpleType($type, $format = null)
    {
        static $aliases = [
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
        ];

        $type = isset($aliases[$type]) ? $aliases[$type] : null;
        if ($type === null && $format !== null) {
            $type = isset($aliases[$format]) ? $aliases[$format] : null;
        }

        return $type;
    }

    /**
     * Transform Swagger's datatype format hinting to Guzzle's
     *
     * @param string Swagger's format field
     *
     * @return string one of "date-time", "date" or ""
     */
    private function transformTypeFormat($format)
    {
        static $aliases = [
            'date' => 'date',
            'date-time' => 'date-time',
        ];
        // Guzzle supports also time, timestamp, date-time-http but Swagger has no equivalent
        return isset($aliases[$format]) ? $aliases[$format] : '';
    }

    /**
     * Transform Swagger responseMessages to Guzzle errorResponses.
     * @todo support registration of 'class' property?
     *
     * @param array containing code and message
     *
     * @return array containing code and phrase
     */
    private function transformResponseMessages(array $responseMessages)
    {
        static $common = [
            'code' => 1,
        ],
        $trans = [
            'message' => 'phrase',
        ];
        $errorResponses = [];
        foreach ($responseMessages as $message) {
            $errorResponses[] = $this->transformArray($message, $common, $trans);
        }
        return $errorResponses;
    }

    /**
     * Utility transform an array based on similarities and differences between the two formats.
     *
     * @param array $swagger source format (swagger)
     * @param array $common Keys common to both formats, { key: '', ... }
     * @param array $trans key translation mappings, { keya: keyb, ... }
     *
     * @return array target format (guzzle)
     */
    private function transformArray(array $swagger, array $common, array $trans)
    {
        // initialize with common array keys
        $guzzle = array_intersect_key($swagger, $common);
        // translate other naming differences
        foreach ($trans as $source => $target) {
            if (isset($swagger[$source])) {
                $guzzle[$target] = $swagger[$source];
            }
        }

        return $guzzle;
    }

    /**
     * Utility, hashes an array into something human readable if less than 32 chars.
     * Example: Use for creating anonymous model names, such as type_string
     *
     * @param array $arr
     * @param array $words
     * @param bool $recursion
     *
     * @return array|string
     */
    private static function hashArray(array $arr, array $words = [], $recursion = false)
    {
        foreach ($arr as $key => $val) {
            $words[] = $key;
            if (is_array($val)) {
                $words = self::hashArray($val, $words, true);
            } else {
                $words[] = (string)$val;
            }
        }
        if ($recursion) {
            return $words;
        }
        $hash = implode('_', $words);
        if (isset($hash{32})) {
            return md5($hash);
        }
        return $hash;
    }

    /**
     * Utility for merging any URI into a fully qualified one
     *
     * @param string URI that may be a /path or http://address
     * @param string full base URL that may or may not be on same domain
     *
     * @return string
     */
    private static function mergeUrl($uri, $baseUrl)
    {
        $href = parse_url($uri);
        $base = parse_url($baseUrl);
        $full = $href + $base + parse_url('http://localhost/');
        return $full['scheme'].'://'.$full['host'].$full['path'];
    }

    /**
     * Export service description to JSON
     *
     * @return string
     */
    public function toJson()
    {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT);
    }

    /**
     * Export service description to JSON
     *
     * @return array
     */
    public function toArray()
    {
        $result = [
            'name' => $this->init['name'],
            'apiVersion' => $this->init['apiVersion'],
            'baseUri' => isset($this->init['baseUri']) ? $this->init['baseUri'] : '',
            'description' => $this->init['description'],
        ];
        $result['operations'] = $this->operations;
        if (!empty($this->models)) {
            $result['models'] = $this->models;
        }

        return array_filter($result);
    }

    /**
     * Check if the service description has a model by name.
     *
     * @param string $id Name/ID of the model to check
     *
     * @return bool
     */
    protected function hasModel($id)
    {
        return isset($this->models[$id]);
    }

    protected function hasOperation($id)
    {
        return isset($this->operations[$id]);
    }
}

