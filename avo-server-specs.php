<?php
/*
Plugin Name: AvocadoWeb Server Specs Dashboard Widget
Description: Clean, visual server stats for your WP Dashboard with live clock, disk/RAM pie charts, and more. Created by AvocadoWeb Services.
Version: 1.0.0
Author: Joseph Brzezowski / AvocadoWeb Services LLC
License: MIT
*/

add_action('wp_dashboard_setup', function () {
    global $wpdb;

    function avo_size($bytes) {
        if ($bytes > 1099511627776) return round($bytes/1099511627776,2).' TB';
        if ($bytes > 1073741824) return round($bytes/1073741824,2).' GB';
        if ($bytes > 1048576) return round($bytes/1048576,2).' MB';
        if ($bytes > 1024) return round($bytes/1024,2).' KB';
        return $bytes.' B';
    }
    function avo_real_memory_usage() {
        $meminfo = @file_get_contents('/proc/meminfo');
        if ($meminfo) {
            preg_match('/MemTotal:\s+(\\d+) kB/', $meminfo, $total);
            preg_match('/MemAvailable:\s+(\\d+) kB/', $meminfo, $avail);
            if ($total && $avail) {
                $used = $total[1] - $avail[1];
                return [
                    'used' => $used,
                    'total' => $total[1]
                ];
            }
        }
        return false;
    }

    wp_add_dashboard_widget('avo_server_stats', 'Server Specs', function () use ($wpdb) {
        $hostname = gethostname() ?: ($_SERVER['SERVER_NAME'] ?? 'Unknown');
        $local_ip = $_SERVER['SERVER_ADDR'] ?? 'Unavailable';
        $public_ip = @file_get_contents('https://api.ipify.org');
        if (!$public_ip || !filter_var($public_ip, FILTER_VALIDATE_IP)) $public_ip = 'Unavailable';

        $uptime = 'Unavailable';
        if (file_exists('/proc/uptime')) {
            $seconds = floatval(file_get_contents('/proc/uptime'));
            $days = floor($seconds / 86400);
            $hours = floor(($seconds % 86400) / 3600);
            $minutes = floor(($seconds % 3600) / 60);
            $uptime = "{$days}d {$hours}h {$minutes}m";
        } elseif (stristr(PHP_OS, 'WIN')) {
            $uptime = 'N/A on Windows';
        } else {
            @exec("uptime -p", $out);
            if (!empty($out[0])) $uptime = $out[0];
        }
        $software = $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown';
        $system = php_uname('s') . ' ' . php_uname('r') . ' (' . php_uname('m') . ')';

        $db_version = $wpdb->db_version();
        $wp_version = get_bloginfo('version');
        $sql_version = $db_version;

        $used_mem = memory_get_usage();
        $mem_limit = ini_get('memory_limit');
        $mem_multiplier = 1;
        if (stripos($mem_limit, 'G') !== false) $mem_multiplier = 1024*1024*1024;
        elseif (stripos($mem_limit, 'M') !== false) $mem_multiplier = 1024*1024;
        elseif (stripos($mem_limit, 'K') !== false) $mem_multiplier = 1024;
        $max_mem = intval($mem_limit) * $mem_multiplier;

        $disk_total = disk_total_space("/");
        $disk_free = disk_free_space("/");
        $disk_used = $disk_total - $disk_free;
        $disk_percent = $disk_total > 0 ? round(($disk_used / $disk_total) * 100) : 0;

        $real_ram = avo_real_memory_usage();
        $ram_percent = 0; $ram_used = 0; $ram_total = 0;
        if ($real_ram) {
            $ram_used = $real_ram['used'];
            $ram_total = $real_ram['total'];
            $ram_percent = $ram_total > 0 ? round(($ram_used / $ram_total) * 100) : 0;
        }

        $php_memory = avo_size($used_mem) . ' / ' . avo_size($max_mem);

        $cpu = 'Unavailable';
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            $cpu = implode(' / ', array_map(function($l){return round($l,2);}, $load));
        }
        ?>
        <style>
        .avo-server-specs-grid {
            display: grid;
            grid-template-columns: 1.2fr 1fr;
            gap: 12px 16px;
            background: #fafbfb;
            padding: 18px 18px 12px 18px;
            border-radius: 13px;
            box-shadow: 0 2px 10px #eee;
            max-width: 680px;
            font-family: 'Menlo', 'Consolas', monospace;
        }
        .avo-server-specs-section { border-bottom: 1.5px solid #ececec; grid-column: 1/-1; padding-bottom:6px; margin-bottom:6px;}
        .avo-server-specs-label {color:#234;font-weight:600;}
        .avo-server-specs-piechart { width:40px; height:40px; display:inline-block; position:relative;}
        .avo-server-specs-piecenter {
            position:absolute;top:0;left:0;width:100%;height:100%;
            display:flex;align-items:center;justify-content:center;
            font-size:12px;font-weight:600; color:#277c35; pointer-events:none;
        }
        .avo-server-specs-pie-wrap { display:flex; align-items:center; gap:8px; }
        </style>
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
        <div style="font-size:18px;font-weight:bold;margin-bottom:14px;">
            Server Specs as of: <span id="avo-live-clock"></span>
        </div>
        <div class="avo-server-specs-grid">
            <!-- Top Row: IPs | Uptime/Software/System -->
            <div>
                <div class="avo-server-specs-label">Server IP:</div> <?= htmlspecialchars($hostname) ?><br>
                <div class="avo-server-specs-label">Local IP:</div> <?= htmlspecialchars($local_ip) ?><br>
                <div class="avo-server-specs-label">Public IP:</div> <?= htmlspecialchars($public_ip) ?>
            </div>
            <div>
                <div class="avo-server-specs-label">Uptime:</div> <?= htmlspecialchars($uptime) ?><br>
                <div class="avo-server-specs-label">Software:</div> <?= htmlspecialchars($software) ?><br>
                <div class="avo-server-specs-label">System:</div> <?= htmlspecialchars($system) ?>
            </div>
            <div class="avo-server-specs-section"></div>
            <!-- Next Row: Versions | PHP Memory + CPU -->
            <div>
                <div class="avo-server-specs-label">DB Version:</div> <?= htmlspecialchars($db_version) ?><br>
                <div class="avo-server-specs-label">WP Version:</div> <?= htmlspecialchars($wp_version) ?><br>
                <div class="avo-server-specs-label">SQL Version:</div> <?= htmlspecialchars($sql_version) ?>
            </div>
            <div>
                <div class="avo-server-specs-label">PHP Memory:</div> <?= htmlspecialchars($php_memory) ?><br>
                <div class="avo-server-specs-label" style="margin-top:8px;">CPU Load:</div> <?= htmlspecialchars($cpu) ?>
            </div>
            <div class="avo-server-specs-section"></div>
            <!-- Disk Usage Row -->
            <div class="avo-server-specs-pie-wrap">
                <div>
                    <div class="avo-server-specs-label" style="margin-bottom:3px;">Disk Usage:</div>
                    <div style="position:relative;display:inline-block;">
                        <canvas id="avo_disk_chart" class="avo-server-specs-piechart"></canvas>
                        <div class="avo-server-specs-piecenter" id="avo_disk_percent"><?= $disk_percent ?>%</div>
                    </div>
                </div>
                <span style="font-size:13px; color:#333; vertical-align:top; margin-left:7px;">
                    <?= avo_size($disk_used) . ' / ' . avo_size($disk_total) ?>
                </span>
            </div>
            <div></div>
            <!-- RAM Usage Row -->
            <div class="avo-server-specs-pie-wrap">
                <div>
                    <div class="avo-server-specs-label" style="margin-bottom:3px;">RAM Usage:</div>
                    <div style="position:relative;display:inline-block;">
                        <canvas id="avo_ram_chart" class="avo-server-specs-piechart"></canvas>
                        <div class="avo-server-specs-piecenter" id="avo_ram_percent"><?= $ram_percent ?>%</div>
                    </div>
                </div>
                <span style="font-size:13px; color:#333; vertical-align:top; margin-left:7px;">
                    <?= $real_ram ? (avo_size($ram_used*1024) . ' / ' . avo_size($ram_total*1024)) : "Unavailable" ?>
                </span>
            </div>
            <div></div>
        </div>
        <script>
        // Live clock - shows browser local time
        function updateAvoLiveClock() {
            var now = new Date();
            var pad = n => n.toString().padStart(2, '0');
            var ts = now.getFullYear() + '-' +
                     pad(now.getMonth()+1) + '-' +
                     pad(now.getDate()) + ' ' +
                     pad(now.getHours()) + ':' +
                     pad(now.getMinutes()) + ':' +
                     pad(now.getSeconds());
            document.getElementById('avo-live-clock').textContent = ts;
        }
        setInterval(updateAvoLiveClock, 1000);
        updateAvoLiveClock();
        // Pie charts
        document.addEventListener('DOMContentLoaded', function() {
            // Disk
            new Chart(document.getElementById('avo_disk_chart'), {
                type: 'doughnut',
                data: {
                    datasets: [{
                        data: [<?= $disk_percent ?>, <?= 100 - $disk_percent ?>],
                        backgroundColor: ['#3aba59','#e2e2e2'],
                        borderWidth: 2
                    }]
                },
                options: {
                    cutout: '72%',
                    plugins: {
                        legend: { display: false },
                        tooltip: { enabled: false }
                    }
                }
            });
            // RAM
            new Chart(document.getElementById('avo_ram_chart'), {
                type: 'doughnut',
                data: {
                    datasets: [{
                        data: [<?= $ram_percent ?>, <?= 100 - $ram_percent ?>],
                        backgroundColor: ['#3085c8','#e2e2e2'],
                        borderWidth: 2
                    }]
                },
                options: {
                    cutout: '72%',
                    plugins: {
                        legend: { display: false },
                        tooltip: { enabled: false }
                    }
                }
            });
        });
        </script>
        <?php
    });
});
