<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/init.php';
require_login();
require_once __DIR__ . '/../controllers/SenaraiPelajarController.php';

$access = new SenaraiPelajarController(null, null, false);
require_page_access('pages/senarai-pelajar.php', $access->profile, Database::pdoMysql());
$PAGE_TITLE = 'Detail Laporan Pelajar';

if (!function_exists('h')) { function h(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); } }

$types = [
    'status' => ['Status Pelajar', "LTRIM(RTRIM(COALESCE(NULLIF(statusketerangan, ''), NULLIF(statuskategori, ''), 'Tidak Dinyatakan')))", 'ri-checkbox-circle-line'],
    'kadet' => ['Kategori Kadet', "LTRIM(RTRIM(COALESCE(NULLIF(kategori_kadet, ''), NULLIF(kadet, ''), 'Tidak Dinyatakan')))", 'ri-shield-star-line'],
    'jantina' => ['Jantina', "LTRIM(RTRIM(COALESCE(NULLIF(jantina, ''), NULLIF(kdjantina, ''), 'Tidak Dinyatakan')))", 'ri-men-line'],
    'program' => ['Program Pengajian', "LTRIM(RTRIM(COALESCE(NULLIF(kdprogram, ''), 'Tidak Dinyatakan')))", 'ri-book-open-line'],
];
$type = (string)($_GET['jenis'] ?? '');
$value = mb_substr(trim((string)($_GET['nilai'] ?? '')), 0, 150);
if (!isset($types[$type]) || $value === '') { header('Location: laporan-pelajar.php'); exit; }
[$typeTitle, $filterSql, $icon] = $types[$type];
$genderFilter = strtoupper(trim((string)($_GET['jantina'] ?? '')));
$genderFilter = in_array($genderFilter, ['LELAKI', 'L', 'PEREMPUAN', 'P'], true) ? $genderFilter : '';
$cadetFilter = strtoupper(trim((string)($_GET['kategori_kadet'] ?? '')));
$cadetFilter = in_array($cadetFilter, ['AWAM', 'KADET'], true) ? $cadetFilter : '';
$activeOnly = strtoupper(trim((string)($_GET['status'] ?? ''))) === 'AKTIF';
$genderSql = "UPPER(LTRIM(RTRIM(COALESCE(NULLIF(jantina, ''), NULLIF(kdjantina, ''), 'Tidak Dinyatakan'))))";
$cadetSql = "UPPER(LTRIM(RTRIM(COALESCE(NULLIF(kategori_kadet, ''), NULLIF(kadet, ''), 'Tidak Dinyatakan'))))";
$statusSql = "UPPER(LTRIM(RTRIM(COALESCE(statuskategori, ''))))";
$filterNotes = [];
if ($activeOnly) $filterNotes[] = 'Aktif';
if ($cadetFilter !== '') $filterNotes[] = ucfirst(strtolower($cadetFilter));
if ($genderFilter !== '') $filterNotes[] = in_array($genderFilter, ['L', 'LELAKI'], true) ? 'Lelaki' : 'Perempuan';
$detailLabel = $value . ($filterNotes !== [] ? ' · ' . implode(' · ', $filterNotes) : '');
$total = $male = $female = $programTotal = 0;
$error = null;

try {
    $pdo = Database::pdoSybaseStudent();
    if (!$pdo instanceof PDO) throw new RuntimeException('Sambungan pangkalan data pelajar tidak tersedia.');
    $where = "matrik IS NOT NULL AND {$filterSql} = :value";
    $params = [':value' => $value];
    if ($activeOnly) $where .= " AND {$statusSql} = 'AKTIF'";
    if ($cadetFilter !== '') { $where .= " AND {$cadetSql} = :kategori_kadet"; $params[':kategori_kadet'] = $cadetFilter; }
    if ($genderFilter !== '') {
        $where .= in_array($genderFilter, ['L', 'LELAKI'], true) ? " AND {$genderSql} IN ('LELAKI', 'L')" : " AND {$genderSql} IN ('PEREMPUAN', 'P')";
    }
    $totalStatement = $pdo->prepare("SELECT COUNT(*) FROM v210_sap_web WHERE {$where}");
    $totalStatement->execute($params); $total = (int)$totalStatement->fetchColumn();
    $genderStatement = $pdo->prepare("SELECT LTRIM(RTRIM(COALESCE(NULLIF(jantina, ''), NULLIF(kdjantina, ''), 'Tidak Dinyatakan'))) AS label, COUNT(*) AS jumlah FROM v210_sap_web WHERE {$where} GROUP BY jantina, kdjantina");
    $genderStatement->execute($params);
    foreach ($genderStatement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $label = strtoupper((string)($row['label'] ?? '')); if (in_array($label, ['L', 'LELAKI'], true)) $male += (int)$row['jumlah']; if (in_array($label, ['P', 'PEREMPUAN'], true)) $female += (int)$row['jumlah'];
    }
    $programStatement = $pdo->prepare("SELECT COUNT(DISTINCT NULLIF(LTRIM(RTRIM(COALESCE(kdprogram, ''))), '')) FROM v210_sap_web WHERE {$where}");
    $programStatement->execute($params); $programTotal = (int)$programStatement->fetchColumn();
} catch (Throwable $e) {
    error_log('[laporan-pelajar-detail] ' . $e->getMessage()); $error = 'Detail laporan tidak dapat dimuatkan buat sementara waktu.';
}
?>
<!doctype html><html lang="<?= h($access->lang) ?>"><head><?php include __DIR__ . '/../includes/head.php'; ?><link rel="stylesheet" href="<?= h(base_url('assets/css/datatables-standard.css')) ?>"><link rel="stylesheet" href="<?= h(base_url('assets/css/pages/laporan-pelajar.css')) ?>"></head>
<body data-topbar-color="<?= h($_SESSION['theme.topbar'] ?? 'light') ?>" data-menu-color="<?= h($_SESSION['theme.menu'] ?? 'light') ?>" data-layout="vertical" data-sidebar-size="default"><div class="wrapper"><?php include __DIR__ . '/../includes/topbar.php'; ?><?php include __DIR__ . '/../includes/sidebar.php'; ?><div class="content-page"><div class="content"><div class="container-fluid">
<div class="page-title-box d-flex justify-content-between align-items-center flex-wrap gap-2"><div><h4 class="page-title"><i class="<?= h($icon) ?> me-1"></i>Detail <?= h($typeTitle) ?></h4><p class="text-muted mb-0">Statistik bagi: <strong><?= h($detailLabel) ?></strong></p></div><div class="d-flex gap-2 flex-wrap"><a class="btn btn-outline-success btn-sm d-inline-flex align-items-center justify-content-center" data-no-loader href="../ajax/laporan-pelajar-export.php?jenis=<?= h(rawurlencode($type)) ?>&nilai=<?= h(rawurlencode($value)) ?>&format=excel" title="Muat turun fail Excel (.xlsx)"><i class="ri-file-excel-2-fill me-1"></i><span>Muat Turun Excel</span></a><a class="btn btn-outline-danger btn-sm d-inline-flex align-items-center justify-content-center" data-no-loader href="../ajax/laporan-pelajar-export.php?jenis=<?= h(rawurlencode($type)) ?>&nilai=<?= h(rawurlencode($value)) ?>&format=pdf" title="Muat turun fail PDF"><i class="ri-file-pdf-fill me-1"></i><span>Muat Turun PDF</span></a><a class="btn btn-light d-inline-flex align-items-center justify-content-center" href="laporan-pelajar.php"><i class="ri-arrow-left-line me-1"></i><span>Kembali ke Laporan</span></a></div></div>
<?php if ($error !== null): ?><div class="alert alert-warning"><i class="ri-error-warning-line me-1"></i><?= h($error) ?></div><?php else: ?><section class="student-report-shell"><div class="row g-3 mb-3"><div class="col-6 col-xl-3"><article class="report-summary"><span class="report-icon blue"><i class="ri-group-line"></i></span><div><small>Jumlah Rekod</small><strong><?= number_format($total) ?></strong></div></article></div><div class="col-6 col-xl-3"><article class="report-summary"><span class="report-icon green"><i class="ri-men-line"></i></span><div><small>Lelaki</small><strong><?= number_format($male) ?></strong></div></article></div><div class="col-6 col-xl-3"><article class="report-summary"><span class="report-icon purple"><i class="ri-women-line"></i></span><div><small>Perempuan</small><strong><?= number_format($female) ?></strong></div></article></div><div class="col-6 col-xl-3"><article class="report-summary"><span class="report-icon orange"><i class="ri-book-open-line"></i></span><div><small>Program Terlibat</small><strong><?= number_format($programTotal) ?></strong></div></article></div></div><section class="report-card"><header><span><i class="ri-group-line"></i> Senarai Pelajar</span><small>Gunakan carian atau nombor halaman untuk melihat semua rekod.</small></header><div class="detail-table-area"><div id="detailStudentLoading" class="detail-table-loading" role="status" aria-live="polite"><span class="detail-table-spinner"></span><span>Memuatkan data pelajar...</span></div><div class="table-responsive"><table id="detailStudentDT" class="table table-sm report-table mb-0 w-100"><thead><tr><th>#</th><th>No. Matrik</th><th>Nama</th><th>Program</th><th>Jantina</th><th>Status</th></tr></thead><tbody></tbody></table></div></div></section></section><?php endif; ?>
</div></div><?php include __DIR__ . '/../includes/footer.php'; ?></div></div>
<?php include __DIR__ . '/../includes/script.php'; ?>
<script src="<?= h(base_url('assets/js/helpers/datatables-standard.js')) ?>"></script>
<?php if ($error === null): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  if (!window.jQuery || !jQuery.fn.DataTable) return;
  var grid = jQuery('#detailStudentDT');
  var loading = document.getElementById('detailStudentLoading');
  var setLoading = function (active) {
    if (loading) loading.classList.toggle('is-visible', !!active);
  };
  setLoading(true);
  grid.removeClass('table-sm report-table').addClass('table-bordered table-hover align-middle');
  grid.parent().addClass('dt-standard');
  var options = {
    processing: true,
    serverSide: true,
    pageLength: 25,
    lengthMenu: [25, 50, 100],
    ajax: { url: '../ajax/laporan-pelajar-detail-data.php', data: { jenis: <?= json_encode($type) ?>, nilai: <?= json_encode($value, JSON_UNESCAPED_UNICODE) ?>, jantina: <?= json_encode($genderFilter) ?>, kategori_kadet: <?= json_encode($cadetFilter) ?>, status: <?= json_encode($activeOnly ? 'AKTIF' : '') ?> } },
    order: [[1, 'desc']],
    columns: [{ orderable: false, searchable: false, render: function (data, type, row, meta) { return meta.row + meta.settings._iDisplayStart + 1; } }, null, null, null, null, null],
    language: { emptyTable: 'Tiada rekod pelajar ditemui.', zeroRecords: 'Tiada rekod sepadan ditemui.', processing: 'Memuatkan data pelajar...', search: 'Carian:', lengthMenu: 'Papar _MENU_ rekod', info: 'Memaparkan _START_ hingga _END_ daripada _TOTAL_ rekod', infoEmpty: 'Tiada rekod', paginate: { previous: 'Sebelum', next: 'Seterusnya' } }
  };
  if (window.DataTableStandard) options = window.DataTableStandard.options(options);
  var table = grid.DataTable(options);
  table.on('processing.dt', function (_event, _settings, processing) { setLoading(processing); });
  table.on('error.dt', function () { setLoading(false); });
  table.on('order.dt search.dt draw.dt', function () {
    var info = table.page.info();
    table.column(0, { search: 'applied', order: 'applied', page: 'current' }).nodes().each(function (cell, index) { cell.textContent = info.start + index + 1; });
  }).draw();
  if (window.DataTableStandard) window.DataTableStandard.decorate('#detailStudentDT', { searchPlaceholder: 'Cari matrik, nama atau program' });
});
</script>
<?php endif; ?>
</body></html>
