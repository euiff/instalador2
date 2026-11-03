<?php
declare(strict_types=1);

/**
 * Read-only Igreja Master Central 360, with tenant-scoped aggregates.
 * One ledger for departments and general treasury, no duplicated sums.
 */
final class ManagementOverviewService
{
    public function __construct(private PDO $pdo, private Auth $auth, private string $churchId)
    {
        $church=$auth->currentChurch();
        if(!$church||$churchId===''||(string)$church['id']!==$churchId||!$auth->can('dashboard'))
            throw new RuntimeException('Acesso negado à central de gestão desta igreja.');
    }

    private function scalar(string $sql,array $args):int|float|null
    {
        try{
            $q=$this->pdo->prepare($sql);$q->execute($args);
            $value=$q->fetchColumn();
            return is_numeric($value)?(float)$value:null;
        }catch(PDOException $e){
            error_log('Central 360 metric unavailable: '.$e->getCode());
            return null;
        }
    }

    public function metrics():array
    {
        $cid=$this->churchId;
        $perms=[
            'people'=>$this->auth->can('secretary'),
            'finance'=>$this->auth->can('finance'),
            'accounting'=>$this->auth->can('accounting_portal'),
            'communications'=>$this->auth->can('communications')
        ];
        $numbers=[];
        if($perms['people']){
            $numbers['members']=$this->scalar('SELECT COUNT(*) FROM members WHERE church_id=? AND active=1',[$cid]);
            $numbers['departments']=$this->scalar('SELECT COUNT(*) FROM ministry_departments WHERE church_id=? AND active=1',[$cid]);
            $numbers['events']=$this->scalar('SELECT COUNT(*) FROM events WHERE church_id=? AND active=1 AND event_date>=NOW()',[$cid]);
            $numbers['prayers']=$this->scalar("SELECT COUNT(*) FROM prayer_requests WHERE church_id=? AND status='pending'",[$cid]);
            $numbers['users']=$this->scalar('SELECT COUNT(*) FROM church_members WHERE church_id=?',[$cid]);
        }
        if($perms['finance']){
            $numbers['accounts']=$this->scalar('SELECT COUNT(*) FROM finance_accounts WHERE church_id=? AND active=1',[$cid]);
            $numbers['overdue_payables']=$this->scalar(
                "SELECT COUNT(*) FROM finance_payables WHERE church_id=? AND status='open' AND due_date<CURDATE()",[$cid]);
            $numbers['unproved_expenses']=$this->scalar(
                "SELECT COUNT(*) FROM finance_entries e WHERE e.church_id=? AND e.status='posted'
                   AND e.direction='expense' AND e.transaction_date>=DATE_FORMAT(CURDATE(),'%Y-%m-01')
                   AND NOT EXISTS(SELECT 1 FROM finance_documents d WHERE d.church_id=e.church_id AND d.entry_id=e.id)
                   AND NOT EXISTS(SELECT 1 FROM accounting_documents a WHERE a.church_id=e.church_id AND a.finance_entry_id=e.id)",
                   [$cid]);
        }
        if($perms['accounting']){
            $numbers['accounting_requests']=$this->scalar(
                "SELECT COUNT(*) FROM accounting_requests WHERE church_id=? AND status='open'",[$cid]);
            $numbers['accounting_drafts']=$this->scalar(
                "SELECT COUNT(*) FROM accounting_documents WHERE church_id=? AND status IN ('draft','needs_fix')",[$cid]);
        }
        return ['permissions'=>$perms,'numbers'=>$numbers];
    }

    public function monthlyFinance(int $months=6):array
    {
        if(!$this->auth->can('finance'))return [];
        $months=max(3,min(12,$months));
        $last=(new DateTimeImmutable('first day of this month'))->setTime(0,0);
        $first=$last->modify('-'.($months-1).' months');
        $end=$last->modify('+1 month');
        $q=$this->pdo->prepare(
            "SELECT DATE_FORMAT(transaction_date,'%Y-%m') AS period,
                COALESCE(SUM(CASE WHEN direction='income' THEN amount ELSE 0 END),0) income,
                COALESCE(SUM(CASE WHEN direction='expense' THEN amount ELSE 0 END),0) expense
              FROM finance_entries
              WHERE church_id=? AND status='posted' AND transaction_date>=? AND transaction_date<?
              GROUP BY DATE_FORMAT(transaction_date,'%Y-%m')"
        );
        $q->execute([$this->churchId,$first->format('Y-m-d'),$end->format('Y-m-d')]);
        $found=[];
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row)$found[$row['period']]=$row;
        $result=[];
        for($i=0;$i<$months;$i++){
            $d=$first->modify('+'.$i.' months');
            $key=$d->format('Y-m');
            $row=$found[$key]??[];
            $result[]=[
                'period'=>$key,
                'label'=>mb_strtoupper($d->format('M')) . '/'.$d->format('y'),
                'income'=>(float)($row['income']??0),
                'expense'=>(float)($row['expense']??0)
            ];
        }
        return $result;
    }

    public function nextEvents(int $limit=4):array
    {
        if(!$this->auth->can('secretary'))return [];
        $limit=max(1,min(12,$limit));
        try{
            $q=$this->pdo->prepare('SELECT title,event_date,location FROM events
                WHERE church_id=? AND active=1 AND event_date>=NOW() ORDER BY event_date LIMIT '.$limit);
            $q->execute([$this->churchId]);
            return $q->fetchAll(PDO::FETCH_ASSOC);
        }catch(PDOException $e){error_log('Central 360 events unavailable: '.$e->getCode());return [];}
    }

    public static function tasks(array $numbers,array $perms):array
    {
        $tasks=[];
        $add=static function(string $title,string $description,string $href,string $kind)use(&$tasks):void{
            $tasks[]=['title'=>$title,'description'=>$description,'href'=>$href,'kind'=>$kind];
        };
        if($perms['people']??false){
            if(array_key_exists('members',$numbers)&&$numbers['members']===0.0)$add('Cadastre os primeiros membros','Comece pela lista de pessoas da igreja.','/members.php?new=1','setup');
            if(array_key_exists('departments',$numbers)&&$numbers['departments']===0.0)$add('Organize os departamentos','Defina ministérios, responsáveis e participantes.','/departments.php','setup');
            if(($numbers['prayers']??0)>0)$add('Pedidos de oração pendentes','Acompanhe as solicitações de oração.','/prayers.php','pending');
        }
        if($perms['finance']??false){
            if(array_key_exists('accounts',$numbers)&&$numbers['accounts']===0.0)$add('Configure as contas financeiras','Cadastre a conta bancária ou caixa da igreja.','/finance.php','setup');
            if(($numbers['overdue_payables']??0)>0)$add('Há contas a pagar vencidas','Confira as obrigações que precisam de atenção.','/finance.php#payables','pending');
            if(($numbers['unproved_expenses']??0)>0)$add('Despesas sem comprovantes','Envie os documentos para facilitar a contabilidade.','/accounting.php','pending');
        }
        if($perms['accounting']??false){
            if(($numbers['accounting_requests']??0)>0)$add('O contador solicitou documentos','Veja as pendências da prestação de contas.','/accounting.php#accounting-requests','pending');
            if(($numbers['accounting_drafts']??0)>0)$add('Documentos fiscais para conferir','Revise notas e cupons antes do fechamento.','/accounting.php#accounting-files','pending');
        }
        if(!$tasks)$add('Tudo organizado para começar','Acesse as áreas no menu ou use a busca para localizar uma função.','/dashboard.php','ok');
        return array_slice($tasks,0,9);
    }

    public function quickLinks():array
    {
        $items=[
            ['title'=>'Pessoas e membros','description'=>'Cadastros, perfis e aniversariantes','href'=>'/members.php','ability'=>'secretary','icon'=>'users'],
            ['title'=>'Departamentos','description'=>'Equipes, responsáveis e atividades','href'=>'/departments.php','ability'=>'secretary','icon'=>'users-round'],
            ['title'=>'Tesouraria','description'=>'Entradas, despesas e contas','href'=>'/finance-control.php','ability'=>'finance','icon'=>'wallet'],
            ['title'=>'Caixas dos ministérios','description'=>'Saldos e movimentações por setor','href'=>'/department-treasury.php','ability'=>'department_treasury','icon'=>'landmark'],
            ['title'=>'Contabilidade','description'=>'Notas fiscais, cupons e entregas','href'=>'/accounting.php','ability'=>'accounting_portal','icon'=>'files'],
            ['title'=>'Grupos e células','description'=>'Grupos, reuniões e frequência','href'=>'/small-groups.php','ability'=>'group_portal','icon'=>'network'],
            ['title'=>'Ensino','description'=>'Estudos, cursos e turmas','href'=>'/learning.php','ability'=>'learning_portal','icon'=>'graduation-cap'],
            ['title'=>'Agenda e eventos','description'=>'Programações e inscrições','href'=>'/events.php','ability'=>'secretary','icon'=>'calendar-days'],
            ['title'=>'Ministério Kids','description'=>'Crianças, equipe e check-in','href'=>'/kids.php','ability'=>'kids_portal','icon'=>'baby'],
            ['title'=>'Comunicação','description'=>'Mural, campanhas e WhatsApp','href'=>'/announcements.php','ability'=>'communications','icon'=>'megaphone'],
            ['title'=>'Usuários e permissões','description'=>'Acesso de líderes, membros e contador','href'=>'/users.php','ability'=>'manage_users','icon'=>'shield-check'],
            ['title'=>'Documentos','description'=>'Cartas, certificados e arquivos','href'=>'/documents.php','ability'=>'secretary','icon'=>'file-text'],
        ];
        return array_values(array_filter($items,fn(array $item):bool=>$this->auth->can($item['ability'])));
    }
}
