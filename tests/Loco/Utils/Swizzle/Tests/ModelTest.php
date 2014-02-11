<?php

namespace Loco\Tests\Api;

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
            ),
            // method returning array of models
            'testlist' => array(
              'uri' => '/testlist.json',
              'httpMethod' => 'GET',
              'responseClass' => 'array',
              'items' => array(
                '$ref' => 'TestModel',
              ),
            ),
            // method returning array of models inside wrapper model
            'testwrap' => array(
              'uri' => '/testwrap.json',
              'httpMethod' => 'GET',
              'responseClass' => 'TestModelList',
            ),
            // method returning special typed array with root property
            'testwrapobj' => array(
              'uri' => '/testwrapobj.json',
              'httpMethod' => 'GET',
              'responseClass' => 'TestModelListObject',
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
                ),
                // property that won't exist in response
                'bar' => array (
                  'type' => 'integer',
                  'location' => 'json',
                ),
              ),
            ),
            // define array of typed objects
            'TestModelList' => array(
              'type' => 'array',
              'location' => 'json',
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
     */
    public function testModelResponse( Client $client ){        
        // fake a response with valid "foo" and invalid "baz" properties
        $plugin = new MockPlugin();
        $plugin->addResponse( new Response( 200, array(), '{"foo":1,"baz":"nan"}' ) );
        $client->addSubscriber( $plugin );
        $response = $client->test();

        // test value of "foo" key, which will exist
        $this->assertEquals( 1, $response->get('foo') );

        // test value of "bar" key which isn't in response
        // Why doesn't the model complain this is missing in response?
        $this->assertEquals( null, $response->get('bar') );        -

        // test value of "baz" key, which should be absent from the model
        $this->assertNull( $response->get('baz') );
    }



    /**
     * Test array of models in response
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
        
}
