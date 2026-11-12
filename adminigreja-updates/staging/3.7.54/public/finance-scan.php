<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/lib/FinanceFiscalScan.php';
require_once __DIR__.'/lib/FinanceVisionReader.php';

$auth->requireAbility('finance');
$church=$auth->currentChurch();
if(!$church){http_response_code(403);exit('Acesso à igreja não disponível.');}
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
function scanReply(array $data,int $status=200): never {
    http_response_code($status);
    echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);
    exit;
}
if(($_SERVER['REQUEST_METHOD']??'')!=='POST')scanReply(['ok'=>false,'message'=>'Método não permitido.'],405);
try{
    verify_csrf();
    $action=(string)($_POST['action']??'scan');
    if($action==='scan_file'){
        $now=time();
        $recent=array_values(array_filter((array)($_SESSION['fiscal_ai_reads']??[]),
            static fn($ts)=>is_int($ts)&&($now-$ts)<600));
        if(count($recent)>=15)throw new RuntimeException('Limite de 15 leituras em 10 minutos. Aguarde antes de solicitar outra análise.');
        $recent[]=$now;$_SESSION['fiscal_ai_reads']=$recent;
        $mode=(string)($_POST['mode']??'fast');
        if(!in_array($mode,['fast','precise'],true))$mode='fast';
        $file=$_FILES['document_file']??[];
        $q=$pdo->prepare("SELECT name FROM church_registers WHERE church_id=? AND kind='finance_category' AND active=1 ORDER BY name LIMIT 70");
        $q->execute([(string)$church['id']]);
        $categories=$q->fetchAll(PDO::FETCH_ASSOC);
        // Free PHP session lock during the external API request.
        if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
        $fields=FinanceVisionReader::extract($file,$categories,$mode);
        $hash=(int)($file['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_OK
            ?hash_file('sha256',(string)$file['tmp_name']):null;
        $dupeFields=$fields;
        $dupeFields['file_hash']=$hash;
        $duplicates=FinanceFiscalScan::duplicates($pdo,(string)$church['id'],$dupeFields);
        $blocking=(bool)array_filter($duplicates,static fn($r)=>!empty($r['blocking'])||($r['reason']??'')==='Mesmo arquivo já anexado');
        scanReply([
            'ok'=>true,'engine'=>'gemini_vision','mode'=>$mode,
            'fields'=>$fields,'file_hash'=>$hash,
            'duplicates'=>$duplicates,'blocking'=>$blocking,
            'message'=>$blocking?'Documento semelhante já registrado nesta igreja. Revise antes de salvar.':
               'Leitura concluída pela IA. Confira os dados e clique em Registrar lançamento.'
        ]);
    }
    $text=(string)($_POST['ocr_text']??'');
    $action=(string)($_POST['action']??'scan');
    if(!in_array($action,['scan','duplicates'],true))throw new InvalidArgumentException('Operação inválida.');
    if(strlen($text)>150000)throw new InvalidArgumentException('Texto muito longo para analisar.');
    if($action==='scan' && trim($text)==='')
        throw new InvalidArgumentException('Não foi possível ler palavras do cupom. Faça uma foto mais nítida ou preencha manualmente.');
    $fields=$action==='scan'?FinanceFiscalScan::parse($text):[];
    $number=FinanceFiscalScan::cleanDocumentNumber((string)($_POST['document_number']??''));
    if($number)$fields['document_number']=$number;
    $amount=FinanceFiscalScan::money((string)($_POST['amount']??''));
    if($amount!==null)$fields['amount']=$amount;
    $date=(string)($_POST['date']??'');
    if(preg_match('/^\d{4}-\d{2}-\d{2}$/',$date))$fields['date']=$date;
    $issuer=trim((string)($_POST['issuer']??''));
    if($issuer!=='')$fields['issuer']=mb_substr($issuer,0,190);
    $fileHash=strtolower(trim((string)($_POST['file_hash']??'')));
    if(preg_match('/^[0-9a-f]{64}$/D',$fileHash))$fields['file_hash']=$fileHash;
    $duplicates=FinanceFiscalScan::duplicates($pdo,(string)$church['id'],$fields);
    $blocking=(bool)array_filter($duplicates,static fn($r)=>!empty($r['blocking'])||$r['reason']==='Mesmo arquivo já anexado');
    scanReply(['ok'=>true,'fields'=>$fields,'duplicates'=>$duplicates,'blocking'=>$blocking,
        'message'=>$blocking?'Possível documento duplicado: confira o registro anterior.':'Leitura concluída. Confira os campos antes de salvar.']);
}catch(Throwable $e){
    error_log('Finance fiscal scan: '.$e->getMessage());
    scanReply(['ok'=>false,'message'=>$e instanceof PDOException
        ?'Não foi possível conferir os comprovantes no banco de dados.':$e->getMessage()],422);
}
