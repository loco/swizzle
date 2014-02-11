<?php
namespace Loco\Utils\Swizzle;

use Guzzle\Common\Collection;

class ModelCollection extends Collection {
    
    /**
     * 
     */
    public function __construct( array $data = array() ){
        foreach( $data as $id => $model ){
            if( ! isset($model['id']) ){
                $data[$id]['id'] = $id;
            }
        }
        uasort( $data, array( $this, 'onDependencySort' ) );
        parent::__construct( $data );
    }
    
    
    
    
    private function onDependencySort( array $a, array $b ){
        // check if B depends on A
        $deps = self::collectRefs( $b );
        if( isset($deps[$a['id']]) ){
            // yes, A is in B's dependency list. A must go first
            return -1;
        }
        // check if A depends on B
        $deps = self::collectRefs( $a );
        if( isset($deps[$b['id']]) ){
            // yes, B is in A's dependency list. B must go first
            return 1;
        }
        return 0;
    }
         
    
    
    /**
     * Collect all models that a model references
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