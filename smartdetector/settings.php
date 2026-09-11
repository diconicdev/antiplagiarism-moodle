<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/plagiarismlib.php');

// 1. Panggil section name bawaan Moodle untuk plugin plagiarism
admin_externalpage_setup('plagiarismsmartdetector');
require_capability('moodle/site:config', context_system::instance());

// 2. Proses simpan form
$statusmessage = '';
if (data_submitted() && confirm_sesskey()) {
    $enabled = optional_param('enabled', 0, PARAM_INT);
    $apikey  = optional_param('api_key', '', PARAM_TEXT);

    set_config('enabled', $enabled, 'plagiarism_smartdetector');
    set_config('api_key', $apikey, 'plagiarism_smartdetector');

    $statusmessage = get_string('changessaved');
}

// 3. Render Header
$PAGE->set_url('/plagiarism/smartdetector/settings.php');
$PAGE->set_title(get_string('pluginname', 'plagiarism_smartdetector'));
$PAGE->set_heading(get_string('pluginname', 'plagiarism_smartdetector'));

echo $OUTPUT->header();

if (!empty($statusmessage)) {
    echo $OUTPUT->notification($statusmessage, 'notifysuccess');
}

$enabled = get_config('plagiarism_smartdetector', 'enabled');
$apikey  = get_config('plagiarism_smartdetector', 'api_key');
?>

<form method="post" action="settings.php">
    <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
    
    <table class="form-table">
        <tr>
            <th scope="row"><label for="id_enabled">Aktifkan Plugin</label></th>
            <td>
                <input type="checkbox" id="id_enabled" name="enabled" value="1" <?php echo $enabled ? 'checked' : ''; ?>>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="id_api_key">API Key Detector</label></th>
            <td>
                <input type="text" id="id_api_key" name="api_key" value="<?php echo s($apikey); ?>" class="form-control" style="width: 300px;">
            </td>
        </tr>
    </table>

    <div class="form-group mt-3">
        <input type="submit" class="btn btn-primary" value="<?php echo get_string('savechanges'); ?>">
    </div>
</form>

<?php
echo $OUTPUT->footer();