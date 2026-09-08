<?php
declare(strict_types=1);

require_once __DIR__ . '/../html/api/db.php';

date_default_timezone_set('Asia/Shanghai');
initDatabase();

const GEO_CACHE_FILE = '/opt/SubMonitor/rules/ip_cache.json';

function isPublicIp(string $ip): bool {
    return filter_var(
        $ip,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    ) !== false;
}

/**
 * 查询 IP 归属地
 */
function queryGeo(string $ip): string {
    $url = 'http://ip-api.com/json/' .
        rawurlencode($ip) .
        '?lang=zh-CN&fields=status,message,country,regionName,city,isp,org';

    if (function_exists('curl_init')) {
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 4,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'SubMonitor/2.0',
            CURLOPT_HTTPHEADER => [
                'Accept: application/json'
            ],
        ]);

        $body = curl_exec($ch);
        curl_close($ch);
    } else {
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 4,
                'ignore_errors' => true,
                'header' =>
                    "Accept: application/json\r\n" .
                    "User-Agent: SubMonitor/2.0\r\n"
            ]
        ]);

        $body = @file_get_contents($url, false, $ctx);
    }

    if (!is_string($body) || $body === '') {
        return '';
    }

    $data = json_decode($body, true);

    if (
        !is_array($data) ||
        ($data['status'] ?? '') !== 'success'
    ) {
        return '';
    }

    $parts = array_filter([
        $data['country'] ?? '',
        $data['regionName'] ?? '',
        $data['city'] ?? '',
        !empty($data['isp'])
            ? '(' . $data['isp'] . ')'
            : '',
    ], static fn($v) => trim((string)$v) !== '');

    return trim(implode(' ', $parts));
}

/**
 * 读取 IP JSON 缓存
 */
function loadGeoCache(): array {
    if (!is_file(GEO_CACHE_FILE)) {
        return [];
    }

    $json = @file_get_contents(GEO_CACHE_FILE);

    if (!is_string($json) || $json === '') {
        return [];
    }

    $cache = json_decode($json, true);

    return is_array($cache) ? $cache : [];
}

/**
 * 写入 IP JSON 缓存
 *
 * 使用临时文件 + rename，避免直接写 JSON 时进程中断导致文件损坏。
 */
function saveGeoCache(array $cache): void {
    $dir = dirname(GEO_CACHE_FILE);

    if (!is_dir($dir)) {
        return;
    }

    $json = json_encode(
        $cache,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRETTY_PRINT
    );

    if ($json === false) {
        return;
    }

    $tmpFile = GEO_CACHE_FILE . '.tmp';

    if (@file_put_contents($tmpFile, $json, LOCK_EX) === false) {
        error_log('[SubMonitor geo] Failed to write geo cache');
        return;
    }

    @rename($tmpFile, GEO_CACHE_FILE);
}

/**
 * 保存归属地到 SQLite
 */
function saveGeoToDatabase(
    string $ip,
    string $info,
    int $now
): void {
    $stmt = db()->prepare(
        'INSERT INTO ip_info(ip,info,updated_at)
         VALUES(:ip,:info,:now)
         ON CONFLICT(ip) DO UPDATE SET
            info=excluded.info,
            updated_at=excluded.updated_at'
    );

    $stmt->bindValue(':ip', $ip, SQLITE3_TEXT);
    $stmt->bindValue(':info', $info, SQLITE3_TEXT);
    $stmt->bindValue(':now', $now, SQLITE3_INTEGER);

    $result = $stmt->execute();

    if ($result === false) {
        throw new RuntimeException(
            'Failed to save geo info to SQLite: ' . $ip
        );
    }
}

while (true) {
    try {
        $now = time();

        /*
         * 每轮加载一次 JSON 缓存。
         */
        $cache = loadGeoCache();

        /*
         * 找出 logs 中还没有进入 ip_info 的公网 IP。
         */
        $rows = [];

        $result = db()->query(
            "SELECT l.ip
             FROM logs l
             LEFT JOIN ip_info i ON i.ip=l.ip
             WHERE i.ip IS NULL
               AND l.ip <> '-'
             LIMIT 20"
        );

        if ($result) {
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $ip = trim((string)($row['ip'] ?? ''));

                if ($ip !== '' && isPublicIp($ip)) {
                    $rows[] = $ip;
                }
            }
        }

        $rows = array_values(array_unique($rows));

        foreach ($rows as $ip) {

            /*
             * ① 优先读取 JSON 缓存
             */
            if (
                array_key_exists($ip, $cache) &&
                is_string($cache[$ip]) &&
                trim($cache[$ip]) !== ''
            ) {
                $info = trim($cache[$ip]);

                saveGeoToDatabase($ip, $info, $now);

                echo "[Geo] Cache -> DB: {$ip} => {$info}\n";

                continue;
            }

            /*
             * ② JSON 没有，才请求 API
             */
            $info = queryGeo($ip);

            /*
             * 查询失败：
             * 不写入 ip_info，下一轮继续尝试。
             */
            if ($info === '') {
                echo "[Geo] Query failed: {$ip}\n";
                continue;
            }

            /*
             * ③ 写入 JSON 缓存
             */
            $cache[$ip] = $info;

            /*
             * ④ 写入 SQLite
             */
            saveGeoToDatabase($ip, $info, $now);

            echo "[Geo] API -> Cache + DB: {$ip} => {$info}\n";
        }

        /*
         * 只有缓存发生变化时才写文件。
         */
        if (!empty($rows)) {
            saveGeoCache($cache);
        }

    } catch (Throwable $e) {
        error_log('[SubMonitor geo] ' . $e->getMessage());
    }

    sleep(5);
}
