from patch_finance_core import replace_once

def patch_accounts(source):
    t=source.decode('utf8')
    anchor="        if($action==='entry'){"
    inserted="""        if($action==='account_update'){
            $id=trim((string)($_POST['account_id']??''));
            $name=trim((string)($_POST['name']??''));
            $type=(string)($_POST['account_type']??'cash');
            $opening=finance_money_input($_POST['opening_balance']??0);
            if($id===''||$name===''||mb_strlen($name,'UTF-8')>130)
                throw new InvalidArgumentException('Selecione a conta geral e informe um nome de até 130 caracteres.');
            if(!in_array($type,['cash','bank'],true)||
               !is_finite($opening)||$opening<0||$opening>999999999.99)
                throw new InvalidArgumentException('Dados financeiros da conta inválidos.');
            $pdo->beginTransaction();
            try{
                $q=$pdo->prepare("SELECT id,name,opening_balance FROM finance_accounts
                    WHERE church_id=? AND id=? AND department_id IS NULL AND active=1 FOR UPDATE");
                $q->execute([$cid,$id]);$old=$q->fetch(PDO::FETCH_ASSOC);
                if(!$old)throw new RuntimeException('Conta geral não encontrada nesta igreja.');
                $q=$pdo->prepare("SELECT COUNT(*) FROM finance_accounts
                    WHERE church_id=? AND department_id IS NULL AND id<>? AND name=? AND active=1");
                $q->execute([$cid,$id,$name]);
                if((int)$q->fetchColumn()>0)throw new RuntimeException('Já existe uma conta geral com este nome.');
                $q=$pdo->prepare('SELECT 1 FROM finance_entries WHERE church_id=? AND account_id=? LIMIT 1');
                $q->execute([$cid,$id]);$used=(bool)$q->fetchColumn();
                $q=$pdo->prepare('SELECT 1 FROM finance_closings WHERE church_id=? AND account_id=? LIMIT 1');
                $q->execute([$cid,$id]);$used=$used||(bool)$q->fetchColumn();
                if($used&&abs((float)$old['opening_balance']-$opening)>0.005)
                    throw new RuntimeException('O saldo inicial não pode mudar após movimentos ou fechamento. Use lançamentos de ajuste autorizados.');
                $q=$pdo->prepare("UPDATE finance_accounts SET name=?,account_type=?,opening_balance=?
                    WHERE church_id=? AND id=? AND department_id IS NULL AND active=1");
                $q->execute([$name,$type,$used?$old['opening_balance']:$opening,$cid,$id]);
                $q=$pdo->prepare("INSERT INTO platform_audit_logs(id,user_id,church_id,action,details)
                    VALUES(?,?,?,'finance_account_updated',?)");
                $q->execute([app_uuid(),$_SESSION['user_id']??null,$cid,
                    json_encode(['id'=>$id,'old_name'=>$old['name'],'new_name'=>$name],
                        JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
                $pdo->commit();
            }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
            flash('success','Conta geral atualizada com segurança.');
            redirect('/finance.php#finance-accounts');
        }

"""
    t=replace_once(t,anchor,inserted+anchor,'account update action')
    return t.encode('utf8')
