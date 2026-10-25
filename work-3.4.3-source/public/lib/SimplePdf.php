<?php

declare(strict_types=1);

final class SimplePdf
{
    private array $pages=[[]];
    private int $page=0;

    public function __construct(
        private string $title='Relatório',
        private string $author='Igreja Master'
    ) {}

    public function heading(string $text,int $size=18): self
    {
        $this->ensureSpace(32);
        $this->pages[$this->page][]=['text'=>$text,'size'=>$size,'bold'=>true,'gap'=>8];
        return $this;
    }

    public function subheading(string $text,int $size=12): self
    {
        $this->ensureSpace(24);
        $this->pages[$this->page][]=['text'=>$text,'size'=>$size,'bold'=>true,'gap'=>6];
        return $this;
    }

    public function line(string $text='',int $size=10,bool $bold=false,int $gap=4): self
    {
        $wrapped=$this->wrap($text,$size);
        foreach($wrapped as $piece){
            $this->ensureSpace($size+7);
            $this->pages[$this->page][]=['text'=>$piece,'size'=>$size,'bold'=>$bold,'gap'=>$gap];
        }
        return $this;
    }

    public function separator(): self
    {
        return $this->line(str_repeat('-',96),8,false,4);
    }

    public function spacer(int $height=8): self
    {
        $this->ensureSpace($height);
        $this->pages[$this->page][]=['text'=>'','size'=>6,'bold'=>false,'gap'=>$height];
        return $this;
    }

    public function bytes(): string
    {
        $objects=[];
        $objects[1]='<< /Type /Catalog /Pages 2 0 R >>';

        $pageIds=[];
        $fontRegular=3;
        $fontBold=4;
        $objects[$fontRegular]='<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[$fontBold]='<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';

        $next=5;
        foreach($this->pages as $index=>$lines){
            $pageId=$next++;
            $contentId=$next++;
            $pageIds[]=$pageId.' 0 R';

            $content=$this->pageContent($lines,$index+1,count($this->pages));
            $objects[$contentId]='<< /Length '.strlen($content).' >>'."\nstream\n".$content."\nendstream";
            $objects[$pageId]='<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] '
                .'/Resources << /Font << /F1 '.$fontRegular.' 0 R /F2 '.$fontBold.' 0 R >> >> '
                .'/Contents '.$contentId.' 0 R >>';
        }

        $objects[2]='<< /Type /Pages /Kids ['.implode(' ',$pageIds).'] /Count '.count($pageIds).' >>';

        ksort($objects);
        $pdf="%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets=[0=>0];
        foreach($objects as $id=>$body){
            $offsets[$id]=strlen($pdf);
            $pdf.=$id." 0 obj\n".$body."\nendobj\n";
        }

        $xref=strlen($pdf);
        $max=max(array_keys($objects));
        $pdf.="xref\n0 ".($max+1)."\n";
        $pdf.=sprintf("%010d %05d f \n",0,65535);
        for($i=1;$i<=$max;$i++){
            $pdf.=isset($offsets[$i])
                ?sprintf("%010d %05d n \n",$offsets[$i],0)
                :sprintf("%010d %05d f \n",0,0);
        }

        $infoId=$max+1;
        // Metadata is optional; keep trailer compact and standards-compatible.
        $pdf.="trailer\n<< /Size ".($max+1)." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF";
        return $pdf;
    }

    public function download(string $fileName): never
    {
        $safe=preg_replace('/[^A-Za-z0-9._-]+/','-',iconv('UTF-8','ASCII//TRANSLIT',$fileName)?:$fileName)?:'relatorio.pdf';
        if(!str_ends_with(strtolower($safe),'.pdf'))$safe.='.pdf';
        $bytes=$this->bytes();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="'.$safe.'"');
        header('Content-Length: '.strlen($bytes));
        header('X-Content-Type-Options: nosniff');
        echo $bytes;
        exit;
    }

    private function pageContent(array $lines,int $number,int $total): string
    {
        $y=795;
        $out=[];
        foreach($lines as $row){
            $size=(int)$row['size'];
            $font=$row['bold']?'F2':'F1';
            $encoded=$this->pdfText((string)$row['text']);
            $out[]='BT /'.$font.' '.$size.' Tf 42 '.$y.' Td ('.$encoded.') Tj ET';
            $y-=$size+(int)$row['gap'];
        }

        $footer=$this->pdfText('Igreja Master · '.$this->title.' · página '.$number.'/'.$total);
        $out[]='BT /F1 8 Tf 42 22 Td ('.$footer.') Tj ET';
        return implode("\n",$out);
    }

    private function ensureSpace(int $needed): void
    {
        $used=0;
        foreach($this->pages[$this->page] as $row)$used+=(int)$row['size']+(int)$row['gap'];
        if(760-$used<$needed){
            $this->pages[]=[];
            $this->page++;
        }
    }

    private function wrap(string $text,int $size): array
    {
        $text=str_replace(["\r\n","\r"],"\n",$text);
        $max=max(42,(int)floor(1050/max(8,$size)));
        $out=[];
        foreach(explode("\n",$text) as $paragraph){
            if($paragraph===''){$out[]='';continue;}
            $wrapped=wordwrap($paragraph,$max,"\n",true);
            foreach(explode("\n",$wrapped) as $line)$out[]=$line;
        }
        return $out?:[''];
    }

    private function pdfText(string $text): string
    {
        $encoded=iconv('UTF-8','Windows-1252//TRANSLIT',$text);
        if($encoded===false)$encoded=$text;
        return str_replace(['\\','(',')'],['\\\\','\\(','\\)'],$encoded);
    }
}
