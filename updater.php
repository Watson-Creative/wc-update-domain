<?php
/*
Plugin Name: GitHub Plugin Updater (wc-update-domain)
Description: A complimentary plugin that enables automatic updates from a GitHub repository.
Version: 1.0
Author: Spencer Thayer
License: GPL2
*/

// Include necessary WordPress files
require_once(ABSPATH . 'wp-admin/includes/plugin.php');
require_once(ABSPATH . 'wp-includes/formatting.php');

// Configuration Variables
$config = array(
    'slug' => plugin_basename(__FILE__),
    'proper_folder_name' => dirname(plugin_basename(__FILE__)),
    'api_url' => 'https://api.github.com/repos/Watson-Creative/wc-update-domain',
    'raw_url' => 'https://raw.github.com/Watson-Creative/wc-update-domain/master',
    'github_url' => 'https://github.com/Watson-Creative/wc-update-domain',
    'zip_url' => 'https://github.com/Watson-Creative/wc-update-domain/archive/master.zip',
    'sslverify' => true,
    'requires' => '6.3',
    'tested' => '6.4.3',
    'access_token' => '',
    'plugin_name' => 'GitHub Plugin Updater',
    'author' => 'Spencer Thayer',
    'author_profile' => 'https://github.com/Watson-Creative',
    'homepage' => 'https://github.com/Watson-Creative/wc-update-domain',
);

// Updater Class
class WP_GitHub_Updater {

    protected $config;
    private $github_response;

    public function __construct($config = array()) {
        $this->config = $config;

        add_filter('pre_set_site_transient_update_plugins', array($this, 'modify_transient'), 10, 1);
        add_filter('plugins_api', array($this, 'plugin_popup'), 10, 3);
        add_filter('upgrader_post_install', array($this, 'after_install'), 10, 3);
    }

    public function modify_transient($transient) {
        if (property_exists($transient, 'checked')) {
            if ($checked = $transient->checked) {
                $this->get_repository_info();
                $slug = current(explode('/', $this->config['slug']));
                $out_of_date = version_compare($this->github_response['tag_name'], $checked[$slug], 'gt');
                if ($out_of_date) {
                    $new_files = $this->github_response['zipball_url'];
                    $plugin = array(
                        'url' => $this->config['github_url'],
                        'slug' => $slug,
                        'package' => $new_files,
                        'new_version' => $this->github_response['tag_name']
                    );
                    $transient->response[$slug] = (object) $plugin;
                }
            }
        }
        return $transient;
    }

    public function plugin_popup($result, $action, $args) {
        if (!empty($args->slug)) {
            $slug = current(explode('/' , $this->config['slug']));
            if ($args->slug == $slug) {
                $this->get_repository_info();
                $plugin = array(
                    'name' => $this->config['plugin_name'],
                    'slug' => $this->config['slug'],
                    'requires' => $this->config['requires'],
                    'tested' => $this->config['tested'],
                    'version' => $this->github_response['tag_name'],
                    'author' => $this->config['author'],
                    'author_profile' => $this->config['author_profile'],
                    'last_updated' => $this->github_response['published_at'],
                    'homepage' => $this->config['homepage'],
                    'short_description' => $this->config['description'],
                    'sections' => array(
                        'Description' => $this->config['description'],
                        'Updates' => $this->github_response['body'],
                    ),
                    'download_link' => $this->config['zip_url']
                );
                return (object) $plugin;
            }
        }
        return $result;
    }

    public function get_repository_info() {
        if (is_null($this->github_response)) {
            $transient_key = 'github_updater_' . md5($this->config['slug']);
            $cached_response = get_transient($transient_key);

            if ($cached_response !== false) {
                $this->github_response = $cached_response;
                return;
            }

            $request_uri = sprintf('%s/repos/%s/releases/latest', $this->config['api_url'], $this->config['proper_folder_name']);

            $args = array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $this->config['access_token'],
                ),
                'sslverify' => $this->config['sslverify'],
            );

            $response = wp_remote_get($request_uri, $args);

            if (is_wp_error($response) || wp_remote_retrieve_response_code($response) != 200) {
                $error_message = is_wp_error($response) ? $response->get_error_message() : 'Invalid response from GitHub API';
                wp_die($error_message, 'GitHub Plugin Updater Error', array('response' => 500));
            }

            $response_body = json_decode(wp_remote_retrieve_body($response), true);

            if ($this->config['zip_url']) {
                $response_body['zipball_url'] = $this->config['zip_url'];
            }

            $this->github_response = $response_body;
            set_transient($transient_key, $this->github_response, 12 * HOUR_IN_SECONDS);
        }
    }

    public function after_install($response, $hook_extra, $result) {
        global $wp_filesystem;
        $install_directory = plugin_dir_path($this->config['slug']);
        $wp_filesystem->move($result['destination'], $install_directory);
        $result['destination'] = $install_directory;
        return $result;
    }
}

new WP_GitHub_Updater($config);