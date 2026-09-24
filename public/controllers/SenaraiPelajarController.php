<?php
declare(strict_types=1);

require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/User.php';

final class SenaraiPelajarController
{
    public string $lang = 'ms';
    /** @var array<string,mixed> */
    public array $profile = [];
    /** @var array<int,array<string,string>> */
    public array $rows = [];
    public ?string $loadError = null;
    public int $recordLimit = 1000;
    public string $searchTerm = '';
    public string $searchBy = 'semua';

    private PDO $pdoMysql;

    public function __construct(?string $searchTerm = null, ?string $searchBy = null, bool $loadRows = true)
    {
        $this->lang = (string)($_SESSION['lang'] ?? 'ms');
        $this->pdoMysql = Database::pdoMysql();
        $this->profile = $this->loadProfile();
        $this->applyUserTheme();
        $this->searchTerm = mb_substr(trim((string)$searchTerm), 0, 100);
        $this->searchBy = in_array($searchBy, ['semua', 'matrik', 'nokp', 'nama'], true) ? (string)$searchBy : 'semua';
        if ($loadRows) {
            $this->loadRows();
        }
    }

    /** @return array<string,mixed> */
    private function loadProfile(): array
    {
        $user = new User($this->pdoMysql);
        $loginId = trim((string)($_SESSION['f_loginID'] ?? ''));
        $staffId = trim((string)($_SESSION['f_stafID'] ?? ''));

        if ($loginId !== '') {
            return $user->getProfileByLoginID($loginId) ?: [];
        }

        return $staffId !== '' ? ($user->getProfile($staffId) ?: []) : [];
    }

    private function applyUserTheme(): void
    {
        $theme = json_decode((string)($this->profile['f_themeSetting'] ?? '{}'), true);
        $theme = is_array($theme) ? $theme : [];
        $_SESSION['theme.menu'] = $theme['sidebarColor'] ?? ($_SESSION['theme.menu'] ?? 'light');
        $_SESSION['theme.topbar'] = $theme['topbarColor'] ?? ($_SESSION['theme.topbar'] ?? 'light');
        $_SESSION['theme.layout'] = $theme['layoutMode'] ?? ($_SESSION['theme.layout'] ?? 'light');
    }

    private function loadRows(): void
    {
        try {
            $pdo = Database::pdoSybaseStudent();
            if (!$pdo instanceof PDO) {
                throw new RuntimeException('Sambungan pangkalan data pelajar tidak tersedia.');
            }

            // Had rekod melindungi halaman daripada memuat keseluruhan jadual pelajar
            // yang berpotensi besar. Gunakan carian DataTables untuk rekod yang dipaparkan.
            $where = ['matrik IS NOT NULL'];
            $params = [];
            if ($this->searchTerm !== '') {
                $needle = '%' . $this->searchTerm . '%';
                if ($this->searchBy === 'matrik') {
                    $where[] = 'CONVERT(VARCHAR(30), matrik) LIKE :search';
                    $params[':search'] = $needle;
                } elseif ($this->searchBy === 'nokp') {
                    $where[] = 'nokp LIKE :search';
                    $params[':search'] = $needle;
                } elseif ($this->searchBy === 'nama') {
                    $where[] = 'nama LIKE :search';
                    $params[':search'] = $needle;
                } else {
                    $where[] = '(CONVERT(VARCHAR(30), matrik) LIKE :search_matrik OR nokp LIKE :search_nokp OR nama LIKE :search_nama)';
                    $params = [':search_matrik' => $needle, ':search_nokp' => $needle, ':search_nama' => $needle];
                }
            }

            $sql = "
                SELECT TOP {$this->recordLimit}
                    CONVERT(VARCHAR(30), matrik) AS matrik,
                    LTRIM(RTRIM(COALESCE(nama, ''))) AS nama,
                    LTRIM(RTRIM(COALESCE(nokp, ''))) AS nokp,
                    LTRIM(RTRIM(COALESCE(kdjantina, ''))) AS jantina,
                    LTRIM(RTRIM(COALESCE(email, ''))) AS email,
                    LTRIM(RTRIM(COALESCE(telno_terkini, ''))) AS telefon,
                    LTRIM(RTRIM(COALESCE(kdprogram, ''))) AS kod_program,
                    LTRIM(RTRIM(COALESCE(program, ''))) AS program_asal,
                    LTRIM(RTRIM(COALESCE(semsemasa, ''))) AS semester,
                    LTRIM(RTRIM(COALESCE(status, ''))) AS status,
                    LTRIM(RTRIM(COALESCE(kategori_kadet, ''))) AS kategori,
                    LTRIM(RTRIM(COALESCE(kadet, ''))) AS kadet
                FROM v210_sap_web
                WHERE " . implode(' AND ', $where) . "
                ORDER BY nama ASC, matrik ASC
            ";

            $statement = $pdo->prepare($sql);
            $statement->execute($params);
            $this->rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('[SenaraiPelajarController] ' . $e->getMessage());
            $this->rows = [];
            $this->loadError = 'Senarai pelajar tidak dapat dimuatkan buat sementara waktu.';
        }
    }


}
