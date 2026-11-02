<?php
declare(strict_types=1);

final class PixPayloadService
{
    public static function payload(string $key,?float $amount=null,string $merchantName='IGREJA',string $merchantCity='BRASIL',string $description=''): string
    {
        $key=trim($key);
        if($key==='')throw new InvalidArgumentException('Chave PIX não configurada.');

        $merchantName=self::clean($merchantName,25,'IGREJA');
        $merchantCity=self::clean($merchantCity,15,'BRASIL');
        $description=mb_substr(trim($description),0,72);

        $merchant=self::tlv('00','br.gov.bcb.pix').self::tlv('01',$key);
        if($description!=='')$merchant.=self::tlv('02',$description);

        $payload=
            self::tlv('00','01').
            self::tlv('26',$merchant).
            self::tlv('52','0000').
            self::tlv('53','986');

        if($amount!==null&&$amount>0)$payload.=self::tlv('54',number_format($amount,2,'.',''));

        $payload.=
            self::tlv('58','BR').
            self::tlv('59',$merchantName).
            self::tlv('60',$merchantCity).
            self::tlv('62',self::tlv('05','***')).
            '6304';

        return $payload.self::crc16($payload);
    }

    private static function tlv(string $id,string $value): string
    {
        $len=strlen($value);
        if($len>99)throw new InvalidArgumentException('Campo PIX maior que o permitido.');
        return $id.str_pad((string)$len,2,'0',STR_PAD_LEFT).$value;
    }

    private static function clean(string $value,int $max,string $fallback): string
    {
        $value=trim($value);
        if($value==='')$value=$fallback;
        $ascii=@iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$value);
        if(is_string($ascii)&&$ascii!=='')$value=$ascii;
        $value=strtoupper($value);
        $value=preg_replace('/[^A-Z0-9 .-]/','',$value)??$value;
        $value=trim(preg_replace('/\s+/',' ',$value)??$value);
        return substr($value,0,$max);
    }

    private static function crc16(string $payload): string
    {
        $crc=0xFFFF;
        $len=strlen($payload);
        for($c=0;$c<$len;$c++){
            $crc^=(ord($payload[$c])<<8);
            for($i=0;$i<8;$i++){
                $crc=($crc&0x8000)?(($crc<<1)^0x1021):($crc<<1);
                $crc&=0xFFFF;
            }
        }
        return strtoupper(str_pad(dechex($crc),4,'0',STR_PAD_LEFT));
    }
}
