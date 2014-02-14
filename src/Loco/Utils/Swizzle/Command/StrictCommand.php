<?php

namespace Loco\Utils\Swizzle\Command;

use Guzzle\Service\Resource\Model;
use Guzzle\Service\Command\AbstractCommand;
use Guzzle\Service\Command\OperationCommand;
use Guzzle\Service\Description\SchemaValidator;
use Guzzle\Service\Exception\ValidationException;
use Guzzle\Service\Command\OperationResponseParser;

/**
 * Operation command that validates response models.
 */
class StrictCommand extends OperationCommand {
    
    
    /**
     * @override
     * Validate response model after processing
     */
    protected function process(){
        parent::process();
        if( $this[ AbstractCommand::DISABLE_VALIDATION] ){
            // skipping validation in all cases
            return;
        }
        if( ! $this->result instanceof Model ){
            // result is not a model - no way to validate
            return; 
        }
        $errors = array();
        $validator = SchemaValidator::getInstance();
        // validate parameters present
        $schema = $this->result->getStructure();
        $value = $this->result->toArray();
        if( ! $validator->validate( $schema, $value ) ){
            $errors = $validator->getErrors();
        }
        // @todo validate additional parameters?
        // $schema->getAdditionalProperties() );
        if( $errors ){
            $e = new ValidationException('Response failed model validation: ' . implode("\n", $errors) );
            $e->setErrors( $errors );
            throw $e;
        }
    }        
    
    
    
    /**
     * @override
     * Get the overridden response parser used for the schema-aware operation
     * @return ResponseParserInterface
     */
    public function getResponseParser(){
        if( ! $this->responseParser ) {
            // Use our overridden response parser that injects models into schemas
            $this->responseParser = StrictResponseParser::getInstance();
        }
        return $this->responseParser;
    }    
    
    
    
}