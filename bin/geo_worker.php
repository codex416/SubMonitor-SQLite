<?php
declare(strict_types=1);

require_once __DIR__ . '/../html/api/db.php';
date_default_timezone_set('Asia/Shanghai');
initDatabase();

function isPublicIp(string $ip): bool {
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
}

function queryGeo(string $ip): string {
    $url = 'http://ip-api.com/json/' . rawurlencode($ip) . '?lang=zh-CN&fields=status,message,country,regionName,city,isp,org';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 4,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'SubMonitor/2.0',
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => [
            'timeout' => 4,
            'ignore_errors' => true,
            'header' => "Accept: application/json\r\nUser-Agent: SubMonitor/2.0\r\n"
        ]]);
        $body = @file_get_contents($url, false, $ctx);
    }
    if (!is_string($body) || $body === '') return '';
    $data = json_decode($body, true);
    if (!is_array($data) || ($data['status'] ?? '') !== 'success') return '';
    $parts = array_filter([
        $data['country'] ?? '',
        $data['regionName'] ?? '',
        $data['city'] ?? '',
        !empty($data['isp']) ? '(' . $data['isp'] . ')' : '',
    ], static fn($v) => trim((string)$v) !== '');
    return trim(implode(' ', $parts));
}

while (true) {
    try {
        $now = time();
        $rows = [];
        $result = db()->query("SELECT l.ip FROM logs l LEFT JOIN ip_info i ON i.ip=l.ip WHERE i.ip IS NULL AND l.ip <> '-' LIMIT 20");
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            if (isPublicIp($row['ip'])) $rows[] = $row['ip'];
        }
        $rows = array_values(array_unique($rows));
        foreach ($rows as $ip) {
            $info = queryGeo($ip);
            $stmt = db()->prepare('INSERT INTO ip_info(ip,info,updated_at) VALUES(:ip,:info,:now) ON CONFLICT(ip) DO UPDATE SET info=excluded.info, updated_at=excluded.updated_at');
            $stmt->bindValue(':ip', $ip, SQLITE3_TEXT);
            $stmt->bindValue(':info', $info, SQLITE3_TEXT);
            $stmt->bindValue(':now', $now, SQLITE3_INTEGER);
            $stmt->execute();
        }
    } catch (Throwable $e) {
        error_log('[SubMonitor geo] ' . $e->getMessage());
    }
    sleep(5);
}
