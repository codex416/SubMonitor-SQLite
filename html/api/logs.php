<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_login();
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Shanghai');

initDatabase();
$db = db();

$search = trim((string)($_GET['search'] ?? ''));
$startTime = trim((string)($_GET['start_time'] ?? ''));
$endTime = trim((string)($_GET['end_time'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = min(200, max(1, (int)($_GET['limit'] ?? 50)));

function parseFilterTime(string $value, bool $end = false): ?int {
    if ($value === '') return null;
    try {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $value .= $end ? ' 23:59:59' : ' 00:00:00';
        }
        $dt = new DateTime($value, new DateTimeZone('Asia/Shanghai'));
        return $dt->getTimestamp();
    } catch (Throwable $e) {
        return null;
    }
}

function bindParams(SQLite3Stmt $stmt, array $params): void {
    foreach ($params as $name => $value) {
        $type = is_int($value) ? SQLITE3_INTEGER : SQLITE3_TEXT;
        $stmt->bindValue($name, $value, $type);
    }
}

function fetchRows(string $sql, array $params = []): array {
    $stmt = db()->prepare($sql);
    bindParams($stmt, $params);
    $result = $stmt->execute();
    $rows = [];
    if ($result) {
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = $row;
        }
    }
    return $rows;
}

$conditions = [];
$params = [];

$startTs = parseFilterTime($startTime, false);
$endTs = parseFilterTime($endTime, true);
if ($startTs !== null) {
    $conditions[] = 'l.ts >= :start_ts';
    $params[':start_ts'] = $startTs;
}
if ($endTs !== null) {
    $conditions[] = 'l.ts <= :end_ts';
    $params[':end_ts'] = $endTs;
}

if ($search !== '') {
    $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($search));
    $like = '%' . $escaped . '%';
    $conditions[] = '(LOWER(l.ip) LIKE :search ESCAPE \'\\\' OR LOWER(COALESCE(i.info, \'\')) LIKE :search ESCAPE \'\\\' OR LOWER(l.token) LIKE :search ESCAPE \'\\\' OR LOWER(l.ua) LIKE :search ESCAPE \'\\\' OR LOWER(l.request_uri) LIKE :search ESCAPE \'\\\' OR CAST(l.status AS TEXT) LIKE :search ESCAPE \'\\\' OR l.day LIKE :search ESCAPE \'\\\')';
    $params[':search'] = $like;
}

$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

/*
 * 统计当前筛选条件。
 * 无搜索时不需要 JOIN ip_info，避免无意义的关联查询。
 * 有搜索时需要搜索 i.info，因此保留 JOIN。
 */
$statsFrom = 'FROM logs l';
if ($search !== '') {
    $statsFrom .= ' LEFT JOIN ip_info i ON i.ip=l.ip';
}

$total = (int)(dbScalar("SELECT COUNT(*) $statsFrom $where", $params) ?? 0);
$totalIps = (int)(dbScalar("SELECT COUNT(DISTINCT l.ip) $statsFrom $where", $params) ?? 0);
$totalSuccess = (int)(dbScalar("SELECT COUNT(*) $statsFrom $where" . ($where ? ' AND ' : ' WHERE ') . 'l.status=200', $params) ?? 0);
$totalError = $total - $totalSuccess;
$totalSuccessTokens = (int)(dbScalar("SELECT COUNT(DISTINCT l.token) $statsFrom $where" . ($where ? ' AND ' : ' WHERE ') . "l.status=200 AND l.token <> '-'", $params) ?? 0);
$errorRate = $total > 0 ? round(($totalError / $total) * 100, 1) : 0;

$offset = ($page - 1) * $limit;
$dataRows = fetchRows("SELECT l.ts, l.ip, COALESCE(i.info, '') AS ip_info, l.token, l.status, l.ua FROM logs l LEFT JOIN ip_info i ON i.ip=l.ip $where ORDER BY l.ts DESC, l.id DESC LIMIT :limit OFFSET :offset", array_merge($params, [':limit' => $limit, ':offset' => $offset]));

$data = [];
$tz = new DateTimeZone('Asia/Shanghai');
foreach ($dataRows as $row) {
    $data[] = [
        'time' => date('Y-m-d H:i:s', (int)$row['ts']),
        'ip' => $row['ip'],
        'ip_info' => $row['ip_info'] !== '' ? $row['ip_info'] : '未知地区',
        'token' => $row['token'],
        'status' => (string)$row['status'],
        'ua' => $row['ua']
    ];
}

// 今日分析。限制返回量，避免把数万条排名数据塞进一次 JSON；前端卡片本身也按 10 条分页。
$today = (new DateTime('today', $tz))->format('Y-m-d');
$topIps = fetchRows("SELECT l.ip, COUNT(*) AS count, COALESCE(i.info,'') AS info FROM logs l LEFT JOIN ip_info i ON i.ip=l.ip WHERE l.day=:day AND l.ip <> '-' GROUP BY l.ip ORDER BY count DESC, l.ip ASC LIMIT 100", [':day'=>$today]);
$topTokens = fetchRows("SELECT l.token, COUNT(*) AS count, MAX(l.ts) AS last_ts FROM logs l WHERE l.day=:day AND l.token <> '-' GROUP BY l.token ORDER BY count DESC, last_ts DESC LIMIT 100", [':day'=>$today]);

// 今日可疑 TOKEN：同一 TOKEN 对应多个不同 IP。
$susTokens = fetchRows("SELECT l.token, COUNT(DISTINCT l.ip) AS ipCount FROM logs l WHERE l.day=:day AND l.token <> '-' AND l.ip <> '-' GROUP BY l.token HAVING COUNT(DISTINCT l.ip) > 1 ORDER BY ipCount DESC, l.token ASC LIMIT 100", [':day'=>$today]);
// 今日可疑 IP：同一 IP 使用多个 TOKEN。
$susIps = fetchRows("SELECT l.ip, COUNT(DISTINCT l.token) AS tokenCount, COALESCE(i.info,'') AS info FROM logs l LEFT JOIN ip_info i ON i.ip=l.ip WHERE l.day=:day AND l.ip <> '-' AND l.token <> '-' GROUP BY l.ip HAVING COUNT(DISTINCT l.token) > 1 ORDER BY tokenCount DESC, l.ip ASC LIMIT 100", [':day'=>$today]);

$analytics = [
    'top_ips' => array_map(static function($r) { return ['ip'=>$r['ip'], 'count'=>(int)$r['count'], 'info'=>$r['info'] !== '' ? $r['info'] : '未知地区']; }, $topIps),
    'top_tokens' => array_map(static function($r) { return ['token'=>$r['token'], 'count'=>(int)$r['count'], 'lastTime'=>date('Y-m-d H:i:s', (int)$r['last_ts'])]; }, $topTokens),
    'sus_tokens' => array_map(static function($r) { return ['token'=>$r['token'], 'ipCount'=>(int)$r['ipCount']]; }, $susTokens),
    'sus_ips' => array_map(static function($r) { return ['ip'=>$r['ip'], 'tokenCount'=>(int)$r['tokenCount'], 'info'=>$r['info'] !== '' ? $r['info'] : '未知地区']; }, $susIps)
];

echo json_encode([
    'total' => $total,
    'total_ips' => $totalIps,
    'total_success' => $totalSuccess,
    'total_error' => $totalError,
    'total_success_tokens' => $totalSuccessTokens,
    'error_rate' => $errorRate,
    'analytics' => $analytics,
    'page' => $page,
    'limit' => $limit,
    'data' => $data
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?>
