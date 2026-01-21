<?php
// lib/AliyunClient.php - 阿里云 API 通信
class AliyunClient {
    private $ak, $sk, $region;

    public function __construct($ak, $sk, $region) {
        $this->ak = $ak;
        $this->sk = $sk;
        $this->region = $region;
    }

    public function request($domain, $version, $action, $params = []) {
        $params = array_merge([
            'Format'           => 'JSON',
            'Version'          => $version,
            'AccessKeyId'      => $this->ak,
            'SignatureMethod'  => 'HMAC-SHA1',
            'Timestamp'        => gmdate('Y-m-d\TH:i:s\Z'),
            'SignatureVersion' => '1.0',
            'SignatureNonce'   => md5(uniqid(mt_rand(), true)),
            'Action'           => $action,
            'RegionId'         => $this->region
        ], $params);

        $params['Signature'] = $this->sign($params);
        return $this->send($domain, $params);
    }

    private function sign($params) {
        ksort($params);
        $q = '';
        foreach ($params as $k => $v) {
            $q .= '&' . rawurlencode($k) . '=' . rawurlencode($v);
        }
        return base64_encode(hash_hmac('sha1', 'POST&%2F&' . rawurlencode(substr($q, 1)), $this->sk . '&', true));
    }

    private function send($domain, $params) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://{$domain}/");
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        
        $res = curl_exec($ch);
        if (curl_errno($ch)) {
            throw new Exception(curl_error($ch));
        }
        curl_close($ch);
        
        $json = json_decode($res, true);
        if ($json === null && $res) {
            throw new Exception("API Error: " . strip_tags(substr($res, 0, 100)));
        }
        return $json;
    }
}
?>