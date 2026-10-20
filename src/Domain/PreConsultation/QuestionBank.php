<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\PreConsultation;

/**
 * Banco versionado de perguntas da pré-consulta (NI2-437).
 *
 * - Cada pergunta tem id estável, seção, tipo, opções, obrigatoriedade padrão, condição de exibição
 *   e o campo da anamnese (NI2-6/NI2-220) para onde a resposta é levada na importação.
 * - `tags` ligam a pergunta a objetivos; 'base' vale para todos.
 * - As perguntas coletam relato do paciente. Nada aqui pontua, classifica ou sugere diagnóstico.
 * - Mudou texto, opções ou condição? Nova BANK_VERSION. Cada questionário guarda a cópia das perguntas
 *   que o paciente viu, então questionários antigos não mudam.
 */
final class QuestionBank
{
    public const BANK_VERSION = '2026.10-r1';

    public const CATEGORIES = [
        'clinical' => 'Clínico',
        'sports' => 'Esportivo',
        'behavioral' => 'Comportamental',
    ];

    /** Objetivos que o nutricionista marca a partir do que o paciente declarou. */
    public const OBJECTIVES = [
        'weight_loss' => ['label' => 'Emagrecimento', 'category' => 'clinical'],
        'glycemic_control' => ['label' => 'Controle glicêmico (diabetes, pré-diabetes, resistência à insulina)', 'category' => 'clinical'],
        'cardiovascular' => ['label' => 'Saúde cardiovascular (pressão, colesterol)', 'category' => 'clinical'],
        'gut_health' => ['label' => 'Saúde intestinal e digestiva', 'category' => 'clinical'],
        'pregnancy' => ['label' => 'Gestação ou amamentação', 'category' => 'clinical'],
        'muscle_gain' => ['label' => 'Ganho de massa muscular', 'category' => 'sports'],
        'performance' => ['label' => 'Desempenho esportivo', 'category' => 'sports'],
        'food_relationship' => ['label' => 'Relação com a comida', 'category' => 'behavioral'],
        'healthy_habits' => ['label' => 'Reeducação alimentar e hábitos', 'category' => 'behavioral'],
    ];

    public const SECTIONS = [
        'goals' => 'Seus objetivos',
        'health' => 'Saúde',
        'routine' => 'Rotina e hábitos',
        'preferences' => 'Preferências alimentares',
        'glycemic' => 'Glicemia',
        'cardio' => 'Pressão e colesterol',
        'gut' => 'Digestão e intestino',
        'pregnancy' => 'Gestação e amamentação',
        'sports' => 'Treino e esporte',
        'behavior' => 'Relação com a comida',
    ];

    private const FREQ = ['nunca' => 'Nunca', 'raramente' => 'Raramente', 'as_vezes' => 'Às vezes', 'frequentemente' => 'Frequentemente', 'quase_sempre' => 'Quase sempre'];
    private const YESNO = ['sim' => 'Sim', 'nao' => 'Não'];

    /**
     * Perguntas. Tipos: text, textarea, number, single, multi, yesno.
     * `condition`: ['question' => id, 'equals' => valor] (single/yesno) ou ['question' => id, 'includes' => valor] (multi).
     * `maps_to`: campo da anamnese que recebe a resposta na importação.
     */
    public static function questions(): array
    {
        return [
            // ---------------- base
            'main_goal' => ['section' => 'goals', 'type' => 'textarea', 'tags' => ['base'], 'required' => true, 'maps_to' => 'objectives',
                'text' => 'Com suas palavras, o que você espera alcançar com o acompanhamento nutricional?'],
            'has_allergy' => ['section' => 'health', 'type' => 'yesno', 'options' => self::YESNO, 'tags' => ['base'], 'required' => true, 'maps_to' => 'allergies',
                'text' => 'Você tem alergia ou intolerância a algum alimento?'],
            'allergy_detail' => ['section' => 'health', 'type' => 'textarea', 'tags' => ['base'], 'required' => true, 'maps_to' => 'allergies',
                'condition' => ['question' => 'has_allergy', 'equals' => 'sim'],
                'text' => 'Quais alimentos e o que acontece quando você os consome?'],
            'health_conditions' => ['section' => 'health', 'type' => 'multi', 'tags' => ['base'], 'required' => true, 'maps_to' => 'clinical_history',
                'options' => ['diabetes' => 'Diabetes ou pré-diabetes', 'hipertensao' => 'Pressão alta', 'colesterol' => 'Colesterol ou triglicerídeos altos',
                    'tireoide' => 'Doença da tireoide', 'renal' => 'Doença nos rins', 'gastro' => 'Gastrite, refluxo ou problema intestinal',
                    'outra' => 'Outra', 'nenhuma' => 'Nenhuma que eu saiba'],
                'text' => 'Algum médico já disse que você tem alguma destas condições?'],
            'health_other' => ['section' => 'health', 'type' => 'text', 'tags' => ['base'], 'required' => true, 'maps_to' => 'clinical_history',
                'condition' => ['question' => 'health_conditions', 'includes' => 'outra'],
                'text' => 'Qual outra condição?'],
            'uses_medication' => ['section' => 'health', 'type' => 'yesno', 'options' => self::YESNO, 'tags' => ['base'], 'required' => true, 'maps_to' => 'clinical_history',
                'text' => 'Você usa algum medicamento ou suplemento com frequência?'],
            'medication_detail' => ['section' => 'health', 'type' => 'textarea', 'tags' => ['base'], 'required' => true, 'maps_to' => 'clinical_history',
                'condition' => ['question' => 'uses_medication', 'equals' => 'sim'],
                'text' => 'Quais, e em que dose, se souber?'],
            'meals_per_day' => ['section' => 'routine', 'type' => 'number', 'min' => 1, 'max' => 12, 'tags' => ['base'], 'required' => true, 'maps_to' => 'routine',
                'text' => 'Quantas refeições você costuma fazer por dia?'],
            'daily_routine' => ['section' => 'routine', 'type' => 'textarea', 'tags' => ['base'], 'required' => false, 'maps_to' => 'routine',
                'text' => 'Conte como é um dia comum: horário em que acorda, trabalha, come e dorme.'],
            'physical_activity' => ['section' => 'routine', 'type' => 'single', 'tags' => ['base'], 'required' => true, 'maps_to' => 'habits',
                'options' => ['nao' => 'Não pratico', '1_2' => '1 a 2 vezes por semana', '3_4' => '3 a 4 vezes por semana', '5_mais' => '5 ou mais vezes por semana'],
                'text' => 'Com que frequência você pratica atividade física?'],
            'water' => ['section' => 'routine', 'type' => 'single', 'tags' => ['base'], 'required' => false, 'maps_to' => 'habits',
                'options' => ['menos_1l' => 'Menos de 1 litro', '1_2l' => 'De 1 a 2 litros', 'mais_2l' => 'Mais de 2 litros', 'nao_sei' => 'Não sei'],
                'text' => 'Quanta água você bebe por dia, mais ou menos?'],
            'alcohol' => ['section' => 'routine', 'type' => 'single', 'tags' => ['base'], 'required' => false, 'maps_to' => 'habits',
                'options' => ['nao' => 'Não bebo', 'eventual' => 'Ocasionalmente', 'semanal' => 'Toda semana', 'diario' => 'Quase todos os dias'],
                'text' => 'Você consome bebida alcoólica?'],
            'food_restrictions' => ['section' => 'preferences', 'type' => 'multi', 'tags' => ['base'], 'required' => false, 'maps_to' => 'restrictions',
                'options' => ['vegetariano' => 'Vegetariano', 'vegano' => 'Vegano', 'sem_lactose' => 'Sem lactose', 'sem_gluten' => 'Sem glúten',
                    'religiosa' => 'Restrição religiosa ou cultural', 'outra' => 'Outra'],
                'text' => 'Você segue alguma restrição alimentar?'],
            'restriction_other' => ['section' => 'preferences', 'type' => 'text', 'tags' => ['base'], 'required' => true, 'maps_to' => 'restrictions',
                'condition' => ['question' => 'food_restrictions', 'includes' => 'outra'],
                'text' => 'Qual outra restrição?'],
            'likes_dislikes' => ['section' => 'preferences', 'type' => 'textarea', 'tags' => ['base'], 'required' => false, 'maps_to' => 'food_preferences',
                'text' => 'Alimentos de que você gosta muito e alimentos que não come de jeito nenhum.'],
            'who_cooks' => ['section' => 'preferences', 'type' => 'single', 'tags' => ['base'], 'required' => false, 'maps_to' => 'routine',
                'options' => ['eu' => 'Eu mesmo(a)', 'familia' => 'Outra pessoa da casa', 'fora' => 'Como fora na maioria das vezes', 'misto' => 'Varia'],
                'text' => 'Quem prepara a maior parte das suas refeições?'],

            // ---------------- emagrecimento
            'weight_history' => ['section' => 'goals', 'type' => 'textarea', 'tags' => ['weight_loss', 'food_relationship'], 'required' => false, 'maps_to' => 'clinical_history',
                'text' => 'Como seu peso mudou nos últimos anos? Houve períodos de grande ganho ou perda?'],
            'previous_diets' => ['section' => 'goals', 'type' => 'textarea', 'tags' => ['weight_loss', 'food_relationship', 'healthy_habits'], 'required' => false, 'maps_to' => 'habits',
                'text' => 'Você já fez dietas antes? Conte o que funcionou e o que não funcionou para você.'],

            // ---------------- glicemia
            'glucose_monitoring' => ['section' => 'glycemic', 'type' => 'single', 'tags' => ['glycemic_control'], 'required' => true, 'maps_to' => 'clinical_history',
                'options' => ['nao' => 'Não meço', 'eventual' => 'Às vezes', 'diario' => 'Todos os dias', 'sensor' => 'Uso sensor contínuo'],
                'text' => 'Você mede a glicemia (açúcar no sangue) em casa?'],
            'recent_exams' => ['section' => 'glycemic', 'type' => 'textarea', 'tags' => ['glycemic_control', 'cardiovascular'], 'required' => false, 'maps_to' => 'clinical_history',
                'text' => 'Se tiver exames de sangue recentes, informe a data e os valores que lembrar (ou traga para a consulta).'],
            'hypoglycemia' => ['section' => 'glycemic', 'type' => 'yesno', 'options' => self::YESNO, 'tags' => ['glycemic_control'], 'required' => true, 'maps_to' => 'symptoms',
                'text' => 'Você já teve tremores, suor frio ou tontura que melhoraram ao comer algo doce?'],

            // ---------------- cardiovascular
            'salt_use' => ['section' => 'cardio', 'type' => 'single', 'tags' => ['cardiovascular'], 'required' => true, 'maps_to' => 'habits',
                'options' => ['pouco' => 'Uso pouco sal', 'moderado' => 'Uso moderado', 'muito' => 'Gosto de comida bem salgada', 'nao_sei' => 'Não sei dizer'],
                'text' => 'Como é o seu uso de sal e de temperos prontos?'],
            'processed_food' => ['section' => 'cardio', 'type' => 'single', 'tags' => ['cardiovascular', 'weight_loss', 'healthy_habits'], 'required' => false, 'maps_to' => 'habits',
                'options' => self::FREQ,
                'text' => 'Com que frequência você come embutidos, frituras ou alimentos ultraprocessados?'],

            // ---------------- intestino
            'bowel_frequency' => ['section' => 'gut', 'type' => 'single', 'tags' => ['gut_health'], 'required' => true, 'maps_to' => 'symptoms',
                'options' => ['diario' => 'Todos os dias', 'dias_alternados' => 'Dia sim, dia não', 'menos_3' => 'Menos de 3 vezes por semana', 'varia' => 'Varia muito'],
                'text' => 'Com que frequência seu intestino funciona?'],
            'gut_symptoms' => ['section' => 'gut', 'type' => 'multi', 'tags' => ['gut_health'], 'required' => false, 'maps_to' => 'symptoms',
                'options' => ['inchaco' => 'Inchaço', 'gases' => 'Gases', 'azia' => 'Azia ou queimação', 'constipacao' => 'Intestino preso', 'diarreia' => 'Diarreia', 'dor' => 'Dor abdominal'],
                'text' => 'Você sente algum destes sintomas com frequência?'],
            'symptom_triggers' => ['section' => 'gut', 'type' => 'textarea', 'tags' => ['gut_health'], 'required' => false, 'maps_to' => 'symptoms',
                'text' => 'Percebe algum alimento que piora esses sintomas?'],

            // ---------------- gestação
            'pregnancy_stage' => ['section' => 'pregnancy', 'type' => 'single', 'tags' => ['pregnancy'], 'required' => true, 'maps_to' => 'clinical_history',
                'options' => ['gestante' => 'Estou gestante', 'amamentando' => 'Estou amamentando', 'planejando' => 'Estou planejando engravidar'],
                'text' => 'Qual é a sua situação?'],
            'pregnancy_weeks' => ['section' => 'pregnancy', 'type' => 'number', 'min' => 1, 'max' => 42, 'tags' => ['pregnancy'], 'required' => true, 'maps_to' => 'clinical_history',
                'condition' => ['question' => 'pregnancy_stage', 'equals' => 'gestante'],
                'text' => 'Com quantas semanas de gestação você está?'],
            'pregnancy_symptoms' => ['section' => 'pregnancy', 'type' => 'multi', 'tags' => ['pregnancy'], 'required' => false, 'maps_to' => 'symptoms',
                'options' => ['enjoo' => 'Enjoo', 'azia' => 'Azia', 'constipacao' => 'Intestino preso', 'desejos' => 'Desejos ou aversões fortes'],
                'text' => 'Algum destes sintomas tem atrapalhado sua alimentação?'],

            // ---------------- esporte
            'sport_modality' => ['section' => 'sports', 'type' => 'text', 'tags' => ['performance', 'muscle_gain'], 'required' => true, 'maps_to' => 'habits',
                'text' => 'Qual esporte ou tipo de treino você pratica?'],
            'training_frequency' => ['section' => 'sports', 'type' => 'single', 'tags' => ['performance', 'muscle_gain'], 'required' => true, 'maps_to' => 'habits',
                'options' => ['1_2' => '1 a 2 vezes por semana', '3_4' => '3 a 4 vezes por semana', '5_6' => '5 a 6 vezes por semana', 'diario' => 'Todos os dias ou mais de uma vez por dia'],
                'text' => 'Quantas vezes por semana você treina?'],
            'training_time' => ['section' => 'sports', 'type' => 'single', 'tags' => ['performance', 'muscle_gain'], 'required' => false, 'maps_to' => 'routine',
                'options' => ['manha' => 'Manhã', 'tarde' => 'Tarde', 'noite' => 'Noite', 'varia' => 'Varia'],
                'text' => 'Em que período você costuma treinar?'],
            'training_duration' => ['section' => 'sports', 'type' => 'number', 'min' => 10, 'max' => 600, 'tags' => ['performance', 'muscle_gain'], 'required' => false, 'maps_to' => 'habits',
                'text' => 'Quanto tempo dura um treino típico (em minutos)?'],
            'competition' => ['section' => 'sports', 'type' => 'text', 'tags' => ['performance'], 'required' => false, 'maps_to' => 'objectives',
                'text' => 'Tem alguma prova ou competição marcada? Qual e quando?'],
            'peri_workout' => ['section' => 'sports', 'type' => 'textarea', 'tags' => ['performance', 'muscle_gain'], 'required' => false, 'maps_to' => 'routine',
                'text' => 'O que você costuma comer antes e depois de treinar?'],
            'sport_supplements' => ['section' => 'sports', 'type' => 'textarea', 'tags' => ['performance', 'muscle_gain'], 'required' => false, 'maps_to' => 'clinical_history',
                'text' => 'Usa suplementos para o treino? Quais?'],

            // ---------------- comportamental
            'eat_without_hunger' => ['section' => 'behavior', 'type' => 'single', 'options' => self::FREQ, 'tags' => ['food_relationship', 'weight_loss'], 'required' => true, 'maps_to' => 'habits',
                'text' => 'Com que frequência você come mesmo sem estar com fome?'],
            'emotional_eating' => ['section' => 'behavior', 'type' => 'single', 'options' => self::FREQ, 'tags' => ['food_relationship'], 'required' => true, 'maps_to' => 'habits',
                'text' => 'Com que frequência suas emoções (ansiedade, tristeza, tédio) mudam o que ou quanto você come?'],
            'loss_of_control' => ['section' => 'behavior', 'type' => 'single', 'options' => self::FREQ, 'tags' => ['food_relationship'], 'required' => false, 'maps_to' => 'habits',
                'text' => 'Com que frequência você sente que não consegue parar de comer depois que começa?'],
            'meal_attention' => ['section' => 'behavior', 'type' => 'single', 'tags' => ['food_relationship', 'healthy_habits'], 'required' => false, 'maps_to' => 'habits',
                'options' => ['sim' => 'Sim, quase sempre', 'as_vezes' => 'Às vezes', 'nao' => 'Não, costumo comer com atenção'],
                'text' => 'Você costuma comer olhando o celular, a TV ou trabalhando?'],
            'food_feelings' => ['section' => 'behavior', 'type' => 'textarea', 'tags' => ['food_relationship'], 'required' => false, 'maps_to' => 'notes',
                'text' => 'Se quiser, conte como você se sente em relação à comida e ao seu corpo. Responda só o que se sentir à vontade.'],
            'habit_to_change' => ['section' => 'behavior', 'type' => 'textarea', 'tags' => ['healthy_habits', 'weight_loss'], 'required' => false, 'maps_to' => 'objectives',
                'text' => 'Que hábito você gostaria de mudar primeiro?'],
        ];
    }

    public static function question(string $id): array
    {
        $all = self::questions();
        if (!isset($all[$id])) {
            throw new \InvalidArgumentException("Pergunta desconhecida: {$id}.");
        }
        return ['id' => $id] + $all[$id];
    }

    /** Ids de perguntas cuja condição depende de outra pergunta (a dependência entra junto). */
    public static function dependencyOf(string $id): ?string
    {
        return self::questions()[$id]['condition']['question'] ?? null;
    }
}
