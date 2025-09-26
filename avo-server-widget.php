<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/*
Plugin Name: Avo Server Widget
Plugin URI:  https://github.com/avocadowebservices/avo-server-widget
Description: Clean, visual server stats for your WordPress Dashboard—live clock, disk/RAM pie charts, server details, database info, and more. Built by AvocadoWeb Services LLC.
Version:     1.0.1
Author:      Joseph Brzezowski
Author URI:  https://avocadoweb.net/
License:     MIT
License URI: https://opensource.org/licenses/MIT
Text Domain: avo-server-widget
Requires at least: 6.0
*/

add_action('wp_dashboard_setup', 'avo_server_specs_dashboard_widget');
add_action('admin_enqueue_scripts', 'avo_server_widget_admin_assets');

/**
 * Enqueue JS and CSS for the dashboard widget.
 */
function avo_server_widget_admin_assets($hook) {
    if ($hook !== 'index.php') return; // Only on dashboard

    // Chart.js (local)
    wp_enqueue_script(
        'avo-server-chartjs',
        plugins_url('assets/js/chart.umd.min.js', __FILE__),
        array(),
        '4.4.1',
        true
    );

    // Your own logic, e.g., chart setup, live clock, etc. (local file)
    wp_enqueue_script(
        'avo-server-widget',
        plugins_url('assets/js/avo-server-widget.js', __FILE__),
        array('avo-server-chartjs'),
        '1.0.1',
        true
    );

    // Styles
    wp_enqueue_style(
        'avo-server-widget',
        plugins_url('assets/css/avo-server-widget.css', __FILE__),
        array(),
        '1.0.1'
    );
}

function avo_server_specs_dashboard_widget() {
    wp_add_dashboard_widget(
        'avo_server_specs_widget',
        esc_html__('Server Specs', 'avo-server-widget'),
        'avo_server_specs_widget_content'
    );
}

function avo_server_specs_widget_content() {
    // Gather all server data, sanitize and escape
    $hostname   = gethostname() ?: ( isset($_SERVER['SERVER_NAME']) ? sanitize_text_field($_SERVER['SERVER_NAME']) : 'Unknown' );
    $server_ip  = gethostbyname($hostname);
    $local_ip   = isset($_SERVER['SERVER_ADDR']) ? sanitize_text_field($_SERVER['SERVER_ADDR']) : 'Unavailable';
    $software   = isset($_SERVER['SERVER_SOFTWARE']) ? sanitize_text_field($_SERVER['SERVER_SOFTWARE']) : 'Unknown';
    $system     = php_uname('s') . ' ' . php_uname('r');
    $php_memory = ini_get('memory_limit');
    $wp_version = get_bloginfo('version');

    // Get public IP using WP HTTP API (no file_get_contents!)
    $public_ip = 'Unavailable';
    $response = wp_remote_get('https://api.ipify.org');
    if ( !is_wp_error($response) ) {
        $body = wp_remote_retrieve_body($response);
        $public_ip = sanitize_text_field($body);
    }

    // RAM
    $ram_total = (int) @shell_exec("awk '/MemTotal/ {print $2}' /proc/meminfo");
    $ram_free  = (int) @shell_exec("awk '/MemAvailable/ {print $2}' /proc/meminfo");
    $ram_used  = $ram_total > 0 ? $ram_total - $ram_free : 0;
    $ram_percent = $ram_total > 0 ? round($ram_used / $ram_total * 100) : 0;
    $real_ram = ($ram_total > 0);

    // Disk
    $disk_total = disk_total_space(ABSPATH);
    $disk_free  = disk_free_space(ABSPATH);
    $disk_used  = $disk_total - $disk_free;
    $disk_percent = $disk_total > 0 ? round($disk_used / $disk_total * 100) : 0;

    // CPU load (Linux only)
    $cpu = 'Unavailable';
    if (function_exists('sys_getloadavg')) {
        $load = sys_getloadavg();
        $cpu = is_array($load) ? esc_html($load[0]) : 'Unavailable';
    }

    // Start HTML output (all values escaped)
    ?>
    <div class="avo-server-specs-wrap">
        <div class="avo-server-specs-title" style="font-weight:bold;font-size:18px;">Server Specs</div>
        <div class="avo-server-specs-label">Hostname:</div> <?php echo esc_html($hostname); ?><br>
        <div class="avo-server-specs-label">Server IP:</div> <?php echo esc_html($server_ip); ?><br>
        <div class="avo-server-specs-label">Local IP:</div> <?php echo esc_html($local_ip); ?><br>
        <div class="avo-server-specs-label">Public IP:</div> <?php echo esc_html($public_ip); ?><br>
        <div class="avo-server-specs-label">Software:</div> <?php echo esc_html($software); ?><br>
        <div class="avo-server-specs-label">System:</div> <?php echo esc_html($system); ?><br>
        <div class="avo-server-specs-label">WP Version:</div> <?php echo esc_html($wp_version); ?><br>
        <div class="avo-server-specs-label">PHP Memory:</div> <?php echo esc_html($php_memory); ?><br>
        <div class="avo-server-specs-label" style="margin-top:8px;">CPU Load:</div> <?php echo esc_html($cpu); ?><br>
        <div class="avo-server-specs-pie-wrap" style="display:flex;align-items:center;gap:36px;margin-top:20px;">
            <div class="avo-server-specs-piechart">
                <canvas id="avo_ram_pie" width="100" height="100"></canvas>
                <div class="avo-server-specs-piecenter" id="avo_ram_percent"><?php echo esc_html($ram_percent); ?>%</div>
                <div style="text-align:center;margin-top:6px;">RAM Usage</div>
                <div style="font-size:13px;text-align:center;">
                    <?php
                    echo $real_ram
                        ? esc_html(avo_size($ram_used * 1024) . ' / ' . avo_size($ram_total * 1024))
                        : esc_html__("Unavailable", "avo-server-widget");
                    ?>
                </div>
            </div>
            <div class="avo-server-specs-piechart">
                <canvas id="avo_disk_pie" width="100" height="100"></canvas>
                <div class="avo-server-specs-piecenter" id="avo_disk_percent"><?php echo esc_html($disk_percent); ?>%</div>
                <div style="text-align:center;margin-top:6px;">Disk Usage</div>
                <div style="font-size:13px;text-align:center;">
                    <?php echo esc_html(avo_size($disk_used) . ' / ' . avo_size($disk_total)); ?>
                </div>
            </div>
        </div>
    </div>
    <script>
    window.avoServerWidgetData = {
        ramPercent: <?php echo (int) $ram_percent; ?>,
        diskPercent: <?php echo (int) $disk_percent; ?>
    };
    </script>
    <?php
}

function avo_size($bytes) {
    if ($bytes < 1024) return $bytes . ' B';
    $units = array('KB','MB','GB','TB','PB');
    $i = floor(log($bytes, 1024));
    return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i-1];
}
