<?php
declare(strict_types=1);
namespace ClyoNutri\Domain\Prescription;
use Dompdf\Dompdf;
use Dompdf\Options;
final class PrescriptionDocument
{
    public static function html(array $s): string
    {
        $e=static fn($s)=>htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $p=$s['payload'];$rows='';
        foreach ($s['eligibility']['items'] as $i) $rows.='<tr><td>'.$e($i['name']).'</td><td>'.$e($i['dose']).' '.$e($i['unit']).' por administração</td></tr><tr><td colspan="2">'.$e($i['constraints']['mandatory_instructions']).'</td></tr>';
        return '<!doctype html><html lang="pt-BR"><meta charset="utf-8"><style>@page{margin:18mm}body{font-family:DejaVu Sans,sans-serif;font-size:11pt;color:#163d24}h1{font-size:20pt}table{width:100%;border-collapse:collapse}td{padding:8px;border-bottom:1px solid #ccc}p{line-height:1.5}.small{font-size:8pt;color:#555}</style><h1>Prescrição nutricional</h1><p>Documento '.$e($s['number']).' · Versão '.$e($s['version']).'<br>Preparado em '.$e($s['prepared_at']).' UTC</p><p><b>Paciente:</b> '.$e($s['patient']['name']).'<br>Nascimento: '.$e($s['patient']['birth_date']).' · Identificador '.$e($s['patient']['id']).'</p><p><b>Nutricionista:</b> '.$e($s['professional']['name']).'<br>'.$e($s['professional']['crn_region']).'/'.$e($s['professional']['crn']).'<br>'.$e($s['professional']['clinic_name']).' · '.$e($s['professional']['clinic_phone']).'</p><table>'.$rows.'</table><p><b>Forma:</b> '.$e($p['form']).' · <b>Via:</b> '.$e($p['route']).'<br><b>Frequência:</b> '.$e($p['frequency_per_day']).' administração(ões) por dia<br><b>Duração:</b> '.$e($p['duration_days']).' dias<br><b>Quantidade total:</b> '.$e($p['quantity']).'</p><p><b>Orientações:</b><br>'.nl2br($e($p['instructions'])).'</p><p class="small">Documento eletrônico com assinatura destacada CMS (.p7s). O PDF isolado não comprova assinatura. Verifique em conjunto os dois arquivos. Política '.$e($s['eligibility']['policy']['version']).'. Integridade e situação atual disponíveis no portal autenticado.</p></html>';
    }
    public static function render(array $snapshot): string
    {
        $o=new Options();$o->set('isRemoteEnabled',false);$o->set('isPhpEnabled',false);$o->set('isJavascriptEnabled',false);$o->set('chroot',dirname(__DIR__,3).'/public');
        $d=new Dompdf($o);$d->loadHtml(self::html($snapshot),'UTF-8');$d->setPaper('A4');$d->render();$bytes=$d->output();
        if (!str_starts_with($bytes,'%PDF-') || strlen($bytes)<800) throw new \RuntimeException('Falha ao gerar documento.');
        return $bytes;
    }
}
