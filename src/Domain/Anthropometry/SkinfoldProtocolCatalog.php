<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Anthropometry;

/**
 * Catálogo versionado de protocolos de dobras cutâneas (NI2-439).
 *
 * Cada protocolo traz referência bibliográfica, população de origem, elegibilidade (sexo e faixa etária),
 * dobras exigidas, equação de densidade corporal e a conversão densidade → % de gordura.
 * Nenhuma equação é extrapolada: fora da faixa etária ou sem sexo definido, o cálculo é recusado.
 *
 * Coeficientes conferidos em 19/10/2026:
 * - Jackson & Pollock (1978), homens: artigo original (Br J Nutr 40:497-504, Tabela 4, eq. 1 e 5).
 *   Confrontado também com a tabela de % de gordura dos autores (3 dobras: diferença ≤ 0,05 p.p.).
 * - Jackson, Pollock & Ward (1980), mulheres: fontes secundárias coincidentes, incluindo artigo revisado
 *   por pares (Jagim et al., 2023). A tabela clínica de 1980 para mulheres diverge até 1,35 p.p. (3 dobras).
 * - Durnin & Womersley (1974): fontes secundárias coincidentes e tabela de % de gordura dos autores
 *   (30 a 72 anos: diferença ≤ 0,2 p.p.).
 * Conferência com os originais é item obrigatório da revisão clínica (docs/clinical/PROTOCOLOS_DOBRAS_CUTANEAS.md).
 *
 * Alterar qualquer coeficiente exige nova CATALOG_VERSION: registros antigos guardam a versão usada.
 */
final class SkinfoldProtocolCatalog
{
    public const CATALOG_VERSION = '2026.10-r1';

    /** Limites físicos de uma dobra (mm). Fora disso o valor é recusado (erro de digitação/plicômetro). */
    public const SITE_MIN_MM = 1.0;
    public const SITE_MAX_MM = 80.0;
    /** Faixa usual; fora dela o valor é aceito com alerta de medição improvável. */
    public const SITE_WARN_LOW_MM = 3.0;
    public const SITE_WARN_HIGH_MM = 50.0;
    /** Diferença máxima entre leituras repetidas da mesma dobra (erro técnico de medida, ISAK). */
    public const READING_TOLERANCE = 0.05;

    /**
     * Pontos anatômicos (unidade: mm; lado direito, padrão ACSM/ISAK).
     * Onde o protocolo descreve o ponto de outra forma, a descrição específica fica em `site_notes` do protocolo.
     */
    public const SITES = [
        'chest' => [
            'label' => 'Peitoral',
            'direction' => 'diagonal',
            'location' => 'Dobra diagonal entre a linha axilar anterior e o mamilo: na metade da distância (homens) ou a 1/3 a partir da axila (mulheres).',
        ],
        'midaxillary' => [
            'label' => 'Axilar média',
            'direction' => 'vertical',
            'location' => 'Dobra vertical na linha axilar média, na altura do processo xifoide do esterno.',
        ],
        'triceps' => [
            'label' => 'Tricipital',
            'direction' => 'vertical',
            'location' => 'Dobra vertical na face posterior do braço, no ponto médio entre o acrômio e o olécrano, braço relaxado ao lado do corpo.',
        ],
        'biceps' => [
            'label' => 'Bicipital',
            'direction' => 'vertical',
            'location' => 'Dobra vertical na face anterior do braço, sobre o ventre do bíceps, na mesma altura da tricipital.',
        ],
        'subscapular' => [
            'label' => 'Subescapular',
            'direction' => 'diagonal',
            'location' => 'Dobra diagonal (cerca de 45°) 1 a 2 cm abaixo do ângulo inferior da escápula.',
        ],
        'abdominal' => [
            'label' => 'Abdominal',
            'direction' => 'vertical',
            'location' => 'Dobra vertical cerca de 2 cm à direita da cicatriz umbilical.',
        ],
        'suprailiac' => [
            'label' => 'Suprailíaca',
            'direction' => 'diagonal',
            'location' => 'Dobra diagonal logo acima da crista ilíaca. O ponto exato depende do protocolo (ver observação do protocolo).',
        ],
        'thigh' => [
            'label' => 'Coxa',
            'direction' => 'vertical',
            'location' => 'Dobra vertical na face anterior da coxa, no ponto médio entre a prega inguinal e a borda superior da patela.',
        ],
    ];

    /**
     * Protocolos. `equation`:
     *  - quadratic: D = a − b·S + c·S² − d·idade
     *  - log_by_age: D = c − m·log10(S), com (c, m) pela faixa etária
     */
    public const PROTOCOLS = [
        'jp7_male' => [
            'name' => 'Jackson & Pollock — 7 dobras (homens)',
            'sex' => 'male',
            'age_min' => 18, 'age_max' => 61,
            'population' => 'Homens adultos de 18 a 61 anos, população geral não atlética (EUA), n = 308.',
            'sites' => ['chest', 'midaxillary', 'triceps', 'subscapular', 'abdominal', 'suprailiac', 'thigh'],
            'equation' => 'quadratic',
            'coefficients' => ['a' => 1.112, 'b' => 0.00043499, 'c' => 0.00000055, 'd' => 0.00028826],
            'equation_text' => 'D = 1,112 − 0,00043499·Σ7 + 0,00000055·Σ7² − 0,00028826·idade',
            'see_density' => 0.0078,
            'conversion' => 'siri',
            'reference' => 'Jackson AS, Pollock ML. Generalized equations for predicting body density of men. Br J Nutr. 1978;40(3):497-504. doi:10.1079/BJN19780152 (Tabela 4, equação 1).',
            'coefficients_source' => 'Artigo original',
            'site_notes' => 'Suprailíaca: dobra diagonal acima da crista ilíaca, na linha axilar anterior.',
        ],
        'jp3_male' => [
            'name' => 'Jackson & Pollock — 3 dobras (homens: peitoral, abdominal, coxa)',
            'sex' => 'male',
            'age_min' => 18, 'age_max' => 61,
            'population' => 'Homens adultos de 18 a 61 anos, população geral não atlética (EUA), n = 308.',
            'sites' => ['chest', 'abdominal', 'thigh'],
            'equation' => 'quadratic',
            'coefficients' => ['a' => 1.10938, 'b' => 0.0008267, 'c' => 0.0000016, 'd' => 0.0002574],
            'equation_text' => 'D = 1,10938 − 0,0008267·Σ3 + 0,0000016·Σ3² − 0,0002574·idade',
            'see_density' => 0.0077,
            'conversion' => 'siri',
            'reference' => 'Jackson AS, Pollock ML. Generalized equations for predicting body density of men. Br J Nutr. 1978;40(3):497-504. doi:10.1079/BJN19780152 (Tabela 4, equação 5).',
            'coefficients_source' => 'Artigo original; confrontado com a tabela de % de gordura dos autores (Pollock, Schmidt & Jackson, 1980): diferença máxima de 0,05 ponto percentual',
            'site_notes' => null,
        ],
        'jp7_female' => [
            'name' => 'Jackson, Pollock & Ward — 7 dobras (mulheres)',
            'sex' => 'female',
            'age_min' => 18, 'age_max' => 55,
            'population' => 'Mulheres adultas de 18 a 55 anos, população geral (EUA).',
            'sites' => ['chest', 'midaxillary', 'triceps', 'subscapular', 'abdominal', 'suprailiac', 'thigh'],
            'equation' => 'quadratic',
            'coefficients' => ['a' => 1.097, 'b' => 0.00046971, 'c' => 0.00000056, 'd' => 0.00012828],
            'equation_text' => 'D = 1,097 − 0,00046971·Σ7 + 0,00000056·Σ7² − 0,00012828·idade',
            'see_density' => null,
            'conversion' => 'siri',
            'reference' => 'Jackson AS, Pollock ML, Ward A. Generalized equations for predicting body density of women. Med Sci Sports Exerc. 1980;12(3):175-181. PMID 7402053.',
            'coefficients_source' => 'Fontes secundárias coincidentes, incluindo artigo revisado por pares (Jagim et al., Front Sports Act Living 2023;5:1240252); conferir com o original na revisão clínica',
            'site_notes' => 'Suprailíaca: dobra diagonal acima da crista ilíaca, na linha axilar anterior.',
        ],
        'jp3_female' => [
            'name' => 'Jackson, Pollock & Ward — 3 dobras (mulheres: tricipital, suprailíaca, coxa)',
            'sex' => 'female',
            'age_min' => 18, 'age_max' => 55,
            'population' => 'Mulheres adultas de 18 a 55 anos, população geral (EUA).',
            'sites' => ['triceps', 'suprailiac', 'thigh'],
            'equation' => 'quadratic',
            'coefficients' => ['a' => 1.0994921, 'b' => 0.0009929, 'c' => 0.0000023, 'd' => 0.0001392],
            'equation_text' => 'D = 1,0994921 − 0,0009929·Σ3 + 0,0000023·Σ3² − 0,0001392·idade',
            'see_density' => null,
            'conversion' => 'siri',
            'reference' => 'Jackson AS, Pollock ML, Ward A. Generalized equations for predicting body density of women. Med Sci Sports Exerc. 1980;12(3):175-181. PMID 7402053.',
            'coefficients_source' => 'Fontes secundárias coincidentes, incluindo artigo revisado por pares (Jagim et al., 2023). ATENÇÃO: a tabela clínica de Pollock, Schmidt & Jackson (1980) para mulheres diverge até 1,35 ponto percentual nos extremos (somas de 27 e 99 mm); conferir com o original na revisão clínica',
            'site_notes' => 'Suprailíaca: dobra diagonal acima da crista ilíaca, na linha axilar anterior.',
        ],
        'dw4' => [
            'name' => 'Durnin & Womersley — 4 dobras (bicipital, tricipital, subescapular, suprailíaca)',
            'sex' => 'any',
            'age_min' => 16, 'age_max' => 72,
            // Faixas do estudo: homens 17-72, mulheres 16-68 (tabela de % de gordura publicada pelos autores).
            'age_by_sex' => ['male' => [17, 72], 'female' => [16, 68]],
            'population' => 'Homens de 17 a 72 anos e mulheres de 16 a 68 anos (Escócia, n = 481). Coeficientes por sexo e faixa etária.',
            'sites' => ['biceps', 'triceps', 'subscapular', 'suprailiac'],
            'equation' => 'log_by_age',
            // [idade mínima, idade máxima, c, m]
            'coefficients' => [
                'male' => [[17, 19, 1.1620, 0.0630], [20, 29, 1.1631, 0.0632], [30, 39, 1.1422, 0.0544], [40, 49, 1.1620, 0.0700], [50, 72, 1.1715, 0.0779]],
                'female' => [[16, 19, 1.1549, 0.0678], [20, 29, 1.1599, 0.0717], [30, 39, 1.1423, 0.0632], [40, 49, 1.1333, 0.0612], [50, 68, 1.1339, 0.0645]],
            ],
            'equation_text' => 'D = c − m·log10(Σ4), com c e m conforme sexo e faixa etária',
            'see_density' => null,
            'conversion' => 'siri',
            'reference' => 'Durnin JVGA, Womersley J. Body fat assessed from total body density and its estimation from skinfold thickness: measurements on 481 men and women aged from 16 to 72 years. Br J Nutr. 1974;32(1):77-97. doi:10.1079/BJN19740060',
            'coefficients_source' => 'Duas fontes secundárias coincidentes e confrontados com a tabela de % de gordura dos autores (30 a 72 anos: diferença máxima de 0,2 ponto percentual); conferir com o original na revisão clínica',
            'site_notes' => 'Suprailíaca: logo acima da crista ilíaca, na linha axilar média (descrição do protocolo original difere da de Jackson & Pollock).',
        ],
    ];

    public const CONVERSIONS = [
        'siri' => [
            'name' => 'Siri (1961)',
            'equation_text' => '%G = (4,95 / D − 4,50) × 100',
            'reference' => 'Siri WE. Body composition from fluid spaces and density: analysis of methods. In: Brozek J, Henschel A, eds. Techniques for Measuring Body Composition. Washington: National Academy of Sciences; 1961:223-244.',
        ],
    ];

    public static function protocol(string $code): array
    {
        if (!isset(self::PROTOCOLS[$code])) {
            throw new InvalidAnthropometricDataException("Protocolo de dobras cutâneas desconhecido: {$code}.");
        }
        return ['code' => $code] + self::PROTOCOLS[$code];
    }

    /**
     * Protocolos elegíveis para o sexo usado na equação e a idade na data da avaliação.
     *
     * @return list<array>
     */
    public static function eligibleFor(?string $sex, ?int $age): array
    {
        $out = [];
        foreach (self::PROTOCOLS as $code => $p) {
            $reason = self::ineligibilityReason($code, $sex, $age);
            $out[] = ['code' => $code, 'name' => $p['name'], 'eligible' => $reason === null, 'reason' => $reason];
        }
        return $out;
    }

    /** Null se elegível; senão, o motivo em português. */
    public static function ineligibilityReason(string $code, ?string $sex, ?int $age): ?string
    {
        $p = self::protocol($code);
        if (!in_array($sex, ['male', 'female'], true)) {
            return 'Informe o sexo a ser usado na equação (as equações foram desenvolvidas separadamente para homens e mulheres).';
        }
        if ($p['sex'] !== 'any' && $p['sex'] !== $sex) {
            return $p['sex'] === 'male' ? 'Protocolo desenvolvido apenas para homens.' : 'Protocolo desenvolvido apenas para mulheres.';
        }
        if ($age === null) {
            return 'Informe a data de nascimento do paciente: a idade entra na equação.';
        }
        [$min, $max] = $p['age_by_sex'][$sex] ?? [$p['age_min'], $p['age_max']];
        if ($age < $min || $age > $max) {
            return "Fora da faixa etária validada ({$min} a {$max} anos). O sistema não extrapola a equação.";
        }
        return null;
    }
}
