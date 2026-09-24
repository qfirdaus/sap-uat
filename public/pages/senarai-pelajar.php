<?php
declare(strict_types=1);

$NEED_DATATABLES = true;
require_once __DIR__ . '/../includes/init.php';
require_login();
require_once __DIR__ . '/../controllers/SenaraiPelajarController.php';

$searchTerm = trim((string)($_GET['q'] ?? ''));
$searchBy = trim((string)($_GET['carian'] ?? 'semua'));
$statusFilter = trim((string)($_GET['status'] ?? 'semua'));
if (!in_array($statusFilter, ['semua', 'aktif', 'tidak_aktif'], true)) $statusFilter = 'semua';
$controller = new SenaraiPelajarController($searchTerm, $searchBy, false);
ensure_current_page_access($controller->profile, Database::pdoMysql());

$lang = $controller->lang;
$rows = $controller->rows;
$PAGE_TITLE = __('senarai_pelajar_page_title');
if ($PAGE_TITLE === 'senarai_pelajar_page_title' || $PAGE_TITLE === '') {
    $PAGE_TITLE = 'Senarai Keseluruhan';
}

if (!function_exists('h')) {
    function h(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
}
?>
<!doctype html>
<html lang="<?= h($lang) ?>">
<head>
  <?php include __DIR__ . '/../includes/head.php'; ?>
  <link rel="stylesheet" href="<?= h(base_url('assets/css/datatables-standard.css')) ?>">
  <link rel="stylesheet" href="<?= h(base_url('assets/css/pages/senarai-pelajar.css')) ?>">
</head>
<body data-topbar-color="<?= h($_SESSION['theme.topbar'] ?? 'light') ?>" data-menu-color="<?= h($_SESSION['theme.menu'] ?? 'light') ?>" data-layout="vertical" data-sidebar-size="default" class="loading">
<div class="wrapper">
  <?php include __DIR__ . '/../includes/topbar.php'; ?>
  <?php include __DIR__ . '/../includes/sidebar.php'; ?>
  <div class="content-page"><div class="content"><div class="container-fluid">
    <div class="row mb-3"><div class="col-12"><div class="page-title-box d-flex justify-content-between align-items-center flex-wrap">
      <h4 class="page-title"><i class="ri-graduation-cap-line me-1"></i><?= h($PAGE_TITLE) ?></h4>
      <ol class="breadcrumb m-0"><li class="breadcrumb-item"><a href="dashboard.php"><i class="ri-home-4-line me-1"></i><?= h(__('breadcrumb_home')) ?></a></li><li class="breadcrumb-item active"><?= h($PAGE_TITLE) ?></li></ol>
    </div></div></div>

    <div class="card student-list-card"><div class="card-body">
      <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-3">
        <div><h5 class="card-title mb-1">Senarai Keseluruhan Pelajar</h5><p class="text-muted mb-0">Paparan rekod pelajar yang dikemas kini daripada pangkalan data akademik.</p></div>
        <div class="d-flex align-items-center gap-2"><a class="btn btn-outline-primary btn-sm" href="gambar-pelajar.php"><i class="ri-image-line me-1"></i>Urus Gambar</a><span id="studentRecordCount" class="badge bg-primary-subtle text-primary fs-6">Memuatkan rekod...</span></div>
      </div>
      <form class="row g-2 align-items-end mb-3" method="get" role="search">
        <div class="col-md-3"><label class="form-label" for="studentSearchType">Kriteria Carian</label><select id="studentSearchType" class="form-select" name="carian"><option value="semua" <?= $controller->searchBy === 'semua' ? 'selected' : '' ?>>Matrik, No. KP atau Nama</option><option value="matrik" <?= $controller->searchBy === 'matrik' ? 'selected' : '' ?>>No. Matrik</option><option value="nokp" <?= $controller->searchBy === 'nokp' ? 'selected' : '' ?>>No. Kad Pengenalan</option><option value="nama" <?= $controller->searchBy === 'nama' ? 'selected' : '' ?>>Nama</option></select></div>
        <div class="col-md-4"><label class="form-label" for="studentSearch">Teks Carian</label><input id="studentSearch" class="form-control" name="q" value="<?= h($controller->searchTerm) ?>" placeholder="Contoh: 20240001, 900101011234 atau Ahmad Ali"></div>
        <div class="col-md-3"><label class="form-label" for="studentStatus">Status Pelajar</label><select id="studentStatus" class="form-select" name="status"><option value="semua" <?= $statusFilter === 'semua' ? 'selected' : '' ?>>Semua Status</option><option value="aktif" <?= $statusFilter === 'aktif' ? 'selected' : '' ?>>Aktif</option><option value="tidak_aktif" <?= $statusFilter === 'tidak_aktif' ? 'selected' : '' ?>>Tidak Aktif</option></select></div>
        <div class="col-md-2 d-flex gap-2"><button class="btn btn-primary" type="submit"><i class="ri-search-line me-1"></i>Cari</button><?php if ($controller->searchTerm !== '' || $statusFilter !== 'semua'): ?><a class="btn btn-light" href="senarai-pelajar.php">Reset</a><?php endif; ?></div>
      </form>
      <?php if ($controller->loadError !== null): ?>
        <div class="alert alert-warning mb-0"><i class="ri-error-warning-line me-1"></i><?= h($controller->loadError) ?></div>
      <?php else: ?>
        <div class="table-responsive dt-standard"><table id="studentDT" class="table table-bordered table-hover align-middle w-100">
          <thead><tr><th>No.</th><th>No. Matrik</th><th>Nama</th><th>No. KP</th><th>Jantina</th><th>Program</th><th>Semester</th><th>Emel</th><th>Telefon</th><th>Status</th><th>Tindakan</th></tr></thead>
          <tbody></tbody>
        </table></div>
      <?php endif; ?>
    </div></div>
  </div></div><?php include __DIR__ . '/../includes/footer.php'; ?></div>
</div>
<?php include __DIR__ . '/../includes/script.php'; ?>
<script src="<?= h(base_url('assets/js/helpers/datatables-standard.js')) ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  if (!window.jQuery || !jQuery.fn.DataTable || !document.getElementById('studentDT')) return;
  var table = jQuery('#studentDT').DataTable({ pageLength: 25, lengthMenu: [25, 50, 100], order: [[1, 'desc']], scrollX: true, processing: true, serverSide: true, searching: false, ajax: { url: <?= json_encode(base_url('ajax/senarai-pelajar-data.php')) ?>, data: { q: <?= json_encode($controller->searchTerm) ?>, carian: <?= json_encode($controller->searchBy) ?>, status: <?= json_encode($statusFilter) ?> } }, columnDefs: [{ targets: [0, 10], orderable: false, searchable: false }], language: { emptyTable: 'Tiada rekod pelajar ditemui.', zeroRecords: 'Tiada rekod sepadan ditemui.', processing: 'Memuatkan data pelajar...' } });
  table.on('order.dt search.dt draw.dt', function () { var info = table.page.info(); table.column(0, {search:'applied', order:'applied', page:'current'}).nodes().each(function (cell, index) { cell.textContent = info.start + index + 1; }); }).draw();
  table.on('xhr.dt', function (_event, _settings, json) { document.getElementById('studentRecordCount').textContent = (json.recordsFiltered || 0).toLocaleString('ms-MY') + ' rekod'; });
  if (window.DataTableStandard) window.DataTableStandard.decorate('#studentDT');
  if (window.bootstrap) document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) { new bootstrap.Tooltip(el); });
});
</script>
</body>
</html>
