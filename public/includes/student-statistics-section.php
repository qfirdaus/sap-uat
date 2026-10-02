<?php
/** @var bool $studentStatsStandalone */
$studentStatsStandalone = $studentStatsStandalone ?? false;
$studentStatsShowStatus = $studentStatsShowStatus ?? false;
$studentStatsStatus = [];
$studentStatsStatusError = null;
$studentStatsFaculty = $studentStatsSemester = $studentStatsProgram = [];
$studentStatsTotal = 0;
$studentStatsError = null;

try {
    $pdo = Database::pdoSybaseStudent();
    if (!$pdo instanceof PDO) {
        throw new RuntimeException('Sambungan pangkalan data pelajar tidak tersedia.');
    }

    $active = "matrik IS NOT NULL AND UPPER(LTRIM(RTRIM(COALESCE(statuskategori, '')))) = 'AKTIF'";
    $studentStatsTotal = (int)$pdo->query("SELECT COUNT(*) FROM v210_sap_web WHERE {$active}")->fetchColumn();
    $queries = [
        'studentStatsFaculty' => "SELECT LTRIM(RTRIM(COALESCE(fakulti_singkatan, fakulti, 'Tidak Dinyatakan'))) AS label, COUNT(*) AS jumlah FROM v210_sap_web WHERE {$active} GROUP BY fakulti_singkatan, fakulti ORDER BY jumlah DESC",
        'studentStatsSemester' => "SELECT LTRIM(RTRIM(COALESCE(tahap_pengajian, 'Tidak Dinyatakan'))) AS label, COUNT(*) AS jumlah FROM v210_sap_web WHERE {$active} GROUP BY tahap_pengajian ORDER BY jumlah DESC",
        'studentStatsProgram' => "SELECT TOP 20 LTRIM(RTRIM(COALESCE(kdprogram, 'Tidak Dinyatakan'))) AS label, COUNT(*) AS jumlah FROM v210_sap_web WHERE {$active} GROUP BY kdprogram ORDER BY jumlah DESC, kdprogram ASC",
    ];
    foreach ($queries as $variable => $sql) {
        $statement = $pdo->query($sql);
        ${$variable} = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
} catch (Throwable $e) {
    error_log('[statistik-pelajar] ' . $e->getMessage());
    $studentStatsError = 'Statistik pelajar tidak dapat dimuatkan buat sementara waktu.';
}

$studentStatsChartData = static fn(array $rows): array => [
    'labels' => array_map(static fn($row) => (string)($row['label'] ?? '-'), $rows),
    'values' => array_map(static fn($row) => (int)($row['jumlah'] ?? 0), $rows),
];
$studentStatsCharts = [
    'faculty' => $studentStatsChartData($studentStatsFaculty),
    'semester' => $studentStatsChartData($studentStatsSemester),
    'program' => $studentStatsChartData($studentStatsProgram),
];

if ($studentStatsShowStatus && !$studentStatsError) {
    try {
        $statusLabel = "COALESCE(NULLIF(LTRIM(RTRIM(statusketerangan)), ''), NULLIF(LTRIM(RTRIM(statuskategori)), ''), 'Tidak Dinyatakan')";
        $studentStatsStatus = $pdo->query("SELECT label, COUNT(*) AS jumlah FROM (SELECT {$statusLabel} AS label FROM v210_sap_web WHERE matrik IS NOT NULL) AS student_status GROUP BY label ORDER BY jumlah DESC, label ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $studentStatsCharts['status'] = $studentStatsChartData($studentStatsStatus);
    } catch (Throwable $e) {
        error_log('[statistik-pelajar-status] ' . $e->getMessage());
        $studentStatsStatusError = 'Statistik mengikut status tidak dapat dimuatkan buat sementara waktu.';
    }
}
?>
<section class="student-statistics-section mt-3<?= !$studentStatsStandalone ? ' student-statistics-shell' : '' ?>">
    <?php if ($studentStatsStandalone): ?>
        <div class="page-title-box d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h4 class="page-title"><i class="ri-bar-chart-box-line me-1"></i>Statistik Pelajar Aktif</h4>
                <p class="text-muted mb-0">Paparan bilangan pelajar berdasarkan rekod aktif SAP.</p>
            </div>
            <a class="btn btn-light" href="senarai-pelajar.php"><i class="ri-group-line me-1"></i>Senarai Keseluruhan</a>
        </div>
    <?php else: ?>
        <div class="section-title mb-3">
            <h4 class="mb-1"><i class="ri-bar-chart-box-line me-1"></i><?= $studentStatsShowStatus ? 'Statistik Pelajar' : 'Statistik Pelajar Aktif' ?></h4>
            <p class="text-muted mb-0"><?= $studentStatsShowStatus ? 'Ringkasan pelajar aktif dan taburan status semua pelajar SAP.' : 'Paparan bilangan pelajar berdasarkan rekod aktif SAP.' ?></p>
        </div>
    <?php endif; ?>

    <?php if ($studentStatsError): ?>
        <div class="alert alert-warning"><?= h($studentStatsError) ?></div>
    <?php else: ?>
        <div class="row g-3 mb-3">
            <div class="col-md-4"><div class="stat-card"><span class="stat-icon primary"><i class="ri-group-line"></i></span><div><small>Pelajar Aktif</small><strong><?= number_format($studentStatsTotal) ?></strong></div></div></div>
            <div class="col-md-4"><div class="stat-card"><span class="stat-icon teal"><i class="ri-building-2-line"></i></span><div><small>Fakulti</small><strong><?= number_format(count($studentStatsFaculty)) ?></strong></div></div></div>
            <div class="col-md-4"><div class="stat-card"><span class="stat-icon purple"><i class="ri-graduation-cap-line"></i></span><div><small>Program Aktif</small><strong><?= number_format(count($studentStatsProgram)) ?></strong></div></div></div>
        </div>
        <div class="row g-3">
            <div class="col-lg-6"><section class="card chart-card h-100"><div class="card-body"><div class="chart-heading"><div><h5>Mengikut Fakulti</h5><p>Bilangan pelajar aktif bagi setiap fakulti.</p></div><i class="ri-building-2-line"></i></div><div id="facultyChart" class="chart-box"></div></div></section></div>
            <div class="col-lg-6"><section class="card chart-card h-100"><div class="card-body"><div class="chart-heading"><div><h5>Mengikut Tahap Pengajian</h5><p>Bilangan pelajar aktif mengikut tahap pengajian.</p></div><i class="ri-graduation-cap-line"></i></div><div id="semesterChart" class="chart-box"></div></div></section></div>
            <div class="<?= $studentStatsShowStatus ? 'col-lg-6' : 'col-12' ?>"><section class="card chart-card h-100"><div class="card-body"><div class="chart-heading"><div><h5>Mengikut Program Pengajian</h5><p>20 program dengan bilangan pelajar aktif tertinggi.</p></div><i class="ri-bar-chart-horizontal-line"></i></div><div id="programChart" class="chart-box chart-box--program"></div></div></section></div>
            <?php if ($studentStatsShowStatus): ?>
                <div class="col-lg-6"><section class="card chart-card h-100"><div class="card-body">
                    <div class="chart-heading"><div><h5>Mengikut Status</h5><p>Bilangan semua pelajar mengikut status pengajian, termasuk pelajar tidak aktif.</p></div><i class="ri-pie-chart-line"></i></div>
                    <?php if ($studentStatsStatusError): ?>
                        <div class="alert alert-warning mb-0"><?= h($studentStatsStatusError) ?></div>
                    <?php elseif (!$studentStatsStatus): ?>
                        <p class="text-muted mb-0">Tiada rekod pelajar untuk dipaparkan.</p>
                    <?php else: ?>
                        <p class="text-muted small">Jumlah keseluruhan: <strong><?= number_format(array_sum($studentStatsCharts['status']['values'])) ?></strong> pelajar</p>
                        <div id="statusChart" class="chart-box"></div>
                    <?php endif; ?>
                </div></section></div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</section>
