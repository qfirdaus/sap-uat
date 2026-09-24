<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/init.php';
require_login();
require_once __DIR__ . '/../controllers/SenaraiPelajarController.php';

$access = new SenaraiPelajarController(null, null, false);
ensure_current_page_access($access->profile, Database::pdoMysql());
$PAGE_TITLE = 'Laporan Pelajar';

if (!function_exists('h')) {
    function h(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
}

$totalStudents = $activeStudents = $inactiveStudents = $totalPrograms = 0;
$statusRows = $cadetRows = $genderRows = $programRows = $combinedRows = [];
$reportError = null;

try {
    $pdo = Database::pdoSybaseStudent();
    if (!$pdo instanceof PDO) throw new RuntimeException('Sambungan pangkalan data pelajar tidak tersedia.');

    $totalStudents = (int)$pdo->query('SELECT COUNT(*) FROM v210_sap_web WHERE matrik IS NOT NULL')->fetchColumn();
    $activeStudents = (int)$pdo->query("SELECT COUNT(*) FROM v210_sap_web WHERE matrik IS NOT NULL AND UPPER(LTRIM(RTRIM(COALESCE(statuskategori, '')))) = 'AKTIF'")->fetchColumn();
    $inactiveStudents = max(0, $totalStudents - $activeStudents);
    $totalPrograms = (int)$pdo->query("SELECT COUNT(DISTINCT NULLIF(LTRIM(RTRIM(COALESCE(kdprogram, ''))), '')) FROM v210_sap_web WHERE matrik IS NOT NULL")->fetchColumn();

    $loadRows = static function (PDO $connection, string $sql): array {
        return $connection->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    };
    $statusRows = $loadRows($pdo, "SELECT LTRIM(RTRIM(COALESCE(NULLIF(statusketerangan, ''), NULLIF(statuskategori, ''), 'Tidak Dinyatakan'))) AS label, COUNT(*) AS jumlah FROM v210_sap_web WHERE matrik IS NOT NULL GROUP BY statusketerangan, statuskategori ORDER BY jumlah DESC, label ASC");
    $cadetRows = $loadRows($pdo, "SELECT label, COUNT(*) AS jumlah FROM (SELECT LTRIM(RTRIM(COALESCE(NULLIF(kategori_kadet, ''), NULLIF(kadet, ''), 'Tidak Dinyatakan'))) AS label FROM v210_sap_web WHERE matrik IS NOT NULL) AS kadet_laporan GROUP BY label ORDER BY jumlah DESC, label ASC");
    $genderRows = $loadRows($pdo, "SELECT LTRIM(RTRIM(COALESCE(NULLIF(jantina, ''), NULLIF(kdjantina, ''), 'Tidak Dinyatakan'))) AS label, COUNT(*) AS jumlah FROM v210_sap_web WHERE matrik IS NOT NULL GROUP BY jantina, kdjantina ORDER BY jumlah DESC, label ASC");
    $programRows = $loadRows($pdo, "SELECT LTRIM(RTRIM(COALESCE(NULLIF(kdprogram, ''), 'Tidak Dinyatakan'))) AS kod, LTRIM(RTRIM(COALESCE(NULLIF(program, ''), 'Tidak Dinyatakan'))) AS label, COUNT(*) AS jumlah FROM v210_sap_web WHERE matrik IS NOT NULL GROUP BY kdprogram, program ORDER BY jumlah DESC, kod ASC");

    $combinedRows = $loadRows($pdo, "SELECT kod_program, program, SUM(CASE WHEN UPPER(kategori_kadet) = 'AWAM' AND UPPER(jantina) IN ('LELAKI', 'L') THEN 1 ELSE 0 END) AS awam_lelaki, SUM(CASE WHEN UPPER(kategori_kadet) = 'AWAM' AND UPPER(jantina) IN ('PEREMPUAN', 'P') THEN 1 ELSE 0 END) AS awam_perempuan, SUM(CASE WHEN UPPER(kategori_kadet) = 'KADET' AND UPPER(jantina) IN ('LELAKI', 'L') THEN 1 ELSE 0 END) AS kadet_lelaki, SUM(CASE WHEN UPPER(kategori_kadet) = 'KADET' AND UPPER(jantina) IN ('PEREMPUAN', 'P') THEN 1 ELSE 0 END) AS kadet_perempuan, SUM(CASE WHEN UPPER(jantina) IN ('LELAKI', 'L') THEN 1 ELSE 0 END) AS jumlah_lelaki, SUM(CASE WHEN UPPER(jantina) IN ('PEREMPUAN', 'P') THEN 1 ELSE 0 END) AS jumlah_perempuan FROM (SELECT LTRIM(RTRIM(COALESCE(NULLIF(kdprogram, ''), 'Tidak Dinyatakan'))) AS kod_program, LTRIM(RTRIM(COALESCE(NULLIF(program, ''), 'Tidak Dinyatakan'))) AS program, LTRIM(RTRIM(COALESCE(NULLIF(kategori_kadet, ''), NULLIF(kadet, ''), 'Tidak Dinyatakan'))) AS kategori_kadet, LTRIM(RTRIM(COALESCE(NULLIF(jantina, ''), NULLIF(kdjantina, ''), 'Tidak Dinyatakan'))) AS jantina FROM v210_sap_web WHERE matrik IS NOT NULL AND UPPER(LTRIM(RTRIM(COALESCE(statuskategori, '')))) = 'AKTIF') AS statistik_jantina GROUP BY kod_program, program ORDER BY kod_program ASC");
} catch (Throwable $e) {
    error_log('[laporan-pelajar] ' . $e->getMessage());
    $reportError = 'Laporan pelajar tidak dapat dimuatkan buat sementara waktu.';
}

$table = static function (array $rows, string $type): void {
    if ($rows === []) { echo '<p class="text-muted text-center py-3 mb-0">Tiada rekod.</p>'; return; }
    echo '<div class="table-responsive"><table class="table table-sm report-table mb-0"><thead><tr><th>#</th><th>' . ($type === 'program' ? 'Program' : 'Kategori') . '</th><th class="text-end">Jumlah</th></tr></thead><tbody>';
    foreach ($rows as $index => $row) {
        $label = $type === 'program' ? trim((string)($row['kod'] ?? '-') . ' — ' . (string)($row['label'] ?? '-')) : (string)($row['label'] ?? '-');
        $filterValue = $type === 'program' ? (string)($row['kod'] ?? '') : (string)($row['label'] ?? '');
        $detailUrl = 'laporan-pelajar-detail.php?jenis=' . rawurlencode($type) . '&nilai=' . rawurlencode($filterValue);
        echo '<tr class="report-clickable-row" data-detail-url="' . h($detailUrl) . '" tabindex="0" role="link" title="Klik untuk lihat detail"><td>' . ($index + 1) . '</td><td>' . h($label) . '</td><td class="text-end"><span class="report-count">' . number_format((int)($row['jumlah'] ?? 0)) . '</span></td></tr>';
    }
    echo '</tbody></table></div>';
};

$combinedTable = static function (array $rows): void {
    if ($rows === []) { echo '<p class="text-muted text-center py-3 mb-0">Tiada rekod.</p>'; return; }
    $total = static function (string $column) use ($rows): int {
        return array_sum(array_map(static fn(array $row): int => (int)($row[$column] ?? 0), $rows));
    };
    echo '<div class="table-responsive report-combined-table"><table class="table table-sm report-table mb-0"><thead><tr><th rowspan="2" class="text-center align-middle">#</th><th rowspan="2" class="text-center align-middle">Kod Program</th><th rowspan="2" class="text-start align-middle">Program</th><th colspan="2" class="text-center">Awam</th><th colspan="2" class="text-center">Kadet</th><th colspan="2" class="text-center">Jumlah Pelajar</th></tr><tr><th class="text-center">Lelaki</th><th class="text-center">Perempuan</th><th class="text-center">Lelaki</th><th class="text-center">Perempuan</th><th class="text-center">Lelaki</th><th class="text-center">Perempuan</th></tr></thead><tbody>';
    foreach ($rows as $index => $row) {
        $program = (string)($row['kod_program'] ?? '');
        $detailLink = static function (string $gender, string $cadet = '') use ($program): string {
            $query = 'laporan-pelajar-detail.php?jenis=program&nilai=' . rawurlencode($program) . '&status=AKTIF&jantina=' . rawurlencode($gender);
            if ($cadet !== '') $query .= '&kategori_kadet=' . rawurlencode($cadet);
            return '<a class="report-count report-count-link" href="' . h($query) . '" title="Lihat senarai pelajar">';
        };
        echo '<tr><td>' . ($index + 1) . '</td><td class="text-center"><span class="report-category">' . h($program ?: '-') . '</span></td><td>' . h((string)($row['program'] ?? '-')) . '</td><td class="text-center">' . $detailLink('LELAKI', 'AWAM') . number_format((int)($row['awam_lelaki'] ?? 0)) . '</a></td><td class="text-center">' . $detailLink('PEREMPUAN', 'AWAM') . number_format((int)($row['awam_perempuan'] ?? 0)) . '</a></td><td class="text-center">' . $detailLink('LELAKI', 'KADET') . number_format((int)($row['kadet_lelaki'] ?? 0)) . '</a></td><td class="text-center">' . $detailLink('PEREMPUAN', 'KADET') . number_format((int)($row['kadet_perempuan'] ?? 0)) . '</a></td><td class="text-center">' . $detailLink('LELAKI') . number_format((int)($row['jumlah_lelaki'] ?? 0)) . '</a></td><td class="text-center">' . $detailLink('PEREMPUAN') . number_format((int)($row['jumlah_perempuan'] ?? 0)) . '</a></td></tr>';
    }
    echo '</tbody><tfoot><tr><td></td><td></td><td><strong>JUMLAH KESELURUHAN</strong></td><td class="text-center"><span class="report-count">' . number_format($total('awam_lelaki')) . '</span></td><td class="text-center"><span class="report-count">' . number_format($total('awam_perempuan')) . '</span></td><td class="text-center"><span class="report-count">' . number_format($total('kadet_lelaki')) . '</span></td><td class="text-center"><span class="report-count">' . number_format($total('kadet_perempuan')) . '</span></td><td class="text-center"><span class="report-count">' . number_format($total('jumlah_lelaki')) . '</span></td><td class="text-center"><span class="report-count">' . number_format($total('jumlah_perempuan')) . '</span></td></tr></tfoot></table></div>';
};
?>
<!doctype html>
<html lang="<?= h($access->lang) ?>">
<head>
<?php include __DIR__ . '/../includes/head.php'; ?>
<link rel="stylesheet" href="<?= h(base_url('assets/css/pages/laporan-pelajar.css')) ?>">
<link rel="stylesheet" href="<?= h(base_url('assets/css/pages/senarai-pelajar.css')) ?>">
<style>.student-list-card .student-report-shell{padding:0;border:0;border-radius:0;box-shadow:none}.student-list-card .report-table th{padding:.42rem .6rem;vertical-align:middle;line-height:1.15;font-size:.67rem}.report-clickable-row{cursor:pointer}.report-clickable-row:hover td{background:#f4f8ff!important}.report-clickable-row:focus{outline:2px solid #93c5fd;outline-offset:-2px}.report-combined-card .report-table td{padding:.48rem .75rem}.report-combined-card .report-table td:first-child,.report-combined-card .report-table th:first-child{width:48px}.report-combined-card .report-table tfoot td{padding:.48rem .75rem;border-top:2px solid #dbe5f1;background:#f8fafc;color:#334155;font-size:.8rem}.report-category{display:inline-block;padding:.14rem .45rem;border-radius:.35rem;background:#f1f5f9;color:#475569;font-size:.69rem;font-weight:700;white-space:nowrap}.report-count-link{cursor:pointer;text-decoration:none}.report-count-link:hover,.report-count-link:focus{background:#dbeafe;color:#1d4ed8;outline:2px solid #93c5fd;outline-offset:2px}</style>
</head>
<body data-topbar-color="<?= h($_SESSION['theme.topbar'] ?? 'light') ?>" data-menu-color="<?= h($_SESSION['theme.menu'] ?? 'light') ?>" data-layout="vertical" data-sidebar-size="default" class="loading">
<div class="wrapper"><?php include __DIR__ . '/../includes/topbar.php'; ?><?php include __DIR__ . '/../includes/sidebar.php'; ?>
<div class="content-page"><div class="content"><div class="container-fluid">
  <div class="row mb-3"><div class="col-12"><div class="page-title-box d-flex justify-content-between align-items-center flex-wrap"><h4 class="page-title"><i class="ri-file-chart-line me-1"></i>Laporan Pelajar</h4><ol class="breadcrumb m-0"><li class="breadcrumb-item"><a href="dashboard.php"><i class="ri-home-4-line me-1"></i>Dashboard</a></li><li class="breadcrumb-item active">Laporan Pelajar</li></ol></div></div></div>
  <div class="card student-list-card"><div class="card-body">
    <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-3"><div><h5 class="card-title mb-1">Laporan Statistik Pelajar</h5><p class="text-muted mb-0">Ringkasan statistik rekod pelajar daripada pangkalan data akademik.</p></div><div class="d-flex align-items-center gap-2"><a class="btn btn-outline-primary btn-sm" href="senarai-pelajar.php"><i class="ri-group-line me-1"></i>Senarai Keseluruhan</a><span class="badge bg-primary-subtle text-primary fs-6"><?= number_format($totalStudents) ?> rekod</span></div></div>
  <?php if ($reportError !== null): ?><div class="alert alert-warning mb-0"><i class="ri-error-warning-line me-1"></i><?= h($reportError) ?></div><?php else: ?>
  <section class="student-report-shell">
    <div class="row g-3 mb-3">
      <div class="col-6 col-xl-3"><article class="report-summary"><span class="report-icon blue"><i class="ri-group-line"></i></span><div><small>Jumlah Pelajar</small><strong><?= number_format($totalStudents) ?></strong></div></article></div>
      <div class="col-6 col-xl-3"><article class="report-summary"><span class="report-icon green"><i class="ri-user-follow-line"></i></span><div><small>Pelajar Aktif</small><strong><?= number_format($activeStudents) ?></strong></div></article></div>
      <div class="col-6 col-xl-3"><article class="report-summary"><span class="report-icon orange"><i class="ri-user-unfollow-line"></i></span><div><small>Tidak Aktif</small><strong><?= number_format($inactiveStudents) ?></strong></div></article></div>
      <div class="col-6 col-xl-3"><article class="report-summary"><span class="report-icon purple"><i class="ri-graduation-cap-line"></i></span><div><small>Program Pengajian</small><strong><?= number_format($totalPrograms) ?></strong></div></article></div>
    </div>
    <section class="report-card report-combined-card mb-3"><header><span><i class="ri-file-list-3-line"></i> Statistik Pelajar Aktif Mengikut Kategori Kadet</span><small>Pecahan Awam dan Kadet kepada lelaki serta perempuan bagi setiap program aktif</small></header><?php $combinedTable($combinedRows); ?></section>
    <div class="row g-3">
      <div class="col-lg-6"><section class="report-card"><header><span><i class="ri-checkbox-circle-line"></i> Status Pelajar</span><small>Pecahan rekod mengikut status</small></header><?php $table($statusRows, 'status'); ?></section></div>
      <div class="col-lg-6 d-flex flex-column gap-3"><section class="report-card"><header><span><i class="ri-shield-star-line"></i> Kategori Kadet</span><small>Pecahan rekod mengikut kategori kadet</small></header><?php $table($cadetRows, 'kadet'); ?></section><section class="report-card"><header><span><i class="ri-men-line"></i> Jantina</span><small>Pecahan rekod mengikut jantina</small></header><?php $table($genderRows, 'jantina'); ?></section></div>
    </div>
  </section>
  <?php endif; ?>
</div></div>
</div></div><?php include __DIR__ . '/../includes/footer.php'; ?></div></div>
<?php include __DIR__ . '/../includes/script.php'; ?><script>function showReportDetailLoader(){if(window.AppLoader&&typeof window.AppLoader.show==='function')window.AppLoader.show('Memuatkan detail laporan...');else if(window.IQSLoader&&typeof window.IQSLoader.show==='function')window.IQSLoader.show('Memuatkan detail laporan...');}document.querySelectorAll('.report-clickable-row').forEach(function(row){function openDetail(){showReportDetailLoader();window.location.href=row.dataset.detailUrl;}row.addEventListener('click',openDetail);row.addEventListener('keydown',function(event){if(event.key==='Enter'||event.key===' '){event.preventDefault();openDetail();}});});document.querySelectorAll('.report-count-link').forEach(function(link){link.addEventListener('click',showReportDetailLoader);});</script>
</body></html>
