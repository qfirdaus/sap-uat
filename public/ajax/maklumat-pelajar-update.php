<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/init.php';
require_login();
require_once __DIR__ . '/_helpers.php';

$isJsonRequest = str_contains(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
if (!isValidCsrfToken()) {
    jsonErrorResponse('Token keselamatan tidak sah. Sila muat semula halaman.', 400);
}

$pdoMysql = Database::pdoMysql();
ensureAjaxGroupManagePermission($pdoMysql);
$input = $isJsonRequest ? json_decode((string)file_get_contents('php://input'), true) : $_POST;
if (!is_array($input)) jsonErrorResponse('Data kemas kini tidak sah.', 400);

$matrik = trim((string)($input['matrik'] ?? ''));
if (!preg_match('/^\d{1,12}$/', $matrik)) jsonErrorResponse('No. matrik tidak sah.', 422);

$fields = [
    // Label kod (jantina/bangsa/agama/negeri/kewarganegaraan) datang daripada view.
    // Ia sengaja read-only di UI sehingga dropdown rujukan kod ditambah.
    'nama' => 100, 'nokp' => 14, 'tarikh_lahir' => 10, 'no_tentera' => 30,
    'kdjantina' => 10, 'kdbangsa' => 10, 'kdagama' => 10, 'kdneglahir' => 10, 'kdkewarganegaraan' => 10, 'kdwarga' => 10,
    'alamat1' => 80, 'alamat2' => 60,
    'alamat3' => 50, 'alamat4' => 40, 'telno' => 12, 'hpno' => 12,
    'telno_terkini' => 12, 'email' => 80, 'email_al_fateh' => 80, 'kdnegeri' => 10,
    'nama_bapa' => 100, 'telefon_bapa' => 20, 'nama_ibu' => 100, 'telefon_ibu' => 20,
];
$params = [':matrik' => (int)$matrik];
foreach ($fields as $field => $maxLength) {
    $value = trim((string)($input[$field] ?? ''));
    if (mb_strlen($value) > $maxLength) jsonErrorResponse("Nilai {$field} melebihi had yang dibenarkan.", 422);
    $params[':' . $field] = $value !== '' ? $value : null;
}
if ($params[':nama'] === null) jsonErrorResponse('Nama pelajar wajib diisi.', 422);
if ($params[':tarikh_lahir'] !== null) {
    $birthDate = (string)$params[':tarikh_lahir'];
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $birthDate, $match) || !checkdate((int)$match[2], (int)$match[3], (int)$match[1])) {
        jsonErrorResponse('Format tarikh lahir tidak sah.', 422);
    }
    // HTML date menghantar YYYY-MM-DD; Sybase ASE perlu DD/MM/YYYY untuk style 103.
    $params[':tarikh_lahir'] = $match[3] . '/' . $match[2] . '/' . $match[1];
}
if ($params[':email'] !== null && !filter_var($params[':email'], FILTER_VALIDATE_EMAIL)) jsonErrorResponse('Format emel tidak sah.', 422);
if ($params[':email_al_fateh'] !== null && !filter_var($params[':email_al_fateh'], FILTER_VALIDATE_EMAIL)) jsonErrorResponse('Format emel Al Fateh tidak sah.', 422);

try {
    $pdo = Database::pdoSybaseStudent();
    if (!$pdo instanceof PDO) throw new RuntimeException('Sambungan pangkalan data pelajar tidak tersedia.');
    $sql = "UPDATE t210student SET
                f210nama = :nama, f210nokp = :nokp, f210thlahir = CONVERT(datetime, :tarikh_lahir, 103), f210notentera = :no_tentera,
                f210kdjantina = :kdjantina, f210kdketurunan = :kdbangsa, f210kdagama = :kdagama, f210neglahir = :kdneglahir,
                f210kewarganegaraan = :kdkewarganegaraan, f210kdwarga = :kdwarga,
                f210almt1 = :alamat1, f210almt2 = :alamat2, f210almt3 = :alamat3, f210almt4 = :alamat4,
                f210telno = :telno, f210hpno = :hpno,
                f210notel_terkini = :telno_terkini, f210email = :email, f210alfateh = :email_al_fateh, f210kdnegeri = :kdnegeri,
                f210namabapa = :nama_bapa, f210nohpbapa = :telefon_bapa, f210namaibu = :nama_ibu, f210nohpibu = :telefon_ibu,
                f210thupdate = GETDATE()
            WHERE f210matrik = CONVERT(int, :matrik)";
    $statement = $pdo->prepare($sql);
    $statement->execute($params);

    $auditId = audit_event(['event_type' => 'UPDATE', 'severity' => 'INFO', 'outcome' => 'SUCCESS', 'target_type' => 'student', 'target_id' => $matrik, 'target_label' => (string)$params[':nama'], 'message' => 'Maklumat peribadi pelajar dikemas kini.', 'meta' => ['matrik' => $matrik, 'changed_fields' => array_keys($fields)]]);
    if ($auditId === null) error_log('[maklumat-pelajar-update] Rekod audit tidak dapat disahkan untuk matrik ' . $matrik);
    $response = [
        'message' => 'Maklumat pelajar berjaya dikemas kini dan direkodkan dalam audit.',
        'audit_logged' => $auditId !== null,
    ];
    if (!$isJsonRequest) {
        header('Location: ' . base_url('pages/maklumat-pelajar.php?matrik=' . rawurlencode($matrik) . '&saved=1'));
        exit;
    }
    jsonSuccessResponse($response);
} catch (Throwable $e) {
    error_log('[maklumat-pelajar-update] ' . $e->getMessage());
    $message = 'Maklumat pelajar tidak dapat dikemas kini buat sementara waktu.';
    if (($_SESSION['MM_KodKumpulan'] ?? $_SESSION['idkumpulan'] ?? '') === 'IT') {
        $message .= ' Ralat teknikal: ' . $e->getMessage();
    }
    jsonErrorResponse($message, 500);
}
