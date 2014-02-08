<?php

namespace Loco\Utils\Swizzle\Tests;

use Guzzle\Tests\GuzzleTestCase;
use Guzzle\Service\Builder\ServiceBuilder;
use Guzzle\Http\Message\Response;
use Guzzle\Plugin\Mock\MockPlugin;
use Loco\Utils\Swizzle\SwaggerClient;

/**
 * Tests SwaggerClient
 */
class SwaggerClientTest extends GuzzleTestCase {
    
    /**
     * Mock resource listing JSON
     * @var string
     */
    private $resourcesJson;
    
    /**
     * Mock API declaration JSON
     * @var string
     */
    private $declarationJson;
    
    /**
     * Set up test with a fake Api consisting of a single /ping method
     */
    public function setUp(){
        // define fake resource listing
        $this->resourcesJson = json_encode( array (
            'apiVersion' => '1.0',
            'apis' => array(
                array (
                    'path' => '/ping',
                ),
            ),
        ) );
        // define fake /test endpoint
        $this->declarationJson = json_encode( array(
            'resourcePath' => '/ping',
            // single api with a single operation
            'apis' => array(
                array (
                    'path' => '/ping',
                    'operations' => array(
                        array(
                            'method' => 'GET',
                            'nickname' => 'ping',
                            'type' => 'Echo',
                        ),
                    ),
                ),
            ),
            // single Echo model that would look like { "pong" : "" }
            'models' => array (
                'Echo' => array (
                    'id' => 'Echo',
                    'properties' => array (
                        'pong' => array (
                            'type' => 'string',
                        ),
                    ),
                ),
            ),
        ) );
    }
    
    
    
    /**
     * @covers Loco\Utils\Swizzle\SwaggerClient::factory
     * @returns SwaggerClient
     */
    public function testFactory(){
        $base_url = 'https://localise.biz/api/docs';
        $client = SwaggerClient::factory( compact('base_url') );
        $this->assertEquals( $base_url, $client->getBaseUrl(), 'base_url not passed to client' );
        return $client;
    }
    
    
    
    /**
     * @group mock
     * @depends testFactory
     * @returns SwaggerClient
     */
    public function testMockResourceListing( SwaggerClient $client ){
        $plugin = new MockPlugin();
        $plugin->addResponse( new Response( 200, array(), $this->resourcesJson ) );
        $client->addSubscriber( $plugin );
        $listing = $client->getResources();
        $this->assertInstanceOf('\Loco\Utils\Swizzle\Response\ResourceListing', $listing );
        $this->assertEquals( '1.0', $listing->getApiVersion() );
        $paths = $listing->getApiPaths();
        $this->assertcount( 1, $paths );
        $this->assertEquals( '/ping', $paths[0] );
    }    



    /**
     * @group mock
     * @depends testFactory
     * @returns SwaggerClient
     */
    public function testMockApiDeclaration( SwaggerClient $client ){
        $plugin = new MockPlugin();
        $plugin->addResponse( new Response( 200, array(), $this->declarationJson ) );
        $client->addSubscriber( $plugin );
        $declaration = $client->getDeclaration( array(
            'path' => '/ping',
        ) );
        $this->assertInstanceOf('\Loco\Utils\Swizzle\Response\ApiDeclaration', $declaration );
        $this->assertEquals( '/ping', $declaration->getResourcePath() );
        // Should have one API
        $apis = $declaration->getApis();
        $this->assertCount( 1, $apis );
        // Should have one model
        $models = $declaration->getModels();
        $this->assertCount( 1, $models );
        $this->assertArrayHasKey( 'Echo', $models );
    }    
    
    
        

}
