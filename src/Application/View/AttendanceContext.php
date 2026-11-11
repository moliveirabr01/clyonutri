<?php
declare(strict_types=1);
namespace ClyoNutri\Application\View;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Domain\Clinical\ClinicalAuthor;
use PDO;

/** Request-local navigation context, never an implicit clinical record association. */
final class AttendanceContext
{
    private static ?array $selected = null;
    private static ?PDO $db = null;

    private static function patientForPath(PDO $db, string $path): ?int
    {
        if ($path==='/planos/novo') return is_string($_POST['patient_id']??null) && preg_match('/^[1-9][0-9]*$/D',$_POST['patient_id']) ? (int)$_POST['patient_id'] : null;
        if (preg_match('~^/api/workflow/exam/[0-9]+$~D',$path)) {
            $patient=$_POST['patient_id']??$_GET['patient_id']??null;
            return is_string($patient) && preg_match('/^[1-9][0-9]*$/D',$patient) ? (int)$patient : null;
        }
        if (preg_match('~^/api/workflow/anamnesis/([1-9][0-9]*)$~D',$path,$m)) return (int)$m[1];
        if (preg_match('~^/api/workflow/plan/([1-9][0-9]*)$~D',$path,$m)) $path='/planos/'.$m[1];
        if (preg_match('~^/pacientes/([1-9][0-9]*)(?:/(?:anamnese|antropometria|plano|prontuario|evolucao|exames|documentos|preparar|condicoes|protocolos|pre-consulta)(?:/[^?#]*)?)?$~D', $path, $m)) return (int)$m[1];
        if (preg_match('~^/planos/([1-9][0-9]*)(?:/[^?#]*)?$~D', $path, $m)) {
            $q=$db->prepare('SELECT patient_id FROM meal_plans WHERE id=? AND clinic_id=?');
            $q->execute([(int)$m[1],TenantContext::getClinicId()]);
            $id=$q->fetchColumn();return $id ? (int)$id : null;
        }
        return null;
    }

    /** Run after authentication/authorization and before any controller read or mutation. */
    public static function begin(PDO $db): void
    {
        self::$selected=null;self::$db=$db;
        $path=(string)parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH);
        // The consultation route itself is an explicit choice; its profile link must not lose that origin.
        if (preg_match('~^/consulta/([1-9][0-9]*)$~D',$path,$room)) {
            $q=$db->prepare('SELECT a.* FROM appointments a JOIN patients p ON p.id=a.patient_id AND p.clinic_id=a.clinic_id WHERE a.id=? AND a.clinic_id=?');
            $q->execute([(int)$room[1],TenantContext::getClinicId()]);$row=$q->fetch(PDO::FETCH_ASSOC);
            if ($row && ClinicalAuthor::resolve($db,(int)TenantContext::getClinicId(),(int)TenantContext::getUserId())!==null) self::$selected=$row;
            return; // The existing controller retains responsibility for missing/unauthorized room access.
        }
        $patient=self::patientForPath($db,$path);
        if ($patient===null || (!array_key_exists('atendimento',$_GET) && !array_key_exists('atendimento',$_POST))) return;
        $id=$_GET['atendimento']??$_POST['atendimento'];
        if (!is_string($id) || !preg_match('/^[1-9][0-9]{0,17}$/D',$id)
            || (isset($_GET['atendimento'],$_POST['atendimento']) && $_GET['atendimento']!==$_POST['atendimento'])) {
            throw new \DomainException('Contexto de atendimento inválido.');
        }
        $clinic=(int)TenantContext::getClinicId();
        if (ClinicalAuthor::resolve($db,$clinic,(int)TenantContext::getUserId())===null) throw new \DomainException('Atendimento não autorizado.');
        $q=$db->prepare('SELECT a.* FROM appointments a JOIN patients p ON p.id=a.patient_id AND p.clinic_id=a.clinic_id WHERE a.id=? AND a.clinic_id=? AND a.patient_id=?');
        $q->execute([(int)$id,$clinic,$patient]);
        $row=$q->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new \DomainException('Atendimento indisponível para este contexto.');
        self::$selected=$row;
    }

    /**
     * v54.57 (UX-101): depois de concluir um registro clínico com o atendimento selecionado (?atendimento=ID), vincula
     * as etapas correspondentes ao atendimento (CareProgress::confirmFromAttendance). Nunca derruba a gravação.
     * @param list<string> $steps
     * @return list<string>
     */
    public static function confirmSteps(int $patient, array $steps): array
    {
        $sel = self::selected($patient);
        if ($sel === null || !self::$db) return [];
        try {
            return (new \ClyoNutri\Domain\Appointment\CareProgress(self::$db))->confirmFromAttendance((int)TenantContext::getClinicId(), $patient, (int)$sel['id'], $steps);
        } catch (\Throwable $e) {
            error_log('[atendimento] vínculo automático da etapa não gravado: ' . get_class($e) . ' ' . $e->getMessage());
            return [];
        }
    }

    public static function selected(int $patient): ?array
    {
        return self::$selected && (int)self::$selected['patient_id']===$patient ? self::$selected : null;
    }

    /** Only same-patient clinical destinations inherit context. External/admin/other-patient URLs do not. */
    public static function url(string $url): string
    {
        if (!self::$selected || !self::$db) return $url;
        if (str_starts_with($url,'?')) $url=(string)parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH).$url;
        if (!str_starts_with($url,'/') || str_starts_with($url,'//')) return $url;
        $parts=parse_url($url);
        if (!$parts || isset($parts['host'])) return $url;
        $targetPatient=($parts['path']??'')==='/planos/novo' ? (int)self::$selected['patient_id'] : self::patientForPath(self::$db,$parts['path']??'');
        if ($targetPatient!==(int)self::$selected['patient_id']) return $url;
        parse_str($parts['query']??'',$query);
        // An explicit link to another consultation is a deliberate context switch, not overwritten.
        if (array_key_exists('atendimento',$query)) return $url;
        $query['atendimento']=(string)self::$selected['id'];
        return $parts['path'].'?'.http_build_query($query,'','&',PHP_QUERY_RFC3986).(isset($parts['fragment'])?'#'.$parts['fragment']:'');
    }

    public static function location(string $header): string
    {
        return str_starts_with($header,'Location: ') ? 'Location: '.self::url(substr($header,10)) : $header;
    }

    /** Decorate only HTML navigation tags in the professional page's content, never script/data strings. */
    public static function navigation(string $html): string
    {
        if (!self::$selected) return $html;
        $html=preg_replace_callback('~<(script|style)\b[^>]*>.*?</\1\s*>(*SKIP)(*F)|<(?:a|form)\b[^>]*>~is',static function(array $tag): string {
            $text=preg_replace_callback('~\b(href|action)="([^"]*)"~i',static function(array $a): string {
                $url=html_entity_decode($a[2],ENT_QUOTES|ENT_HTML5,'UTF-8');
                return $a[1].'="'.htmlspecialchars(self::url($url),ENT_QUOTES,'UTF-8').'"';
            },$tag[0]);
            // Browsers replace an action's query on GET submission: keep the selected context as a control.
            if (preg_match('~^<form\b~i',$text) && preg_match('~\baction="([^"]*)"~i',$text,$a)) {
                parse_str((string)parse_url(html_entity_decode($a[1],ENT_QUOTES|ENT_HTML5,'UTF-8'),PHP_URL_QUERY),$q);
                if (($q['atendimento']??null)===(string)self::$selected['id']) $text.='<input type="hidden" name="atendimento" value="'.(int)self::$selected['id'].'">';
            }
            return $text;
        },$html);
        if (!str_contains($html,'data-testid="attendance-hub"') && !preg_match('~^/consulta/[1-9][0-9]*$~D',(string)parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH))) {
            $html=self::contextBar(self::$selected).$html;
        }
        return $html;
    }

    /**
     * v54.57 (UX-119): rótulo da consulta selecionada com tipo, data e hora (fuso do consultório) e situação.
     */
    public static function label(array $a): string
    {
        $t=strtotime((string)($a['scheduled_at']??''));
        return 'Consulta #'.(int)$a['id'].' · '.\ClyoNutri\Core\I18n\LabelMap::appointmentType($a['type']??null)
            .($t?' · '.date('d/m/Y',$t).' às '.date('H:i',$t):'')
            .' · '.\ClyoNutri\Core\I18n\LabelMap::appointmentStatus($a['status']??null);
    }

    /**
     * v54.57 (UX-119 + UX-105): faixa única do atendimento nas telas do paciente: consulta com data/hora e as etapas
     * com o estado real (CareProgress), cada uma com link direto. Vai de uma etapa à outra sem voltar à sala.
     */
    public static function contextBar(array $a): string
    {
        $e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
        $id=(int)$a['id'];
        return '<div class="op-message op-message--info attendance-bar" data-testid="selected-attendance"><p class="attendance-bar__label"><strong>'.$e(self::label($a)).'</strong></p>'
            .self::stepsNav($a)
            .'<a data-testid="attendance-hub" class="attendance-bar__hub" href="/consulta/'.$id.'">Voltar ao atendimento #'.$id.'</a></div>';
    }

    /** Etapas do atendimento (estado real do CareProgress) com link direto; vazio se a consulta não estiver aberta. */
    public static function stepsNav(array $a): string
    {
        if (!self::$db || !in_array((string)($a['status']??''),['in_progress','scheduled','confirmed'],true)) return '';
        $e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
        try {
            $snap=(new \ClyoNutri\Domain\Appointment\CareProgress(self::$db))->snapshot((int)TenantContext::getClinicId(),(int)$a['patient_id'],(int)$a['id']);
        } catch (\Throwable $x) { return ''; }
        $current=(string)parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH);
        $chips='';
        foreach ($snap['steps'] as $st) {
            $mark=['done'=>'✓ ','in_progress'=>'… ','not_applicable'=>'— '][$st['state']]??'';
            $path=(string)parse_url((string)$st['url'],PHP_URL_PATH);
            $here=$current!=='' && $path===$current && $st['key']!=='revisao';
            $chips.='<li><a class="attendance-steps__step attendance-steps__step--'.$e($st['state']).'" href="'.$e($st['url']).'" data-step="'.$e($st['key']).'"'.($here?' aria-current="step"':'').'>'
                .$e($mark.$st['label']).'<span class="sr-only"> — '.$e(\ClyoNutri\Domain\Appointment\CareWorkflow::LABELS[$st['state']]??'').'</span></a></li>';
        }
        return $chips===''?'':'<nav class="attendance-steps" aria-label="Etapas deste atendimento" data-testid="attendance-steps"><ol>'.$chips.'</ol></nav>';
    }
}
