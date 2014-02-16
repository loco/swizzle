<?php
namespace Loco\Utils\Swizzle;

use Guzzle\Common\Collection;

/**
 * Sorts models by dependency order
 */
class ModelCollection extends Collection {
    
    /**
     * Construct collection from models indexed by name
     * @param array $models Associative array of data to set
     */
    public function __construct( array $models = array() ){
        parent::__construct();
        // build dependency graph and add missing id properties
        $graph = array();
        foreach( $models as $id => $model ){
            if( ! isset($model['id']) ){
                $models[$id]['id'] = $id;
            }
            $graph[$id] = self::collectRefs($model);
        }
        // Add items with no dependencies until no items have dependencies left
        while( $models ){
            foreach( array_keys($models) as $id ){
                if( empty($graph[$id]) ){
                    // add item with no dependencies to collection
                    $this->set( $id, $models[$id] );
                    unset( $models[$id] );
                    // remove added item from dependency list of others
                    foreach( $graph as $ref => $references ){
                        unset($graph[$ref][$id]);
                    }
                }
            }
        }
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