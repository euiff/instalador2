<?php
declare(strict_types=1);

/**
 * Restricted XLSX reader for Enuves "people" export.
 * Zip entry limits, no macros, formulas, external resources or scripts executed.
 */
final class EnuvesXlsxReader
{
    public const MAX_UPLOAD=12*1024*1024;
    public const MAX_ROWS=2500;
    public const MAX_COLUMNS=64;
    private const MAIN_NS='http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private static function zipEntry(ZipArchive $zip,string $path,int $limit):?string
    {
        $idx=$zip->locateName($path);
        if($idx===false)return null;
        $stat=$zip->statIndex($idx);
        if(!$stat || (int)$stat['size']>$limit || (int)$stat['size']<0)
            throw new InvalidArgumentException('Planilha grande ou com estrutura não permitida.');
        $bytes=$zip->getFromIndex($idx);
        if(!is_string($bytes)||strlen($bytes)>$limit)throw new InvalidArgumentException('Falha ao ler a planilha.');
        return $bytes;
    }

    private static function xml(string $content):DOMDocument
    {
        if(stripos($content,'<!DOCTYPE')!==false || stripos($content,'<!ENTITY')!==false)
            throw new InvalidArgumentException('XML de planilha não permitido.');
        $doc=new DOMDocument();
        $doc->resolveExternals=false;
        $doc->substituteEntities=false;
        $previous=libxml_use_internal_errors(true);
        try{
            if(!$doc->loadXML($content,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING))
                throw new InvalidArgumentException('Estrutura XLSX inválida.');
            return $doc;
        }finally{
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private static function text(DOMElement $node):string
    {
        $out='';
        foreach($node->getElementsByTagNameNS(self::MAIN_NS,'t') as $part)$out.=$part->textContent;
        return $out;
    }

    private static function columnIndex(string $address):int
    {
        if(!preg_match('/^([A-Z]{1,3})\d+$/D',$address,$m))return -1;
        $col=0;
        foreach(str_split($m[1]) as $letter)$col=$col*26+(ord($letter)-64);
        return $col-1;
    }

    public static function read(string $path):array
    {
        if(!class_exists('ZipArchive')||!class_exists('DOMDocument'))
            throw new RuntimeException('A hospedagem precisa habilitar as extensões PHP ZIP e DOM.');
        if(!is_file($path)||!is_readable($path)||(int)filesize($path)>self::MAX_UPLOAD)
            throw new InvalidArgumentException('Arquivo XLSX inválido ou maior que 12 MB.');
        $zip=new ZipArchive();
        if($zip->open($path)!==true)throw new InvalidArgumentException('Não foi possível abrir o arquivo XLSX.');
        try{
            if($zip->numFiles>150)throw new InvalidArgumentException('Planilha com arquivos internos em excesso.');
            $shared=[];
            $strings=self::zipEntry($zip,'xl/sharedStrings.xml',8*1024*1024);
            if($strings!==null){
                $doc=self::xml($strings);
                foreach($doc->getElementsByTagNameNS(self::MAIN_NS,'si') as $node){
                    if(count($shared)>200000)throw new InvalidArgumentException('Planilha com textos em excesso.');
                    $shared[]=mb_substr(self::text($node),0,8000);
                }
            }
            $file=self::zipEntry($zip,'xl/worksheets/sheet1.xml',12*1024*1024);
            if($file===null)throw new InvalidArgumentException('A primeira aba de dados não foi encontrada.');
            $xml=self::xml($file);
            $headers=[];
            $rows=[];
            $line=0;
            foreach($xml->getElementsByTagNameNS(self::MAIN_NS,'row') as $node){
                $line++;
                if($line>self::MAX_ROWS+1)throw new InvalidArgumentException('Esta importação aceita até 2.500 pessoas por arquivo.');
                $values=[];
                foreach($node->getElementsByTagNameNS(self::MAIN_NS,'c') as $cell){
                    $index=self::columnIndex((string)$cell->getAttribute('r'));
                    if($index<0||$index>=self::MAX_COLUMNS)continue;
                    $t=(string)$cell->getAttribute('t');
                    $value='';
                    if($t==='inlineStr'){
                        $inline=$cell->getElementsByTagNameNS(self::MAIN_NS,'is');
                        $value=$inline->length?self::text($inline->item(0)):'';
                    }else{
                        $v=$cell->getElementsByTagNameNS(self::MAIN_NS,'v');
                        $raw=$v->length?(string)$v->item(0)->textContent:'';
                        if($t==='s'){
                            if($raw!=='' && !array_key_exists((int)$raw,$shared))
                                throw new InvalidArgumentException('Índice XLSX inválido.');
                            $value=$raw===''?'':(string)$shared[(int)$raw];
                        }else $value=$raw;
                    }
                    $values[$index]=mb_substr(trim(str_replace("\0",'',$value)),0,12000);
                }
                if(!$values)continue;
                if(!$headers){
                    for($i=0;$i<=max(array_keys($values));$i++){
                        $header=trim((string)($values[$i]??''));
                        if($header==='')$header='extra_'.$i;
                        $headers[$i]=$header;
                    }
                    continue;
                }
                $row=[];
                foreach($headers as $i=>$header)$row[$header]=(string)($values[$i]??'');
                if(trim((string)($row['id']??''))===''&&trim((string)($row['full_name']??''))==='')continue;
                $rows[]=$row;
            }
            foreach(['id','full_name','birthydate','archived'] as $required){
                if(!in_array($required,$headers,true))
                    throw new InvalidArgumentException('Coluna obrigatória do Enuves não encontrada: '.$required);
            }
            if(!$rows)throw new InvalidArgumentException('A planilha não contém pessoas para importar.');
            return $rows;
        }finally{$zip->close();}
    }
}
