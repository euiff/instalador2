<?php
declare(strict_types=1);

/**
 * Approved, redacted platform screenshots. Every image must match the exact
 * SHA-256 published in the course release: an unredacted photo cannot be
 * accidentally added through the upload interface.
 */
final class SystemCourseImageService
{
    private array $images = [];
    private string $root;

    public function __construct()
    {
        $data=dirname(__DIR__).'/data/system-course-images.json';
        $raw=is_file($data)?file_get_contents($data):false;
        if(!is_string($raw))throw new RuntimeException('Catálogo de imagens do curso indisponível.');
        $decoded=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
        if(($decoded['scope']??'')!=='client-churches-only'||!isset($decoded['images'])||
           !is_array($decoded['images']))throw new RuntimeException('Catálogo de imagens inválido.');
        foreach($decoded['images'] as $img){
            $id=(string)($img['id']??'');$file=(string)($img['file']??'');
            $hash=strtolower((string)($img['sha256']??''));
            if(!preg_match('/^[0-9]{2}-[a-z0-9-]{5,65}$/D',$id)||
               $file!==$id.'.png'||!preg_match('/^[a-f0-9]{64}$/D',$hash)||
               !preg_match('/^[a-z0-9-]{4,100}$/D',(string)($img['lesson_slug']??''))||
               isset($this->images[$id]))
                throw new RuntimeException('Imagens do curso inválidas ou duplicadas.');
            $this->images[$id]=$img;
        }
        $this->root=dirname(__DIR__).'/storage/private/system-course/screens';
    }

    public function catalog():array{return array_values($this->images);}
    public function total():int{return count($this->images);}
    public function image(string $id):?array{return $this->images[$id]??null;}
    public function storagePath(string $id):string
    {
        if(!isset($this->images[$id]))throw new InvalidArgumentException('Imagem desconhecida.');
        return $this->root.'/'.$id.'.png';
    }

    public function approved(string $id):bool
    {
        $img=$this->image($id);
        if(!$img)return false;
        $path=$this->storagePath($id);
        if(!is_file($path)||is_link($path)||filesize($path)>600000)return false;
        return hash_equals((string)$img['sha256'],hash_file('sha256',$path));
    }

    public function forLesson(string $slug):array
    {
        $result=[];
        foreach($this->images as $img)
            if(($img['lesson_slug']??'')===$slug ||
               in_array($slug,(array)($img['additional_lessons']??[]),true)){
                $img['is_illustration']=!$this->approved((string)$img['id']);
                $result[]=$img;
            }
        return $result;
    }

    public function approvedCount():int
    {
        $count=0;foreach(array_keys($this->images) as $id)if($this->approved($id))$count++;
        return $count;
    }

    public function importZip(string $tmpPath):int
    {
        if(!is_file($tmpPath)||filesize($tmpPath)>10000000)
            throw new InvalidArgumentException('Selecione o ZIP das imagens do curso, de até 10 MB.');
        if(!class_exists('ZipArchive'))throw new RuntimeException('Extensão ZIP indisponível no servidor.');
        $zip=new ZipArchive();
        if($zip->open($tmpPath)!==true)throw new InvalidArgumentException('Arquivo ZIP inválido.');
        $files=[];
        try{
            if($zip->numFiles!==$this->total())
                throw new InvalidArgumentException('O pacote deve conter exatamente as 17 imagens aprovadas.');
            $bytes=0;
            for($i=0;$i<$zip->numFiles;$i++){
                $stat=$zip->statIndex($i);
                if(!$stat)throw new RuntimeException('Não foi possível conferir um arquivo do ZIP.');
                $name=(string)$stat['name'];
                if(!preg_match('#^imagens/([0-9]{2}-[a-z0-9-]+\.png)$#D',$name,$m))
                    throw new InvalidArgumentException('O ZIP deve conter somente as imagens aprovadas na pasta imagens.');
                $id=substr($m[1],0,-4);$img=$this->image($id);
                if(!$img||isset($files[$id]))throw new InvalidArgumentException('Imagem ausente ou duplicada.');
                $size=(int)$stat['size'];
                $bytes+=$size;
                if($size<1000||$size>600000||$bytes>10000000)
                    throw new InvalidArgumentException('Imagem com tamanho não permitido.');
                $file=$zip->getFromIndex($i);
                if(!is_string($file)||strlen($file)!==$size||
                   !hash_equals($img['sha256'],hash('sha256',$file)))
                    throw new InvalidArgumentException('Imagem alterada ou não anonimizada. Utilize o pacote aprovado.');
                $info=@getimagesizefromstring($file);
                if(!$info||($info['mime']??'')!=='image/png'||$info[0]!==1360||$info[1]!==720)
                    throw new InvalidArgumentException('Tamanho ou formato de imagem não aprovado.');
                $files[$id]=$file;
            }
            if(count($files)!==$this->total())
                throw new InvalidArgumentException('O pacote de imagens está incompleto.');
        }finally{$zip->close();}

        // Store outside public asset URLs; serve only after authentication.
        if(!is_dir($this->root)&&!mkdir($this->root,0750,true)&&!is_dir($this->root))
            throw new RuntimeException('Não foi possível criar o arquivo privado do curso.');
        foreach([dirname(dirname($this->root)),dirname($this->root),$this->root] as $dir){
            $ht=$dir.'/.htaccess';if(!is_file($ht))@file_put_contents($ht,"Require all denied\nDeny from all\n",LOCK_EX);
            $index=$dir.'/index.php';if(!is_file($index))@file_put_contents($index,"<?php http_response_code(403);exit;\n",LOCK_EX);
        }
        $temp=[];
        try{
            foreach($files as $id=>$content){
                $final=$this->storagePath($id);
                $tmp=tempnam($this->root,'.upload-');
                if(!is_string($tmp)||file_put_contents($tmp,$content,LOCK_EX)!==strlen($content))
                    throw new RuntimeException('Falha ao salvar uma imagem aprovada.');
                @chmod($tmp,0640);$temp[$id]=$tmp;
            }
            foreach($temp as $id=>$tmp){
                if(!rename($tmp,$this->storagePath($id)))
                    throw new RuntimeException('Falha ao publicar uma imagem no curso.');
            }
        }finally{foreach($temp as $tmp)if(is_file($tmp))@unlink($tmp);}
        return count($files);
    }
}
