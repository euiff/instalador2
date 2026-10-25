<?php

declare(strict_types=1);

require_once __DIR__.'/SimplePdf.php';

final class MemberDocumentService
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

        $q=$this->pdo->prepare('SELECT m.*,c.name congregation_name,c.address congregation_address,c.pastor_name congregation_pastor,c.pastor_signature_url congregation_signature FROM members m LEFT JOIN congregations c ON c.id=m.congregation_id WHERE m.church_id=? AND m.active=1');
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
        $q=$this->pdo->prepare('SELECT m.*,c.name congregation_name,c.address congregation_address,c.pastor_name congregation_pastor,c.pastor_signature_url congregation_signature FROM members m LEFT JOIN congregations c ON c.id=m.congregation_id WHERE m.id=? AND m.church_id=? LIMIT 1');
        $q->execute([$memberId,$churchId]);
        return $q->fetch()?:null;
    }

    public function issue(array $church,array $member,string $type,?string $issuedBy=null,array $options=[]): array
    {
        if(!isset(self::TYPES[$type]))throw new RuntimeException('Tipo de documento inválido.');

        [$prefix,$title]=self::TYPES[$type];
        $verification=$this->verification((string)$church['id'],(string)$member['id'],$type,$prefix,$issuedBy,$member,$church,$options);
        $pdf=$this->buildPdf($church,$member,$type,$title,$verification,$options);

        return [
            'type'=>$type,
            'title'=>$title,
            'verification_code'=>$verification,
            'bytes'=>$pdf->bytes(),
            'file_name'=>$this->safeFileName($title.' - '.$member['name'].'.pdf'),
        ];
    }

    public function saveTemporary(array $issued,string $churchId): string
    {
        $safeChurch=preg_replace('/[^A-Za-z0-9_-]/','',$churchId)?:'church';
        $dir=rtrim($this->appRoot,'/\\').'/storage/private/generated-documents/'.$safeChurch;
        if(!is_dir($dir)&&!mkdir($dir,0750,true)&&!is_dir($dir))throw new RuntimeException('Não foi possível criar a pasta de documentos.');
        $base=dirname($dir);
        if(!is_file($base.'/.htaccess'))@file_put_contents($base.'/.htaccess',"Require all denied\nDeny from all\n",LOCK_EX);
        $path=$dir.'/'.date('YmdHis').'-'.bin2hex(random_bytes(6)).'.pdf';
        if(file_put_contents($path,(string)$issued['bytes'],LOCK_EX)===false)throw new RuntimeException('Não foi possível gerar o arquivo PDF.');
        @chmod($path,0640);
        return $path;
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
        ];
        $this->pdo->prepare('INSERT INTO issued_documents(id,church_id,member_id,document_type,verification_code,issued_by,metadata) VALUES(?,?,?,?,?,?,?)')
            ->execute([app_uuid(),$churchId,$memberId,$type,$code,$issuedBy,json_encode($metadata,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
        return $code;
    }

    private function buildPdf(array $church,array $m,string $type,string $title,string $verification,array $options): SimplePdf
    {
        $pastor=(string)($m['congregation_pastor']?:($church['pastor_name']??'Responsável / Pastor'));
        $address=(string)($m['congregation_address']?:($church['address']??''));
        $today=date('d/m/Y');
        $fmt=fn($d)=>$d?date('d/m/Y',strtotime((string)$d)):'_______________';

        $pdf=new SimplePdf($title,(string)($church['name']??'Igreja'));
        $pdf->heading((string)($church['name']??'Igreja'),17);
        if($address!=='')$pdf->line($address,9);
        $pdf->separator()->spacer(12);
        $pdf->heading(mb_strtoupper($title,'UTF-8'),16)->spacer(10);

        switch($type){
            case 'baptism_certificate':
                $pdf->line('Certificamos que '.$m['name'].', nascido(a) em '.$fmt($m['birth_date']??null).', foi batizado(a) nas águas em '.$fmt($m['baptism_date']??null).', na igreja '.($m['baptism_church']?:($church['name']??'')).', pelo Pastor '.($m['baptism_pastor']?:$pastor).'.',12);
                break;
            case 'membership_card':
                $pdf->subheading((string)$m['name'],14);
                $pdf->line('CPF: '.($m['cpf']?:'_______________'),11);
                $pdf->line('Membro desde: '.$fmt($m['membership_date']??null),11);
                $pdf->line('Cargo/Função: '.($m['role']?:'Membro'),11);
                $pdf->line('Departamento: '.($m['department']?:'—'),11);
                $pdf->line('Batizado(a): '.(!empty($m['is_baptized'])?'Sim':'Não'),11);
                break;
            case 'membership_certificate':
                $pdf->line('Certificamos, para os devidos fins, que '.$m['name'].' é membro ativo desta igreja desde '.$fmt($m['membership_date']??null).', participando das atividades eclesiásticas, exercendo a função de '.($m['role']?:'membro').'.',12);
                break;
            case 'recommendation_letter':
                $pdf->line('A Paz do Senhor! Pela presente, recomendamos o(a) irmão(ã) '.$m['name'].', membro desta igreja desde '.$fmt($m['membership_date']??null).', de bom testemunho e conduta cristã, para que seja recebido(a) com amor fraternal.',12);
                break;
            case 'dismissal_letter':
                $pdf->line('Declaramos que o(a) irmão(ã) '.$m['name'].', membro desta igreja desde '.$fmt($m['membership_date']??null).', solicita seu desligamento nesta data, ficando livre para congregar em outra comunidade de fé.',12);
                break;
            case 'visitor_presentation':
                $pdf->line('É com alegria que apresentamos '.$m['name'].'. Desejamos que seja recebido(a) com amor e comunhão cristã.',12);
                break;
            case 'attendance_declaration':
                $start=$options['period_start']??null;
                $end=$options['period_end']??null;
                $purpose=trim((string)($options['purpose']??'Fins gerais'));
                $text='Declaramos, para os devidos fins, que '.$m['name'].' é membro ativo desta igreja';
                if($start||$end){
                    $text.=' e mantém frequência';
                    if($start)$text.=' desde '.$fmt($start);
                    if($end)$text.=' até '.$fmt($end);
                }else{
                    $text.=' desde '.$fmt($m['membership_date']??null).' e frequenta regularmente os cultos e atividades eclesiásticas';
                }
                $text.='. Finalidade: '.$purpose.'.';
                $pdf->line($text,12);
                break;
        }

        $pdf->spacer(44);
        $pdf->line($today,10);
        $pdf->spacer(28);
        $pdf->line('________________________________________',10);
        $pdf->line($pastor,10,true);
        $pdf->spacer(24);
        $pdf->separator();
        $pdf->line('VERIFICAÇÃO DE AUTENTICIDADE',9,true);
        $pdf->line('Código: '.$verification,9);
        $pdf->line('Consulte a autenticidade no sistema de documentos da igreja.',8);
        return $pdf;
    }

    private function safeFileName(string $name): string
    {
        $ascii=iconv('UTF-8','ASCII//TRANSLIT',$name);
        $ascii=$ascii!==false?$ascii:$name;
        return preg_replace('/[^A-Za-z0-9._ -]+/','',$ascii)?:'documento.pdf';
    }
}
