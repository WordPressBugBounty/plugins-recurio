<?php
namespace Recurio\Traits;

trait Singleton {

    private static $_instance = null;
    /**
     * Get Instance
     */
    public static function get_instance(){
        if( is_null( self::$_instance ) ){
            self::$_instance = new self();
        }
        return self::$_instance;
    }


}
