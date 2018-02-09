<?php

namespace Loco\Utils\Swizzle\Result;

use Psr\Http\Message\ResponseInterface;

interface ClassResultInterface
{
    /**
     * @param ResponseInterface $response
     *
     * @return ClassResultInterface
     */
    public static function fromResponse(ResponseInterface $response);
}
