<?php

namespace Loco\Tests\Utils\Swizzle;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Loco\Utils\Swizzle\Result\ApiDeclaration;
use Loco\Utils\Swizzle\Result\ResourceListing;
use Loco\Utils\Swizzle\SwaggerClient;

/**
 * Tests SwaggerClient
 * @group swagger
 */
class SwaggerClientTest extends \PHPUnit\Framework\TestCase
{
    const BASE_URI = 'https://localise.biz/api/docs';

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
    public function setUp()
    {
        // define fake resource listing
        $this->resourcesJson = json_encode([
            'apiVersion' => '1.0',
            'apis' => [
                [
                    'path' => '/ping',
                ],
            ],
        ]);
        // define fake /test endpoint
        $this->declarationJson = json_encode([
            'resourcePath' => '/ping',
            // single api with a single operation
            'apis' => [
                [
                    'path' => '/ping',
                    'operations' => [
                        [
                            'method' => 'GET',
                            'nickname' => 'ping',
                            'type' => 'Echo',
                        ],
                    ],
                ],
            ],
            // single Echo model that would look like { "pong" : "" }
            'models' => [
                'Echo' => [
                    'id' => 'Echo',
                    'properties' => [
                        'pong' => [
                            'type' => 'string',
                        ],
                    ],
                ],
            ],
        ]);
    }

    private function createClient(array $config = [])
    {
        $defaults = [
            'base_uri' => 'https://localise.biz/api/docs'
        ];
        /** @noinspection AdditionOperationOnArraysInspection */
        return SwaggerClient::factory($config + $defaults);

    }

    /**
     * @covers \Loco\Utils\Swizzle\SwaggerClient::factory
     */
    public function testFactory()
    {
        $client = $this->createClient();
        $this->assertEquals(static::BASE_URI, $client->getDescription()->getBaseUri()->__toString(), 'base_url not passed to description');
        $config = $client->getHttpClient()->getConfig();
        $this->assertArrayHasKey('base_uri', $config);
        $this->assertEquals(static::BASE_URI, $config['base_uri'], 'base_url not passed to client');
    }

    /**
     * @group mock
     */
    public function testMockResourceListing()
    {
        $response = new Response(200, [], $this->resourcesJson);
        $handlerStack = MockHandler::createWithMiddleware([$response]);
        $client = $this->createClient(['handler' => $handlerStack]);

        /** @var ResourceListing $listing */
        $listing = $client->getResources();
        $this->assertInstanceOf(ResourceListing::class, $listing);
        $this->assertEquals('1.0', $listing->getApiVersion());
        $paths = $listing->getApiPaths();
        $this->assertCount(1, $paths);
        $this->assertEquals('/ping', $paths[0]);
    }

    /**
     * @group mock
     * @throws \Loco\Utils\Swizzle\Exception\CircularReferenceException
     */
    public function testMockApiDeclaration()
    {
        $response = new Response(200, [], $this->declarationJson);
        $handlerStack = MockHandler::createWithMiddleware([$response]);
        $client = $this->createClient(['handler' => $handlerStack]);

        $declaration = $client->getDeclaration([
            'path' => '/ping',
        ]);
        $this->assertInstanceOf(ApiDeclaration::class, $declaration);
        $this->assertEquals('/ping', $declaration->getResourcePath());
        // Should have one API
        $apis = $declaration->getApis();
        $this->assertCount(1, $apis);
        // Should have one model
        $models = $declaration->getModels();
        $this->assertCount(1, $models);
        $this->assertArrayHasKey('Echo', $models->getData());
    }

}
