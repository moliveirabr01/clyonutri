<?php
declare(strict_types=1);
namespace ClyoNutri\Domain\Prescription;
use DomainException;
/** Verifies a real detached CMS signature over the exact immutable PDF; does not hold private keys. */
final class PrescriptionSignature
{
    public function verify(string $pdf,string $der,string $expectedFingerprint): array
    {
        $ca=getenv('RX_TRUST_CA_FILE') ?: '';
        if (!$ca || !is_file($ca) || !is_readable($ca)) throw new DomainException('Assinatura indisponível: cadeia de confiança aprovada ainda não configurada.');
        if (strlen($der)>1024*1024 || strlen($der)<32) throw new DomainException('Assinatura destacada inválida ou maior que 1 MB.');
        $dir=sys_get_temp_dir().'/clyo-rx-'.bin2hex(random_bytes(16));
        if (!mkdir($dir,0700)) throw new \RuntimeException('Falha no armazenamento temporário.');
        try {
            file_put_contents($dir.'/document.pdf',$pdf);file_put_contents($dir.'/signature.der',$der);
            $valid=@openssl_cms_verify($dir.'/document.pdf',OPENSSL_CMS_BINARY|OPENSSL_CMS_DETACHED,$dir.'/signers.pem',[$ca],null,null,null,$dir.'/signature.der',OPENSSL_ENCODING_DER);
            if (!$valid) throw new DomainException('Assinatura não confere com o PDF ou com a cadeia de confiança aprovada.');
            $pem=(string)file_get_contents($dir.'/signers.pem');
            preg_match_all('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s',$pem,$matches);
            if (count($matches[0])!==1) throw new DomainException('A assinatura deve ter exatamente um signatário identificado.');
            $fingerprint=strtolower(openssl_x509_fingerprint($matches[0][0],'sha256') ?: '');
            if (!hash_equals($expectedFingerprint,$fingerprint)) throw new DomainException('Certificado não corresponde ao profissional habilitado.');
            $cert=openssl_x509_parse($matches[0][0]);
            if (!$cert || $cert['validFrom_time_t']>time() || $cert['validTo_time_t']<time()) throw new DomainException('Certificado fora da validade.');
            return ['certificate_sha256'=>$fingerprint,'verified_at'=>gmdate('Y-m-d H:i:s'),'profile'=>'cms_detached'];
        } finally {
            foreach (glob($dir.'/*') ?: [] as $file) @unlink($file);
            @rmdir($dir);
            while (openssl_error_string()!==false) {} // never log certificate or signature payload
        }
    }
}
