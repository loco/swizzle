<?php

namespace Loco\Tests\Utils\Swizzle;

use Guzzle\Http\Message\Response;
use Guzzle\Plugin\Mock\MockPlugin;
use Guzzle\Service\Client;
use Guzzle\Service\Description\ServiceDescription;
use Guzzle\Service\Description\Parameter;
use Guzzle\Service\Resource\Model;
use Guzzle\Tests\GuzzleTestCase;

/**
 * Tests Guzzle's internal modelling logic.
 * @group model
 */
class ModelTest extends GuzzleTestCase {

    /**
     * @return Client
     */
    public function testClientConstruct(){

        // Define a service with a test() method
        $service = new ServiceDescription( array(
          'name' => 'test-service',
          'operations' => array (
            // method returning single model
            'test' => array (
              'uri' => '/test.json',
              'httpMethod' => 'GET',
              'responseClass' => 'TestModel',
              'class' => '\\Loco\\Utils\\Swizzle\\Command\\StrictCommand',
            ),
            // method returning array of models
            'testlist' => array(
              'uri' => '/testlist.json',
              'httpMethod' => 'GET',
              'responseClass' => 'array',
              'class' => '\\Loco\\Utils\\Swizzle\\Command\\StrictCommand',
              'items' => array(
                '$ref' => 'TestModel',
              ),
            ),
            // method returning array of models inside wrapper model
            'testwrap' => array(
              'uri' => '/testwrap.json',
              'httpMethod' => 'GET',
              'responseClass' => 'TestModelList',
              'class' => '\\Loco\\Utils\\Swizzle\\Command\\StrictCommand',
            ),
            // method returning special typed array with root property
            'testwrapobj' => array(
              'uri' => '/testwrapobj.json',
              'httpMethod' => 'GET',
              'responseClass' => 'TestModelListObject',
              'class' => '\\Loco\\Utils\\Swizzle\\Command\\StrictCommand',
            ),
          ),
          'models' => array (
            'TestModel' => array (
              'type' => 'object',
              'additionalProperties' => false,
              'properties' => array (
                // property that will exist in response
                'foo' => array (
                  'type' => 'integer',
                  'location' => 'json',
                  'required' => true,
                ),
                // property that won't exist in response
                'bar' => array (
                  'type' => 'integer',
                  'location' => 'json',
                  'required' => false,
                ),
              ),
            ),
            // define array of typed objects
            'TestModelList' => array(
              'type' => 'array',
              'items' => array(
                '$ref' => 'TestModel',
              ),
            ),
            // define root object with array property
            'TestModelListObject' => array(
              'type' => 'object',
              'additionalProperties' => false,
              'properties' => array(
                'list' => array(
                  'type' => 'array',
                  'location' => 'json', // <- critical
                  'items' => array(
                    '$ref' => 'TestModel',
                  ),
                ),
              ),
            ),
          ),
        ) );

        $client = new Client;
        $client->setDescription( $service );

        // test models are defined ok
        $op = $service->getOperation('test');
        $this->assertEquals('model', $op->getResponseType() );
        $this->assertEquals('TestModel', $op->getResponseClass() );
        // listing is just an array
        $op = $service->getOperation('testlist');
        $this->assertEquals('primitive', $op->getResponseType() );
        $this->assertEquals('array', $op->getResponseClass() );
        
        // test listing is aware of models in itself
        // It's not. Operation has no 'items' property
        
        // list wrapper is a model 
        $op = $service->getOperation('testwrap');
        $this->assertEquals('model', $op->getResponseType() );
        $this->assertEquals('TestModelList', $op->getResponseClass() );
        
        // test model resolved from $ref
        $listModel = $service->getModel('TestModelList');
        $this->assertInstanceOf('\Guzzle\Service\Description\Parameter', $listModel );        
        // items should be single schema specifying one allowed model type
        $items = $listModel->getItems();
        $this->assertInstanceOf('\Guzzle\Service\Description\Parameter', $items );        
        $this->assertEquals('object', $items->getType() );
        $this->assertArrayHasKey('foo', $items->getProperties() );

        return $client;
    }



    /**
     * Test single model response
     * @depends testClientConstruct
     * @group mock
     */
    public function testModelResponseValidates( Client $client ){        
        // fake a response with valid "foo" and legally missing "bar" property
        $plugin = new MockPlugin();
        $plugin->addResponse( new Response( 200, array(), '{"foo":1}' ) );
        $client->addSubscriber( $plugin );
        $response = $client->test();
        // test value of "foo" key, which will exist
        $this->assertEquals( 1, $response->get('foo') );
        // test value of "bar" key which isn't in response
        $this->assertEquals( null, $response->get('bar') );
    }



    /**
     * Test single model response that fails validation
     * @depends testClientConstruct
     * @group mock
     * @expectedException \Guzzle\Service\Exception\ValidationException
     */
    public function testModelResponseFailure( Client $client ){        
        // fake a response with missing required property and extra invalid one
        $plugin = new MockPlugin();
        $plugin->addResponse( new Response( 200, array(), '{"baz":1}' ) );
        $client->addSubscriber( $plugin );
        $response = $client->test();
    }



    /**
     * Test array of models in response
     * @group mock
     * @depends testClientConstruct
     */
    public function testArrayResponse( Client $client ){        
        // fake a response with multiple valid objects
        $plugin = new MockPlugin();
        $plugin->addResponse( new Response( 200, array(), '[{"foo":1},{"foo":2},{"foo":3}]' ) );
        $client->addSubscriber( $plugin );
        $response = $client->testlist();

        // response is a plain Response object
        $this->assertInstanceof('Guzzle\Http\Message\Response', $response );

        // test response has contains items
        $data = $response->json();
        $this->assertCount( 3, $data );
        
        // test if array items are models
        //$this->assertInstanceOf('\Guzzle\Service\Resource\Model', $data[0] );

        // They're not - they're arrays!
        $this->assertInternalType('array', $data[0] );
        $this->assertArrayHasKey('foo', $data[0] );
    }



    /**
     * Test array of models in response defined with wrapper model 
     * @group mock
     * @depends testClientConstruct
     */
    public function testModelListResponse( Client $client ){        
        // fake a response with multiple valid objects
        $plugin = new MockPlugin();
        $plugin->addResponse( new Response( 200, array(), '[{"foo":3},{"foo":4},{"foo":5}]' ) );
        $client->addSubscriber( $plugin );
        $response = $client->testwrap();

        // test response is a model
        $this->assertInstanceof('\Guzzle\Service\Resource\Model', $response );
        
        $data = $response->toArray();
        //$this->assertCount( 3, $data ); // <- fails
        $this->assertCount( 0, $data );
    }



    /**
     * Test array of models in response defined with wrapper model containing an array property
     * @group mock
     * @depends testClientConstruct
     */
    public function testModelListObjectResponse( Client $client ){        
        // fake a response with multiple valid objects
        $plugin = new MockPlugin();
        $plugin->addResponse( new Response( 200, array(), '{"list":[{"foo":4},{"foo":5},{"foo":6}]}' ) );
        $client->addSubscriber( $plugin );
        $response = $client->testwrapobj();

        // test response is a model
        $this->assertInstanceof('\Guzzle\Service\Resource\Model', $response );

        // test response has 3 items
        $list = $response->get('list');
        $this->assertCount( 3, $list );
    }
    
    
    
    /**
     * #depends testClientConstruct
     * https://github.com/guzzle/guzzle/issues/581
     *
    public function testModelRecursion( Client $client ){
        // define a model with a property that is a list of the same model
        $raw = array (
          'name' => 'TestRecursion',
          'type' => 'object',
          'properties' => array(
            'recurse' => array(
              'type' => 'array',
              'items' => array(
                '$ref' => 'TestRecursion',
              ),
            ),
          ),
        );
        $desc = $client->getServiceDescription();
        $model = new Parameter( $raw, $desc );
        //$model->toArray(); // <- [1] call this now leaves items empty
        $desc->addModel( $model );
        $model->toArray(); // <- [2] this would infinitely loop without call [1]
    }*/
         
}


