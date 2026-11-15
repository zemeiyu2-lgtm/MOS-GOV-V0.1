<?php
/**
 * ChurchCRM Include/Config.php 生成模板（Phase 3 凭据外置）。
 *
 * 由 deploy.sh 在服务器上渲染成 Include/Config.php（0640 root:www-data），
 * 真实值全部来自 /etc/mosgov/<tenant>/env，模板本身不含任何口令、不入库。
 * 严禁把生成后的 Config.php 提交到 Git。
 *
 * 原实现的三个问题（SEC-08）：
 *   1. 明文 $sPASSWORD 写在 Web 目录里的 PHP 文件；
 *   2. $URL[0] 硬编码 http://127.0.0.1:8082/，HTTPS 域名下登录跳转会指回本机；
 *   3. 所有教会共用一份配置，无法物理隔离。
 */

$required = [
    'MOSGOV_DB_HOST', 'MOSGOV_DB_PORT', 'MOSGOV_DB_USER', 'MOSGOV_DB_PASSWORD',
    'MOSGOV_TENANT_DB', 'MOSGOV_TENANT_HOST',
];
foreach ($required as $name) {
    $value = getenv($name);
    if ($value === false || $value === '' || str_starts_with($value, '__')) {
        throw new RuntimeException('MOS-GOV configuration incomplete: ' . $name);
    }
}

$sSERVERNAME = getenv('MOSGOV_DB_HOST');
$dbPort      = (int) getenv('MOSGOV_DB_PORT');
$sUSER       = getenv('MOSGOV_DB_USER');
$sPASSWORD   = getenv('MOSGOV_DB_PASSWORD');
$sDATABASE   = getenv('MOSGOV_TENANT_DB');

// TLS 由上游 vhost 终结；Apache 已 SetEnv HTTPS on，因此这里固定 https。
// 不使用 Host 头拼接，避免 Host-header 注入污染绝对地址。
$scheme      = 'https';
$host        = getenv('MOSGOV_TENANT_HOST');
$URL         = [$scheme . '://' . $host . '/'];
$sRootPath   = '';

// 公网环境强制关闭错误回显；异常细节只进服务器日志（SafeError）。
@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
