<?php

declare(strict_types=1);

final class UploadService
{
    public function __construct(private string $appRoot) {}

    public function notificationImage(array $file,string $churchId): ?array
    {
        if(($file['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE)return null;

        return $this->store(
            $file,
            $churchId,
            'storage/uploads/notifications',
            [
                'image/jpeg'=>'jpg',
                'image/png'=>'png',
                'image/webp'=>'webp',
            ],
            8*1024*1024,
            false
        );
    }

    public function congregationLogo(array $file,string $churchId): ?array
    {
        if(($file['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE)return null;

        return $this->store(
            $file,
            $churchId,
            'storage/uploads/congregations',
            [
                'image/jpeg'=>'jpg',
                'image/png'=>'png',
                'image/webp'=>'webp',
            ],
            5*1024*1024,
            false
        );
    }

    public function financeDocument(array $file,string $churchId): ?array
    {
        if(($file['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE)return null;

        return $this->store(
            $file,
            $churchId,
            'storage/private/finance',
            [
                'application/pdf'=>'pdf',
                'image/jpeg'=>'jpg',
                'image/png'=>'png',
                'image/webp'=>'webp',
                'image/heic'=>'heic',
                'image/heif'=>'heif',
            ],
            12*1024*1024,
            true
        );
    }

    public function absolute(string $relativePath): string
    {
        $relativePath=ltrim(str_replace('\\','/',$relativePath),'/');
        if(str_contains($relativePath,'..'))throw new RuntimeException('Caminho de arquivo inválido.');
        return rtrim($this->appRoot,'/\\').'/'.$relativePath;
    }

    public function repairPublicImage(?string $url): void
    {
        $url=trim((string)$url);
        if($url===''||preg_match('#^https?://#i',$url))return;
        $relative=ltrim(parse_url($url,PHP_URL_PATH)?:$url,'/');
        if(!str_starts_with($relative,'storage/uploads/'))return;
        $file=$this->absolute($relative);
        if(is_file($file)){
            @chmod(dirname($file),0755);
            @chmod($file,0644);
        }
        $dir=dirname($file);
        while(str_starts_with($dir,$this->absolute('storage/uploads'))){
            @chmod($dir,0755);
            $parent=dirname($dir);
            if($parent===$dir)break;
            $dir=$parent;
        }
        @chmod($this->absolute('storage'),0755);
        @chmod($this->absolute('storage/uploads'),0755);
    }

    private function store(
        array $file,
        string $churchId,
        string $base,
        array $allowedMimes,
        int $maxBytes,
        bool $protect
    ): array {
        $error=(int)($file['error']??UPLOAD_ERR_NO_FILE);
        if($error!==UPLOAD_ERR_OK)throw new RuntimeException($this->uploadError($error));
        $tmp=(string)($file['tmp_name']??'');
        $size=(int)($file['size']??0);
        if($tmp===''||$size<=0||!is_uploaded_file($tmp))
            throw new RuntimeException('O servidor não recebeu o arquivo corretamente. Verifique o limite de upload configurado no PHP.');
        if($size>$maxBytes)
            throw new RuntimeException('Arquivo de '.self::displayBytes($size).' acima do limite do sistema ('.self::displayBytes($maxBytes).').');
        $limit=self::effectivePhpUploadLimit();
        if($limit!==null&&$size>$limit)throw new RuntimeException('Arquivo acima do limite de upload da hospedagem: '.self::displayBytes($limit).'.');
        $mime=(string)(new finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if($mime==='application/octet-stream'&&strtolower(pathinfo((string)($file['name']??''),PATHINFO_EXTENSION))==='pdf'){
            $fh=@fopen($tmp,'rb');
            if($fh){$header=fread($fh,5);fclose($fh);if($header==='%PDF-')$mime='application/pdf';}
        }
        if(!isset($allowedMimes[$mime]))throw new RuntimeException('Formato não aceito. Envie PDF, JPG, PNG, WEBP ou foto HEIC.');
        $safeChurch=preg_replace('/[^A-Za-z0-9_-]/','',$churchId)?:'church';
        $relative=rtrim($base,'/').'/'.$safeChurch;
        $dir=$this->ensureStorageDirectory($relative,$protect);
        if($protect)$this->protectDirectory($this->absolute(rtrim($base,'/')));
        $ext=$allowedMimes[$mime];
        $name=date('YmdHis').'-'.bin2hex(random_bytes(8)).'.'.$ext;
        $target=$dir.'/'.$name;
        if(!@move_uploaded_file($tmp,$target)){
            error_log('Igreja Master upload failure: writable='.(is_writable($dir)?'yes':'no').', free_space='.(@disk_free_space($dir)?:'unavailable'));
            throw new RuntimeException('O servidor não conseguiu salvar o documento. Confira permissões da pasta storage/private/finance e espaço em disco no cPanel.');
        }
        @chmod($target,$protect?0640:0644);

        return [
            'path'=>$relative.'/'.$name,
            'absolute'=>$target,
            'original_name'=>mb_substr((string)($file['name']??$name),0,250),
            'mime'=>$mime,
            'size'=>$size,
        ];
    }

    public static function financeLimits(): array
    {
        return [
            'system'=>12*1024*1024,
            'php'=>self::effectivePhpUploadLimit(),
            'upload_max_filesize'=>(string)ini_get('upload_max_filesize'),
            'post_max_size'=>(string)ini_get('post_max_size'),
            'file_uploads'=>filter_var(ini_get('file_uploads'),FILTER_VALIDATE_BOOLEAN),
            'tmp_writable'=>is_writable(sys_get_temp_dir()),
        ];
    }

    public function financeStorageStatus(string $churchId): array
    {
        $safe=preg_replace('/[^A-Za-z0-9_-]/','',$churchId)?:'church';
        try {
            $dir=$this->ensureStorageDirectory('storage/private/finance/'.$safe,true);
            $this->protectDirectory($this->absolute('storage/private/finance'));
            $probe=$dir.'/.probe-'.bin2hex(random_bytes(5));
            $ok=@file_put_contents($probe,'test',LOCK_EX)!==false;
            if($ok)@unlink($probe);
            return ['ok'=>$ok,'message'=>$ok?'Pasta privada gravável.':'Não foi possível gravar na pasta privada.'];
        } catch(Throwable $e) {
            error_log('Igreja Master finance upload diagnostic: '.$e->getMessage());
            return ['ok'=>false,'message'=>'A hospedagem não permite gravar na pasta de documentos.'];
        }
    }

    private static function iniSize(string|false $raw): ?int
    {
        $value=trim((string)$raw);
        if($value===''||$value==='0'||$value==='-1')return null;
        if(!preg_match('/^(\d+(?:\.\d+)?)\s*([KMG]?)B?$/i',$value,$m))return null;
        $power=match(strtoupper($m[2]??'')){'K'=>1,'M'=>2,'G'=>3,default=>0};
        return (int)floor((float)$m[1]*(1024**$power));
    }

    public static function effectivePhpUploadLimit(): ?int
    {
        $a=self::iniSize(ini_get('upload_max_filesize'));
        $b=self::iniSize(ini_get('post_max_size'));
        if($a===null)return $b;
        if($b===null)return $a;
        return min($a,$b);
    }

    public static function displayBytes(int $bytes): string
    {
        return number_format($bytes/1048576,1,',','.').' MB';
    }

    private function ensureStorageDirectory(string $relative,bool $protect): string
    {
        $segments=explode('/',trim(str_replace('\\','/',$relative),'/'));
        $current=rtrim($this->appRoot,'/\\');
        foreach($segments as $segment){
            if($segment===''||$segment==='.'||$segment==='..')throw new RuntimeException('Caminho inválido.');
            $current.='/'.$segment;
            if(is_link($current))throw new RuntimeException('Atalho de pasta não permitido.');
            if(!is_dir($current)&&!@mkdir($current,$protect?0750:0755)&&!is_dir($current))
                throw new RuntimeException('Não foi possível criar a pasta de documentos. Confira permissões no cPanel.');
            if(!is_writable($current))
                throw new RuntimeException('A pasta de documentos não permite gravação no PHP. Verifique o proprietário e as permissões no cPanel.');
        }
        return $current;
    }

    private function protectDirectory(string $baseDir): void
    {
        if(!is_dir($baseDir)&&!mkdir($baseDir,0750,true)&&!is_dir($baseDir))return;
        $htaccess=$baseDir.'/.htaccess';
        if(!is_file($htaccess)){
            @file_put_contents($htaccess,"Require all denied\nDeny from all\n",LOCK_EX);
        }
        $index=$baseDir.'/index.php';
        if(!is_file($index)){
            @file_put_contents($index,"<?php http_response_code(403); exit('forbidden');\n",LOCK_EX);
        }
    }

    private function uploadError(int $code): string
    {
        return match($code){
            UPLOAD_ERR_INI_SIZE,UPLOAD_ERR_FORM_SIZE=>'Arquivo acima do limite do PHP. Ajuste upload_max_filesize e post_max_size no cPanel.',
            UPLOAD_ERR_PARTIAL=>'O upload foi interrompido. Tente novamente.',
            UPLOAD_ERR_NO_FILE=>'Nenhum arquivo selecionado.',
            UPLOAD_ERR_NO_TMP_DIR=>'Pasta temporária indisponível. Verifique upload_tmp_dir no PHP da hospedagem.',
            UPLOAD_ERR_CANT_WRITE=>'O servidor não conseguiu gravar o arquivo.',
            UPLOAD_ERR_EXTENSION=>'O upload foi bloqueado por uma extensão do PHP.',
            default=>'Falha no upload do arquivo.',
        };
    }
}
