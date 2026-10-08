<?php

declare(strict_types=1);

require_once __DIR__.'/Schema.php';

final class Updater
{
    public function __construct(
        private PDO $pdo,
        private array $cfg,
        private string $root
    ) {
        $this->root=rtrim($this->root,'/\\');
        $this->ensureTables();
    }

    private function ensureTables(): void
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS system_updates (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            version VARCHAR(40) NOT NULL UNIQUE,
            installed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            details TEXT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS update_migrations (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            migration VARCHAR(190) NOT NULL UNIQUE,
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function currentVersion(): string
    {
        $v=$this->pdo->query("SELECT version FROM system_updates ORDER BY installed_at DESC,id DESC LIMIT 1")->fetchColumn();
        if(is_string($v)&&preg_match('/^\\d+\\.\\d+\\.\\d+(?:[-+][A-Za-z0-9.-]+)?$/',$v))return $v;

        $versionFile=$this->root.'/VERSION';
        if(is_file($versionFile)){
            $fileVersion=trim((string)file_get_contents($versionFile));
            if(preg_match('/^\\d+\\.\\d+\\.\\d+(?:[-+][A-Za-z0-9.-]+)?$/',$fileVersion))return $fileVersion;
        }
        return '0.1.0';
    }

    public function channelLabel(): string
    {
        return (string)($this->cfg['channel_label']??$this->cfg['repository']??'Canal de atualização');
    }

    private function httpGet(string $url,array $headers=[]): string
    {
        $ch=curl_init($url);
        curl_setopt_array($ch,[
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_FOLLOWLOCATION=>true,
            CURLOPT_CONNECTTIMEOUT=>10,
            CURLOPT_TIMEOUT=>60,
            CURLOPT_HTTPHEADER=>array_merge([
                'Accept: application/json, text/plain, */*',
                'User-Agent: Igreja-Master-Updater',
                'Cache-Control: no-cache',
            ],$headers),
        ]);
        $body=curl_exec($ch);
        $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
        $err=curl_error($ch);
        curl_close($ch);
        if($body===false||$status<200||$status>=300){
            throw new RuntimeException('Falha ao acessar o canal de atualização (HTTP '.$status.'): '.($err?:'servidor indisponível'));
        }
        return (string)$body;
    }

    private function api(string $path,?string $ref=null): string
    {
        $repo=(string)$this->cfg['repository'];
        $ref=rawurlencode($ref?:(string)$this->cfg['branch']);
        $url="https://api.github.com/repos/{$repo}/contents/".ltrim($path,'/')."?ref={$ref}";
        $headers=['Accept: application/vnd.github.raw+json'];
        if(!empty($this->cfg['token']))$headers[]='Authorization: Bearer '.$this->cfg['token'];
        return $this->httpGet($url,$headers);
    }

    public function manifest(): array
    {
        if(!empty($this->cfg['channel_url'])){
            $url=(string)$this->cfg['channel_url'];
            $url.=(str_contains($url,'?')?'&':'?').'t='.time();
            $data=json_decode($this->httpGet($url),true);
            if(!is_array($data)||empty($data['version'])||!is_array($data['files']??null)||!is_array($data['parts']??null)||empty($data['sha256'])){
                throw new RuntimeException('Manifesto público de atualização inválido.');
            }
            if(!preg_match('/^\\d+\\.\\d+\\.\\d+(?:[-+][A-Za-z0-9.-]+)?$/',(string)$data['version'])){
                throw new RuntimeException('Versão inválida no manifesto público.');
            }
            if(!preg_match('/^[a-f0-9]{64}$/i',(string)$data['sha256'])){
                throw new RuntimeException('SHA-256 do pacote público é inválido.');
            }

            $mapped=[];
            foreach($data['files'] as $file){
                if(!is_array($file))continue;
                $source=str_replace('\\','/',trim((string)($file['path']??'')));
                $sha=strtolower(trim((string)($file['sha256']??'')));
                if(!str_starts_with($source,'public/'))continue;

                $rel=substr($source,7);
                $rel=$this->safeRelative($rel);
                if(!preg_match('/^[a-f0-9]{64}$/',$sha))throw new RuntimeException('SHA-256 inválido para '.$source);
                $mapped[]=['path'=>$rel,'source_path'=>$source,'sha256'=>$sha];
            }
            if(!$mapped)throw new RuntimeException('O pacote público não contém arquivos do sistema.');

            $data['files']=$mapped;
            $data['_channel_mode']='public_package';
            $data['_package_sha256']=strtolower((string)$data['sha256']);
            return $data;
        }

        $path=trim((string)$this->cfg['repo_path'],'/').'/'.ltrim((string)$this->cfg['manifest'],'/');
        $data=json_decode($this->api($path),true);
        if(!is_array($data)||empty($data['version'])||!isset($data['files'])){
            throw new RuntimeException('Manifesto de atualização inválido.');
        }
        if(!preg_match('/^\\d+\\.\\d+\\.\\d+(?:[-+][A-Za-z0-9.-]+)?$/',(string)$data['version'])){
            throw new RuntimeException('Versão inválida no manifesto.');
        }
        if(!empty($data['ref'])&&!preg_match('/^[A-Za-z0-9._\\/-]{7,100}$/',(string)$data['ref'])){
            throw new RuntimeException('Referência de release inválida.');
        }
        $data['_channel_mode']='private_files';
        return $data;
    }

    public function check(): array
    {
        $m=$this->manifest();
        $m['current_version']=$this->currentVersion();
        $m['update_available']=version_compare((string)$m['version'],(string)$m['current_version'],'>');
        return $m;
    }

    private function safeRelative(string $path): string
    {
        $path=str_replace('\\','/',trim($path));
        if(
            $path===''||
            str_contains($path,'..')||
            str_starts_with($path,'/')||
            preg_match('#^(config/database\\.php|config/local\\.php|storage/|\\.env(?:\\.|$))#i',$path)
        ){
            throw new RuntimeException('Caminho de atualização não permitido: '.$path);
        }
        return $path;
    }

    private function backup(array $files): array
    {
        $base=rtrim((string)$this->cfg['backup_dir'],'/\\');
        $dir=$base.'/'.date('Ymd-His').'-'.bin2hex(random_bytes(3));
        if(!is_dir($dir)&&!mkdir($dir,0755,true)&&!is_dir($dir))throw new RuntimeException('Não foi possível criar backup.');

        $existing=[];
        foreach($files as $f){
            $rel=$this->safeRelative((string)($f['path']??''));
            $src=$this->root.'/'.$rel;
            if(!is_file($src))continue;
            $existing[]=$rel;
            $dst=$dir.'/'.$rel;
            if(!is_dir(dirname($dst))&&!mkdir(dirname($dst),0755,true)&&!is_dir(dirname($dst)))throw new RuntimeException('Falha ao criar pasta de backup.');
            if(!copy($src,$dst))throw new RuntimeException('Falha ao copiar backup de '.$rel);
        }
        file_put_contents($dir.'/.backup.json',json_encode(['existing'=>$existing],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),LOCK_EX);
        return ['dir'=>$dir,'existing'=>$existing];
    }

    private function restoreFiles(array $files,array $backup): array
    {
        $errors=[];$existing=array_flip($backup['existing']??[]);
        foreach($files as $f){
            $rel='';
            try{
                $rel=$this->safeRelative((string)($f['path']??''));
                $dst=$this->root.'/'.$rel;
                $bak=rtrim((string)$backup['dir'],'/\\').'/'.$rel;
                if(isset($existing[$rel])){
                    if(!is_file($bak))throw new RuntimeException('backup ausente');
                    if(!is_dir(dirname($dst)))mkdir(dirname($dst),0755,true);
                    if(!copy($bak,$dst))throw new RuntimeException('falha ao restaurar');
                }elseif(is_file($dst)){
                    @unlink($dst);
                }
            }catch(Throwable $e){$errors[]=($rel?:'arquivo').': '.$e->getMessage();}
        }
        return ['ok'=>$errors===[],'errors'=>$errors];
    }

    private function removeTree(string $dir): void
    {
        if(!is_dir($dir))return;
        $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach($it as $item){
            if($item->isDir())@rmdir($item->getPathname());else @unlink($item->getPathname());
        }
        @rmdir($dir);
    }

    private function setMaintenance(bool $enabled,string $version=''): void
    {
        $file=$this->root.'/.maintenance.lock';
        if($enabled){
            file_put_contents($file,json_encode(['version'=>$version,'started_at'=>date(DATE_ATOM)],JSON_UNESCAPED_SLASHES),LOCK_EX);
        }else{
            @unlink($file);
        }
    }

    private function stageFiles(array $manifest,string $version): string
    {
        if(($manifest['_channel_mode']??'')==='public_package'){
            return $this->stagePublicPackage($manifest,$version);
        }

        $files=is_array($manifest['files']??null)?$manifest['files']:[];
        $base=trim((string)$this->cfg['repo_path'],'/');
        $ref=(string)($manifest['ref']??$this->cfg['branch']);
        $stage=$this->newStage($version);

        try{
            foreach($files as $f){
                $rel=$this->safeRelative((string)($f['path']??''));
                $content=$this->api($base.'/'.$rel,$ref);
                $expected=strtolower((string)($f['sha256']??''));
                if($expected===''||!preg_match('/^[a-f0-9]{64}$/',$expected))throw new RuntimeException('SHA-256 ausente ou inválido: '.$rel);
                if(!hash_equals($expected,strtolower(hash('sha256',$content))))throw new RuntimeException('Integridade inválida: '.$rel);
                $this->writeStageFile($stage,$rel,$content);
            }
            return $stage;
        }catch(Throwable $e){
            $this->removeTree($stage);
            throw $e;
        }
    }

    private function newStage(string $version): string
    {
        $stage=$this->root.'/storage/update-staging/'.preg_replace('/[^A-Za-z0-9._-]/','-',$version).'-'.bin2hex(random_bytes(4));
        if(!is_dir($stage)&&!mkdir($stage,0755,true)&&!is_dir($stage))throw new RuntimeException('Não foi possível criar staging da atualização.');
        return $stage;
    }

    private function writeStageFile(string $stage,string $rel,string $content): void
    {
        $dst=$stage.'/'.$rel;
        if(!is_dir(dirname($dst))&&!mkdir(dirname($dst),0755,true)&&!is_dir(dirname($dst)))throw new RuntimeException('Falha ao criar staging para '.$rel);
        if(file_put_contents($dst,$content,LOCK_EX)===false)throw new RuntimeException('Falha ao preparar '.$rel);
    }

    private function stagePublicPackage(array $manifest,string $version): string
    {
        $stage=$this->newStage($version);
        try{
            $base=rtrim((string)($this->cfg['channel_base_url']??''),'/').'/';
            if($base==='/')throw new RuntimeException('URL base do canal público não configurada.');

            $encoded='';
            foreach($manifest['parts'] as $part){
                $part=str_replace('\\','/',trim((string)$part));
                if($part===''||str_contains($part,'..')||str_starts_with($part,'/'))throw new RuntimeException('Parte inválida no pacote público.');
                $encoded.=trim($this->httpGet($base.$part));
            }

            $gzip=base64_decode(preg_replace('/\\s+/','',$encoded),true);
            if($gzip===false)throw new RuntimeException('Pacote público com Base64 inválido.');

            $expectedPackage=strtolower((string)($manifest['_package_sha256']??$manifest['sha256']??''));
            $actualPackage=strtolower(hash('sha256',$gzip));
            if(!hash_equals($expectedPackage,$actualPackage))throw new RuntimeException('SHA-256 do pacote público não confere.');

            $tar=@gzdecode($gzip);
            if($tar===false)throw new RuntimeException('Pacote público GZIP inválido.');

            $entries=$this->parseTar($tar);
            foreach($manifest['files'] as $file){
                $rel=$this->safeRelative((string)$file['path']);
                $source=(string)($file['source_path']??('public/'.$rel));
                if(!array_key_exists($source,$entries))throw new RuntimeException('Arquivo ausente no pacote público: '.$source);
                $content=$entries[$source];
                $expected=strtolower((string)$file['sha256']);
                if(!hash_equals($expected,strtolower(hash('sha256',$content))))throw new RuntimeException('Integridade inválida no pacote público: '.$rel);
                $this->writeStageFile($stage,$rel,$content);
            }

            return $stage;
        }catch(Throwable $e){
            $this->removeTree($stage);
            throw $e;
        }
    }

    private function parseTar(string $tar): array
    {
        $entries=[];$pos=0;$length=strlen($tar);
        while($pos+512<=$length){
            $header=substr($tar,$pos,512);$pos+=512;
            if(trim($header,"\0")==='')break;

            $name=rtrim(substr($header,0,100),"\0 ");
            $prefix=rtrim(substr($header,345,155),"\0 ");
            if($prefix!=='')$name=$prefix.'/'.$name;
            $name=str_replace('\\','/',$name);

            if($name===''||str_starts_with($name,'/')||str_contains($name,'..'))throw new RuntimeException('Caminho inseguro dentro do pacote.');
            $sizeField=trim(substr($header,124,12),"\0 ");
            $size=$sizeField===''?0:octdec($sizeField);
            $type=substr($header,156,1);

            if($pos+$size>$length)throw new RuntimeException('Pacote TAR truncado.');
            $data=substr($tar,$pos,$size);
            $pos+=((int)ceil($size/512))*512;

            if($type==="\0"||$type===''||$type==='0')$entries[$name]=$data;
        }
        return $entries;
    }

    private function applyMigrations(array $manifest): array
    {
        if(($manifest['_channel_mode']??'')==='public_package')return [];

        $base=trim((string)$this->cfg['repo_path'],'/');
        $ref=(string)($manifest['ref']??$this->cfg['branch']);
        $applied=[];
        foreach(($manifest['migrations']??[]) as $migration){
            $migration=basename((string)$migration);
            if(!preg_match('/^[A-Za-z0-9._-]+\\.sql$/',$migration))throw new RuntimeException('Nome de migration inválido.');

            $q=$this->pdo->prepare('SELECT 1 FROM update_migrations WHERE migration=?');
            $q->execute([$migration]);
            if($q->fetchColumn())continue;

            $sql=$this->api($base.'/updates/migrations/'.$migration,$ref);
            try{
                $this->pdo->exec($sql);
                $i=$this->pdo->prepare('INSERT INTO update_migrations(migration) VALUES(?)');
                $i->execute([$migration]);
                $applied[]=$migration;
            }catch(Throwable $e){
                throw new RuntimeException('Falha na migration '.$migration.': '.$e->getMessage(),0,$e);
            }
        }
        return $applied;
    }

    public function install(array $manifest): array
    {
        $version=(string)($manifest['version']??'');
        if($version===''||!version_compare(PHP_VERSION,(string)($manifest['min_php']??'8.1'),'>=')){
            throw new RuntimeException('PHP mínimo exigido: '.($manifest['min_php']??'8.1'));
        }

        $files=is_array($manifest['files']??null)?$manifest['files']:[];
        $deletes=is_array($manifest['delete']??null)?$manifest['delete']:[];
        if(!$files)throw new RuntimeException('Manifesto não possui arquivos para instalação.');

        $deleteEntries=[];
        foreach($deletes as $path){
            $rel=$this->safeRelative((string)$path);
            $deleteEntries[]=['path'=>$rel];
        }

        $stage=$this->stageFiles($manifest,$version);
        $backupTargets=array_merge($files,$deleteEntries);
        $backup=$this->backup($backupTargets);
        $this->setMaintenance(true,$version);

        try{
            $appliedMigrations=$this->applyMigrations($manifest);

            $schemaMigrations=[];
            if(is_dir($stage.'/database')){
                $schemaMigrations=(new Schema($this->pdo,$stage.'/database'))->migrate();
            }

            foreach($files as $f){
                $rel=$this->safeRelative((string)$f['path']);
                $src=$stage.'/'.$rel;
                $dst=$this->root.'/'.$rel;
                if(!is_file($src))throw new RuntimeException('Arquivo não encontrado no staging: '.$rel);
                if(!is_dir(dirname($dst))&&!mkdir(dirname($dst),0755,true)&&!is_dir(dirname($dst)))throw new RuntimeException('Falha ao criar pasta para '.$rel);
                $tmp=$dst.'.update.tmp';
                if(!copy($src,$tmp))throw new RuntimeException('Falha ao preparar substituição de '.$rel);
                if(!rename($tmp,$dst)){@unlink($tmp);throw new RuntimeException('Falha ao substituir '.$rel);}
            }

            $deleted=[];
            foreach($deleteEntries as $entry){
                $rel=$this->safeRelative((string)$entry['path']);
                $target=$this->root.'/'.$rel;
                if(is_dir($target))throw new RuntimeException('A atualização não remove diretórios automaticamente: '.$rel);
                if(is_file($target)){
                    if(!unlink($target))throw new RuntimeException('Falha ao remover arquivo obsoleto: '.$rel);
                    $deleted[]=$rel;
                }
            }

            $details=[
                'backup'=>$backup['dir'],
                'channel'=>$this->channelLabel(),
                'migrations'=>$appliedMigrations,
                'schema_migrations'=>$schemaMigrations,
                'deleted'=>$deleted,
            ];
            $q=$this->pdo->prepare('INSERT INTO system_updates(version,details) VALUES(?,?) ON DUPLICATE KEY UPDATE installed_at=CURRENT_TIMESTAMP,details=VALUES(details)');
            $q->execute([$version,json_encode($details,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);

            return [
                'version'=>$version,
                'backup'=>$backup['dir'],
                'channel'=>$this->channelLabel(),
                'migrations'=>$appliedMigrations,
                'schema_migrations'=>$schemaMigrations,
                'deleted'=>$deleted,
            ];
        }catch(Throwable $e){
            $restore=$this->restoreFiles($backupTargets,$backup);
            throw new RuntimeException(
                'Atualização interrompida: '.$e->getMessage().
                ($restore['ok']?' Os arquivos anteriores foram restaurados.':' Falha parcial ao restaurar arquivos.'),
                0,
                $e
            );
        }finally{
            $this->setMaintenance(false);
            $this->removeTree($stage);
        }
    }
}
