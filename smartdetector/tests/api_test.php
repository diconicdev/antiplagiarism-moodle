<?php
namespace plagiarism_smartdetector;

defined('MOODLE_INTERNAL') || die();

class api_test extends \advanced_testcase {

    public function test_winston_ai_connection() {
        $this->resetAfterTest(true);

        $apikey = '8HzYwd9ifX7SRiAR5gZXw2IUsMeZWHoaKsSako8rb20a3e69';
        $apiurl = 'https://api.gowinston.ai/v2/predict';

        $testtext = "Artificial intelligence is transforming education by enabling personalized learning experiences and automating administrative tasks for educators worldwide.";

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $apiurl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apikey,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode(['text' => $testtext]),
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $response = curl_exec($curl);
        $httpcode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        $this->assertEquals(200, $httpcode, "Koneksi ke Winston AI gagal! HTTP Status Code: " . $httpcode);

        $result = json_decode($response, true);
        $this->assertArrayHasKey('score', $result, "Respon Winston AI tidak memiliki key 'score'");
    }
}