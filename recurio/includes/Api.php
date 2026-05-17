<?php
namespace Recurio;
use Recurio\Traits\Singleton;
use WP_REST_Controller;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * API Handler Class
 */
class Api extends WP_REST_Controller {
    use Singleton;

    /**
     * [__construct description]
     */
    public function __construct() {
        $this->includes();

        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    /**
     * Include the controller classes
     *
     * @return void
     */
    private function includes() {
        if ( !class_exists( __NAMESPACE__ . '\Api\Plans'  ) ) {
            require_once __DIR__ . '/api/Plans.php';
        }
        if ( !class_exists( __NAMESPACE__ . '\Api\Subscriptions'  ) ) {
            require_once __DIR__ . '/api/Subscriptions.php';
        }
        if ( !class_exists( __NAMESPACE__ . '\Api\ChangeLog'  ) ) {
            require_once __DIR__ . '/api/ChangeLog.php';
        }
        if ( !class_exists( __NAMESPACE__ . '\Api\ImportExport'  ) ) {
            require_once __DIR__ . '/api/ImportExport.php';
        }
        if ( !class_exists( __NAMESPACE__ . '\Api\Settings'  ) ) {
            require_once __DIR__ . '/api/Settings.php';
        }
    }

    /**
     * Register the API routes
     *
     * @return void
     */
    public function register_routes() {
        (new Api\Plans())->register_routes();
        (new Api\Subscriptions())->register_routes();
        (new Api\ChangeLog())->register_routes();
        (new Api\ImportExport())->register_routes();
        (new Api\Settings())->register_routes();
    }

}
