<?php
namespace plagiarism_smartdetector\task;

defined('MOODLE_INTERNAL') || die();

class analyze_submission extends \core\task\adhoc_task {

    public function execute() {
        global $DB;

        $data = $this->get_custom_data();
        $submissionid = is_object($data) ? ($data->submissionid ?? null) : ($data['submissionid'] ?? null);
        $cmid         = is_object($data) ? ($data->cmid ?? null) : ($data['cmid'] ?? null);

        if (!$submissionid || !$cmid) {
            return;
        }

        $this->set_status($submissionid, 'processing');

        $submission = $DB->get_record('assign_submission', ['id' => $submissionid]);
        if (!$submission) {
            $this->set_status($submissionid, 'failed', 'Submission tidak ditemukan.');
            return;
        }

        // 1. Ekstraksi teks dari file
        $fs = get_file_storage();
        $cm = get_coursemodule_from_id('assign', $cmid);
        if (!$cm) {
            $this->set_status($submissionid, 'failed', 'Course module tidak ditemukan.');
            return;
        }
        $context = \context_module::instance($cm->id);

        $extractedtext = \plagiarism_smartdetector\file_extractor::extract_text_from_submission(
            $context->id,
            $submission->id
        );

        $onlinetext = $DB->get_record('assignsubmission_onlinetext', ['submission' => $submissionid]);
        if ($onlinetext && !empty($onlinetext->onlinetext)) {
            $extractedtext .= strip_tags($onlinetext->onlinetext) . "\n";
        }

        $extractedtext = trim($extractedtext);
        if (empty($extractedtext)) {
            $this->set_status(
                $submissionid,
                'failed',
                'File tidak memiliki text layer yang dapat diekstrak. PDF hasil scan memerlukan OCR atau pdftotext.'
            );
            return;
        }

        // 2. Ambil dokumen tugas lain dalam course module yang sama untuk dijadikan pembanding plagiarisme
        $other_submissions = $DB->get_records_select(
            'assign_submission', 
            'assignment = :assignid AND id != :subid AND status = :status',
            ['assignid' => $submission->assignment, 'subid' => $submissionid, 'status' => 'submitted']
        );

        $existing_docs = [];
        foreach ($other_submissions as $other) {
            $other_files = $fs->get_area_files($context->id, 'assignsubmission_file', 'submission_files', $other->id, 'id ASC', false);
            $doc_text = '';
            foreach ($other_files as $f) {
                if (!$f->is_directory()) {
                    $doc_text .= \plagiarism_smartdetector\file_extractor::extract_text_from_file($f) . "\n";
                }
            }
            if (!empty(trim($doc_text))) {
                $existing_docs[] = trim($doc_text);
            }
        }

        $apikey = get_config('plagiarism_smartdetector', 'api_key');
        if (empty($apikey)) {
            $this->set_status($submissionid, 'failed', 'API key Smart Detector belum dikonfigurasi.');
            return;
        }
        
        // 3. Call Service Python Lokal
        $plagscore = $this->fetch_plagiarism_score($apikey, $extractedtext, $existing_docs);
        $aiscore   = $this->fetch_ai_score($apikey, $extractedtext);

        $status = ($plagscore !== false && $aiscore !== false) ? 'completed' : 'failed';
        $error = $status === 'failed' ? 'API tidak mengembalikan score yang valid.' : '';

        // 4. Update Database Moodle
        $existing = $DB->get_record('plagiarism_smartdetector_res', ['submissionid' => $submissionid]);

        if ($existing) {
            $existing->plagiarism_score = (float)($plagscore ?: 0);
            $existing->ai_score         = (float)($aiscore ?: 0);
            $existing->status           = $status;
            $existing->error_message    = $error;
            $existing->timemodified     = time();
            $DB->update_record('plagiarism_smartdetector_res', $existing);
        } else {
            $record = new \stdClass();
            $record->submissionid     = $submissionid;
            $record->userid           = $submission->userid;
            $record->cmid             = $cmid;
            $record->plagiarism_score = (float)($plagscore ?: 0);
            $record->ai_score         = (float)($aiscore ?: 0);
            $record->status           = $status;
            $record->error_message    = $error;
            $record->timecreated      = time();
            $record->timemodified     = time();
            $DB->insert_record('plagiarism_smartdetector_res', $record);
        }
    }

    private function set_status($submissionid, $status, $errormessage = '') {
        global $DB;

        $record = $DB->get_record('plagiarism_smartdetector_res', ['submissionid' => $submissionid]);
        if (!$record) {
            return;
        }

        $record->status = $status;
        $record->error_message = $errormessage;
        $record->timemodified = time();
        $DB->update_record('plagiarism_smartdetector_res', $record);
    }

    private function fetch_plagiarism_score($apikey, $text, $docs = []) {
        $url = 'http://127.0.0.1:8000/v2/plagiarism';
        $payload = json_encode([
            'text' => $text, 
            'existing_documents' => $docs,
            'language' => 'auto'
        ]);
        $response = $this->send_curl($url, $apikey, $payload);
        return $response['result']['score'] ?? false;
    }

    private function fetch_ai_score($apikey, $text) {
        $url = 'http://127.0.0.1:8000/v2/ai-content-detection';
        $payload = json_encode([
            'text' => $text,
            'sentences' => true,
            'language' => 'auto'
        ]);
        $response = $this->send_curl($url, $apikey, $payload);
        return $response['score'] ?? false;
    }

    private function send_curl($url, $apikey, $payload) {
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apikey,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => 15,
        ]);

        $res = curl_exec($curl);
        $httpcode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curlerror = curl_error($curl);
        curl_close($curl);

        if (!$res || $httpcode < 200 || $httpcode >= 300) {
            error_log('[SmartDetector] API error HTTP ' . $httpcode . ': ' . ($curlerror ?: (string)$res));
            return null;
        }

        return json_decode($res, true);
    }
}