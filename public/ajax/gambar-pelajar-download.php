<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/init.php';
require_login();
require_once __DIR__ . '/../controllers/SenaraiPelajarController.php';

try {
    $controller = new SenaraiPelajarController('', 'semua', false);
    require_page_access('pages/senarai-pelajar.php', $controller->profile, Database::pdoMysql());
    $group = prestasi_resolve_active_group($controller->profile, Database::pdoMysql());
    if (!in_array(strtoupper(trim((string)($group['kod'] ?? ''))), ['ADM-SA', 'ADM-PE'], true)) throw new RuntimeException('Akses muat turun gambar tidak dibenarkan.');
    if (!class_exists('ZipArchive')) throw new RuntimeException('Sambungan ZIP PHP tidak tersedia pada pelayan.');

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

    $safeNamePart = static function (string $value): string {
        $value = preg_replace('/[^A-Za-z0-9]+/', '-', $value) ?? '';
        return trim($value, '-');
    };
    $nameParts = ['GAMBAR-PELAJAR'];
    if ($query !== '') $nameParts[] = $safeNamePart($query) ?: 'rekod';
    if ($faculty !== '') $nameParts[] = $safeNamePart($faculty) ?: 'semua';
    if ($session !== '') $nameParts[] = $safeNamePart($session) ?: 'semua';
    if ($status !== '') $nameParts[] = $safeNamePart($status) ?: 'semua';
    $archiveFolder = mb_strtoupper(implode('-', $nameParts), 'UTF-8') . '-' . date('Ymd-His');

    $pdo = Database::pdoSybaseStudent();
    $statement = $pdo->prepare('SELECT TOP 500 CONVERT(VARCHAR(30), matrik) AS matrik FROM v210_sap_web WHERE ' . implode(' AND ', $where) . ' ORDER BY matrik DESC');
    $statement->execute($params);
    $matriks = $statement->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $directory = __DIR__ . '/../assets/images/pelajar';
    $temporary = tempnam(sys_get_temp_dir(), 'sap-gambar-');
    if ($temporary === false) throw new RuntimeException('Fail ZIP sementara tidak dapat disediakan.');
    $zipPath = $temporary . '.zip';
    @unlink($temporary);
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Fail ZIP tidak dapat dicipta.');
    $added = 0;
    foreach ($matriks as $matrik) {
        $matrik = trim((string)$matrik);
        if (!preg_match('/^\d{1,12}$/', $matrik)) continue;
        foreach (['jpg', 'jpeg', 'png'] as $extension) {
            $image = $directory . DIRECTORY_SEPARATOR . $matrik . '.' . $extension;
            if (is_file($image)) { $zip->addFile($image, $archiveFolder . '/' . $matrik . '.' . $extension); $added++; break; }
        }
    }
if ($added === 0) {
    $zip->close();
    @unlink($zipPath);
    http_response_code(422);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Tiada gambar SAP yang sepadan ditemui. Sila gunakan Padankan Semua Gambar terlebih dahulu.';
    exit;
}

$zip->addFromString($archiveFolder . '/MAKLUMAT.txt', "Jumlah gambar: {$added}\nHad muat turun: 500 rekod bagi setiap permintaan.\n");
    $zip->close();
    $filename = $archiveFolder . '.zip';
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . (string)filesize($zipPath));
    header('Cache-Control: no-store, no-cache, must-revalidate');
    readfile($zipPath);
    @unlink($zipPath);
    exit;
} catch (Throwable $e) {
    error_log('[gambar-pelajar-download] ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Muat turun gambar tidak dapat disediakan buat sementara waktu.';
}
