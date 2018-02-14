<?php

namespace Loco\Utils\Swizzle;

use GuzzleHttp\Client;
use GuzzleHttp\Command\Guzzle\Description;
use GuzzleHttp\Command\Guzzle\GuzzleClient;
use Loco\Utils\Swizzle\Result\ApiDeclaration;
use Loco\Utils\Swizzle\Result\ResourceListing;

/**
 * Client for pulling Swagger docs
 *
 * @method ResourceListing getResources(array $args = [])
 * @method ApiDeclaration getDeclaration(array $args = [])
 */
class SwaggerClient extends GuzzleClient
{
    /**
     * Factory method to create a new Swagger Docs client.
     *
     * @param array $config Configuration data
     *
     * @return SwaggerClient
     *
     * @throws \InvalidArgumentException
     */
    public static function factory(array $config = [])
    {
        // Swagger docs URI is required
        $required = ['base_uri'];
        if ($missing = array_diff($required, array_keys($config))) {
            throw new \InvalidArgumentException('Config is missing the following keys: '.implode(', ', $missing));
        }

        $serviceConfig = json_decode(file_get_contents(__DIR__.'/Resources/service.json'), true);
        // allow override of base_uri after it's been set by service description
        if (isset($config['base_uri'])) {
            $serviceConfig['baseUri'] = $config['base_uri'];
        }
        // describe service from included config file.
        $description = new Description($serviceConfig);

        // Prefix Loco identifier to user agent string
        $config['headers']['User-Agent'] = $description->getName().'/'.$description->getApiVersion()
            .' '.\GuzzleHttp\default_user_agent();

        // Create a new instance of HTTP Client
        $client = new Client($config);

        // Create a new instance of self
        return new self(
            $client,
            $description,
            null,
            new Deserializer($description, true)
        );
    }

}

