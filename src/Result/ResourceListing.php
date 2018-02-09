<?php

namespace Loco\Utils\Swizzle\Result;

/**
 * Response class for Swagger resource listing
 */
class ResourceListing extends BaseResponse
{
    /**
     * Get info field, comprising title and description
     * @return array
     */
    public function getInfo()
    {
        $defaults = ['title' => '', 'description' => ''];
        $info = $this->get('info') ?: [];
        return array_intersect_key($info, $defaults) + $defaults;
    }

}
