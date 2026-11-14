<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Clinical\RealtimeTranscription;

/**
 * Texto versionado do aceite do paciente para a transcrição da consulta (NI2-830 / SEC-001).
 * O que o paciente lê é exatamente o que fica registrado: versão + SHA-256 do texto no momento do aceite.
 * Qualquer mudança de redação exige nova VERSION. A revisão jurídica/DPO da base legal e da redação fica pendente.
 */
final class TranscriptionConsentTerms
{
    public const VERSION = 'v2.0-2026-11';
    /** Validade do link/pedido de aceite (uso único). */
    public const REQUEST_TTL_SECONDS = 900;
    /** Validade do aceite depois de confirmado: só este atendimento, no máximo este tempo. */
    public const CONSENT_VALIDITY_SECONDS = 21600;

    /** @return array<string,string> título => texto */
    public static function sections(): array
    {
        return [
            'Finalidade' => 'Apoiar o seu nutricionista a registrar, em texto, o que for conversado nesta consulta e a montar um rascunho da evolução nutricional. '
                . 'O rascunho só entra no seu prontuário depois que o profissional o revisar e aprovar. Nenhuma conduta é decidida automaticamente.',
            'Dados captados' => 'A sua fala durante esta consulta, convertida em texto. O áudio não é gravado nem guardado pelo Clyo Nutri; só o texto transcrito é salvo. '
                . 'Em alguns navegadores o reconhecimento de fala é feito por um serviço do fabricante do navegador (por exemplo, o Google no Chrome); nesse caso o áudio passa por esse serviço para virar texto.',
            'Duração e retenção' => 'Este aceite vale apenas para esta consulta e por até 6 horas depois da sua confirmação. Não é reaproveitado em outras consultas nem para outros pacientes. '
                . 'O texto transcrito e a minuta ficam ligados a este atendimento, sob sigilo profissional, pelo prazo de guarda do prontuário.',
            'Seus direitos' => 'Você pode recusar sem nenhum prejuízo: a consulta acontece normalmente, sem transcrição. Você pode pedir acesso, correção ou informações sobre o uso desses dados '
                . 'ao seu nutricionista ou à clínica, nos termos da Lei Geral de Proteção de Dados (LGPD).',
            'Como revogar' => 'A qualquer momento, nesta mesma página logo depois de aceitar, no portal do paciente (em "Transcrição da consulta"), ou pedindo ao profissional. '
                . 'Ao revogar, a captação para na hora e nenhum novo trecho é gravado.',
        ];
    }

    public static function text(): string
    {
        $out = [];
        foreach (self::sections() as $title => $body) {
            $out[] = $title . ': ' . $body;
        }
        return implode("\n", $out);
    }

    public static function sha256(): string
    {
        return hash('sha256', self::VERSION . "\n" . self::text());
    }
}
