<?php
declare(strict_types=1);
namespace ClyoNutri\Application\Controllers;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\Csrf;
use ClyoNutri\Domain\ClinicalProtocol\ClinicProtocolEditor;
use ClyoNutri\Domain\ClinicalProtocol\ProtocolEngine;
use ClyoNutri\Domain\ClinicalProtocol\ProtocolWorkspace;
use PDO;

final class ClinicProtocolEditorController
{
    private PDO $db;
    public function __construct(?PDO $db = null) { $this->db = $db ?? Connection::getInstance(); }

    public function page(array $params = []): void
    {
        $this->handle(false);
    }

    public function mutate(array $params = []): void
    {
        $this->handle(true);
    }

    private function handle(bool $post): void
    {
        if (!(new ProtocolWorkspace($this->db))->globallyEnabled()) { http_response_code(404); echo 'Página não encontrada.'; return; }
        $clinic = (int)TenantContext::getClinicId(); $actor = (int)TenantContext::getUserId();
        $editor = new ClinicProtocolEditor($this->db);
        try { $editor->authorize($clinic, $actor); } catch (\DomainException $e) { http_response_code(403); echo $e->getMessage(); return; }
        $error = null; $notice = null; $preview = null; $definition = null;
        $version = max(0, (int)($post ? ($_POST['version_id'] ?? 0) : ($_GET['version_id'] ?? 0)));
        $references = '';
        try {
            if ($version) {
                $row = $editor->version($clinic, $actor, $version);
                $definition = json_decode($row['definition_json'], true, 32, JSON_THROW_ON_ERROR);
                $references = (string)$row['references_text'];
            }
            if ($post) {
                if (!Csrf::validate(is_string($_POST['_csrf_token'] ?? null) ? $_POST['_csrf_token'] : '')) throw new \DomainException('Formulário expirado. Recarregue a página.');
                $action = $_POST['action'] ?? '';
                if ($action === 'dietary_starter') {
                    $condition=is_string($_POST['condition']??null)?$_POST['condition']:'';
                    $definition=\ClyoNutri\Domain\Dietary\DietaryStarterCatalog::definition($condition);
                    $refs=$definition['dietary_suggestions'][0]['source_url'];
                    $version=$editor->save($clinic,$actor,null,$definition,'Proposta editorial '.\ClyoNutri\Domain\Dietary\DietaryStarterCatalog::VERSION.'; fonte consultada em 04/11/2026: '.$refs);
                    $references=$refs;
                    $notice='Proposta criada como rascunho. Revise o conteúdo e solicite aprovação clínica independente antes de habilitar.';
                } elseif (in_array($action, ['save','preview'], true)) {
                    $definition = self::fromForm($_POST);
                    $references = is_string($_POST['references'] ?? null) ? $_POST['references'] : '';
                    $validated = ClinicProtocolEditor::definition($definition);
                    $preview = ProtocolEngine::compose([['code'=>'preview','name'=>$validated['title'],'version'=>0,'version_id'=>0,'definition'=>$validated]], is_array($_POST['answers'] ?? null) ? $_POST['answers'] : []);
                    if ($action === 'save') {
                        $version = $editor->save($clinic, $actor, $version ?: null, $definition, $references, is_string($_POST['base_hash'] ?? null) ? $_POST['base_hash'] : null);
                        $notice = 'Rascunho salvo. A publicação exige revisão clínica por outra pessoa habilitada.';
                    }
                } elseif ($action === 'submit') {
                    $editor->submit($clinic, $actor, $version); $notice = 'Enviado para revisão clínica.';
                } elseif ($action === 'approve') {
                    $editor->approve($clinic, $actor, $version, is_string($_POST['review_due_at'] ?? null) ? $_POST['review_due_at'] : ''); $notice = 'Versão aprovada. A ativação na clínica é feita na lista de protocolos.';
                } elseif ($action === 'share') {
                    $editor->share($clinic, $actor, $version, (int)($_POST['target_clinic'] ?? 0)); $notice = 'Modelo compartilhado. As respostas de pacientes permanecem privadas.';
                } elseif ($action === 'import_shared') {
                    $version = $editor->importShared($clinic, $actor, (int)($_POST['share_id'] ?? 0)); $notice = 'Cópia privada criada como rascunho. Solicite revisão clínica local.';
                    $definition = json_decode($editor->version($clinic, $actor, $version)['definition_json'], true);
                } else throw new \DomainException('Ação inválida.');
            }
        } catch (\DomainException | \InvalidArgumentException | \JsonException $e) { $error = $e->getMessage(); }
        $received = $editor->received($clinic, $actor);
        $rows = $editor->list($clinic, $actor);
        $definition ??= ['title'=>'','summary'=>'','scope'=>'','editor'=>'','sections'=>[], 'questions'=>[]];
        $baseHash = '';
        if ($version) { try { $baseHash = ClinicProtocolEditor::revision($editor->version($clinic, $actor, $version)); } catch (\DomainException $e) { $version = 0; } }
        $csrfToken = Csrf::token();
        $title = 'Editor de protocolos'; $activeNav = 'configuracoes';
        $clinicName = $_SESSION['clinic_name'] ?? ''; $userName = $_SESSION['user_name'] ?? '';
        ob_start(); require __DIR__ . '/../../../views/settings/protocol_editor.php'; $content = ob_get_clean();
        require __DIR__ . '/../../../views/layouts/professional-shell.php';
    }

    private static function fromForm(array $data): array
    {
        $str = static fn($v) => is_string($v) ? trim($v) : '';
        $d = ['title'=>$str($data['title'] ?? ''), 'summary'=>$str($data['summary'] ?? ''), 'scope'=>$str($data['scope'] ?? ''), 'editor'=>$str($data['editor'] ?? ''), 'sections'=>[], 'questions'=>[]];
        foreach (array_slice(is_array($data['questions'] ?? null) ? $data['questions'] : [], 0, 81) as $row) {
            if (!is_array($row)) throw new \DomainException('Pergunta inválida.');
            $q = ['key'=>$str($row['key'] ?? ''), 'text'=>$str($row['text'] ?? ''), 'help'=>$str($row['help'] ?? ''), 'type'=>$str($row['type'] ?? ''), 'required'=>isset($row['required']), 'audience'=>($row['audience'] ?? '') === 'professional' ? 'professional' : 'both'];
            $group = $str($row['group'] ?? '');
            if ($group !== '') { $section = 'g_' . substr(hash('sha256', $group),0,12); $d['sections'][$section] = $group; $q['section'] = $section; }
            if (in_array($q['type'], ['single','multi','yesno'], true)) {
                $q['options'] = [];
                foreach (explode("\n", $str($row['options_text'] ?? '')) as $line) {
                    [$k,$label] = array_pad(explode('=', $line, 2), 2, '');
                    if (trim($k) !== '' && trim($label) !== '') $q['options'][trim($k)] = trim($label);
                }
            }
            foreach (['min','max'] as $k) if ($str($row[$k] ?? '') !== '') {
                if (!is_numeric($row[$k])) throw new \DomainException('Limite numérico inválido.');
                $q[$k] = (float)$row[$k];
            }
            if ($str($row['depends_on'] ?? '') !== '') $q['condition'] = ['question'=>$str($row['depends_on']), 'equals'=>$str($row['depends_value'] ?? '')];
            $d['questions'][] = $q;
        }
        $condition=$str($data['dietary_condition']??'');
        if($condition!==''){
            $d['dietary_condition']=$condition;$d['dietary_suggestions']=[];
            foreach(array_slice(is_array($data['dietary']??null)?$data['dietary']:[],0,6) as $i=>$row){
                if(!is_array($row))throw new \DomainException('Sugestão inválida.');
                if(trim((string)($row['title']??''))===''&&trim((string)($row['guidance']??''))==='')continue;
                $d['dietary_suggestions'][]=['key'=>$str($row['key']??'')?:'orientacao_'.($i+1),'title'=>$str($row['title']??''),
                    'guidance'=>$str($row['guidance']??''),'rationale'=>$str($row['rationale']??''),'source_url'=>$str($row['source_url']??''),
                    'review_only'=>isset($row['review_only']),'exclude_conditions'=>is_array($row['exclude_conditions']??null)?array_values($row['exclude_conditions']):[],
                    'exclude_allergens'=>is_array($row['exclude_allergens']??null)?array_values($row['exclude_allergens']):[]];
            }
        }
        return $d;
    }
}
