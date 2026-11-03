<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/lib/AccountingService.php';
$user=$auth->requireAbility('accounting_portal');
$church=$auth->currentChurch();
if(!$church){http_response_code(403);exit('Acesso negado.');}
$service=new AccountingService($pdo,$auth,(string)$church['id'],(string)$user['id']);
try{
    AccountingService::ensureSchema($pdo);
    $period=AccountingService::period($_GET['period']??date('Y-m'));
    if(!class_exists('ZipArchive'))throw new RuntimeException('Extensão ZIP não disponível na hospedagem.');
    $entries=$service->entries($period);
    $docs=$service->documents($period,1000);
    $legacy=$service->legacyDocuments($period);
    $requests=$service->requests($period);
    $summary=$service->summary($period);
    $accounts=$service->accounts();
    $start=$period.'-01';
    $end=(new DateTimeImmutable($start))->modify('+1 month')->format('Y-m-d');
    $pdoQ=$pdo->prepare('SELECT due_date,description,amount,status,document_number FROM finance_payables WHERE church_id=? AND due_date>=? AND due_date<? ORDER BY due_date,id');
    $pdoQ->execute([(string)$church['id'],$start,$end]);
    $payables=$pdoQ->fetchAll(PDO::FETCH_ASSOC);

    // Guard against large exports on low-memory shared hosting.
    if(count($entries)>25000||count($docs)>999||count($legacy)>1000||count($payables)>10000)
        throw new RuntimeException('Mês com muitos registros. Solicite a exportação em partes.');

    $tmp=tempnam(sys_get_temp_dir(),'igreja-contab-');
    if($tmp===false)throw new RuntimeException('Não foi possível preparar o pacote.');
    $zip=new ZipArchive();
    if($zip->open($tmp,ZipArchive::OVERWRITE)!==true)throw new RuntimeException('Não foi possível criar o arquivo ZIP.');
    $safe=static function(mixed $x):string{
        $v=str_replace(["\r","\n"],' ',(string)($x??''));
        $trim=ltrim($v);
        if($trim!==''&&preg_match('/^[=+@\-]/',$trim)&&!preg_match('/^\-?\d+(?:[.,]\d+)?$/D',$trim))return "'".$v;
        return $v;
    };
    $csv=static function(array $header,array $rows)use($safe):string{
        $f=fopen('php://temp','r+');
        fwrite($f,"\xEF\xBB\xBF");
        fputcsv($f,$header,';','"','');
        foreach($rows as $row)fputcsv($f,array_map($safe,array_values($row)),';','"','');
        rewind($f);$s=stream_get_contents($f);fclose($f);return $s;
    };
    $zip->addFromString('LEIA-ME.txt',
        "IGREJA MASTER — DOCUMENTOS CONTÁBEIS\nIgreja: ".($church['name']??'')."\nCompetência: ".$period.
        "\nDocumentos e relatórios extraídos do sistema; não constituem escrituração contábil final.\n".
        "O escritório contábil deve reconciliar com extratos, validar documentos fiscais e preparar obrigações legais aplicáveis.\n".
        "Dados pessoais e financeiros: uso restrito ao contador autorizado.\n");
    $zip->addFromString('resumo.csv',$csv(['Competência','Entradas','Saídas','Despesas sem comprovante','Documentos','Pendentes','Solicitações abertas','Situação'],[
        [$period,$summary['income']??0,$summary['expense']??0,$summary['expense_without_proof']??0,$summary['documents']??0,$summary['pending_review']??0,$summary['open_requests']??0,$summary['period_status']??'open']
    ]));
    $zip->addFromString('movimentacoes.csv',$csv(['Data','Tipo','Descrição','Categoria','Conta','Contraparte','Método','Documento','Valor','Situação','ID'],array_map(static fn($e)=>[
        $e['transaction_date'],$e['direction'],$e['description'],$e['category'],$e['account_name']??'',
        $e['counterparty']??'',$e['payment_method']??'',$e['document_number']??'',$e['amount'],$e['status'],$e['id']],$entries)));
    $zip->addFromString('contas-financeiras.csv',$csv(['Conta','Tipo'],array_map(static fn($a)=>[$a['name'],$a['account_type']],$accounts)));
    $zip->addFromString('contas-a-pagar.csv',$csv(['Vencimento','Descrição','Valor','Situação','Documento'],array_map(
        static fn($p)=>[$p['due_date'],$p['description'],$p['amount'],$p['status'],$p['document_number']??''],$payables)));
    $zip->addFromString('pendencias.csv',$csv(['Solicitação','Detalhes','Situação','Arquivo relacionado'],array_map(
        static fn($r)=>[$r['title'],$r['details'],$r['status'],$r['responded_document_id']??''],$requests)));
    $fileRows=[];$itemRows=[];$missing=[];$bytes=0;$seen=[];
    $addFile=static function(array $record,string $label)use($zip,$service,&$bytes,&$missing,&$seen){
        $storage=(string)($record['storage_path']??'');
        if($storage==='')return;
        try{
            $path=$service->storedFile($storage);
            $size=(int)filesize($path);
            if($bytes+$size>125*1024*1024)throw new RuntimeException('Limite de documentos do pacote excedido.');
            $bytes+=$size;
            $hash=hash_file('sha256',$path);
            if(isset($record['sha256'])&&$record['sha256']!==''&&!hash_equals((string)$record['sha256'],$hash))
                throw new RuntimeException('Arquivo não corresponde ao hash armazenado.');
            if(isset($seen[$hash]))return;
            $seen[$hash]=true;
            $name=basename(str_replace('\\','/',(string)($record['original_name']??'arquivo')));
            $name=preg_replace('/[^\pL\pN\._ -]/u','_',mb_substr($name,0,90))?:'arquivo';
            $zip->addFile($path,'documentos/'.$label.'-'.substr($hash,0,12).'-'.$name);
        }catch(Throwable $e){
            error_log('Accounting pack skip '.$label.' '.$e->getMessage());
            $missing[]=[$label,(string)($record['original_name']??''),'Arquivo ausente, inacessível ou divergente — conferir'];
        }
    };
    foreach($docs as $d){
        $row=$service->document($d['id']);
        $fileRows[]=[
            $row['id'],$row['reference_period'],$row['document_kind'],$row['supplier_name'],$row['supplier_cnpj'],
            $row['document_number'],$row['access_key'],$row['issued_on'],$row['total'],$row['confirmed_category'],
            $row['status'],$row['finance_entry_id'],$row['original_name'],$row['sha256'],$row['review_note']
        ];
        $items=json_decode((string)($row['item_details_json']??'[]'),true);
        if(is_array($items))foreach($items as $item)$itemRows[]=[$row['id'],$item['name']??'',$item['amount']??'',$item['category']??''];
        $addFile($row,'fiscal');
    }
    foreach($legacy as $row)$addFile($row,'tesouraria');
    $zip->addFromString('documentos-indice.csv',$csv(['ID','Competência','Tipo','Fornecedor','CNPJ','Número','Chave','Emissão','Valor','Categoria','Situação','Lançamento','Arquivo','SHA256','Observação do contador'],$fileRows));
    $zip->addFromString('itens-notas.csv',$csv(['Documento','Produto/serviço','Valor','Categoria sugerida/confirmada'],$itemRows));
    if($missing)$zip->addFromString('documentos-nao-incluidos.csv',$csv(['Origem','Nome','Problema'],$missing));
    if(!$zip->close())throw new RuntimeException('Erro ao finalizar o ZIP.');

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="contabilidade-'.$period.'.zip"');
    header('Content-Length: '.filesize($tmp));
    header('Cache-Control: private,no-store,max-age=0');
    header('X-Content-Type-Options: nosniff');
    readfile($tmp);
    unlink($tmp);
    exit;
}catch(Throwable $e){
    error_log('accounting pack '.$e->getMessage());
    http_response_code(422);
    View::header('Pacote contábil',$auth,'accounting.php');
    echo '<section class="card card-pad"><h1>Pacote indisponível</h1><p>'.e($e instanceof PDOException?'Erro ao consultar os dados.':$e->getMessage()).'</p><a class="btn btn-light" href="/accounting.php">Voltar à contabilidade</a></section>';
    View::footer();
}
