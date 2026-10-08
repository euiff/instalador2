<?php

declare(strict_types=1);

/**
 * Gera um pacote compatível com o updater legado AdminIgreja 2.6.x.
 *
 * Uso:
 *   php tools/build-legacy-bridge.php 3.0.0 /tmp/adminigreja-bridge
 *
 * O conteúdo atual de php/ é remapeado para public/ porque o updater antigo
 * só permite arquivos em raízes específicas. Credenciais locais e storage
 * nunca entram no pacote.
 */
$root=dirname(__DIR__);
$version=trim((string)($argv[1]??''));
$outDir=rtrim((string)($argv[2]??''),'/\\');

if(!preg_match('/^\d+\.\d+\.\d+$/',$version)){
    fwrite(STDERR,"Informe uma versão SemVer simples, ex.: 3.0.0\n");
    exit(2);
}
if($outDir===''){
    fwrite(STDERR,"Informe a pasta de saída.\n");
    exit(2);
}
if(!is_dir($outDir)&&!mkdir($outDir,0755,true)&&!is_dir($outDir)){
    throw new RuntimeException('Não foi possível criar a pasta de saída.');
}
$releaseDir=$outDir.'/releases';
if(!is_dir($releaseDir)&&!mkdir($releaseDir,0755,true)&&!is_dir($releaseDir)){
    throw new RuntimeException('Não foi possível criar releases/.');
}

$entries=[
    '.htaccess'=>implode("\n",[
        'Options -Indexes',
        'DirectoryIndex public/index.php',
        '<IfModule mod_rewrite.c>',
        'RewriteEngine On',
        'RewriteRule ^public(?:/|$) - [L]',
        'RewriteRule ^(.*)$ public/$1 [L,QSA]',
        '</IfModule>',
        '',
    ]),
    'index.php'=>"<?php require __DIR__.'/public/index.php';\n",
    'VERSION'=>$version."\n",
    'public/landing.php'=>"<?php header('Location: /', true, 302); exit;\n",
];

$excludeExact=[
    'config/database.php',
    'EQUIVALENCIA-PHP.md',
    'README.md',
];
$excludePrefixes=[
    'storage/',
    '.git/',
];
$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS));
foreach($it as $file){
    if(!$file->isFile())continue;
    $abs=$file->getPathname();
    $rel=str_replace('\\','/',substr($abs,strlen($root)+1));
    if(in_array($rel,$excludeExact,true))continue;
    $skip=false;
    foreach($excludePrefixes as $prefix){
        if(str_starts_with($rel,$prefix)){$skip=true;break;}
    }
    if($skip)continue;
    $data=file_get_contents($abs);
    if($data===false)throw new RuntimeException('Falha ao ler '.$rel);
    $entries['public/'.$rel]=$data;
}
ksort($entries,SORT_STRING);

function bridge_octal(int $value,int $length): string {
    $s=sprintf('%0'.($length-1).'o',$value);
    if(strlen($s)>$length-1)throw new RuntimeException('Valor TAR excedeu o campo.');
    return $s."\0";
}
function bridge_split_path(string $path): array {
    if(strlen($path)<=100)return [$path,''];
    $slash=strrpos(substr($path,0,156),'/');
    if($slash===false)throw new RuntimeException('Caminho TAR muito longo: '.$path);
    $prefix=substr($path,0,$slash);
    $name=substr($path,$slash+1);
    if(strlen($name)>100||strlen($prefix)>155)throw new RuntimeException('Caminho TAR muito longo: '.$path);
    return [$name,$prefix];
}
function bridge_tar_header(string $path,int $size): string {
    [$name,$prefix]=bridge_split_path($path);
    $h=str_pad($name,100,"\0")
      .bridge_octal(0644,8)
      .bridge_octal(0,8)
      .bridge_octal(0,8)
      .bridge_octal($size,12)
      .bridge_octal(0,12)
      .str_repeat(' ',8)
      .'0'
      .str_repeat("\0",100)
      ."ustar\0"
      .'00'
      .str_repeat("\0",32)
      .str_repeat("\0",32)
      .bridge_octal(0,8)
      .bridge_octal(0,8)
      .str_pad($prefix,155,"\0")
      .str_repeat("\0",12);
    if(strlen($h)!==512)throw new RuntimeException('Cabeçalho USTAR inválido.');
    $sum=0;
    for($i=0;$i<512;$i++)$sum+=ord($h[$i]);
    $check=sprintf('%06o',$sum)."\0 ";
    return substr_replace($h,$check,148,8);
}
function bridge_tar(array $entries): string {
    $tar='';
    foreach($entries as $path=>$data){
        $header=bridge_tar_header($path,strlen($data));
        $tar.=$header.$data;
        $pad=(512-(strlen($data)%512))%512;
        if($pad)$tar.=str_repeat("\0",$pad);
    }
    return $tar.str_repeat("\0",1024);
}

$tar=bridge_tar($entries);
$gz=gzencode($tar,9,ZLIB_ENCODING_GZIP);
if($gz===false)throw new RuntimeException('Não foi possível gerar GZIP.');
$encoded=base64_encode($gz);

foreach(glob($releaseDir.'/v'.$version.'.*.b64.txt')?:[] as $old)@unlink($old);
$parts=[];
$chunks=str_split($encoded,8000);
foreach($chunks as $i=>$chunk){
    $name=sprintf('releases/v%s.%02d.b64.txt',$version,$i+1);
    if(file_put_contents($outDir.'/'.$name,$chunk,LOCK_EX)===false)throw new RuntimeException('Falha ao gravar '.$name);
    $parts[]=$name;
}

$files=[];
foreach($entries as $path=>$data){
    $files[]=['path'=>$path,'sha256'=>hash('sha256',$data)];
}
$manifest=[
    'version'=>$version,
    'min_php'=>'8.1',
    'encoding'=>'base64',
    'parts'=>$parts,
    'sha256'=>hash('sha256',$gz),
    'files'=>$files,
];
file_put_contents(
    $outDir.'/latest.json',
    json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n",
    LOCK_EX
);

echo "Bridge {$version}\n";
echo "Arquivos: ".count($files)."\n";
echo "Partes: ".count($parts)."\n";
echo "SHA-256: ".$manifest['sha256']."\n";
