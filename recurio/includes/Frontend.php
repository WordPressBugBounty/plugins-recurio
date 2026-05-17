<?php
namespace Recurio;
use Recurio\Traits\Singleton;
/**
 * Frontend handlers class
 */
class Frontend {
    use Singleton;
    
    /**
     * Initialize the class
     */
    private function __construct() {
        $this->includes();
        $this->init();
    }

    public function includes(){
        if ( !class_exists( __NAMESPACE__ . '\Frontend\Customer_Portal'  ) ) {
            require_once( __DIR__. '/frontend/Customer_Portal.php' );
        }
    }

    /**
     * Initilize
     *
     * @return void
     */
    public function init() {
        Frontend\Customer_Portal::get_instance();
    }

}