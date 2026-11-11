<?php
declare(strict_types=1);
namespace ClyoNutri\Domain\Dietary;

final class DietaryDefinition
{
    public const CONDITIONS = ['diabetes'=>'Diabetes','gastrite'=>'Gastrite','hipertensao'=>'Hipertensão','renal'=>'Doença renal','celiaca'=>'Doença celíaca'];

    public static function validate(array $definition): void
    {
        if (!array_key_exists('dietary_suggestions',$definition) && !array_key_exists('dietary_condition',$definition)) return;
        if (!isset(self::CONDITIONS[$definition['dietary_condition']??''])) throw new \InvalidArgumentException('Selecione uma comorbidade para as sugestões.');
        $rows=$definition['dietary_suggestions']??[];
        if (!is_array($rows)||!array_is_list($rows)||count($rows)>5) throw new \InvalidArgumentException('Use até cinco sugestões por versão.');
        $keys=[];
        foreach($rows as $row){
            if(!is_array($row)||array_diff(array_keys($row),['key','title','guidance','rationale','source_url','review_only','exclude_conditions','exclude_allergens']))throw new \InvalidArgumentException('Sugestão dietética inválida.');
            if(!is_string($row['key']??null)||!preg_match('/^[a-z][a-z0-9_]{1,59}$/D',$row['key'])||isset($keys[$row['key']]))throw new \InvalidArgumentException('Chave de sugestão inválida ou repetida.');
            $keys[$row['key']]=true;
            foreach(['title'=>160,'guidance'=>1500,'rationale'=>1000] as $field=>$max){
                $v=$row[$field]??null;
                if(!is_string($v)||trim($v)===''||mb_strlen($v)>$max||preg_match('/<[^>]*>|<\?|[\x00-\x08\x0B\x0C\x0E-\x1F]/u',$v))throw new \InvalidArgumentException('Preencha título, orientação e motivo em texto simples, dentro do limite.');
            }
            $url=$row['source_url']??null;
            if(!is_string($url)||strlen($url)>1000||!filter_var($url,FILTER_VALIDATE_URL)||parse_url($url,PHP_URL_SCHEME)!=='https'||parse_url($url,PHP_URL_USER)||parse_url($url,PHP_URL_PASS))throw new \InvalidArgumentException('Fonte precisa ser um endereço HTTPS válido.');
            if(!is_bool($row['review_only']??null))throw new \InvalidArgumentException('Informe se a orientação exige avaliação individual.');
            $allergens=$row['exclude_allergens']??[];
            if(!is_array($allergens)||!array_is_list($allergens)||count($allergens)>20)throw new \InvalidArgumentException('Exclusões por alergia inválidas.');
            foreach($allergens as $group)if(!is_string($group)||!in_array($group,\ClyoNutri\Domain\Clinical\AllergenSafetyValidator::groupKeys(),true))throw new \InvalidArgumentException('Grupo de alergia inválido.');
            $exclude=$row['exclude_conditions']??null;
            if(!is_array($exclude)||!array_is_list($exclude)||array_diff($exclude,array_keys(self::CONDITIONS)))throw new \InvalidArgumentException('Combinação de comorbidades inválida.');
        }
    }
}
