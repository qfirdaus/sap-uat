<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../sap/ext/vendor/autoload.php';
require_once __DIR__ . '/../includes/init.php';
require_login();
require_once __DIR__ . '/../controllers/SenaraiPelajarController.php';

use Mpdf\Mpdf;

$access = new SenaraiPelajarController(null, null, false);
require_page_access('pages/senarai-pelajar.php', $access->profile, Database::pdoMysql());

$types = [
    'status' => ['Status Pelajar', "LTRIM(RTRIM(COALESCE(NULLIF(statusketerangan, ''), NULLIF(statuskategori, ''), 'Tidak Dinyatakan')))", 'status'],
    'kadet' => ['Kategori Kadet', "LTRIM(RTRIM(COALESCE(NULLIF(kategori_kadet, ''), NULLIF(kadet, ''), 'Tidak Dinyatakan')))", 'kadet'],
    'jantina' => ['Jantina', "LTRIM(RTRIM(COALESCE(NULLIF(jantina, ''), NULLIF(kdjantina, ''), 'Tidak Dinyatakan')))", 'jantina'],
    'program' => ['Program Pengajian', "LTRIM(RTRIM(COALESCE(NULLIF(kdprogram, ''), 'Tidak Dinyatakan')))", 'program'],
];
$type = (string)($_GET['jenis'] ?? '');
$value = mb_substr(trim((string)($_GET['nilai'] ?? '')), 0, 150);
$format = (string)($_GET['format'] ?? '');
if (!isset($types[$type]) || $value === '' || !in_array($format, ['excel', 'pdf'], true)) { http_response_code(400); exit('Kriteria eksport tidak sah.'); }

try {
    $pdo = Database::pdoSybaseStudent();
    if (!$pdo instanceof PDO) throw new RuntimeException('Sambungan pangkalan data pelajar tidak tersedia.');
    [$title, $filterSql, $fileLabel] = $types[$type];
    $statement = $pdo->prepare("SELECT CONVERT(VARCHAR(30), matrik) AS matrik, LTRIM(RTRIM(COALESCE(nama, ''))) AS nama, LTRIM(RTRIM(COALESCE(nokp, ''))) AS nokp, LTRIM(RTRIM(COALESCE(kdprogram, ''))) AS program, LTRIM(RTRIM(COALESCE(NULLIF(jantina, ''), kdjantina, ''))) AS jantina, LTRIM(RTRIM(COALESCE(NULLIF(statusketerangan, ''), statuskategori, ''))) AS status FROM v210_sap_web WHERE matrik IS NOT NULL AND {$filterSql} = :nilai ORDER BY matrik DESC");
    $statement->execute([':nilai' => $value]);
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $safeName = preg_replace('/[^A-Za-z0-9]+/', '-', strtoupper($fileLabel . '-' . $value)) ?: 'LAPORAN-PELAJAR';
    $filename = 'LAPORAN-PELAJAR-' . trim($safeName, '-') . '-' . date('Ymd-His');
    if ($format === 'excel') {
        if (!class_exists('ZipArchive')) throw new RuntimeException('Sokongan ZIP PHP tidak tersedia untuk menjana fail Excel.');
        $xlsxPath = tempnam(sys_get_temp_dir(), 'sap-xlsx-');
        if ($xlsxPath === false) throw new RuntimeException('Fail Excel sementara tidak dapat dicipta.');
        @unlink($xlsxPath);
        $zip = new ZipArchive();
        if ($zip->open($xlsxPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Fail Excel tidak dapat dijana.');

        $xml = static fn($text): string => htmlspecialchars((string)$text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $cell = static function (string $ref, string $text, int $style = 3) use ($xml): string {
            return '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">' . $xml($text) . '</t></is></c>';
        };
        $sheetRows = [];
        $sheetRows[] = '<row r="1" ht="24" customHeight="1">' . $cell('A1', 'LAPORAN PELAJAR - ' . strtoupper($title) . ': ' . strtoupper($value), 1) . '</row>';
        $headers = ['#', 'NO. MATRIK', 'NAMA', 'NO. KP', 'PROGRAM', 'JANTINA', 'STATUS'];
        $headerCells = [];
        foreach ($headers as $col => $header) $headerCells[] = $cell(chr(65 + $col) . '2', $header, 2);
        $sheetRows[] = '<row r="2">' . implode('', $headerCells) . '</row>';
        foreach ($rows as $index => $row) {
            $values = [(string)($index + 1), (string)$row['matrik'], (string)$row['nama'], (string)$row['nokp'], (string)$row['program'], (string)$row['jantina'], (string)$row['status']];
            $dataCells = [];
            foreach ($values as $col => $item) $dataCells[] = $cell(chr(65 + $col) . ($index + 3), $item);
            $sheetRows[] = '<row r="' . ($index + 3) . '">' . implode('', $dataCells) . '</row>';
        }

        $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><cols><col min="1" max="1" width="7" customWidth="1"/><col min="2" max="2" width="16" customWidth="1"/><col min="3" max="3" width="34" customWidth="1"/><col min="4" max="4" width="18" customWidth="1"/><col min="5" max="5" width="18" customWidth="1"/><col min="6" max="6" width="14" customWidth="1"/><col min="7" max="7" width="28" customWidth="1"/></cols><sheetData>' . implode('', $sheetRows) . '</sheetData><mergeCells count="1"><mergeCell ref="A1:G1"/></mergeCells></worksheet>';
        $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="3"><font><sz val="10"/><name val="Arial"/></font><font><b/><sz val="12"/><color rgb="FF123B73"/><name val="Arial"/></font><font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Arial"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF2F75B5"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border><left style="thin"><color rgb="FFD9E2F0"/></left><right style="thin"><color rgb="FFD9E2F0"/></right><top style="thin"><color rgb="FFD9E2F0"/></top><bottom style="thin"><color rgb="FFD9E2F0"/></bottom><diagonal/></border></borders><cellXfs count="4"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" applyFont="1"/><xf numFmtId="0" fontId="2" fillId="2" borderId="1" applyFont="1" applyFill="1" applyBorder="1"/><xf numFmtId="0" fontId="0" fillId="0" borderId="1" applyBorder="1"/></cellXfs></styleSheet>';
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Laporan Pelajar" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
        $zip->addFromString('xl/styles.xml', $stylesXml);
        $zip->close();

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '.xlsx"');
        header('Content-Length: ' . (string)filesize($xlsxPath));
        readfile($xlsxPath);
        @unlink($xlsxPath);
        exit;
    }
    $html = '<style>body{font-family:Arial,sans-serif;font-size:8pt;color:#172033}h2{margin:0;color:#123b73;font-size:15pt}p{margin:4px 0 12px;color:#526274}table{width:100%;border-collapse:collapse}th{background:#eaf1fb;color:#254a7b;font-size:7pt;text-align:left}th,td{border:1px solid #d8e0ea;padding:5px}td{font-size:7pt}.num{text-align:right}</style><h2>LAPORAN PELAJAR</h2><p><b>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . ':</b> ' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . ' &nbsp; | &nbsp; Jumlah Rekod: ' . number_format(count($rows)) . '</p><table><thead><tr><th>#</th><th>No. Matrik</th><th>Nama</th><th>No. KP</th><th>Program</th><th>Jantina</th><th>Status</th></tr></thead><tbody>';
    foreach ($rows as $index => $row) { $html .= '<tr><td>' . ($index + 1) . '</td><td>' . htmlspecialchars((string)$row['matrik'], ENT_QUOTES, 'UTF-8') . '</td><td>' . htmlspecialchars((string)$row['nama'], ENT_QUOTES, 'UTF-8') . '</td><td>' . htmlspecialchars((string)$row['nokp'], ENT_QUOTES, 'UTF-8') . '</td><td>' . htmlspecialchars((string)$row['program'], ENT_QUOTES, 'UTF-8') . '</td><td>' . htmlspecialchars((string)$row['jantina'], ENT_QUOTES, 'UTF-8') . '</td><td>' . htmlspecialchars((string)$row['status'], ENT_QUOTES, 'UTF-8') . '</td></tr>'; }
    $html .= '</tbody></table>';
    $pdf = new Mpdf(['format' => 'A4-L', 'margin_left' => 8, 'margin_right' => 8, 'margin_top' => 10, 'margin_bottom' => 12]);
    $pdf->SetTitle($filename); $pdf->SetHTMLFooter('<div style="text-align:right;font-size:7pt;color:#64748b">Sistem Akademik Pelajar (SAP) | Halaman {PAGENO}</div>'); $pdf->WriteHTML($html); $pdf->Output($filename . '.pdf', 'D');
} catch (Throwable $e) {
    error_log('[laporan-pelajar-export] ' . $e->getMessage()); http_response_code(500); echo 'Eksport laporan tidak dapat disediakan buat sementara waktu.';
}
