<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/init.php';
require_login();
require_once __DIR__ . '/../controllers/SenaraiPelajarController.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $controller = new SenaraiPelajarController('', 'semua', false);
    require_page_access('pages/senarai-pelajar.php', $controller->profile, Database::pdoMysql());
    $group = prestasi_resolve_active_group($controller->profile, Database::pdoMysql());
    if (!in_array(strtoupper(trim((string)($group['kod'] ?? ''))), ['ADM-SA', 'ADM-PE'], true)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Anda tidak mempunyai kebenaran untuk mengurus gambar pelajar.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $query = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
    $faculty = mb_substr(trim((string)($_GET['fakulti'] ?? '')), 0, 30);
    $session = mb_substr(trim((string)($_GET['sesi'] ?? '')), 0, 30);
    $status = mb_substr(trim((string)($_GET['status'] ?? '')), 0, 30);
    $where = ['matrik IS NOT NULL'];
    $params = [];
    if ($query !== '') { $where[] = '(CONVERT(VARCHAR(30), matrik) LIKE :q_matrik OR UPPER(nama) LIKE :q_nama)'; $params[':q_matrik'] = '%' . $query . '%'; $params[':q_nama'] = '%' . mb_strtoupper($query, 'UTF-8') . '%'; }
    if ($faculty !== '') { $where[] = 'LTRIM(RTRIM(fakulti_singkatan)) = :fakulti'; $params[':fakulti'] = $faculty; }
    if ($session !== '') { $where[] = 'LTRIM(RTRIM(kdsesimasuk)) = :sesi'; $params[':sesi'] = $session; }
    if ($status !== '') { $where[] = 'LTRIM(RTRIM(statuskategori)) = :status'; $params[':status'] = $status; }

    $pdo = Database::pdoSybaseStudent();
    $statement = $pdo->prepare('SELECT CONVERT(VARCHAR(30), matrik) AS matrik FROM v210_sap_web WHERE ' . implode(' AND ', $where) . ' ORDER BY matrik DESC');
    $statement->execute($params);
    $matriks = array_values(array_filter($statement->fetchAll(PDO::FETCH_COLUMN) ?: [], static fn($matrik): bool => preg_match('/^\d{1,12}$/', trim((string)$matrik)) === 1));
    echo json_encode(['success' => true, 'matriks' => $matriks], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[gambar-pelajar-list] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Senarai gambar tidak dapat diproses buat sementara waktu.'], JSON_UNESCAPED_UNICODE);
}
