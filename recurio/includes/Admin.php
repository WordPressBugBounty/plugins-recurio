<?php
namespace Recurio;
use Recurio\Traits\Singleton;
use Recurio\Api;

/**
 * Admin class
 */
class Admin {

    use Singleton;

    /**
     * Initialize the class
     */
    public function __construct() {
        $this->remove_all_notices();
        $this->includes();
        $this->init();
    }

     /**
     * Include the controller classes
     *
     * @return void
     */
    private function includes() {
        if ( !class_exists( __NAMESPACE__ . '\admin\Menu'  ) ) {
            require_once __DIR__ . '/admin/Menu.php';
        }
        if ( !class_exists( __NAMESPACE__ . '\admin\Dashboard_Widget'  ) ) {
            require_once __DIR__ . '/admin/Dashboard_Widget.php';
        }
        if ( !class_exists( __NAMESPACE__ . '\admin\Pro_Upsell'  ) ) {
            require_once __DIR__ . '/admin/Pro_Upsell.php';
        }
        if ( !class_exists( 'Recurio\Api\Plans' ) ) {
            require_once __DIR__ . '/api/Plans.php';
        }
        if ( !class_exists( 'Recurio\Api\ChangeLog' ) ) {
            require_once __DIR__ . '/api/ChangeLog.php';
        }
    }

    /**
     * Admin Initilize
     *
     * @return void
     */
    public function init() {
        (new Admin\Menu())->init();
        (new Admin\Dashboard_Widget())->init();
        (new Admin\Pro_Upsell())->init();
        (new Api\Plans())->register_hooks();
        (new Api\ChangeLog())->register_hooks();
    }

    /**
     * [remove_all_notices] remove addmin notices
     * @return [void]
     */
    public function remove_all_notices(){
        add_action('in_admin_header', function (){
            $screen = get_current_screen();
            if ($screen && strpos($screen->id, 'recurio') !== false) {
                remove_all_actions('admin_notices');
                remove_all_actions('all_admin_notices');
            }
       }, 1000);
    }

}