<?php
// lib/Functions.php - 工具函数

// 智能 ID 脱敏
function smartMaskId($id) {
    if (empty($id)) return 'Unknown';
    // 地区ID不打码
    if (strpos($id, 'cn-') === 0 || strpos($id, 'ap-') === 0 || strpos($id, 'us-') === 0 || strpos($id, 'eu-') === 0) {
        return $id;
    }
    // 短ID不打码
    if (strlen($id) < 8) return $id;
    // 资源ID: 保留前7位 + 中间打码 + 后3位
    return substr($id, 0, 7) . '.....' . substr($id, -3);
}

// 金额格式化
function formatMoney($amount) {
    return rtrim(rtrim(sprintf('%.8f', floatval($amount)), '0'), '.');
}

// 发送通知 (Bark/Telegram)
function sendNotify($config, $title, $body) {
    // Bark
    if ($config['bark']['enable'] && !empty($config['bark']['server'])) {
        $url = rtrim($config['bark']['server'], '/') . '/' . rawurlencode($title) . '/' . rawurlencode($body);
        asyncCurl($url);
    }
    // Telegram
    if ($config['telegram']['enable'] && !empty($config['telegram']['bot_token']) && !empty($config['telegram']['chat_id'])) {
        $tgUrl = "https://api.telegram.org/bot" . $config['telegram']['bot_token'] . "/sendMessage";
        $text = "<b>" . $title . "</b>\n" . $body;
        $postData = [
            'chat_id' => $config['telegram']['chat_id'],
            'text' => $text,
            'parse_mode' => 'HTML'
        ];
        asyncCurl($tgUrl, $postData);
    }
}

// 异步/简单请求
function asyncCurl($url, $postData = null) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 2); 
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    if ($postData) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    }
    curl_exec($ch);
    curl_close($ch);
}
?>