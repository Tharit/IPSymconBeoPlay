<?php

trait BeoPlayAPI {
    private function BeoPlayAPIRequest($host, $path, $body = null, $method = null) {
        $url = "http://" . $host . $path;

        $headers = [];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        //curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        //curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        if($method) {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        }
        if($body) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
            if(!$method) {
                curl_setopt($ch, CURLOPT_POST, 1);
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        $result = curl_exec($ch);
        curl_close($ch);

        return @json_decode($result, true);
    }

    private function BeoPlayAPISetVolume($host, $volume) {
        $path = '/BeoZone/Zone/Sound/Volume/Speaker/Level';
        return $this->BeoPlayAPIRequest($host, $path, ["level" => $volume], "PUT");
    }

    private function BeoPlayAPIPlay($host) {
        $path = '/BeoZone/Zone/Stream/Play';
        return $this->BeoPlayAPIRequest($host, $path, null, "POST");
    }

    private function BeoPlayAPIPause($host) {
        $path = '/BeoZone/Zone/Stream/Pause';
        return $this->BeoPlayAPIRequest($host, $path, null, "POST");
    }

    private function BeoPlayAPIStop($host) {
        $path = '/BeoZone/Zone/Stream/Stop';
        return $this->BeoPlayAPIRequest($host, $path, null, "POST");
    }

    private function BeoPlayAPINext($host) {
        $path = '/BeoZone/Zone/Stream/Forward';
        return $this->BeoPlayAPIRequest($host, $path, null, "POST");
    }

    private function BeoPlayAPIPrev($host) {
        $path = '/BeoZone/Zone/Stream/Backward';
        return $this->BeoPlayAPIRequest($host, $path, null, "POST");
    }
}