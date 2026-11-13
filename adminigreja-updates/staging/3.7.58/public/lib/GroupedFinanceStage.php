<?php
declare(strict_types=1);
require_once __DIR__.'/UploadService.php';

final class GroupedFinanceStage
{
    public function __construct(private PDO $db,private string $publicRoot) {}

    private function stageDir(string $cid): string
    {
        $safe=preg_replace('/[^A-Za-z0-9-]/','',$cid);
        if($safe!==$cid||$cid==='')throw new InvalidArgumentException('Igreja inválida para anexos.');
        $dir=rtrim($this->publicRoot,'/').'/storage/private/finance-stage/'.$cid;
        if(!is_dir($dir)&&!@mkdir($dir,0700,true)&&!is_dir($dir))
            throw new RuntimeException('Sem permissão para preparar anexos temporários.');
        @chmod($dir,0700);
        $parent=rtrim($this->publicRoot,'/').'/storage/private/finance-stage';
        @file_put_contents($parent.'/.htaccess',"Require all denied\n",LOCK_EX);
        @file_put_contents($dir.'/.htaccess',"Require all denied\n",LOCK_EX);
        return $dir;
    }

    public function stage(string $cid,string $userId,array $file): array
    {
        if((int)($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)
            throw new RuntimeException('Selecione uma foto, cupom ou PDF válido.');
        $uploader=new UploadService($this->publicRoot);
        $stored=$uploader->financeDocument($file,$cid);
        if(!$stored)throw new RuntimeException('Não foi possível arquivar a foto temporariamente.');
        $token=bin2hex(random_bytes(24));
        $sha=hash_file('sha256',$stored['absolute']);
        $meta=[
            'church_id'=>$cid,'user_id'=>$userId,'path'=>$stored['path'],
            'original_name'=>$stored['original_name'],'mime'=>$stored['mime'],
            'size'=>$stored['size'],'file_sha256'=>$sha,'created_at'=>time()
        ];
        $dir=$this->stageDir($cid);
        $temp=$dir.'/'.$token.'.json';
        $encoded=json_encode($meta,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        if(@file_put_contents($temp,$encoded,LOCK_EX)!==strlen($encoded)){
            @unlink($stored['absolute']);
            throw new RuntimeException('Não foi possível registrar o anexo temporário.');
        }
        @chmod($temp,0600);
        return ['token'=>$token,'original_name'=>$meta['original_name'],'file_sha256'=>$sha,'size'=>$meta['size']];
    }

    public function lookup(string $cid,string $userId,string $token): array
    {
        if(!preg_match('/^[a-f0-9]{48}$/D',$token))
            throw new InvalidArgumentException('Token do documento inválido; selecione o arquivo novamente.');
        $path=$this->stageDir($cid).'/'.$token.'.json';
        if(!is_file($path))throw new RuntimeException('Documento temporário indisponível; selecione a foto novamente.');
        $meta=json_decode((string)@file_get_contents($path),true);
        if(!is_array($meta)||($meta['church_id']??'')!==$cid||($meta['user_id']??'')!==$userId
            ||(int)($meta['created_at']??0)<time()-86400)
            throw new RuntimeException('Este documento expirou ou não pertence ao usuário e à igreja selecionados.');
        $relative=(string)($meta['path']??'');
        $prefix='storage/private/finance/'.$cid.'/';
        if(!str_starts_with($relative,$prefix)||str_contains($relative,'..'))
            throw new RuntimeException('Caminho do comprovante inválido.');
        $absolute=(new UploadService($this->publicRoot))->absolute($relative);
        if(!is_file($absolute)||!is_readable($absolute)
            ||!hash_equals((string)$meta['file_sha256'],hash_file('sha256',$absolute)))
            throw new RuntimeException('Arquivo temporário ausente ou modificado; envie novamente.');
        $meta['absolute']=$absolute;$meta['token_path']=$path;
        return $meta;
    }

    public function consume(string $tokenPath): void
    {
        // Once the DB transaction commits, only metadata is removed;
        // the original file remains stored and linked to the document row.
        if(is_file($tokenPath))@unlink($tokenPath);
    }

    public function prune(string $cid): void
    {
        // Remove only abandoned, unlinked files older than 24h.
        $dir=$this->stageDir($cid);
        foreach(glob($dir.'/*.json')?:[] as $metaPath){
            if((int)(@filemtime($metaPath)?:0)>time()-86400)continue;
            $data=json_decode((string)@file_get_contents($metaPath),true);
            if(!is_array($data)||($data['church_id']??'')!==$cid)continue;
            $path=(string)($data['path']??'');
            $q=$this->db->prepare('SELECT COUNT(*) FROM finance_documents WHERE church_id=? AND storage_path=?');
            $q->execute([$cid,$path]);
            if((int)$q->fetchColumn()!==0){@unlink($metaPath);continue;}
            $prefix='storage/private/finance/'.$cid.'/';
            if(!str_starts_with($path,$prefix)||str_contains($path,'..'))continue;
            $full=(new UploadService($this->publicRoot))->absolute($path);
            if(is_file($full))@unlink($full);
            @unlink($metaPath);
        }
    }
}
