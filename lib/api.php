<?php
// lib/api.php - 后端逻辑 
// ----------------------------------------------------
header('Content-Type: application/json; charset=utf-8');
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
error_reporting(E_ALL & ~E_NOTICE);

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/Functions.php';
require_once __DIR__ . '/AliyunClient.php';

$dataFile = __DIR__ . '/data.json';   
$cacheFile = __DIR__ . '/cache.json'; 

if (!empty($config['schedule']['timezone'])) {
    date_default_timezone_set($config['schedule']['timezone']);
}

$requestKey = isset($_GET['key']) ? $_GET['key'] : '';
$canExecute = ($requestKey === $config['cron_key']); 
$cacheTime = isset($config['cache_time']) ? $config['cache_time'] : 60;

// 缓存逻辑
if (!$canExecute && file_exists($cacheFile) && (time() - filemtime($cacheFile) < $cacheTime)) {
    $cachedData = json_decode(file_get_contents($cacheFile), true);
    if ($cachedData) {
        $fileTime = date('Y-m-d H:i:s', filemtime($cacheFile));
        $cachedData['mode'] = '只读模式 (缓存)';
        $cachedData['logs'][] = ['type'=>'INFO', 'msg'=>"[系统] 数据来自缓存 (更新于: $fileTime)", 'time'=>date('H:i:s')];
        echo json_encode($cachedData, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

try {
    $logsTopWarn = []; $logsInfo = []; $logsBottom = []; 
    $modeText = $canExecute ? '读写模式 (实时)' : '只读模式 (实时)';
    $isDryRun = $config['dry_run'];

    $lastState = file_exists($dataFile) ? json_decode(file_get_contents($dataFile), true) : ['percent' => 0, 'status' => 'Unknown', 'cost_alert_sent' => false];
    $client = new AliyunClient($config['access_key_id'], $config['access_key_secret'], $config['region_id']);

    // 1. 查流量
    $res = $client->request('cdt.aliyuncs.com', '2021-08-13', 'ListCdtInternetTraffic');
    $bytes = 0;
    if (!empty($res['TrafficDetails'])) {
        foreach ($res['TrafficDetails'] as $d) $bytes += isset($d['Traffic']) ? $d['Traffic'] : 0;
    }
    $usedGb = round($bytes / 1073741824, 2);
    $limitGb = $config['traffic_limit_gb'];
    $currentPercent = ($limitGb > 0) ? min(100, round(($usedGb / $limitGb) * 100, 2)) : 0;

    // 2. 查状态 & 查IP (本次新增逻辑)
    $ecsDomain = "ecs.{$config['region_id']}.aliyuncs.com";
    $res = $client->request($ecsDomain, '2014-05-26', 'DescribeInstances', ['InstanceIds' => json_encode([$config['instance_id']])]);
    
    // 获取状态
    $currentStatus = isset($res['Instances']['Instance'][0]['Status']) ? $res['Instances']['Instance'][0]['Status'] : 'Unknown';
    
    // 获取IP并打码
    $rawIp = '无公网IP';
    // 优先获取分配的公网IP
    if (!empty($res['Instances']['Instance'][0]['PublicIpAddress']['IpAddress'][0])) {
        $rawIp = $res['Instances']['Instance'][0]['PublicIpAddress']['IpAddress'][0];
    } 
    // 其次获取弹性公网IP (EIP)
    elseif (!empty($res['Instances']['Instance'][0]['EipAddress']['IpAddress'])) {
        $rawIp = $res['Instances']['Instance'][0]['EipAddress']['IpAddress'];
    }

    // IP 脱敏处理 (例如: 47.1.2.3 -> 47.1.xxx.3)
    $displayIp = $rawIp;
    if (filter_var($rawIp, FILTER_VALIDATE_IP)) {
        $parts = explode('.', $rawIp);
        if (count($parts) === 4) {
            $displayIp = $parts[0] . '.' . $parts[1] . '.xxx.' . $parts[3];
        }
    }

    // 3. 查账单
    $totalCost = 0.00; $currency = 'USD'; 
    try {
        $billingCycle = date('Y-m'); 
        $bssParams = ['BillingCycle' => $billingCycle, 'IsHideZeroCharge' => 'false', 'PageSize' => 100];
        $accountType = isset($config['account_type']) ? $config['account_type'] : 'intl';
        $bssEndpoint = ($accountType === 'cn') ? 'business.aliyuncs.com' : 'business.ap-southeast-1.aliyuncs.com';
        
        $billRes = $client->request($bssEndpoint, '2017-12-14', 'QueryInstanceBill', $bssParams);
        $items = isset($billRes['Data']['Items']['Item']) ? $billRes['Data']['Items']['Item'] : [];

        foreach ($items as $item) {
            $amountVal = isset($item['PretaxAmount']) ? floatval($item['PretaxAmount']) : 0;
            $itemId = isset($item['InstanceID']) ? $item['InstanceID'] : '';
            $currItemCurrency = isset($item['Currency']) ? $item['Currency'] : 'USD';
            
            if ($amountVal > 0) {
                $displayId = smartMaskId($itemId);         
                $fmtMoney = formatMoney($amountVal); 
                
                if ($itemId == $config['instance_id']) {
                    $totalCost += $amountVal;
                    $currency = $currItemCurrency;
                    $logsInfo[] = ['type'=>'INFO', 'msg'=>"账单匹配: {$fmtMoney} {$currency} (ID: {$displayId})", 'time'=>date('H:i:s')];
                } else {
                    $hint = "未知资源";
                    if (strpos($itemId, 'i-') === 0) $hint = "闲置ECS实例/或者已经删除";
                    elseif (strpos($itemId, 'eip-') === 0) $hint = "独立公网IP (EIP)";
                    elseif (strpos($itemId, 'cn-') === 0 || strpos($itemId, 'ap-') === 0 || strpos($itemId, 'us-') === 0 || strpos($itemId, 'eu-') === 0) $hint = "OSS存储/快照费用";
                    elseif (strpos($itemId, 'comm') !== false) $hint = "流量包/共用资源";
                    $logsTopWarn[] = ['type'=>'WARN', 'msg'=>"⚠️ 发现其他费用: {$fmtMoney} {$currItemCurrency} (ID: {$displayId}) - {$hint}", 'time'=>date('H:i:s')];
                }
            }
        }
    } catch (Exception $e) {
        $logsTopWarn[] = ['type'=>'ERROR', 'msg'=>"账单查询失败: " . $e->getMessage(), 'time'=>date('H:i:s')];
    }

    // 4. 汇总信息
    $logsInfo[] = ['type'=>'INFO', 'msg'=>"🖥️ 运行状态: {$currentStatus}", 'time'=>date('H:i:s')];
    $logsInfo[] = ['type'=>'INFO', 'msg'=>"📡 流量使用: {$usedGb}G", 'time'=>date('H:i:s')];
    $billDisplay = formatMoney($totalCost);
    $logsInfo[] = ['type'=>'INFO', 'msg'=>"💸 本月账单: {$billDisplay} {$currency}", 'time'=>date('H:i:s')];

    $costLimit = isset($config['cost_protection']['limit_money']) ? floatval($config['cost_protection']['limit_money']) : 0;
    $costStopEnabled = isset($config['cost_protection']['enable_stop']) ? $config['cost_protection']['enable_stop'] : false;

    // 余额/动作逻辑
    if ($costLimit > 0) {
        $remaining = $costLimit - $totalCost;
        if ($remaining < 0) $logsTopWarn[] = ['type'=>'WARN', 'msg'=>"🛡️ 距离安全金额阈值剩余: ".formatMoney($remaining)." {$currency} (已超额)", 'time'=>date('H:i:s')];
        else $logsInfo[] = ['type'=>'INFO', 'msg'=>"🛡️ 距离安全金额阈值剩余: ".formatMoney($remaining)." {$currency}", 'time'=>date('H:i:s')];
    } else {
        $logsInfo[] = ['type'=>'INFO', 'msg'=>"🛡️ 安全阈值未设置", 'time'=>date('H:i:s')];
    }

    $targetAction = 'NONE'; $reason = '';
    // 策略判断
    if ($costLimit > 0 && $totalCost >= $costLimit) {
        if ($costStopEnabled && $currentStatus != 'Stopped') { $targetAction = 'STOP'; $reason = "费用超标 ({$totalCost} >= {$costLimit})"; }
        $logsTopWarn[] = ['type'=>'WARN', 'msg'=>"⚠️ 费用已达阈值: {$totalCost} / {$costLimit}", 'time'=>date('H:i:s')];
    } elseif ($usedGb >= $limitGb) {
        if ($currentStatus != 'Stopped') { $targetAction = 'STOP'; $reason = "流量超标 ({$usedGb}G)"; }
    } else {
        $inOperatingHours = true;
        if ($config['schedule']['enable']) {
            $now = time(); $startT = strtotime($config['schedule']['start_time']); $stopT = strtotime($config['schedule']['stop_time']);
            if ($startT > $stopT) $inOperatingHours = ($now >= $startT || $now < $stopT); else $inOperatingHours = ($now >= $startT && $now < $stopT);
            if (!$inOperatingHours && $currentStatus != 'Stopped') { $targetAction = 'STOP'; $reason = "定时关机时间"; }
        }
        if ($inOperatingHours && $targetAction == 'NONE' && $currentStatus != 'Running' && $currentStatus != 'Starting') { $targetAction = 'START'; $reason = $config['schedule']['enable'] ? "定时开机" : "离线保活"; }
    }

    if ($canExecute && !$isDryRun) {
        if ($targetAction == 'START') {
            $client->request($ecsDomain, '2014-05-26', 'StartInstance', ['InstanceId' => $config['instance_id']]);
            $logsTopWarn[] = ['type'=>'WARN', 'msg'=>"🚀 执行开机: {$reason}", 'time'=>date('H:i:s')];
            sendNotify($config, "🚀 服务器已启动", "原因: {$reason}\n流量: {$currentPercent}%");
        } elseif ($targetAction == 'STOP') {
            $params = ['InstanceId' => $config['instance_id']];
            if (isset($config['stop_mode']) && $config['stop_mode'] == 1) $params['StoppedMode'] = 'StopCharging';
            $client->request($ecsDomain, '2014-05-26', 'StopInstance', $params);
            $logsTopWarn[] = ['type'=>'WARN', 'msg'=>"🛑 执行关机: {$reason}", 'time'=>date('H:i:s')];
            sendNotify($config, "🛑 服务器已停止", "原因: {$reason}\n账单: {$totalCost} {$currency}");
        }
    } elseif ($targetAction != 'NONE') {
        $logsTopWarn[] = ['type'=>'WARN', 'msg'=>"计划 {$targetAction}: {$reason} (未执行: 模式限制)", 'time'=>date('H:i:s')];
    } else {
        $logsBottom[] = ['type'=>'SUCCESS', 'msg'=>"系统健康，无操作", 'time'=>date('H:i:s')];
    }

    // 通知逻辑
    if ($config['bark']['enable'] || $config['telegram']['enable']) {
        $step = isset($config['bark']['notify_traffic_step']) ? intval($config['bark']['notify_traffic_step']) : 0;
        $currPct = intval($currentPercent);
        $lastPct = isset($lastState['percent']) ? intval($lastState['percent']) : 0;
        if ($step > 0 && floor($currPct / $step) > floor($lastPct / $step)) {
            sendNotify($config, "⚠️ 流量警报: 已用 {$currentPercent}%", "已消耗: {$usedGb}GB / {$limitGb}GB");
        }
        $lastCostAlert = isset($lastState['cost_alert_sent']) ? $lastState['cost_alert_sent'] : false;
        if (($costLimit > 0 && $totalCost >= $costLimit) && !$lastCostAlert) {
            sendNotify($config, "💰 费用熔断警告", "当前账单: {$totalCost} {$currency}");
            $logsTopWarn[] = ['type'=>'WARN', 'msg'=>"📢 发送费用熔断通知", 'time'=>date('H:i:s')];
            $lastCostAlert = true; 
        } elseif (!($costLimit > 0 && $totalCost >= $costLimit)) $lastCostAlert = false;
    } else {
        $lastCostAlert = ($costLimit > 0 && $totalCost >= $costLimit);
    }

    $finalLogs = array_merge($logsTopWarn, $logsInfo, $logsBottom);
    file_put_contents($dataFile, json_encode(['percent' => $currentPercent, 'status' => $currentStatus, 'cost_alert_sent' => $lastCostAlert]));

    // 格式化输出
    $displayStatus = str_replace(['Running','Stopped','Starting','Stopping','Unknown'], ['运行中','已停止','启动中','停止中','未知'], $currentStatus);
    $regionMap = ['cn-hongkong'=>'中国香港', 'cn-shanghai'=>'华东2 (上海)', 'cn-beijing'=>'华北2 (北京)', 'ap-southeast-1'=>'新加坡', 'ap-northeast-1'=>'日本 (东京)', 'us-west-1'=>'美国 (硅谷)'];
    $displayRegion = isset($regionMap[$config['region_id']]) ? $regionMap[$config['region_id']] : $config['region_id'];

    $outputData = [
        'success' => true,
        'data' => [
            'status' => $displayStatus,
            'ip_address' => $displayIp, // <--- 新增字段
            'used' => $usedGb,
            'limit' => $limitGb,
            'percent' => $currentPercent,
            'region' => $displayRegion,
            'bill_amount' => $totalCost,
            'bill_currency' => $currency,
            'bill_limit' => $costLimit,
            'cost_stop_enabled' => $costStopEnabled
        ],
        'logs' => $finalLogs,
        'mode' => $modeText
    ];

    file_put_contents($cacheFile, json_encode($outputData, JSON_UNESCAPED_UNICODE));
    echo json_encode($outputData, JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'msg' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>