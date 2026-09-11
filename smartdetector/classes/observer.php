<?php

namespace plagiarism_smartdetector;

defined('MOODLE_INTERNAL') || die();

class observer {
    public static function submission_submitted(\core\event\base $event) {
        global $DB;

        // Log debugging ke PHP error log
        error_log('[SmartDetector] Observer terpanggil oleh Event: ' . $event->eventname);

        if (!get_config('plagiarism_smartdetector', 'enabled')) {
            error_log('[SmartDetector] Plugin belum diaktifkan di admin settings!');
            return;
        }

        // Ambil ID dari event
        $submissionid = $event->objectid;
        $userid       = $event->userid;
        $cmid         = $event->contextinstanceid;

        if (!$submissionid) {
            return;
        }

        // Simpan status awal 'pending' ke database
        $existing = $DB->get_record('plagiarism_smartdetector_res', ['submissionid' => $submissionid]);

        if (!$existing) {
            $record = new \stdClass();
            $record->submissionid     = $submissionid;
            $record->userid           = $userid;
            $record->cmid             = $cmid;
            $record->plagiarism_score = 0;
            $record->ai_score         = 0;
            $record->status           = 'pending';
            $record->timecreated      = time();
            $record->timemodified     = time();

            $DB->insert_record('plagiarism_smartdetector_res', $record);
            error_log('[SmartDetector] Data pending berhasil dibuat untuk Submission ID: ' . $submissionid);
        } else {
            $existing->status       = 'pending';
            $existing->timemodified = time();
            $DB->update_record('plagiarism_smartdetector_res', $existing);
        }

        // Buat adhoc task untuk eksekusi di latar belakang
        $task = new \plagiarism_smartdetector\task\analyze_submission();
        $task->set_custom_data([
            'submissionid' => $submissionid,
            'userid'       => $userid,
            'cmid'         => $cmid
        ]);
        \core\task\manager::queue_adhoc_task($task);
    }
}