<?php
declare(strict_types=1);

final class SimplePdf {
    private array $pages=[];
    private int $page=-1;
    private array $images=[];

    public function addPage(string $orientation='P'): void {
        $land=strtoupper($orientation)==='L';
        $this->pages[]=['w'=>$land?841.89:595.28,'h'=>$land?595.28:841.89,'ops'=>[],'images'=>[]];
        $this->page=count($this->pages)-1;
    }
    public function width(): float { return $this->pages[$this->page]['w']; }
    public function height(): float { return $this->pages[$this->page]['h']; }
    private function op(string $s):void{$this->pages[$this->page]['ops'][]=$s;}
    private function n(float $v):string{return rtrim(rtrim(number_format($v,2,'.',''),'0'),'.');}
    private function color(array $c):string{return $this->n(($c[0]??0)/255).' '.$this->n(($c[1]??0)/255).' '.$this->n(($c[2]??0)/255);}
    private function enc(string $s):string{
        $x=@iconv('UTF-8','Windows-1252//TRANSLIT',$s); if($x===false)$x=$s;
        return str_replace(['\\','(',')',"\r","\n"],['\\\\','\\(','\\)',' ',' '],$x);
    }
    public function text(float $x,float $yTop,string $text,float $size=10,bool $bold=false,array $color=[20,32,51]):void{
        $y=$this->height()-$yTop;
        $this->op($this->color($color).' rg BT /'.($bold?'F2':'F1').' '.$this->n($size).' Tf '.$this->n($x).' '.$this->n($y).' Td ('.$this->enc($text).') Tj ET');
    }
    public function textRight(float $xRight,float $yTop,string $text,float $size=10,bool $bold=false,array $color=[20,32,51]):void{
        $this->text($xRight-$this->textWidth($text,$size),$yTop,$text,$size,$bold,$color);
    }
    public function textWidth(string $text,float $size):float{return max(0,(function_exists('mb_strlen')?mb_strlen($text,'UTF-8'):strlen($text))*$size*.50);}
    public function line(float $x1,float $y1Top,float $x2,float $y2Top,array $color=[220,228,238],float $width=.7):void{
        $h=$this->height();$this->op($this->color($color).' RG '.$this->n($width).' w '.$this->n($x1).' '.$this->n($h-$y1Top).' m '.$this->n($x2).' '.$this->n($h-$y2Top).' l S');
    }
    public function rect(float $x,float $yTop,float $w,float $h,array $fill=[255,255,255],?array $stroke=null,float $radius=0):void{
        $y=$this->height()-$yTop-$h;
        $op=$this->color($fill).' rg ';
        if($stroke!==null)$op.=$this->color($stroke).' RG .7 w ';
        // Retângulo simples: visual limpo e compatível.
        $op.=$this->n($x).' '.$this->n($y).' '.$this->n($w).' '.$this->n($h).' re '.($stroke!==null?'B':'f');
        $this->op($op);
    }
    public function wrap(string $text,float $maxWidth,float $size=9):array{
        $words=preg_split('/\s+/u',trim($text))?:[];$lines=[];$line='';
        foreach($words as $word){$test=$line===''?$word:$line.' '.$word;if($this->textWidth($test,$size)<=$maxWidth){$line=$test;}else{if($line!=='')$lines[]=$line;$line=$word;}}
        if($line!=='')$lines[]=$line; return $lines?:[''];
    }
    public function image(string $path,float $x,float $yTop,float $w,float $h):bool{
        if(!is_file($path))return false;
        $raw=@file_get_contents($path); if($raw===false)return false;
        $info=@getimagesizefromstring($raw); if(!$info)return false;
        $mime=(string)($info['mime']??'');
        if($mime!=='image/jpeg'){
            if(!function_exists('imagecreatefromstring')||!function_exists('imagejpeg'))return false;
            $im=@imagecreatefromstring($raw); if(!$im)return false;
            ob_start(); imagejpeg($im,null,88); $raw=(string)ob_get_clean(); imagedestroy($im);
            $info=@getimagesizefromstring($raw); if(!$info)return false;
        }
        $key=hash('sha256',$raw);
        if(!isset($this->images[$key]))$this->images[$key]=['name'=>'Im'.(count($this->images)+1),'data'=>$raw,'w'=>(int)$info[0],'h'=>(int)$info[1]];
        $name=$this->images[$key]['name'];$this->pages[$this->page]['images'][$key]=true;
        $y=$this->height()-$yTop-$h;
        $this->op('q '.$this->n($w).' 0 0 '.$this->n($h).' '.$this->n($x).' '.$this->n($y).' cm /'.$name.' Do Q');
        return true;
    }
    public function output(string $filename='documento.pdf', string $disposition='attachment'):never{
        if(!$this->pages)$this->addPage();
        $objs=[];$add=function(string $s)use(&$objs){$objs[]=$s;return count($objs);};
        $catalog=$add('');$pagesObj=$add('');$font1=$add('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>');$font2=$add('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>');
        $imageObj=[];foreach($this->images as $k=>$img){$imageObj[$k]=$add('<< /Type /XObject /Subtype /Image /Width '.$img['w'].' /Height '.$img['h'].' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length '.strlen($img['data'])." >>\nstream\n".$img['data']."\nendstream");}
        $pageRefs=[];
        foreach($this->pages as $p){
            $stream=implode("\n",$p['ops']);$content=$add('<< /Length '.strlen($stream)." >>\nstream\n".$stream."\nendstream");
            $x='';foreach(array_keys($p['images']) as $k){$x.='/'.$this->images[$k]['name'].' '.$imageObj[$k].' 0 R ';}
            $resources='<< /Font << /F1 '.$font1.' 0 R /F2 '.$font2.' 0 R >>'.($x!==''?' /XObject << '.$x.'>>':'').' >>';
            $pageRefs[]=$add('<< /Type /Page /Parent '.$pagesObj.' 0 R /MediaBox [0 0 '.$this->n($p['w']).' '.$this->n($p['h']).'] /Resources '.$resources.' /Contents '.$content.' 0 R >>');
        }
        $objs[$pagesObj-1]='<< /Type /Pages /Count '.count($pageRefs).' /Kids ['.implode(' ',array_map(fn($n)=>$n.' 0 R',$pageRefs)).'] >>';
        $objs[$catalog-1]='<< /Type /Catalog /Pages '.$pagesObj.' 0 R >>';
        $pdf="%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";$offs=[0];
        foreach($objs as $i=>$obj){$offs[]=strlen($pdf);$pdf.=($i+1)." 0 obj\n".$obj."\nendobj\n";}
        $xref=strlen($pdf);$pdf.="xref\n0 ".(count($objs)+1)."\n0000000000 65535 f \n";
        for($i=1;$i<=count($objs);$i++)$pdf.=sprintf('%010d 00000 n ',$offs[$i])."\n";
        $pdf.='trailer << /Size '.(count($objs)+1).' /Root '.$catalog." 0 R >>\nstartxref\n".$xref."\n%%EOF";
        header('Content-Type: application/pdf');header('Content-Length: '.strlen($pdf));$safeName=preg_replace('/[^A-Za-z0-9._-]/','_',$filename);$disp=$disposition==='inline'?'inline':'attachment';header('Content-Disposition: '.$disp.'; filename="'.$safeName.'"');header('Cache-Control: private, no-store');echo $pdf;exit;
    }
}
