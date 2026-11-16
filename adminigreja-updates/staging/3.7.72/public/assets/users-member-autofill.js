/* 3.7.20 — use the selected church member instead of asking for the same
 * name and email twice. Server still validates every value and role. */
(function(){
 'use strict';
 function init(){
   const form=document.querySelector('form[data-user-create-from-member]');
   if(!form)return;
   const person=form.querySelector('select[name="person_id"]');
   const name=form.querySelector('input[name="full_name"]');
   const email=form.querySelector('input[name="email"]');
   const password=form.querySelector('input[name="password"]');
   const role=form.querySelector('select[name="church_role"]');
   const departments=form.querySelector('select[name="department_ids[]"]');
   const hint=form.querySelector('[data-user-member-help]');
   const passHint=form.querySelector('[data-user-password-help]');
   const save=form.querySelector('button[type="submit"]');
   if(!person||!name||!email||!role)return;
   function refresh(){
      const option=person.options[person.selectedIndex];
      const selected=!!person.value;
      const knownName=selected?(option.dataset.memberName||''):'';
      const storedEmail=selected?(option.dataset.memberEmail||''):'';
      const existing=selected&&option.dataset.memberLogin==='1';
      if(selected){
        name.value=knownName;
        name.readOnly=true;
        if(storedEmail){email.value=storedEmail;email.readOnly=true;}
        else {email.readOnly=false;if(email.dataset.memberWasFilled==='1')email.value='';}
        email.dataset.memberWasFilled=storedEmail?'1':'0';
        if(hint)hint.textContent=existing
          ?'Este membro já possui um login. O sistema aproveitará o acesso existente; a senha atual continua valendo.'
          :(storedEmail
            ?'Nome e e-mail foram preenchidos pelo cadastro do membro. Não é necessário digitar novamente.'
            :'Nome preenchido automaticamente. Informe um e-mail pessoal válido para criar o acesso.');
        if(passHint)passHint.textContent=existing
          ?'Este membro já tem senha: o sistema não trocará a senha existente.'
          :'A senha inicial será necessária apenas se ainda não houver uma conta com este e-mail.';
        if(existing&&password){password.value='';password.disabled=true;}
        else if(password){password.disabled=false;}
      }else{
        if(name.readOnly)name.value='';
        if(email.readOnly)email.value='';
        name.readOnly=false;email.readOnly=false;
        if(password)password.disabled=false;
        email.dataset.memberWasFilled='0';
        if(hint)hint.textContent='Para criar um acesso vinculado a uma pessoa já cadastrada, selecione-a nesta lista.';
        if(passHint)passHint.textContent='Para uma conta nova, informe uma senha inicial de pelo menos 8 caracteres.';
      }
      const isLeader=role.value==='department_leader';
      person.required=isLeader;
      if(departments)departments.required=isLeader;
      if(save)save.textContent=isLeader?'Salvar líder e vincular ao departamento':'Criar ou atualizar acesso desta igreja';
      if(isLeader&&hint&&selected)hint.textContent+=' O vínculo de líder será salvo no(s) departamento(s) selecionado(s), sem liberar a tesouraria geral.';
   }
   person.addEventListener('change',refresh);
   role.addEventListener('change',refresh);
   refresh();
 }
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init,{once:true});
 else init();
})();
