#!/usr/bin/env php
<?php
require_once __DIR__ . '/instance_data.php';

if (php_sapi_name() !== 'cli') {
    die("Este script deve ser executado via CLI.\n");
}

if (!function_exists('debug_log')) {
    function debug_log($message) { }
}

function isPortOpenCli(string $host, int $port, int $timeout = 1): bool
{
    $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
    if ($fp) {
        fclose($fp);
        return true;
    }
    return false;
}

function shouldSendAlarmNotification(string $lastSent, int $intervalMinutes): bool
{
    if (!$lastSent) {
        return true;
    }
    $lastStamp = strtotime($lastSent);
    if ($lastStamp === false) {
        return true;
    }
    $intervalSeconds = max(1, $intervalMinutes) * 60;
    return (time() - $lastStamp) >= $intervalSeconds;
}

function formatIntervalMinutes(int $minutes): string
{
    if ($minutes < 60) {
        return "{$minutes} min";
    }
    $hours = intdiv($minutes, 60);
    $remainder = $minutes % 60;
    if ($remainder === 0) {
        return "{$hours}h";
    }
    return "{$hours}h {$remainder}m";
}

function sendAlarmEmail(array $recipients, string $subject, string $body, ?string $htmlBody = null): bool
{
    if (empty($recipients)) {
        return false;
    }
    $toHeader = implode(', ', $recipients);
    $headers = "To: {$toHeader}\nSubject: {$subject}\n";
    if ($htmlBody !== null) {
        $headers .= "MIME-Version: 1.0\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\n";
        $headers .= "Content-Transfer-Encoding: 8bit\n";
        $mailData = $headers . "\n" . $htmlBody . "\n";
    } else {
        $mailData = $headers . "\n" . $body . "\n";
    }
    $cmd = 'sendmail -t';
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w']
    ];
    $process = proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($process)) {
        return false;
    }
    fwrite($pipes[0], $mailData);
    fclose($pipes[0]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $status = proc_close($process);
    return $status === 0;
}

function buildAlarmHtml(string $title, string $subtitle, array $rows, string $message, string $logoUrl): string
{
    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $safeSubtitle = htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8');
    $safeMessage = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));
    $safeLogo = htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8');

    $rowHtml = '';
    foreach ($rows as $label => $value) {
        $safeLabel = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
        $safeValue = htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $rowHtml .= "<tr><td style=\"padding:8px 0; font-size:13px; color:#4b615f; width:160px;\">{$safeLabel}</td>"
            . "<td style=\"padding:8px 0; font-size:14px; color:#0f1f1e; font-weight:600;\">{$safeValue}</td></tr>";
    }

    return <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{$safeTitle}</title>
</head>
<body style="margin:0; padding:0; background:#f4f7f7; font-family:'Segoe UI','Inter',Arial,sans-serif;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f4f7f7; padding:32px 0;">
    <tr>
      <td align="center">
        <table role="presentation" width="680" cellspacing="0" cellpadding="0" style="background:#ffffff; border-radius:18px; overflow:hidden; box-shadow:0 18px 45px rgba(15,118,110,0.12);">
          <tr>
            <td style="background:linear-gradient(120deg,#0f766e,#115e59); padding:28px 36px; color:#ffffff;">
              <img src="{$safeLogo}" alt="Maestro" style="height:36px; display:block; margin-bottom:14px;">
              <div style="font-size:20px; font-weight:700; letter-spacing:0.3px;">{$safeTitle}</div>
              <div style="margin-top:6px; font-size:14px; opacity:0.9;">{$safeSubtitle}</div>
            </td>
          </tr>
          <tr>
            <td style="padding:28px 36px;">
              <div style="font-size:15px; color:#143533; line-height:1.6; margin-bottom:18px;">
                {$safeMessage}
              </div>
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-top:1px solid #e3eceb; padding-top:12px;">
                {$rowHtml}
              </table>
            </td>
          </tr>
          <tr>
            <td style="background:#f1f7f6; padding:18px 36px; font-size:12px; color:#6b7e7c;">
              Este é um aviso automático do Maestro. Se precisar de suporte, responda este e-mail com o log ou o horário do incidente.
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
}

$instances = loadInstancesFromDatabase();
if (empty($instances)) {
    echo "Nenhuma instância encontrada.\n";
    exit(0);
}

$publicBaseUrl = rtrim(getenv('PUBLIC_BASE_URL') ?: 'https://janeri.com.br/api/envio/wpp', '/');
$logoUrl = $publicBaseUrl . '/assets/maestro-logo.png';

foreach ($instances as $instanceId => $instance) {
    $alarmConfig = isset($instance['alarms']['server']) ? $instance['alarms']['server'] : null;
    if (!$alarmConfig || empty($alarmConfig['enabled'])) {
        continue;
    }

    $port = isset($instance['port']) ? $instance['port'] : null;
    $isPortUp = $port !== null && $port !== false && isPortOpenCli('127.0.0.1', $port);
    if (!$port || $isPortUp) {
        if ($isPortUp && !empty($alarmConfig['last_sent'])) {
            saveInstanceSettings($instanceId, ['alarm_server_last_sent' => '']);
            echo "Alarme de servidor resetado para {$instanceId}\n";
        }
        continue;
    }

    $intervalMinutes = (int)(isset($alarmConfig['interval']) ? $alarmConfig['interval'] : 120);
    $lastSent = isset($alarmConfig['last_sent']) ? $alarmConfig['last_sent'] : '';
    if (!shouldSendAlarmNotification($lastSent, $intervalMinutes)) {
        continue;
    }

    $recipients = isset($alarmConfig['recipients_list']) ? $alarmConfig['recipients_list'] : null;
    if ($recipients === null) {
        $recipients = parseEmailList(isset($alarmConfig['recipients']) ? $alarmConfig['recipients'] : '');
    }
    if (empty($recipients)) {
        continue;
    }

    $instanceLabel = isset($instance['name']) && $instance['name'] !== '' ? $instance['name'] : $instanceId;
    $subject = "Maestro: instância {$instanceLabel} offline";
    $timestamp = date('Y-m-d H:i:s');
    $hostname = gethostname() ?: 'desconhecido';
    $phpVersion = phpversion();
    $environment = getenv('APP_ENV') ?: 'não definido';
    $pid = getmypid();
    $nodeStatus = $instance['status'] ?? 'desconhecido';
    $connectionState = $instance['connection_status'] ?? 'desconhecido';
    $intervalLabel = formatIntervalMinutes($intervalMinutes);
    $body = "Instância: {$instanceLabel}\n"
        . "Identificador: {$instanceId}\n"
        . "Porta monitorada: {$port}\n"
        . "Detectado em: {$timestamp} (UTC-3)\n"
        . "Intervalo de alertas: {$intervalLabel}\n"
        . "Ambiente: {$environment}\n"
        . "PIDs e status: host={$hostname} pid={$pid}\n\n"
        . "Motivo: a porta {$port} não respondeu ao monitoramento local.\n"
        . "Status registrado: {$nodeStatus} / {$connectionState}\n\n"
        . "Debug:\n"
        . "- PHP: {$phpVersion}\n"
        . "- Sistema operacional: " . php_uname('s') . " " . php_uname('r') . "\n"
        . "- Comando invocado: monitor_instances.php\n"
        . "- Versão do script: " . basename(__FILE__) . "\n"
        . "Recomendações: confirme se o processo principal está em execução, verifique logs em /var/log, e reinicie se necessário.\n";

    $htmlRows = [
        'Instância' => $instanceLabel,
        'Identificador' => $instanceId,
        'Porta monitorada' => $port,
        'Detectado em' => $timestamp . ' (UTC-3)',
        'Intervalo de alertas' => $intervalLabel,
        'Ambiente' => $environment,
        'Host/PID' => "host={$hostname} pid={$pid}",
        'Status registrado' => "{$nodeStatus} / {$connectionState}"
    ];
    $htmlMessage = "A porta {$port} não respondeu ao monitoramento local. "
        . "Verifique se o processo principal está ativo e reinicie se necessário.";
    $htmlBody = buildAlarmHtml($subject, 'Monitoramento de servidor offline', $htmlRows, $htmlMessage, $logoUrl);

    $sent = sendAlarmEmail($recipients, $subject, $body, $htmlBody);
    if ($sent) {
        saveInstanceSettings($instanceId, ['alarm_server_last_sent' => date('c')]);
        echo "Alarme de servidor enviado para {$instanceId} -> {$subject}\n";
    } else {
        echo "Falha ao enviar alarme de servidor para {$instanceId}\n";
    }
}
