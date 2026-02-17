<?php
/*
    xync_manager_gui.php
    Copyright (c) 2026 xync
*/
require_once 'auth.inc';
require_once 'guiconfig.inc';

use common\arr;

// Variables and Paths
$sphere_scriptname = basename(__FILE__);
$confdir = "/var/etc/xync";
$ext_conf = "{$confdir}/xync.conf.ext";

if (file_exists($ext_conf)) {
    $cwdir = exec("/usr/bin/grep 'INSTALL_DIR=' " . escapeshellarg($ext_conf) . " | /usr/bin/cut -d'\"' -f2");
} else {
    $cwdir = "/mnt/backup/extensions/xync"; 
}

$configfile = "{$cwdir}/xync.conf";
$script_path = "{$cwdir}/xync.sh";
$logbase = "{$cwdir}/logs";
$xync_uuid = "68c74f5d-1234-4321-a1b2-c3d4e5f6a7b8"; 

$checkbox_vars = ['ALLOW_RECONCILIATION', 'ALLOW_ROOT_DATASETS', 'RECURSE_CHILDREN'];
$text_vars = ['LOG_BASE'];

if ($_POST) {
    unset($savemsg);
    
    if (isset($_POST['save']) && $_POST['save']) {
        foreach ($checkbox_vars as $var) {
            $val = isset($_POST[$var]) ? "1" : "0";
            mwexec("sysrc -f " . escapeshellarg($configfile) . " " . escapeshellarg($var) . "=" . escapeshellarg($val));
        }
        if (isset($_POST['LOG_BASE'])) {
            mwexec("sysrc -f " . escapeshellarg($configfile) . " LOG_BASE=" . escapeshellarg($_POST['LOG_BASE']));
        }
        if (!empty($_POST['REPLICATE_SETS_ADD'])) {
            mwexec("sysrc -f " . escapeshellarg($configfile) . " REPLICATE_SETS+=" . escapeshellarg($_POST['REPLICATE_SETS_ADD']));
        }

        $a_cronjob = &arr::make_branch($config, 'cron', 'job');
        $index = arr::search_ex($xync_uuid, $a_cronjob, 'uuid');
        $preset = $_POST['SCHEDULE_PRESET'];

        if ($preset === 'none') {
            if ($index !== false) unset($a_cronjob[$index]);
        } else {
            $cron_record = [];
            $cron_record['enable'] = true; 
            $cron_record['uuid'] = $xync_uuid;
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
            $cron_record['command'] = $script_path . " --config " . $configfile;
            
            if ($index !== false) {
                $a_cronjob[$index] = $cron_record;
            } else {
                $a_cronjob[] = $cron_record;
            }
        }

        write_config();
        config_lock();
        rc_update_service('cron'); 
        config_unlock();
        $savemsg = gtext("Configuration updated.");
    }

    if (isset($_POST['delete_set']) && is_numeric($_POST['delete_set'])) {
        $idx = (int)$_POST['delete_set'];
        $raw = exec("sysrc -f " . escapeshellarg($configfile) . " -n REPLICATE_SETS 2>/dev/null");
        $sets = array_filter(explode(" ", $raw));
        // Re-index array after exploding to ensure numeric keys match
        $sets = array_values($sets); 
        if (isset($sets[$idx])) {
            unset($sets[$idx]);
            mwexec("sysrc -f " . escapeshellarg($configfile) . " REPLICATE_SETS=" . escapeshellarg(implode(" ", $sets)));
            $savemsg = gtext("Replication set removed.");
        }
    }
}

$current_values = [];
foreach (array_merge($checkbox_vars, $text_vars) as $var) {
    $val = exec("sysrc -f " . escapeshellarg($configfile) . " -n " . escapeshellarg($var) . " 2>/dev/null");
    if ($var === 'LOG_BASE' && empty($val)) { $val = $logbase; }
    $current_values[$var] = $val;
}

$current_preset = 'none';
$xml_status_text = "Disabled";
$job_index = arr::search_ex($xync_uuid, $config['cron']['job'] ?? [], 'uuid');

if ($job_index !== false) {
    $job = $config['cron']['job'][$job_index];
    if (($job['all_hours'] ?? '') === '1') $current_preset = 'hourly';
    elseif (($job['all_weekdays'] ?? '') === '0') $current_preset = 'weekly';
    else $current_preset = 'daily';

    $fmt = function($val, $default = '*') {
        return (is_array($val) || empty($val)) ? $default : (string)$val;
    };

    $m = $fmt($job['minute'], '0');
    $h = ($job['all_hours'] === '1') ? '*' : $fmt($job['hour'], '0');
    $d = ($job['all_days'] === '1') ? '*' : $fmt($job['day'], '*');
    $M = ($job['all_months'] === '1') ? '*' : $fmt($job['month'], '*');
    $w = ($job['all_weekdays'] === '1') ? '*' : $fmt($job['weekday'], '*');
    $who = $fmt($job['who'], 'root');
    $cmd = $fmt($job['command'], $script_path);
    $xml_status_text = "{$m} {$h} {$d} {$M} {$w} {$who} {$cmd}";
}

$raw_replicate_sets = exec("sysrc -f " . escapeshellarg($configfile) . " -n REPLICATE_SETS 2>/dev/null");
$replicate_sets_list = array_values(array_filter(explode(" ", $raw_replicate_sets)));

$pgtitle = [gtext("Extensions"), gtext('Xync')];
include 'fbegin.inc';
?>

<form action="<?=$sphere_scriptname;?>" method="post" id="iform">
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
                    ?>
                    
                    <?php if (!empty($replicate_sets_list)): ?>
                    <tr>
                        <td class="vncell"><?=gtext("Active Sets");?></td>
                        <td class="vtable">
                            <table width="100%" border="0" cellpadding="2" cellspacing="0">
                                <?php foreach ($replicate_sets_list as $idx => $set): ?>
                                <tr>
                                    <td width="80%"><code><?= htmlspecialchars($set); ?></code></td>
                                    <td width="20%">
                                        <button type="submit" name="delete_set" value="<?= $idx; ?>" class="formbtn" style="padding:2px 10px;">Delete</button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </table>
                        </td>
                    </tr>
                    <?php endif; ?>

                    <?php
                    html_titleline2(gettext("Replication Schedule"));
                    $opts = ['none' => 'Disabled', 'hourly' => 'Hourly', 'daily' => 'Daily', 'weekly' => 'Weekly'];
                    html_combobox2('SCHEDULE_PRESET', gettext('Frequency'), $current_preset, $opts, '', false);
                    ?>
                    <tr>
                        <td class="vncell"><?=gtext("Current schedule (cron).");?></td>
                        <td class="vtable">
                            <pre style="margin:0; padding:10px; background:#111; color:#55ff55; border-radius:4px; font-weight:bold;"><?= htmlspecialchars($xml_status_text); ?></pre>
                        </td>
                    </tr>
                    <?php
                    html_titleline2(gettext("Logging"));
                    html_inputbox2('LOG_BASE', gettext('Log Path'), $current_values['LOG_BASE'], '', false, 60);
                    ?>
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
