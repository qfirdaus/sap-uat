<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/init.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

try {
    $types = [
        'status' => "LTRIM(RTRIM(COALESCE(NULLIF(statusketerangan, ''), NULLIF(statuskategori, ''), 'Tidak Dinyatakan')))",
        'kadet' => "LTRIM(RTRIM(COALESCE(NULLIF(kategori_kadet, ''), NULLIF(kadet, ''), 'Tidak Dinyatakan')))",
        'jantina' => "LTRIM(RTRIM(COALESCE(NULLIF(jantina, ''), NULLIF(kdjantina, ''), 'Tidak Dinyatakan')))",
        'program' => "LTRIM(RTRIM(COALESCE(NULLIF(kdprogram, ''), 'Tidak Dinyatakan')))",
    ];
    $type = (string)($_GET['jenis'] ?? '');
    $value = mb_substr(trim((string)($_GET['nilai'] ?? '')), 0, 150);
    if (!isset($types[$type]) || $value === '') throw new RuntimeException('Kriteria laporan tidak sah.');
    $genderFilter = strtoupper(trim((string)($_GET['jantina'] ?? '')));
    $genderFilter = in_array($genderFilter, ['LELAKI', 'L', 'PEREMPUAN', 'P'], true) ? $genderFilter : '';
    $cadetFilter = strtoupper(trim((string)($_GET['kategori_kadet'] ?? '')));
    $cadetFilter = in_array($cadetFilter, ['AWAM', 'KADET'], true) ? $cadetFilter : '';
    $activeOnly = strtoupper(trim((string)($_GET['status'] ?? ''))) === 'AKTIF';
    $genderSql = "UPPER(LTRIM(RTRIM(COALESCE(NULLIF(jantina, ''), NULLIF(kdjantina, ''), 'Tidak Dinyatakan'))))";
    $cadetSql = "UPPER(LTRIM(RTRIM(COALESCE(NULLIF(kategori_kadet, ''), NULLIF(kadet, ''), 'Tidak Dinyatakan'))))";
    $statusSql = "UPPER(LTRIM(RTRIM(COALESCE(statuskategori, ''))))";
    $pdo = Database::pdoSybaseStudent();
    if (!$pdo instanceof PDO) throw new RuntimeException('Sambungan pangkalan data pelajar tidak tersedia.');

    $draw = max(0, (int)($_GET['draw'] ?? 0));
    $start = max(0, (int)($_GET['start'] ?? 0));
    $length = min(100, max(10, (int)($_GET['length'] ?? 25)));
    $search = mb_substr(trim((string)($_GET['search']['value'] ?? '')), 0, 100);
    $where = ['matrik IS NOT NULL', $types[$type] . ' = :nilai'];
    $params = [':nilai' => $value];
    if ($activeOnly) $where[] = $statusSql . " = 'AKTIF'";
    if ($cadetFilter !== '') { $where[] = $cadetSql . ' = :kategori_kadet'; $params[':kategori_kadet'] = $cadetFilter; }
    if ($genderFilter !== '') $where[] = in_array($genderFilter, ['L', 'LELAKI'], true) ? $genderSql . " IN ('LELAKI', 'L')" : $genderSql . " IN ('PEREMPUAN', 'P')";
    $baseParams = $params;
    if ($search !== '') {
        $needle = '%' . mb_strtoupper($search, 'UTF-8') . '%';
        $where[] = "(CONVERT(VARCHAR(30), matrik) LIKE :matrik OR UPPER(nama) LIKE :nama OR UPPER(kdprogram) LIKE :program)";
        $params[':matrik'] = $needle; $params[':nama'] = $needle; $params[':program'] = $needle;
    }
    $baseWhere = implode(' AND ', array_filter($where, static fn(string $item): bool => !str_contains($item, ':matrik') && !str_contains($item, ':nama') && !str_contains($item, ':program')));
    $whereSql = implode(' AND ', $where);
    $baseCount = $pdo->prepare("SELECT COUNT(*) FROM v210_sap_web WHERE {$baseWhere}");
    $baseCount->execute($baseParams); $total = (int)$baseCount->fetchColumn();
    $filteredCount = $pdo->prepare("SELECT COUNT(*) FROM v210_sap_web WHERE {$whereSql}");
    $filteredCount->execute($params); $filtered = (int)$filteredCount->fetchColumn();
    $orders = [1 => 1, 2 => 2, 3 => 3, 4 => 4, 5 => 5];
    $orderIndex = (int)($_GET['order'][0]['column'] ?? 1);
    $order = $orders[$orderIndex] ?? 1;
    $direction = strtolower((string)($_GET['order'][0]['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
    $tieBreaker = $order === 1 ? '' : ', 1 DESC';
    $limit = $start + $length;
    $sql = "SELECT TOP {$limit} CONVERT(VARCHAR(30), matrik) AS matrik, LTRIM(RTRIM(COALESCE(nama, ''))) AS nama, LTRIM(RTRIM(COALESCE(kdprogram, ''))) AS program, LTRIM(RTRIM(COALESCE(NULLIF(jantina, ''), kdjantina, ''))) AS jantina, LTRIM(RTRIM(COALESCE(NULLIF(statusketerangan, ''), statuskategori, ''))) AS status FROM v210_sap_web WHERE {$whereSql} ORDER BY {$order} {$direction}{$tieBreaker}";
    $statement = $pdo->prepare($sql); $statement->execute($params);
    $safe = static fn(mixed $item): string => htmlspecialchars((string)$item, ENT_QUOTES, 'UTF-8');
    $data = [];
    foreach (array_slice($statement->fetchAll(PDO::FETCH_ASSOC) ?: [], $start, $length) as $row) {
        $data[] = ['', $safe($row['matrik'] ?? ''), $safe($row['nama'] ?? ''), $safe($row['program'] ?? ''), $safe($row['jantina'] ?? ''), $safe($row['status'] ?? '')];
    }
    echo json_encode(['draw' => $draw, 'recordsTotal' => $total, 'recordsFiltered' => $filtered, 'data' => $data], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[laporan-pelajar-detail-data] ' . $e->getMessage());
    echo json_encode(['draw' => (int)($_GET['draw'] ?? 0), 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => [], 'error' => 'Grid detail tidak dapat dimuatkan.'], JSON_UNESCAPED_UNICODE);
}
