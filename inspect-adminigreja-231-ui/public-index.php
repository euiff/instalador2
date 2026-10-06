<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
if (!file_exists(dirname(__DIR__) . '/config/database.php')) redirect('/install.php');
require dirname(__DIR__) . '/app/layout.php';


$route = (string)($_GET['r'] ?? 'dashboard');

function br_date(?string $date): string {
    if(!$date)return '—'; $ts=strtotime($date); return $ts?date('d/m/Y',$ts):'—';
}
function money_br(float $v): string { return 'R$ '.number_format($v,2,',','.'); }
function cut_text(string $text,int $width): string { if(function_exists('mb_strimwidth')) return mb_strimwidth($text,0,$width,'…','UTF-8'); return strlen($text)>$width?substr($text,0,max(0,$width-3)).'...':$text; }
function date_long_br(?string $date=null): string { $ts=$date?strtotime($date):time(); $months=[1=>'janeiro',2=>'fevereiro',3=>'março',4=>'abril',5=>'maio',6=>'junho',7=>'julho',8=>'agosto',9=>'setembro',10=>'outubro',11=>'novembro',12=>'dezembro']; return date('d',$ts).' de '.$months[(int)date('n',$ts)].' de '.date('Y',$ts); }
function church_doc_profile(int $cid): array {
    $c=one('SELECT * FROM churches WHERE id=?',[$cid])?:[];
    $leaders=all("SELECT * FROM church_leaders WHERE church_id=? AND active=1 ORDER BY sort_order,id",[$cid]);
    $c['leaders']=$leaders; return $c;
}
function church_leader_name(array $church,array $patterns): string {
    foreach(($church['leaders']??[]) as $l){foreach($patterns as $p){if(stripos((string)$l['role'],$p)!==false)return (string)$l['name'];}}
    return '';
}
function period_values(string $period,string $fromInput='',string $toInput=''): array {
    if(!in_array($period,['week','month','custom'],true))$period='month'; $today=new DateTimeImmutable('today');
    if($period==='week'){$from=$today->modify('monday this week')->format('Y-m-d');$to=$today->modify('sunday this week')->format('Y-m-d');$label='Relatório semanal';}
    elseif($period==='month'){$from=$today->format('Y-m-01');$to=$today->format('Y-m-t');$label='Relatório mensal';}
    else{$from=$fromInput?:$today->format('Y-m-01');$to=$toInput?:$today->format('Y-m-d');$label='Relatório personalizado';}
    if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$from))$from=$today->format('Y-m-01');
    if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$to))$to=$today->format('Y-m-d');
    if($from>$to){$x=$from;$from=$to;$to=$x;} return [$period,$from,$to,$label];
}

if ($route === 'login') {
    if (is_logged()) redirect(is_master() ? '/?r=master' : '/?r=dashboard');
    $err = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        if (login_user((string)($_POST['email'] ?? ''), (string)($_POST['password'] ?? ''))) {
            redirect(is_master() ? '/?r=master' : '/?r=dashboard');
        }
        $err = 'E-mail/senha inválidos ou acesso temporariamente bloqueado.';
    }
    echo '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Entrar - AdminIgreja</title><link rel="stylesheet" href="/assets/app.css"></head><body class="login"><div class="login-card"><h1>AdminIgreja</h1><p>Gestão administrativa segura</p>';
    if (!empty($_GET['installed'])) echo '<div class="alert ok">Instalação concluída. Entre com seu usuário MASTER.</div>';
    if ($err) echo '<div class="alert error">'.e($err).'</div>';
    echo '<form method="post">'.csrf_input().'<div class="field"><label>E-mail</label><input type="email" name="email" autocomplete="username" required></div><div class="field"><label>Senha</label><input type="password" name="password" autocomplete="current-password" required></div><button class="btn" type="submit">Entrar</button></form></div></body></html>';
    exit;
}

if ($route === 'logout') {
    require_login();
    logout_user();
    redirect('/?r=login');
}

require_login();

if ($route === 'master') {
    require_roles(['master']);
    unset($_SESSION['support_church_id']);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'create') {
            $name = trim((string)($_POST['name'] ?? ''));
            $email = strtolower(trim((string)($_POST['email'] ?? '')));
            $pastor = trim((string)($_POST['pastor'] ?? ''));
            $pass = (string)($_POST['password'] ?? '');
            if ($name && filter_var($email, FILTER_VALIDATE_EMAIL) && $pastor && strlen($pass) >= 10) {
                db()->beginTransaction();
                try {
                    q("INSERT INTO churches(name,plan,monthly_fee,due_date,max_users,status) VALUES(?,?,?,?,?,'active')", [
                        $name, trim((string)($_POST['plan'] ?? 'Padrão')), (float)($_POST['monthly_fee'] ?? 0),
                        ($_POST['due_date'] ?? '') ?: null, max(1, (int)($_POST['max_users'] ?? 10))
                    ]);
                    $newChurchId = (int)db()->lastInsertId();
                    q("INSERT INTO users(church_id,name,email,password_hash,role,active) VALUES(?,?,?,?, 'pastor',1)", [
                        $newChurchId, $pastor, $email, password_make($pass)
                    ]);
                    q("INSERT INTO accounts(church_id,name,account_type,opening_balance) VALUES(?,?,?,0)", [$newChurchId,'Caixa Geral','cash']);
                    db()->commit();
                    audit('create_church','church',$newChurchId);
                    set_flash('Igreja criada com Pastor / Administrador.');
                } catch (Throwable $e) {
                    if (db()->inTransaction()) db()->rollBack();
                    error_log('create_church: '.$e->getMessage());
                    set_flash('Não foi possível criar a igreja. Verifique se o e-mail já está em uso.','error');
                }
            } else {
                set_flash('Preencha corretamente os dados da igreja e do pastor.','error');
            }
        } elseif ($action === 'support') {
            $_SESSION['support_church_id'] = (int)($_POST['church_id'] ?? 0);
            redirect('/?r=dashboard');
        } elseif ($action === 'toggle') {
            $targetChurch = (int)($_POST['church_id'] ?? 0);
            $c = one('SELECT status FROM churches WHERE id=?',[$targetChurch]);
            if ($c) {
                $new = $c['status'] === 'active' ? 'suspended' : 'active';
                q('UPDATE churches SET status=? WHERE id=?',[$new,$targetChurch]);
                audit('toggle_church','church',$targetChurch,['status'=>$new]);
                set_flash('Status atualizado.');
            }
        }
        redirect('/?r=master');
    }

    $churches = all("SELECT c.*, (SELECT COUNT(*) FROM users u WHERE u.church_id=c.id) users_count, (SELECT COUNT(*) FROM members m WHERE m.church_id=c.id) members_count FROM churches c ORDER BY c.id DESC");
    page_top('Painel MASTER','Igrejas, acessos e planos');
    flash();
    echo '<div class="toolbar"><span></span><button class="btn" onclick="document.getElementById(\'newChurch\').hidden=false">+ Nova igreja</button></div>';
    echo '<div id="newChurch" class="card" hidden><h3>Nova igreja</h3><form method="post">'.csrf_input().'<input type="hidden" name="action" value="create"><div class="form-grid"><div><label>Igreja</label><input name="name" required></div><div><label>Plano</label><input name="plan" value="Padrão" required></div><div><label>Mensalidade</label><input name="monthly_fee" type="number" step="0.01" value="0"></div><div><label>Vencimento</label><input name="due_date" type="date"></div><div><label>Limite de usuários</label><input name="max_users" type="number" value="10" min="1"></div><div></div><div><label>Pastor / Administrador</label><input name="pastor" required></div><div><label>E-mail do pastor</label><input name="email" type="email" required></div><div class="full"><label>Senha inicial do pastor</label><input name="password" type="password" minlength="10" required></div></div><p><button class="btn">Criar igreja e pastor</button></p></form></div><br>';
    echo '<div class="card"><div class="table-wrap"><table class="table"><thead><tr><th>IGREJA</th><th>PLANO</th><th>VALOR</th><th>STATUS</th><th>USUÁRIOS</th><th>MEMBROS</th><th>AÇÕES</th></tr></thead><tbody>';
    foreach ($churches as $c) {
        echo '<tr><td><b>'.e($c['name']).'</b></td><td>'.e($c['plan']).'</td><td>R$ '.number_format((float)$c['monthly_fee'],2,',','.').'</td><td><span class="pill">'.e($c['status']==='active'?'Ativa':'Suspensa').'</span></td><td>'.(int)$c['users_count'].'</td><td>'.(int)$c['members_count'].'</td><td>';
        echo '<form style="display:inline" method="post">'.csrf_input().'<input type="hidden" name="action" value="support"><input type="hidden" name="church_id" value="'.(int)$c['id'].'"><button class="btn light">Acessar</button></form> ';
        echo '<form style="display:inline" method="post">'.csrf_input().'<input type="hidden" name="action" value="toggle"><input type="hidden" name="church_id" value="'.(int)$c['id'].'"><button class="btn danger">'.($c['status']==='active'?'Suspender':'Liberar').'</button></form></td></tr>';
    }
    echo '</tbody></table></div></div>';
    page_bottom();
    exit;
}

if ($route === 'updates') {
    require_roles(['master']);
    $check = $_SESSION['update_check'] ?? null;
    unset($_SESSION['update_check']);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $action = (string)($_POST['action'] ?? '');
        try {
            if ($action === 'check') {
                $_SESSION['update_check'] = updater_check();
                redirect('/?r=updates');
            } elseif ($action === 'apply') {
                $result = updater_apply();
                set_flash($result['updated'] ? 'Sistema atualizado para a versão '.$result['version'].'. Backup criado antes da troca.' : 'Você já está na versão mais recente.');
            }
        } catch (Throwable $e) {
            error_log('updater: '.$e->getMessage());
            set_flash($e->getMessage(),'error');
        }
        redirect('/?r=updates');
    }

    page_top('Atualizações','Atualização automática pronta · sem token, Terminal ou SSH');
    flash();
    echo '<div class="grid update-grid"><div class="card metric"><small>Versão instalada</small><strong>'.e(app_version()).'</strong></div><div class="card metric"><small>Origem</small><strong class="repo-name">'.e(UPDATE_REPO_LABEL).'</strong></div><div class="card metric"><small>Canal de atualização</small><strong class="money in">Pronto</strong></div></div><br>';
    echo '<div class="card"><h3>Atualização automática</h3><p>Não é necessário token, Terminal ou SSH. O sistema consulta o canal oficial de versões, valida SHA-256 de cada pacote e cria um backup antes de substituir arquivos. Banco MySQL, configurações e comprovantes não são substituídos.</p><div class="action-row"><form method="post">'.csrf_input().'<input type="hidden" name="action" value="check"><button class="btn light">Verificar atualização</button></form><form method="post" onsubmit="return confirm(\'Atualizar o sistema agora? Um backup dos arquivos atuais será criado antes da troca.\')">'.csrf_input().'<input type="hidden" name="action" value="apply"><button class="btn">Atualizar agora</button></form></div></div>';
    if (is_array($check)) {
        $msg = $check['available'] ? 'Há uma atualização disponível.' : 'Seu sistema está atualizado.';
        echo '<br><div class="alert ok"><b>Verificação concluída:</b> instalada '.e($check['local']).' · disponível '.e($check['remote']).'. '.e($msg).'</div>';
    }
    page_bottom();
    exit;
}

$cid = require_church();

if ($route === 'private-image') {
    $type=(string)($_GET['type']??''); $id=(int)($_GET['id']??0); $f=null;
    if($type==='logo'){$f=logo_file_for_church($cid);}
    elseif($type==='member-photo'&&can_secretary()){$f=photo_file_for_member($cid,$id);}
    if(!$f){http_response_code(404);exit('Imagem não encontrada.');}
    header('Content-Type: '.((string)($f['logo_mime']??$f['photo_mime']??'image/jpeg')));
    header('Content-Length: '.filesize((string)$f['full_path'])); header('Cache-Control: private, max-age=300'); header('X-Content-Type-Options: nosniff');
    readfile((string)$f['full_path']); exit;
}

if ($route === 'church-settings') {
    if(!can_manage_users()){http_response_code(403);exit('Acesso restrito ao Pastor / Administrador.');}
    if($_SERVER['REQUEST_METHOD']==='POST'){
        verify_csrf(); $action=(string)($_POST['action']??'save_profile');
        try{
            if($action==='save_profile'){
                $logo=null; if(isset($_FILES['logo']))$logo=save_logo_upload($cid,$_FILES['logo']);
                $params=[trim((string)$_POST['name']),trim((string)($_POST['legal_name']??''))?:null,trim((string)($_POST['document']??''))?:null,trim((string)($_POST['email']??''))?:null,trim((string)($_POST['phone']??''))?:null,trim((string)($_POST['address']??''))?:null,trim((string)($_POST['city']??''))?:null,strtoupper(substr(trim((string)($_POST['state']??'')),0,2))?:null,trim((string)($_POST['zip_code']??''))?:null,trim((string)($_POST['website']??''))?:null,($_POST['founded_at']??'')?:null];
                $sql='UPDATE churches SET name=?,legal_name=?,document=?,email=?,phone=?,address=?,city=?,state=?,zip_code=?,website=?,founded_at=?';
                if($logo){$sql.=',logo_path=?,logo_mime=?,logo_name=?';$params[]=$logo['path'];$params[]=$logo['mime'];$params[]=$logo['name'];}
                $sql.=' WHERE id=?';$params[]=$cid;q($sql,$params);audit('update_church_profile','church',$cid);set_flash('Dados institucionais atualizados.');
            } elseif($action==='add_leader'){
                $name=trim((string)($_POST['leader_name']??''));$roleName=trim((string)($_POST['leader_role']??''));
                if($name===''||$roleName==='')throw new RuntimeException('Informe nome e função da liderança.');
                q('INSERT INTO church_leaders(church_id,name,role,phone,email,sort_order) VALUES(?,?,?,?,?,?)',[$cid,$name,$roleName,trim((string)($_POST['leader_phone']??''))?:null,trim((string)($_POST['leader_email']??''))?:null,(int)($_POST['sort_order']??0)]);
                audit('create_leader','church_leader',(int)db()->lastInsertId());set_flash('Liderança adicionada.');
            } elseif($action==='delete_leader'){
                $lid=(int)($_POST['leader_id']??0);q('DELETE FROM church_leaders WHERE id=? AND church_id=?',[$lid,$cid]);audit('delete_leader','church_leader',$lid);set_flash('Liderança removida.');
            }
        }catch(Throwable $e){error_log('church-settings: '.$e->getMessage());set_flash($e->getMessage(),'error');}
        redirect('/?r=church-settings');
    }
    $c=church_doc_profile($cid);page_top('Dados da igreja','Informações institucionais usadas em relatórios, carteirinhas, certificados e cartas');flash();
    echo '<div class="settings-grid"><div class="card"><h3>Identificação da igreja</h3><form method="post" enctype="multipart/form-data">'.csrf_input().'<input type="hidden" name="action" value="save_profile"><div class="form-grid">';
    echo '<div><label>Nome da igreja</label><input name="name" value="'.e($c['name']??'').'" required></div><div><label>Razão / nome oficial <span class="optional">(opcional)</span></label><input name="legal_name" value="'.e($c['legal_name']??'').'"></div>';
    echo '<div><label>CNPJ / documento</label><input name="document" value="'.e($c['document']??'').'"></div><div><label>Data de fundação</label><input type="date" name="founded_at" value="'.e($c['founded_at']??'').'"></div>';
    echo '<div><label>E-mail</label><input type="email" name="email" value="'.e($c['email']??'').'"></div><div><label>Telefone</label><input name="phone" value="'.e($c['phone']??'').'"></div>';
    echo '<div class="full"><label>Endereço</label><input name="address" value="'.e($c['address']??'').'"></div><div><label>Cidade</label><input name="city" value="'.e($c['city']??'').'"></div><div><label>UF</label><input maxlength="2" name="state" value="'.e($c['state']??'').'"></div><div><label>CEP</label><input name="zip_code" value="'.e($c['zip_code']??'').'"></div><div><label>Site</label><input name="website" value="'.e($c['website']??'').'"></div>';
    echo '<div class="full"><label>Logo da igreja</label><input type="file" name="logo" accept="image/jpeg,image/png,image/webp"><small class="hint">JPG, PNG ou WEBP, até 3 MB. A logo aparece nos PDFs e documentos.</small></div></div>';
    if(!empty($c['logo_path']))echo '<div class="logo-preview"><img src="/?r=private-image&type=logo" alt="Logo"></div>';
    echo '<p><button class="btn">Salvar dados da igreja</button></p></form></div>';
    echo '<div class="card"><h3>Pastores, presidência e dirigentes</h3><p class="muted">Cadastre a administração da igreja. Essas informações são usadas automaticamente nos documentos.</p><form method="post">'.csrf_input().'<input type="hidden" name="action" value="add_leader"><div class="form-grid"><div><label>Nome</label><input name="leader_name" required></div><div><label>Função</label><select name="leader_role"><option>Pastor Presidente</option><option>Presidente</option><option>Vice-Presidente</option><option>Dirigente</option><option>Pastor</option><option>Secretário(a)</option><option>Tesoureiro(a)</option><option>Diácono(a)</option><option>Outro</option></select></div><div><label>Telefone</label><input name="leader_phone"></div><div><label>E-mail</label><input type="email" name="leader_email"></div></div><p><button class="btn">Adicionar à administração</button></p></form><div class="leader-list">';
    foreach(($c['leaders']??[]) as $l){echo '<div class="leader-row"><div><b>'.e($l['name']).'</b><small>'.e($l['role']).'</small></div><form method="post" onsubmit="return confirm(\'Remover esta pessoa da administração?\')">'.csrf_input().'<input type="hidden" name="action" value="delete_leader"><input type="hidden" name="leader_id" value="'.(int)$l['id'].'"><button class="btn danger">Remover</button></form></div>';}
    if(empty($c['leaders']))echo '<p class="muted">Nenhuma liderança cadastrada ainda.</p>';echo '</div></div></div>';page_bottom();exit;
}

if ($route === 'receipt') {
    if (!can_treasury()) { http_response_code(403); exit('Acesso restrito à Tesouraria.'); }
    $id = (int)($_GET['id'] ?? 0);
    $f = receipt_file_for_transaction($cid,$id);
    if (!$f) { http_response_code(404); exit('Comprovante não encontrado.'); }
    audit('view_receipt','transaction',$id);
    header('Content-Type: '.((string)$f['receipt_mime'] ?: 'application/octet-stream'));
    header('Content-Length: '.filesize((string)$f['full_path']));
    header('Content-Disposition: inline; filename="'.str_replace(['"',"\r","\n"],'',(string)($f['receipt_name'] ?: 'comprovante')).'"');
    header('X-Content-Type-Options: nosniff');
    readfile((string)$f['full_path']);
    exit;
}

if ($route === 'dashboard') {
    $members = (int)(one("SELECT COUNT(*) c FROM members WHERE church_id=? AND status='active'",[$cid])['c']??0);
    $visitors = (int)(one('SELECT COUNT(*) c FROM visitors WHERE church_id=?',[$cid])['c']??0);
    $inc = (float)(one("SELECT COALESCE(SUM(amount),0) s FROM transactions WHERE church_id=? AND direction='income' AND status='posted' AND MONTH(transaction_date)=MONTH(CURDATE()) AND YEAR(transaction_date)=YEAR(CURDATE())",[$cid])['s']??0);
    $exp = (float)(one("SELECT COALESCE(SUM(amount),0) s FROM transactions WHERE church_id=? AND direction='expense' AND status='posted' AND MONTH(transaction_date)=MONTH(CURDATE()) AND YEAR(transaction_date)=YEAR(CURDATE())",[$cid])['s']??0);
    page_top('Painel','Resumo da igreja'); flash();
    echo '<div class="grid"><div class="card metric"><small>Membros ativos</small><strong>'.$members.'</strong></div><div class="card metric"><small>Visitantes</small><strong>'.$visitors.'</strong></div><div class="card metric"><small>Entradas no mês</small><strong class="money in">R$ '.number_format($inc,2,',','.').'</strong></div><div class="card metric"><small>Saídas no mês</small><strong class="money out">R$ '.number_format($exp,2,',','.').'</strong></div></div>';
    page_bottom(); exit;
}

if ($route === 'members') {
    if(!can_secretary()){http_response_code(403);exit('Acesso restrito à Secretaria.');}
    $editId=(int)($_GET['edit']??0); $editing=$editId?one('SELECT * FROM members WHERE id=? AND church_id=?',[$editId,$cid]):null;
    if($_SERVER['REQUEST_METHOD']==='POST'){
        verify_csrf();$id=(int)($_POST['member_id']??0);$existing=$id?one('SELECT * FROM members WHERE id=? AND church_id=?',[$id,$cid]):null;
        $status=in_array((string)($_POST['status']??'active'),['active','inactive'],true)?(string)$_POST['status']:'active';
        $type=in_array((string)($_POST['member_type']??'member'),['member','congregant'],true)?(string)$_POST['member_type']:'member';
        $photo=null;
        try{
            if(isset($_FILES['photo']))$photo=save_member_photo_upload($cid,$_FILES['photo']);
            $vals=[(int)($_POST['family_id']??0)?:null,trim((string)$_POST['name']),trim((string)($_POST['membership_number']??''))?:null,$type,trim((string)($_POST['email']??''))?:null,trim((string)($_POST['phone']??''))?:null,($_POST['birth_date']??'')?:null,trim((string)($_POST['gender']??''))?:null,trim((string)($_POST['marital_status']??''))?:null,secure_encrypt(trim((string)($_POST['cpf']??''))?:null),secure_encrypt(trim((string)($_POST['rg']??''))?:null),trim((string)($_POST['address']??''))?:null,trim((string)($_POST['city']??''))?:null,strtoupper(substr(trim((string)($_POST['state']??'')),0,2))?:null,trim((string)($_POST['zip_code']??''))?:null,trim((string)($_POST['occupation']??''))?:null,trim((string)($_POST['father_name']??''))?:null,trim((string)($_POST['mother_name']??''))?:null,trim((string)($_POST['spouse_name']??''))?:null,($_POST['conversion_date']??'')?:null,($_POST['baptism_date']??'')?:null,($_POST['admission_date']??'')?:null,trim((string)($_POST['ministry']??''))?:null,trim((string)($_POST['emergency_name']??''))?:null,trim((string)($_POST['emergency_phone']??''))?:null,$status,secure_encrypt(trim((string)($_POST['notes']??''))?:null)];
            if($existing){
                $sql='UPDATE members SET family_id=?,name=?,membership_number=?,member_type=?,email=?,phone=?,birth_date=?,gender=?,marital_status=?,cpf_enc=?,rg_enc=?,address=?,city=?,state=?,zip_code=?,occupation=?,father_name=?,mother_name=?,spouse_name=?,conversion_date=?,baptism_date=?,admission_date=?,ministry=?,emergency_name=?,emergency_phone=?,status=?,notes_enc=?';
                if($photo){$sql.=',photo_path=?,photo_mime=?,photo_name=?';$vals[]=$photo['path'];$vals[]=$photo['mime'];$vals[]=$photo['name'];}
                $sql.=' WHERE id=? AND church_id=?';$vals[]=$id;$vals[]=$cid;q($sql,$vals);audit('update_member','member',$id);set_flash('Cadastro do membro atualizado.');
            }else{
                $sql='INSERT INTO members(church_id,family_id,name,membership_number,member_type,email,phone,birth_date,gender,marital_status,cpf_enc,rg_enc,address,city,state,zip_code,occupation,father_name,mother_name,spouse_name,conversion_date,baptism_date,admission_date,ministry,emergency_name,emergency_phone,status,notes_enc'.($photo?',photo_path,photo_mime,photo_name':'').') VALUES('.implode(',',array_fill(0,28+($photo?3:0),'?')).')';
                $insert=[$cid,...$vals]; if($photo){$insert[]=$photo['path'];$insert[]=$photo['mime'];$insert[]=$photo['name'];}
                q($sql,$insert);$id=(int)db()->lastInsertId();if(trim((string)($_POST['membership_number']??''))==='')q('UPDATE members SET membership_number=? WHERE id=?',[str_pad((string)$id,6,'0',STR_PAD_LEFT),$id]);audit('create_member','member',$id);set_flash('Membro cadastrado com sucesso.');
            }
        }catch(Throwable $e){if($photo&&!empty($photo['path']))@unlink(dirname(__DIR__).'/'.$photo['path']);error_log('member: '.$e->getMessage());set_flash('Não foi possível salvar o membro: '.$e->getMessage(),'error');}
        redirect('/?r=members'.($id?'&edit='.$id:''));
    }
    $families=all('SELECT id,name FROM families WHERE church_id=? ORDER BY name',[$cid]);$rows=all('SELECT id,membership_number,name,member_type,phone,birth_date,baptism_date,ministry,status FROM members WHERE church_id=? ORDER BY name LIMIT 500',[$cid]);
    $m=$editing?:[];$cpf=$editing?secure_decrypt($editing['cpf_enc']??null):'';$rg=$editing?secure_decrypt($editing['rg_enc']??null):'';$notes=$editing?secure_decrypt($editing['notes_enc']??null):'';
    page_top('Membros','Cadastro completo para secretaria, carteirinhas, certificados e relatórios');flash();
    echo '<div class="card"><div class="card-title-row"><div><h3>'.($editing?'Editar membro':'Novo membro').'</h3><p class="muted">Os dados pessoais ficam restritos à Secretaria/Pastor. CPF, RG e observações são armazenados criptografados.</p></div>'.($editing?'<a class="btn light" href="/?r=members">+ Novo</a>':'').'</div><form method="post" enctype="multipart/form-data">'.csrf_input().'<input type="hidden" name="member_id" value="'.(int)($m['id']??0).'"><div class="form-grid">';
    echo '<div><label>Nome completo</label><input name="name" required value="'.e($m['name']??'').'"></div><div><label>Nº de membro / matrícula</label><input name="membership_number" value="'.e($m['membership_number']??'').'" placeholder="Automático se deixar vazio"></div><div><label>Tipo</label><select name="member_type"><option value="member" '.(($m['member_type']??'member')==='member'?'selected':'').'>Membro</option><option value="congregant" '.(($m['member_type']??'')==='congregant'?'selected':'').'>Congregado</option></select></div><div><label>Status</label><select name="status"><option value="active" '.(($m['status']??'active')==='active'?'selected':'').'>Ativo</option><option value="inactive" '.(($m['status']??'')==='inactive'?'selected':'').'>Inativo</option></select></div>';
    echo '<div><label>Telefone</label><input name="phone" value="'.e($m['phone']??'').'"></div><div><label>E-mail</label><input type="email" name="email" value="'.e($m['email']??'').'"></div><div><label>Nascimento</label><input type="date" name="birth_date" value="'.e($m['birth_date']??'').'"></div><div><label>Sexo / gênero</label><input name="gender" value="'.e($m['gender']??'').'" placeholder="Ex.: Masculino, Feminino"></div><div><label>Estado civil</label><input name="marital_status" value="'.e($m['marital_status']??'').'"></div><div><label>Família</label><select name="family_id"><option value="">Sem vínculo</option>';foreach($families as $f)echo '<option value="'.(int)$f['id'].'" '.((int)($m['family_id']??0)===(int)$f['id']?'selected':'').'>'.e($f['name']).'</option>';echo '</select></div>';
    echo '<div><label>CPF</label><input name="cpf" value="'.e($cpf).'"></div><div><label>RG</label><input name="rg" value="'.e($rg).'"></div><div class="full"><label>Endereço</label><input name="address" value="'.e($m['address']??'').'"></div><div><label>Cidade</label><input name="city" value="'.e($m['city']??'').'"></div><div><label>UF</label><input maxlength="2" name="state" value="'.e($m['state']??'').'"></div><div><label>CEP</label><input name="zip_code" value="'.e($m['zip_code']??'').'"></div><div><label>Profissão</label><input name="occupation" value="'.e($m['occupation']??'').'"></div>';
    echo '<div><label>Nome do pai</label><input name="father_name" value="'.e($m['father_name']??'').'"></div><div><label>Nome da mãe</label><input name="mother_name" value="'.e($m['mother_name']??'').'"></div><div><label>Cônjuge</label><input name="spouse_name" value="'.e($m['spouse_name']??'').'"></div><div><label>Data de conversão</label><input type="date" name="conversion_date" value="'.e($m['conversion_date']??'').'"></div><div><label>Data do batismo</label><input type="date" name="baptism_date" value="'.e($m['baptism_date']??'').'"></div><div><label>Data de admissão</label><input type="date" name="admission_date" value="'.e($m['admission_date']??'').'"></div><div><label>Ministério / função</label><input name="ministry" value="'.e($m['ministry']??'').'"></div><div><label>Contato de emergência</label><input name="emergency_name" value="'.e($m['emergency_name']??'').'"></div><div><label>Telefone de emergência</label><input name="emergency_phone" value="'.e($m['emergency_phone']??'').'"></div><div><label>Foto do membro</label><input type="file" name="photo" accept="image/jpeg,image/png,image/webp"></div><div class="full"><label>Observações internas</label><textarea name="notes">'.e($notes).'</textarea></div></div>';
    if(!empty($m['photo_path']))echo '<div class="member-photo-preview"><img src="/?r=private-image&type=member-photo&id='.(int)$m['id'].'" alt="Foto do membro"></div>';
    echo '<p><button class="btn">'.($editing?'Salvar alterações':'Cadastrar membro').'</button></p></form></div><br>';
    echo '<div class="card"><div class="card-title-row"><h3>Lista de membros</h3><a class="btn green" href="/?r=documents">Gerar documentos</a></div><div class="table-wrap"><table class="table"><thead><tr><th>MATRÍCULA</th><th>NOME</th><th>TIPO</th><th>TELEFONE</th><th>BATISMO</th><th>MINISTÉRIO</th><th>STATUS</th><th></th></tr></thead><tbody>';
    foreach($rows as $r)echo '<tr><td>'.e($r['membership_number']??'').'</td><td><b>'.e($r['name']).'</b></td><td>'.e(($r['member_type']??'member')==='member'?'Membro':'Congregado').'</td><td>'.e($r['phone']??'').'</td><td>'.e(br_date($r['baptism_date']??null)).'</td><td>'.e($r['ministry']??'').'</td><td><span class="pill">'.e($r['status']==='active'?'Ativo':'Inativo').'</span></td><td><a class="btn light" href="/?r=members&edit='.(int)$r['id'].'">Editar</a></td></tr>';
    echo '</tbody></table></div></div>';page_bottom();exit;
}

if ($route === 'documents') {
    if(!can_secretary()){http_response_code(403);exit('Acesso restrito à Secretaria.');}
    $members=all("SELECT id,name,membership_number,baptism_date,admission_date FROM members WHERE church_id=? AND status='active' ORDER BY name",[$cid]);
    $history=all('SELECT gd.id,gd.doc_type,gd.title,gd.created_at,m.name member_name,u.name user_name FROM generated_documents gd LEFT JOIN members m ON m.id=gd.member_id LEFT JOIN users u ON u.id=gd.generated_by WHERE gd.church_id=? ORDER BY gd.id DESC LIMIT 50',[$cid]);
    page_top('Gerar documentos','Carteirinhas, certificados, declarações e cartas com os dados oficiais da igreja');flash();
    echo '<div class="documents-grid"><div class="card"><h3>Novo documento</h3><form method="post" action="/?r=document-pdf" target="_blank">'.csrf_input().'<div class="form-grid"><div><label>Tipo de documento</label><select name="doc_type" id="docType"><option value="member_card">Carteirinha de membro</option><option value="baptism_certificate">Certificado de batismo</option><option value="course_certificate">Certificado de curso</option><option value="member_declaration">Declaração de membro</option><option value="participation_declaration">Declaração de participação</option><option value="recommendation_letter">Carta de recomendação</option><option value="transfer_letter">Carta de transferência</option><option value="presentation_letter">Carta de apresentação</option></select></div><div><label>Membro</label><select name="member_id" required><option value="">Selecione...</option>';foreach($members as $m)echo '<option value="'.(int)$m['id'].'">'.e($m['name']).' · '.e($m['membership_number']??'').'</option>';echo '</select></div><div class="card-field"><label>Validade da carteirinha</label><input type="date" name="card_validity" value="'.e(date('Y-m-d',strtotime('+2 years'))).'"><small class="hint">Você pode alterar antes de gerar.</small></div><div class="course-field"><label>Nome do curso</label><input name="course_name" placeholder="Ex.: Curso de Liderança"></div><div class="course-field"><label>Carga horária</label><input name="course_hours" placeholder="Ex.: 20 horas"></div><div class="course-field"><label>Data de conclusão</label><input type="date" name="course_date"></div><div class="letter-field"><label>Destino / igreja destinatária</label><input name="destination" placeholder="Opcional"></div><div class="full"><label>Complemento / observação</label><textarea name="custom_text" placeholder="Informação adicional que deve aparecer no documento, se necessário."></textarea></div></div><div class="action-row document-actions"><button class="btn" name="pdf_mode" value="inline">Imprimir documento</button><button class="btn light" name="pdf_mode" value="download">Baixar PDF</button></div></form></div>';
    echo '<div class="card document-help"><h3>Documentos disponíveis</h3><div class="doc-type-list"><div><b>Carteirinha de membro</b><small>Nome, matrícula, foto, nascimento, admissão e ministério.</small></div><div><b>Certificados</b><small>Batismo e cursos realizados pela igreja.</small></div><div><b>Declarações</b><small>Vínculo como membro e participação em atividades.</small></div><div><b>Cartas</b><small>Recomendação, transferência e apresentação.</small></div></div><p class="hint">A logo, endereço, CNPJ e nomes da administração vêm de <b>Administração → Dados da igreja</b>.</p></div></div>';
    echo '<script>(function(){const s=document.getElementById("docType"),cf=document.querySelectorAll(".course-field"),lf=document.querySelectorAll(".letter-field"),card=document.querySelectorAll(".card-field");function sync(){cf.forEach(x=>x.style.display=s.value==="course_certificate"?"block":"none");lf.forEach(x=>x.style.display=["recommendation_letter","transfer_letter","presentation_letter"].includes(s.value)?"block":"none");card.forEach(x=>x.style.display=s.value==="member_card"?"block":"none");}s.addEventListener("change",sync);sync();})();</script>';
    echo '<br><div class="card"><h3>Documentos gerados recentemente</h3><div class="table-wrap"><table class="table"><thead><tr><th>DATA</th><th>DOCUMENTO</th><th>MEMBRO</th><th>GERADO POR</th></tr></thead><tbody>';if(!$history)echo '<tr><td colspan="4">Nenhum documento gerado ainda.</td></tr>';foreach($history as $h)echo '<tr><td>'.e(date('d/m/Y H:i',strtotime((string)$h['created_at']))).'</td><td>'.e($h['title']).'</td><td>'.e($h['member_name']??'').'</td><td>'.e($h['user_name']??'').'</td></tr>';echo '</tbody></table></div></div>';page_bottom();exit;
}

if (in_array($route,['families','visitors','events'],true)) {
    if (!can_secretary()) { http_response_code(403); exit('Acesso restrito à Secretaria.'); }
    $cfg = [
        'families'=>['Famílias','families',['name'=>'Nome da família','phone'=>'Telefone','address'=>'Endereço']],
        'visitors'=>['Visitantes','visitors',['name'=>'Nome','phone'=>'Telefone','email'=>'E-mail','first_visit'=>'Primeira visita']],
        'events'=>['Cultos / Eventos','events',['title'=>'Título','event_date'=>'Data e hora','attendance'=>'Presença']],
    ][$route];
    [$title,$table,$fields]=$cfg;
    if($_SERVER['REQUEST_METHOD']==='POST'){verify_csrf();$cols=['church_id'];$vals=[$cid];$marks=['?'];foreach($fields as $k=>$label){$cols[]=$k;$vals[]=($_POST[$k]??'')!==''?$_POST[$k]:null;$marks[]='?';}q('INSERT INTO '.$table.'('.implode(',',$cols).') VALUES('.implode(',',$marks).')',$vals);audit('create_'.$route,$table,(int)db()->lastInsertId());set_flash($title.' cadastrado(a).');redirect('/?r='.$route);}
    $rows=all('SELECT * FROM '.$table.' WHERE church_id=? ORDER BY id DESC LIMIT 300',[$cid]);page_top($title,'Secretaria');flash();echo '<div class="card"><h3>Novo cadastro</h3><form method="post">'.csrf_input().'<div class="form-grid">';
    foreach($fields as $k=>$label){$type=strpos($k,'date')!==false?'date':($k==='event_date'?'datetime-local':($k==='email'?'email':($k==='attendance'?'number':'text')));echo '<div><label>'.e($label).'</label><input type="'.$type.'" name="'.e($k).'" '.(in_array($k,['name','title'],true)?'required':'').'></div>';}
    echo '</div><p><button class="btn">Salvar</button></p></form></div><br><div class="card"><div class="table-wrap"><table class="table"><thead><tr><th>ID</th>';foreach($fields as $label)echo '<th>'.e(strtoupper($label)).'</th>';echo '</tr></thead><tbody>';foreach($rows as $row){echo '<tr><td>'.(int)$row['id'].'</td>';foreach($fields as $k=>$label)echo '<td>'.e($row[$k]??'').'</td>';echo '</tr>';}echo '</tbody></table></div></div>';page_bottom();exit;
}


if ($route === 'document-pdf') {
    if(!can_secretary()){http_response_code(403);exit('Acesso restrito à Secretaria.');}
    if($_SERVER['REQUEST_METHOD']!=='POST'){redirect('/?r=documents');}
    verify_csrf(); require_once dirname(__DIR__).'/app/pdf.php';
    $docType=(string)($_POST['doc_type']??'member_card');$memberId=(int)($_POST['member_id']??0);$member=one('SELECT * FROM members WHERE id=? AND church_id=?',[$memberId,$cid]);
    if(!$member){http_response_code(404);exit('Membro não encontrado.');}
    $church=church_doc_profile($cid);$pastor=church_leader_name($church,['Pastor Presidente','Presidente','Pastor']);$secretary=church_leader_name($church,['Secretário','Secretaria']);$leader=$pastor?:church_leader_name($church,['Dirigente']);
    $logo=logo_file_for_church($cid);$photo=photo_file_for_member($cid,$memberId);$pdf=new SimplePdf();
    $name=(string)$member['name'];$churchName=(string)($church['name']??'Igreja');$city=(string)($church['city']??'');$state=(string)($church['state']??'');$place=trim($city.($state?' - '.$state:''));
    $footer=trim(implode(' · ',array_filter([(string)($church['address']??''),$place,(string)($church['phone']??''),(string)($church['email']??'')])));
    $courseName=trim((string)($_POST['course_name']??''));$courseHours=trim((string)($_POST['course_hours']??''));$courseDate=(string)($_POST['course_date']??'');$destination=trim((string)($_POST['destination']??''));$custom=trim((string)($_POST['custom_text']??''));$cardValidity=(string)($_POST['card_validity']??'');if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$cardValidity))$cardValidity=date('Y-m-d',strtotime('+2 years'));$pdfMode=((string)($_POST['pdf_mode']??'inline'))==='download'?'attachment':'inline';
    $titles=['member_card'=>'Carteirinha de membro','baptism_certificate'=>'Certificado de batismo','course_certificate'=>'Certificado de curso','member_declaration'=>'Declaração de membro','participation_declaration'=>'Declaração de participação','recommendation_letter'=>'Carta de recomendação','transfer_letter'=>'Carta de transferência','presentation_letter'=>'Carta de apresentação'];$title=$titles[$docType]??'Documento';
    $navy=[21,53,80];$blue=[47,128,237];$gold=[193,151,56];$muted=[90,104,120];
    $drawBrand=function(SimplePdf $pdf,bool $land=false)use($church,$churchName,$logo,$navy,$footer){$w=$pdf->width();$pdf->rect(0,0,$w,76,$navy);$x=34;if($logo&&$pdf->image((string)$logo['full_path'],34,14,48,48))$x=96;$pdf->text($x,31,$churchName,20,true,[255,255,255]);if(!empty($church['legal_name']))$pdf->text($x,50,(string)$church['legal_name'],8,false,[220,231,241]);if($footer)$pdf->text(34,$pdf->height()-22,$footer,7,false,$muted);};
    if($docType==='member_card'){
        // Carteirinha moderna em formato horizontal, frente e verso na mesma folha para imprimir e recortar.
        $pdf->addPage('L');$w=$pdf->width();$cardW=340;$cardH=214;$frontX=58;$backX=444;$top=150;$soft=[246,249,252];$border=[205,215,225];$accent=[47,128,237];
        $pdf->text(58,92,'CARTEIRINHA DE MEMBRO',18,true,$navy);$pdf->text(58,113,'Frente e verso prontos para impressão',8,false,$muted);
        // Frente
        $pdf->rect($frontX,$top,$cardW,$cardH,[255,255,255],$border);$pdf->rect($frontX,$top,$cardW,52,$navy);$pdf->rect($frontX,$top+52,8,$cardH-52,$accent);
        $brandX=$frontX+18;if($logo&&$pdf->image((string)$logo['full_path'],$frontX+18,$top+10,42,32))$brandX=$frontX+70;
        $pdf->text($brandX,$top+24,cut_text($churchName,32),12,true,[255,255,255]);$pdf->text($brandX,$top+41,'CREDENCIAL DE MEMBRO',7,true,[213,231,247]);
        if($photo&&$pdf->image((string)$photo['full_path'],$frontX+24,$top+72,82,106)){}else{$pdf->rect($frontX+24,$top+72,82,106,[235,240,245],$border);$pdf->text($frontX+38,$top+129,'SEM FOTO',8,true,$muted);}
        $tx=$frontX+124;$pdf->text($tx,$top+82,cut_text($name,31),14,true,$navy);$pdf->text($tx,$top+103,'Nº DO MEMBRO',6.5,true,$muted);$pdf->rect($tx,$top+110,104,24,[237,244,255],[201,218,239]);$pdf->text($tx+9,$top+126,(string)($member['membership_number']??'—'),10,true,$blue);
        $pdf->text($tx,$top+151,'FUNÇÃO / MINISTÉRIO',6.5,true,$muted);$pdf->text($tx,$top+168,cut_text((string)($member['ministry']??'Membro'),27),9,true,[45,55,68]);
        $pdf->text($tx,$top+190,'Nascimento '.br_date($member['birth_date']??null).'  ·  Validade '.br_date($cardValidity),6.8,false,$muted);
        // Verso
        $pdf->rect($backX,$top,$cardW,$cardH,$soft,$border);$pdf->rect($backX,$top,$cardW,42,$navy);$pdf->text($backX+18,$top+27,'IDENTIFICAÇÃO E VÍNCULO',10,true,[255,255,255]);$pdf->rect($backX,$top+$cardH-16,$cardW,16,$accent);
        $yy=$top+67;$pdf->text($backX+20,$yy,'IGREJA',6.5,true,$muted);$pdf->text($backX+20,$yy+17,cut_text($churchName,46),10,true,$navy);$yy+=43;
        $churchDoc=(string)($church['document']??'');if($churchDoc!==''){$pdf->text($backX+20,$yy,'CNPJ / DOCUMENTO',6.5,true,$muted);$pdf->text($backX+20,$yy+16,$churchDoc,8,false,[45,55,68]);$yy+=34;}
        $contact=trim(implode(' · ',array_filter([(string)($church['phone']??''),(string)($church['email']??'')])));if($contact!==''){$pdf->text($backX+20,$yy,'CONTATO',6.5,true,$muted);$pdf->text($backX+20,$yy+16,cut_text($contact,50),7.5,false,[45,55,68]);$yy+=31;}
        $address=trim(implode(', ',array_filter([(string)($church['address']??''),trim((string)($church['city']??'').(!empty($church['state'])?' - '.(string)$church['state']:''))])));if($address!==''){$pdf->text($backX+20,$yy,'ENDEREÇO',6.5,true,$muted);$pdf->text($backX+20,$yy+15,cut_text($address,50),7,false,[45,55,68]);}
        $pdf->line($backX+25,$top+168,$backX+180,$top+168,[120,135,150]);$pdf->text($backX+25,$top+184,cut_text($leader?:'Pastor / responsável',28),7.5,true,$navy);$pdf->text($backX+200,$top+158,'EMISSÃO',6.2,true,$muted);$pdf->text($backX+200,$top+174,date('d/m/Y'),8,true,[45,55,68]);$pdf->text($backX+200,$top+191,'Válida enquanto o cadastro estiver ativo.',6.2,false,$muted);
        $pdf->text(58,395,'Imprima em papel adequado, recorte nas bordas e, se desejar, plastifique. Tamanho proporcional a uma credencial horizontal.',7,false,$muted);
    } elseif(in_array($docType,['baptism_certificate','course_certificate'],true)){
        $pdf->addPage('L');$w=$pdf->width();$pdf->rect(18,18,$w-36,$pdf->height()-36,[255,255,255],$gold);$pdf->rect(26,26,$w-52,$pdf->height()-52,[255,255,255],[225,211,170]);if($logo)$pdf->image((string)$logo['full_path'],$w/2-34,54,68,68);$pdf->text($w/2-$pdf->textWidth($churchName,16)/2,142,$churchName,16,true,$navy);
        $certTitle=$docType==='baptism_certificate'?'CERTIFICADO DE BATISMO':'CERTIFICADO DE CONCLUSÃO';$pdf->text($w/2-$pdf->textWidth($certTitle,25)/2,190,$certTitle,25,true,$navy);
        if($docType==='baptism_certificate'){$date=$member['baptism_date']??null;$body='Certificamos, para os devidos fins, que '.$name.', membro desta igreja, foi batizado(a) nas águas'.($date?' em '.date_long_br((string)$date):'').', professando publicamente sua fé cristã.';}
        else{$body='Certificamos que '.$name.' concluiu o curso “'.($courseName?:'Curso promovido pela igreja').'”'.($courseHours?' com carga horária de '.$courseHours:'').($courseDate?' em '.date_long_br($courseDate):'').'.';}
        $lines=$pdf->wrap($body,$w-170,15);$y=255;foreach($lines as $line){$pdf->text($w/2-$pdf->textWidth($line,15)/2,$y,$line,15,false,[45,55,68]);$y+=25;}if($custom!==''){foreach($pdf->wrap($custom,$w-190,11) as $line){$pdf->text($w/2-$pdf->textWidth($line,11)/2,$y+12,$line,11,false,$muted);$y+=18;}}
        $dateText=($place?$place.', ':'').date_long_br();$pdf->text($w/2-$pdf->textWidth($dateText,10)/2,430,$dateText,10,false,$muted);$pdf->line(110,490,330,490,[60,70,80]);$pdf->line($w-330,490,$w-110,490,[60,70,80]);$pdf->text(110+(220-$pdf->textWidth($leader?:'Pastor Presidente',10))/2,510,$leader?:'Pastor Presidente',10,true,$navy);$pdf->text($w-330+(220-$pdf->textWidth($secretary?:'Secretaria',10))/2,510,$secretary?:'Secretaria',10,true,$navy);
    } else {
        $pdf->addPage('P');$drawBrand($pdf);$pdf->text(34,125,strtoupper($title),18,true,$navy);$pdf->line(34,140,560,140,[205,215,225],1);
        $body='';
        if($docType==='member_declaration')$body='Declaramos, para os devidos fins, que '.$name.', matrícula '.((string)($member['membership_number']??'—')).', encontra-se cadastrado(a) como membro desta igreja'.(!empty($member['admission_date'])?' desde '.date_long_br((string)$member['admission_date']):'').'.';
        elseif($docType==='participation_declaration')$body='Declaramos que '.$name.' participa das atividades desta igreja, conforme nossos registros administrativos.';
        elseif($docType==='recommendation_letter')$body='Recomendamos '.$name.' à '.($destination?:'igreja ou instituição destinatária').', declarando que integra nossa comunidade e, até a presente data, encontra-se em comunhão e bom relacionamento com esta igreja.';
        elseif($docType==='transfer_letter')$body='Por meio desta, apresentamos '.$name.' para fins de transferência de membresia à '.($destination?:'igreja destinatária').', agradecendo o acolhimento e desejando que continue servindo com fidelidade.';
        else $body='Apresentamos '.$name.' à '.($destination?:'instituição destinatária').', membro/congregado(a) vinculado(a) a esta igreja, para os fins que se fizerem necessários.';
        $y=190;foreach($pdf->wrap($body,510,13) as $line){$pdf->text(42,$y,$line,13,false,[40,50,63]);$y+=22;}if($custom!==''){$y+=18;foreach($pdf->wrap($custom,510,11) as $line){$pdf->text(42,$y,$line,11,false,$muted);$y+=18;}}
        $dateText=($place?$place.', ':'').date_long_br();$pdf->text(42,$y+50,$dateText,11,false,$muted);$pdf->line(55,$y+145,270,$y+145,[60,70,80]);$pdf->line(325,$y+145,540,$y+145,[60,70,80]);$pdf->text(55+(215-$pdf->textWidth($leader?:'Pastor Presidente',10))/2,$y+165,$leader?:'Pastor Presidente',10,true,$navy);$pdf->text(325+(215-$pdf->textWidth($secretary?:'Secretaria',10))/2,$y+165,$secretary?:'Secretaria',10,true,$navy);
    }
    q('INSERT INTO generated_documents(church_id,member_id,doc_type,title,details_json,generated_by) VALUES(?,?,?,?,?,?)',[$cid,$memberId,$docType,$title,json_encode(['course_name'=>$courseName,'course_hours'=>$courseHours,'course_date'=>$courseDate,'destination'=>$destination,'card_validity'=>$cardValidity],JSON_UNESCAPED_UNICODE),(int)user()['id']]);audit('generate_document','member',$memberId,['type'=>$docType]);
    $pdf->output('AdminIgreja-'.preg_replace('/[^A-Za-z0-9_-]/','_',strtolower($docType)).'-'.(int)$memberId.'.pdf',$pdfMode);
}

if ($route === 'users') {
    if (!can_manage_users()) { http_response_code(403); exit('Acesso restrito ao Pastor / Administrador.'); }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $count=(int)(one('SELECT COUNT(*) c FROM users WHERE church_id=?',[$cid])['c']??0);
        $church=one('SELECT max_users FROM churches WHERE id=?',[$cid]);
        if($count >= (int)($church['max_users']??0)) set_flash('Limite de usuários do plano atingido.','error');
        else {
            $role=(string)($_POST['role']??'secretaria');
            if(!in_array($role,['pastor','secretaria','tesouraria'],true))$role='secretaria';
            q('INSERT INTO users(church_id,name,email,password_hash,role,active) VALUES(?,?,?,?,?,1)',[$cid,trim((string)$_POST['name']),strtolower(trim((string)$_POST['email'])),password_make((string)$_POST['password']),$role]);
            audit('create_user','user',(int)db()->lastInsertId(),['role'=>$role]); set_flash('Usuário criado.');
        }
        redirect('/?r=users');
    }
    $rows=all('SELECT id,name,email,role,active,last_login_at FROM users WHERE church_id=? ORDER BY id DESC',[$cid]);
    page_top('Usuários e permissões','Pastor administra os acessos da própria igreja'); flash();
    echo '<div class="card"><h3>Novo usuário</h3><form method="post">'.csrf_input().'<div class="form-grid"><div><label>Nome</label><input name="name" required></div><div><label>E-mail</label><input type="email" name="email" required></div><div><label>Perfil</label><select name="role"><option value="pastor">Pastor / Administrador</option><option value="secretaria">Secretaria</option><option value="tesouraria">Tesouraria</option></select></div><div><label>Senha inicial</label><input type="password" name="password" minlength="10" required></div></div><p><button class="btn">Criar usuário</button></p></form></div><br><div class="card"><div class="table-wrap"><table class="table"><thead><tr><th>NOME</th><th>E-MAIL</th><th>PERFIL</th><th>ATIVO</th></tr></thead><tbody>';
    foreach($rows as $r)echo '<tr><td>'.e($r['name']).'</td><td>'.e($r['email']).'</td><td>'.e(role_label($r['role'])).'</td><td>'.($r['active']?'Sim':'Não').'</td></tr>';
    echo '</tbody></table></div></div>'; page_bottom(); exit;
}

if ($route === 'finance') {
    if (!can_treasury()) { http_response_code(403); exit('Acesso restrito à Tesouraria.'); }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $dir=(string)($_POST['direction']??'income');
        if(!in_array($dir,['income','expense'],true))$dir='income';
        $kind=$dir==='income'?(string)(($_POST['income_kind']??'') ?: 'other'):null;
        if($dir==='income' && !in_array($kind,['tithe','offering','donation','other'],true))$kind='other';
        $memberId = null;
        if ($dir==='income' && $kind==='tithe') {
            $candidate=(int)($_POST['member_id']??0);
            $member=one("SELECT id FROM members WHERE id=? AND church_id=? AND status='active'",[$candidate,$cid]);
            if(!$member){set_flash('Para lançar um dízimo, escolha o membro.','error');redirect('/?r=finance');}
            $memberId=$candidate;
        }
        $receipt=null;
        try {
            if(isset($_FILES['receipt'])) $receipt=save_receipt_upload($cid,$_FILES['receipt']);
            q('INSERT INTO transactions(church_id,account_id,member_id,direction,income_kind,category,description,amount,transaction_date,created_by,receipt_path,receipt_name,receipt_mime,receipt_size) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[
                $cid,(int)($_POST['account_id']??0)?:null,$memberId,$dir,$kind,trim((string)($_POST['category']??'')),trim((string)($_POST['description']??'')),(float)($_POST['amount']??0),$_POST['transaction_date'],(int)user()['id'],
                $receipt['path']??null,$receipt['name']??null,$receipt['mime']??null,$receipt['size']??null
            ]);
            $tx=(int)db()->lastInsertId(); audit('create_transaction','transaction',$tx,['direction'=>$dir,'kind'=>$kind,'member_id'=>$memberId,'receipt'=>(bool)$receipt]);
            set_flash('Movimentação lançada'.($receipt?' com comprovante anexado.':'.'));
        } catch(Throwable $e) {
            if($receipt && !empty($receipt['path'])) @unlink(dirname(__DIR__).'/'.$receipt['path']);
            error_log('finance upload/insert: '.$e->getMessage()); set_flash($e->getMessage(),'error');
        }
        redirect('/?r=finance');
    }
    $accounts=all('SELECT * FROM accounts WHERE church_id=? AND active=1 ORDER BY name',[$cid]);
    $members=all("SELECT id,name FROM members WHERE church_id=? AND status='active' ORDER BY name",[$cid]);
    $rows=all('SELECT t.*,a.name account_name,m.name member_name FROM transactions t LEFT JOIN accounts a ON a.id=t.account_id LEFT JOIN members m ON m.id=t.member_id WHERE t.church_id=? ORDER BY t.transaction_date DESC,t.id DESC LIMIT 300',[$cid]);
    page_top('Movimentações financeiras','Tesouraria · dízimos ligados aos membros e comprovantes protegidos'); flash();
    echo '<div class="card"><h3>Novo lançamento</h3><form method="post" enctype="multipart/form-data" id="financeForm">'.csrf_input().'<div class="form-grid"><div><label>Tipo</label><select name="direction" id="direction"><option value="income">Entrada</option><option value="expense">Saída</option></select></div><div id="incomeKindBox"><label>Tipo da entrada</label><select name="income_kind" id="income_kind"><option value="other">Outra entrada</option><option value="tithe">Dízimo</option><option value="offering">Oferta</option><option value="donation">Doação</option></select></div><div id="memberBox" class="full" hidden><label>Membro do dízimo</label><select name="member_id" id="member_id"><option value="">Selecione o membro...</option>';
    foreach($members as $m)echo '<option value="'.(int)$m['id'].'">'.e($m['name']).'</option>';
    echo '</select><small class="hint">Obrigatório quando o lançamento for Dízimo.</small></div><div><label>Conta</label><select name="account_id"><option value="">Sem conta</option>';
    foreach($accounts as $a)echo '<option value="'.(int)$a['id'].'">'.e($a['name']).'</option>';
    echo '</select></div><div><label>Categoria</label><input name="category" id="category" required></div><div class="full"><label>Descrição</label><input name="description" required></div><div><label>Valor</label><input name="amount" type="number" min="0.01" step="0.01" required></div><div><label>Data</label><input name="transaction_date" type="date" value="'.date('Y-m-d').'" required></div><div class="full"><label>Recibo / comprovante <span class="optional">(opcional)</span></label><input name="receipt" type="file" accept="application/pdf,image/jpeg,image/png,image/webp"><small class="hint">PDF, JPG, PNG ou WEBP · máximo 5 MB. O arquivo fica protegido e só a Tesouraria/Pastor pode abrir.</small></div></div><p><button class="btn">Lançar</button></p></form></div>';
    echo '<script>(function(){const d=document.getElementById("direction"),k=document.getElementById("income_kind"),kb=document.getElementById("incomeKindBox"),mb=document.getElementById("memberBox"),m=document.getElementById("member_id"),c=document.getElementById("category");function sync(){const income=d.value==="income";kb.hidden=!income;const tithe=income&&k.value==="tithe";mb.hidden=!tithe;m.required=tithe;if(!tithe)m.value="";if(c.value.trim()===""){if(tithe)c.value="Dízimo";else if(income&&k.value==="offering")c.value="Oferta";else if(income&&k.value==="donation")c.value="Doação";else if(!income)c.value="Despesa";}}d.addEventListener("change",sync);k.addEventListener("change",function(){c.value="";sync();});sync();})();</script>';
    echo '<br><div class="card"><div class="table-wrap"><table class="table"><thead><tr><th>DATA</th><th>TIPO</th><th>MEMBRO</th><th>CATEGORIA</th><th>DESCRIÇÃO</th><th>CONTA</th><th>COMPROVANTE</th><th>VALOR</th></tr></thead><tbody>';
    foreach($rows as $r){$in=$r['direction']==='income';echo '<tr><td>'.e($r['transaction_date']).'</td><td>'.($in?e($r['income_kind']==='tithe'?'Dízimo':($r['income_kind']==='offering'?'Oferta':($r['income_kind']==='donation'?'Doação':'Entrada'))):'Saída').'</td><td>'.e($r['member_name']??'').'</td><td>'.e($r['category']).'</td><td>'.e($r['description']).'</td><td>'.e($r['account_name']).'</td><td>'.(!empty($r['receipt_path'])?'<a class="receipt-link" target="_blank" href="/?r=receipt&id='.(int)$r['id'].'">Ver anexo</a>':'—').'</td><td class="money '.($in?'in':'out').'">'.($in?'+ ':'- ').'R$ '.number_format((float)$r['amount'],2,',','.').'</td></tr>';}
    echo '</tbody></table></div></div>'; page_bottom(); exit;
}

if ($route === 'payables') {
    if (!can_treasury()) { http_response_code(403); exit('Acesso restrito à Tesouraria.'); }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        q("INSERT INTO payables(church_id,supplier,description,amount,due_date,status) VALUES(?,?,?,?,?,'open')",[$cid,trim((string)$_POST['supplier']),trim((string)$_POST['description']),(float)$_POST['amount'],$_POST['due_date']]);
        audit('create_payable','payable',(int)db()->lastInsertId()); set_flash('Conta a pagar cadastrada.'); redirect('/?r=payables');
    }
    $rows=all('SELECT * FROM payables WHERE church_id=? ORDER BY due_date,id DESC',[$cid]);
    page_top('Contas a pagar','Tesouraria'); flash();
    echo '<div class="card"><form method="post">'.csrf_input().'<div class="form-grid"><div><label>Fornecedor</label><input name="supplier" required></div><div><label>Descrição</label><input name="description" required></div><div><label>Valor</label><input type="number" step="0.01" min="0.01" name="amount" required></div><div><label>Vencimento</label><input type="date" name="due_date" required></div></div><p><button class="btn">Cadastrar</button></p></form></div><br><div class="card"><div class="table-wrap"><table class="table"><thead><tr><th>VENCIMENTO</th><th>FORNECEDOR</th><th>DESCRIÇÃO</th><th>VALOR</th><th>STATUS</th></tr></thead><tbody>';
    foreach($rows as $r)echo '<tr><td>'.e($r['due_date']).'</td><td>'.e($r['supplier']).'</td><td>'.e($r['description']).'</td><td>R$ '.number_format((float)$r['amount'],2,',','.').'</td><td><span class="pill">'.e($r['status']).'</span></td></tr>';
    echo '</tbody></table></div></div>'; page_bottom(); exit;
}

if ($route === 'report-pdf') {
    if(!can_treasury()){http_response_code(403);exit('Acesso restrito à Tesouraria.');}
    require_once dirname(__DIR__).'/app/pdf.php';
    $pdfMode=((string)($_GET['mode']??'download'))==='inline'?'inline':'attachment';
    [$period,$from,$to,$periodLabel]=period_values((string)($_GET['period']??'month'),(string)($_GET['from']??''),(string)($_GET['to']??''));
    $church=church_doc_profile($cid);$logo=logo_file_for_church($cid);$pastor=church_leader_name($church,['Pastor Presidente','Presidente','Pastor']);$treasurer=church_leader_name($church,['Tesoureiro']);
    $sum=one("SELECT COALESCE(SUM(CASE WHEN direction='income' AND status='posted' THEN amount ELSE 0 END),0) income,COALESCE(SUM(CASE WHEN direction='expense' AND status='posted' THEN amount ELSE 0 END),0) expense,COUNT(*) qty FROM transactions WHERE church_id=? AND transaction_date BETWEEN ? AND ? AND status='posted'",[$cid,$from,$to]);
    $kinds=all("SELECT CASE WHEN direction='expense' THEN CONCAT('Saída · ',category) WHEN income_kind='tithe' THEN 'Dízimos' WHEN income_kind='offering' THEN 'Ofertas' WHEN income_kind='donation' THEN 'Doações' ELSE CONCAT('Entrada · ',category) END kind,SUM(amount) total,COUNT(*) qty FROM transactions WHERE church_id=? AND transaction_date BETWEEN ? AND ? AND status='posted' GROUP BY kind ORDER BY total DESC",[$cid,$from,$to]);
    $tithes=all("SELECT m.name,m.membership_number,SUM(t.amount) total,COUNT(*) launches FROM transactions t JOIN members m ON m.id=t.member_id WHERE t.church_id=? AND t.direction='income' AND t.income_kind='tithe' AND t.status='posted' AND t.transaction_date BETWEEN ? AND ? GROUP BY m.id,m.name,m.membership_number ORDER BY m.name",[$cid,$from,$to]);
    $moves=all("SELECT t.id,t.transaction_date,t.direction,t.income_kind,t.category,t.description,t.amount,t.receipt_path,t.receipt_name,t.receipt_mime,m.name member_name,a.name account_name,u.name created_by_name FROM transactions t LEFT JOIN members m ON m.id=t.member_id LEFT JOIN accounts a ON a.id=t.account_id LEFT JOIN users u ON u.id=t.created_by WHERE t.church_id=? AND t.status='posted' AND t.transaction_date BETWEEN ? AND ? ORDER BY t.transaction_date,t.id",[$cid,$from,$to]);
    $pdf=new SimplePdf();$navy=[21,53,80];$blue=[47,128,237];$green=[19,138,85];$red=[200,63,73];$muted=[92,107,125];$line=[215,224,234];$soft=[245,248,251];
    $header=function(SimplePdf $pdf,string $subtitle='')use($church,$logo,$navy,$muted){$w=$pdf->width();$pdf->rect(0,0,$w,72,$navy);$x=28;if($logo&&$pdf->image((string)$logo['full_path'],28,13,45,45))$x=88;$pdf->text($x,31,(string)($church['name']??'Igreja'),18,true,[255,255,255]);$meta=trim(implode(' · ',array_filter([(string)($church['document']??''),(string)($church['phone']??''),(string)($church['email']??'')])));if($meta)$pdf->text($x,49,$meta,7,false,[221,232,242]);if($subtitle)$pdf->textRight($w-28,35,$subtitle,9,true,[255,255,255]);};
    $pdf->addPage('L');$header($pdf,$periodLabel);$w=$pdf->width();$pdf->text(28,100,'RELATÓRIO FINANCEIRO',19,true,$navy);$pdf->text(28,121,'Período: '.br_date($from).' a '.br_date($to).' · Emitido em '.date('d/m/Y H:i'),9,false,$muted);
    $cards=[['Entradas',(float)$sum['income'],$green],['Saídas',(float)$sum['expense'],$red],['Saldo',(float)$sum['income']-(float)$sum['expense'],$navy],['Lançamentos',(float)($sum['qty']??0),$blue]];$x=28;foreach($cards as $i=>$c){$pdf->rect($x,148,185,70,[255,255,255],$line);$pdf->text($x+14,170,$c[0],8,true,$muted);$val=$i===3?(string)(int)$c[1]:money_br($c[1]);$pdf->text($x+14,199,$val,17,true,$c[2]);$x+=198;}
    $pdf->text(28,250,'Resumo por categoria',12,true,$navy);$y=270;$pdf->rect(28,$y,390,24,$navy);$pdf->text(38,$y+16,'TIPO / CATEGORIA',8,true,[255,255,255]);$pdf->textRight(404,$y+16,'TOTAL',8,true,[255,255,255]);$y+=24;foreach(array_slice($kinds,0,10) as $k){$pdf->rect(28,$y,390,23,[255,255,255],$line);$pdf->text(38,$y+15,(string)$k['kind'],8,false,[45,55,68]);$pdf->textRight(404,$y+15,money_br((float)$k['total']),8,true,$navy);$y+=23;}
    $pdf->text(445,250,'Dízimos por membro',12,true,$navy);$y2=270;$pdf->rect(445,$y2,368,24,$navy);$pdf->text(455,$y2+16,'MEMBRO',8,true,[255,255,255]);$pdf->textRight(800,$y2+16,'TOTAL',8,true,[255,255,255]);$y2+=24;foreach(array_slice($tithes,0,10) as $t){$pdf->rect(445,$y2,368,23,[255,255,255],$line);$label=(string)$t['name'].(!empty($t['membership_number'])?' · '.$t['membership_number']:'');$pdf->text(455,$y2+15,cut_text($label,45),8,false,[45,55,68]);$pdf->textRight(800,$y2+15,money_br((float)$t['total']),8,true,$green);$y2+=23;}
    // Tabela detalhada em páginas próprias.
    $cols=[28,68,115,195,300,390,565,655,738];$widths=[40,47,80,105,90,175,90,83,75];
    $newTablePage=function()use($pdf,$header,$navy,$line,$cols,$widths){$pdf->addPage('L');$header($pdf,'Movimentações detalhadas');$y=94;$headers=['REF.','DATA','TIPO','MEMBRO','CATEGORIA','DESCRIÇÃO','CONTA','RECIBO','VALOR'];for($i=0;$i<count($headers);$i++){$pdf->rect($cols[$i],$y,$widths[$i],26,$navy);$pdf->text($cols[$i]+5,$y+17,$headers[$i],7,true,[255,255,255]);}return $y+26;};
    $y=$newTablePage();$rowNo=0;foreach($moves as $m){if($y>535)$y=$newTablePage();$isIn=$m['direction']==='income';$type=!$isIn?'Saída':($m['income_kind']==='tithe'?'Dízimo':($m['income_kind']==='offering'?'Oferta':($m['income_kind']==='donation'?'Doação':'Entrada')));$bg=($rowNo++%2===0)?[255,255,255]:$soft;for($i=0;$i<count($cols);$i++)$pdf->rect($cols[$i],$y,$widths[$i],27,$bg,$line);$vals=['#'.str_pad((string)$m['id'],5,'0',STR_PAD_LEFT),br_date($m['transaction_date']),$type,(string)($m['member_name']??'—'),(string)$m['category'],(string)$m['description'],(string)($m['account_name']??'—'),!empty($m['receipt_path'])?'Anexado':'—',($isIn?'+ ':'- ').money_br((float)$m['amount'])];for($i=0;$i<count($vals);$i++){$txt=cut_text($vals[$i],$i===5?32:($i===3?18:16));$pdf->text($cols[$i]+5,$y+17,$txt,6.7,$i===8,$i===8?($isIn?$green:$red):[45,55,68]);}$y+=27;}
    // Anexos: comprovantes em imagem ganham uma página visual; PDFs são listados.
    $receipts=array_values(array_filter($moves,fn($m)=>!empty($m['receipt_path'])));if($receipts){$pdf->addPage('P');$header($pdf,'Comprovantes anexados');$pdf->text(28,105,'COMPROVANTES E RECIBOS',16,true,$navy);$yy=135;foreach($receipts as $r){if($yy>760){$pdf->addPage('P');$header($pdf,'Comprovantes anexados');$yy=105;}$pdf->rect(28,$yy,540,54,[255,255,255],$line);$pdf->text(40,$yy+17,'Mov. #'.str_pad((string)$r['id'],5,'0',STR_PAD_LEFT).' · '.br_date($r['transaction_date']).' · '.money_br((float)$r['amount']),8,true,$navy);$pdf->text(40,$yy+34,cut_text((string)$r['description'],70),7,false,[45,55,68]);$pdf->text(40,$yy+48,'Arquivo: '.cut_text((string)($r['receipt_name']??'comprovante'),65),6.5,false,$muted);$yy+=62;}
        foreach($receipts as $r){if(str_starts_with((string)($r['receipt_mime']??''),'image/')){$f=receipt_file_for_transaction($cid,(int)$r['id']);if($f){$pdf->addPage('P');$header($pdf,'Comprovante · Mov. #'.str_pad((string)$r['id'],5,'0',STR_PAD_LEFT));$pdf->text(28,100,br_date($r['transaction_date']).' · '.money_br((float)$r['amount']).' · '.(string)$r['description'],9,true,$navy);$pdf->image((string)$f['full_path'],45,130,505,640);}}}
    }
    $pdf->addPage('P');$header($pdf,'Encerramento');$pdf->text(34,120,'RESPONSABILIDADE E CONFERÊNCIA',14,true,$navy);$pdf->text(34,150,'Este relatório consolida os lançamentos registrados no AdminIgreja no período informado.',9,false,$muted);$pdf->line(55,290,270,290,[80,90,100]);$pdf->line(325,290,540,290,[80,90,100]);$pdf->text(55+(215-$pdf->textWidth($treasurer?:'Tesouraria',10))/2,312,$treasurer?:'Tesouraria',10,true,$navy);$pdf->text(325+(215-$pdf->textWidth($pastor?:'Pastor / Responsável',10))/2,312,$pastor?:'Pastor / Responsável',10,true,$navy);$pdf->text(34,380,'Resumo final',12,true,$navy);$pdf->text(34,410,'Entradas: '.money_br((float)$sum['income']),10,true,$green);$pdf->text(34,432,'Saídas: '.money_br((float)$sum['expense']),10,true,$red);$pdf->text(34,454,'Saldo: '.money_br((float)$sum['income']-(float)$sum['expense']),11,true,$navy);audit('generate_financial_pdf','report',null,['from'=>$from,'to'=>$to]);$pdf->output('Relatorio-Financeiro-'.$from.'-a-'.$to.'.pdf',$pdfMode);
}

if ($route === 'reports') {
    if (!can_treasury()) { http_response_code(403); exit('Acesso restrito à Tesouraria.'); }
    [$period,$from,$to,$periodLabel]=period_values((string)($_GET['period']??'month'),(string)($_GET['from']??''),(string)($_GET['to']??''));
    $sum=one("SELECT COALESCE(SUM(CASE WHEN direction='income' AND status='posted' THEN amount ELSE 0 END),0) income,COALESCE(SUM(CASE WHEN direction='expense' AND status='posted' THEN amount ELSE 0 END),0) expense,COUNT(*) qty FROM transactions WHERE church_id=? AND transaction_date BETWEEN ? AND ? AND status='posted'",[$cid,$from,$to]);
    $moves=all("SELECT t.id,t.transaction_date,t.direction,t.income_kind,t.category,t.description,t.amount,t.receipt_path,t.receipt_name,m.name member_name,a.name account_name FROM transactions t LEFT JOIN members m ON m.id=t.member_id LEFT JOIN accounts a ON a.id=t.account_id WHERE t.church_id=? AND t.status='posted' AND t.transaction_date BETWEEN ? AND ? ORDER BY t.transaction_date DESC,t.id DESC LIMIT 150",[$cid,$from,$to]);
    $receipts=(int)(one("SELECT COUNT(*) c FROM transactions WHERE church_id=? AND status='posted' AND transaction_date BETWEEN ? AND ? AND receipt_path IS NOT NULL",[$cid,$from,$to])['c']??0);
    page_top('Relatórios financeiros','PDF profissional · semanal, mensal ou período personalizado');flash();
    echo '<div class="card report-filter-card"><form method="get" class="form-grid report-filter"><input type="hidden" name="r" value="reports"><div><label>Período</label><select name="period" id="reportPeriod"><option value="week" '.($period==='week'?'selected':'').'>Semanal</option><option value="month" '.($period==='month'?'selected':'').'>Mensal</option><option value="custom" '.($period==='custom'?'selected':'').'>Personalizado</option></select></div><div class="custom-date"><label>De</label><input type="date" name="from" value="'.e($from).'"></div><div class="custom-date"><label>Até</label><input type="date" name="to" value="'.e($to).'"></div><div class="report-buttons"><button class="btn light">Atualizar visualização</button><a class="btn" target="_blank" href="/?r=report-pdf&mode=inline&period='.e($period).'&from='.e($from).'&to='.e($to).'">Imprimir relatório</a><a class="btn green" target="_blank" href="/?r=report-pdf&mode=download&period='.e($period).'&from='.e($from).'&to='.e($to).'">Baixar PDF</a></div></form></div><script>(function(){const p=document.getElementById("reportPeriod"),ds=document.querySelectorAll(".custom-date");function sync(){ds.forEach(x=>x.style.display=p.value==="custom"?"block":"none");}p.addEventListener("change",sync);sync();})();</script>';
    echo '<div class="grid report-summary modern"><div class="card metric"><small>Entradas</small><strong class="money in">'.e(money_br((float)$sum['income'])).'</strong></div><div class="card metric"><small>Saídas</small><strong class="money out">'.e(money_br((float)$sum['expense'])).'</strong></div><div class="card metric"><small>Saldo</small><strong>'.e(money_br((float)$sum['income']-(float)$sum['expense'])).'</strong></div><div class="card metric"><small>Comprovantes</small><strong>'.$receipts.'</strong></div></div><br>';
    echo '<div class="card"><div class="card-title-row"><div><h3>Prévia detalhada</h3><p class="muted">O PDF contém capa, resumo, tabela detalhada, dízimos por membro, comprovantes e assinaturas.</p></div><span class="pill">'.e($periodLabel).' · '.e(br_date($from)).' a '.e(br_date($to)).'</span></div><div class="table-wrap"><table class="table"><thead><tr><th>REF.</th><th>DATA</th><th>TIPO</th><th>MEMBRO</th><th>CATEGORIA</th><th>DESCRIÇÃO</th><th>CONTA</th><th>RECIBO</th><th>VALOR</th></tr></thead><tbody>';
    if(!$moves)echo '<tr><td colspan="9">Nenhuma movimentação no período.</td></tr>';foreach($moves as $m){$in=$m['direction']==='income';$type=!$in?'Saída':($m['income_kind']==='tithe'?'Dízimo':($m['income_kind']==='offering'?'Oferta':($m['income_kind']==='donation'?'Doação':'Entrada')));echo '<tr><td>#'.str_pad((string)$m['id'],5,'0',STR_PAD_LEFT).'</td><td>'.e(br_date($m['transaction_date'])).'</td><td>'.e($type).'</td><td>'.e($m['member_name']??'—').'</td><td>'.e($m['category']).'</td><td>'.e($m['description']).'</td><td>'.e($m['account_name']??'—').'</td><td>'.(!empty($m['receipt_path'])?'<a class="receipt-link" target="_blank" href="/?r=receipt&id='.(int)$m['id'].'">'.e($m['receipt_name']?:'Ver comprovante').'</a>':'—').'</td><td class="money '.($in?'in':'out').'">'.($in?'+ ':'- ').e(money_br((float)$m['amount'])).'</td></tr>';}
    echo '</tbody></table></div></div>';page_bottom();exit;
}

http_response_code(404);
page_top('Página não encontrada');
echo '<div class="card">A página solicitada não existe.</div>';
page_bottom();
