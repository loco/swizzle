<?php

namespace Loco\Utils\Swizzle;

/**
 * Sorts models by dependency order
 */
class ModelCollection implements \IteratorAggregate
{
    /**
     * @var array
     */
    protected $data = [];

    /**
     * Construct collection from models indexed by name
     *
     * @param array $models Associative array of data to set
     */
    public function __construct(array $models = [])
    {
        // build dependency graph for sorting model order
        $graph = new \ArrayObject;
        // Set initial models filling in empty id fields
        foreach ($models as $id => $model) {
            if (!isset($model['id'])) {
                $model['id'] = $id;
                $models[$id] = $model;
            }
            self::collectDependencies($graph, $model);
        }
        // Add internal "object" model as Swagger models probably didn't define it
        if ($graph->offsetExists('object') && ! isset($models['object'])) {
            $models['object'] = [
                'id' => 'object',
                'properties' =>  [],
                'description' => 'Generic object',
            ];
        }
        // Sort into dependency order
        $sorted = [];
        foreach ($graph as $ref => $deps) {
            // find slot for ref where it comes before everything else that depends on it
            foreach ($sorted as $i => $parent) {
                // is reference required by this parent?
                if (self::checkDependency($graph, $parent, $ref)) {
                    array_splice($sorted, $i, 0, $ref);
                    continue 2;
                }
            }
            // safe to append
            $sorted[] = $ref;
        }
        // add models in sorted dependency order
        foreach ($sorted as $id) {
            $this->set($id, $models[$id]);
        }
    }

    /**
     * @return array
     */
    public function getData()
    {
        return $this->data;
    }

    /**
     * Test if key was found in original JSON, even if empty
     *
     * @param string $key
     *
     * @return bool
     */
    public function has($key)
    {
        return array_key_exists($key, $this->data);
    }

    /**
     * Get raw data value
     *
     * @param string $key
     *
     * @return mixed
     */
    public function get($key)
    {
        return isset($this->data[$key]) ? $this->data[$key] : null;
    }

    /**
     * Get raw data value
     *
     * @param string $key
     * @param mixed $value
     *
     * @return ModelCollection
     */
    public function set($key, $value)
    {
        $this->data[$key] = $value;

        return $this;
    }

    public function getIterator()
    {
        return new \ArrayIterator($this->data);
    }


    /**
     * @param \ArrayObject
     * @param string parent/outer reference
     * @param string child/inner reference
     * @param array
     * @return bool
     */
    public static function checkDependency(\ArrayObject $graph, $parent, $child, array $recursion = [])
    {
        $root = $graph->offsetGet($parent);
        if ($root instanceof \ArrayObject) {
            // check immediate child
            if ($root->offsetExists($child)) {
                return true;
            }
            // descend without risk of infinite recursion
            foreach (array_keys($root->getArrayCopy()) as $next) {
                if (isset($recursion[$next])) {
                    continue;
                }
                $recursion[$next] = true;
                if (self::checkDependency($graph, $next, $child, $recursion)) {
                    return true;
                }
            }
        }
        return false;
    }


    /**
     * @param \ArrayObject dependency graph
     * @param array model data
     * @return \ArrayObject
     */
    public static function collectDependencies(\ArrayObject $graph, array $model)
    {
        $id = $model['id'];
        // start with top level reference to current model
        if (! $graph->offsetExists($id)) {
            $graph->offsetSet($id, new \ArrayObject);
        }
        self::crawlDependencies($graph, $model, $id);
        return $graph;
    }


    /**
     * @param \ArrayObject dependency graph
     * @param array current array data being crawled
     * @param string top-level key
     * @return void
     */
    private static function crawlDependencies(\ArrayObject $graph, array $parent, $id)
    {
        foreach ($parent as $key => $child) {
            // $ref => "reference"
            if ('$ref' === $key && is_string($child)) {
                // every reference needs an entry on the top level graph pointing to itself
                if (! $graph->offsetExists($child)) {
                    $graph->offsetSet($child, new \ArrayObject);
                }
                // add reference to its parent as a dependency
                $parent = $graph->offsetGet($id);
                if ($parent instanceof \ArrayObject) {
                    $parent->offsetSet($child, $graph->offsetGet($child));
                } else {
                    throw new \LogicException(sprintf("Can't add %s as dependency without %s entry on graph", $child, $id));
                }
            }
            // else recurse into whatever this is. e.g. "items" => [ $ref ....
            elseif (is_array($child)) {
                self::crawlDependencies($graph, $child, $id);
            }
        }
    }
}
