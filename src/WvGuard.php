<?php
namespace Webvooruit;

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

require_once __DIR__ . '/../vendor-prefixed/autoload.php';

class WvGuard
{
    private array $config;
    private string $action;

    /**
     * WvGuard constructor.
     *
     * @param string $base_file
     * @param array $config
     */
    public function __construct(string $base_file, array $config = [])
    {
        /**
         * Set config
         */
        $this->set_config($base_file, $config);

        /**
         * register hooks
         */
        $this->register_hooks();
        $this->register_updater();
    }

    /**
     * Set config
     *
     * @param string $base_file
     * @param array $config
     *
     * @return void
     */
    public function set_config(string $base_file, array $config): void
    {
        /**
         * Get the plugin data
         */
        $plugin_data = get_plugin_data( $base_file );
        $base_name = basename(plugin_dir_path($base_file));

        /**
         * Get the plugin id
         */
        $plugin_file_path_parts = explode('/',  wp_normalize_path( $base_file ));
        $plugin_id_parts = array_slice($plugin_file_path_parts, -2, 2);
        $plugin_id = implode('/', $plugin_id_parts);

        /**
         * Set the action
         */
        $this->action = htmlspecialchars($_GET['license_action'] ?? '');

        /**
         * Set the config
         */
        $this->config = array_replace_recursive([
            'plugin' => [
                'id' => $plugin_id,
                'basename' => $base_name,
                'version' => $plugin_data['Version'],
                'base_file' => $base_file,
            ],

            'license_key' => 'wv_' . $base_name . '_license_key',

            'api' => [
                'url' => 'https://dist.anystack.sh/v1',
            ],
        ], $config);
    }

    /**
     * Register hooks
     *
     * @return void
     */
    protected function register_hooks(): void
    {
        register_activation_hook( $this->config['plugin']['base_file'], [$this, 'maybe_plugin_needs_license'] );
        register_deactivation_hook( $this->config['plugin']['base_file'], [$this, 'maybe_plugin_needs_license'] );

        $this->maybe_plugin_needs_license();
    }

    /**
     * Check if plugin needs license
     *
     * @return void
     */
    public function maybe_plugin_needs_license(): void
    {
        $metadata = $this->do_query_license('get_metadata');
        if( $metadata?->license_required) {
            $this->register_license_hooks();
        }
    }

    /**
     * Register updater
     *
     * @return void
     */
    protected function register_updater(): void
    {
        $metadata = [
            'action' => 'get_metadata',
            'plugin_slug' => $this->config['plugin']['basename'],
            'license_key' => $this->get_license_key(),
            'domain' => $_SERVER['SERVER_NAME'],
            'version' => $this->config['plugin']['version'],
        ];

        $metadata_url = trailingslashit($this->config['api']['url']);
        $metadata_url = add_query_arg($metadata, $metadata_url);

        PucFactory::buildUpdateChecker($metadata_url, $this->config['plugin']['base_file']);
    }

    /**
     * Register license hooks
     *
     * @return void
     */
    protected function register_license_hooks(): void
    {
        /**
         * Add filter to add meta to plugin row
         */
        add_filter('plugin_row_meta', array($this, 'plugin_add_meta'), 10, 2);

        /**
         * Add action to add license form to plugin row
         */
        add_action('after_plugin_row_' . $this->config['plugin']['id'], array($this, 'plugin_license_form'), 10, 3);
        add_action('admin_enqueue_scripts', array($this, 'plugin_add_admin_scripts'), 99, 1);

        /**
         * Add ajax actions
         */
        add_action('wp_ajax_wv_activate_license', array($this, 'activate_license'), 10, 0);
        add_action('wp_ajax_wv_deactivate_license', array($this, 'deactivate_license'), 10, 0);
    }

    /**
     * Add meta to plugin row
     *
     * @param array $meta
     * @param string $plugin
     *
     * @return array
     */
    public function plugin_add_meta(array $meta, string $plugin ) : array
    {
        /**
         * Check if we are in admin
         */
        if( ! is_admin() ) {
            return $meta;
        }

        /**
         * Check if plugin is the plugin we are looking for
         */
        if( $this->config['plugin']['id'] === $plugin ) {
            /**
             * Check if plugin has license
             */
            if ( $this->get_license_status() ) {
                /**
                 * Add meta to plugin row
                 */
                $meta[] = sprintf('<a href="?license_action=deactivate&plugin=%s">Deactivate license</a>', $this->config['plugin']['basename']);
                $meta[] = $this->get_license_key(true);
            }

            else {
                /**
                 * Add meta to plugin row
                 */
                $meta[] = sprintf('<a href="?license_action=activate&plugin=%s">Activate license</a>', $this->config['plugin']['basename']);
            }
        }

        return $meta;
    }

    /**
     * Add admin scripts
     *
     * @param string $hook
     *
     * @return void
     */
    public function plugin_add_admin_scripts(string $hook): void
    {
        $condition = 'plugins.php' === $hook;
        $condition = $condition || 'appearance_page_theme-license' === $hook;
        $condition = $condition || 'appearance_page_parent-theme-license' === $hook;
        $condition = $condition && !wp_script_is('wv-license-script');

        /**
         * Check if condition is met
         */
        if( $condition ) {
            /**
             * Add script to admin
             */
            $params = array(
                'action_prefix' => 'wv_' . $this->config['plugin']['basename'],
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('license_nonce'),
            );

            wp_enqueue_script('wv-license-script', plugin_dir_url($this->config['plugin']['id']) . 'webvooruit/src/js/main.js', array('jquery'), 0, true);
            wp_localize_script('wv-license-script', 'WP_PackageUpdater', $params);
        }
    }

    /**
     * Add license form to plugin row
     *
     * @param $plugin
     *
     * @return void
     */
    public function plugin_license_form( $plugin ): void
    {
        $plugin_slug = isset($_GET['plugin']) ? htmlspecialchars($_GET['plugin']) : '';

        /**
         * Check if we are in admin
         */
        if( ! is_admin() ) {
            return;
        }

        /**
         * Get plugin basename
         */
        $plugin = explode('/', $plugin);
        $plugin = $plugin[0];

        /**
         * Check if plugin is the plugin we are looking for
         */
        if( $plugin !== $plugin_slug ) {
            return;
        }

        /**
         * Check if we have an action
         */
        if( $this->action === 'activate' ) {
            $this->show_license_form();
        }

        if( $this->action === 'deactivate') {
            $this->deactivate_license();
        }
    }

    /**
     * Show license form
     *
     * @return void
     */
    protected function show_license_form(): void
    {
        $license = $this->get_license_key(true);
        $has_license = $this->get_license_status() && $license;
        $plugin_slug = isset($_GET['plugin']) ? htmlspecialchars($_GET['plugin']) : '';

        var_dump($has_license);

        /**
         * Check if plugin has license
         */
        if( $has_license  === false ) {
            echo '<tr class="plugin-update-tr active">';
            echo '<td colspan="5" class="plugin-update column-description desc">';
            echo '<div class="notice inline notice-alt">';
            echo '<div class="wrap-license" data-plugin_slug="' . esc_attr( $plugin_slug ) . '" data-nonce="' . esc_attr( wp_create_nonce( 'license_nonce' ) ) . '">';
            echo '<b class="license-message hidden"></b>';
            echo '<p>';
            echo '<input class="regular-text license" type="password" id="' . esc_attr( 'license_key_' . $plugin_slug ) . '">';
            echo '<input type="button" value="Activate" class="button-primary activate-license" />';
            echo '</p>';
            echo '</div>';
            echo '</td>';
            echo '</tr>';
        }
    }

    /**
     * Do query to license server
     *
     * @param string $action
     * @param array $args
     *
     * @return object
     */
    protected function do_query_license(string $action, array $args = []) : object
    {
        $args = array_replace_recursive([
            'plugin_slug' => $this->config['plugin']['basename'],
            'license_key' => 1,
            'domain' => $_SERVER['SERVER_NAME'],
            'version' => $this->config['plugin']['version'],
            'action' => $action
        ], $args);

        $metadata_url = trailingslashit($this->config['api']['url']);
        $metadata_url = add_query_arg($args, $metadata_url);

        $response = wp_remote_get($metadata_url);
        $body = wp_remote_retrieve_body($response);
        $body = json_decode($body);

        if (is_wp_error($response)) {
            throw new InvalidArgumentException($response->get_error_message());
        }

        if (JSON_ERROR_NONE !== json_last_error()) {
            throw new InvalidArgumentException('Unexpected Error! The query to retrieve the license data returned a malformed response.');
        }

        return $body;
    }

    /**
     * Activate license
     *
     * @return string
     */
    public function activate_license(): string
    {
        $license_key = htmlspecialchars($_POST['license_key']);
        $plugin_slug = htmlspecialchars($_POST['plugin_slug']);

        $this->config['plugin']['basename'] = $plugin_slug;

        $license_data = $this->do_query_license('activate', ['license_key' => $license_key, 'plugin_slug' => $plugin_slug]);

        if ( false === isset( $license_data->license_key ) ) {
            $error = new \WP_Error('License', $license_data->message);
            $this->delete_license_key();

            return wp_send_json_error($error);
        }

        $this->update_license_key($license_key);

        return wp_send_json_success($license_data);
    }

    /**
     * Deactivate license
     *
     * @return void
     */
    public function deactivate_license(): void
    {
        /*
         * Check if we have a license
         */
        if( ! $this->get_license_status() ) {
            return;
        }

        /*
         * Do query to license server to deactivate license and
         * delete the license key
         */
        $this->do_query_license('deactivate');
        $this->delete_license_key();

        /*
         * Redirect to plugins page
         */
        echo '<script>window.location = "' . admin_url('plugins.php') . '";</script>';
    }

    /**
     * Deactivate license
     *
     * @return string
     */
    protected function get_license_slug() : string
    {
        return 'wv_' . $this->config['plugin']['basename'] . '_license_key';
    }

    /**
     * Get license key
     *
     * @param bool $obfuscate
     *
     * @return string
     */
    protected function get_license_key(bool $obfuscate = false) : string
    {
        $license = rawurlencode(
            get_option($this->get_license_slug())
        );

        /**
         * Check if we need to obfuscate the license key
         */
        if( $obfuscate ) {
            $license = preg_replace('/(.*)-(.*)-(.*)-(.*)/', '$1-****-****-****', $license);
        }

        return $license;
    }

    /**
     * Delete license key
     *
     * @return void
     */
    public function delete_license_key() : void
    {
        delete_option($this->get_license_slug());
    }

    /**
     * Get license status
     *
     * @return bool
     */
    public function get_license_status(): bool
    {
        return (bool) get_option($this->get_license_slug());
    }

    /**
     * Update license key
     *
     * @param string $license_key
     *
     * @return void
     */
    protected function update_license_key(string $license_key): void
    {
        update_option($this->get_license_slug(), $license_key);
    }
}

