<?php

namespace Loco\Utils\Swizzle\Result;

use Loco\Utils\Swizzle\ModelCollection;

/**
 * Response class for Swagger API declaration
 */
class ApiDeclaration extends BaseResponse
{
    /**
     * Get basePath specified outside of api operations
     *
     * @return string
     */
    public function getBasePath()
    {
        return $this->get('basePath') ?: '';
    }

    /**
     * Get resourcePath specified outside of api operations
     *
     * @return string
     */
    public function getResourcePath()
    {
        return $this->get('resourcePath') ?: '';
    }

    /**
     * Get model definitions
     *
     * @return ModelCollection
     * @throws \Loco\Utils\Swizzle\Exception\CircularReferenceException
     */
    public function getModels()
    {
        $models = $this->get('models') ?: [];
        return new ModelCollection($models);
    }

}
