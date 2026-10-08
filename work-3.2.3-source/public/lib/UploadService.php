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
        if($tmp===''||$size<=0)throw new RuntimeException('Arquivo vazio ou inválido.');
        if($size>$maxBytes)throw new RuntimeException('Arquivo maior que '.round($maxBytes/1024/1024).' MB.');

        $finfo=new finfo(FILEINFO_MIME_TYPE);
        $mime=(string)$finfo->file($tmp);
        if(!isset($allowedMimes[$mime]))throw new RuntimeException('Formato de arquivo não permitido: '.$mime.'.');

        $safeChurch=preg_replace('/[^A-Za-z0-9_-]/','',$churchId)?:'church';
        $relative=rtrim($base,'/').'/'.$safeChurch;
        $dir=$this->absolute($relative);
        if(!is_dir($dir)&&!mkdir($dir,0750,true)&&!is_dir($dir)){
            throw new RuntimeException('Não foi possível criar a pasta de arquivos.');
        }

        if($protect)$this->protectDirectory(dirname($this->absolute(rtrim($base,'/').'/placeholder')));

        $ext=$allowedMimes[$mime];
        $name=date('YmdHis').'-'.bin2hex(random_bytes(8)).'.'.$ext;
        $target=$dir.'/'.$name;

        if(!is_uploaded_file($tmp)||!move_uploaded_file($tmp,$target)){
            throw new RuntimeException('Não foi possível salvar o arquivo enviado.');
        }
        @chmod($target,0640);

        return [
            'path'=>$relative.'/'.$name,
            'absolute'=>$target,
            'original_name'=>mb_substr((string)($file['name']??$name),0,250),
            'mime'=>$mime,
            'size'=>$size,
        ];
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
            UPLOAD_ERR_INI_SIZE,UPLOAD_ERR_FORM_SIZE=>'O arquivo excede o tamanho permitido.',
            UPLOAD_ERR_PARTIAL=>'O upload foi interrompido. Tente novamente.',
            UPLOAD_ERR_NO_FILE=>'Nenhum arquivo selecionado.',
            UPLOAD_ERR_NO_TMP_DIR=>'Pasta temporária indisponível no servidor.',
            UPLOAD_ERR_CANT_WRITE=>'O servidor não conseguiu gravar o arquivo.',
            UPLOAD_ERR_EXTENSION=>'O upload foi bloqueado por uma extensão do PHP.',
            default=>'Falha no upload do arquivo.',
        };
    }
}
