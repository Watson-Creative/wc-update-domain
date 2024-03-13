<?php
/*
 * Plugin Name: Watson Replace Domain
 * Description: Replace the Pantheon or Lando domain name in the database with the current site domain.
 * Version: 0.4.1
 * Author: Spencer Thayer
 * Author URI: https://www.watsoncreative.com
 */

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

function replace_domain_in_content($content) {
    global $wpdb;

    $project_name = getenv('PANTHEON_SITE_NAME');
    $domain = $_SERVER['HTTP_HOST'];
    $domain_name = get_option('my_domain_name');

    $domain_env = [
        "{$project_name}.lndo.site" => $domain,
        "dev-{$project_name}.pantheonsite.io" => $domain,
        "test-{$project_name}.pantheonsite.io" => $domain,
        "live-{$project_name}.pantheonsite.io" => $domain,
        "{$domain_name}" => $domain
    ];

    foreach($domain_env as $src => $tgt) {
        if ($domain === $src) {
            continue;
        }

        $content = str_replace($src, $tgt, $content);
    }

    return $content;
}
add_filter('the_content', 'replace_domain_in_content');

function wt_domain_table_install() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'wt_domain';

    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        timestamp datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
        event text NOT NULL,
        error_number mediumint(9) NOT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";

    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    dbDelta( $sql );
}
register_activation_hook( __FILE__, 'wt_domain_table_install' );

function insert_error_into_db($event, $error_number = 0) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'wt_domain';

    $wpdb->insert(
        $table_name,
        array(
            'timestamp' => current_time( 'mysql' ),
            'event' => $event,
            'error_number' => $error_number,
        )
    );
}

function fetch_error_logs_from_db() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'wt_domain';
    $results = $wpdb->get_results( "SELECT * FROM $table_name ORDER BY timestamp DESC", ARRAY_A );

    return $results;
}

function clear_error_logs_from_db() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'wt_domain';
    $wpdb->query("TRUNCATE TABLE $table_name");
}

function replace_url_and_protocol_in_db() {
    global $wpdb;

    $project_name = getenv('PANTHEON_SITE_NAME');
    $domain = $_SERVER['HTTP_HOST'];

    if (!filter_var($domain, FILTER_VALIDATE_DOMAIN)) {
        insert_error_into_db("Invalid domain: $domain");
        return;
    }
    
    // Get the domain name from the Admin options
    $domain_name = get_option('my_domain_name');
    
    // If the domain name is not defined, use the domain from the request
    if (!$domain_name) {
        error_log("[".date('Y-m-d H:i:s')."] Domain name not defined", 3, plugin_dir_path( __FILE__ ) . 'error.log');
        return;
    }
    
    $domain_env = [
        "{$project_name}.lndo.site" => $domain,
        "dev-{$project_name}.pantheonsite.io" => $domain,
        "test-{$project_name}.pantheonsite.io" => $domain,
        "live-{$project_name}.pantheonsite.io" => $domain,
        "{$domain_name}" => $domain
    ];

    $tables = $wpdb->get_col('SHOW TABLES');

    foreach ($tables as $table) {
        if ($table === $wpdb->prefix . 'wt_domain') {
            continue;
        }

        $columns = $wpdb->get_results('SHOW COLUMNS FROM ' . $table);

        foreach ($columns as $column) {
            $column_name = $column->Field;
            $column_type = $column->Type;

            if (!preg_match('/char|text/', $column_type)) {
                continue;
            }

            foreach($domain_env as $src => $tgt) {
                // Exclude the current domain from search and replace
                if ($domain === $src) {
                    continue;
                }

                try {
                    $query = $wpdb->prepare("UPDATE {$table} SET {$column_name} = REPLACE({$column_name}, %s, %s) WHERE {$column_name} IS NOT NULL AND {$column_name} NOT LIKE %s", $src, $tgt, '%wp_wt_domain%');
                    $wpdb->query($query);
                    $rows_updated = $wpdb->rows_affected;

                    // Log the changes only if rows were updated
                    if ($rows_updated > 0) {
                        insert_error_into_db("Query: {$query}, Rows Updated: {$rows_updated}");
                    }
                } catch(Exception $e) {
                    insert_error_into_db("Error occurred in table: {$table}, column: {$column_name}. Message: {$e->getMessage()}", $e->getCode());
                }
            }
        }
    }
}

function register_my_setting() {
    register_setting( 'my_options_group', 'my_domain_name' );
}
add_action('admin_init', 'register_my_setting');

function add_my_menu() {
    if(current_user_can('administrator')) {
        add_management_page( 'Replace Domain', 'Replace Domain', 'manage_options', 'replace-domain', 'replace_domain_page' );
    }
}
add_action('admin_menu', 'add_my_menu');

function replace_domain_page() {
    $project_name = getenv('PANTHEON_SITE_NAME');
    $domain = $_SERVER['HTTP_HOST'];
    $domain_name = get_option('my_domain_name');

    if (isset($_POST['replace_domain_button'])) {
        replace_url_and_protocol_in_db();
    } elseif (isset($_POST['clear_log_button'])) {
        clear_error_logs_from_db();
    }

    $error_logs = fetch_error_logs_from_db();
    ?>
    <div class="wrap">
        <h1>Update Pantheon Domains</h1>
        <form method="post" action="">
            <p>
            <?php
                $localDomain = "{$project_name}.lndo.site";
                $devDomain = "dev-{$project_name}.pantheonsite.io";
                $testDomain = "test-{$project_name}.pantheonsite.io";
                $liveDomain = "live-{$project_name}.pantheonsite.io";

                $domains = [$localDomain, $devDomain, $testDomain, $liveDomain, $domain_name];
                $replacements = [];

                foreach ($domains as $currentDomain) {
                    if ($currentDomain !== $domain) {
                        $replacements[] = "<em>{$currentDomain}</em>";
                    }
                }

                $replacementString = '';
                if (!empty($replacements)) {
                    $replacementString = implode(", ", $replacements);
                    $replacementString .= count($replacements) > 1 ? ', and ' : ' with ';
                }

                echo "Click <code>start</code> to replace {$replacementString}<strong>{$domain}</strong>.";
            ?>
            </p>
            <?php submit_button('Start Query', 'secondary', 'replace_domain_button'); ?>
            <label for="my_domain_name">Custom Domain Name:</label>
            <input  style="min-width:300px;" type="text" id="my_domain_name" name="my_domain_name" value="<?php echo get_option('my_domain_name'); ?>" />
            <?php submit_button('Save Domain Name', 'primary', 'save_domain_button', false, array('style' => 'margin-top: 0px;')); ?>
            <h2>Event Log</h2>
            <table id="error-log" style="width:100%;">
                <thead>
                    <tr>
                        <th style="text-align:left;">Timestamp</th>
                        <th style="text-align:left;">Event</th>
                        <th style="text-align:left;">Error Number</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                if (!empty($error_logs)) {
                    foreach ($error_logs as $log) {
                        echo "<tr>";
                        echo "<td>" . esc_html($log['timestamp']) . "</td>";
                        echo "<td>" . esc_html($log['event']) . "</td>";
                        echo "<td>" . esc_html($log['error_number']) . "</td>";
                        echo "</tr>";
                    }
                } else {
                    echo "<tr><td colspan='3'>No queries or errors to log.</td></tr>";
                }
                ?>
                </tbody>
            </table>
            <?php submit_button('Clear Log', 'secondary', 'clear_log_button'); ?>
        </form>
    </div>
    <?php
}

function update_my_domain_name() {
    if (isset($_POST['save_domain_button'])) {
        update_option('my_domain_name', sanitize_text_field($_POST['my_domain_name']));
    }
}
add_action('admin_init', 'update_my_domain_name');
