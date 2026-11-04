<?php
declare(strict_types=1);

/**
 * Offline, automatically available SVG visual guide.
 * Never contains customer data. Approved screenshots supersede this fallback
 * once the Master imports the image pack to the protected course storage.
 */
final class SystemCourseIllustration
{
    private const WIDTH=1360;
    private const HEIGHT=720;

    private static function esc(string $v):string
    {
        return htmlspecialchars($v,ENT_QUOTES|ENT_XML1|ENT_SUBSTITUTE,'UTF-8');
    }

    private static function wrap(string $label,int $limit=41):array
    {
        $words=preg_split('/\s+/u',trim($label))?:[];
        $lines=[];$line='';
        foreach($words as $w){
            if(mb_strlen($line.' '.$w)>$limit && $line!==''){$lines[]=$line;$line=$w;}
            else $line=trim($line.' '.$w);
        }
        if($line!=='')$lines[]=$line;
        return array_slice($lines,0,3);
    }

    public static function svg(array $img):string
    {
        $id=(string)($img['id']??'');
        $title=(string)($img['title']??'Passo a passo');
        $desc=(string)($img['description']??'');
        $guides=[
          '01-painel-inicial'=>['Visão geral','Início','Cadastrar membro','Novo cadastro','Abra o painel e escolha o cadastro'],
          '02-cadastrar-membro'=>['Pessoas e cadastros','Novo membro','Nome completo','Cadastrar membro','Preencha e salve o membro'],
          '03-lista-membros'=>['Pessoas e cadastros','Membros','Buscar membros','Exportar resultados CSV','Use pesquisa e filtros'],
          '04-usuarios-permissoes'=>['Administração','Usuários e permissões','Usuários internos','Novo cadastro','Crie acesso para a equipe'],
          '05-criar-acesso'=>['Administração','Novo acesso','Nome / E-mail / Perfil','Criar usuário','Vincule perfil e pessoa'],
          '06-departamentos'=>['Pessoas e cadastros','Departamentos','Departamentos e ministérios','Novo cadastro','Cadastre o ministério antes dos vínculos'],
          '07-menu-financeiro'=>['Financeiro','Tesourarias dos Departamentos','Pessoas e Lideranças','Caixas dos departamentos','Escolha a área correta do setor'],
          '08-novo-lancamento'=>['Financeiro','Novo lançamento','Entrada ou Saída','Registrar lançamento','Preencha tipo, categoria, valor e data'],
          '09-perfil-membro'=>['Pessoas e cadastros','Perfil do Membro','Editar cadastro','Vincular departamento','Confira dados e vínculos'],
          '10-portal-contador'=>['Financeiro','Portal do Contador','Competência / Livro caixa','Baixar pacote do mês','Revise documentos e pendências'],
          '11-agenda-eventos'=>['Agenda da igreja','Eventos','Agenda de eventos','Novo cadastro','Crie o culto ou evento'],
          '12-cargos-funcoes'=>['Pessoas e cadastros','Cargos e funções','Pesquisar cargos','Novo cadastro','Cadastre o cargo antes de vincular'],
          '13-tesouraria-geral'=>['Financeiro','Tesouraria','Escolher período','Imprimir relatório','Consulte os totais do caixa'],
          '14-prestacao-de-contas'=>['Financeiro','Prestação de contas','Escolher datas e departamento','Consultar','Filtre antes de exportar'],
          '15-pessoas-lideres'=>['Financeiro','Pessoas e Líderes','Departamento cadastrado','Abrir departamento','Escolha o ministério e depois o líder'],
          '16-configuracoes'=>['Administração','Configurações','Dados da igreja','Salvar configurações','Revise nome, contato e responsável'],
          '17-central-360'=>['Visão geral','Central de Gestão 360','Departamentos e Caixas','Acessar módulo','Use os atalhos da central'],
        ];
        $g=$guides[$id]??['Ensino','Curso Oficial','Abra o assunto indicado','Continuar','Siga o passo a passo da aula'];
        $a=self::esc($g[0]);$b=self::esc($g[1]);
        $c=self::esc($g[2]);$d=self::esc($g[3]);
        $tip=self::esc($g[4]);$ttl=self::esc($title);
        $des=self::esc($desc);
        $ys=0;$titleLines='';
        foreach(self::wrap($title,30) as $line){
            $titleLines.='<text x="71" y="'.(207+$ys).'" font-size="36" fill="#102e48" font-weight="800">'.self::esc($line).'</text>';
            $ys+=46;
        }
        $descLines='';$dy=0;
        foreach(self::wrap($desc,44) as $line){
            $descLines.='<text x="72" y="'.(351+$dy).'" font-size="19" fill="#516b81">'.self::esc($line).'</text>';
            $dy+=25;
        }
        $xml='<?xml version="1.0" encoding="UTF-8"?>';
        $xml.='<svg xmlns="http://www.w3.org/2000/svg" width="1360" height="720" viewBox="0 0 1360 720" role="img" aria-labelledby="title desc">';
        $xml.='<title id="title">'.$ttl.'</title><desc id="desc">Ilustração didática sem dados pessoais: '.$des.'</desc>';
        $xml.='<rect width="1360" height="720" fill="#eff4f7"/>';
        $xml.='<rect width="1360" height="92" fill="#09517b"/>';
        $xml.='<rect x="27" y="20" width="54" height="52" rx="11" stroke="#cfe7f6" fill="#146e96"/>';
        $xml.='<text x="54" y="57" font-size="31" text-anchor="middle" fill="#ffffff">✦</text>';
        $xml.='<text x="98" y="57" font-size="31" font-weight="700" fill="#ffffff">Igreja Master</text>';
        $xml.='<text x="495" y="54" font-size="19" fill="#d7f1ff">Guia visual do curso</text>';
        $xml.='<rect x="1018" y="27" width="308" height="40" rx="14" fill="#e3f2f8"/>';
        $xml.='<text x="1172" y="54" text-anchor="middle" font-weight="700" font-size="17" fill="#105475">EXEMPLO SEM DADOS REAIS</text>';
        $xml.='<rect x="33" y="117" width="620" height="528" rx="18" fill="#ffffff" stroke="#dce9f1" stroke-width="2"/>';
        $xml.='<rect x="54" y="139" width="222" height="41" rx="9" fill="#dfeffc"/>';
        $xml.='<text x="165" y="166" text-anchor="middle" font-size="15" font-weight="700" fill="#175475">TUTORIAL PASSO A PASSO</text>';
        $xml.=$titleLines.$descLines;
        $xml.='<rect x="65" y="452" width="535" height="78" rx="13" fill="#edf7ee"/>';
        $xml.='<text x="90" y="482" font-size="17" font-weight="800" fill="#26653e">✓ OBJETIVO DESTA TELA</text>';
        $xml.='<text x="90" y="512" font-size="19" fill="#244d39">'.$tip.'</text>';
        $xml.='<text x="71" y="607" font-size="15" fill="#698092">Imagem demonstrativa da interface; não é um print real.</text>';
        $xml.='<rect x="678" y="117" width="648" height="528" rx="18" fill="#ffffff" stroke="#dce9f1" stroke-width="2"/>';
        $xml.='<rect x="696" y="136" width="612" height="50" rx="8" fill="#e8f1f7"/>';
        $xml.='<text x="713" y="168" font-size="18" font-weight="700" fill="#235a79">'.$a.'  ›  '.$b.'</text>';
        $xml.='<rect x="704" y="209" width="598" height="81" rx="10" stroke="#c5d7e2" fill="#f7fbfd"/>';
        $xml.='<text x="729" y="245" font-size="22" font-weight="700" fill="#163951">'.$b.'</text>';
        $xml.='<text x="729" y="271" font-size="15" fill="#57778c">Área do sistema para este procedimento</text>';
        $xml.='<rect x="714" y="320" width="570" height="94" rx="10" fill="#f6f9fb" stroke="#d8e5ec"/>';
        $xml.='<text x="741" y="350" font-size="16" font-weight="600" fill="#43647a">1. Localize ou preencha o campo</text>';
        $xml.='<rect x="734" y="364" width="530" height="34" rx="8" fill="#ffffff" stroke="#d2dee9"/>';
        $xml.='<text x="747" y="388" font-size="17" fill="#285772">'.$c.'</text>';
        $xml.='<rect x="715" y="445" width="570" height="82" rx="11" fill="#fff9f7" stroke="#f04440" stroke-width="4"/>';
        $xml.='<rect x="891" y="459" width="375" height="53" rx="9" fill="#096d93"/>';
        $xml.='<text x="1078" y="493" text-anchor="middle" font-size="23" font-weight="800" fill="#ffffff">'.$d.'</text>';
        $xml.='<circle cx="724" cy="448" r="27" fill="#e53935" stroke="white" stroke-width="3"/>';
        $xml.='<text x="724" y="458" text-anchor="middle" font-size="28" font-weight="800" fill="white">2</text>';
        $xml.='<path d="M779 473 L881 483" stroke="#dc302c" stroke-width="8" stroke-linecap="round"/>';
        $xml.='<path d="M862 468 L881 483 L859 494" fill="none" stroke="#dc302c" stroke-width="8" stroke-linejoin="round"/>';
        $xml.='<rect x="707" y="557" width="596" height="59" rx="9" fill="#edf5fb"/>';
        $xml.='<text x="731" y="593" font-size="18" font-weight="700" fill="#2a6283">Clique no botão destacado para continuar</text>';
        $xml.='<rect x="0" y="677" width="1360" height="43" fill="#dfeaf1"/>';
        $xml.='<text x="36" y="704" fill="#587388" font-size="15">Curso Oficial • Ilustração segura e genérica • Telas reais aparecem após importar o pacote aprovado</text>';
        $xml.='</svg>';
        return $xml;
    }
}
