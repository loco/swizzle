<?php

namespace Loco\Tests\Utils\Swizzle;

use GuzzleHttp\Client;
use GuzzleHttp\Command\Guzzle\Description;
use GuzzleHttp\Command\Guzzle\GuzzleClient;
use GuzzleHttp\Command\Guzzle\Parameter;
use GuzzleHttp\Command\Result;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Loco\Utils\Swizzle\Deserializer;

/**
 * Tests Guzzle's internal modelling logic.
 * @group model
 */
class ModelTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var Description
     */
    private static $serviceDescription;

    public static function setUpBeforeClass()
    {
        // Define a service with a test() method
        static::$serviceDescription = new Description([
            'name' => 'test-service',
            'operations' => [
                // method returning single model
                'test' => [
                    'uri' => '/test.json',
                    'httpMethod' => 'GET',
                    'responseModel' => 'TestModel',
                    'class' => '\\Loco\\Utils\\Swizzle\\Command\\StrictCommand',
                ],
                // method returning array of models inside wrapper model
                'testwrap' => [
                    'uri' => '/testwrap.json',
                    'httpMethod' => 'GET',
                    'responseModel' => 'TestModelList',
                    'class' => '\\Loco\\Utils\\Swizzle\\Command\\StrictCommand',
                ],
                // method returning special typed array with root property
                'testwrapobj' => [
                    'uri' => '/testwrapobj.json',
                    'httpMethod' => 'GET',
                    'responseModel' => 'TestModelListObject',
                    'class' => '\\Loco\\Utils\\Swizzle\\Command\\StrictCommand',
                ],
            ],
            'models' => [
                'TestModel' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        // property that will exist in response
                        'foo' => [
                            'type' => 'integer',
                            'location' => 'json',
                            'required' => true,
                        ],
                        // property that won't exist in response
                        'bar' => [
                            'type' => 'integer',
                            'location' => 'json',
                            'required' => false,
                        ],
                    ],
                ],
                // define array of typed objects
                'TestModelList' => [
                    'type' => 'array',
                    'location' => 'json',
                    'items' => [
                        '$ref' => 'TestModel',
                    ],
                ],
                // define root object with array property
                'TestModelListObject' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'list' => [
                            'type' => 'array',
                            'location' => 'json', // <- critical
                            'items' => [
                                '$ref' => 'TestModel',
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    /**
     * @param array $httpClientConfig
     *
     * @return GuzzleClient
     */
    private function createClient(array $httpClientConfig = [])
    {
        return new GuzzleClient(
            new Client($httpClientConfig),
            static::$serviceDescription,
            null,
            new Deserializer(static::$serviceDescription, true)
        );
    }

    /**
     * Test constructing a client. Verify client is build correctly with all configured operations and models.
     */
    public function testClientConstruct()
    {
        $client = $this->createClient();
        $service = $client->getDescription();

        // test models are defined ok
        $operation = $service->getOperation('test');
        $this->assertEquals('TestModel', $operation->getResponseModel());

        // test listing is aware of models in itself
        // list wrapper is a model
        $operation = $service->getOperation('testwrap');
        $this->assertEquals('TestModelList', $operation->getResponseModel());
        // test model resolved from $ref
        $listModel = $service->getModel('TestModelList');
        $this->assertInstanceOf(Parameter::class, $listModel);
        // items should be single schema specifying one allowed model type
        $items = $listModel->getItems();
        $this->assertInstanceOf(Parameter::class, $items);
        $this->assertEquals('object', $items->getType());
        $this->assertArrayHasKey('foo', $items->getProperties());
    }

    /**
     * Test single model response
     * @group mock
     */
    public function testModelResponseValidates()
    {
        // fake a response with valid "foo" and legally missing "bar" property
        $response = new Response(200, [], '{"foo":1}');
        $handlerStack = MockHandler::createWithMiddleware([$response]);
        $client = $this->createClient(['handler' => $handlerStack]);

        /** @var Result $response */
        $response = $client->test();
        // test value of "foo" key, which will exist
        $this->assertEquals(1, $response->offsetGet('foo'));
        // test value of "bar" key which isn't in response
        $this->assertEquals(null, $response->offsetGet('bar'));
    }

    /**
     * Test single model response that fails validation
     *
     * @expectedException \Loco\Utils\Swizzle\Exception\ValidationException
     *
     * @group mock
     */
    public function testModelResponseFailure()
    {
        // fake a response with missing required property and extra invalid one
        $response = new Response(200, [], '{"baz":1}');
        $handlerStack = MockHandler::createWithMiddleware([$response]);
        $client = $this->createClient(['handler' => $handlerStack]);

        $client->test();
    }

    /**
     * Test array of models in response defined with wrapper model
     *
     * @group mock
     */
    public function testModelListResponse()
    {
        // fake a response with multiple valid objects
        $response = new Response(200, [], '[{"foo":1},{"foo":2},{"foo":3}]');
        $handlerStack = MockHandler::createWithMiddleware([$response]);
        $client = $this->createClient(['handler' => $handlerStack]);

        /** @var Result $result */
        $result = $client->testwrap();
        // test response is a model
        $this->assertInstanceOf(Result::class, $result);

        $data = $result->toArray();
        $this->assertCount(3, $data);
    }

    /**
     * Test array of models in response defined with wrapper model containing an array property
     *
     * @group mock
     */
    public function testModelListObjectResponse()
    {
        // fake a response with multiple valid objects
        $response = new Response(200, [], '{"list":[{"foo":4},{"foo":5},{"foo":6}]}');
        $handlerStack = MockHandler::createWithMiddleware([$response]);
        $client = $this->createClient(['handler' => $handlerStack]);

        /** @var Result $result */
        $result = $client->testwrapobj();
        // test response is a model
        $this->assertInstanceof(Result::class, $result);

        // test response has 3 items
        $list = $result->offsetGet('list');
        $this->assertCount(3, $list);
    }

    /**
     * @see https://github.com/guzzle/guzzle/issues/581
     */
    public function testModelRecursion()
    {
        // define a model with a property that is a list of the same model
        $modelData = [
            'name' => 'TestRecursion',
            'type' => 'object',
            'properties' => [
                'recurse' => [
                    'type' => 'array',
                    'items' => [
                        '$ref' => 'TestRecursion',
                    ],
                ],
            ],
        ];

        $desc = new Description([
            'models' => [
                'TestRecursion' => $modelData
            ],
        ]);
        $model = $desc->getModel('TestRecursion');
        $this->assertInstanceOf(Parameter::class, $model);
        $dump = $model->toArray();

        $this->assertEquals($modelData, $dump);
    }

}


