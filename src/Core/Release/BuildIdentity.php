<?php
declare(strict_types=1);
namespace ClyoNutri\Core\Release;

/** Artifact consistency, not a signature or a production acceptance. */
final class BuildIdentity
{
    public const PACKAGE_VERSION = 'v54.76';
    public const UI_VERSION = 'v54.76';
    public const SCOPE = 'runtime-sha256-v1';
    private const DIRS = ['src','views','public','config','database','bin','app','routes','bootstrap','vendor'];
    private const FILES = ['.htaccess','index.php','composer.json','composer.lock'];

    public static function inventory(string $root): array
    {
        $files = [];
        foreach (self::DIRS as $dir) {
            $base = $root.'/'.$dir;
            if (is_link($base)) throw new \RuntimeException('Symlink in runtime scope');
            if (!is_dir($base)) continue;
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $entry) {
                $path = str_replace(DIRECTORY_SEPARATOR, '/', substr($entry->getPathname(), strlen($root)+1));
                if ($path === 'config/build-manifest.json' || $entry->getFilename() === '.DS_Store' || str_ends_with($path, '.log')) continue;
                if ($entry->isLink()) throw new \RuntimeException('Symlink in runtime scope');
                if ($entry->isFile()) {
                    $hash = hash_file('sha256', $entry->getPathname());
                    if ($hash === false) throw new \RuntimeException('Unreadable runtime file');
                    $files[$path] = $hash;
                }
            }
        }
        foreach (self::FILES as $path) {
            if (is_link($root.'/'.$path)) throw new \RuntimeException('Symlink in runtime scope');
            if (is_file($root.'/'.$path)) $files[$path] = hash_file('sha256', $root.'/'.$path);
        }
        ksort($files, SORT_STRING);
        return $files;
    }

    public static function fingerprint(array $files): string
    {
        return hash('sha256', json_encode($files, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public static function generate(string $root): array
    {
        $files = self::inventory($root);
        $digest = self::fingerprint($files);
        return ['schema'=>1,'scope'=>self::SCOPE,'package_version'=>self::PACKAGE_VERSION,
            'ui_version'=>self::UI_VERSION,'build_id'=>self::PACKAGE_VERSION.'-'.substr($digest,0,16),
            'built_at_utc'=>gmdate('Y-m-d\TH:i:s\Z'),'content_sha256'=>$digest,'files'=>$files];
    }

    public static function inspect(string $root): array
    {
        $failed = ['integrity'=>'unverified','package_version'=>null,'ui_version'=>self::UI_VERSION,
            'build_id'=>null,'built_at_utc'=>null,'content_sha256'=>null,'file_count'=>0,'scope'=>self::SCOPE];
        try {
            if (!is_file($root.'/config/build-manifest.json') || !is_readable($root.'/config/build-manifest.json')) return $failed;
            $raw = file_get_contents($root.'/config/build-manifest.json');
            if ($raw === false) return $failed;
            $m = json_decode($raw,true,64,JSON_THROW_ON_ERROR);
            if (!is_array($m) || ($m['schema']??null)!==1 || ($m['scope']??null)!==self::SCOPE
                || ($m['package_version']??null)!==self::PACKAGE_VERSION || ($m['ui_version']??null)!==self::UI_VERSION
                || !is_array($m['files']??null) || !$m['files']
                || !preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/',(string)($m['built_at_utc']??''))
                || !preg_match('/^[a-f0-9]{64}$/',(string)($m['content_sha256']??''))
                || ($m['build_id']??null)!==self::PACKAGE_VERSION.'-'.substr($m['content_sha256'],0,16)
                || !hash_equals($m['content_sha256'],self::fingerprint($m['files']))) return $failed;
            $actual = self::inventory($root);
            if ($actual !== $m['files']) return $failed;
            return ['integrity'=>'consistent','package_version'=>$m['package_version'],'ui_version'=>$m['ui_version'],
                'build_id'=>$m['build_id'],'built_at_utc'=>$m['built_at_utc'],'content_sha256'=>$m['content_sha256'],
                'file_count'=>count($actual),'scope'=>self::SCOPE];
        } catch (\Throwable $e) { return $failed; }
    }
}
