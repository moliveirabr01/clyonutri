<?php
declare(strict_types=1);
namespace ClyoNutri\Domain\Dietary;

/** Editorial proposals, NEVER approved automatically or installed into patient records. */
final class DietaryStarterCatalog
{
    public const VERSION='2026-11-04-r1';
    public static function all(): array
    {
        return [
            'diabetes'=>['title'=>'Planejamento individualizado de carboidratos',
                'guidance'=>'Organize as fontes e porções de carboidratos nas refeições conforme o planejamento combinado na consulta, considerando sua rotina e o tratamento em uso.',
                'rationale'=>'A quantidade e a distribuição precisam de avaliação individual; o sistema não define uma meta universal nem altera medicamentos.',
                'source_url'=>'https://www.niddk.nih.gov/health-information/diabetes/overview/healthy-living-with-diabetes','review_only'=>false,'exclude_conditions'=>['renal']],
            'gastrite'=>['title'=>'Alimentação conforme sintomas e tolerância',
                'guidance'=>'Registre a relação entre alimentação e sintomas para revisar na consulta. Evite exclusões amplas por conta própria e siga os ajustes individualizados combinados com o profissional.',
                'rationale'=>'Não há uma dieta única para todas as gastrites. A causa e a avaliação clínica orientam o cuidado; dieta não substitui tratamento.',
                'source_url'=>'https://www.niddk.nih.gov/health-information/digestive-diseases/gastritis-gastropathy','review_only'=>false,'exclude_conditions'=>['renal']],
            'hipertensao'=>['title'=>'Revisão das fontes de sódio',
                'guidance'=>'Compare os rótulos e priorize opções com menos sódio entre produtos semelhantes, conforme o planejamento individualizado discutido na consulta.',
                'rationale'=>'O planejamento para pressão arterial pode incluir redução de sódio; não há cálculo automático de meta, porções ou potássio.',
                'source_url'=>'https://www.nhlbi.nih.gov/health/dash-eating-plan','review_only'=>false,'exclude_conditions'=>['renal']],
            'celiaca'=>['title'=>'Revisão de glúten e contato cruzado',
                'guidance'=>'Conforme o diagnóstico confirmado e a orientação da consulta, revise rótulos e preparo para evitar glúten e contato cruzado, mantendo variedade e adequação nutricional.',
                'rationale'=>'Aplicável à condição documentada. Não iniciar exclusão por suspeita enquanto a investigação diagnóstica estiver em curso.',
                'source_url'=>'https://www.niddk.nih.gov/health-information/digestive-diseases/celiac-disease/eating-diet-nutrition','review_only'=>false,'exclude_conditions'=>['renal']],
            'renal'=>['title'=>'Avaliação renal individual obrigatória',
                'guidance'=>'Revisar estágio da doença, exames, tratamento, estado nutricional e orientação da equipe antes de definir ajustes de proteína, potássio, fósforo, sódio ou líquidos.',
                'rationale'=>'Necessidades variam com estágio e diálise. Esta proposta é somente um lembrete clínico e não pode ser incorporada como orientação automática ao paciente.',
                'source_url'=>'https://www.niddk.nih.gov/health-information/kidney-disease/chronic-kidney-disease-ckd/healthy-eating-adults-chronic-kidney-disease','review_only'=>true,'exclude_conditions'=>[]],
        ];
    }

    public static function definition(string $condition): array
    {
        $rule=self::all()[$condition]??throw new \InvalidArgumentException('Comorbidade não disponível.');
        return ['title'=>'Proposta dietética — '.DietaryDefinition::CONDITIONS[$condition],
            'summary'=>'Proposta editorial '.self::VERSION.'. Requer revisão clínica independente antes de habilitar.',
            'scope'=>'Apoio qualitativo para adultos. Não calcula dieta, metas, doses ou substituições automáticas. Avaliar particularidades e comorbidades.',
            'editor'=>'Proposta de referência Clyo Nutri; responsável clínico a definir na revisão.',
            'sections'=>[],'suggestion_terms'=>[],
            'questions'=>[['key'=>'contexto_clinico','text'=>'Registre os dados clínicos e as limitações relevantes para individualizar o planejamento.','type'=>'textarea','required'=>true,'audience'=>'professional']],
            'dietary_condition'=>$condition,'dietary_suggestions'=>[['key'=>'orientacao_1']+$rule]];
    }
}
