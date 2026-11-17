<?php
/**
 * v54.76 — NI2-882 / CR875-08: minuta da transcrição só com o que foi dito, no tópico certo, por locutor.
 * Gerador real (NutritionalSummaryGenerator) + ingestão real (RealtimeTranscriptionService) em SQLite isolado,
 * com aceite real do paciente (NI2-830). Dados só de TESTE.
 */
declare(strict_types=1);

putenv('V54_DB=' . dirname(__DIR__) . '/storage/testing-ni2-882.sqlite');
require __DIR__ . '/_v54_bootstrap.php';

use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Domain\Clinical\RealtimeTranscription\NutritionalSummaryGenerator;
use ClyoNutri\Domain\Clinical\RealtimeTranscription\RealtimeTranscriptionService;
use ClyoNutri\Domain\Clinical\RealtimeTranscription\TranscriptionConsentService;
use ClyoNutri\Domain\Clinical\RealtimeTranscription\TranscriptionConsentTerms;

$NM = NutritionalSummaryGenerator::NOT_MENTIONED;
$gen = new NutritionalSummaryGenerator();
$TOPICS = ['chief_complaint', 'nutritional_goals', 'dietary_routine', 'reported_anthropometry', 'clinical_symptoms', 'adherence_notes', 'agreed_conduct', 'next_appointment_notes', 'other_notes'];
$seg = static fn(int $i, string $sp, string $t): array => ['id' => 100 + $i, 'sequence_number' => $i, 'speaker' => $sp, 'text' => $t];

/** Propriedade: toda frase de todo tópico existe literalmente em algum trecho e tem origem registrada. */
$assertTraceable = static function (array $draft, array $segments, string $label) use ($TOPICS, $NM): void {
    $all = implode("\n", array_map(static fn($s) => (string)$s['text'], $segments));
    $orphans = [];
    foreach ($TOPICS as $t) {
        $src = $draft['segment_sources'][$t] ?? [];
        if ($draft[$t] === $NM) { T::ok($src === [], "{$label}: {$t} sem fala não tem origem"); continue; }
        foreach ($src as $s) {
            if (!str_contains($all, (string)$s['text'])) { $orphans[] = $s['text']; }
        }
        $joined = implode(' ', array_map(static fn($s) => (string)$s['text'], $src));
        if ($joined !== $draft[$t]) { $orphans[] = "[{$t}] texto do tópico difere das origens"; }
    }
    T::ok($orphans === [], "{$label}: zero frases sem origem" . ($orphans ? ' → ' . implode(' | ', $orphans) : ''));
};

fwrite(STDOUT, "=== NI2-882 transcrição por locutor ===\n");

// Critério 1: as 3 falas de ia_draft.json
$ia = [$seg(1, 'professional', 'Você tem sentido dor de cabeça?'), $seg(2, 'patient', 'Tenho sentido dor de cabeça no fim da tarde.'), $seg(3, 'professional', 'Vamos ajustar o fracionamento e beber mais água.')];
$d = $gen->generateDraftFromSegments($ia);
T::ok(!str_contains($d['agreed_conduct'], '?') && !str_contains($d['agreed_conduct'], 'Você tem sentido'), 'ia_draft: a pergunta não aparece em Conduta');
T::ok(str_contains($d['agreed_conduct'], 'Vamos ajustar o fracionamento'), 'ia_draft: a conduta aparece em Conduta');
T::ok(str_contains($d['clinical_symptoms'] . $d['other_notes'], 'dor de cabeça no fim da tarde'), 'ia_draft: dor de cabeça em Sintomas ou Outros relatos');
T::ok($d['nutritional_goals'] === $NM, 'ia_draft: Objetivos = "Não mencionado na consulta"');
$assertTraceable($d, $ia, 'ia_draft');

// CR875-08: entrada no formato que o navegador enviava (locutor fixo "speaker_uncertain" + marcador técnico)
$cr = [$seg(1, 'speaker_uncertain', 'Você tem sentido azia depois do almoço? Não, nunca tive azia.'), $seg(2, 'speaker_uncertain', 'Vamos reduzir o açúcar e retornar em 30 dias.'), $seg(4, 'speaker_uncertain', '[Segmento de áudio captado #4]')];
$d = $gen->generateDraftFromSegments($cr);
T::ok($d['clinical_symptoms'] === $NM, 'CR875-08: pergunta/fala sem locutor não vira "Sintomas" do paciente');
T::ok(!str_contains($d['full_evolution_text'], 'Segmento de áudio captado'), 'CR875-08: marcador técnico não entra na minuta');
T::ok(!str_contains($d['full_evolution_text'], 'Você tem sentido azia'), 'CR875-08: pergunta sem locutor fica fora da minuta');
T::ok($d['agreed_conduct'] === $NM && $d['next_appointment_notes'] === $NM, 'CR875-08: fala sem locutor não é atribuída ao profissional');
T::ok(str_contains($d['other_notes'], 'Não, nunca tive azia.') && str_contains($d['other_notes'], 'Vamos reduzir o açúcar'), 'CR875-08: fala sem locutor vai para Outros relatos (nunca é descartada)');
$assertTraceable($d, $cr, 'CR875-08');

// Mesmas falas com locutor marcado na sala
$crs = [$seg(1, 'professional', 'Você tem sentido azia depois do almoço?'), $seg(2, 'patient', 'Não, nunca tive azia.'), $seg(3, 'professional', 'Vamos reduzir o açúcar e retornar em 30 dias.')];
$d = $gen->generateDraftFromSegments($crs);
T::ok($d['clinical_symptoms'] === 'Não, nunca tive azia.', 'com locutor: a resposta do paciente vai literal para Sintomas, sem a pergunta');
T::ok(str_contains($d['agreed_conduct'], 'reduzir o açúcar') && str_contains($d['next_appointment_notes'], 'retornar em 30 dias'), 'com locutor: "reduzir o açúcar e retornar" vai a Conduta e a Retorno');
$assertTraceable($d, $crs, 'CR875-08 com locutor');

// Critério 3: sessão sem nenhuma fala
foreach ([[], [$seg(1, 'patient', '   ')], [$seg(1, 'speaker_uncertain', '[Segmento de áudio captado #1]')], [$seg(1, 'professional', 'Tudo bem com você?')]] as $k => $empty) {
    $d = $gen->generateDraftFromSegments($empty);
    $ok = true;
    foreach ($TOPICS as $t) { $ok = $ok && $d[$t] === $NM; }
    T::ok($ok && !str_contains($d['full_evolution_text'], 'Outros Relatos'), "sessão sem fala de conteúdo #{$k}: minuta só com \"Não mencionado na consulta\"");
}

// Critério 5: ≥10 diálogos sintéticos (TESTE), tópico esperado + propriedade de origem
$dialogs = [
    ['Meu objetivo é ganhar massa muscular.', 'patient', 'nutritional_goals'],
    ['No café da manhã como pão com ovo.', 'patient', 'dietary_routine'],
    ['Estou pesando 74kg na balança de casa.', 'patient', 'reported_anthropometry'],
    ['Sinto cansaço e tontura de manhã.', 'patient', 'clinical_symptoms'],
    ['Ando com dores nas pernas e náusea.', 'patient', 'clinical_symptoms'],
    ['Tive dificuldade nos finais de semana por ansiedade.', 'patient', 'adherence_notes'],
    ['Marcaremos o retorno em 30 dias.', 'professional', 'next_appointment_notes'],
    ['Viajo a trabalho toda quinta-feira.', 'patient', 'other_notes'],
    ['Adorei a receita de panqueca.', 'patient', 'other_notes'],            // "dor" dentro de "adorei" não é sintoma
    ['A minha queixa principal é falta de disposição.', 'patient', 'chief_complaint'],
    ['Combinamos incluir duas frutas por dia.', 'professional', 'agreed_conduct'],
    ['Recomendo evitar refrigerante.', 'professional', 'agreed_conduct'],
];
foreach ($dialogs as $i => [$text, $sp, $topic]) {
    $segs = [$seg(1, 'professional', 'Como foi a sua semana?'), $seg(2, $sp, $text)];
    $d = $gen->generateDraftFromSegments($segs);
    T::ok($d[$topic] === $text, "diálogo " . ($i + 1) . ": \"{$text}\" ({$sp}) → {$topic}" . ($d[$topic] !== $text ? ' (obtido: ' . json_encode(array_filter(array_intersect_key($d, array_flip($TOPICS)), fn($v) => $v !== $NM), JSON_UNESCAPED_UNICODE) . ')' : ''));
    $assertTraceable($d, $segs, 'diálogo ' . ($i + 1));
}
T::ok(count($dialogs) >= 10, 'pelo menos 10 diálogos sintéticos');

// Ingestão real: aceite do paciente (NI2-830), validação do trecho, locutor normalizado
$db = Connection::getInstance();
$c = Fx::clinic($db, 'Clínica TESTE 882');
$u = Fx::user($db, $c, 'Nutri TESTE 882', 'owner');
$p = Fx::patient($db, $c, 'Paciente TESTE 882');
$ap = Fx::appointment($db, $c, $p, (int)$u['professional_id']);
Fx::act($u);
$consent = new TranscriptionConsentService($db);
$stt = new RealtimeTranscriptionService($db, $consent);
$req = $consent->createRequest($ap, $p, (int)$u['user_id']);
$consent->decideByToken((string)$req['token'], 'accept', TranscriptionConsentTerms::sha256(), '203.0.113.9', 'Mozilla/5.0 (TESTE)', (string)$req['otp']);
$sess = $stt->startSession($ap, $p, (int)$u['professional_id']);
$sid = (int)($sess['id'] ?? $sess['session']['id'] ?? 0);
T::ok($sid > 0, 'sessão iniciada com aceite real do paciente');
T::throws(fn() => $stt->ingestSegment($sid, 1, '[Segmento de áudio captado #1]', 'speaker_uncertain'), 'marcador técnico é recusado pelo servidor', 'sem fala');
T::throws(fn() => $stt->ingestSegment($sid, 2, '... ???', 'patient'), 'trecho sem letras é recusado');
T::throws(fn() => $stt->ingestSegment($sid, 3, str_repeat('a', RealtimeTranscriptionService::MAX_SEGMENT_CHARS + 1), 'patient'), 'trecho acima do limite é recusado', 'limite');
$r = $stt->ingestSegment($sid, 4, 'Tenho sentido azia depois do almoço.', 'patient');
T::ok($r['status'] === 'ingested' && $r['speaker'] === 'patient', 'fala do paciente gravada com locutor "patient"');
$r = $stt->ingestSegment($sid, 5, 'Vamos reduzir o café à noite.', 'nutricionista-hacker');
T::ok($r['speaker'] === 'speaker_uncertain', 'locutor fora da lista vira "speaker_uncertain"');
$stored = $stt->getSegments($sid, 0);
T::ok(count($stored) === 2 && !str_contains(json_encode($stored, JSON_UNESCAPED_UNICODE), 'Segmento de áudio'), 'banco só tem as 2 falas reconhecidas');
$d = $gen->generateDraftFromSegments($stored);
T::ok($d['clinical_symptoms'] === 'Tenho sentido azia depois do almoço.' && str_contains($d['other_notes'], 'café à noite'), 'minuta dos trechos gravados respeita o locutor');
$assertTraceable($d, $stored, 'trechos gravados');
T::ok((int)($d['segment_sources']['clinical_symptoms'][0]['id'] ?? 0) === (int)$stored[0]['id'], 'origem aponta para o id real do trecho no banco');

T::done();
