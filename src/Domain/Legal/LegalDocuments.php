<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Legal;

/**
 * NI2-449: versão vigente dos Termos de Uso e da Política de Privacidade.
 *
 * É a única fonte da versão: as páginas /termos e /privacidade, os checkboxes de aceite e o registro
 * dos aceites leem daqui. Ao publicar texto novo, suba a versão e a data aqui; quem aceitou a anterior
 * passa a ver a tela /aceite-termos na próxima entrada.
 *
 * Os textos continuam sendo rascunho para revisão de um advogado.
 */
final class LegalDocuments
{
    public const TERMS = 'terms';
    public const PRIVACY = 'privacy';

    public const TERMS_VERSION = '1.1.1';
    public const TERMS_DATE = '26 de Outubro de 2026';

    public const PRIVACY_VERSION = '1.2.0';
    public const PRIVACY_DATE = '25 de Outubro de 2026';

    /** Códigos usados em consent_terms.purpose_code / patient_consents.consent_type para o paciente. */
    public const PATIENT_PURPOSE = [
        self::TERMS => 'platform_terms',
        self::PRIVACY => 'platform_privacy',
    ];

    /** @return array<string,string> documento => versão vigente */
    public static function currentVersions(): array
    {
        return [self::TERMS => self::TERMS_VERSION, self::PRIVACY => self::PRIVACY_VERSION];
    }

    public static function currentVersion(string $document): string
    {
        return self::currentVersions()[$document] ?? throw new \InvalidArgumentException('Documento legal desconhecido.');
    }

    public static function title(string $document): string
    {
        return match ($document) {
            self::TERMS => 'Termos de Uso',
            self::PRIVACY => 'Política de Privacidade',
            default => throw new \InvalidArgumentException('Documento legal desconhecido.'),
        };
    }

    public static function url(string $document): string
    {
        return match ($document) {
            self::TERMS => '/termos',
            self::PRIVACY => '/privacidade',
            default => throw new \InvalidArgumentException('Documento legal desconhecido.'),
        };
    }

    public static function date(string $document): string
    {
        return match ($document) {
            self::TERMS => self::TERMS_DATE,
            self::PRIVACY => self::PRIVACY_DATE,
            default => throw new \InvalidArgumentException('Documento legal desconhecido.'),
        };
    }

    /** true quando $accepted cobre a versão vigente (igual ou mais nova, ex.: depois de um rollback do deploy). */
    public static function covers(string $accepted, string $current): bool
    {
        return version_compare($accepted, $current, '>=');
    }
}
