"""Igreja Master v3.7.23: repair PIX reporting and re-confirmation flow."""
def once(s,old,new,label):
    n=s.count(old)
    if n!=1:raise RuntimeError(f"PIX patch {label}: expected 1 anchor, found {n}")
    return s.replace(old,new,1)

def patch_payments(source:bytes)->bytes:
    s=source.decode("utf-8")
    s=once(s,"require_once __DIR__.'/lib/FinancePeriodGuard.php';",
        "require_once __DIR__.'/lib/FinancePeriodGuard.php';\n"
        "require_once __DIR__.'/lib/PixPaymentSummary.php';", "include summary service")
    s=once(s,
        "$payment=$p->fetch();",
        "$payment=$p->fetch();if(!$payment)throw new RuntimeException('Pagamento não encontrado nesta igreja.');",
        "reject invalid or cross-church payment update")
    s=once(s,
        "$q->execute($params);$rows=$q->fetchAll();",
        "$q->execute($params);$rows=$q->fetchAll();\n"
        " $summary=PixPaymentSummary::totals($pdo,(string)$cid,$from,$to,$status);\n"
        " $total=$summary['total'];$confirmed=$summary['confirmed'];$pending=$summary['pending'];\n",
        "populate total confirmed pending for all rows, not capped grid")
    s=once(s,"<div class=\"stat-label\">Movimentado no filtro</div>",
        "<div class=\"stat-label\">Total registrado no filtro</div>",
        "label pending/failed as registered not settled")
    s=once(s,
        "$type=trim((string)($_POST['payment_type']??'oferta'))?:'oferta';",
        "$type=trim((string)($_POST['payment_type']??'oferta'))?:'oferta';\n"
        "   if(!in_array($type,['dizimo','oferta','campanha','evento','outro'],true))"
        " throw new InvalidArgumentException('Tipo de pagamento inválido.');",
        "validate PIX purpose at server")
    s=once(s,
        "if($phone===''||$amount<=0) throw new InvalidArgumentException('Telefone e valor válido são obrigatórios.');",
        "if($phone===''||mb_strlen($phone)>40||!is_finite($amount)||$amount<=0||$amount>999999999.99)"
        " throw new InvalidArgumentException('Informe telefone e valor válido (até R$ 999.999.999,99).');",
        "validate reasonable amount and length")
    # Explicitly lock the PIX row for concurrent confirmations and ensure that
    # older installations without UNIQUE(pix_payment_id) never double-post.
    s=once(s,
        "$p=$pdo->prepare('SELECT * FROM pix_payments WHERE id=? AND church_id=? LIMIT 1');",
        "$p=$pdo->prepare('SELECT * FROM pix_payments WHERE id=? AND church_id=? LIMIT 1 FOR UPDATE');",
        "lock one PIX payment against duplicate confirmation")
    replacement="""       $pixDescription='PIX - '.(($payment['name']??'')?:$payment['phone']);
       $pixDate=date('Y-m-d',strtotime($payment['paid_at']?:$payment['created_at']));
       $findPix=$pdo->prepare('SELECT id FROM finance_entries WHERE church_id=? AND pix_payment_id=? FOR UPDATE');
       $findPix->execute([$cid,$payment['id']]);$pixEntryIds=$findPix->fetchAll(PDO::FETCH_COLUMN);
       if(count($pixEntryIds)>1)throw new RuntimeException('Há mais de um lançamento financeiro para o mesmo PIX. Procure a tesouraria antes de confirmar.');
       if($pixEntryIds){
        $f=$pdo->prepare("UPDATE finance_entries SET member_id=?,income_kind=?,category=?,description=?,amount=?,transaction_date=?,status='posted'
          WHERE id=? AND church_id=? AND pix_payment_id=?");
        $f->execute([$payment['member_id']??null,$payment['payment_type'],$category,$pixDescription,$payment['amount'],$pixDate,$pixEntryIds[0],$cid,$payment['id']]);
       }else{
        $f=$pdo->prepare("INSERT INTO finance_entries(id,church_id,member_id,direction,income_kind,category,description,amount,transaction_date,status,source,pix_payment_id,created_at)
          VALUES(?,?,?,'income',?,?,?,?,?,'posted','pix',?,?)");
        $f->execute([app_uuid(),$cid,$payment['member_id']??null,$payment['payment_type'],$category,$pixDescription,$payment['amount'],$pixDate,$payment['id'],$payment['created_at']]);
       }"""
    # Take the full existing PIX SQL block by immutable SQL/parameter anchors.
    marker='INSERT INTO finance_entries(id,church_id,member_id,direction,income_kind'
    if s.count(marker)!=1:raise RuntimeError("Legacy PIX posting column list changed")
    start=s.rfind(chr(10),0,s.index(marker))+1
    if '$f=$pdo->prepare' not in s[start:start+100]:raise RuntimeError('PIX SQL block begins at wrong boundary')
    endMarker="$payment['created_at']]);"
    end=s.find(endMarker,start)
    if end<0:raise RuntimeError("Legacy PIX params changed")
    end+=len(endMarker)
    if 'ON DUPLICATE KEY UPDATE' not in s[start:end]:raise RuntimeError("Original PIX posting style changed")
    s=s[:start]+replacement+s[end:]
    return s.encode("utf-8")
