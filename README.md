

```markdown
# 阿里云 CDT / ECS 流量与费用智能监控面板

[![PHP Version](https://img.shields.io/badge/php-%3E%3D7.4-blue)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-MIT-green)](./LICENSE)

这是一个基于 **PHP + HTML** 的轻量级服务器监控面板，专为阿里云 ECS（特别是 CDT 计费模式）用户设计。

它可以实时展示实例的流量使用情况、当月账单金额，并提供**双重熔断保护**（流量超标或费用超标自动关机），防止意外的高额扣费。前端采用纯静态设计，所有逻辑由后端 PHP 处理，安全高效。

## ✨ 功能特性

* **📊 仪表盘可视化**：
    * **实时数据**：展示所在地区、本月实时账单、已用流量、实例状态。
    * **隐私保护**：前端显示的 IPv4/IPv6 地址自动脱敏（如 `8.218.xxx.3`）。
    * **双栈支持**：完美支持并显示 IPv4 和 IPv6 地址。
* **🛡️ 双重熔断保护**：
    * **费用熔断**：当月账单超过设定阈值（如 1.00 USD），支持自动关机或发送报警。
    * **流量限额**：流量使用超过设定值（如 180GB），自动触发关机，防止 CDT 流量超额扣费。
* **⚡ 高性能与防刷**：
    * 内置文件缓存机制（默认 60秒），防止高频刷新导致触发阿里云 API 限速。
    * 前后端分离架构，前端极速加载。
* **🔔 消息通知**：
    * 支持 **Telegram** 和 **Bark** (iOS) 推送。
    * 支持流量预警、关机通知、费用熔断警告。
* **⏰ 定时任务**：支持配合 Crontab 设置定时开机/关机计划。

## 🛠️ 环境要求

* **PHP 版本**：最低 **PHP 7.2**（推荐 PHP 7.4 或 PHP 8.x）。
* **扩展依赖**：需要安装 `curl` 和 `json` 扩展（绝大多数 PHP 环境默认已包含）。
* **Web 服务器**：Nginx、Apache 或 OpenLiteSpeed 均可。
* **阿里云权限**：由于需要查询账单和操作关机，需要 AccessKey 具备相应权限（见下文）。

## 🚀 安装步骤

### 1. 下载源码
将本项目代码下载并上传至您的网站根目录。

### 2. 配置文件
为了安全起见，项目不包含含有密钥的配置文件。你需要手动创建：

1.  进入 `lib` 目录。
2.  将 `config.sample.php` 复制并重命名为 `config.php`。
3.  编辑 `config.php`，填入您的配置信息：

```php
return [
    // 阿里云 API 密钥 (建议使用 RAM 子用户)
    'access_key_id'     => '您的AccessKeyID',
    'access_key_secret' => '您的AccessKeySecret',
    
    // 实例配置
    'region_id'   => 'cn-hongkong', // 例如：cn-hongkong, ap-southeast-1
    'instance_id' => 'i-xxxxxxxx',  // 您的 ECS 实例 ID
    
    // ... 其他阈值配置 ...
];

```

### 3. 访问测试

在浏览器中访问：`http://您的域名/index.html`

如果显示“等待数据...”，请尝试手动访问 API 接口进行初始化：
`http://您的域名/api.php?key=配置文件中设置的cron_key`

## ⚙️ 阿里云 RAM 权限说明

建议创建一个 **RAM 子用户** 专门用于此脚本，**不要**使用主账号 AccessKey。
该子用户需要以下权限：

1. **AliyunECSReadOnlyAccess** (读取 ECS 状态、IP 等)
2. **AliyunBSSReadOnlyAccess** (读取账单费用)
3. **AliyunCDTReadOnlyAccess** (读取 CDT 流量详情)
4. **自定义权限 (用于关机)**：
如果开启了自动关机功能，还需要赋予 `StopInstance` 权限。你可以创建一个自定义策略：

```json
{
    "Version": "1",
    "Statement": [
        {
            "Effect": "Allow",
            "Action": [
                "ecs:StopInstance",
                "ecs:StartInstance"
            ],
            "Resource": "*"
        }
    ]
}

```

## ⏰ 定时任务 (Crontab)

为了实现自动监控和熔断保护，你需要配置服务器的定时任务，每分钟执行一次检测。

**Linux Crontab 示例：**

```bash
# 每分钟请求一次 API，触发监控逻辑
* * * * * curl -s "[http://127.0.0.1/api.php?key=您的CRON密钥](http://127.0.0.1/api.php?key=您的CRON密钥)" > /dev/null

```

或者在宝塔面板的“计划任务”中添加一个“访问 URL”的任务。

## 📂 目录结构

```text
/
├── index.html          # 前端主页 (纯静态)
├── api.php             # API 入口文件
└── lib/
    ├── api.php         # 核心逻辑处理
    ├── app.js          # 前端逻辑脚本
    ├── style.css       # 样式表
    ├── config.php      # 配置文件 (需手动创建)
    ├── AliyunClient.php # 阿里云 API 客户端类
    ├── Functions.php   # 公共函数库
    └── ...

```

## ⚠️ 免责声明

* 本工具涉及对云服务器的自动操作（如关机），虽然经过测试，但**开发者不对因软件错误、配置错误或网络延迟导致的任何费用损失或数据丢失承担责任**。
* 请务必先在测试环境中验证阈值配置是否符合您的预期。
* **请勿公开您的 `config.php` 文件！**

## 📄 License

MIT License

```

```
