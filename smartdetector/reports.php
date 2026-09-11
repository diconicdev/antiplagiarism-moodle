<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('plagiarismsmartdetector');
require_capability('moodle/site:config', context_system::instance());

$PAGE->set_url('/plagiarism/smartdetector/reports.php');
$PAGE->set_title('Laporan Plagiarisme & AI');
$PAGE->set_heading('Semua Hasil Pemindaian Tugas');

echo $OUTPUT->header();

global $DB;

// Ambil data hasil deteksi beserta nama siswa dan tugasnya
$sql = "SELECT r.*, u.firstname, u.lastname, a.name AS assignment_name
          FROM {plagiarism_smartdetector_res} r
          JOIN {user} u ON u.id = r.userid
     LEFT JOIN {assign_submission} s ON s.id = r.submissionid
     LEFT JOIN {assign} a ON a.id = s.assignment
      ORDER BY r.timecreated DESC";

$records = $DB->get_records_sql($sql);

echo '<table class="table table-bordered table-striped mt-3">
        <thead>
            <tr>
                <th>Siswa</th>
                <th>Tugas</th>
                <th>Plagiarism Score</th>
                <th>AI Score</th>
                <th>Status</th>
                <th>Waktu Scan</th>
            </tr>
        </thead>
        <tbody>';

if (empty($records)) {
    echo '<tr><td colspan="6" class="text-center">Belum ada data pemindaian.</td></tr>';
} else {
    foreach ($records as $rec) {
        echo '<tr>
                <td>' . s($rec->firstname . ' ' . $rec->lastname) . '</td>
                <td>' . s($rec->assignment_name ?? '-') . '</td>
                <td><strong>' . number_format($rec->plagiarism_score, 1) . '%</strong></td>
                <td><strong>' . number_format($rec->ai_score, 1) . '%</strong></td>
                <td><span class="badge bg-info">' . s($rec->status) . '</span></td>
                <td>' . userdate($rec->timecreated) . '</td>
              </tr>';
    }
}

echo '</tbody></table>';
echo '<p class="mt-3"><strong>Catatan:</strong> Plagiarism Score dan AI Score dihitung berdasarkan hasil pemindaian dari API Smart Detector. Nilai 0% menunjukkan tidak ada indikasi plagiarisme atau konten AI, sedangkan nilai 100% menunjukkan indikasi tinggi.</p>';
echo '<p class="mt-3"><strong>API Key:</strong> ' . get_config('plagiarism_smartdetector', 'api_key') . '</p>';
echo $OUTPUT->footer();