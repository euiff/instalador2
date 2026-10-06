<?php
declare(strict_types=1);

function finance_month_bounds(string $month): array {
    if(!preg_match('/^\d{4}-\d{2}$/',$month)) $month=date('Y-m');
    $dt=DateTimeImmutable::createFromFormat('!Y-m',$month)?:new DateTimeImmutable('first day of this month');
    return [$dt->format('Y-m-01'),$dt->format('Y-m-t'),$dt->format('Y-m')];
}
function finance_period_closed(int $cid,string $date): bool {
    $month=substr($date,0,7);
    $r=one("SELECT id FROM financial_period_closures WHERE church_id=? AND period_month=? AND status='closed'",[$cid,$month]);
    return (bool)$r;
}
function finance_assert_open_period(int $cid,string $date): void {
    if(finance_period_closed($cid,$date)) throw new RuntimeException('Este mês já foi fechado. Reabra o período antes de alterar lançamentos.');
}
function finance_decimal(string|float|int|null $value): float {
    if(is_int($value)||is_float($value)) return (float)$value;
    $s=trim((string)$value);
    $s=preg_replace('/[^0-9,\.\-]/','',$s)??'';
    if(str_contains($s,',')) $s=str_replace(['. ','.'],['',''],$s);
    $s=str_replace(',','.',$s);
    return (float)$s;
}
function finance_date_parse(string $value): ?string {
    $v=trim($value);
    foreach(['Y-m-d','d/m/Y','d-m-Y','m/d/Y'] as $fmt){
        $d=DateTimeImmutable::createFromFormat('!'.$fmt,$v);
        if($d&&$d->format($fmt)===$v)return $d->format('Y-m-d');
    }
    if(preg_match('/^(\d{4})(\d{2})(\d{2})/',$v,$m)) return $m[1].'-'.$m[2].'-'.$m[3];
    $ts=strtotime($v); return $ts?date('Y-m-d',$ts):null;
}
function finance_category(int $cid,int $id): ?array {
    if($id<=0)return null;
    return one('SELECT * FROM finance_categories WHERE id=? AND church_id=? AND active=1',[$id,$cid])?:null;
}
function finance_fund(int $cid,int $id): ?array {
    if($id<=0)return null;
    return one('SELECT * FROM finance_funds WHERE id=? AND church_id=? AND active=1',[$id,$cid])?:null;
}
function finance_cost_center(int $cid,int $id): ?array {
    if($id<=0)return null;
    return one('SELECT * FROM cost_centers WHERE id=? AND church_id=? AND active=1',[$id,$cid])?:null;
}
function finance_accounts(int $cid): array { return all('SELECT * FROM accounts WHERE church_id=? AND active=1 ORDER BY name',[$cid]); }
function finance_categories(int $cid,string $direction=''): array {
    if(in_array($direction,['income','expense'],true)) return all("SELECT * FROM finance_categories WHERE church_id=? AND active=1 AND (direction=? OR direction='both') ORDER BY accounting_code,name",[$cid,$direction]);
    return all('SELECT * FROM finance_categories WHERE church_id=? AND active=1 ORDER BY direction,accounting_code,name',[$cid]);
}
function finance_funds(int $cid): array { return all('SELECT * FROM finance_funds WHERE church_id=? AND active=1 ORDER BY restricted,name',[$cid]); }
function finance_cost_centers(int $cid): array { return all('SELECT * FROM cost_centers WHERE church_id=? AND active=1 ORDER BY name',[$cid]); }

function finance_ensure_occurrences(int $cid,string $month=''): void {
    $month=$month?:date('Y-m');
    [$from,$to,$month]=finance_month_bounds($month);
    $items=all('SELECT * FROM recurring_financial_items WHERE church_id=? AND active=1',[$cid]);
    $days=(int)substr($to,8,2);
    foreach($items as $it){
        $day=max(1,min($days,(int)$it['due_day']));
        $due=$month.'-'.str_pad((string)$day,2,'0',STR_PAD_LEFT);
        try{
            q("INSERT IGNORE INTO recurring_occurrences(church_id,recurring_id,period_month,due_date,status) VALUES(?,?,?,?,'pending')",[$cid,(int)$it['id'],$month,$due]);
        }catch(Throwable $e){}
    }
}
function finance_opening_balance(int $cid,string $from): float {
    $opening=(float)(one('SELECT COALESCE(SUM(opening_balance),0) s FROM accounts WHERE church_id=?',[$cid])['s']??0);
    $prior=one("SELECT COALESCE(SUM(CASE WHEN direction='income' AND status='posted' THEN amount WHEN direction='expense' AND status='posted' THEN -amount ELSE 0 END),0) s FROM transactions WHERE church_id=? AND transaction_date<?",[$cid,$from]);
    return $opening+(float)($prior['s']??0);
}
function finance_period_totals(int $cid,string $from,string $to): array {
    $r=one("SELECT
      COALESCE(SUM(CASE WHEN direction='income' AND status='posted' THEN amount ELSE 0 END),0) income,
      COALESCE(SUM(CASE WHEN direction='expense' AND status='posted' THEN amount ELSE 0 END),0) expense,
      SUM(CASE WHEN status='posted' THEN 1 ELSE 0 END) qty
      FROM transactions WHERE church_id=? AND transaction_date BETWEEN ? AND ?",[$cid,$from,$to])?:[];
    $opening=finance_opening_balance($cid,$from);
    $income=(float)($r['income']??0);$expense=(float)($r['expense']??0);
    return ['opening'=>$opening,'income'=>$income,'expense'=>$expense,'closing'=>$opening+$income-$expense,'qty'=>(int)($r['qty']??0)];
}
function finance_pending_summary(int $cid,string $month=''): array {
    [$from,$to,$month]=finance_month_bounds($month?:date('Y-m'));
    finance_ensure_occurrences($cid,$month);
    $missing=(int)(one("SELECT COUNT(*) c FROM transactions WHERE church_id=? AND direction='expense' AND status='posted' AND transaction_date BETWEEN ? AND ? AND receipt_path IS NULL",[$cid,$from,$to])['c']??0);
    $bank=(int)(one("SELECT COUNT(*) c FROM bank_statement_lines WHERE church_id=? AND status='pending' AND transaction_date<=?",[$cid,$to])['c']??0);
    $recurring=(int)(one("SELECT COUNT(*) c FROM recurring_occurrences WHERE church_id=? AND period_month=? AND status='pending'",[$cid,$month])['c']??0);
    $overdue=(int)(one("SELECT COUNT(*) c FROM payables WHERE church_id=? AND status='open' AND due_date<=?",[$cid,min(date('Y-m-d'),$to)])['c']??0);
    $dueSoon=(int)(one("SELECT COUNT(*) c FROM payables WHERE church_id=? AND status='open' AND due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 7 DAY)",[$cid])['c']??0);
    $closed=(bool)one("SELECT id FROM financial_period_closures WHERE church_id=? AND period_month=? AND status='closed'",[$cid,$month]);
    return ['missing_receipts'=>$missing,'bank_pending'=>$bank,'recurring_pending'=>$recurring,'overdue_payables'=>$overdue,'due_soon'=>$dueSoon,'closed'=>$closed,'month'=>$month,'from'=>$from,'to'=>$to];
}
function finance_report_data(int $cid,string $from,string $to): array {
    $totals=finance_period_totals($cid,$from,$to);
    $categories=all("SELECT direction,category,COALESCE(fc.transparency_group,category) transparency_group,SUM(amount) total,COUNT(*) qty
      FROM transactions t LEFT JOIN finance_categories fc ON fc.id=t.category_id
      WHERE t.church_id=? AND t.status='posted' AND t.transaction_date BETWEEN ? AND ?
      GROUP BY direction,category,transparency_group ORDER BY direction,total DESC",[$cid,$from,$to]);
    $funds=all("SELECT COALESCE(f.name,'Sem fundo') name,
      SUM(CASE WHEN t.direction='income' THEN t.amount ELSE 0 END) income,
      SUM(CASE WHEN t.direction='expense' THEN t.amount ELSE 0 END) expense
      FROM transactions t LEFT JOIN finance_funds f ON f.id=t.fund_id
      WHERE t.church_id=? AND t.status='posted' AND t.transaction_date BETWEEN ? AND ?
      GROUP BY COALESCE(f.name,'Sem fundo') ORDER BY name",[$cid,$from,$to]);
    $centers=all("SELECT COALESCE(c.name,'Sem centro de custo') name,
      SUM(CASE WHEN t.direction='expense' THEN t.amount ELSE 0 END) expense
      FROM transactions t LEFT JOIN cost_centers c ON c.id=t.cost_center_id
      WHERE t.church_id=? AND t.status='posted' AND t.transaction_date BETWEEN ? AND ?
      GROUP BY COALESCE(c.name,'Sem centro de custo') ORDER BY expense DESC",[$cid,$from,$to]);
    $moves=all("SELECT t.*,m.name member_name,a.name account_name,f.name fund_name,cc.name cost_center_name,u.name created_by_name
      FROM transactions t
      LEFT JOIN members m ON m.id=t.member_id
      LEFT JOIN accounts a ON a.id=t.account_id
      LEFT JOIN finance_funds f ON f.id=t.fund_id
      LEFT JOIN cost_centers cc ON cc.id=t.cost_center_id
      LEFT JOIN users u ON u.id=t.created_by
      WHERE t.church_id=? AND t.transaction_date BETWEEN ? AND ?
      ORDER BY t.transaction_date,t.id",[$cid,$from,$to]);
    $payables=all("SELECT * FROM payables WHERE church_id=? AND status='open' AND due_date<=? ORDER BY due_date",[$cid,$to]);
    $budgets=all("SELECT b.*,fc.name category_name,
      (SELECT COALESCE(SUM(t.amount),0) FROM transactions t WHERE t.church_id=b.church_id AND t.status='posted' AND t.direction=b.direction AND t.transaction_date BETWEEN ? AND ? AND (t.category_id=b.category_id OR (t.category_id IS NULL AND t.category=b.category))) actual
      FROM finance_budgets b LEFT JOIN finance_categories fc ON fc.id=b.category_id
      WHERE b.church_id=? AND b.year=? AND b.month=? ORDER BY b.direction,b.category",[$from,$to,$cid,(int)substr($from,0,4),(int)substr($from,5,2)]);
    $pending=finance_pending_summary($cid,substr($from,0,7));
    $closure=one("SELECT * FROM financial_period_closures WHERE church_id=? AND period_month=? ORDER BY id DESC LIMIT 1",[$cid,substr($from,0,7)]);
    return compact('totals','categories','funds','centers','moves','payables','budgets','pending','closure');
}
function finance_transparency_snapshot(int $cid,string $from,string $to): array {
    $church=one('SELECT name,legal_name,city,state FROM churches WHERE id=?',[$cid])?:[];
    $data=finance_report_data($cid,$from,$to);
    $cat=[];
    foreach($data['categories'] as $c){
        $group=(string)($c['transparency_group']?:$c['category']);
        $key=$c['direction'].'|'.$group;
        if(!isset($cat[$key]))$cat[$key]=['direction'=>$c['direction'],'name'=>$group,'total'=>0.0];
        $cat[$key]['total']+=(float)$c['total'];
    }
    $needs=all("SELECT title,description,quantity,estimated_amount,priority,status FROM church_needs WHERE church_id=? AND status IN ('requested','approved') ORDER BY FIELD(priority,'urgent','high','normal','low'),id DESC LIMIT 30",[$cid]);
    $budgetPlanned=0.0;$budgetActual=0.0;
    foreach($data['budgets'] as $b){if($b['direction']==='expense'){$budgetPlanned+=(float)$b['planned_amount'];$budgetActual+=(float)$b['actual'];}}
    return [
      'church'=>['name'=>(string)($church['name']??'Igreja'),'legal_name'=>(string)($church['legal_name']??''),'city'=>(string)($church['city']??''),'state'=>(string)($church['state']??'')],
      'period'=>['from'=>$from,'to'=>$to],
      'totals'=>$data['totals'],
      'categories'=>array_values($cat),
      'funds'=>array_map(fn($f)=>['name'=>(string)$f['name'],'income'=>(float)$f['income'],'expense'=>(float)$f['expense']],$data['funds']),
      'budget'=>['planned'=>$budgetPlanned,'actual'=>$budgetActual],
      'needs'=>array_map(fn($n)=>['title'=>(string)$n['title'],'description'=>(string)($n['description']??''),'quantity'=>(float)$n['quantity'],'estimated_amount'=>(float)$n['estimated_amount'],'priority'=>(string)$n['priority'],'status'=>(string)$n['status']],$needs),
      'generated_at'=>date('c')
    ];
}
function finance_parse_statement(string $tmp,string $filename): array {
    $ext=strtolower(pathinfo($filename,PATHINFO_EXTENSION));
    $lines=[];
    if($ext==='ofx'){
        $raw=(string)@file_get_contents($tmp);
        preg_match_all('/<STMTTRN>(.*?)(?=<STMTTRN>|<\/BANKTRANLIST>|$)/is',$raw,$blocks);
        foreach($blocks[1]??[] as $b){
            preg_match('/<DTPOSTED>([^<\r\n]+)/i',$b,$dm);
            preg_match('/<TRNAMT>([^<\r\n]+)/i',$b,$am);
            preg_match('/<FITID>([^<\r\n]+)/i',$b,$fm);
            preg_match('/<(?:MEMO|NAME)>([^<\r\n]+)/i',$b,$mm);
            $date=finance_date_parse(trim((string)($dm[1]??'')));
            $amount=finance_decimal($am[1]??0);
            if(!$date||abs($amount)<0.0001)continue;
            $desc=trim(html_entity_decode((string)($mm[1]??'Movimentação bancária'),ENT_QUOTES|ENT_HTML5,'UTF-8'));
            $key=trim((string)($fm[1]??''))?:hash('sha256',$date.'|'.$amount.'|'.$desc);
            $lines[]=['date'=>$date,'amount'=>$amount,'description'=>$desc,'key'=>$key];
        }
        return $lines;
    }
    $fh=@fopen($tmp,'rb'); if(!$fh)return [];
    $first=(string)fgets($fh); rewind($fh);
    $delimiter=substr_count($first,';')>=substr_count($first,',')?';':',';
    $rows=[];while(($row=fgetcsv($fh,0,$delimiter))!==false)$rows[]=$row;fclose($fh);
    if(!$rows)return [];
    $norm=function(string $s):string{$x=@iconv('UTF-8','ASCII//TRANSLIT',$s);$x=$x===false?$s:$x;return strtolower(preg_replace('/[^a-z0-9]+/','',strtolower($x))??'');};
    $headers=array_map(fn($v)=>$norm((string)$v),$rows[0]);
    $find=function(array $names)use($headers){foreach($headers as $i=>$h)if(in_array($h,$names,true))return $i;return null;};
    $di=$find(['data','date','dt']);$ai=$find(['valor','amount','montante']);$hi=$find(['descricao','historico','memo','description','nome']);$debi=$find(['debito','debit']);$credi=$find(['credito','credit']);
    $start=($di!==null||$ai!==null)?1:0;
    if($di===null)$di=0;if($hi===null)$hi=1;if($ai===null&&$debi===null&&$credi===null)$ai=2;
    for($r=$start;$r<count($rows);$r++){
        $row=$rows[$r];$date=finance_date_parse((string)($row[$di]??''));if(!$date)continue;
        if($ai!==null)$amount=finance_decimal($row[$ai]??0);
        else{$credit=finance_decimal($row[$credi]??0);$debit=finance_decimal($row[$debi]??0);$amount=$credit!=0?$credit:-abs($debit);}
        if(abs($amount)<0.0001)continue;
        $desc=trim((string)($row[$hi]??'Movimentação bancária'));
        $key=hash('sha256',$date.'|'.number_format($amount,2,'.','').'|'.$desc.'|'.$r);
        $lines[]=['date'=>$date,'amount'=>$amount,'description'=>$desc,'key'=>$key];
    }
    return $lines;
}
