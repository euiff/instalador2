<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/lib/FinanceVisionConfig.php';

$master=$auth->requireRole('super_admin');
$error=null;$notice=flash('success');
if(($_SERVER['REQUEST_METHOD']??'')==='POST'){
    try{
        verify_csrf();
        $action=(string)($_POST['action']??'');
        if($action==='save'){
            FinanceVisionConfig::save((string)($_POST['gemini_api_key']??''));
            flash('success','Chave Gemini salva com segurança. A leitura por IA está habilitada para todas as igrejas da plataforma.');
        }elseif($action==='remove'){
            FinanceVisionConfig::remove();
            flash('success','Chave local desativada. Os documentos já lançados permanecem intactos.');
        }else throw new RuntimeException('Ação inválida.');
        redirect('/master-ai.php');
    }catch(Throwable $e){$error=$e->getMessage();}
}
$source=FinanceVisionConfig::source();
View::header('IA Fiscal - Master',$auth,'master.php');
?>
<div class="page-head">
 <div><h1>Leitura fiscal com inteligência artificial</h1>
 <p>Configure a API Gemini para reconhecer fotos e PDFs de notas e cupons antes do lançamento financeiro.</p></div>
 <a class="btn btn-light" href="/master.php">Voltar ao Master</a>
</div>
<?php if($error):?><div class="alert alert-error"><?=e($error)?></div><?php endif?>
<?php if($notice):?><div class="alert alert-success"><?=e($notice)?></div><?php endif?>
<div class="card card-pad" style="max-width:800px">
 <h2>Conexão da IA</h2>
 <?php if($source!=='none'):?>
 <p><strong style="color:#146b4d">Ativada</strong> — <?=e($source==='environment'?'chave configurada no ambiente PHP do servidor':'chave privada salva pelo Master')?>.</p>
 <?php else:?>
 <p><strong style="color:#944a19">Pendente</strong> — o sistema manterá o preenchimento manual até uma chave válida ser configurada.</p>
 <?php endif?>
 <p>Modelos disponíveis: <strong>Gemini 3.6 Flash</strong> para leitura rápida e <strong>Gemini 3.7 Flash</strong> para reanálise detalhada.</p>
 <p>A chave é mantida no servidor PHP e não aparece para tesoureiros ou membros. Cada igreja visualiza somente seus próprios documentos. A imagem escolhida é enviada ao serviço Gemini para análise, sem publicá-la na internet.</p>
 <?php if($source!=='environment'):?>
 <form method="post" autocomplete="off">
   <?=csrf_field()?><input type="hidden" name="action" value="save">
   <div class="field"><label for="gemini_api_key">Chave da API Gemini *</label>
     <input type="password" id="gemini_api_key" name="gemini_api_key" class="input" minlength="12" maxlength="4096" required autocomplete="new-password" placeholder="Cole aqui a chave criada no Google AI Studio">
     <small>Cole somente a chave copiada do Google AI Studio. Novas chaves de autorização podem ter formato diferente das antigas; o sistema não restringe o prefixo. A chave atual nunca é mostrada. Ao salvar, a anterior é substituída.</small>
   </div>
   <button type="submit" class="btn btn-primary">Salvar chave e ativar leitura IA</button>
 </form>
 <?php if($source==='master'):?>
 <form method="post" style="margin-top:15px" onsubmit="return confirm('Desativar a leitura fiscal por IA até configurar outra chave?');">
   <?=csrf_field()?><input type="hidden" name="action" value="remove">
   <button class="btn btn-light" type="submit">Desativar integração</button>
 </form>
 <?php endif?>
 <?php else:?>
 <p class="muted">A chave vem da variável <code>GEMINI_API_KEY</code> da hospedagem. Para substituí-la, altere essa configuração no cPanel.</p>
 <?php endif?>
 <p style="margin-top:18px"><a href="https://aistudio.google.com/apikey" target="_blank" rel="noopener noreferrer">Criar ou consultar chave no Google AI Studio</a></p>
 <p class="muted">A utilização da API pode ter cobrança conforme o plano e volume contratado com o Google. Recomenda-se um projeto com faturamento e controles de privacidade adequados às informações financeiras das igrejas.</p>
</div>
<?php View::footer();?>
