<?php
declare(strict_types=1);

// Canal público de atualização. O código-fonte continua privado no GitHub;
// somente manifesto/pacotes versionados são publicados neste endpoint.
const UPDATE_CHANNEL_URL = 'https://raw.githubusercontent.com/euiff/instalador2/main/adminigreja-updates';
const UPDATE_REPO_LABEL = 'euiff/adminigreja';

function update_http_get(string $url): string {
    if (!str_starts_with($url, 'https://')) throw new RuntimeException('Canal de atualização inseguro recusado.');
    $body = false; $status = 0;
    $headers = ['Accept: application/json, text/plain, */*','User-Agent: AdminIgreja-Updater/2.1.1'];
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>$headers,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>45,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_MAXREDIRS=>4]);
        $body=curl_exec($ch); $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE); $err=curl_error($ch); curl_close($ch);
        if($body===false) throw new RuntimeException('Falha ao acessar o canal de atualização: '.$err);
    } else {
        $ctx=stream_context_create(['http'=>['method'=>'GET','header'=>implode("\r\n",$headers),'timeout'=>45,'ignore_errors'=>true]]);
        $body=@file_get_contents($url,false,$ctx);
        foreach(($http_response_header??[]) as $h) if(preg_match('/HTTP\/\S+\s+(\d{3})/',$h,$m)) $status=(int)$m[1];
        if($body===false) throw new RuntimeException('A hospedagem não conseguiu acessar o canal de atualização por HTTPS.');
    }
    if($status<200||$status>=300) throw new RuntimeException('Canal de atualização indisponível (HTTP '.$status.').');
    return (string)$body;
}

function update_url(string $relative): string {
    $relative=ltrim(str_replace('\\','/',$relative),'/');
    if($relative===''||str_contains($relative,'..')) throw new RuntimeException('Caminho de atualização inválido.');
    return rtrim(UPDATE_CHANNEL_URL,'/').'/'.implode('/',array_map('rawurlencode',explode('/',$relative)));
}

function remote_release(): array {
    $raw=update_http_get(update_url('latest.json').'?t='.time());
    $r=json_decode($raw,true);
    if(!is_array($r)||empty($r['version'])||empty($r['parts'])||empty($r['sha256'])||empty($r['files'])) throw new RuntimeException('Manifesto de atualização inválido.');
    return $r;
}

function safe_update_path(string $path):string{
    $path=str_replace('\\','/',trim($path));
    if($path===''||str_contains($path,'..')||str_starts_with($path,'/')) throw new RuntimeException('Caminho de atualização recusado.');
    $allowedRoot=['index.php','VERSION','.htaccess','install.php','reparar.php']; $prefixes=['app/','assets/','public/','database/'];
    if(!in_array($path,$allowedRoot,true)){ $ok=false; foreach($prefixes as $p) if(str_starts_with($path,$p)){ $ok=true; break; } if(!$ok) throw new RuntimeException('Arquivo fora da área permitida: '.$path); }
    return $path;
}

function updater_check():array{
    $local=app_version();
    $r=remote_release();
    return ['local'=>$local,'remote'=>(string)$r['version'],'available'=>version_compare((string)$r['version'],$local,'>')];
}

function tar_extract_string(string $tar,string $stageRoot,array $expected):void{
    $len=strlen($tar);$pos=0;$seen=[];
    while($pos+512<=$len){$h=substr($tar,$pos,512);$pos+=512;if(trim($h,"\0")==='')break;
        $name=rtrim(substr($h,0,100),"\0 ");$prefix=rtrim(substr($h,345,155),"\0 ");if($prefix!=='')$name=$prefix.'/'.$name;
        $size=octdec(trim(substr($h,124,12),"\0 "));$type=substr($h,156,1);
        if($type==='5'){continue;} if($type!=="\0"&&$type!==''&&$type!=='0')throw new RuntimeException('Tipo de arquivo não permitido no pacote.');
        $path=safe_update_path($name);$data=substr($tar,$pos,$size);$pos+=((int)ceil($size/512))*512;
        if(!isset($expected[$path]))throw new RuntimeException('Arquivo inesperado no pacote: '.$path);
        if(!hash_equals(strtolower((string)$expected[$path]),hash('sha256',$data)))throw new RuntimeException('Falha de integridade em '.$path.'.');
        $dest=$stageRoot.'/'.$path;if(!is_dir(dirname($dest)))@mkdir(dirname($dest),0750,true);if(file_put_contents($dest,$data,LOCK_EX)===false)throw new RuntimeException('Não foi possível preparar '.$path.'.');$seen[$path]=true;
    }
    foreach($expected as $path=>$hash)if(empty($seen[$path]))throw new RuntimeException('Arquivo ausente no pacote: '.$path);
}
function remove_tree(string $dir):void{if(!is_dir($dir))return;$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $x){$x->isDir()?@rmdir($x->getPathname()):@unlink($x->getPathname());}@rmdir($dir);}

function updater_apply():array{
    $r=remote_release(); $remote=(string)$r['version']; $local=app_version();
    if(!version_compare($remote,$local,'>'))return['updated'=>false,'version'=>$local];
    if(!empty($r['min_php'])&&version_compare(PHP_VERSION,(string)$r['min_php'],'<'))throw new RuntimeException('Esta atualização exige PHP '.(string)$r['min_php'].' ou superior.');
    $b64=''; foreach($r['parts'] as $part){ $b64.=trim(update_http_get(update_url((string)$part))); if(strlen($b64)>30*1024*1024) throw new RuntimeException('Pacote de atualização excedeu o limite de segurança.'); }
    $gz=base64_decode($b64,true); if($gz===false)throw new RuntimeException('Pacote de atualização inválido.');
    if(!hash_equals(strtolower((string)$r['sha256']),hash('sha256',$gz)))throw new RuntimeException('SHA-256 do pacote não confere.');
    if(!function_exists('gzdecode'))throw new RuntimeException('A extensão Zlib do PHP é necessária para atualização automática.');
    $tar=gzdecode($gz);if($tar===false)throw new RuntimeException('Não foi possível descompactar a atualização.');
    $expected=[];foreach($r['files'] as $f){if(!is_array($f)||empty($f['path'])||empty($f['sha256']))throw new RuntimeException('Manifesto de arquivos inválido.');$expected[safe_update_path((string)$f['path'])]=(string)$f['sha256'];}
    $root=dirname(__DIR__);$stamp=date('Ymd-His');$backupRoot=$root.'/storage/backups/update-'.$stamp;$stageRoot=$root.'/storage/update-stage-'.bin2hex(random_bytes(6));@mkdir($backupRoot,0750,true);@mkdir($stageRoot,0750,true);
    try{
        tar_extract_string($tar,$stageRoot,$expected);
        foreach(array_keys($expected) as $path){$target=$root.'/'.$path;if(is_file($target)){$backup=$backupRoot.'/'.$path;if(!is_dir(dirname($backup)))@mkdir(dirname($backup),0750,true);if(!@copy($target,$backup))throw new RuntimeException('Não foi possível criar backup de '.$path.'.');}}
        foreach(array_keys($expected) as $path){$stage=$stageRoot.'/'.$path;$target=$root.'/'.$path;if(!is_dir(dirname($target)))@mkdir(dirname($target),0750,true);$tmp=$target.'.new';if(!@copy($stage,$tmp))throw new RuntimeException('Falha ao gravar '.$path.'.');@chmod($tmp,0644);if(!@rename($tmp,$target)){@unlink($tmp);throw new RuntimeException('Falha ao ativar '.$path.'.');}}
        audit('system_update','system',null,['from'=>$local,'to'=>$remote]);return['updated'=>true,'version'=>$remote,'backup'=>basename($backupRoot)];
    }finally{remove_tree($stageRoot);}
}
