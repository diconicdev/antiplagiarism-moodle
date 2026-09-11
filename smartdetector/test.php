<?php


     $apikey = '8HzYwd9ifX7SRiAR5gZXw2IUsMeZWHoaKsSako8rb20a3e69';
     $sampletext = "Artificial intelligence is transforming education by enabling personalized learning experiences and automating administrative tasks for educators worldwide.";

     function test_plagiarism_api() {
        global $apikey, $sampletext;
        

        $url = 'http://127.0.0.1:8000/v2/plagiarism';
        $payload = json_encode(['text' => $sampletext, 'language' => 'auto']);

        return $res = execute_curl($url, $payload);
        
       
    }

     function test_ai_detection_api() {
        global $apikey, $sampletext;
        

        $url = 'http://127.0.0.1:8000/v2/ai-content-detection';
        $payload = json_encode(['text' => $sampletext, 'sentences' => true, 'language' => 'auto']);

        return $res = execute_curl($url, $payload);

       
    }

     function execute_curl($url, $payload) {
        global $apikey;
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
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $response = curl_exec($curl);
        $httpcode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

       

        return json_decode($response, true);
    }

    print_r(test_plagiarism_api());
    print_r(test_ai_detection_api());