<?php
// lib/config.php
return [
    // ================= 账号类型配置 =================
    // 'intl' = 阿里云国际站 (默认，使用新加坡接口查询账单)
    // 'cn'   = 阿里云国内站 (使用国内接口查询账单)
    'account_type'      => 'intl',
    
    // 阿里云 AccessKey 信息
    'access_key_id'     => '你的AccessKey',       // 您的 AccessKey ID
    'access_key_secret' => '你的AccessSecret',       // 您的 AccessKey Secret
    
    // 实例配置
    'region_id'         => 'cn-hongkong',       // 区域 ID
    'instance_id'       => 'i-xxxxx',       // ECS 实例 ID
    
    // 流量安全策略配置
    'traffic_limit_gb'  => 180, //以G为单位
    'dry_run'           => false, // true=只测试不执行关机, false=真实执行

    // Web Cron 安全密钥
	//访问地址https://www.xxxx.com/api.php?key=1234567
    'cron_key'          => '123456',
    
    // 停机模式配置
    // 0 = 普通停机 (停机后继续收费，保留公网 IP)
    // 1 = 节省停机 (停机后不收费，但回收公网 IP，下次开机 IP 会变)
    'stop_mode'         => 1,
    
    // ================= 性能配置 =================
    // 前端网页缓存时间 (秒)
    // 180秒 = 3分钟。3分钟内重复访问只读缓存，不消耗 API。
    'cache_time'        => 180,
    
    // ================= Bark 通知配置 =================
    'bark' => [
        'enable'   => true,                               
        'server'   => 'https://api.day.app/你的密钥/', 
        'notify_traffic_step' => 10,                      
    ],

    // ================= 定时开关机任务配置 =================
    'schedule' => [
        'enable'     => false,      
        'start_time' => '08:00',   
        'stop_time'  => '23:00',   
        'timezone'   => 'Asia/Shanghai', 
    ],
    
    // ================= Telegram 通知 =================
    'telegram' => [
        'enable'    => false,                     
        'bot_token' => '123456:ABC-DEF1234ghIkl-zyxavc57W2v1u123ew11', //机器人密钥
        'chat_id'   => '123456789',  //管理员ID           
    ],
    
    // =======================================================
    // 费用监控与熔断配置
    // =======================================================
    'cost_protection' => [
        'limit_money'      => 1.00,  // 设定金额阈值 (单位跟随账号，国际站为USD)
        'enable_stop'      => false, // 达到金额是否执行关机 (true=关机, false=仅通知)
        'exclude_zero'     => true,  // 是否隐藏0元账单明细
    ],
];
?>