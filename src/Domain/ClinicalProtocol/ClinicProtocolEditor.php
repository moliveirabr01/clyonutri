<?php
declare(strict_types=1);
namespace ClyoNutri\Domain\ClinicalProtocol;

use DomainException;
use PDO;

/** Private templates. Every operation checks the actor and owner before loading content. */
final class ClinicProtocolEditor
{
    public function __construct(private PDO $db) {}

    public function authorize(int $clinic, int $actor): void
    {
        $st = $this->db->prepare("SELECT 1 FROM clinic_members cm JOIN users u ON u.id = cm.user_id JOIN professionals p ON p.user_id = u.id JOIN clinics c ON c.id = cm.clinic_id
            WHERE cm.clinic_id = ? AND cm.user_id = ? AND cm.status = 'active' AND cm.role IN ('owner','admin','nutritionist')
            AND u.status = 'active' AND c.status = 'active' AND TRIM(COALESCE(p.crn,'')) <> ''");
        $st->execute([$clinic, $actor]);
        if (!$st->fetchColumn()) throw new DomainException('Acesso restrito a nutricionista ativo com CRN nesta clínica.');
    }

    public function list(int $clinic, int $actor): array
    {
        $this->authorize($clinic, $actor);
        $st = $this->db->prepare('SELECT p.id AS protocol_id, p.name, v.* FROM clinical_protocols p JOIN clinical_protocol_versions v ON v.protocol_id = p.id WHERE p.clinic_id = ? ORDER BY p.id DESC, v.version DESC');
        $st->execute([$clinic]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function version(int $clinic, int $actor, int $version): array
    {
        $this->authorize($clinic, $actor);
        $st = $this->db->prepare('SELECT v.id FROM clinical_protocol_versions v JOIN clinical_protocols p ON p.id = v.protocol_id WHERE v.id = ? AND p.clinic_id = ?' . ($this->db->inTransaction() && $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''));
        $st->execute([$version, $clinic]);
        if (!$st->fetchColumn()) throw new DomainException('Protocolo não encontrado.');
        return (new ProtocolCatalog($this->db))->version($version);
    }

    public function save(int $clinic, int $actor, ?int $version, array $definition, string $references, ?string $baseHash = null): int
    {
        $this->authorize($clinic, $actor);
        $definition = self::definition($definition);
        $catalog = new ProtocolCatalog($this->db);
        $this->db->beginTransaction();
        try {
            if ($version) {
                $row = $this->version($clinic, $actor, $version);
                if ($row['status'] === 'draft') {
                    if ($baseHash !== null && !hash_equals(self::revision($row), $baseHash)) throw new DomainException('Outra edição deste rascunho foi salva. Suas alterações estão no formulário; compare com a versão salva antes de tentar novamente.');
                    $catalog->updateDraft($version, $definition, $references, $actor);
                    $id = $version;
                } else {
                    $id = $catalog->createDraft((int)$row['protocol_id'], $definition, $references, $actor);
                }
            } else {
                $code = 'clinic_' . $clinic . '_' . bin2hex(random_bytes(8));
                $pid = $catalog->register($code, $definition['title'], !empty($definition['dietary_condition'])?'condition':'profile');
                $this->db->prepare('UPDATE clinical_protocols SET clinic_id = ? WHERE id = ?')->execute([$clinic, $pid]);
                $id = $catalog->createDraft($pid, $definition, $references, $actor);
            }
            $this->db->commit();
            return $id;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function submit(int $clinic, int $actor, int $version): void
    {
        $row = $this->version($clinic, $actor, $version);
        self::definition(json_decode($row['definition_json'], true, 32, JSON_THROW_ON_ERROR));
        if (trim((string)$row['references_text']) === '') throw new DomainException('Inclua referências antes de solicitar revisão clínica.');
        (new ProtocolCatalog($this->db))->submit($version, $actor);
    }

    public function approve(int $clinic, int $actor, int $version, string $due): void
    {
        $row = $this->version($clinic, $actor, $version);
        self::definition(json_decode($row['definition_json'], true, 32, JSON_THROW_ON_ERROR));
        $st = $this->db->prepare('SELECT u.name, p.crn FROM users u JOIN professionals p ON p.user_id = u.id WHERE u.id = ?');
        $st->execute([$actor]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        (new ProtocolCatalog($this->db))->approve($version, $actor, $p['name'], $p['crn'], $due);
    }

    public static function revision(array $row): string
    {
        return hash('sha256', $row['content_sha256'] . "\0" . (string)$row['references_text'] . "\0" . $row['status']);
    }

    public function share(int $clinic, int $actor, int $version, int $target): void
    {
        $row = $this->version($clinic, $actor, $version);
        $this->assertSharingLicense($clinic);
        if ($target === $clinic || $target < 1) throw new DomainException('Escolha outra clínica destinatária.');
        $st = $this->db->prepare("SELECT 1 FROM clinics WHERE id = ? AND status = 'active'");
        $st->execute([$target]);
        if (!$st->fetchColumn()) throw new DomainException('Destino indisponível.');
        if ($row['status'] !== 'approved') throw new DomainException('Compartilhe somente versões aprovadas.');
        $st = $this->db->prepare('SELECT id FROM clinic_protocol_shares WHERE protocol_version_id = ? AND target_clinic_id = ?');
        $st->execute([$version, $target]);
        if ($st->fetchColumn()) return;
        $this->db->prepare('INSERT INTO clinic_protocol_shares (protocol_version_id, source_clinic_id, target_clinic_id, shared_by_user_id, created_at) VALUES (?, ?, ?, ?, ?)')->execute([$version, $clinic, $target, $actor, date('Y-m-d H:i:s')]);
    }

    public function received(int $clinic, int $actor): array
    {
        $this->authorize($clinic, $actor);
        $st = $this->db->prepare("SELECT s.id, p.name, v.version FROM clinic_protocol_shares s JOIN clinical_protocol_versions v ON v.id = s.protocol_version_id JOIN clinical_protocols p ON p.id = v.protocol_id WHERE s.target_clinic_id = ? AND v.status = 'approved' ORDER BY s.id DESC");
        $st->execute([$clinic]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function importShared(int $clinic, int $actor, int $share): int
    {
        $this->authorize($clinic, $actor);
        $st = $this->db->prepare("SELECT v.*, s.source_clinic_id FROM clinic_protocol_shares s JOIN clinical_protocol_versions v ON v.id = s.protocol_version_id WHERE s.id = ? AND s.target_clinic_id = ? AND v.status = 'approved'");
        $st->execute([$share, $clinic]); $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) throw new DomainException('Modelo não disponível.');
        $this->assertSharingLicense((int)$r['source_clinic_id']);
        $def = json_decode($r['definition_json'], true, 32, JSON_THROW_ON_ERROR);
        if (ProtocolDefinition::hash($def) !== $r['content_sha256']) throw new DomainException('Integridade do modelo inválida.');
        $id = $this->save($clinic, $actor, null, $def, (string)$r['references_text']);
        $this->db->prepare("INSERT INTO clinical_protocol_events (protocol_version_id, action, actor_user_id, note, created_at) VALUES (?, 'forked', ?, ?, ?)")->execute([$id, $actor, 'modelo compartilhado: versão ' . $r['id'], date('Y-m-d H:i:s')]);
        return $id;
    }

    private function assertSharingLicense(int $clinic): void
    {
        $st = $this->db->prepare("SELECT p.features_json FROM clinic_subscriptions s JOIN saas_plans p ON p.id = s.plan_id WHERE s.clinic_id = ? AND s.status = 'active'");
        $st->execute([$clinic]);
        $features = json_decode((string)$st->fetchColumn(), true) ?: [];
        if (($features['clinical_protocol_sharing'] ?? false) !== true) throw new DomainException('O plano ativo da clínica não licencia compartilhamento de protocolos.');
    }

    /** Same allowlist feeds preview, persistence and clinical review; no executable extensions. */
    public static function definition(array $input): array
    {
        if (strlen(json_encode($input, JSON_THROW_ON_ERROR)) > 120000 || count($input['questions'] ?? []) > 80) throw new DomainException('Use até 80 perguntas e 120 KB por protocolo.');
        foreach (['scope', 'editor'] as $k) {
            if (!is_string($input[$k] ?? null) || trim($input[$k]) === '') throw new DomainException('Defina escopo e responsável editorial.');
        }
        if (!is_string($input['title'] ?? null) || mb_strlen($input['title']) > 160) throw new DomainException('Título deve ter até 160 caracteres.');
        $top = ['title','summary','scope','editor','sections','suggestion_terms','questions','dietary_condition','dietary_suggestions'];
        if (array_diff(array_keys($input), $top)) throw new DomainException('Propriedade de protocolo não permitida.');
        if (!is_array($input['questions'] ?? null)) throw new DomainException('Inclua perguntas válidas.');
        foreach ($input['questions'] as $q) {
            if (!is_array($q) || array_diff(array_keys($q), ['key','shared_key','section','text','help','type','options','min','max','unit','required','audience','condition'])) throw new DomainException('Propriedade de pergunta não permitida.');
        }
        foreach ($input['questions'] as $q) {
            foreach (['key','text','type','help','section','shared_key','audience'] as $k) if (isset($q[$k]) && !is_string($q[$k])) throw new DomainException('Campo de pergunta deve ser texto.');
            foreach ($q['options'] ?? [] as $k => $label) if (!preg_match('/^[a-z0-9_]{1,60}$/', (string)$k) || !is_string($label)) throw new DomainException('Use valores de opção com letras minúsculas, números e sublinhado.');
        }
        $walk = function ($v) use (&$walk): void {
            if (is_array($v)) { foreach ($v as $x) $walk($x); return; }
            if (is_string($v) && (preg_match('/<[^>]*>|javascript\s*:|data\s*:\s*text\/html|<\?/iu', $v) || mb_strlen($v) > 4000)) throw new DomainException('Use somente texto simples, sem HTML ou código.');
        };
        $walk($input);
        return ProtocolDefinition::validate($input);
    }
}
