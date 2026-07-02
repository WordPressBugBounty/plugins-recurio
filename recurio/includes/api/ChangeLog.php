<?php
namespace Recurio\Api;

use WP_REST_Server;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Changelog API
 *
 * Handles changelog data, per-user read tracking, and unread notifications.
 * Replaces class-changelog-manager.php (singleton) with a self-contained
 * API class that owns both the business logic and the REST routes.
 *
 * Routes registered:
 *   GET  /recurio/v1/changelog
 *   POST /recurio/v1/changelog/mark-read
 *   GET  /recurio/v1/changelog/unread-count
 *
 * @package Recurio
 * @since   1.2.0
 */
class ChangeLog
{

    /** REST namespace. */
    private string $namespace = 'recurio/v1';

    /** REST base. */
    private string $rest_base = 'changelog';

    /**
     * User-meta key that stores the highest version the user has read.
     */
    private const READ_META_KEY = 'recurio_changelog_read';

    // -------------------------------------------------------------------------
    // Bootstrap
    // -------------------------------------------------------------------------

    public function register_hooks()
    {
        add_action('admin_init', [$this, 'maybe_migrate_legacy_table']);
    }

    // -------------------------------------------------------------------------
    // REST routes
    // -------------------------------------------------------------------------

    public function register_routes()
    {

        // GET /changelog — full list with per-item is_new flag.
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_changelog'],
                'permission_callback' => [$this, 'check_permission'],
                'args'                => [
                    'limit' => [
                        'type'              => 'integer',
                        'minimum'           => 1,
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ]
        );

        // POST /changelog/mark-read — mark all (or a specific version) as read.
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/mark-read',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'mark_as_read'],
                'permission_callback' => [$this, 'check_permission'],
                'args'                => [
                    'version' => [
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ]
        );

        // GET /changelog/unread-count — badge count for notification bell.
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/unread-count',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_unread_count'],
                'permission_callback' => [$this, 'check_permission'],
            ]
        );
    }

    // -------------------------------------------------------------------------
    // Route callbacks
    // -------------------------------------------------------------------------

    /**
     * GET /changelog
     * Returns all changelog entries; each entry has an `is_new` boolean flag
     * so the Vue component can show "New" badges without a second request.
     */
    public function get_changelog($request)
    {
        $limit     = $request->get_param('limit');
        $changelog = $this->get_formatted_changelog($limit ? (int) $limit : null);

        return rest_ensure_response(
            [
                'success' => true,
                'data'    => $changelog,
            ]
        );
    }

    /**
     * POST /changelog/mark-read
     * Marks the given version (or the latest, if none given) as read for the
     * current user. Returns updated unread_count so the Vue badge can update
     * without a follow-up request.
     */
    public function mark_as_read($request)
    {
        $version = $request->get_param('version');
        $result  = $this->mark_as_viewed($version ?: null);

        return rest_ensure_response(
            [
                'success'      => (bool) $result,
                'unread_count' => $this->count_unread(),
            ]
        );
    }

    /**
     * GET /changelog/unread-count
     * Returns the number of changelog entries the current user has not yet read.
     */
    public function get_unread_count($request)
    {
        return rest_ensure_response(
            [
                'success'      => true,
                'unread_count' => $this->count_unread(),
            ]
        );
    }

    // -------------------------------------------------------------------------
    // Business logic (previously in Recurio_Changelog_Manager)
    // -------------------------------------------------------------------------

    /**
     * All changelog entries in reverse-chronological order.
     *
     * @return array<int, array<string, mixed>>
     */
    public function get_changelog_data(): array
    {
        return [

            [
                'version' => '1.1.1',
                'date'    => '2026-05-23',
                'changes' => [
                    'Fixes'        => [
                        esc_html__('Payment method filter caused persistent checkout error notices that prevented orders from completing.', 'recurio'),
                        esc_html__('Payment gateway allowlist logic was duplicated across two code paths; consolidated into a single static helper.', 'recurio'),
                        esc_html__('Admin notice warning for missing subscription-compatible gateways was being stripped by the Recurio admin notice cleanup.', 'recurio'),
                    ],
                    'Improvements' => [
                        esc_html__('Admin gateway warning now only displays when at least one subscription product exists, is restricted to users with manage_options capability, and is dismissible.', 'recurio'),
                        esc_html__('Subscription-product existence check behind the gateway warning is now cached with a 12-hour transient.', 'recurio'),
                    ],
                ],
            ],
            [
                'version' => '1.0.2',
                'date'    => '2026-04-15',
                'changes' => [
                    'Fixes'         => [
                        esc_html__('Offer text showing issue in product page.', 'recurio'),
                        esc_html__('Checkout page order summary showing issue with subscription product.', 'recurio'),
                        esc_html__('Subscribe status showing issue with non subscription product.', 'recurio'),
                        esc_html__('Email template wrong billing periods showing issue with custom billing type.', 'recurio'),
                    ],
                    'Compatibility' => [
                        esc_html__('Latest WordPress and WooCommerce version', 'recurio'),
                    ],
                ],
            ],
            [
                'version' => '1.0.0',
                'date'    => '2025-09-18',
                'changes' => [
                    'New Features'  => [
                        esc_html__('Initial release', 'recurio'),
                    ],
                    'Fixes'         => [],
                    'Improvements'  => [],
                    'Compatibility' => [
                        esc_html__('Latest WordPress and WooCommerce version', 'recurio'),
                    ],
                ],
            ],
        ];
    }

    /**
     * Latest version string (first entry in the list).
     */
    public function get_latest_version(): string
    {
        $data = $this->get_changelog_data();
        return ! empty($data[0]['version']) ? (string) $data[0]['version'] : '1.0.0';
    }

    /**
     * Changelog entries annotated with `is_new` for the current user.
     * Optionally sliced to the most recent $limit entries.
     *
     * @param int|null $limit Maximum entries to return.
     * @return array<int, array<string, mixed>>
     */
    public function get_formatted_changelog(?int $limit = null): array
    {
        $user_id   = get_current_user_id();
        $changelog = $this->get_changelog_data();

        if ($limit) {
            $changelog = array_slice($changelog, 0, $limit);
        }

        $unread_numbers = array_column($this->get_unread_entries($user_id), 'version');

        foreach ($changelog as &$entry) {
            $entry['is_new'] = in_array($entry['version'], $unread_numbers, true);
        }
        unset($entry);

        return $changelog;
    }

    /**
     * Mark a version as read for a user; only advances — never goes backwards.
     *
     * @param string|null $version Version to mark, or null to mark through latest.
     * @param int|null    $user_id Defaults to current user.
     * @return bool
     */
    public function mark_as_viewed(?string $version = null, ?int $user_id = null): bool
    {
        $user_id = $user_id ?: get_current_user_id();
        if (! $user_id) {
            return false;
        }

        $target  = $version ?? $this->get_latest_version();
        $current = $this->get_last_read_version($user_id);

        // Never downgrade the stored version.
        if ('' === $current || version_compare($target, $current, '>')) {
            return (bool) update_user_meta($user_id, self::READ_META_KEY, $target);
        }

        return true;
    }

    /**
     * Number of unread changelog entries for the current user.
     *
     * @param int|null $user_id Defaults to current user.
     * @return int
     */
    public function count_unread(?int $user_id = null): int
    {
        return count($this->get_unread_entries($user_id));
    }

    /**
     * Changelog entries newer than the user's last-read version.
     *
     * @param int|null $user_id Defaults to current user.
     * @return array<int, array<string, mixed>>
     */
    public function get_unread_entries(?int $user_id = null): array
    {
        $user_id = $user_id ?: get_current_user_id();
        if (! $user_id) {
            return [];
        }

        $last_read = $this->get_last_read_version($user_id);

        return array_filter(
            $this->get_changelog_data(),
            static function (array $entry) use ($last_read): bool {
                if (empty($entry['version'])) {
                    return false;
                }
                return '' === $last_read
                || version_compare($entry['version'], $last_read, '>');
            }
        );
    }

    // -------------------------------------------------------------------------
    // Permission
    // -------------------------------------------------------------------------

    public function check_permission(): bool
    {
        return current_user_can('manage_woocommerce');
    }

    // -------------------------------------------------------------------------
    // One-time migration from legacy DB table
    // -------------------------------------------------------------------------

    /**
     * If the old wp_recurio_changelog_views table exists, migrate its data to
     * user meta and drop the table. Runs once per admin page load until gone.
     */
    public function maybe_migrate_legacy_table(): void
    {
        global $wpdb;

        $table = $wpdb->prefix . 'recurio_changelog_views';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Legacy check.
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($exists !== $table) {
            return;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time migration.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe, no user input; no dynamic values in this query
        $rows = $wpdb->get_results("SELECT user_id, version FROM `{$table}`", ARRAY_A);

        if (is_array($rows)) {
            $max_by_user = [];
            foreach ($rows as $row) {
                if (empty($row['user_id']) || empty($row['version'])) {
                    continue;
                }
                $uid = (int) $row['user_id'];
                $ver = (string) $row['version'];
                if (! isset($max_by_user[$uid])
                    || version_compare($ver, $max_by_user[$uid], '>')) {
                    $max_by_user[$uid] = $ver;
                }
            }
            foreach ($max_by_user as $uid => $ver) {
                update_user_meta($uid, self::READ_META_KEY, $ver);
            }
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- One-time migration.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe, no user input; DDL has no value to prepare
        $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * The highest version string the user has already read ('' if never).
     */
    private function get_last_read_version(int $user_id): string
    {
        $value = get_user_meta($user_id, self::READ_META_KEY, true);
        return is_string($value) ? $value : '';
    }
}
