<?php
declare(strict_types=1);
namespace ClyoNutri\Application\View;
use ClyoNutri\Domain\Clinical\WorkflowState;

final class OperationalUI
{
    public static function escape(mixed $v): string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
    public static function state(string $key,?string $savedAt=null,?string $resume=null): string
    {
        $e=[self::class,'escape'];$out='<div class="op-state" data-workflow-state data-state="'.$e($key).'" role="status" aria-live="polite" aria-atomic="true"><strong data-state-label>'.$e(WorkflowState::label($key)).'</strong><span data-state-time>'.($savedAt?' · '.$e(\ClyoNutri\Core\I18n\LocalTime::local($savedAt)):'').'</span>';
        if($resume)$out.=' <a href="'.$e($resume).'" data-resume'.($key==='not_started'?' hidden':'').'>Retomar o salvo</a>';
        return $out.'</div>';
    }
    /** NI2-687: além da cor, cada gravidade tem ícone de forma própria e um rótulo lido pelo leitor de tela. */
    public const ALERT_LABELS=['info'=>'Informação','success'=>'Concluído','warning'=>'Atenção','error'=>'Erro','critical'=>'Alerta clínico'];
    public static function alert(string $title,string $message,string $severity='info'): string
    {
        $severity=isset(self::ALERT_LABELS[$severity])?$severity:'info';$role=in_array($severity,['critical','error'],true)?'alert':'status';
        return '<div class="op-alert op-alert--'.$severity.'" role="'.$role.'" data-severity="'.$severity.'"><span class="op-alert__icon" aria-hidden="true"></span><div><strong><span class="sr-only">'.self::ALERT_LABELS[$severity].': </span>'.self::escape($title).'</strong><p>'.self::escape($message).'</p></div></div>';
    }
    public static function button(string $label,string $variant='secondary',string $type='button'): string
    {
        $variant=in_array($variant,['primary','secondary','destructive'],true)?$variant:'secondary';$type=in_array($type,['button','submit'],true)?$type:'button';
        return '<button type="'.$type.'" class="op-button op-button--'.$variant.'">'.self::escape($label).'</button>';
    }
    public static function formControl(string $id,string $label,string $value='',?string $error=null): string
    {
        $id=preg_replace('/[^a-zA-Z0-9_-]/','',$id)?:'op-field';
        return '<label for="'.$id.'">'.self::escape($label).'</label><input class="form-control" id="'.$id.'" value="'.self::escape($value).'"'.($error?' aria-invalid="true" aria-describedby="'.$id.'-error"':'').'>'.($error?'<p class="op-error" id="'.$id.'-error">'.self::escape($error).'</p>':'');
    }
    public static function progress(string $id,string $label,int $value,int $max): string
    {
        $id=preg_replace('/[^a-zA-Z0-9_-]/','',$id)?:'op-progress';$max=max(1,$max);$value=max(0,min($value,$max));
        return '<label for="'.$id.'">'.self::escape($label).'</label><progress class="op-progress" id="'.$id.'" max="'.$max.'" value="'.$value.'">'.$value.' de '.$max.'</progress>';
    }
    public static function menu(string $label,array $items): string
    {
        $html='<details data-op-menu><summary class="op-button op-button--secondary">'.self::escape($label).'</summary><div class="op-menu">';
        foreach($items as $item)if(is_string($item['url']??null)&&preg_match('#^/(?!/)#',$item['url']))$html.='<a href="'.self::escape($item['url']).'">'.self::escape($item['label']??'').'</a>';
        return $html.'</div></details>';
    }
    public static function card(string $title,string $text): string
    {
        return '<section class="card"><h2>'.self::escape($title).'</h2><p>'.self::escape($text).'</p></section>';
    }
    public static function table(string $caption,array $columns,array $rows): string
    {
        $out='<div class="table-responsive" tabindex="0" role="region" aria-label="'.self::escape($caption).'"><table class="table"><caption>'.self::escape($caption).'</caption><thead><tr>';
        foreach($columns as $c)$out.='<th scope="col">'.self::escape($c).'</th>';
        $out.='</tr></thead><tbody>';foreach($rows as $row){$out.='<tr>';foreach($row as $v)$out.='<td>'.self::escape($v).'</td>';$out.='</tr>';}
        return $out.'</tbody></table></div>';
    }
    public static function modal(string $id,string $title,string $text): string
    {
        $id='modal-'.(preg_replace('/[^a-zA-Z0-9_-]/','',$id)?:'op-dialog');
        return '<div id="'.$id.'" data-dialog="modal" class="op-dialog-overlay"><div class="cn-dialog__panel op-dialog-panel"><h2>'.self::escape($title).'</h2><p>'.self::escape($text).'</p><button class="op-button op-button--secondary" data-dialog-close>Fechar</button></div></div>';
    }
}
