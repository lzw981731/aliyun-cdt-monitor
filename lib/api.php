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
        $cachedData['logs'][] = ['type' => 'INFO', 'msg' => "[系统] 数据来自缓存 (更新于: $fileTime)", 'time' => date('H:i:s')];
        echo json_encode($cachedData, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

try {
    $logsTopWarn = [];
    $logsInfo = [];
    $logsBottom = [];
    $modeText = $canExecute ? '读写模式 (实时)' : '只读模式 (实时)';
    $isDryRun = $config['dry_run'];

    $lastState = file_exists($dataFile) ? json_decode(file_get_contents($dataFile), true) : ['percent' => 0, 'status' => 'Unknown', 'cost_alert_sent' => false];

    $startRetryCount = isset($lastState['start_retry_count']) ? intval($lastState['start_retry_count']) : 0;
    $startSleepMode = isset($lastState['start_sleep_mode']) ? $lastState['start_sleep_mode'] : false;

    $client = new AliyunClient($config['access_key_id'], $config['access_key_secret'], $config['region_id']);

    // 1. 查流量
    $res = $client->request('cdt.aliyuncs.com', '2021-08-13', 'ListCdtInternetTraffic');
    $bytes = 0;
    if (!empty($res['TrafficDetails'])) {
        foreach ($res['TrafficDetails'] as $d)
            $bytes += isset($d['Traffic']) ? $d['Traffic'] : 0;
    }
    $usedGb = round($bytes / 1073741824, 2);
    $limitGb = $config['traffic_limit_gb'];
    $currentPercent = ($limitGb > 0) ? min(100, round(($usedGb / $limitGb) * 100, 2)) : 0;

    // 2. 查状态 & 查IP (本次新增逻辑)
    $ecsDomain = "ecs.{$config['region_id']}.aliyuncs.com";
    $res = $client->request($ecsDomain, '2014-05-26', 'DescribeInstances', ['InstanceIds' => json_encode([$config['instance_id']])]);

    // 获取状态
    $currentStatus = isset($res['Instances']['Instance'][0]['Status']) ? $res['Instances']['Instance'][0]['Status'] : 'Unknown';

    if ($currentStatus === 'Running') {
        if ($startSleepMode && ($config['bark']['enable'] || $config['telegram']['enable'])) {
            sendNotify($config, "✅ 服务器重启成功", "系统已恢复运行状态，启动重试次数清零，解除推送通知休眠。");
            $logsInfo[] = ['type' => 'SUCCESS', 'msg' => "✅ 服务器已恢复运行，解除通知休眠", 'time' => date('H:i:s')];
        }
        $startRetryCount = 0;
        $startSleepMode = false;
    }

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

    // 获取 IPv6并尝试脱敏
    $ipv6Raw = '';
    if (!empty($res['Instances']['Instance'][0]['NetworkInterfaces']['NetworkInterface'][0]['NetworkInterfaceId'])) {
        $eniId = $res['Instances']['Instance'][0]['NetworkInterfaces']['NetworkInterface'][0]['NetworkInterfaceId'];
        // 通过网卡ID额外查询IPv6地址详情
        try {
            $eniRes = $client->request($ecsDomain, '2014-05-26', 'DescribeNetworkInterfaces', ['NetworkInterfaceId.1' => $eniId]);
            if (!empty($eniRes['NetworkInterfaceSets']['NetworkInterfaceSet'][0]['Ipv6Sets']['Ipv6Set'][0]['Ipv6Address'])) {
                $ipv6Raw = $eniRes['NetworkInterfaceSets']['NetworkInterfaceSet'][0]['Ipv6Sets']['Ipv6Set'][0]['Ipv6Address'];
            }
        } catch (Exception $e) {
            $logsTopWarn[] = ['type' => 'WARN', 'msg' => "⚠️ IPv6查询失败: " . $e->getMessage(), 'time' => date('H:i:s')];
        }
    }

    $displayIpv6 = $ipv6Raw;
    if ($ipv6Raw && filter_var($ipv6Raw, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        // 解构脱敏: 只保留第一组十六进制，后面使用 ellipsis，以及最后一组
        $parts = explode(':', trim($ipv6Raw));

        $firstPart = $parts[0];

        $lastPart = '';
        for ($i = count($parts) - 1; $i >= 0; $i--) {
            if ($parts[$i] !== '') {
                $lastPart = $parts[$i];
                break;
            }
        }

        if ($lastPart === '' || $lastPart === $firstPart) {
            $lastPart = 'xxxx';
        }

        $displayIpv6 = $firstPart . ':...:' . $lastPart;
    }

    // 3. 查账单
    $totalCost = 0.00;
    $currency = 'USD';
    try {
        $billingCycle = date('Y-m');
        $bssParams = ['BillingCycle' => $billingCycle, 'IsHideZeroCharge' => 'false', 'PageSize' => 300];
        $accountType = isset($config['account_type']) ? $config['account_type'] : 'intl';
        $bssEndpoint = ($accountType === 'cn') ? 'business.aliyuncs.com' : 'business.ap-southeast-1.aliyuncs.com';

        $billRes = $client->request($bssEndpoint, '2017-12-14', 'QueryInstanceBill', $bssParams);
        $items = isset($billRes['Data']['Items']['Item']) ? $billRes['Data']['Items']['Item'] : [];

        foreach ($items as $item) {
            $amountVal = isset($item['PretaxAmount']) ? floatval($item['PretaxAmount']) : 0;
            $itemId = isset($item['InstanceID']) ? $item['InstanceID'] : '';
            $currItemCurrency = isset($item['Currency']) ? $item['Currency'] : 'USD';

            if ($amountVal != 0) {
                $totalCost += $amountVal;
                $currency = $currItemCurrency;
                $displayId = smartMaskId($itemId);
                $fmtMoney = formatMoney($amountVal);

                if ($itemId == $config['instance_id']) {
                    $logsInfo[] = ['type' => 'INFO', 'msg' => "账单匹配: {$fmtMoney} {$currency} (当前实例)", 'time' => date('H:i:s')];
                } else {
                    $hint = "其他资源";
                    if (strpos($itemId, 'i-') === 0)
                        $hint = "其他/历史ECS实例";
                    elseif (strpos($itemId, 'eip-') === 0)
                        $hint = "独立公网IP (EIP)";
                    elseif (strpos($itemId, 'cn-') === 0 || strpos($itemId, 'ap-') === 0)
                        $hint = "存储/快照/网络费用";
                    $logsTopWarn[] = ['type' => 'WARN', 'msg' => "发现费用: {$fmtMoney} {$currency} (${hint})", 'time' => date('H:i:s')];
                }
            }
        }
    } catch (Exception $e) {
        $logsTopWarn[] = ['type' => 'ERROR', 'msg' => "账单查询失败: " . $e->getMessage(), 'time' => date('H:i:s')];
    }

    // --- 历史账单获取逻辑 ---
    $prevBills = [];
    $historyFile = __DIR__ . '/history_cache.json';
    $historyCache = file_exists($historyFile) ? json_decode(file_get_contents($historyFile), true) : [];

    try {
        for ($i = 1; $i <= 2; $i++) {
            $month = date('Y-m', strtotime("-$i month"));

            // 账单查询 (计算该月总费用)
            if (isset($historyCache[$month]['bill'])) {
                $histAmount = $historyCache[$month]['bill'];
            } else {
                $histRes = $client->request($bssEndpoint, '2017-12-14', 'QueryInstanceBill', ['BillingCycle' => $month, 'IsHideZeroCharge' => 'false', 'PageSize' => 300]);
                $histItems = isset($histRes['Data']['Items']['Item']) ? $histRes['Data']['Items']['Item'] : [];
                $histAmount = 0;
                foreach ($histItems as $hItem) {
                    $histAmount += floatval($hItem['PretaxAmount']);
                }
                if ($histAmount != 0 && date('Y-m') > $month) {
                    $historyCache[$month]['bill'] = $histAmount;
                }
            }
            $prevBills[] = ['month' => $month, 'amount' => $histAmount];
        }
        file_put_contents($historyFile, json_encode($historyCache));
    } catch (Exception $e) {
        // 报错不中断主流程
    }
    // --------------------------------

    // 4. 汇总信息
    $logsInfo[] = ['type' => 'INFO', 'msg' => "🖥️ 运行状态: {$currentStatus}", 'time' => date('H:i:s')];
    $logsInfo[] = ['type' => 'INFO', 'msg' => "📡 流量使用: {$usedGb}G", 'time' => date('H:i:s')];
    $billDisplay = formatMoney($totalCost);
    $logsInfo[] = ['type' => 'INFO', 'msg' => "💸 本月账单: {$billDisplay} {$currency}", 'time' => date('H:i:s')];

    $costLimit = isset($config['cost_protection']['limit_money']) ? floatval($config['cost_protection']['limit_money']) : 0;
    $costStopEnabled = isset($config['cost_protection']['enable_stop']) ? $config['cost_protection']['enable_stop'] : false;

    // 余额/动作逻辑
    if ($costLimit > 0) {
        $remaining = $costLimit - $totalCost;
        if ($remaining < 0)
            $logsTopWarn[] = ['type' => 'WARN', 'msg' => "🛡️ 距离安全金额阈值剩余: " . formatMoney($remaining) . " {$currency} (已超额)", 'time' => date('H:i:s')];
        else
            $logsInfo[] = ['type' => 'INFO', 'msg' => "🛡️ 距离安全金额阈值剩余: " . formatMoney($remaining) . " {$currency}", 'time' => date('H:i:s')];
    } else {
        $logsInfo[] = ['type' => 'INFO', 'msg' => "🛡️ 安全阈值未设置", 'time' => date('H:i:s')];
    }

    $targetAction = 'NONE';
    $reason = '';
    // 策略判断
    if ($costLimit > 0 && $totalCost >= $costLimit) {
        if ($costStopEnabled && $currentStatus != 'Stopped') {
            $targetAction = 'STOP';
            $reason = "费用超标 ({$totalCost} >= {$costLimit})";
        }
        $logsTopWarn[] = ['type' => 'WARN', 'msg' => "⚠️ 费用已达阈值: {$totalCost} / {$costLimit}", 'time' => date('H:i:s')];
    } elseif ($usedGb >= $limitGb) {
        if ($currentStatus != 'Stopped') {
            $targetAction = 'STOP';
            $reason = "流量超标 ({$usedGb}G)";
        }
    } else {
        $inOperatingHours = true;
        if ($config['schedule']['enable']) {
            $now = time();
            $startT = strtotime($config['schedule']['start_time']);
            $stopT = strtotime($config['schedule']['stop_time']);
            if ($startT > $stopT)
                $inOperatingHours = ($now >= $startT || $now < $stopT);
            else
                $inOperatingHours = ($now >= $startT && $now < $stopT);
            if (!$inOperatingHours && $currentStatus != 'Stopped') {
                $targetAction = 'STOP';
                $reason = "定时关机时间";
            }
        }
        if ($inOperatingHours && $targetAction == 'NONE' && $currentStatus != 'Running' && $currentStatus != 'Starting') {
            $targetAction = 'START';
            $reason = $config['schedule']['enable'] ? "定时开机" : "离线保活";
        }
    }

    if ($canExecute && !$isDryRun) {
        if ($targetAction == 'START') {
            $client->request($ecsDomain, '2014-05-26', 'StartInstance', ['InstanceId' => $config['instance_id']]);

            $maxRetries = isset($config['schedule']['max_start_retries']) ? intval($config['schedule']['max_start_retries']) : 3;

            if (!$startSleepMode) {
                $startRetryCount++;
                if ($startRetryCount >= $maxRetries) {
                    $startSleepMode = true;
                    // 发送最后一次休眠通知
                    sendNotify($config, "💤 开机通知已休眠", "开机尝试已连续失败 ({$maxRetries}次)，不再循环推送开机通知，如需查看状态可以前往监控前端查看。\n(直到后台重启成功后将自动解除休眠)");
                    $logsTopWarn[] = ['type' => 'WARN', 'msg' => "🛑 达到最大重试次数 ({$maxRetries}次)，开机通知进入休眠状态", 'time' => date('H:i:s')];
                } else {
                    sendNotify($config, "🚀 服务器正在开机", "尝试次数: {$startRetryCount}/{$maxRetries}\n原因: {$reason}\n流量: {$currentPercent}%");
                    $logsTopWarn[] = ['type' => 'WARN', 'msg' => "🚀 执行开机 ({$startRetryCount}/{$maxRetries}): {$reason}", 'time' => date('H:i:s')];
                }
            } else {
                // 休眠状态下静默启动，不发推送
                $logsTopWarn[] = ['type' => 'WARN', 'msg' => "💤 开机重试推送休眠中... (仍在后台尝试静默开机)", 'time' => date('H:i:s')];
            }
        } elseif ($targetAction == 'STOP') {
            $params = ['InstanceId' => $config['instance_id']];
            if (isset($config['stop_mode']) && $config['stop_mode'] == 1)
                $params['StoppedMode'] = 'StopCharging';
            $client->request($ecsDomain, '2014-05-26', 'StopInstance', $params);
            $logsTopWarn[] = ['type' => 'WARN', 'msg' => "🛑 执行关机: {$reason}", 'time' => date('H:i:s')];
            sendNotify($config, "🛑 服务器已停止", "原因: {$reason}\n账单: {$totalCost} {$currency}");
        }
    } elseif ($targetAction != 'NONE') {
        $logsTopWarn[] = ['type' => 'WARN', 'msg' => "计划 {$targetAction}: {$reason} (未执行: 模式限制)", 'time' => date('H:i:s')];
    } else {
        $logsBottom[] = ['type' => 'SUCCESS', 'msg' => "系统健康，无操作", 'time' => date('H:i:s')];
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
            $logsTopWarn[] = ['type' => 'WARN', 'msg' => "📢 发送费用熔断通知", 'time' => date('H:i:s')];
            $lastCostAlert = true;
        } elseif (!($costLimit > 0 && $totalCost >= $costLimit))
            $lastCostAlert = false;
    } else {
        $lastCostAlert = ($costLimit > 0 && $totalCost >= $costLimit);
    }

    $finalLogs = array_merge($logsTopWarn, $logsInfo, $logsBottom);
    file_put_contents($dataFile, json_encode([
        'percent' => $currentPercent,
        'status' => $currentStatus,
        'cost_alert_sent' => $lastCostAlert,
        'start_retry_count' => $startRetryCount,
        'start_sleep_mode' => $startSleepMode
    ]));

    // 格式化输出
    $displayStatus = str_replace(['Running', 'Stopped', 'Starting', 'Stopping', 'Unknown'], ['运行中', '已停止', '启动中', '停止中', '未知'], $currentStatus);
    $regionMap = ['cn-hongkong' => '中国香港', 'cn-shanghai' => '华东2 (上海)', 'cn-beijing' => '华北2 (北京)', 'ap-southeast-1' => '新加坡', 'ap-northeast-1' => '日本 (东京)', 'us-west-1' => '美国 (硅谷)'];
    $displayRegion = isset($regionMap[$config['region_id']]) ? $regionMap[$config['region_id']] : $config['region_id'];

    $outputData = [
        'success' => true,
        'data' => [
            'status' => $displayStatus,
            'ip_address' => $displayIp, // <--- 新增字段
            'ipv6_address' => $displayIpv6,
            'used' => $usedGb,
            'limit' => $limitGb,
            'percent' => $currentPercent,
            'region' => $displayRegion,
            'bill_amount' => $totalCost,
            'bill_currency' => $currency,
            'bill_limit' => $costLimit,
            'cost_stop_enabled' => $costStopEnabled,
            'prev_bills' => $prevBills,
            'prev_traffic' => [] // <--- 移除历史流量
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