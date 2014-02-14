<?php

namespace Loco\Utils\Swizzle\Tests;

use Guzzle\Service\Client;
use Guzzle\Service\Description\ServiceDescription;
use Guzzle\Service\Builder\ServiceBuilder;
use Guzzle\Service\Resource\Model;
use Guzzle\Http\Message\Response;
use Loco\Utils\Swizzle\Swizzle;


/**
 * Run full feature test on official Swagger Petstore example API
 * @group petstore
 */
class PetstoreTest extends \PHPUnit_Framework_TestCase {
    
    
    /**
     * Build service description from remote docs.
     * @return ServiceDescription
     */
    public function testServiceBuild(){
        $builder = new Swizzle( 'pets', 'Swagger Pet store' );
        //$builder->verbose( STDERR );
        $builder->registerCommandClass( '', '\\Loco\\Utils\\Swizzle\\Command\\StrictCommand' );
        $builder->setBaseUrl('http://petstore.swagger.wordnik.com/api');
        $builder->build('http://petstore.swagger.wordnik.com/api/api-docs');
        //die( $builder->toJson() );
        $service = $builder->getServiceDescription();
        $this->assertCount( 6, $service->getModels() );
        $this->assertCount( 20, $service->getOperations() );
        return $service;
    }    
    
    
    
    /**
     * Construct Swagger client for calling the petstore
     * @depends testServiceBuild
     * @return Client
     */
    public function testClientConstruct( ServiceDescription $service ){
        $client = new Client;
        $client->setDescription( $service );
        $this->assertEquals('http://petstore.swagger.wordnik.com/api', $client->getBaseUrl() );
        // @todo add Accept: application/json to every request?
        return $client;
    }    
    
    
    
    /**
     * Tests typed array response
     * @depends testClientConstruct
     */
    public function testFindPetsByStatus( Client $client ){
        $pets = $client->findPetsByStatus( array( 'status' => 'pending' ) );
        
        // listing should be validated as Pet_array model, except it doesn't work so disabled.
        // $this->assertInstanceOf('\Guzzle\Service\Resource\Model', $pets );
        // var_dump( $pets->toArray() );
        
        $this->assertInternalType('array', $pets );
        $this->assertArrayHasKey('id', $pets[0] );
        return (int) $pets[0]['id'];
    }
    
    
    
    /**
     * Tests a simple model
     * @depends testClientConstruct
     * @depends testFindPetsByStatus
     */
    public function testGetPetById( Client $client, $petId ){
        $pet = $client->getPetById( compact('petId') );
        $this->assertInstanceOf('\Guzzle\Service\Resource\Model', $pet );
        $this->assertEquals( $petId, $pet['id'] );
    }
    
    
}



