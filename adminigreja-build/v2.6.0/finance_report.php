<?php
declare(strict_types=1);

function finance_pdf_report(int $cid,string $from,string $to,string $mode='attachment'): never {
    require_once __DIR__.'/pdf.php';
    $data=finance_report_data($cid,$from,$to);
    $church=church_doc_profile($cid);
    $logo=logo_file_for_church($cid);
    $pastor=church_leader_name($church,['Pastor Presidente','Presidente','Pastor']);
    $treasurer=church_leader_name($church,['Tesoureiro','Tesouraria']);
    $pdf=new SimplePdf();
    $navy=[15,53,84];$blue=[23,105,210];$green=[19,138,85];$red=[201,71,81];$muted=[96,112,130];$line=[216,226,236];$soft=[246,249,252];$white=[255,255,255];

    $header=function(SimplePdf $p,string $right='')use($church,$logo,$navy,$white){
        $w=$p->width();$p->rect(0,0,$w,72,$navy);$x=28;
        if($logo&&$p->image((string)$logo['full_path'],28,13,44,44))$x=86;
        $p->text($x,30,(string)($church['name']??'Igreja'),17,true,$white);
        $meta=trim(implode(' · ',array_filter([(string)($church['document']??''),(string)($church['phone']??''),(string)($church['email']??'')])));
        if($meta)$p->text($x,48,$meta,7,false,[220,234,244]);
        if($right)$p->textRight($w-28,34,$right,8,true,$white);
    };

    $tot=$data['totals'];
    $pdf->addPage('L');$header($pdf,'Prestação de contas');
    $pdf->text(28,102,'PRESTAÇÃO DE CONTAS FINANCEIRA',19,true,$navy);
    $pdf->text(28,123,'Período: '.br_date($from).' a '.br_date($to).' · Emitido em '.date('d/m/Y H:i'),8.5,false,$muted);
    $pdf->text(28,140,'Demonstrativo administrativo e gerencial gerado a partir dos lançamentos registrados no AdminIgreja.',7.5,false,$muted);

    $cards=[
      ['Saldo inicial',(float)$tot['opening'],$navy],
      ['Entradas',(float)$tot['income'],$green],
      ['Saídas',(float)$tot['expense'],$red],
      ['Saldo final',(float)$tot['closing'],$blue]
    ];
    $x=28;foreach($cards as $c){$pdf->rect($x,164,185,70,$white,$line);$pdf->text($x+13,185,$c[0],8,true,$muted);$pdf->text($x+13,214,money_br($c[1]),16,true,$c[2]);$x+=198;}

    $pdf->text(28,265,'Resumo por categoria',12,true,$navy);
    $y=284;$pdf->rect(28,$y,382,23,$navy);$pdf->text(38,$y+15,'CATEGORIA',7.5,true,$white);$pdf->textRight(397,$y+15,'TOTAL',7.5,true,$white);$y+=23;
    foreach(array_slice($data['categories'],0,10) as $c){$pdf->rect(28,$y,382,22,$white,$line);$label=($c['direction']==='income'?'Entrada · ':'Saída · ').$c['category'];$pdf->text(38,$y+14,cut_text($label,48),7.4,false,[45,58,70]);$pdf->textRight(397,$y+14,money_br((float)$c['total']),7.4,true,$c['direction']==='income'?$green:$red);$y+=22;}

    $pdf->text(438,265,'Fundos / destinações',12,true,$navy);
    $y2=284;$pdf->rect(438,$y2,375,23,$navy);$pdf->text(448,$y2+15,'FUNDO',7.5,true,$white);$pdf->textRight(690,$y2+15,'ENTRADAS',7.5,true,$white);$pdf->textRight(800,$y2+15,'SAÍDAS',7.5,true,$white);$y2+=23;
    foreach(array_slice($data['funds'],0,10) as $f){$pdf->rect(438,$y2,375,22,$white,$line);$pdf->text(448,$y2+14,cut_text((string)$f['name'],30),7.4,false,[45,58,70]);$pdf->textRight(690,$y2+14,money_br((float)$f['income']),7.2,true,$green);$pdf->textRight(800,$y2+14,money_br((float)$f['expense']),7.2,true,$red);$y2+=22;}

    // Página orçamento e controles
    $pdf->addPage('L');$header($pdf,'Orçamento e controles');
    $pdf->text(28,100,'ORÇAMENTO, PENDÊNCIAS E CONTROLES',15,true,$navy);
    $pdf->text(28,121,'Indicadores que ajudam a Tesouraria a conferir se o período está completo antes do fechamento.',8,false,$muted);
    $p=$data['pending'];$labels=[
      ['Comprovantes pendentes',(int)$p['missing_receipts']],
      ['Extrato sem conciliar',(int)$p['bank_pending']],
      ['Recorrências pendentes',(int)$p['recurring_pending']],
      ['Contas vencidas',(int)$p['overdue_payables']]
    ];
    $x=28;foreach($labels as $i=>$c){$pdf->rect($x,148,185,58,$white,$line);$pdf->text($x+12,168,$c[0],7.5,true,$muted);$pdf->text($x+12,193,(string)$c[1],16,true,$c[1]>0?$red:$green);$x+=198;}

    $pdf->text(28,237,'Orçamento previsto x realizado',11,true,$navy);
    $y=255;$heads=['TIPO','CATEGORIA','PREVISTO','REALIZADO','DIFERENÇA'];$xs=[28,88,330,485,640];$ws=[60,242,155,155,173];
    for($i=0;$i<count($heads);$i++){$pdf->rect($xs[$i],$y,$ws[$i],24,$navy);$pdf->text($xs[$i]+5,$y+16,$heads[$i],7,true,$white);}$y+=24;
    foreach(array_slice($data['budgets'],0,10) as $b){$diff=(float)$b['planned_amount']-(float)$b['actual'];for($i=0;$i<count($xs);$i++)$pdf->rect($xs[$i],$y,$ws[$i],23,$white,$line);$vals=[$b['direction']==='income'?'Entrada':'Saída',(string)($b['category_name']?:$b['category']),money_br((float)$b['planned_amount']),money_br((float)$b['actual']),money_br($diff)];for($i=0;$i<count($vals);$i++)$pdf->text($xs[$i]+5,$y+15,cut_text($vals[$i],$i===1?38:18),7,false,[45,58,70]);$y+=23;}
    if(!$data['budgets'])$pdf->text(28,$y+18,'Nenhum orçamento cadastrado para este período.',8,false,$muted);

    $closure=$data['closure'];
    $pdf->text(28,520,'Situação do período: '.($closure&&$closure['status']==='closed'?'FECHADO':'ABERTO'),9,true,$closure&&$closure['status']==='closed'?$green:$red);

    // Movimentações detalhadas
    $cols=[24,62,108,172,280,390,500,590,680,752];$widths=[38,46,64,108,110,110,90,90,72,65];
    $newDetail=function()use($pdf,$header,$navy,$white,$cols,$widths){
        $pdf->addPage('L');$header($pdf,'Movimentações detalhadas');$y=91;
        $heads=['REF.','DATA','TIPO','CATEGORIA','DESCRIÇÃO','MEMBRO/FORNEC.','FUNDO','C. CUSTO','RECIBO','VALOR'];
        for($i=0;$i<count($heads);$i++){$pdf->rect($cols[$i],$y,$widths[$i],24,$navy);$pdf->text($cols[$i]+4,$y+16,$heads[$i],6.5,true,$white);}
        return $y+24;
    };
    $y=$newDetail();$row=0;
    foreach($data['moves'] as $m){
        if($y>540)$y=$newDetail();
        $in=$m['direction']==='income';$who=$in?(string)($m['member_name']??''):(string)($m['supplier']??'');
        $bg=($row++%2)?$soft:$white;
        for($i=0;$i<count($cols);$i++)$pdf->rect($cols[$i],$y,$widths[$i],25,$bg,$line);
        $vals=['#'.str_pad((string)$m['id'],5,'0',STR_PAD_LEFT),br_date((string)$m['transaction_date']),$in?'Entrada':'Saída',(string)$m['category'],(string)$m['description'],$who?:'—',(string)($m['fund_name']??'—'),(string)($m['cost_center_name']??'—'),!empty($m['receipt_path'])?'Sim':'—',($in?'+ ':'- ').money_br((float)$m['amount'])];
        for($i=0;$i<count($vals);$i++){$limit=[8,10,10,18,19,18,14,14,7,16][$i];$pdf->text($cols[$i]+4,$y+16,cut_text($vals[$i],$limit),6.1,$i===9,$i===9?($in?$green:$red):[45,58,70]);}
        $y+=25;
    }

    // Encerramento
    $pdf->addPage('P');$header($pdf,'Conferência e assinaturas');
    $pdf->text(34,112,'CONFERÊNCIA DO PERÍODO',15,true,$navy);
    $pdf->text(34,140,'Saldo inicial',8,true,$muted);$pdf->textRight(555,140,money_br((float)$tot['opening']),9,true,$navy);
    $pdf->line(34,150,555,150,$line);
    $pdf->text(34,172,'Total de entradas',8,true,$muted);$pdf->textRight(555,172,money_br((float)$tot['income']),9,true,$green);
    $pdf->line(34,182,555,182,$line);
    $pdf->text(34,204,'Total de saídas',8,true,$muted);$pdf->textRight(555,204,money_br((float)$tot['expense']),9,true,$red);
    $pdf->line(34,214,555,214,$line);
    $pdf->text(34,236,'Saldo final',9,true,$navy);$pdf->textRight(555,236,money_br((float)$tot['closing']),11,true,$blue);
    $pdf->text(34,286,'Pendências automáticas na emissão: '.((int)$p['missing_receipts']+(int)$p['bank_pending']+(int)$p['recurring_pending']+(int)$p['overdue_payables']),8,true,$navy);
    $pdf->text(34,308,'Este demonstrativo é administrativo/gerencial e deve ser conciliado com a escrituração contábil da entidade.',7.5,false,$muted);
    $pdf->line(55,450,270,450,[80,90,100]);$pdf->line(325,450,540,450,[80,90,100]);
    $pdf->text(55+(215-$pdf->textWidth($treasurer?:'Tesoureiro(a)',9))/2,472,$treasurer?:'Tesoureiro(a)',9,true,$navy);
    $pdf->text(325+(215-$pdf->textWidth($pastor?:'Pastor / Responsável',9))/2,472,$pastor?:'Pastor / Responsável',9,true,$navy);
    $pdf->text(55+(215-$pdf->textWidth('Tesouraria',7))/2,489,'Tesouraria',7,false,$muted);
    $pdf->text(325+(215-$pdf->textWidth('Responsável pela igreja',7))/2,489,'Responsável pela igreja',7,false,$muted);

    audit('generate_professional_financial_pdf','report',null,['from'=>$from,'to'=>$to]);
    $pdf->output('Prestacao-de-Contas-'.$from.'-a-'.$to.'.pdf',$mode==='inline'?'inline':'attachment');
}
