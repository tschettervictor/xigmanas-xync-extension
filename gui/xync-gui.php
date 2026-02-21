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
$text_vars = ['LOG_BASE', 'SNAP_KEEP', 'LOG_KEEP'];

if ($_POST) {
    unset($savemsg);
    
    // Update and Uninstall
    if (isset($_POST['update']) || isset($_POST['uninstall'])) {
        $flag = isset($_POST['update']) ? "-u" : "-r";
        $cmd = "{$app_command} {$flag}";
        exec($cmd, $output, $return_val);
        $savemsg = implode("<br />", $output);
        if (empty($savemsg)) { $savemsg = "Command executed."; }
    }

    // Add Set
    if (isset($_POST['add_set']) && !empty($_POST['REPLICATE_SETS_ADD'])) {
        mwexec("sysrc -f " . escapeshellarg($app_config) . " REPLICATE_SETS+=" . escapeshellarg($_POST['REPLICATE_SETS_ADD']));
        $savemsg = gtext("Replication set added.");
    }

    // Save Logic
    if (isset($_POST['save']) && $_POST['save']) {
        foreach ($checkbox_vars as $var) {
            $val = isset($_POST[$var]) ? "1" : "0";
            mwexec("sysrc -f " . escapeshellarg($app_config) . " " . escapeshellarg($var) . "=" . escapeshellarg($val));
        }
        
        foreach (['LOG_BASE', 'SNAP_KEEP', 'LOG_KEEP'] as $var) {
            if (isset($_POST[$var])) {
                $val = $_POST[$var];
                mwexec("sysrc -f " . escapeshellarg($app_config) . " " . escapeshellarg($var) . "=" . escapeshellarg($val));
            }
        }

        // Cron logic
        if (!isset($config['cron']['job']) || !is_array($config['cron']['job'])) { $config['cron']['job'] = []; }
        $a_cronjob = &$config['cron']['job'];
        $index = false;
        foreach ($a_cronjob as $key => $job) {
            if (isset($job['uuid']) && $job['uuid'] === $cron_uuid) { $index = $key; break; }
        }
        $preset = $_POST['SCHEDULE_PRESET'];

        if ($preset === 'none') {
            if ($index !== false) unset($a_cronjob[$index]);
        } else {
            $cron_record = ['enable' => true, 'uuid' => $cron_uuid, 'desc' => 'Xync Replication Task', 'who' => 'root'];
            $cron_record['minute'] = '0';
            $cron_record['hour']   = ($preset === 'hourly') ? '*' : '0';
            $cron_record['day']    = $cron_record['month'] = '';
            $cron_record['weekday'] = ($preset === 'weekly') ? '0' : '';
            $cron_record['all_mins'] = '0';
            $cron_record['all_hours'] = ($preset === 'hourly') ? '1' : '0';
            $cron_record['all_days'] = $cron_record['all_months'] = '1';
            $cron_record['all_weekdays'] = ($preset === 'weekly') ? '0' : '1';
            $cron_record['command'] = "/bin/tmux new-session -d -s xync \"" . $app_command . " --config " . $app_config . "\"";
            
            if ($index !== false) { $a_cronjob[$index] = $cron_record; } 
            else { $a_cronjob[] = $cron_record; }
        }

        write_config();
        config_lock();
        rc_update_service('cron'); 
        config_unlock();
        $savemsg = gtext("Configuration updated.");
    }

    // Delete Logic
    if (isset($_POST['delete_set']) && is_numeric($_POST['delete_set'])) {
        $idx = (int)$_POST['delete_set'];
        $raw = exec("sysrc -f " . escapeshellarg($app_config) . " -n REPLICATE_SETS 2>/dev/null");
        $sets = array_values(array_filter(explode(" ", $raw)));
        if (isset($sets[$idx])) {
            unset($sets[$idx]);
            mwexec("sysrc -f " . escapeshellarg($app_config) . " REPLICATE_SETS=" . escapeshellarg(implode(" ", $sets)));
            $savemsg = gtext("Replication set removed.");
        }
    }
}

// Fetch values
$current_values = [];
foreach (array_merge($checkbox_vars, $text_vars) as $var) {
    $val = exec("sysrc -f " . escapeshellarg($app_config) . " -n " . escapeshellarg($var) . " 2>/dev/null");
    if ($var === 'LOG_BASE' && empty($val)) { $val = $log_dir_default; }
    if (in_array($var, ['SNAP_KEEP', 'LOG_KEEP']) && empty($val)) { $val = "0"; }
    $current_values[$var] = $val;
}

// Log status
$log_dir = $current_values['LOG_BASE'];
$last_line = "No logs found.";
$status_color = "#999"; 
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
                    html_checkbox2('ALLOW_RECONCILIATION', gettext('Allow Reconciliation'), ($current_values['ALLOW_RECONCILIATION'] === '1'), gettext('Force replication in case of mismatching snapshots.'), '', false);
                    html_checkbox2('ALLOW_ROOT_DATASETS', gettext('Allow Root Datasets'), ($current_values['ALLOW_ROOT_DATASETS'] === '1'), gettext('Allow replicating of root datasets.'), '', false);
                    html_checkbox2('RECURSE_CHILDREN', gettext('Recurse Children'), ($current_values['RECURSE_CHILDREN'] === '1'), gettext('Recursively replicate all child datasets.'), '', false);
                    ?>
                    <tr>
                        <td class="vncell"><?=gtext("Snapshot Retention");?></td>
                        <td class="vtable">
                            <select name="SNAP_KEEP" class="formfld" id="SNAP_KEEP">
                                <?php for ($i=0; $i<=9; $i++): ?>
                                    <option value="<?=$i;?>" <?php if ($current_values['SNAP_KEEP'] == $i) echo "selected"; ?>><?=$i;?></option>
                                <?php endfor; ?>
                            </select>
                            <span class="vexpl"><?=gtext("Number of snapshots to keep.");?></span>
                        </td>
                    </tr>

                    <?php html_titleline2(gettext("Replication Sets")); ?>
                    <tr>
                        <td class="vncell"><?=gtext("Add New Set");?></td>
                        <td class="vtable">
                            <input name="REPLICATE_SETS_ADD" type="text" class="formfld" size="60" value="" placeholder="source:destination" /><br />
                            <input name="add_set" type="submit" class="formbtn" style="margin-top:5px;" value="<?=gtext('Add');?>" />
                        </td>
                    </tr>
                    <tr>
                        <td class="vncell"><?=gtext("Active Sets");?></td>
                        <td class="vtable">
                            <?php foreach ($replicate_sets_list as $idx => $set): ?>
                                <div style="margin-bottom:10px; border-bottom:1px solid #333; padding-bottom:5px;">
                                    <code><?= htmlspecialchars($set); ?></code><br />
                                    <button type="submit" name="delete_set" value="<?= $idx; ?>" class="formbtn" style="margin-top:4px; padding:2px 10px; font-size:10px;"><?=gtext("Delete");?></button>
                                </div>
                            <?php endforeach; ?>
                        </td>
                    </tr>

                    <?php 
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
                    ?>
                    <tr>
                        <td class="vncell"><?=gtext("Log Retention");?></td>
                        <td class="vtable">
                            <select name="LOG_KEEP" class="formfld" id="LOG_KEEP">
                                <?php for ($i=0; $i<=9; $i++): ?>
                                    <option value="<?=$i;?>" <?php if ($current_values['LOG_KEEP'] == $i) echo "selected"; ?>><?=$i;?></option>
                                <?php endfor; ?>
                            </select>
                            <span class="vexpl"><?=gtext("Number of log files to keep.");?></span>
                        </td>
                    </tr>

                    <?php html_titleline2(gettext("Status")); ?>
                    <tr>
                        <td class="vncell"><?=gtext("Last Log Status");?></td>
                        <td class="vtable">
                            <pre style="margin:0; padding:10px; background:#111; color:<?= $status_color; ?>; border-radius:4px; white-space: pre-wrap;"><?= htmlspecialchars($last_line); ?></pre>
                        </td>
                    </tr>
                </table>
                <div id="submit" style="margin-top: 20px;">
                    <input name="save" type="submit" class="formbtn" value="<?=gtext('Save');?>"/>
                    <input name="update" type="submit" class="formbtn" value="<?=gtext('Update');?>" />
                    <input name="uninstall" type="submit" class="formbtn" value="<?=gtext('Uninstall');?>" />
                </div>
                <?php include 'formend.inc'; ?>
            </td>
        </tr>
    </table>
</form>
<?php include 'fend.inc'; ?>