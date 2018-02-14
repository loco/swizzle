<?php

namespace Loco\Tests\Utils\Swizzle;

use Loco\Utils\Swizzle\ModelCollection;

/**
 * @group utils
 */
class ModelCollectionTest extends \PHPUnit_Framework_TestCase
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

    public function testCollectRefs()
    {
        $refs = ModelCollection::collectRefs(self::$raw['bar']);
        $this->assertArrayHasKey('foo', $refs);
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
     * @expectedException \Loco\Utils\Swizzle\Exception\CircularReferenceException
     *
     * @param ModelCollection $collection
     *
     * @throws \Exception
     */
    public function testCircularReferenceFails(ModelCollection $collection)
    {
        $models = $collection->getData();
        // bar depends on foo, let foo also depend on bar
        $models['foo']['items']['$ref'] = 'bar';
        new ModelCollection($models);
    }

    /**
     * @depends testDependencyOrder
     *
     * @param ModelCollection $collection
     *
     * @throws \Exception
     */
    public function testSelfReferencePermitted(ModelCollection $collection)
    {
        $models = $collection->getData();
        // Let foo also depend upon itself.
        $models['foo']['items']['$ref'] = 'foo';
        new ModelCollection($models);
    }

}
