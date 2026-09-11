<?php

namespace plagiarism_smartdetector;

defined('MOODLE_INTERNAL') || die();

class api_client {
    private $apikey;

    public function __construct() {
        $this->apikey = get_config('plagiarism_smartdetector', 'api_key');
    }

    public function scan_text(string $text): array {
        $curl = new \curl();
        $options = [
            'CURLOPT_HTTPHEADER' => [
                'Authorization: Bearer ' . $this->apikey,
                'Content-Type: application/json'
            ]
        ];
        
        $payload = json_encode([
            'text' => $text,
            'sentences' => true,
            'ai_detection' => true,
            'plagiarism_detection' => true
        ]);

        // Ganti URL dengan endpoint resmi provider API (e.g., Winston AI / Scribbr API wrapper)
        $response = $curl->post('https://api.gowinston.ai/v2/predict', $payload, $options);
        return json_decode($response, true) ?? [];
    }
}