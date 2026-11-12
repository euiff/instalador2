<?php
declare(strict_types=1);

/** Platform-wide Gemini API credential, never returned to clients. */
final class FinanceVisionConfig
{
    private static function dir(): string
    {
        return dirname(__DIR__).'/storage/private/fiscal-ai';
    }

    private static function path(): string
    {
        return self::dir().'/gemini.key';
    }

    public static function key(): string
    {
        $env=trim((string)(getenv('GEMINI_API_KEY')?:''));
        if($env!=='')return $env;
        $path=self::path();
        return is_file($path)?trim((string)@file_get_contents($path)):'';
    }

    public static function source(): string
    {
        if(trim((string)(getenv('GEMINI_API_KEY')?:''))!=='')return 'environment';
        return is_file(self::path()) && self::key()!==''?'master':'none';
    }

    public static function enabled(): bool
    {
        return self::key()!=='';
    }

    /**
     * Accept legacy standard and new Gemini authorization keys without
     * assuming the provider's secret alphabet or fixed length.
     * Reject line breaks, whitespace and URLs to keep the API header safe.
     */
    public static function normalizeKey(string $input): string
    {
        $key=trim($input);
        if(preg_match('/^GEMINI_API_KEY\s*=\s*(.*)$/s',$key,$m))
            $key=trim($m[1]);
        if(strlen($key)>=2 &&
           (($key[0]==='"' && substr($key,-1)==='"')
           || ($key[0]==="'" && substr($key,-1)==="'")))
            $key=substr($key,1,-1);
        if(strlen($key)<12 || strlen($key)>4096
           || preg_match('/[^\x21-\x7E]/',$key)
           || str_contains($key,'://') || str_starts_with($key,'{')
           || str_starts_with($key,'['))
            throw new InvalidArgumentException(
                'Cole somente a chave copiada do Google AI Studio, sem espaços, URL, JSON ou quebras de linha.'
            );
        return $key;
    }

    public static function save(string $key): void
    {
        $key=self::normalizeKey($key);
        $dir=self::dir();
        if(!is_dir($dir)&&!@mkdir($dir,0700,true)&&!is_dir($dir))
            throw new RuntimeException('Não foi possível preparar a pasta privada da IA na hospedagem.');
        @chmod($dir,0700);
        // Block direct HTTP access on Apache/cPanel even if storage lies under public_html.
        $guard="Require all denied\n";
        if(@file_put_contents($dir.'/.htaccess',$guard,LOCK_EX)===false)
            throw new RuntimeException('Não foi possível proteger a pasta de credenciais da IA.');
        @chmod($dir.'/.htaccess',0600);
        @file_put_contents($dir.'/index.html','',LOCK_EX);
        $temp=$dir.'/key-'.bin2hex(random_bytes(6)).'.tmp';
        if(@file_put_contents($temp,$key."\n",LOCK_EX)===false)
            throw new RuntimeException('Não foi possível salvar a chave na área privada.');
        @chmod($temp,0600);
        if(!@rename($temp,self::path())){@unlink($temp);throw new RuntimeException('Falha ao ativar a chave da IA.');}
        @chmod(self::path(),0600);
    }

    public static function remove(): void
    {
        if(self::source()==='environment')
            throw new RuntimeException('A chave foi definida no ambiente PHP; remova GEMINI_API_KEY no cPanel.');
        $file=self::path();
        if(is_file($file)&&!@unlink($file))throw new RuntimeException('Não foi possível desativar a chave.');
    }

    public static function model(string $mode): string
    {
        return $mode==='precise'?'gemini-3.7-flash':'gemini-3.6-flash';
    }
}
