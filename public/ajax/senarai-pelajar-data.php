<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/init.php';
require_login();
require_once __DIR__ . '/_helpers.php';

header('Content-Type: application/json; charset=utf-8');
try {
    // Senarai Keseluruhan boleh diakses oleh semua pengguna yang telah log masuk.
    // require_login() di atas kekal sebagai perlindungan sesi.
    $pdo = Database::pdoSybaseStudent();
    if (!$pdo instanceof PDO) throw new RuntimeException('Sambungan pangkalan data pelajar tidak tersedia.');

    $draw = max(0, (int)($_GET['draw'] ?? 0));
    $start = max(0, (int)($_GET['start'] ?? 0));
    $length = min(100, max(10, (int)($_GET['length'] ?? 25)));
    $term = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
    $by = (string)($_GET['carian'] ?? 'semua');
    if (!in_array($by, ['semua', 'matrik', 'nokp', 'nama'], true)) $by = 'semua';
    $statusFilter = (string)($_GET['status'] ?? 'semua');
    if (!in_array($statusFilter, ['semua', 'aktif', 'tidak_aktif'], true)) $statusFilter = 'semua';

    $where = ['matrik IS NOT NULL'];
    $params = [];
    if ($statusFilter === 'aktif') {
        $where[] = "UPPER(LTRIM(RTRIM(COALESCE(statuskategori, '')))) = 'AKTIF'";
    } elseif ($statusFilter === 'tidak_aktif') {
        $where[] = "UPPER(LTRIM(RTRIM(COALESCE(statuskategori, '')))) <> 'AKTIF'";
    }
    if ($term !== '') {
        $needle = '%' . mb_strtoupper($term, 'UTF-8') . '%';
        if ($by === 'matrik') { $where[] = 'CONVERT(VARCHAR(30), matrik) LIKE :q'; $params[':q'] = $needle; }
        elseif ($by === 'nokp') { $where[] = 'nokp LIKE :q'; $params[':q'] = $needle; }
        elseif ($by === 'nama') { $where[] = 'UPPER(nama) LIKE :q'; $params[':q'] = $needle; }
        else { $where[] = '(CONVERT(VARCHAR(30), matrik) LIKE :qm OR nokp LIKE :qk OR UPPER(nama) LIKE :qn)'; $params = [':qm' => $needle, ':qk' => $needle, ':qn' => $needle]; }
    }
    $whereSql = implode(' AND ', $where);
    $total = (int)$pdo->query('SELECT COUNT(*) FROM v210_sap_web WHERE matrik IS NOT NULL')->fetchColumn();
    $count = $pdo->prepare("SELECT COUNT(*) FROM v210_sap_web WHERE {$whereSql}"); $count->execute($params); $filtered = (int)$count->fetchColumn();

    // DataTables menghantar indeks kolum. Gunakan senarai putih supaya ORDER BY kekal selamat.
    // Gunakan kedudukan kolum SELECT untuk keserasian dengan ASE lama.
    $orderColumns = [1 => 1, 2 => 2, 3 => 3, 4 => 4, 5 => 5, 6 => 6, 7 => 7, 8 => 8, 9 => 9];
    $orderIndex = (int)($_GET['order'][0]['column'] ?? 1);
    $orderColumn = $orderColumns[$orderIndex] ?? 1;
    $orderDirection = strtolower((string)($_GET['order'][0]['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
    $tieBreaker = $orderColumn === 1 ? '' : ', 1 DESC';

    // ASE lama tidak menyokong OFFSET/ROW_NUMBER. Ambil rekod sehingga hujung halaman
    // di pelayan, kemudian hanya hantar satu halaman kecil kepada browser.
    $fetchLimit = $start + $length;
    $sql = "SELECT TOP {$fetchLimit} CONVERT(VARCHAR(30), matrik) AS matrik, LTRIM(RTRIM(COALESCE(nama, ''))) AS nama, LTRIM(RTRIM(COALESCE(nokp, ''))) AS nokp, LTRIM(RTRIM(COALESCE(kdjantina, ''))) AS jantina, LTRIM(RTRIM(COALESCE(kdprogram, ''))) AS kod_program, LTRIM(RTRIM(COALESCE(kdsemsemasa, ''))) AS semester, LTRIM(RTRIM(COALESCE(email, ''))) AS email, LTRIM(RTRIM(COALESCE(telno_terkini, ''))) AS telefon, LTRIM(RTRIM(COALESCE(statusketerangan, ''))) AS status, LTRIM(RTRIM(COALESCE(statuskategori, ''))) AS status_kategori FROM v210_sap_web WHERE {$whereSql} ORDER BY {$orderColumn} {$orderDirection}{$tieBreaker}";
    $statement = $pdo->prepare($sql); $statement->execute($params);
    $data = [];
    foreach (array_slice($statement->fetchAll(PDO::FETCH_ASSOC) ?: [], $start, $length) as $row) {
        $matrik = (string)$row['matrik'];
        $safe = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
        $isActive = strtoupper(trim((string)$row['status_kategori'])) === 'AKTIF';
        $status = '<span class="badge ' . ($isActive ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger') . '">' . $safe($row['status']) . '</span>';
        $actions = '<div class="btn-group btn-group-sm" role="group">'
            . '<a class="btn btn-outline-primary" href="../pages/maklumat-pelajar.php?matrik=' . rawurlencode($matrik) . '" title="Lihat Profil Pelajar"><i class="ri-eye-line"></i></a>'
            . '<a class="btn btn-outline-purple" href="../pages/cv-pelajar.php?matrik=' . rawurlencode($matrik) . '" title="Curriculum Vitae Pelajar"><i class="ri-file-user-line"></i></a>'
            . '</div>';
        $data[] = ['', $safe($matrik), $safe($row['nama']), $safe($row['nokp']), $safe($row['jantina']), $safe($row['kod_program']), $safe($row['semester']), $safe($row['email']), $safe($row['telefon']), $status, $actions];
    }
    echo json_encode(['draw' => $draw, 'recordsTotal' => $total, 'recordsFiltered' => $filtered, 'data' => $data], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[senarai-pelajar-data] ' . $e->getMessage());
    echo json_encode(['draw' => (int)($_GET['draw'] ?? 0), 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => [], 'error' => 'Senarai pelajar tidak dapat dimuatkan.'], JSON_UNESCAPED_UNICODE);
}
