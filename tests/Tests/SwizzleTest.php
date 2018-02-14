<?php

namespace Loco\Tests\Utils\Swizzle;

use GuzzleHttp\Command\Guzzle\Description;
use GuzzleHttp\Command\Guzzle\Parameter;
use Loco\Utils\Swizzle\Swizzle;

/**
 * Tests Swizzle
 * @group transform
 */
class SwizzleTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @return Swizzle
     *
     * @throws \Exception
     */
    public function testServiceConstruct()
    {
        $builder = new Swizzle('test', 'A test API', '1.0');
        $description = $builder->getServiceDescription();
        $this->assertEquals('test', $description->getName());
        $this->assertEquals('1.0', $description->getApiVersion());
        return $builder;
    }

    /**
     * Test adding of a model
     *
     * @depends testServiceConstruct
     *
     * @param Swizzle $builder
     *
     * @return Swizzle
     *
     * @throws \Exception
     */
    public function testModelAddition(Swizzle $builder)
    {
        // mock a Swagger model definition with one mandatory property    
        $def = [
            'id' => 'fooType',
            'properties' => [
                'bar' => [
                    'type' => 'string',
                    'description' => 'A test property',
                ],
            ],
            'required' => [
                'bar',
            ],
        ];
        $builder->addModel($def);
        $description = new Description($builder->toArray());
        $this->assertCount(1, $description->getModels());
        $foo = $description->getModel('fooType');
        $this->assertEquals('string', $foo->getProperty('bar')->getType());
        return $builder;
    }

    /**
     * Test a property can inherit an existing model by reference
     *
     * @depends testServiceConstruct
     *
     * @param Swizzle $builder
     *
     * @throws \Exception
     */
    public function testModelResolvesReference(Swizzle $builder)
    {
        $child = [
            'id' => 'barType',
            'type' => 'object',
        ];
        $parent = [
            'id' => 'bazType',
            'type' => 'object',
            'properties' => [
                'bar' => [
                    '$ref' => 'barType',
                ],
            ],
        ];
        $builder->addModel($child);
        $builder->addModel($parent);
        $description = new Description($builder->toArray());
        $baz = $description->getModel('bazType');
        $this->assertEquals('object', $baz->getProperty('bar')->getType());
    }

    /**
     * Test adding of an operation with two methods
     *
     * @depends testModelAddition
     *
     * @param Swizzle $builder
     *
     * @return Swizzle
     *
     * @throws \Exception
     */
    public function testOperationAddition(Swizzle $builder)
    {
        // mock a Swagger API with two ops
        $api = [
            'path' => '/test',
            'operations' => [
                [
                    'nickname' => 'getTest',
                    'summary' => 'Gets a test',
                    'method' => 'GET',
                    'type' => 'string',
                ],
                [
                    'method' => 'PUT',
                    'summary' => 'Puts a test',
                ],
            ],
        ];
        $builder->addApi($api);
        $description = new Description($builder->toArray());
        $ops = $description->getOperations();
        $this->assertCount(2, $ops, 'Wrong number of operations found');
        // test specified command name:
        $this->assertArrayHasKey('getTest', $ops);
        // test auto-geneated command name:
        $this->assertArrayHasKey('put_test', $ops);
        return $builder;
    }

    /**
     * Test operation parameters
     *
     * @depends testOperationAddition
     *
     * @param Swizzle $builder
     *
     * @return Swizzle
     *
     * @throws \Exception
     */
    public function testOperationParameters(Swizzle $builder)
    {
        // mock a Swagger API op with params 
        $api = [
            'path' => '/test/params',
            'operations' => [
                [
                    'parameters' => [
                        // simple path parameter
                        [
                            'name' => 'test',
                            'paramType' => 'path',
                            'defaultValue' => 'ok',
                            'type' => 'string',
                        ],
                        // model parameter containing $foo sent in request body
                        [
                            'name' => 'myFoo',
                            'type' => 'fooType',
                            'paramType' => 'body',
                        ],
                        // object literal containing $bar will get merged in with model
                        [
                            'name' => 'myBar',
                            'type' => 'object',
                            'paramType' => 'body',
                            'properties' => [
                                ['type' => 'string', 'name' => 'baz'],
                            ],
                        ],
                        // deliberately add a parameter that conflicts with request body property
                        [
                            'name' => 'bar',
                            'type' => 'string',
                            'paramType' => 'query',
                        ],
                    ],
                ],
            ],
        ];
        $builder->addApi($api);
        $description = new Description($builder->toArray());
        $operation = $description->getOperation('get_test_params');
        $param = $operation->getParam('test');
        $this->assertInstanceOf(Parameter::class, $param);
        $this->assertEquals('uri', $param->getLocation());
        $this->assertEquals('ok', $param->getDefault());
        $this->assertEquals('string', $param->getType());
        $this->assertTrue($param->isRequired());
        // The barType request body should have its properties merged into root
        $param = $operation->getParam('myBar');
        $this->assertNull($param);
        $child = $operation->getParam('baz');
        $this->assertInstanceOf(Parameter::class, $child);
        $this->assertEquals('json', $child->getLocation());
        // Conflicting bar query param would be reached before conflicting with request body
        $param = $operation->getParam('bar');
        $this->assertInstanceOf(Parameter::class, $param);
        $this->assertEquals('query', $param->getLocation());
        // The fooType request body  should have resolved to an object too
        $param = $operation->getParam('myFoo');
        $this->assertNull($param);
        // We will need to use *_json namespace to access it as it's conflicted.
        $child = $operation->getParam('bar_json');
        $this->assertInstanceOf(Parameter::class, $child);
        $this->assertEquals('json', $child->getLocation());

        return $builder;
    }

    /**
     * Test an operation that responds with the fooType model we added earlier
     *
     * @depends testModelAddition
     *
     * @param Swizzle $builder
     *
     * @throws \Exception
     */
    public function testModelResponse(Swizzle $builder)
    {
        // mock a Swagger API op that returns a fooType
        $api = [
            'path' => '/test/type',
            'operations' => [
                [
                    'type' => 'fooType',
                ],
            ],
        ];
        $builder->addApi($api);
        $description = new Description($builder->toArray());
        $operation = $description->getOperation('get_test_type');
        $this->assertEquals('fooType', $operation->getResponseModel());
    }

    /**
     * Test an operation that responds with a root object literal
     *
     * @depends testOperationAddition
     *
     * @param Swizzle $builder
     *
     * @throws \Exception
     */
    public function testObjectLiteralTranformsToModel(Swizzle $builder)
    {
        // mock a Swagger API op that returns a root type that is defined inline
        $api = [
            'path' => '/test/type_literal',
            'operations' => [
                [
                    'type' => 'object',
                    'properties' => [
                        'ok' => [
                            'type' => 'boolean',
                            'defaultValue' => true,
                        ],
                    ],
                ],
            ],
        ];
        $builder->addApi($api);
        $description = new Description($builder->toArray());
        $operation = $description->getOperation('get_test_type_literal');
        $this->assertStringStartsWith('anon_', $operation->getResponseModel());
    }

    /**
     * Test an operation that responds with an array of fooType objects
     *
     * @depends testModelAddition
     *
     * @throws \Exception
     */
    public function testModelArrayResponse(Swizzle $builder)
    {
        $api = [
            'path' => '/test/type_array',
            'operations' => [
                [
                    'type' => 'array',
                    'items' => [
                        '$ref' => 'fooType',
                    ],
                ],
            ],
        ];
        $builder->addApi($api);
        $description = new Description($builder->toArray());
        $operation = $description->getOperation('get_test_type_array');
        // root array modelling disabled - will be unvalidated primitive
        $this->assertEquals('fooTypeList', $operation->getResponseModel());
        $model = $description->getModel('fooTypeList');
        $this->assertEquals('array', $model->getType());
        $this->assertEquals('fooType', $model->getItems()->getName());
    }

    /**
     * Test an operation that responds with an array of primatives
     *
     * @depends testServiceConstruct
     *
     * @param Swizzle $builder
     *
     * @throws \Exception
     */
    public function testDynamicArrayResponse(Swizzle $builder)
    {
        $api = [
            'path' => '/test/type_ints',
            'operations' => [
                [
                    'type' => 'array',
                    'items' => [
                        'type' => 'integer',
                    ],
                ],
            ],
        ];
        $builder->addApi($api);
        $description = new Description($builder->toArray());
        $operation = $description->getOperation('get_test_type_ints');
        // anonymous model should have been created on the fly
        $this->assertEquals('anon_type_array_items_type_integer', $operation->getResponseModel());
        $model = $description->getModel('anon_type_array_items_type_integer');
        $this->assertEquals('array', $model->getType());
        $this->assertEquals('integer', $model->getItems()->getType());

    }

    /**
     * Test failure when response type falls back to an unregistered class
     *
     * @depends testServiceConstruct
     *
     * @expectedException \Exception
     *
     * @param Swizzle $builder
     *
     * @throws \Exception
     */
    public function testUnregisteredResponseClassFails(Swizzle $builder)
    {
        // mock an operation with a bad response class
        $api = [
            'path' => '/test/bad_class',
            'operations' => [
                [
                    'type' => 'BadClass',
                ],
            ],
        ];
        $builder->addApi($api);
    }

}








