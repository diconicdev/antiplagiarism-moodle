<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Class utama plugin plagiarism untuk Moodle core API
 */
class plagiarism_plugin_smartdetector {

    /**
     * Menampilkan pesan pemberitahuan/privasi jika dikonfigurasi.
     *
     * @param int $cmid Course module ID
     * @return string HTML/Teks disclosure
     */
    public function print_disclosure($cmid) {
        return ''; // Kosongkan jika tidak ada pesan privasi khusus
    }

    /**
     * Menampilkan badge/link skor di halaman grading guru
     *
     * @param array $linkarray Parameter bawaan Moodle
     * @return string HTML badge
     */
    public function get_links($linkarray) {
        global $DB;

        // Cek apakah plugin diaktifkan di site administration
        if (!get_config('plagiarism_smartdetector', 'enabled')) {
            return '';
        }

        $submissionid = $this->get_submission_id($linkarray);
        if (!$submissionid) {
            return '';
        }

        // Ambil data hasil scan dari database
        $res = $DB->get_record('plagiarism_smartdetector_res', ['submissionid' => $submissionid]);

        if (!$res) {
            return '<div class="mt-1"><span class="badge bg-secondary text-white p-1" style="font-size:0.75rem;">Detector: Pending...</span></div>';
        }

        if (in_array($res->status, ['pending', 'processing'], true)) {
            return '<div class="mt-1"><span class="badge bg-info text-white p-1" style="font-size:0.75rem;">Detector: Scanning...</span></div>';
        }

        if ($res->status === 'failed') {
            return '<div class="mt-1"><span class="badge bg-danger text-white p-1" style="font-size:0.75rem;">Detector: Error</span></div>';
        }

        // Tentukan warna badge berdasarkan persentase
        $plag_badge = $res->plagiarism_score > 25 ? 'bg-danger' : ($res->plagiarism_score > 10 ? 'bg-warning text-dark' : 'bg-success');
        $ai_badge   = $res->ai_score > 50 ? 'bg-danger' : ($res->ai_score > 20 ? 'bg-warning text-dark' : 'bg-success');

        $html = '<div class="plagiarism-badge-box border rounded p-2 mt-1 bg-light" style="display:inline-block; font-size:0.8rem; line-height:1.4;">';
        $html .= '<div>Plagiarism: <span class="badge ' . $plag_badge . ' text-white">' . number_format($res->plagiarism_score, 1) . '%</span></div>';
        $html .= '<div>AI Content: <span class="badge ' . $ai_badge . ' text-white">' . number_format($res->ai_score, 1) . '%</span></div>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Resolve the assignment submission represented by a plagiarism hook call.
     *
     * Assignment passes submissionid for some views, while submission plugins
     * pass either the file item id or the assignment/user pair.
     *
     * @param array $linkarray
     * @return int|null
     */
    private function get_submission_id(array $linkarray) {
        global $DB;

        if (!empty($linkarray['submissionid'])) {
            return (int)$linkarray['submissionid'];
        }

        if (!empty($linkarray['file']) && $linkarray['file'] instanceof stored_file) {
            return (int)$linkarray['file']->get_itemid();
        }

        if (empty($linkarray['assignment']) || empty($linkarray['userid'])) {
            return null;
        }

        $submissions = $DB->get_records('assign_submission', [
            'assignment' => (int)$linkarray['assignment'],
            'userid' => (int)$linkarray['userid'],
        ], 'id DESC', 'id', 0, 1);

        $submission = reset($submissions);
        return $submission ? (int)$submission->id : null;
    }
}

/**
 * Procedural Hooks yang dipanggil oleh Moodle Assign Module
 */
function plagiarism_smartdetector_get_file_links(array $linkarray): string {
    $plugin = new plagiarism_plugin_smartdetector();
    return $plugin->get_links($linkarray);
}

function plagiarism_smartdetector_get_links(array $linkarray): string {
    $plugin = new plagiarism_plugin_smartdetector();
    return $plugin->get_links($linkarray);
}

/**
 * Hook pendaftaran halaman Admin Settings
 */
function plagiarism_smartdetector_admin_tree(admin_root $adminroot) {
    $adminroot->add('plagiarism', new admin_externalpage(
        'plagiarismsmartdetector',
        get_string('pluginname', 'plagiarism_smartdetector'),
        new moodle_url('/plagiarism/smartdetector/settings.php')
    ));
}