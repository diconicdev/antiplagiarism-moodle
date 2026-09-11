<?php

namespace plagiarism_smartdetector;

defined('MOODLE_INTERNAL') || die();

class file_extractor {

    /**
     * Mengambil teks dari semua file yang diunggah pada suatu submission
     * 
     * @param int $contextid Context ID dari assignment
     * @param int $itemid Submission ID
     * @return string Teks gabungan yang berhasil diekstraksi
     */
    public static function extract_text_from_submission(int $contextid, int $itemid): string {
        $fs = get_file_storage();
        
        // Ambil semua file yang ada di komponen assignsubmission_file area submission
        $files = $fs->get_area_files(
            $contextid, 
            'assignsubmission_file', 
            'submission_files', 
            $itemid, 
            'id ASC', 
            false
        );

        $extracted_text = '';

        foreach ($files as $file) {
            $extracted_text .= self::extract_text_from_file($file) . "\n\n";
        }

        return trim($extracted_text);
    }

    public static function extract_text_from_file(\stored_file $file): string {
        $mimetype = $file->get_mimetype();

        if ($mimetype === 'application/pdf') {
            return self::extract_pdf($file);
        } else if ($mimetype === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document') {
            return self::extract_docx($file);
        } else if ($mimetype === 'text/plain' || $mimetype === 'text/csv' || $mimetype === 'text/html') {
            return $file->get_content();
        }

        return '';
    }

    /**
     * Ekstraksi Teks dari File PDF
     */
    private static function extract_pdf(\stored_file $file): string {
        $apikey = get_config('plagiarism_smartdetector', 'api_key');
        if (!empty($apikey) && function_exists('curl_init')) {
            $curl = curl_init();
            $url = 'http://127.0.0.1:8000/v2/extract?filename=' . rawurlencode($file->get_filename());
            curl_setopt_array($curl, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $apikey,
                    'Content-Type: application/pdf',
                ],
                CURLOPT_POSTFIELDS => $file->get_content(),
                CURLOPT_TIMEOUT => 120,
            ]);
            $response = curl_exec($curl);
            $httpcode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);

            if ($response !== false && $httpcode >= 200 && $httpcode < 300) {
                $result = json_decode($response, true);
                if (is_array($result) && !empty($result['text'])) {
                    return trim($result['text']);
                }
            }
        }

        // Opsi 1: Gunakan pdftotext CLI jika terpasang di server (paling cepat & akurat)
        $content = '';
        $tempdir = make_request_directory();
        $filepath = $tempdir . '/' . $file->get_filename();
        $file->copy_content_to($filepath);

        // Cek apakah exec/shell_exec diizinkan dan pdftotext tersedia
        if (function_exists('shell_exec')) {
            $output = shell_exec('pdftotext ' . escapeshellarg($filepath) . ' -');
            if (!empty($output)) {
                return trim($output);
            }
        }

        // Opsi 2: Parse plain and FlateDecode PDF streams without external tools.
        $raw_content = $file->get_content();
        $streams = [$raw_content];
        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $raw_content, $stream_matches);
        foreach ($stream_matches[1] as $stream) {
            $decoded = @gzuncompress($stream);
            if ($decoded === false) {
                $decoded = @gzinflate($stream);
            }
            if ($decoded !== false) {
                $streams[] = $decoded;
            }
        }

        foreach ($streams as $stream) {
            preg_match_all('/\((?:\\.|[^\\)])*\)\s*Tj/s', $stream, $text_matches);
            foreach ($text_matches[0] as $text_match) {
                $text = preg_replace('/\s*Tj$/', '', $text_match);
                $text = substr($text, 1, -1);
                $content .= stripcslashes($text) . ' ';
            }
        }

        return trim($content);
    }

    /**
     * Ekstraksi Teks dari File DOCX (Microsoft Word)
     */
    private static function extract_docx(\stored_file $file): string {
        $text = '';
        $tempdir = make_request_directory();
        $filepath = $tempdir . '/' . $file->get_filename();
        $file->copy_content_to($filepath);

        $zip = new \ZipArchive();
        if ($zip->open($filepath) === true) {
            // Document text tersimpan di word/document.xml di dalam file zip docx
            if (($index = $zip->locateName('word/document.xml')) !== false) {
                $xml_data = $zip->getFromIndex($index);
                
                // Hilangkan namespace & tag XML untuk mengambil teks murni
                $xml_data = str_replace('</w:r></w:p>', "\n", $xml_data);
                $xml_data = str_replace('</w:p>', "\n", $xml_data);
                $text = strip_tags($xml_data);
            }
            $zip->close();
        }

        return trim($text);
    }
}