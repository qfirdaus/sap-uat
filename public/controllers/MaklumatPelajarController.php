<?php
declare(strict_types=1);

require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/User.php';

final class MaklumatPelajarController
{
    public string $lang = 'ms';
    /** @var array<string,mixed> */
    public array $profile = [];
    /** @var array<string,mixed>|null */
    public ?array $student = null;
    public ?string $loadError = null;
    /** @var array<int,array<string,mixed>> */
    public array $studentStatusChanges = [];
    /** @var array<int,array<string,mixed>> */
    public array $programStatusChanges = [];
    /** @var array<int,array<string,mixed>> */
    public array $cadetStatusChanges = [];
    /** @var array<int,array<string,mixed>> */
    public array $examinationResults = [];
    /** @var array<int,array<string,mixed>> */
    public array $muetResults = [];
    /** @var array<int,array<string,mixed>> */
    public array $spmResults = [];
    /** @var array<string,mixed> */
    public array $guardians = [];
    /** @var array<string,array<int,array{code:string,label:string}>> */
    public array $referenceOptions = [];

    private PDO $pdoMysql;

    public function __construct(?string $matrik = null)
    {
        $this->lang = (string)($_SESSION['lang'] ?? 'ms');
        $this->pdoMysql = Database::pdoMysql();
        $this->profile = $this->loadProfile();
        $this->applyUserTheme();

        $matrik = trim((string)$matrik);
        if ($matrik !== '') {
            try {
                $this->student = self::findByMatrik($matrik);
                if ($this->student !== null) {
                    $this->loadReferenceOptions();
                    $this->loadStatusChanges($matrik);
                    $this->loadExaminationResults($matrik);
                    $this->loadAcademicQualifications($matrik);
                    $this->loadGuardians($matrik);
                }
            } catch (Throwable $e) {
                error_log('[MaklumatPelajarController] ' . $e->getMessage());
                $this->loadError = 'Maklumat pelajar tidak dapat dimuatkan buat sementara waktu.';
            }
        }
    }

    private function loadReferenceOptions(): void
    {
        $pdo = Database::pdoSybaseStudent();
        if (!$pdo instanceof PDO) return;

        $this->referenceOptions['jantina'] = [['code' => 'L', 'label' => 'Lelaki'], ['code' => 'P', 'label' => 'Perempuan']];
        $this->referenceOptions['warga'] = [['code' => 'W', 'label' => 'Warganegara'], ['code' => 'B', 'label' => 'Bukan Warganegara']];
        $references = [
            'bangsa' => 'SELECT f013kdketurunan AS code, f013keterangan AS label FROM t013keturunan ORDER BY f013kdketurunan',
            'agama' => 'SELECT f014kdagama AS code, f014keterangan AS label FROM t014agama ORDER BY f014kdupu',
            'negeri_lahir' => 'SELECT f015kdnegeri AS code, f015keterangan AS label FROM t015negeri ORDER BY f015kdnegeri',
            'kewarganegaraan' => 'SELECT f015kdnegeri AS code, f015keterangan AS label FROM t015kewarganegaraan ORDER BY f015kdnegeri',
            'negeri' => 'SELECT f015kdnegeri AS code, f015keterangan AS label FROM t015negeri ORDER BY f015kdnegeri',
        ];

        foreach ($references as $key => $sql) {
            try {
                $statement = $pdo->query($sql);
                $this->referenceOptions[$key] = array_values(array_filter(
                    $statement->fetchAll(PDO::FETCH_ASSOC) ?: [],
                    static fn(array $row): bool => trim((string)($row['code'] ?? '')) !== ''
                ));
            } catch (Throwable $e) {
                error_log("[MaklumatPelajarController][reference {$key}] " . $e->getMessage());
                $this->referenceOptions[$key] = [];
            }
        }
    }

    /** @return array<string,mixed> */
    private function loadProfile(): array
    {
        $user = new User($this->pdoMysql);
        $loginId = trim((string)($_SESSION['f_loginID'] ?? ''));
        $staffId = trim((string)($_SESSION['f_stafID'] ?? ''));
        return $loginId !== '' ? ($user->getProfileByLoginID($loginId) ?: []) : ($staffId !== '' ? ($user->getProfile($staffId) ?: []) : []);
    }

    private function applyUserTheme(): void
    {
        $theme = json_decode((string)($this->profile['f_themeSetting'] ?? '{}'), true);
        $theme = is_array($theme) ? $theme : [];
        $_SESSION['theme.menu'] = $theme['sidebarColor'] ?? ($_SESSION['theme.menu'] ?? 'light');
        $_SESSION['theme.topbar'] = $theme['topbarColor'] ?? ($_SESSION['theme.topbar'] ?? 'light');
        $_SESSION['theme.layout'] = $theme['layoutMode'] ?? ($_SESSION['theme.layout'] ?? 'light');
    }

    /** @return array<string,mixed>|null */
    public static function findByMatrik(string $matrik): ?array
    {
        if (!preg_match('/^\d{1,12}$/', $matrik)) {
            return null;
        }
        $pdo = Database::pdoSybaseStudent();
        if (!$pdo instanceof PDO) {
            throw new RuntimeException('Sambungan pangkalan data pelajar tidak tersedia.');
        }
        // View ini sudah menggabungkan keterangan kod (jantina, bangsa, agama,
        // program, fakulti dan status). Jadual t210student hanya digunakan semasa save.
        $sql = "SELECT TOP 1
                    CONVERT(varchar(30), matrik) AS matrik, nama, nokp, kdjantina, jantina,
                    kdbangsa, bangsa, kdagama, agama, CONVERT(char(10), thlahir, 103) AS tarikh_lahir,
                    kdneglahir, neglahir AS negeri_lahir, kdnegeri, negeri, kdwarga, warga,
                    kdkewarganegaraan, kewarganegaraan, notentera AS no_tentera,
                    alamat1, alamat2, alamat3, alamat4, negeri, telno AS telefon_rumah, hpno AS telefon_hp,
                    telno_terkini AS telefon_terkini, email, alfateh AS email_al_fateh,
                    kdprogram AS kod_program, program AS program_asal, kdsemsemasa AS kod_semester, kdsemsemasa, semsemasa AS semester, nosem,
                    sesimasuk AS sesi_masuk, kdsesimasuk, statusketerangan AS status, statuskategori AS kategori_status, kategori_kadet AS kategori,
                    thdaftar AS tarikh_daftar, kadet, batalion, muet, '' AS oku_no,
                    '' AS oku_code, fakulti, kdfakulti, fakulti_singkatan
                FROM v210_sap_web
                WHERE matrik = CONVERT(int, :matrik)";
        $statement = $pdo->prepare($sql);
        $statement->execute([':matrik' => $matrik]);
        $student = $statement->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$student) return null;

        $yearStatement = $pdo->prepare("SELECT TOP 1 CONVERT(varchar(20), f210tahun) AS tahun_pengajian
            FROM t210student WHERE f210matrik = CONVERT(int, :matrik)");
        $yearStatement->execute([':matrik' => $matrik]);
        $student['tahun_pengajian'] = (string)($yearStatement->fetchColumn() ?: '');

        $mentor = $pdo->prepare("SELECT TOP 1
                    CAST(f350nopkj AS varchar(30)) + ' - ' + ISNULL(gelaran, '') + ' ' +
                    ISNULL(pensyarah, '') + ' (' + ISNULL(jawatansemasa, '') + ')' AS penasihat_akademik
                FROM v350v210mentormentee_detail
                WHERE kodstatus = '1' AND f350matrik = CONVERT(int, :matrik)");
        $mentor->execute([':matrik' => $matrik]);
        $student['penasihat_akademik'] = (string)($mentor->fetchColumn() ?: '-');
        return $student;
    }

    private function loadStatusChanges(string $matrik): void
    {
        $pdo = Database::pdoSybaseStudent();
        if (!$pdo instanceof PDO) return;
        $queries = [
            'studentStatusChanges' => "SELECT f231sesikuatkuasa AS sesi, f231statusbaru AS kod_status,
                    f018keterangan AS status, CONVERT(char(20), f231thsurat, 103) AS tarikh, f231catatan AS catatan
                FROM v231ubahstatus_sebab_all WHERE f231matrik = CONVERT(int, :matrik) ORDER BY f231thsurat DESC",
            'programStatusChanges' => "SELECT CONVERT(char(20), thsurat, 103) AS tarikh,
                    prog_asal, prog_baru, alasan AS catatan
                FROM v210_230ubah_prog WHERE matrik = CONVERT(int, :matrik) ORDER BY thsurat DESC",
            'cadetStatusChanges' => "SELECT CONVERT(char(20), tarikhsurat, 103) AS tarikh,
                    kadetasal, kadetbaru, catatan
                FROM v236ubah_kadet WHERE matrik = CONVERT(int, :matrik) ORDER BY tarikhsurat DESC",
        ];
        foreach ($queries as $property => $sql) {
            try {
                $statement = $pdo->prepare($sql);
                $statement->execute([':matrik' => $matrik]);
                $this->{$property} = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable $e) {
                // A missing legacy view must not block the rest of the profile page.
                error_log("[MaklumatPelajarController][$property] " . $e->getMessage());
            }
        }
    }

    private function loadExaminationResults(string $matrik): void
    {
        $pdo = Database::pdoSybaseStudent();
        if (!$pdo instanceof PDO) return;

        $queries = [
            'examinationResults' => "SELECT term, kdkelulusan, kelulusan, pngs, pngk
                FROM v312_all WHERE matrik = CONVERT(int, :matrik) ORDER BY term ASC",
            'muetResults' => "SELECT muet FROM v210_MUET WHERE matrik = CONVERT(int, :matrik)",
        ];

        foreach ($queries as $property => $sql) {
            try {
                $statement = $pdo->prepare($sql);
                $statement->execute([':matrik' => $matrik]);
                $this->{$property} = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable $e) {
                // Rekod peperiksaan daripada view legasi tidak boleh menghalang profil dipaparkan.
                error_log("[MaklumatPelajarController][$property] " . $e->getMessage());
            }
        }
    }

    private function loadAcademicQualifications(string $matrik): void
    {
        $pdo = Database::pdoSybaseStudent();
        if (!$pdo instanceof PDO) return;

        try {
            $statement = $pdo->prepare("SELECT f212kdsubjek AS kod, f002keterangan AS subjek, f212gred AS gred
                FROM t212akademik_dtl
                INNER JOIN t002subjek ON t212akademik_dtl.f212kdsubjek = t002subjek.f002singkatan
                WHERE f212matrik = CONVERT(int, :matrik)
                ORDER BY f212thcreate ASC, f212kdsubjek ASC");
            $statement->execute([':matrik' => $matrik]);
            $this->spmResults = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('[MaklumatPelajarController][spmResults] ' . $e->getMessage());
        }
    }

    private function loadGuardians(string $matrik): void
    {
        $pdo = Database::pdoSybaseStudent();
        if (!$pdo instanceof PDO) return;

        try {
            $statement = $pdo->prepare("SELECT TOP 1
                    f210namabapa AS nama_bapa, f210nohpbapa AS telefon_bapa,
                    f210namaibu AS nama_ibu, f210nohpibu AS telefon_ibu
                FROM t210student WHERE f210matrik = CONVERT(int, :matrik)");
            $statement->execute([':matrik' => $matrik]);
            $this->guardians = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            // Sesetengah pangkalan data lama mungkin belum mempunyai medan waris.
            error_log('[MaklumatPelajarController][guardians] ' . $e->getMessage());
        }
    }
}
