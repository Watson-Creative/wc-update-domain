<?php
/*
Plugin Name: GitHub Plugin Updater (wc-update-domain)
Description: A complimentary plugin that enables automatic updates from a GitHub repository.
Version: 1.0
Author: Spencer Thayer
License: GPL2
*/

// Configuration Variables
$config = array(
    // 'slug' is the slug of your plugin, which is typically the name of the main plugin file.
    // This is used to identify your plugin in WordPress and should match the plugin slug in your GitHub repository.
    'slug' => plugin_basename(__FILE__),

    // 'proper_folder_name' is the name of the folder that contains your plugin files.
    // This is used to ensure that the plugin is installed in the correct directory.
    'proper_folder_name' => dirname(plugin_basename(__FILE__)),

    // 'api_url' is the URL of the GitHub API endpoint for your plugin repository.
    // Replace 'your-username' with your GitHub username and 'your-plugin' with the name of your plugin repository.
    'api_url' => 'https://api.github.com/repos/Watson-Creative/wc-update-domain',

    // 'raw_url' is the URL of the raw content of your plugin repository on GitHub.
    // Replace 'your-username' with your GitHub username, 'your-plugin' with the name of your plugin repository,
    // and 'master' with the branch name where your plugin files are located (e.g., 'main' or 'develop').
    'raw_url' => 'https://raw.github.com/Watson-Creative/wc-update-domain/master',

    // 'github_url' is the URL of your plugin repository on GitHub.
    // Replace 'your-username' with your GitHub username and 'your-plugin' with the name of your plugin repository.
    'github_url' => 'https://github.com/Watson-Creative/wc-update-domain',

    // 'zip_url' is the URL of the ZIP archive of your plugin repository on GitHub.
    // Replace 'your-username' with your GitHub username, 'your-plugin' with the name of your plugin repository,
    // and 'master' with the branch name where your plugin files are located (e.g., 'main' or 'develop').
    'zip_url' => 'https://github.com/Watson-Creative/wc-update-domain/archive/master.zip',

    // 'sslverify' is a boolean value that determines whether SSL verification should be performed when making requests to GitHub.
    // Set this to 'true' to enable SSL verification, or 'false' to disable it.
    'sslverify' => true,

    // 'requires' is the minimum version of WordPress required for your plugin to work properly.
    // Set this to the minimum WordPress version that your plugin is compatible with (e.g., '4.0' or '5.0').
    'requires' => '6.3',

    // 'tested' is the latest version of WordPress that your plugin has been tested with.
    // Set this to the most recent WordPress version that you have tested your plugin with (e.g., '5.4' or '5.7').
    'tested' => '6.4.3',

    // 'access_token' is an optional GitHub personal access token that is used to authenticate requests to the GitHub API.
    // If your plugin repository is private, you'll need to provide an access token with the necessary permissions.
    // Leave this empty if your repository is public or if you don't want to use an access token.
    'access_token' => '',
);
// Updater Class
class WP_GitHub_Updater {

    protected $config;

    public function __construct($config = array()) {
	
		$this->config = wp_parse_args($config, $defaults);
	
		add_filter('pre_set_site_transient_update_plugins', array($this, 'modify_transient'), 10, 1);
		add_filter('plugins_api', array($this, 'plugin_popup'), 10, 3);
		add_filter('upgrader_post_install', array($this, 'after_install'), 10, 3);
	}

    public function modify_transient($transient) {
        if (property_exists($transient, 'checked')) {
            if ($checked = $transient->checked) {
                $this->get_repository_info();
                $out_of_date = version_compare($this->github_response['tag_name'], $checked[$this->config['slug']], 'gt');
                if ($out_of_date) {
                    $new_files = $this->github_response['zipball_url'];
                    $slug = current(explode('/', $this->config['slug']));
                    $plugin = array(
                        'url' => $this->config['github_url'],
                        'slug' => $slug,
                        'package' => $new_files,
                        'new_version' => $this->github_response['tag_name']
                    );
                    $transient->response[$this->config['slug']] = (object) $plugin;
                }
            }
        }
        return $transient;
    }

    public function plugin_popup($result, $action, $args) {
        if (!empty($args->slug)) {
            if ($args->slug == current(explode('/' , $this->config['slug']))) {
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
            $request_uri = sprintf('https://api.github.com/repos/%s/%s/releases', $this->config['username'], $this->config['repository']);
            if ($this->config['access_token']) {
                $request_uri = add_query_arg(array('access_token' => $this->config['access_token']), $request_uri);
            }
            $response = json_decode(wp_remote_retrieve_body(wp_remote_get($request_uri)), true);
            if (is_array($response)) {
                $response = current($response);
            }
            if ($this->config['zip_url']) {
                $response['zipball_url'] = $this->config['zip_url'];
            }
            $this->github_response = $response;
        }
    }

    public function after_install($response, $hook_extra, $result) {
        global $wp_filesystem;
        $install_directory = plugin_dir_path($this->config['slug']);
        $wp_filesystem->move($result['destination'], $install_directory);
        $result['destination'] = $install_directory;
        if ($this->active) {
            activate_plugin($this->config['slug']);
        }
        return $result;
    }
}

new WP_GitHub_Updater($config);