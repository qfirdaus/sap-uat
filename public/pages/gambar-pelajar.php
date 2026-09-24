<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/init.php';
require_login();
require_once __DIR__ . '/../controllers/SenaraiPelajarController.php';

$access = new SenaraiPelajarController('', 'semua', false);
ensure_current_page_access($access->profile, Database::pdoMysql());
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

$query = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
$faculty = mb_substr(trim((string)($_GET['fakulti'] ?? '')), 0, 30);
$session = mb_substr(trim((string)($_GET['sesi'] ?? '')), 0, 30);
$status = mb_substr(trim((string)($_GET['status'] ?? '')), 0, 30);
$page = min(1000, max(1, (int)($_GET['page'] ?? 1)));
$pageSize = 48;
$students = $faculties = $sessions = $statuses = [];
$totalRecords = 0;
$loadError = null;

try {
    $pdo = Database::pdoSybaseStudent();
    if (!$pdo instanceof PDO) throw new RuntimeException('Sambungan pangkalan data pelajar tidak tersedia.');
    $faculties = $pdo->query("SELECT DISTINCT LTRIM(RTRIM(fakulti_singkatan)) AS value FROM v210_sap_web WHERE LTRIM(RTRIM(COALESCE(fakulti_singkatan, ''))) <> '' ORDER BY value")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $sessions = $pdo->query("SELECT DISTINCT LTRIM(RTRIM(kdsesimasuk)) AS value FROM v210_sap_web WHERE LTRIM(RTRIM(COALESCE(kdsesimasuk, ''))) <> '' ORDER BY value DESC")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $statuses = $pdo->query("SELECT DISTINCT LTRIM(RTRIM(statuskategori)) AS value FROM v210_sap_web WHERE LTRIM(RTRIM(COALESCE(statuskategori, ''))) <> '' ORDER BY value")->fetchAll(PDO::FETCH_COLUMN) ?: [];

    $where = ['matrik IS NOT NULL'];
    $params = [];
    if ($query !== '') { $where[] = '(CONVERT(VARCHAR(30), matrik) LIKE :q_matrik OR UPPER(nama) LIKE :q_nama)'; $params[':q_matrik'] = '%' . $query . '%'; $params[':q_nama'] = '%' . mb_strtoupper($query, 'UTF-8') . '%'; }
    if ($faculty !== '') { $where[] = 'LTRIM(RTRIM(fakulti_singkatan)) = :fakulti'; $params[':fakulti'] = $faculty; }
    if ($session !== '') { $where[] = 'LTRIM(RTRIM(kdsesimasuk)) = :sesi'; $params[':sesi'] = $session; }
    if ($status !== '') { $where[] = 'LTRIM(RTRIM(statuskategori)) = :status'; $params[':status'] = $status; }
    $whereSql = implode(' AND ', $where);
    $count = $pdo->prepare('SELECT COUNT(*) FROM v210_sap_web WHERE ' . $whereSql);
    $count->execute($params);
    $totalRecords = (int)$count->fetchColumn();
    $page = min($page, max(1, (int)ceil($totalRecords / $pageSize)));
    $fetchLimit = $page * $pageSize;
    $sql = 'SELECT TOP ' . $fetchLimit . ' CONVERT(VARCHAR(30), matrik) AS matrik, LTRIM(RTRIM(COALESCE(nama, \'\'))) AS nama, LTRIM(RTRIM(COALESCE(kdprogram, \'\'))) AS kod_program, LTRIM(RTRIM(COALESCE(fakulti_singkatan, \'\'))) AS fakulti, LTRIM(RTRIM(COALESCE(kdsesimasuk, \'\'))) AS sesi, LTRIM(RTRIM(COALESCE(statuskategori, \'\'))) AS status FROM v210_sap_web WHERE ' . $whereSql . ' ORDER BY matrik DESC';
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    $students = array_slice($statement->fetchAll(PDO::FETCH_ASSOC) ?: [], ($page - 1) * $pageSize, $pageSize);
} catch (Throwable $e) {
    error_log('[gambar-pelajar] ' . $e->getMessage());
    $loadError = 'Senarai gambar pelajar tidak dapat dimuatkan buat sementara waktu.';
}

$activeGroup = prestasi_resolve_active_group($access->profile, Database::pdoMysql());
$groupCode = strtoupper(trim((string)($activeGroup['kod'] ?? '')));
$canManagePhotos = in_array($groupCode, ['ADM-SA', 'ADM-PE'], true);
$photoDirectory = __DIR__ . '/../assets/images/pelajar';
function h(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function photoLocalUrl(string $matrik, string $directory): string {
    foreach (['jpg', 'jpeg', 'png'] as $extension) {
        $path = $directory . DIRECTORY_SEPARATOR . $matrik . '.' . $extension;
        if (is_file($path)) return base_url('assets/images/pelajar/' . rawurlencode($matrik) . '.' . $extension) . '?v=' . (string)filemtime($path);
    }
    return '';
}
function photoRemoteUrl(string $matrik): string { return 'https://kemasukan.upnm.edu.my/tawaran/pelajar/student_image/' . rawurlencode($matrik) . '.jpg'; }
$pageUrl = static function (int $targetPage) use ($query, $faculty, $session, $status): string {
    $params = ['page' => max(1, $targetPage)];
    if ($query !== '') $params['q'] = $query;
    if ($faculty !== '') $params['fakulti'] = $faculty;
    if ($session !== '') $params['sesi'] = $session;
    if ($status !== '') $params['status'] = $status;
    return 'gambar-pelajar.php?' . http_build_query($params);
};
$downloadParams = [];
if ($query !== '') $downloadParams['q'] = $query;
if ($faculty !== '') $downloadParams['fakulti'] = $faculty;
if ($session !== '') $downloadParams['sesi'] = $session;
if ($status !== '') $downloadParams['status'] = $status;
$downloadUrl = '../ajax/gambar-pelajar-download.php' . ($downloadParams !== [] ? '?' . http_build_query($downloadParams) : '');
$PAGE_TITLE = 'Pengurusan Gambar Pelajar';
?>
<!doctype html>
<html lang="<?= h($access->lang) ?>"><head>
<?php include __DIR__ . '/../includes/head.php'; ?>
<link rel="stylesheet" href="<?= h(base_url('assets/css/pages/gambar-pelajar.css')) ?>">
<style>.photo-manager-card .card-body{padding:1rem}.photo-search-top{margin-bottom:.85rem}.photo-search-actions{display:flex;justify-content:flex-end;align-items:center;gap:.45rem;flex-wrap:wrap}.photo-search-actions .btn{white-space:nowrap}@media(max-width:1199.98px){.photo-search-actions{justify-content:flex-start;margin-top:.2rem}}@media(max-width:575.98px){.photo-manager-card .card-body{padding:.8rem}.photo-search-top{margin-bottom:.65rem}.photo-search-actions{gap:.35rem}.photo-search-actions .btn{flex:1 1 auto;font-size:.75rem}}</style>
<style>.photo-pagination .btn-primary,.photo-pagination .btn-light,.photo-browser-back{padding:.42rem .68rem!important;border-radius:.4rem!important;font-size:.74rem!important;font-weight:600!important;background:linear-gradient(135deg,#334155,#0f172a)!important;border-color:#1e293b!important;color:#fff!important;box-shadow:0 4px 12px rgba(15,23,42,.24)!important}.photo-pagination .btn-primary:hover,.photo-pagination .btn-light:hover,.photo-browser-back:hover{background:linear-gradient(135deg,#475569,#1e293b)!important;border-color:#334155!important;color:#fff!important}.photo-pagination .btn-light,.photo-browser-back{left:2rem!important}.photo-student-card .badge.bg-secondary-subtle{background:#fde2e2!important;color:#c62828!important}@media (min-width:768px){.photo-pagination .btn-light,.photo-browser-back{left:calc(var(--ct-leftbar-width) + 1.25rem)!important}}</style>
<style>.photo-search-actions{min-height:34px}@media(min-width:768px) and (max-width:1199.98px){.photo-search-actions{gap:.3rem}.photo-search-actions .btn{padding:.35rem .42rem;font-size:.72rem}}@media(max-width:575.98px){.photo-search-top>.btn{width:100%}.photo-search-actions{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.4rem}.photo-search-actions .btn{width:100%;min-width:0;justify-content:center}.photo-search-actions #downloadPhotosBtn{grid-column:1/-1}}</style>
<style>.photo-bulk-actions{display:flex;align-items:center;justify-content:flex-end;gap:.45rem;flex-wrap:wrap}.photo-bulk-actions .btn{white-space:nowrap}@media(min-width:768px) and (max-width:1199.98px){form[role=search]>.col-md-7,form[role=search]>.col-md-5{flex:0 0 50%;width:50%}}@media(max-width:575.98px){.photo-search-top{width:100%}.photo-search-top>div:first-child{width:100%}.photo-bulk-actions{width:100%;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.4rem}.photo-bulk-actions .btn{width:100%;min-width:0;justify-content:center;font-size:.72rem;padding:.38rem .25rem}}</style>
<style>.photo-filter-form{margin:0;padding:.7rem;border:1px solid #e5eaf1;border-radius:.65rem;background:linear-gradient(135deg,#f8fbff,#fff)}.photo-filter-form .form-label{display:flex;align-items:center;gap:.28rem;margin-bottom:.3rem}.photo-filter-form .form-label i{color:#2563eb;font-size:.82rem}.photo-filter-form .input-group-text{color:#2563eb;background:#edf4ff;border-color:#dbe5f1;padding:0 .6rem}.photo-filter-form .form-control,.photo-filter-form .form-select{background:#fff;border-color:#dbe5f1}.photo-filter-form .form-control:focus,.photo-filter-form .form-select:focus{border-color:#86aef5;box-shadow:0 0 0 .16rem rgba(37,99,235,.11)}.photo-filter-form .btn{min-height:34px;border-radius:.4rem;font-weight:600}.photo-filter-form .btn-primary{box-shadow:0 3px 8px rgba(37,99,235,.18)}@media(max-width:575.98px){.photo-filter-form{padding:.6rem}.photo-filter-form .photo-search-actions{grid-template-columns:repeat(2,minmax(0,1fr))}.photo-filter-form .photo-search-actions .btn{font-size:.76rem}}</style>
<style>.photo-filter-form{position:relative;overflow:hidden;padding:1rem!important;border:1px solid rgba(219,229,241,.9)!important;border-radius:.85rem!important;background:linear-gradient(115deg,#f5f9ff 0%,#fff 48%,#f8fbff 100%)!important;box-shadow:0 9px 24px rgba(30,64,175,.06)}.photo-filter-form:before{content:"";position:absolute;top:0;left:0;right:0;height:3px;background:linear-gradient(90deg,#2563eb,#38bdf8,#14b8a6)}.photo-filter-form .form-label{margin-bottom:.4rem!important;font-size:.68rem!important;letter-spacing:.025em;text-transform:uppercase}.photo-filter-form .form-label i{display:inline-grid;place-items:center;width:18px;height:18px;border-radius:6px;background:#e9f1ff;color:#2563eb!important;font-size:.72rem!important}.photo-filter-form .form-control,.photo-filter-form .form-select,.photo-filter-form .input-group-text{min-height:38px!important;border-radius:.55rem!important}.photo-filter-form .input-group>.form-control{border-radius:0 .55rem .55rem 0!important}.photo-filter-form .input-group-text{border-radius:.55rem 0 0 .55rem!important}.photo-filter-form .photo-search-actions{height:38px;align-items:stretch}.photo-filter-form .photo-search-actions .btn{display:inline-flex;align-items:center;justify-content:center;border-radius:.55rem!important;padding:.38rem .75rem!important}.photo-filter-form .btn-primary{background:linear-gradient(135deg,#2563eb,#4f46e5);border-color:#3159dc!important;box-shadow:0 5px 12px rgba(37,99,235,.22)!important}.photo-filter-form .btn-primary:hover{background:linear-gradient(135deg,#1d4ed8,#4338ca)}@media(max-width:575.98px){.photo-filter-form{padding:.75rem!important}.photo-filter-form .photo-search-actions{height:auto}.photo-filter-form .photo-search-actions .btn{min-height:38px}}</style>
<style>.photo-filter-form{padding:.75rem!important;border:1px solid #e5eaf1!important;border-radius:.55rem!important;background:#fff!important;box-shadow:none!important}.photo-filter-form:before{display:none}.photo-filter-form .form-label{margin-bottom:.25rem!important;font-size:.72rem!important;letter-spacing:0;text-transform:none}.photo-filter-form .form-label i{width:auto;height:auto;background:transparent;border-radius:0;font-size:.82rem!important}.photo-filter-form .form-control,.photo-filter-form .form-select,.photo-filter-form .input-group-text{min-height:35px!important;border-radius:.4rem!important}.photo-filter-form .input-group>.form-control{border-radius:0 .4rem .4rem 0!important}.photo-filter-form .input-group-text{border-radius:.4rem 0 0 .4rem!important}.photo-filter-form .photo-search-actions{height:35px}.photo-filter-form .photo-search-actions .btn{border-radius:.4rem!important;padding:.32rem .65rem!important}.photo-filter-form .btn-primary{background:#2563eb!important;border-color:#2563eb!important;box-shadow:none!important}.photo-filter-form .btn-primary:hover{background:#1d4ed8!important}@media(max-width:575.98px){.photo-filter-form{padding:.65rem!important}.photo-filter-form .photo-search-actions{height:auto}.photo-filter-form .photo-search-actions .btn{min-height:35px}}</style>
<style>.photo-search-actions{justify-content:flex-start!important;width:100%}.photo-search-actions .btn{flex:1 1 0;text-align:center}</style>
</head><body data-topbar-color="<?= h($_SESSION['theme.topbar'] ?? 'light') ?>" data-menu-color="<?= h($_SESSION['theme.menu'] ?? 'light') ?>" data-layout="vertical" data-sidebar-size="default" class="loading">
<div class="wrapper"><?php include __DIR__ . '/../includes/topbar.php'; ?><?php include __DIR__ . '/../includes/sidebar.php'; ?>
<div class="content-page"><div class="content"><div class="container-fluid">
  <div class="page-title-box d-flex justify-content-between align-items-center flex-wrap"><h4 class="page-title"><i class="ri-image-line me-1"></i><?= h($PAGE_TITLE) ?></h4><ol class="breadcrumb m-0"><li class="breadcrumb-item"><a href="dashboard.php"><i class="ri-home-4-line me-1"></i>Dashboard</a></li><li class="breadcrumb-item active">Pengurusan Gambar</li></ol></div>
  <div class="card photo-manager-card"><div class="card-body">
    <div class="photo-search-top d-flex justify-content-between align-items-start gap-2 flex-wrap"><div><h5 class="card-title mb-1">Galeri Gambar Pelajar</h5><p class="text-muted mb-0">Cari, semak dan urus gambar pelajar. Sebanyak 48 rekod dipaparkan bagi setiap halaman.</p></div><?php if ($canManagePhotos): ?><div class="photo-bulk-actions"><button id="syncAllBtn" class="btn btn-outline-info" type="button"><i class="ri-refresh-line me-1"></i>Padankan Gambar</button><a id="downloadPhotosBtn" class="btn btn-outline-primary" href="<?= h($downloadUrl) ?>"><i class="ri-download-2-line me-1"></i>Muat Turun Gambar</a></div><?php endif; ?></div>
    <form class="row g-2 align-items-end" method="get" role="search"><div class="col-12 col-md-7 col-xl-3"><label class="form-label" for="photoSearch">Carian</label><input id="photoSearch" class="form-control" name="q" value="<?= h($query) ?>" placeholder="No. Matrik atau nama"></div><div class="col-12 col-md-5 col-xl-3"><label class="form-label" for="photoFaculty">Fakulti</label><select id="photoFaculty" class="form-select" name="fakulti"><option value="">Semua Fakulti</option><?php foreach ($faculties as $item): ?><option value="<?= h($item) ?>" <?= $faculty === $item ? 'selected' : '' ?>><?= h($item) ?></option><?php endforeach; ?></select></div><div class="col-6 col-md-4 col-xl-2"><label class="form-label" for="photoSession">Sesi Masuk</label><select id="photoSession" class="form-select" name="sesi"><option value="">Semua Sesi</option><?php foreach ($sessions as $item): ?><option value="<?= h($item) ?>" <?= $session === $item ? 'selected' : '' ?>><?= h($item) ?></option><?php endforeach; ?></select></div><div class="col-6 col-md-4 col-xl-2"><label class="form-label" for="photoStatus">Status</label><select id="photoStatus" class="form-select" name="status"><option value="">Semua Status</option><?php foreach ($statuses as $item): ?><option value="<?= h($item) ?>" <?= $status === $item ? 'selected' : '' ?>><?= h($item) ?></option><?php endforeach; ?></select></div><div class="col-12 col-md-4 col-xl-2"><div class="photo-search-actions"><button class="btn btn-primary" type="submit"><i class="ri-search-line me-1"></i>Cari</button><a class="btn btn-outline-secondary" href="gambar-pelajar.php">Reset</a></div></div></form>
  </div></div>
  <?php if (!$canManagePhotos): ?><div class="alert alert-info"><i class="ri-information-line me-1"></i>Anda boleh melihat gambar sahaja. Tindakan muat naik, padam dan padanan gambar adalah untuk Pentadbir.</div><?php endif; ?>
  <div id="photoManagerAlert" class="d-none" role="alert"></div>
  <script>
  (function () {
    function escapeHtml(value) {
      var element = document.createElement('div');
      element.textContent = value;
      return element.innerHTML;
    }

    function showWarning(title, message) {
      if (window.Swal && typeof window.Swal.fire === 'function') {
        window.Swal.fire({ icon: 'warning', title: title, text: message, confirmButtonText: 'Tutup' });
      } else {
        alert(title + '\n\n' + message);
      }
    }

    function download(button) {
      var original = button.innerHTML;
      button.classList.add('disabled');
      button.setAttribute('aria-disabled', 'true');
      button.innerHTML = '<i class="ri-loader-4-line me-1"></i>Menyediakan...';
      fetch(button.href, { credentials: 'same-origin' }).then(function (response) {
        if (!response.ok) return response.text().then(function (message) { throw new Error(message || 'Muat turun gambar tidak berjaya.'); });
        var type = (response.headers.get('content-type') || '').toLowerCase();
        if (type.indexOf('zip') === -1 && type.indexOf('octet-stream') === -1) return response.text().then(function (message) { throw new Error(message || 'Fail ZIP tidak dapat disediakan.'); });
        var disposition = response.headers.get('content-disposition') || '';
        var match = disposition.match(/filename="?([^";]+)"?/i);
        return response.blob().then(function (blob) { return { blob: blob, filename: match ? match[1] : 'GAMBAR-PELAJAR.zip' }; });
      }).then(function (file) {
        var url = URL.createObjectURL(file.blob), link = document.createElement('a');
        link.href = url; link.download = file.filename; document.body.appendChild(link); link.click(); link.remove(); URL.revokeObjectURL(url);
      }).catch(function (error) {
        showWarning('Muat Turun Tidak Berjaya', error.message || 'Sila cuba semula.');
      }).finally(function () {
        button.classList.remove('disabled'); button.removeAttribute('aria-disabled'); button.innerHTML = original;
      });
    }

    document.addEventListener('click', function (event) {
      var button = event.target.closest('#downloadPhotosBtn');
      if (!button) return;
      event.preventDefault();
      event.stopImmediatePropagation();

      var values = [
        ['Carian', document.getElementById('photoSearch')],
        ['Fakulti', document.getElementById('photoFaculty')],
        ['Sesi Masuk', document.getElementById('photoSession')],
        ['Status', document.getElementById('photoStatus')]
      ];
      var selected = values.filter(function (item) { return item[1] && item[1].value.trim() !== ''; }).map(function (item) {
        var field = item[1];
        var value = field.tagName === 'SELECT' ? field.options[field.selectedIndex].text : field.value.trim();
        return '<li><strong>' + escapeHtml(item[0]) + ':</strong> ' + escapeHtml(value) + '</li>';
      });

      if (selected.length === 0) {
        showWarning('Kriteria Carian Diperlukan', 'Sila pilih sekurang-kurangnya satu kriteria carian sebelum memuat turun gambar.');
        return;
      }

      var details = '<div class="text-start"><p class="mb-2">Gambar akan dimuat turun berdasarkan pilihan berikut:</p><ul class="mb-0">' + selected.join('') + '</ul></div>';
      if (window.Swal && typeof window.Swal.fire === 'function') {
        window.Swal.fire({ icon: 'question', title: 'Sahkan Muat Turun Gambar', html: details, showCancelButton: true, confirmButtonText: '<i class="ri-download-2-line me-1"></i>Muat Turun', cancelButtonText: 'Batal', reverseButtons: true }).then(function (result) {
          if (result.isConfirmed) download(button);
        });
      } else if (window.confirm('Muat turun gambar mengikut kriteria yang dipilih?')) {
        download(button);
      }
    }, true);
  })();
  </script>
  <script>
  (function () {
    function escapeHtml(value) { var element = document.createElement('div'); element.textContent = value; return element.innerHTML; }
    function selectedCriteria() {
      return [['Carian', document.getElementById('photoSearch')], ['Fakulti', document.getElementById('photoFaculty')], ['Sesi Masuk', document.getElementById('photoSession')], ['Status', document.getElementById('photoStatus')]].filter(function (item) {
        return item[1] && item[1].value.trim() !== '';
      }).map(function (item) {
        var field = item[1], value = field.tagName === 'SELECT' ? field.options[field.selectedIndex].text : field.value.trim();
        return '<li><strong>' + escapeHtml(item[0]) + ':</strong> ' + escapeHtml(value) + '</li>';
      });
    }

    document.addEventListener('click', function (event) {
      var button = event.target.closest('#syncAllBtn');
      if (!button || button.dataset.swalConfirmed === '1') {
        if (button) delete button.dataset.swalConfirmed;
        return;
      }
      event.preventDefault(); event.stopImmediatePropagation();
      var criteria = selectedCriteria();
      var details = criteria.length ? '<div class="text-center"><ul class="list-unstyled mb-0">' + criteria.join('') + '</ul></div>' : '<p class="mb-0 text-center">Tiada penapis dipilih. Semua rekod pelajar akan diproses.</p>';
      function continueSync() {
        var originalConfirm = window.confirm;
        window.confirm = function () { return true; };
        button.dataset.swalConfirmed = '1';
        button.click();
        window.confirm = originalConfirm;
      }
      if (window.Swal && typeof window.Swal.fire === 'function') {
        window.Swal.fire({ icon: 'question', title: 'Sahkan Padanan Semua Gambar', html: details, showCancelButton: true, confirmButtonText: '<i class="ri-refresh-line me-1"></i>Ya, Padankan', cancelButtonText: 'Batal', reverseButtons: true }).then(function (result) { if (result.isConfirmed) continueSync(); });
      } else if (window.confirm('Padankan semua gambar berdasarkan kriteria semasa?')) {
        continueSync();
      }
    }, true);

    function observeSyncErrors() {
      var syncText = document.getElementById('syncAllText'), previousError = '';
      if (!syncText || !window.MutationObserver) return;
      new MutationObserver(function () {
        var message = (syncText.textContent || '').trim();
        if (!message || message === previousError || (!/tidak dapat|ralat/i.test(message) && !/gagal:\s*[1-9]/i.test(message))) return;
        previousError = message;
        if (window.Swal && typeof window.Swal.fire === 'function') window.Swal.fire({ icon: 'error', title: 'Padanan Gambar Tidak Selesai', text: message, confirmButtonText: 'Tutup' });
      }).observe(syncText, { childList: true, characterData: true, subtree: true });
    }
    document.addEventListener('DOMContentLoaded', observeSyncErrors);
  })();
  </script>
  <script>(function(){var button=document.getElementById('downloadPhotosBtn');if(!button)return;button.addEventListener('click',function(event){event.preventDefault();var original=button.innerHTML;button.classList.add('disabled');button.setAttribute('aria-disabled','true');button.innerHTML='<i class="ri-loader-4-line me-1"></i>Menyediakan...';fetch(button.href,{credentials:'same-origin'}).then(function(response){if(!response.ok){return response.text().then(function(message){throw new Error(message||'Muat turun gambar tidak berjaya.');});}var type=(response.headers.get('content-type')||'').toLowerCase();if(type.indexOf('zip')===-1&&type.indexOf('octet-stream')===-1){return response.text().then(function(message){throw new Error(message||'Fail ZIP tidak dapat disediakan.');});}var disposition=response.headers.get('content-disposition')||'';var match=disposition.match(/filename="?([^";]+)"?/i);return response.blob().then(function(blob){return{blob:blob,filename:match?match[1]:'gambar-pelajar.zip'};});}).then(function(file){var url=URL.createObjectURL(file.blob),link=document.createElement('a');link.href=url;link.download=file.filename;document.body.appendChild(link);link.click();link.remove();URL.revokeObjectURL(url);}).catch(function(error){alert('Tidak berjaya memuat turun gambar.\n\n'+(error.message||'Sila cuba semula.'));}).finally(function(){button.classList.remove('disabled');button.removeAttribute('aria-disabled');button.innerHTML=original;});});})();</script>
  <?php if ($loadError !== null): ?><div class="alert alert-warning"><i class="ri-error-warning-line me-1"></i><?= h($loadError) ?></div>
  <?php elseif ($students === []): ?><div class="card"><div class="card-body text-center py-5 text-muted"><i class="ri-image-search-line fs-1 d-block mb-2"></i>Tiada rekod pelajar ditemui.</div></div>
  <?php else: ?><div class="row g-3 photo-gallery"><?php foreach ($students as $student): $matrik = trim((string)$student['matrik']); $localPhoto = photoLocalUrl($matrik, $photoDirectory); $photo = $localPhoto ?: photoRemoteUrl($matrik); ?><div class="col-6 col-md-4 col-lg-3 col-xl-2"><article class="card photo-student-card h-100"><div class="photo-preview"><img src="<?= h($photo) ?>" data-remote="<?= h(photoRemoteUrl($matrik)) ?>" data-fallback="<?= h(base_url('assets/images/no-image.jpg')) ?>" alt="Gambar <?= h($student['nama']) ?>" loading="lazy"><span class="photo-source <?= $localPhoto !== '' ? 'local' : 'remote' ?>"><i class="ri-<?= $localPhoto !== '' ? 'hard-drive-2-line' : 'cloud-line' ?>"></i><?= $localPhoto !== '' ? 'SAP' : 'eTawaran' ?></span></div><div class="card-body"><div class="d-flex justify-content-between gap-2"><div><h6 class="mb-1"><?= h($matrik) ?></h6><p class="photo-student-name mb-2"><?= h(mb_strtoupper((string)$student['nama'], 'UTF-8')) ?></p></div><span class="badge <?= strtoupper((string)$student['status']) === 'AKTIF' ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary' ?> align-self-start"><?= h($student['status'] ?: '-') ?></span></div><div class="photo-meta"><span><i class="ri-graduation-cap-line"></i><?= h($student['kod_program'] ?: '-') ?></span><span><i class="ri-building-line"></i><?= h($student['fakulti'] ?: '-') ?></span><span><i class="ri-calendar-line"></i><?= h($student['sesi'] ?: '-') ?></span></div></div><?php if ($canManagePhotos): ?><div class="card-footer d-flex gap-2"><button class="btn btn-sm btn-primary flex-fill" type="button" data-bs-toggle="modal" data-bs-target="#uploadPhotoModal" data-matrik="<?= h($matrik) ?>" data-name="<?= h($student['nama']) ?>"><i class="ri-upload-2-line me-1"></i>Muat Naik</button><button class="btn btn-sm btn-outline-info" type="button" data-photo-action="sync" data-matrik="<?= h($matrik) ?>" title="Padan gambar daripada eTawaran"><i class="ri-refresh-line"></i></button><button class="btn btn-sm btn-outline-danger" type="button" data-photo-action="delete" data-matrik="<?= h($matrik) ?>" title="Padam gambar setempat"><i class="ri-delete-bin-line"></i></button></div><?php endif; ?></article></div><?php endforeach; ?></div><div class="photo-pagination d-flex justify-content-between align-items-center gap-2 flex-wrap"><span class="text-muted small">Memaparkan <?= h((string)((($page - 1) * $pageSize) + 1)) ?>–<?= h((string)min($page * $pageSize, $totalRecords)) ?> daripada <?= h((string)$totalRecords) ?> rekod</span><div class="d-flex gap-2"><?php if ($page > 1): ?><a class="btn btn-light btn-sm" href="<?= h($pageUrl($page - 1)) ?>"><i class="ri-arrow-left-line me-1"></i>Kembali</a><?php endif; ?><?php if ($page * $pageSize < $totalRecords): ?><a class="btn btn-primary btn-sm" href="<?= h($pageUrl($page + 1)) ?>">Papar Lagi<i class="ri-arrow-right-line ms-1"></i></a><?php endif; ?></div></div><?php endif; ?>
<?php if ($page === 1): ?><button class="btn btn-light btn-sm photo-browser-back" type="button" onclick="window.history.back()"><i class="ri-arrow-left-line me-1"></i>Kembali</button><?php endif; ?></div></div><?php include __DIR__ . '/../includes/footer.php'; ?></div></div>
<?php if ($canManagePhotos): ?><div class="modal fade" id="uploadPhotoModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><form id="photoUploadForm" class="modal-content" enctype="multipart/form-data"><div class="modal-header"><h5 class="modal-title"><i class="ri-upload-2-line me-1"></i>Muat Naik Gambar Pelajar</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>"><input type="hidden" name="action" value="upload"><input type="hidden" id="uploadMatrik" name="matrik"><p id="uploadStudentInfo" class="text-muted small"></p><label class="form-label" for="studentPhotoFile">Pilih gambar</label><input id="studentPhotoFile" class="form-control" type="file" name="student_image" accept="image/jpeg,image/png" required><div class="form-text">Format JPG, JPEG atau PNG. Saiz maksimum 5 MB.</div></div><div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Batal</button><button class="btn btn-primary" type="submit"><i class="ri-upload-2-line me-1"></i>Muat Naik</button></div></form></div></div><div class="modal fade" id="syncAllModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title"><i class="ri-refresh-line me-1"></i>Padanan Semua Gambar</h5></div><div class="modal-body"><p id="syncAllText" class="mb-3">Menyediakan senarai pelajar...</p><div class="progress" style="height:9px"><div id="syncAllProgress" class="progress-bar progress-bar-striped progress-bar-animated" style="width:0%"></div></div><p id="syncAllCount" class="small text-muted text-center mt-2 mb-0">0 / 0</p></div><div class="modal-footer"><button id="syncAllClose" type="button" class="btn btn-primary d-none">Tutup &amp; Muat Semula</button></div></div></div></div><?php endif; ?>
<?php include __DIR__ . '/../includes/script.php'; ?><script>document.querySelectorAll('.photo-preview img').forEach(function(img){img.addEventListener('error',function(){if(img.src!==img.dataset.remote){img.src=img.dataset.remote;return;}img.src=img.dataset.fallback;},{once:false});});(function(){var modal=document.getElementById('uploadPhotoModal'),form=document.getElementById('photoUploadForm'),alertBox=document.getElementById('photoManagerAlert'),actionUrl=<?= json_encode(base_url('ajax/gambar-pelajar-action.php')) ?>,csrf=<?= json_encode($_SESSION['csrf_token']) ?>;function showAlert(type,message){alertBox.className='alert alert-'+type;alertBox.textContent=message;alertBox.classList.remove('d-none');window.scrollTo({top:0,behavior:'smooth'});}if(modal){modal.addEventListener('show.bs.modal',function(event){var button=event.relatedTarget;document.getElementById('uploadMatrik').value=button.dataset.matrik;document.getElementById('uploadStudentInfo').textContent=button.dataset.matrik+' — '+button.dataset.name;form.reset();document.getElementById('uploadMatrik').value=button.dataset.matrik;});form.addEventListener('submit',function(event){event.preventDefault();var submit=form.querySelector('[type=submit]');submit.disabled=true;fetch(actionUrl,{method:'POST',body:new FormData(form)}).then(function(r){return r.json();}).then(function(data){if(!data.success)throw new Error(data.message);bootstrap.Modal.getInstance(modal).hide();showAlert('success',data.message);setTimeout(function(){location.reload();},600);}).catch(function(error){showAlert('danger',error.message||'Tindakan tidak berjaya.');}).finally(function(){submit.disabled=false;});});}document.querySelectorAll('[data-photo-action]').forEach(function(button){button.addEventListener('click',function(){var action=button.dataset.photoAction,matrik=button.dataset.matrik,verb=action==='delete'?'memadam gambar setempat ini':'memadan gambar daripada eTawaran';if(!window.confirm('Anda pasti mahu '+verb+' bagi matrik '+matrik+'?'))return;button.disabled=true;var data=new FormData();data.append('csrf_token',csrf);data.append('action',action);data.append('matrik',matrik);fetch(actionUrl,{method:'POST',body:data}).then(function(r){return r.json();}).then(function(result){if(!result.success)throw new Error(result.message);showAlert('success',result.message);setTimeout(function(){location.reload();},600);}).catch(function(error){showAlert('danger',error.message||'Tindakan tidak berjaya.');}).finally(function(){button.disabled=false;});});});})();</script><?php if ($canManagePhotos): ?><script>(function(){var button=document.getElementById('syncAllBtn'),modal=document.getElementById('syncAllModal'),progress=document.getElementById('syncAllProgress'),count=document.getElementById('syncAllCount'),text=document.getElementById('syncAllText'),close=document.getElementById('syncAllClose'),listUrl=<?= json_encode(base_url('ajax/gambar-pelajar-list.php')) ?>,actionUrl=<?= json_encode(base_url('ajax/gambar-pelajar-action.php')) ?>,csrf=<?= json_encode($_SESSION['csrf_token']) ?>;if(!button||!modal)return;function finish(ok,failed){progress.classList.remove('progress-bar-animated');progress.classList.toggle('bg-success',failed===0);progress.classList.toggle('bg-warning',failed>0);text.textContent='Padanan selesai. Berjaya: '+ok+' | Gagal: '+failed+'.';close.classList.remove('d-none');button.disabled=false;}button.addEventListener('click',function(){if(!window.confirm('Padankan semua gambar berdasarkan carian dan penapis semasa? Proses ini mungkin mengambil masa.'))return;button.disabled=true;progress.style.width='0%';progress.className='progress-bar progress-bar-striped progress-bar-animated';count.textContent='0 / 0';text.textContent='Menyediakan senarai pelajar...';close.classList.add('d-none');bootstrap.Modal.getOrCreateInstance(modal).show();fetch(listUrl+'?'+new URLSearchParams(window.location.search)).then(function(response){return response.json();}).then(function(data){if(!data.success)throw new Error(data.message);var matriks=data.matriks||[],index=0,ok=0,failed=0;if(matriks.length===0){finish(ok,failed);return;}function next(){if(index>=matriks.length){finish(ok,failed);return;}var form=new FormData();form.append('csrf_token',csrf);form.append('action','sync');form.append('matrik',matriks[index]);fetch(actionUrl,{method:'POST',body:form}).then(function(response){return response.json();}).then(function(result){if(result.success)ok++;else failed++;}).catch(function(){failed++;}).finally(function(){index++;var percent=Math.round(index/matriks.length*100);progress.style.width=percent+'%';count.textContent=index+' / '+matriks.length;text.textContent='Memadan gambar '+index+' daripada '+matriks.length+'...';next();});}next();}).catch(function(error){text.textContent=error.message||'Padanan tidak dapat dimulakan.';progress.classList.remove('progress-bar-animated');button.disabled=false;close.classList.remove('d-none');});});close.addEventListener('click',function(){location.reload();});})();</script><?php endif; ?></body></html>
