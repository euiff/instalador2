<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/lib/TreasurySmartEntry.php';
require_once __DIR__.'/lib/DepartmentTreasuryService.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
try{
    $user=$auth->requireLogin();
    $church=$auth->currentChurch();
    if(!$church)throw new RuntimeException('Igreja não encontrada.');
    if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);throw new RuntimeException('Use o formulário de novo contato.');}
    verify_csrf();
    $cid=(string)$church['id'];
    if(!$auth->can('finance')){
        if(!$auth->can('department_treasury'))throw new RuntimeException('Sem permissão para cadastro financeiro.');
        $dept=(string)($_POST['department_id']??'');
        $service=new DepartmentTreasuryService($pdo,$auth,$cid,(string)$user['id']);
        if(!$service->mayPost())throw new RuntimeException('Seu perfil não pode lançar despesas.');
        $service->department($dept,true);
    }
    $name=trim((string)($_POST['name']??''));
    $email=trim((string)($_POST['email']??''));
    $phone=trim((string)($_POST['phone']??''));
    if($name===''||mb_strlen($name,'UTF-8')>190)
        throw new InvalidArgumentException('Informe um nome de contato até 190 caracteres.');
    if($email!==''&&(!filter_var($email,FILTER_VALIDATE_EMAIL)||mb_strlen($email,'UTF-8')>190))
        throw new InvalidArgumentException('E-mail do contato inválido.');
    if(mb_strlen($phone,'UTF-8')>40)throw new InvalidArgumentException('Telefone do contato muito longo.');
    $query=$pdo->prepare("SELECT id,active FROM church_registers WHERE church_id=? AND kind='contact' AND name=? LIMIT 1");
    $query->execute([$cid,$name]);$existing=$query->fetch(PDO::FETCH_ASSOC);
    if($existing){
        if(!(bool)$existing['active'])throw new RuntimeException('Esse contato está arquivado. Peça ao financeiro para restaurá-lo.');
        echo json_encode(['id'=>$existing['id'],'name'=>$name,'existing'=>true],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;
    }
    $id=app_uuid();
    $details=json_encode(['phone'=>$phone,'email'=>$email,'document'=>'','responsible'=>'','notes'=>''],
        JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    $pdo->beginTransaction();
    try{
        $q=$pdo->prepare("INSERT INTO church_registers(id,church_id,kind,name,details)
            VALUES(?,?,'contact',?,?)");
        $q->execute([$id,$cid,$name,$details]);
        $audit=$pdo->prepare("INSERT INTO platform_audit_logs(id,user_id,church_id,action,details)
            VALUES(?,?,?,'registry_saved',?)");
        $audit->execute([app_uuid(),$user['id'],$cid,
            json_encode(['kind'=>'contact','id'=>$id,'via'=>'treasury_quick_create'],JSON_THROW_ON_ERROR)]);
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    echo json_encode(['id'=>$id,'name'=>$name,'existing'=>false],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
}catch(Throwable $e){
    if(http_response_code()!==405)http_response_code(422);
    echo json_encode(['error'=>$e instanceof PDOException?'Não foi possível salvar o contato. Confira se já existe nesta igreja.':$e->getMessage()],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
}
