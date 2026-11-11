<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Materials;

use ClyoNutri\Core\Security\TenantGuard;
use DomainException;
use InvalidArgumentException;
use PDO;

/**
 * Biblioteca de materiais educativos da clínica (estilo WebDiet): a clínica sobe PDF/PNG/JPG uma vez e envia para
 * quantos pacientes quiser; o paciente vê e baixa na área dele (web e app).
 *
 *  - formato conferido pelo conteúdo (finfo + assinatura do PDF), não pela extensão; até 10 MB;
 *  - arquivo fora da pasta pública, nome aleatório, SHA-256 guardado;
 *  - tudo filtrado pela clínica; paciente só baixa o que foi enviado para ele e não foi revogado nem arquivado;
 *  - arquivar tira o material da biblioteca e da área do paciente sem apagar o arquivo nem o histórico de envios.
 */
final class MaterialService
{
    public const MAX_BYTES = 10 * 1024 * 1024;
    public const MIME = ['application/pdf' => 'pdf', 'image/png' => 'png', 'image/jpeg' => 'jpg'];
    public const CATEGORIES = [
        'orientacao' => 'Orientações gerais', 'receitas' => 'Receitas', 'lista_compras' => 'Lista de compras',
        'substituicoes' => 'Substituições', 'condicoes' => 'Condições de saúde', 'atividade' => 'Atividade física', 'outros' => 'Outros',
    ];
    private const ROLES = ['owner', 'admin', 'nutritionist'];

    public function __construct(private PDO $db, private ?string $dir = null)
    {
        $this->dir ??= (getenv('MATERIALS_STORAGE_DIR') ?: dirname(__DIR__, 3) . '/storage/app/materials');
    }

    /** Quem pode subir, enviar e arquivar: titular, administrador ou nutricionista ativo da clínica. */
    public function assertCanManage(int $clinicId, int $userId): void
    {
        $s = $this->db->prepare("SELECT role FROM clinic_members WHERE clinic_id = ? AND user_id = ? AND status = 'active'");
        $s->execute([$clinicId, $userId]);
        if (!in_array((string)$s->fetchColumn(), self::ROLES, true)) {
            throw new DomainException('Só o titular, administradores e nutricionistas da clínica gerenciam materiais.');
        }
    }

    /** @param array{name?:string,tmp_name?:string,size?:int,error?:int} $file */
    public function upload(int $clinicId, int $userId, array $file, string $title, string $description, string $category): int
    {
        $this->assertCanManage($clinicId, $userId);
        $title = trim($title);
        if ($title === '' || mb_strlen($title, 'UTF-8') > 160) {
            throw new InvalidArgumentException('Dê um título ao material (até 160 caracteres).');
        }
        if (!isset(self::CATEGORIES[$category])) {
            throw new InvalidArgumentException('Escolha a categoria do material.');
        }
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $big = in_array((int)($file['error'] ?? 0), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
            throw new InvalidArgumentException($big ? 'O arquivo passa de 10 MB.' : 'Escolha um arquivo PDF, PNG ou JPG.');
        }
        $tmp = (string)($file['tmp_name'] ?? '');
        $size = (int)($file['size'] ?? 0);
        if ($tmp === '' || !is_file($tmp) || $size <= 0) {
            throw new InvalidArgumentException('O arquivo não chegou inteiro. Tente de novo.');
        }
        if ($size > self::MAX_BYTES) {
            throw new InvalidArgumentException('O arquivo precisa ter até 10 MB.');
        }
        $mime = (string)(new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if (!isset(self::MIME[$mime])) {
            throw new InvalidArgumentException('Formato não aceito. Envie PDF, PNG ou JPG.');
        }
        if ($mime === 'application/pdf' && file_get_contents($tmp, false, null, 0, 5) !== '%PDF-') {
            throw new InvalidArgumentException('O arquivo não parece ser um PDF válido.');
        }
        $sha = hash_file('sha256', $tmp);
        $dup = $this->db->prepare("SELECT title FROM educational_materials WHERE clinic_id = ? AND sha256 = ? AND status = 'active'");
        $dup->execute([$clinicId, $sha]);
        if ($t = $dup->fetchColumn()) {
            throw new DomainException('Este arquivo já está na biblioteca como "' . $t . '".');
        }
        $dir = $this->dir . '/' . $clinicId;
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('Não foi possível preparar o armazenamento dos materiais.');
        }
        $stored = bin2hex(random_bytes(16)) . '.' . self::MIME[$mime];
        $dest = $dir . '/' . $stored;
        $ok = \ClyoNutri\Core\Http\UploadMover::move($tmp, $dest); // CR-NI2-011
        if (!$ok) {
            throw new \RuntimeException('Falha ao gravar o arquivo.');
        }
        @chmod($dest, 0640);
        $orig = basename(str_replace('\\', '/', (string)($file['name'] ?? 'material')));
        $orig = mb_substr((string)preg_replace('/[^\w.\-() ]+/u', '_', $orig), 0, 120) ?: 'material.' . self::MIME[$mime];
        $now = date('Y-m-d H:i:s');
        $this->db->prepare('INSERT INTO educational_materials (clinic_id, title, description, category, file_name, mime_type, size_bytes, sha256, storage_name, status, uploaded_by_user_id, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$clinicId, $title, trim($description) !== '' ? mb_substr(trim($description), 0, 2000, 'UTF-8') : null, $category, $orig, $mime, $size, $sha, $stored, 'active', $userId, $now, $now]);
        return (int)$this->db->lastInsertId();
    }

    /** @return list<array> materiais da clínica com quantos pacientes receberam */
    public function list(int $clinicId, string $q = '', string $category = '', bool $archived = false): array
    {
        $sql = "SELECT m.*, (SELECT COUNT(*) FROM educational_material_shares s WHERE s.material_id = m.id AND s.revoked_at IS NULL) AS shared_count
            FROM educational_materials m WHERE m.clinic_id = ? AND m.status = ?";
        $args = [$clinicId, $archived ? 'archived' : 'active'];
        if (isset(self::CATEGORIES[$category])) {
            $sql .= ' AND m.category = ?';
            $args[] = $category;
        }
        $q = trim($q);
        if ($q !== '') {
            $sql .= " AND (m.title LIKE ? ESCAPE '!' OR m.description LIKE ? ESCAPE '!')";
            $like = '%' . strtr(mb_substr($q, 0, 100, 'UTF-8'), ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
            array_push($args, $like, $like);
        }
        $s = $this->db->prepare($sql . ' ORDER BY m.updated_at DESC, m.id DESC');
        $s->execute($args);
        return $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function get(int $clinicId, int $id): array
    {
        $s = $this->db->prepare('SELECT * FROM educational_materials WHERE id = ? AND clinic_id = ?');
        $s->execute([$id, $clinicId]);
        $m = $s->fetch(PDO::FETCH_ASSOC);
        if (!$m) {
            throw new DomainException('Material não encontrado.'); // de outra clínica: mesma resposta
        }
        return $m;
    }

    public function update(int $clinicId, int $userId, int $id, string $title, string $description, string $category): void
    {
        $this->assertCanManage($clinicId, $userId);
        $this->get($clinicId, $id);
        $title = trim($title);
        if ($title === '' || mb_strlen($title, 'UTF-8') > 160 || !isset(self::CATEGORIES[$category])) {
            throw new InvalidArgumentException('Título (até 160 caracteres) e categoria são obrigatórios.');
        }
        $this->db->prepare('UPDATE educational_materials SET title = ?, description = ?, category = ?, updated_at = ? WHERE id = ? AND clinic_id = ?')
            ->execute([$title, trim($description) !== '' ? mb_substr(trim($description), 0, 2000, 'UTF-8') : null, $category, date('Y-m-d H:i:s'), $id, $clinicId]);
    }

    public function setArchived(int $clinicId, int $userId, int $id, bool $archived): void
    {
        $this->assertCanManage($clinicId, $userId);
        $this->get($clinicId, $id);
        $this->db->prepare('UPDATE educational_materials SET status = ?, updated_at = ? WHERE id = ? AND clinic_id = ?')
            ->execute([$archived ? 'archived' : 'active', date('Y-m-d H:i:s'), $id, $clinicId]);
    }

    /**
     * Envia para pacientes da clínica. Idempotente: quem já tem fica como está; quem tinha revogado volta a receber.
     * @param list<int> $patientIds @return int quantos passaram a ter acesso agora
     */
    public function share(int $clinicId, int $userId, int $materialId, array $patientIds, ?string $note = null): int
    {
        $this->assertCanManage($clinicId, $userId);
        $m = $this->get($clinicId, $materialId);
        if ($m['status'] !== 'active') {
            throw new DomainException('Material arquivado não pode ser enviado. Reative-o primeiro.');
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $patientIds), static fn($i) => $i > 0)));
        if (!$ids) {
            throw new InvalidArgumentException('Escolha pelo menos um paciente.');
        }
        if (count($ids) > 500) {
            throw new InvalidArgumentException('Envie para até 500 pacientes por vez.');
        }
        $note = $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 500, 'UTF-8') : null;
        $now = date('Y-m-d H:i:s');
        $made = 0;
        $manage = !$this->db->inTransaction();
        if ($manage) {
            $this->db->beginTransaction();
        }
        try {
            foreach ($ids as $pid) {
                TenantGuard::assertPatientBelongsToClinic($pid, $clinicId, $this->db);
                $s = $this->db->prepare('SELECT id, revoked_at FROM educational_material_shares WHERE material_id = ? AND patient_id = ?');
                $s->execute([$materialId, $pid]);
                $row = $s->fetch(PDO::FETCH_ASSOC);
                if ($row && $row['revoked_at'] === null) {
                    continue;
                }
                if ($row) {
                    $this->db->prepare('UPDATE educational_material_shares SET revoked_at = NULL, revoked_by_user_id = NULL, shared_by_user_id = ?, shared_at = ?, note = ? WHERE id = ?')
                        ->execute([$userId, $now, $note, (int)$row['id']]);
                } else {
                    $this->db->prepare('INSERT INTO educational_material_shares (clinic_id, material_id, patient_id, shared_by_user_id, note, shared_at) VALUES (?, ?, ?, ?, ?, ?)')
                        ->execute([$clinicId, $materialId, $pid, $userId, $note, $now]);
                }
                $made++;
            }
            if ($manage) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($manage && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
        return $made;
    }

    public function revoke(int $clinicId, int $userId, int $shareId): void
    {
        $this->assertCanManage($clinicId, $userId);
        $s = $this->db->prepare('UPDATE educational_material_shares SET revoked_at = ?, revoked_by_user_id = ? WHERE id = ? AND clinic_id = ? AND revoked_at IS NULL');
        $s->execute([date('Y-m-d H:i:s'), $userId, $shareId, $clinicId]);
        if ($s->rowCount() === 0) {
            $c = $this->db->prepare('SELECT 1 FROM educational_material_shares WHERE id = ? AND clinic_id = ?');
            $c->execute([$shareId, $clinicId]);
            if (!$c->fetchColumn()) {
                throw new DomainException('Envio não encontrado.');
            }
        }
    }

    /** @return list<array> quem recebeu o material (inclui revogados, para o histórico) */
    public function shares(int $clinicId, int $materialId): array
    {
        $this->get($clinicId, $materialId);
        $s = $this->db->prepare('SELECT s.*, p.name AS patient_name FROM educational_material_shares s JOIN patients p ON p.id = s.patient_id AND p.clinic_id = s.clinic_id
            WHERE s.clinic_id = ? AND s.material_id = ? ORDER BY s.revoked_at IS NOT NULL, s.shared_at DESC');
        $s->execute([$clinicId, $materialId]);
        return $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<array{id:int,name:string}> pacientes ativos da clínica para escolher no envio */
    public function patientsForPicker(int $clinicId): array
    {
        $s = $this->db->prepare("SELECT id, name FROM patients WHERE clinic_id = ? AND (status IS NULL OR status NOT IN ('anonymized','inactive','archived')) ORDER BY name LIMIT 2000");
        $s->execute([$clinicId]);
        return $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** Materiais visíveis para o paciente (enviados, não revogados, não arquivados). */
    public function patientList(int $clinicId, int $patientId): array
    {
        $s = $this->db->prepare("SELECT m.id, m.title, m.description, m.category, m.mime_type, m.size_bytes, s.shared_at, s.note, s.first_opened_at
            FROM educational_material_shares s JOIN educational_materials m ON m.id = s.material_id AND m.clinic_id = s.clinic_id
            WHERE s.clinic_id = ? AND s.patient_id = ? AND s.revoked_at IS NULL AND m.status = 'active' ORDER BY s.shared_at DESC, m.id DESC");
        $s->execute([$clinicId, $patientId]);
        return array_map(static fn($r) => $r + ['category_label' => self::CATEGORIES[$r['category']] ?? 'Outros'], $s->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** Arquivo para o paciente: só se foi enviado para ele. Marca a primeira abertura. */
    public function patientFile(int $clinicId, int $patientId, int $materialId): array
    {
        $s = $this->db->prepare("SELECT m.*, s.id AS share_id, s.first_opened_at FROM educational_material_shares s JOIN educational_materials m ON m.id = s.material_id AND m.clinic_id = s.clinic_id
            WHERE s.clinic_id = ? AND s.patient_id = ? AND s.material_id = ? AND s.revoked_at IS NULL AND m.status = 'active'");
        $s->execute([$clinicId, $patientId, $materialId]);
        $m = $s->fetch(PDO::FETCH_ASSOC);
        if (!$m) {
            throw new DomainException('Material não encontrado.');
        }
        if ($m['first_opened_at'] === null) {
            $this->db->prepare('UPDATE educational_material_shares SET first_opened_at = ? WHERE id = ? AND first_opened_at IS NULL')->execute([date('Y-m-d H:i:s'), (int)$m['share_id']]);
        }
        return $this->fileOf($m);
    }

    /** Arquivo para a clínica (prévia na biblioteca). */
    public function clinicFile(int $clinicId, int $materialId): array
    {
        return $this->fileOf($this->get($clinicId, $materialId));
    }

    /** Envia o arquivo com cabeçalhos seguros (PDF abre no navegador do usuário; nada é interpretado como página). */
    public static function send(array $f, bool $download = false, bool $frameable = false): void
    {
        $name = preg_replace('/["\\\\\r\n]+/', '_', (string)$f['file_name']);
        header('Content-Type: ' . $f['mime_type']);
        header('Content-Length: ' . (int)$f['size_bytes']);
        header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . $name . '"; filename*=UTF-8\'\'' . rawurlencode((string)$f['file_name']));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        $ancestors = $frameable ? "'self'" : "'none'";
        header_remove('Content-Security-Policy');
        header_remove('Content-Security-Policy-Report-Only');
        header('X-Frame-Options: ' . ($frameable ? 'SAMEORIGIN' : 'DENY'), true);
        // Imagem: documento isolado (sandbox). PDF: o visualizador do navegador precisa rodar, então só limitamos quem enquadra.
        header($f['mime_type'] === 'application/pdf'
            ? "Content-Security-Policy: frame-ancestors $ancestors; base-uri 'none'; form-action 'none'"
            : "Content-Security-Policy: sandbox; default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; frame-ancestors $ancestors", true);
        readfile($f['path']);
    }

    public static function humanSize(int $bytes): string
    {
        return $bytes >= 1048576 ? number_format($bytes / 1048576, 1, ',', '.') . ' MB' : max(1, (int)round($bytes / 1024)) . ' KB';
    }

    private function fileOf(array $m): array
    {
        $path = $this->dir . '/' . (int)$m['clinic_id'] . '/' . basename((string)$m['storage_name']);
        if (!is_file($path) || !hash_equals((string)$m['sha256'], (string)hash_file('sha256', $path))) {
            throw new \RuntimeException('Arquivo do material indisponível ou alterado no armazenamento.');
        }
        return ['path' => $path, 'file_name' => $m['file_name'], 'mime_type' => $m['mime_type'], 'size_bytes' => (int)$m['size_bytes']];
    }
}
