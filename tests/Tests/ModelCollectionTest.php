<?php

namespace Loco\Tests\Utils\Swizzle;

use Loco\Utils\Swizzle\ModelCollection;

/**
 * @group utils
 */
class ModelCollectionTest extends \PHPUnit\Framework\TestCase
{

    // define a model that depends on a model
    private static $raw = [
        'foo' => [],
        'baz' => [
            'items' => [
                '$ref' => 'bar',
            ],
        ],
        'bar' => [
            'items' => [
                '$ref' => 'foo',
            ],
        ],
    ];

    /**
     * @return ModelCollection
     *
     * @throws \Exception
     */
    public function testConstruct()
    {
        $modelCollection = new ModelCollection(self::$raw);
        $this->assertCount(3, $modelCollection);
        return $modelCollection;
    }


    /**
     * @depends testConstruct
     *
     * @param ModelCollection $collection
     *
     * @return ModelCollection
     */
    public function testDependencyOrder(ModelCollection $collection)
    {
        $keys = array_keys($collection->getData());

        // test that "foo" comes before "bar"
        $lower = array_search('foo', $keys, true);
        $higher = array_search('bar', $keys, true);
        $this->assertGreaterThan($lower, $higher, '"foo" index expected to be lower than "bar"');

        // test that "bar" comes before "baz"
        $lower = array_search('bar', $keys, true);
        $higher = array_search('baz', $keys, true);
        $this->assertGreaterThan($lower, $higher, '"bar" index expected to be lower than "baz"');

        return $collection;
    }

    /**
     * @depends testDependencyOrder
     *
     * @param ModelCollection $collection
     */
    public function testCircularReferencePermitted(ModelCollection $collection)
    {
        $models = $collection->getData();
        // bar depends on foo, let foo also depend on bar
        $models['foo']['items']['$ref'] = 'bar';
        $modelCollection = new ModelCollection($models);
        $this->assertCount(3, $modelCollection);
    }

    /**
     * @depends testDependencyOrder
     *
     * @param ModelCollection $collection
     */
    public function testSelfReferencePermitted(ModelCollection $collection)
    {
        $models = $collection->getData();
        // Let foo also depend upon itself.
        $models['foo']['items']['$ref'] = 'foo';
        $modelCollection = new ModelCollection($models);
        $this->assertCount(3, $modelCollection);
    }
}
