<?php

declare(strict_types=1);

final class BibleReadingService
{
    private const BOOKS = [
        ['Gênesis',50],['Êxodo',40],['Levítico',27],['Números',36],['Deuteronômio',34],['Josué',24],['Juízes',21],['Rute',4],['1 Samuel',31],['2 Samuel',24],['1 Reis',22],['2 Reis',25],['1 Crônicas',29],['2 Crônicas',36],['Esdras',10],['Neemias',13],['Ester',10],['Jó',42],['Salmos',150],['Provérbios',31],['Eclesiastes',12],['Cânticos',8],['Isaías',66],['Jeremias',52],['Lamentações',5],['Ezequiel',48],['Daniel',12],['Oseias',14],['Joel',3],['Amós',9],['Obadias',1],['Jonas',4],['Miqueias',7],['Naum',3],['Habacuque',3],['Sofonias',3],['Ageu',2],['Zacarias',14],['Malaquias',4],
        ['Mateus',28],['Marcos',16],['Lucas',24],['João',21],['Atos',28],['Romanos',16],['1 Coríntios',16],['2 Coríntios',13],['Gálatas',6],['Efésios',6],['Filipenses',4],['Colossenses',4],['1 Tessalonicenses',5],['2 Tessalonicenses',3],['1 Timóteo',6],['2 Timóteo',4],['Tito',3],['Filemom',1],['Hebreus',13],['Tiago',5],['1 Pedro',5],['2 Pedro',3],['1 João',5],['2 João',1],['3 João',1],['Judas',1],['Apocalipse',22],
    ];

    private const API_NAMES = [
        'Gênesis'=>'Genesis','Êxodo'=>'Exodus','Levítico'=>'Leviticus','Números'=>'Numbers','Deuteronômio'=>'Deuteronomy','Josué'=>'Joshua','Juízes'=>'Judges','Rute'=>'Ruth','1 Samuel'=>'1 Samuel','2 Samuel'=>'2 Samuel','1 Reis'=>'1 Kings','2 Reis'=>'2 Kings','1 Crônicas'=>'1 Chronicles','2 Crônicas'=>'2 Chronicles','Esdras'=>'Ezra','Neemias'=>'Nehemiah','Ester'=>'Esther','Jó'=>'Job','Salmos'=>'Psalms','Provérbios'=>'Proverbs','Eclesiastes'=>'Ecclesiastes','Cânticos'=>'Song of Solomon','Isaías'=>'Isaiah','Jeremias'=>'Jeremiah','Lamentações'=>'Lamentations','Ezequiel'=>'Ezekiel','Daniel'=>'Daniel','Oseias'=>'Hosea','Joel'=>'Joel','Amós'=>'Amos','Obadias'=>'Obadiah','Jonas'=>'Jonah','Miqueias'=>'Micah','Naum'=>'Nahum','Habacuque'=>'Habakkuk','Sofonias'=>'Zephaniah','Ageu'=>'Haggai','Zacarias'=>'Zechariah','Malaquias'=>'Malachi','Mateus'=>'Matthew','Marcos'=>'Mark','Lucas'=>'Luke','João'=>'John','Atos'=>'Acts','Romanos'=>'Romans','1 Coríntios'=>'1 Corinthians','2 Coríntios'=>'2 Corinthians','Gálatas'=>'Galatians','Efésios'=>'Ephesians','Filipenses'=>'Philippians','Colossenses'=>'Colossians','1 Tessalonicenses'=>'1 Thessalonians','2 Tessalonicenses'=>'2 Thessalonians','1 Timóteo'=>'1 Timothy','2 Timóteo'=>'2 Timothy','Tito'=>'Titus','Filemom'=>'Philemon','Hebreus'=>'Hebrews','Tiago'=>'James','1 Pedro'=>'1 Peter','2 Pedro'=>'2 Peter','1 João'=>'1 John','2 João'=>'2 John','3 João'=>'3 John','Judas'=>'Jude','Apocalipse'=>'Revelation',
    ];

    public function allBooks(): array
    {
        return self::BOOKS;
    }

    public function planLabel(string $plan): string
    {
        return match($plan){
            'new_testament'=>'Novo Testamento',
            'psalms_proverbs'=>'Salmos e Provérbios',
            'full_bible_1year'=>'Bíblia Completa em 1 Ano',
            default=>'Bíblia Completa',
        };
    }

    public function firstForPlan(string $plan): array
    {
        $books=$this->booksForPlan($plan);
        return ['book'=>$books[0][0]??'Gênesis','chapter'=>1];
    }

    public function parseReference(string $value): ?array
    {
        $value=trim(preg_replace('/\s+/u',' ',$value)??$value);
        if($value==='')return null;
        if(!preg_match('/^(.+?)\s+(\d{1,3})$/u',$value,$m))return null;

        $typed=$this->normalize((string)$m[1]);
        $chapter=(int)$m[2];
        foreach(self::BOOKS as [$book,$chapters]){
            $normalized=$this->normalize($book);
            $aliases=$this->aliases($book);
            if($typed===$normalized||in_array($typed,$aliases,true)){
                if($chapter<1||$chapter>(int)$chapters)return null;
                return ['book'=>$book,'chapter'=>$chapter];
            }
        }
        return null;
    }

    private function normalize(string $value): string
    {
        $ascii=iconv('UTF-8','ASCII//TRANSLIT',mb_strtolower(trim($value),'UTF-8'));
        $ascii=$ascii!==false?$ascii:$value;
        $ascii=preg_replace('/[^a-z0-9]+/',' ',strtolower($ascii))??'';
        return trim(preg_replace('/\s+/',' ',$ascii)??$ascii);
    }

    private function aliases(string $book): array
    {
        $map=[
            'Gênesis'=>['genesis','gen'],
            'Êxodo'=>['exodo','exo'],
            'Levítico'=>['levitico','lev'],
            'Números'=>['numeros','num'],
            'Deuteronômio'=>['deuteronomio','deut','dt'],
            'Josué'=>['josue'],
            'Juízes'=>['juizes'],
            'Salmos'=>['salmo','salmos','sl'],
            'Provérbios'=>['proverbios','prov','pv'],
            'Eclesiastes'=>['eclesiastes','ec'],
            'Cânticos'=>['canticos','cantares','ct'],
            'Isaías'=>['isaias','is'],
            'Jeremias'=>['jeremias','jr'],
            'Ezequiel'=>['ezequiel','ez'],
            'Oseias'=>['oseias','os'],
            'Amós'=>['amos'],
            'Miqueias'=>['miqueias','mq'],
            'Naum'=>['naum'],
            'Habacuque'=>['habacuque','hc'],
            'Sofonias'=>['sofonias','sf'],
            'Ageu'=>['ageu','ag'],
            'Zacarias'=>['zacarias','zc'],
            'Malaquias'=>['malaquias','ml'],
            'Mateus'=>['mateus','mt'],
            'Marcos'=>['marcos','mc'],
            'Lucas'=>['lucas','lc'],
            'João'=>['joao','jo','j'],
            'Atos'=>['atos','at'],
            'Romanos'=>['romanos','rm'],
            'Gálatas'=>['galatas','gl'],
            'Efésios'=>['efesios','ef'],
            'Filipenses'=>['filipenses','fp'],
            'Colossenses'=>['colossenses','cl'],
            'Tiago'=>['tiago','tg'],
            'Apocalipse'=>['apocalipse','ap'],
        ];
        $aliases=$map[$book]??[];
        $normalized=$this->normalize($book);
        if(preg_match('/^(\d)\s+(.+)$/',$normalized,$m)){
            $aliases[]=$m[1].' '.$m[2];
            $aliases[]=$m[1].$m[2];
        }
        return array_values(array_unique(array_map(fn($v)=>$this->normalize($v),$aliases)));
    }

    public function booksForPlan(string $plan): array
    {
        return match($plan){
            'new_testament'=>array_slice(self::BOOKS,39),
            'psalms_proverbs'=>array_values(array_filter(self::BOOKS,fn($b)=>in_array($b[0],['Salmos','Provérbios'],true))),
            default=>self::BOOKS,
        };
    }

    public function next(string $book,int $chapter,string $plan): array
    {
        $books=$this->booksForPlan($plan);$idx=null;
        foreach($books as $i=>$b){if($b[0]===$book){$idx=$i;break;}}
        if($idx===null)return ['book'=>$books[0][0],'chapter'=>1,'completed'=>false];
        if($chapter<$books[$idx][1])return ['book'=>$book,'chapter'=>$chapter+1,'completed'=>false];
        if(isset($books[$idx+1]))return ['book'=>$books[$idx+1][0],'chapter'=>1,'completed'=>false];
        return ['book'=>$books[0][0],'chapter'=>1,'completed'=>true];
    }

    public function chapterText(string $book,int $chapter): string
    {
        $api=self::API_NAMES[$book]??$book;
        $url='https://bible-api.com/'.rawurlencode($api.' '.$chapter).'?translation=almeida';
        $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15]);$raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
        if($raw!==false&&$status>=200&&$status<300){
            $d=json_decode((string)$raw,true);$verses=$d['verses']??[];
            if(is_array($verses)&&$verses){$lines=['📖 *'.$book.' '.$chapter.'*',''];foreach($verses as $v){$lines[]=(string)($v['verse']??'').' '.trim((string)($v['text']??''));}$lines[]='';$lines[]='💭 *Reflexão:* Medite nesta passagem e permita que a Palavra de Deus transforme seu coração hoje.';return implode("\n",$lines);}
        }
        return '📖 *'.$book.' '.$chapter."*\n\nLeia o capítulo completo em sua Bíblia.\n\n🙏 _Boa leitura!_";
    }
}
