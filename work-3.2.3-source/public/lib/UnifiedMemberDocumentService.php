<?php

declare(strict_types=1);

require_once __DIR__.'/MemberDocumentTemplate.php';

$autoload=dirname(__DIR__).'/vendor/autoload.php';
if(is_file($autoload))require_once $autoload;

final class UnifiedMemberDocumentService
{
    private const TYPES=[
        'baptism_certificate'=>['BAP','Certificado de Batismo'],
        'membership_card'=>['CAR','Carteira de Membro'],
        'membership_certificate'=>['MEM','Certificado de Membro'],
        'recommendation_letter'=>['REC','Carta de Recomendação'],
        'dismissal_letter'=>['DIS','Carta de Desligamento'],
        'visitor_presentation'=>['VIS','Carta de Apresentação'],
        'attendance_declaration'=>['FRE','Declaração de Frequência'],
    ];

    public function __construct(private PDO $pdo,private string $appRoot) {}

    public function types(array $member): array
    {
        $types=self::TYPES;
        if(empty($member['is_baptized']))unset($types['baptism_certificate']);
        return $types;
    }

    public function memberByPhone(string $churchId,string $phone): ?array
    {
        $digits=preg_replace('/\D+/','',$phone)?:'';
        if($digits==='')return null;

        $q=$this->pdo->prepare($this->memberSql().' WHERE m.church_id=? AND m.active=1');
        $q->execute([$churchId]);

        foreach($q->fetchAll() as $member){
            $candidate=preg_replace('/\D+/','',(string)($member['phone']??''))?:'';
            if($candidate===$digits)return $member;
            if(strlen($digits)>=10&&strlen($candidate)>=10&&substr($candidate,-10)===substr($digits,-10))return $member;
        }
        return null;
    }

    public function member(string $churchId,string $memberId): ?array
    {
        $q=$this->pdo->prepare($this->memberSql().' WHERE m.id=? AND m.church_id=? LIMIT 1');
        $q->execute([$memberId,$churchId]);
        return $q->fetch()?:null;
    }

    public function renderHtml(array $church,array $member,string $type,?string $issuedBy=null,array $options=[]): array
    {
        if(!isset(self::TYPES[$type]))throw new RuntimeException('Tipo de documento inválido.');

        [$prefix,$title]=self::TYPES[$type];
        $verification=$this->verification(
            (string)$church['id'],
            (string)$member['id'],
            $type,
            $prefix,
            $issuedBy,
            $member,
            $church,
            $options
        );

        $html=(new MemberDocumentTemplate($this->appRoot))
            ->render($church,$member,$type,$title,$verification,$options);

        return [
            'type'=>$type,
            'title'=>$title,
            'verification_code'=>$verification,
            'html'=>$html,
            'file_name'=>$this->safeFileName($title.' - '.$member['name'].'.pdf'),
        ];
    }

    public function issue(array $church,array $member,string $type,?string $issuedBy=null,array $options=[]): array
    {
        $doc=$this->renderHtml($church,$member,$type,$issuedBy,$options);

        if(!class_exists('Dompdf\\Dompdf')){
            throw new RuntimeException('Motor PDF moderno indisponível. Atualize o sistema novamente para instalar o renderizador.');
        }

        $optionsPdf=new Dompdf\Options();
        $optionsPdf->set('isRemoteEnabled',true);
        $optionsPdf->set('isHtml5ParserEnabled',true);
        $optionsPdf->set('defaultFont','DejaVu Sans');
        $optionsPdf->setChroot($this->appRoot);

        $dompdf=new Dompdf\Dompdf($optionsPdf);
        $dompdf->loadHtml((string)$doc['html'],'UTF-8');
        $dompdf->setPaper('A4','portrait');
        $dompdf->render();

        $bytes=$dompdf->output();
        if(!is_string($bytes)||!str_starts_with($bytes,'%PDF-')){
            throw new RuntimeException('Não foi possível gerar o PDF moderno.');
        }

        $doc['bytes']=$bytes;
        return $doc;
    }

    public function saveTemporary(array $issued,string $churchId): string
    {
        $safeChurch=preg_replace('/[^A-Za-z0-9_-]/','',$churchId)?:'church';
        $dir=rtrim($this->appRoot,'/\\').'/storage/private/generated-documents/'.$safeChurch;

        if(!is_dir($dir)&&!mkdir($dir,0750,true)&&!is_dir($dir)){
            throw new RuntimeException('Não foi possível criar a pasta de documentos.');
        }

        $base=dirname($dir);
        if(!is_file($base.'/.htaccess')){
            @file_put_contents($base.'/.htaccess',"Require all denied\nDeny from all\n",LOCK_EX);
        }

        $path=$dir.'/'.date('YmdHis').'-'.bin2hex(random_bytes(6)).'.pdf';
        if(file_put_contents($path,(string)$issued['bytes'],LOCK_EX)===false){
            throw new RuntimeException('Não foi possível salvar o PDF.');
        }
        @chmod($path,0640);
        return $path;
    }

    private function memberSql(): string
    {
        return 'SELECT m.*,c.name congregation_name,c.address congregation_address,c.pastor_name congregation_pastor,c.pastor_signature_url congregation_signature,c.logo_url congregation_logo
                FROM members m
                LEFT JOIN congregations c ON c.id=m.congregation_id';
    }

    private function verification(string $churchId,string $memberId,string $type,string $prefix,?string $issuedBy,array $member,array $church,array $options): string
    {
        $q=$this->pdo->prepare('SELECT verification_code FROM issued_documents WHERE church_id=? AND member_id=? AND document_type=? AND revoked_at IS NULL ORDER BY issued_at DESC LIMIT 1');
        $q->execute([$churchId,$memberId,$type]);
        $code=$q->fetchColumn();
        if($code)return (string)$code;

        $memberCode=strtoupper(substr(str_replace('-','',$memberId),0,6));
        $churchCode=strtoupper(substr(str_replace('-','',$churchId),0,6));
        $code=$prefix.'-'.$memberCode.'-'.$churchCode.'-'.strtoupper(substr(bin2hex(random_bytes(5)),0,10));

        $metadata=[
            'member_name'=>$member['name']??'',
            'church_name'=>$church['name']??'',
            'options'=>$options,
            'renderer'=>'unified-template',
        ];

        $this->pdo->prepare('INSERT INTO issued_documents(id,church_id,member_id,document_type,verification_code,issued_by,metadata) VALUES(?,?,?,?,?,?,?)')
            ->execute([
                app_uuid(),$churchId,$memberId,$type,$code,$issuedBy,
                json_encode($metadata,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)
            ]);

        return $code;
    }

    private function safeFileName(string $name): string
    {
        $ascii=iconv('UTF-8','ASCII//TRANSLIT',$name);
        $ascii=$ascii!==false?$ascii:$name;
        return preg_replace('/[^A-Za-z0-9._ -]+/','',$ascii)?:'documento.pdf';
    }
}
