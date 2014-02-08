<?php
namespace Loco\Utils\Swizzle\Response;

use Guzzle\Service\Command\OperationCommand;


/**
 * Response class for Swagger API declaration
 */
class ApiDeclaration extends BaseResponse {


    /**
     * Create a response model object from a completed command
     * @internal
     * @param OperationCommand Command that serialized the request
     * @throws \Guzzle\Http\Exception\BadResponseException 
     * @return ApiDeclaration
     */
    public static function fromCommand( OperationCommand $command ) {
        return new ApiDeclaration( $command->getResponse() );
    }
    
    
    /**
     * Get basePath sepcified outside of api operations
     * @return string
     */
    public function getBasePath(){
        return $this->get('basePath')?:'';
    }
    
    
    /**
     * Get resourcePath sepcified outside of api operations
     * @return string
     */
    public function getResourcePath(){
        return $this->get('resourcePath')?:'';
    }
    

    
    /**
     * Get model definitions
     * @return array
     */
    public function getModels(){
        return $this->get('models')?:array();
    }
    
}