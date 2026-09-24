<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/init.php';
require_login();
require_once __DIR__ . '/../controllers/SenaraiPelajarController.php';
$access = new SenaraiPelajarController(null, null, false);
require_page_access('pages/senarai-pelajar.php', $access->profile, Database::pdoMysql());
$PAGE_TITLE = 'Statistik Pelajar';
if (!function_exists('h')) { function h(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); } }
?>
<!doctype html><html lang="ms"><head><?php include __DIR__ . '/../includes/head.php'; ?><link rel="stylesheet" href="<?= h(base_url('assets/css/pages/statistik-pelajar.css')) ?>"></head><body><div class="wrapper"><?php include __DIR__ . '/../includes/topbar.php'; ?><?php include __DIR__ . '/../includes/sidebar.php'; ?><div class="content-page"><div class="content"><div class="container-fluid">
<?php $studentStatsStandalone = true; include __DIR__ . '/../includes/student-statistics-section.php'; ?></div></div><?php include __DIR__ . '/../includes/footer.php'; ?></div></div><?php include __DIR__ . '/../includes/script.php'; ?>
<?php if (!$studentStatsError): ?><script>window.studentStats=<?= json_encode($studentStatsCharts, JSON_UNESCAPED_UNICODE) ?>;</script><script src="<?= h(base_url('assets/js/pages/statistik-pelajar.js')) ?>"></script><?php endif; ?></body></html>
