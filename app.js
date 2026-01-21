// lib/app.js
const API_URL = 'api.php'; 

const STATUS_MAP = {
    'Running':  { text: '运行中', class: 'bg-success', icon: 'bi-play-circle-fill' },
    'Stopped':  { text: '已停止', class: 'bg-danger', icon: 'bi-stop-circle-fill' },
    'Starting': { text: '启动中', class: 'bg-warning text-dark', icon: 'bi-arrow-repeat' },
    'Stopping': { text: '停止中', class: 'bg-warning text-dark', icon: 'bi-power' },
    '运行中':   { text: '运行中', class: 'bg-success', icon: 'bi-play-circle-fill' },
    '已停止':   { text: '已停止', class: 'bg-danger', icon: 'bi-stop-circle-fill' },
    '启动中':   { text: '启动中', class: 'bg-warning text-dark', icon: 'bi-arrow-repeat' },
    '停止中':   { text: '停止中', class: 'bg-warning text-dark', icon: 'bi-power' },
    '未知':     { text: '未知状态', class: 'bg-secondary', icon: 'bi-question-circle' },
    'Unknown':  { text: '未知状态', class: 'bg-secondary', icon: 'bi-question-circle' }
};

document.addEventListener('DOMContentLoaded', fetchData);

function fetchData() {
    const logBox = document.getElementById('log-box');
    document.getElementById('status-badge').innerHTML = '<span class="spinner-border spinner-border-sm"></span> 同步中...';
    
    fetch(API_URL)
        .then(response => response.json())
        .then(res => {
            if (res.success) {
                updateUI(res.data);
                renderLogs(res.logs);
                document.getElementById('auth-mode').innerText = res.mode;
            } else {
                logLog('ERROR', 'API 报错: ' + (res.msg || '未知错误'), '#ff4d4d');
            }
        })
        .catch(err => {
            logLog('ERROR', '连接失败: ' + err, '#ff4d4d');
            document.getElementById('status-badge').innerText = '连接断开';
        });
}

function updateUI(data) {
    document.getElementById('last-update-time').innerText = '更新于: ' + new Date().toLocaleTimeString();

    const statusConfig = STATUS_MAP[data.status] || STATUS_MAP['未知'];
    const badgeEl = document.getElementById('status-badge');
    badgeEl.className = `badge ${statusConfig.class} status-badge-lg shadow-sm`;
    badgeEl.innerHTML = `<i class="bi ${statusConfig.icon}"></i> ${statusConfig.text}`;

    document.getElementById('region-val').innerText = data.region;
    
    // 更新 IP 地址
    document.getElementById('ip-val').innerText = data.ip_address || '--';

    document.getElementById('used-val').innerText = data.used;

    let currencySymbol = '';
    if(data.bill_amount !== undefined) {
        document.getElementById('bill-val').innerText = parseFloat(data.bill_amount).toFixed(2);
        let currDisplay = data.bill_currency || '';
        if (currDisplay === 'CNY') { currDisplay = '¥'; currencySymbol = '¥'; }
        else if (currDisplay === 'USD') { currDisplay = '$'; currencySymbol = '$'; }
        else { currencySymbol = currDisplay; }
        document.getElementById('bill-curr').innerText = currDisplay;
    }

    const limitVal = parseFloat(data.limit);
    const limitEl = document.getElementById('limit-val');
    const limitBadgeEl = document.getElementById('limit-badge');

    if (limitVal <= 0) {
        limitEl.innerText = "无限制";
        limitEl.className = "text-secondary fw-bold";
        limitBadgeEl.innerHTML = '<span class="badge bg-success">放行</span>';
    } else {
        limitEl.innerText = limitVal + " GB";
        limitEl.className = "text-dark fw-bold";
        limitBadgeEl.innerHTML = '<span class="badge bg-danger"><i class="bi bi-lightning-fill"></i> 自动关机</span>';
    }

    const thresholdVal = parseFloat(data.bill_limit);
    const thresholdEl = document.getElementById('threshold-val');
    const badgeSwitchEl = document.getElementById('threshold-badge');

    if (thresholdVal <= 0) {
        thresholdEl.innerText = "未设置";
        thresholdEl.className = "text-secondary fw-bold";
        badgeSwitchEl.innerHTML = '<span class="badge bg-secondary">功能关闭</span>';
    } else {
        thresholdEl.innerText = thresholdVal.toFixed(2) + " " + currencySymbol;
        thresholdEl.className = "text-dark fw-bold";
        if (data.cost_stop_enabled) {
            badgeSwitchEl.innerHTML = '<span class="badge bg-danger"><i class="bi bi-lightning-fill"></i> 自动关机</span>';
        } else {
            badgeSwitchEl.innerHTML = '<span class="badge bg-warning text-dark"><i class="bi bi-bell-fill"></i> 仅警告</span>';
        }
    }

    const pBar = document.getElementById('progress-bar');
    const pText = document.getElementById('progress-text');
    
    pBar.style.width = data.percent + '%';
    pText.innerText = data.percent + '%';
    
    let bgClass = 'bg-success';
    if (data.percent > 80) bgClass = 'bg-danger';
    else if (data.percent > 50) bgClass = 'bg-warning';

    pBar.className = `progress-bar progress-bar-striped progress-bar-animated ${bgClass}`;
}

function renderLogs(logs) {
    const logBox = document.getElementById('log-box');
    logBox.innerHTML = ''; 
    logs.forEach(log => {
        let color = '#00ff00';
        if (log.type === 'WARN') color = '#ffc107';
        if (log.type === 'ERROR') color = '#ff4d4d';
        if (log.type === 'INFO') color = '#0dcaf0';
        if (log.type === 'SUCCESS') color = '#198754';
        
        logBox.innerHTML += `<div class="mb-1"><span class="text-secondary">[${log.time}]</span> <span style="color:${color}">[${log.type}] ${log.msg}</span></div>`;
    });
}

function logLog(type, msg, color) {
        document.getElementById('log-box').innerHTML += `<div class="mb-1"><span style="color:${color}">[${type}] ${msg}</span></div>`;
}