<?php
declare(strict_types=1);
namespace ClyoNutri\Application\View;
/** A single list drives discovery, area switching and the permission inventory. */
final class SettingsNavigation
{
    public static function areas(bool $manage,bool $protocols): array
    {
        $areas=[
            ['key'=>'perfil','label'=>'Consultório e perfil','hint'=>'Cadastro, CRN e identificação profissional','url'=>'/configuracoes?tab=perfil','tasks'=>[
                ['label'=>'Editar cadastro e CRN','url'=>'/configuracoes?tab=perfil','terms'=>'nome email celular especialidade consultorio registro conselho'],
                ['label'=>'Etapas por tipo de consulta','url'=>'/configuracoes/atendimento','terms'=>'atendimento primeira consulta retorno progresso','permission'=>'owner/admin para salvar'],
            ]],
            ['key'=>'agenda','label'=>'Agenda e disponibilidade','hint'=>'Horários, agendamento online e bloqueios','url'=>'/configuracoes?tab=agenda','tasks'=>[
                ['label'=>'Configurar dias e horários','url'=>'/configuracoes?tab=agenda&secao=horarios','terms'=>'disponibilidade jornada pausa atendimento duracao'],
                ['label'=>'Configurar link de agendamento','url'=>'/configuracoes?tab=agenda&secao=link','terms'=>'online publico apelido portal'],
                ['label'=>'Feriados e bloqueios','url'=>'/configuracoes?tab=agenda&secao=feriados','terms'=>'calendario excecoes fechar importar'],
            ]],
            ['key'=>'equipe','label'=>'Equipe','hint'=>$manage?'Profissionais, convites e permissões':'Consultar profissionais do consultório','url'=>'/configuracoes/equipe','tasks'=>[
                ['label'=>$manage?'Gerenciar equipe e convites':'Consultar equipe','url'=>'/configuracoes/equipe','terms'=>'profissional membro permissao convite acesso','permission'=>'leitura de membro; alterações owner/admin'],
            ]],
            ['key'=>'paciente-app','label'=>'Comunicação','hint'=>'Aplicativo do paciente, chat, avisos e lembretes','url'=>'/configuracoes?tab=paciente-app','tasks'=>[
                ['label'=>'Configurar seus avisos','url'=>'/configuracoes?tab=paciente-app&secao=notifications#task-notifications','terms'=>'email silencio notificacoes checkin mensagens'],
                ['label'=>'Lembrete de consulta','url'=>'/configuracoes?tab=paciente-app&secao=reminders#task-reminders','terms'=>'antecedencia horas aviso paciente'],
            ]],
            ['key'=>'conta','label'=>'Segurança','hint'=>'Conta, senha e privacidade','url'=>'/configuracoes?tab=conta','tasks'=>[
                ['label'=>'Alterar senha de acesso','url'=>'/configuracoes?tab=conta','terms'=>'seguranca conta credencial senha atual'],
            ]],
        ];
        if($manage){
            $areas[1]['tasks'][]=['label'=>'Regras de agendamento e lista de espera','url'=>'/configuracoes?tab=agenda&secao=regras','terms'=>'buffer intervalo encaixe espera','permission'=>'owner/admin'];
            array_unshift($areas[3]['tasks'],
                ['label'=>'Regras de consulta no aplicativo','url'=>'/configuracoes?tab=paciente-app&secao=patient_actions#task-patient_actions','terms'=>'confirmar cancelar endereco paciente app','permission'=>'owner/admin'],
                ['label'=>'Remarcação imediata','url'=>'/configuracoes?tab=paciente-app&secao=self_service#task-self_service','terms'=>'remarcar portal autonomia paciente','permission'=>'owner/admin'],
                ['label'=>'Regras do chat','url'=>'/configuracoes?tab=paciente-app&secao=chat#task-chat','terms'=>'resposta mensagens retencao anos','permission'=>'owner/admin']);
            array_splice($areas,4,0,[['key'=>'assinatura','label'=>'Assinatura','hint'=>'Plano contratado e faturamento','url'=>'/configuracoes/assinatura','tasks'=>[
                ['label'=>'Consultar assinatura e faturamento','url'=>'/configuracoes/assinatura','terms'=>'cobranca plano contratado cancelar pagamento','permission'=>'SubscriptionService::canManageSubscription'],
            ]]]);
        }
        if($protocols)$areas[0]['tasks'][]=['label'=>'Protocolos clínicos','url'=>'/configuracoes/protocolos','terms'=>'anamnese adaptativa perguntas modelos editor','permission'=>'flag global; ACL do protocolo'];
        return $areas;
    }
    public static function current(): array
    {
        $db=\ClyoNutri\Core\Database\Connection::getInstance();
        $st=$db->prepare("SELECT role FROM clinic_members WHERE clinic_id=? AND user_id=? AND status='active'");
        $st->execute([\ClyoNutri\Core\Auth\TenantContext::getClinicId(),\ClyoNutri\Core\Auth\TenantContext::getUserId()]);
        $role=$st->fetchColumn();if(!$role)return [];
        $manage=in_array($role,['owner','admin'],true);
        $protocols=(new \ClyoNutri\Domain\ClinicalProtocol\ProtocolWorkspace($db))->globallyEnabled();
        return self::areas($manage,$protocols);
    }
}
