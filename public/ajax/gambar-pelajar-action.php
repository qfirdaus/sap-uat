<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/init.php';
require_login();
require_once __DIR__ . '/../controllers/SenaraiPelajarController.php';

header('Content-Type: application/json; charset=utf-8');

function photoJson(bool $success, string $message, int $status = 200): never { http_response_code($status); echo json_encode(['success' => $success, 'message' => $message], JSON_UNESCAPED_UNICODE); exit; }
function photoManagerAllowed(): bool {
    $controller = new SenaraiPelajarController('', 'semua', false);
    ensure_current_request_access($controller->profile, Database::pdoMysql());
    $group = prestasi_resolve_active_group($controller->profile, Database::pdoMysql());
    return in_array(strtoupper(trim((string)($group['kod'] ?? ''))), ['ADM-SA', 'ADM-PE'], true);
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') photoJson(false, 'Kaedah permintaan tidak sah.', 405);
    if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)($_POST['csrf_token'] ?? ''))) photoJson(false, 'Sesi borang tidak sah. Sila cuba semula.', 403);
    if (!photoManagerAllowed()) photoJson(false, 'Anda tidak mempunyai kebenaran untuk mengurus gambar pelajar.', 403);
    $matrik = trim((string)($_POST['matrik'] ?? ''));
    $action = trim((string)($_POST['action'] ?? ''));
    if (!preg_match('/^\d{1,12}$/', $matrik) || !in_array($action, ['upload', 'delete', 'sync'], true)) photoJson(false, 'Permintaan gambar tidak sah.', 422);
    $studentDb = Database::pdoSybaseStudent();
    $studentCheck = $studentDb->prepare('SELECT TOP 1 1 FROM v210_sap_web WHERE matrik = CONVERT(int, :matrik)');
    $studentCheck->execute([':matrik' => $matrik]);
    if (!$studentCheck->fetchColumn()) photoJson(false, 'Rekod pelajar tidak ditemui.', 404);
    $directory = __DIR__ . '/../assets/images/pelajar';
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) throw new RuntimeException('Direktori gambar tidak dapat disediakan.');

    if ($action === 'delete') {
        $deleted = false;
        foreach (['jpg', 'jpeg', 'png'] as $extension) { $file = $directory . DIRECTORY_SEPARATOR . $matrik . '.' . $extension; if (is_file($file) && unlink($file)) $deleted = true; }
        photoJson($deleted, $deleted ? 'Gambar setempat berjaya dipadam.' : 'Tiada gambar setempat untuk dipadam.', $deleted ? 200 : 404);
    }

    if ($action === 'sync') {
        $imageData = false;
        foreach (['jpg', 'jpeg'] as $extension) {
            $url = 'https://kemasukan.upnm.edu.my/tawaran/pelajar/student_image/' . rawurlencode($matrik) . '.' . $extension;
            $imageData = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 15], 'https' => ['timeout' => 15]]));
            if (is_string($imageData) && $imageData !== '') break;
        }
        if (!is_string($imageData) || $imageData === '' || @getimagesizefromstring($imageData) === false) photoJson(false, 'Gambar eTawaran tidak ditemui atau tidak sah.', 404);
        $target = $directory . DIRECTORY_SEPARATOR . $matrik . '.jpg';
        $temporary = $target . '.tmp';
        if (file_put_contents($temporary, $imageData, LOCK_EX) === false) throw new RuntimeException('Gambar tidak dapat disimpan.');
        if (is_file($target) && !unlink($target)) { @unlink($temporary); throw new RuntimeException('Gambar sedia ada tidak dapat diganti.'); }
        if (!rename($temporary, $target)) { @unlink($temporary); throw new RuntimeException('Gambar tidak dapat disimpan.'); }
        photoJson(true, 'Gambar berjaya dipadan daripada eTawaran.');
    }

    $file = $_FILES['student_image'] ?? null;
    if (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)($file['tmp_name'] ?? ''))) photoJson(false, 'Fail gambar tidak sah.', 422);
    if ((int)($file['size'] ?? 0) > 5 * 1024 * 1024) photoJson(false, 'Saiz gambar melebihi 5 MB.', 422);
    $type = @exif_imagetype((string)$file['tmp_name']);
    if (!in_array($type, [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) photoJson(false, 'Hanya fail JPG, JPEG atau PNG dibenarkan.', 422);
    $source = $type === IMAGETYPE_PNG ? @imagecreatefrompng((string)$file['tmp_name']) : @imagecreatefromjpeg((string)$file['tmp_name']);
    if ($source === false) photoJson(false, 'Fail gambar tidak dapat diproses.', 422);
    $width = imagesx($source); $height = imagesy($source);
    $canvas = imagecreatetruecolor($width, $height); imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255)); imagecopy($canvas, $source, 0, 0, 0, 0, $width, $height);
    $saved = imagejpeg($canvas, $directory . DIRECTORY_SEPARATOR . $matrik . '.jpg', 90); imagedestroy($source); imagedestroy($canvas);
    if (!$saved) throw new RuntimeException('Gambar tidak dapat disimpan.');
    photoJson(true, 'Gambar pelajar berjaya dimuat naik.');
} catch (Throwable $e) { error_log('[pengurusan-gambar-action] ' . $e->getMessage()); photoJson(false, 'Tindakan gambar tidak dapat diselesaikan buat sementara waktu.', 500); }
