<?php
namespace Loco\Utils\Swizzle\Response;

use Guzzle\Service\Command\OperationCommand;


/**
 * Response class for Swagger resource listing
 */
class ResourceListing extends BaseResponse {


    /**
     * @internal Create a response model object from a completed command
     * @param OperationCommand Command that serialized the request
     * @throws \Guzzle\Http\Exception\BadResponseException 
     * @return ResourceListing
     */
    public static function fromCommand( OperationCommand $command ) {
        return new ResourceListing( $command->getResponse() );
    }
    
    
    /**
     * Get info field, comprising title and description
     * @return array
     */
    public function getInfo(){
        $defaults = array( 'title' => '', 'description' => '' );
        $info = $this->get('info')?:array();
        return array_intersect_key( $info, $defaults ) + $defaults;
    }     
    
    
}