<?php
declare(strict_types=1);
namespace ClyoNutri\Domain\Clinical;

final class WorkflowState
{
    public const LABELS=['draft'=>'Rascunho','archived'=>'Arquivado','not_started'=>'Não iniciado','editing'=>'Em edição — alterações não salvas','saving'=>'Salvando…','saved'=>'Salvo como rascunho','review'=>'Em revisão','completed'=>'Concluído','published'=>'Publicado','superseded'=>'Substituído','issued'=>'Emitido','signed'=>'Assinado verificavelmente','conflict'=>'Conflito de edição — nada foi sobrescrito','error'=>'Não foi possível confirmar o salvamento','expired'=>'Sessão expirada — salvamento não confirmado'];
    public static function label(string $state, bool $verifiedSignature=false): string
    {
        if($state==='signed'&&!$verifiedSignature)$state='issued';
        return self::LABELS[$state]??self::LABELS['not_started'];
    }
}
