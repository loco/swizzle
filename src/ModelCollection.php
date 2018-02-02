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
     * @param array $models Associative array of data to set
     * @throws \Exception if circular references cannot be resolved.
     */
    public function __construct( array $models = array() ){
        // build dependency graph and add missing id properties
        $graph = array();
        foreach( $models as $id => $model ){
            if( ! isset($model['id']) ){
                $models[$id]['id'] = $id;
            }
            $graph[$id] = self::collectRefs($model);
            // allow self-dependency
            unset( $graph[$id][$id] );
        }
        // Add items with no dependencies until no items have dependencies left
        while( $models ){
            $n = 0;
            foreach( array_keys($models) as $id ){
                if( empty($graph[$id]) ){
                    $n++;
                    // add item with no dependencies to collection
                    $this->set( $id, $models[$id] );
                    unset( $models[$id] );
                    // remove added item from dependency list of others
                    foreach( $graph as $ref => $references ){
                        unset($graph[$ref][$id]);
                    }
                }
            }
            if( ! $n ){
                throw new \Exception('circular references in model depenencies');
            }
        }
    }
    /**
     * Test if key was found in original JSON, even if empty
     *
     * @param string $key
     *
     * @return bool
     */
    protected function has($key)
    {
        return isset($this->data[$key]) || array_key_exists($key, $this->data);
    }

    /**
     * Get raw data value
     *
     * @param string $key
     *
     * @return mixed
     */
    protected function get($key)
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
    protected function set($key, $value)
    {
        $this->data[$key] = $value;

        return $this;
    }

    public function getIterator()
    {
        return new \ArrayIterator($this->data);
    }

    /**
     * @ignore 
     * Collect all models that a model references.
     */
    public static function collectRefs( array $model, array $refs = array() ){
        foreach( $model as $key => $val ){
            if( is_array($val) ){
                $refs = self::collectRefs( $val, $refs );
            }
            else if( '$ref' === $key ){
                $refs[$val] = 1;
            }
        }
        return $refs;
    }    
    
    
}