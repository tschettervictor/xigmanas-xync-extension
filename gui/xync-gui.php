<?php
/*
    xync-gui.php
    Copyright (c) 2026 Victor Tschetter
*/

require_once 'auth.inc';
require_once 'guiconfig.inc';

// Variables and Paths
$ext_config_dir = "/var/etc/xync";
$ext_config = "{$ext_config_dir}/xync.conf.ext";
$cwdir = exec("/usr/bin/grep 'INSTALL_DIR=' " . escapeshellarg($ext_config) . " | /usr/bin/cut -d'\"' -f2");
$app_command = "{$cwdir}/xync-dist/xync.sh";
$app_config = "{$cwdir}/conf/xync.conf";
$log_dir_default = "{$cwdir}/logs";
$cron_uuid = "68c74f5d-1234-4321-a1b2-c3d4e5f6a7b8"; 
$checkbox_vars = ['ALLOW_RECONCILIATION', 'ALLOW_ROOT_DATASETS', 'RECURSE_CHILDREN'];
$text_vars = ['LOG_BASE'];

if ($_POST) {
    unset($savemsg);
    if (isset($_POST['save']) && $_POST['save']) {
        foreach ($checkbox_vars as $var) {
            $val = isset($_POST[$var]) ? "1" : "0";
            mwexec("sysrc -f " . escapeshellarg($app_config) . " " . escapeshellarg($var) . "=" . escapeshellarg($val));
        }
        if (isset($_POST['LOG_BASE'])) {
            mwexec("sysrc -f " . escapeshellarg($app_config) . " LOG_BASE=" . escapeshellarg($_POST['LOG_BASE']));
        }
        if (!empty($_POST['REPLICATE_SETS_ADD'])) {
            mwexec("sysrc -f " . escapeshellarg($app_config) . " REPLICATE_SETS+=" . escapeshellarg($_POST['REPLICATE_SETS_ADD']));
        }

        if (!isset($config['cron']['job']) || !is_array($config['cron']['job'])) {
            $config['cron']['job'] = [];
        }
        $a_cronjob = &$config['cron']['job'];
        $index = false;
        foreach ($a_cronjob as $key => $job) {
            if (isset($job['uuid']) && $job['uuid'] === $cron_uuid) {
                $index = $key;
                break;
            }
        }
        $preset = $_POST['SCHEDULE_PRESET'];

        if ($preset === 'none') {
            if ($index !== false) unset($a_cronjob[$index]);
        } else {
            $cron_record = [];
            $cron_record['enable'] = true; 
            $cron_record['uuid'] = $cron_uuid;
            $cron_record['desc'] = 'Xync Replication Task';
            $cron_record['minute'] = '0';
            $cron_record['hour']   = ($preset === 'hourly') ? '*' : '0';
            $cron_record['day']    = '';
            $cron_record['month']  = '';
            $cron_record['weekday'] = ($preset === 'weekly') ? '0' : '';
            $cron_record['all_mins']     = '0';
            $cron_record['all_hours']    = ($preset === 'hourly') ? '1' : '0';
            $cron_record['all_days']     = '1';
            $cron_record['all_months']   = '1';
            $cron_record['all_weekdays'] = ($preset === 'weekly') ? '0' : '1';
            $cron_record['who'] = 'root';
            $cron_record['command'] = $app_command . " --config " . $app_config;
            $cron_record['command'] = "/bin/tmux new-session -d -s xync \"" . $script_path . " --config " . $app_config . "\"";
            
            if ($index !== false) { $a_cronjob[$index] = $cron_record; } 
            else { $a_cronjob[] = $cron_record; }
        }

        write_config();
        config_lock();
        rc_update_service('cron'); 
        config_unlock();
        $savemsg = gtext("Configuration updated.");
    }

    if (isset($_POST['delete_set']) && is_numeric($_POST['delete_set'])) {
        $idx = (int)$_POST['delete_set'];
        $raw = exec("sysrc -f " . escapeshellarg($app_config) . " -n REPLICATE_SETS 2>/dev/null");
        $sets = array_values(array_filter(explode(" ", $raw)));
        if (isset($sets[$idx])) {
            unset($sets[$idx]);
            mwexec("sysrc -f " . escapeshellarg($app_config) . " REPLICATE_SETS=" . escapeshellarg(implode(" ", $sets)));
        }
    }
}

// Fetch current values
$current_values = [];
foreach (array_merge($checkbox_vars, $text_vars) as $var) {
    $val = exec("sysrc -f " . escapeshellarg($app_config) . " -n " . escapeshellarg($var) . " 2>/dev/null");
    if ($var === 'LOG_BASE' && empty($val)) { $val = $log_dir_default; }
    $current_values[$var] = $val;
}

// Log Parser Logic
$log_dir = $current_values['LOG_BASE'];
$last_line = "No logs found.";
$status_color = "#999"; // Default Gray

if (is_dir($log_dir)) {
    $latest_log = exec("ls -t " . escapeshellarg($log_dir) . "/*.log 2>/dev/null | head -n 1");
    if (!empty($latest_log)) {
        $last_line = exec("tail -n 1 " . escapeshellarg($latest_log));
        $lower_line = strtolower($last_line);
        
        if (strpos($lower_line, 'success') !== false) { $status_color = "#55ff55"; }
        elseif (strpos($lower_line, 'warning') !== false) { $status_color = "#ffff55"; }
        elseif (strpos($lower_line, 'error') !== false) { $status_color = "#ff5555"; }
    }
}

// Cron Lookup
$current_preset = 'none';
$xml_status_text = "Disabled";
$job_index = false;
if (isset($config['cron']['job']) && is_array($config['cron']['job'])) {
    foreach ($config['cron']['job'] as $key => $job) {
        if (isset($job['uuid']) && $job['uuid'] === $cron_uuid) {
            $job_index = $key;
            break;
        }
    }
}
if ($job_index !== false) {
    $job = $config['cron']['job'][$job_index];
    if (($job['all_hours'] ?? '') === '1') $current_preset = 'hourly';
    elseif (($job['all_weekdays'] ?? '') === '0') $current_preset = 'weekly';
    else $current_preset = 'daily';
    
    $fmt = function($val, $default = '*') { return (is_array($val) || empty($val)) ? $default : (string)$val; };
    $m = $fmt($job['minute'], '0');
    $h = ($job['all_hours'] === '1') ? '*' : $fmt($job['hour'], '0');
    $d = ($job['all_days'] === '1') ? '*' : $fmt($job['day'], '*');
    $M = ($job['all_months'] === '1') ? '*' : $fmt($job['month'], '*');
    $w = ($job['all_weekdays'] === '1') ? '*' : $fmt($job['weekday'], '*');
    $xml_status_text = "{$m} {$h} {$d} {$M} {$w} {$job['who']} {$job['command']}";
}

$raw_replicate_sets = exec("sysrc -f " . escapeshellarg($app_config) . " -n REPLICATE_SETS 2>/dev/null");
$replicate_sets_list = array_values(array_filter(explode(" ", $raw_replicate_sets)));

$pgtitle = [gtext("Extensions"), gtext('Xync')];
include 'fbegin.inc';
?>

<form action="xync-gui.php" method="post" name="iform" id="iform" onsubmit="spinner()">
    <table width="100%" border="0" cellpadding="0" cellspacing="0">
        <tr>
            <td class="tabcont">
                <?php if (!empty($savemsg)) print_info_box($savemsg); ?>
                <table width="100%" border="0" cellpadding="6" cellspacing="0">
                    <?php 
                    html_titleline2(gettext("General Settings"));
                    html_checkbox2('ALLOW_RECONCILIATION', gettext('Allow Reconciliation'), ($current_values['ALLOW_RECONCILIATION'] === '1'), gettext('Force replicate.'), '', false);
                    html_checkbox2('ALLOW_ROOT_DATASETS', gettext('Allow Root Datasets'), ($current_values['ALLOW_ROOT_DATASETS'] === '1'), gettext('Permit root dataset replication.'), '', false);
                    html_checkbox2('RECURSE_CHILDREN', gettext('Recurse Children'), ($current_values['RECURSE_CHILDREN'] === '1'), gettext('Include child datasets.'), '', false);
                    
                    html_titleline2(gettext("Replication Sets"));
                    html_inputbox2('REPLICATE_SETS_ADD', gettext('Add New Set'), '', 'source:destination', false, 60);
                    if (!empty($replicate_sets_list)): ?>
                    <tr>
                        <td class="vncell"><?=gtext("Active Sets");?></td>
                        <td class="vtable">
                            <?php foreach ($replicate_sets_list as $idx => $set): ?>
                                <div style="margin-bottom:5px;">
                                    <code><?= htmlspecialchars($set); ?></code> 
                                    <button type="submit" name="delete_set" value="<?= $idx; ?>" class="formbtn" style="padding:0 5px; font-size:10px;">Delete</button>
                                </div>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                    <?php endif;

                    html_titleline2(gettext("Replication Schedule"));
                    $opts = ['none' => 'Disabled', 'hourly' => 'Hourly', 'daily' => 'Daily', 'weekly' => 'Weekly'];
                    html_combobox2('SCHEDULE_PRESET', gettext('Frequency'), $current_preset, $opts, '', false);
                    ?>
                    <tr>
                        <td class="vncell"><?=gtext("Cron Command");?></td>
                        <td class="vtable">
                            <pre style="margin:0; padding:8px; background:#111; color:#ccc; border-radius:4px; font-size:11px;"><?= htmlspecialchars($xml_status_text); ?></pre>
                        </td>
                    </tr>
                    <?php
                    html_titleline2(gettext("Logging"));
                    html_inputbox2('LOG_BASE', gettext('Log Path'), $current_values['LOG_BASE'], '', false, 60);

                    // NEW STATUS SECTION
                    html_titleline2(gettext("Status"));
                    ?>
                    <tr>
                        <td class="vncell"><?=gtext("Last Replication Status");?></td>
                        <td class="vtable">
                            <pre style="margin:0; padding:10px; background:#111; color:<?= $status_color; ?>; border-radius:4px; font-weight:bold; white-space: pre-wrap; word-break: break-all;"><?= htmlspecialchars($last_line); ?></pre>
                        </td>
                    </tr>
                </table>
                <div id="submit" style="margin-top: 20px;">
                    <input name="save" type="submit" class="formbtn" value="<?=gtext('Save');?>"/>
                </div>
                <?php include 'formend.inc'; ?>
            </td>
        </tr>
    </table>
</form>
<?php include 'fend.inc'; ?>
