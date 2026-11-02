<?php
declare(strict_types=1);
require_once __DIR__.'/ChurchCare.php';

function care_field(string $name,string $label,mixed $value='',string $type='text',bool $required=false,array $options=[]): void {
    $id='field-'.$name;
    echo '<div class="field"><label for="'.e($id).'">'.e($label).($required?' *':'').'</label>';
    $attrs=' id="'.e($id).'" name="'.e($name).'"'.($required?' required':'');
    if($type==='select') {
        echo '<select class="select"'.$attrs.'>';
        foreach($options as $k=>$v) echo '<option value="'.e((string)$k).'"'.((string)$value===(string)$k?' selected':'').'>'.e($v).'</option>';
        echo '</select>';
    } elseif($type==='textarea') echo '<textarea class="textarea" maxlength="5000"'.$attrs.'>'.e((string)$value).'</textarea>';
    else echo '<input class="input" type="'.e($type).'"'.$attrs.' value="'.e((string)$value).'"'.($type==='number'?' min="0" max="9999999999.99" step="0.01"':'').'>';
    echo '</div>';
}
function care_heading(string $title,string $description): void {
    echo '<div class="page-head"><div><h1>'.e($title).'</h1><p>'.e($description).'</p></div><button type="button" class="btn btn-light no-print" onclick="window.print()">Imprimir / salvar PDF</button></div>';
}
function care_notice(?string $error): void {
    View::flash();
    if($error) echo '<div class="alert alert-error" role="alert">'.e($error).'</div>';
}
function care_error(Throwable $e): string {
    if($e instanceof InvalidArgumentException) return $e->getMessage();
    if($e instanceof PDOException && (int)($e->errorInfo[1]??0)===1062) return 'Já existe um cadastro com esse nome ou código nesta igreja.';
    error_log('ChurchCare: '.$e->getMessage());
    return 'Não foi possível salvar. Tente novamente.';
}
function care_member_options(ChurchCare $care): array {
    $opts=[''=>'Selecione o membro'];
    foreach($care->all('SELECT id,name,phone FROM members WHERE church_id=? ORDER BY name') as $m) $opts[$m['id']]=$m['name'].' · '.$m['phone'];
    return $opts;
}
