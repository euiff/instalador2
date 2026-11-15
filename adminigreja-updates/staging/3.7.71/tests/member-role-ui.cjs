'use strict';
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');
const assert=require('node:assert/strict');
const src=fs.readFileSync(path.join(__dirname,'../public/assets/users-member-autofill.js'),'utf8');

function element(value='',extra={}){
  return {value,required:false,readOnly:false,disabled:false,hidden:false,textContent:'',
    dataset:{},listeners:{},addEventListener(name,fn){this.listeners[name]=fn},...extra};
}
function fixture({storedEmail='',linked=false,roleValue='treasurer',selectedId='m1'}={}){
  const person=element(selectedId,{
    options:[
      {value:'',dataset:{memberName:'',memberEmail:'',memberLogin:'0'}},
      {value:'m1',dataset:{memberName:'Membro Já Cadastrado',memberEmail:storedEmail,memberLogin:linked?'1':'0'}}
    ],
    selectedIndex:selectedId?1:0,
  });
  const name=element('');
  const email=element('');
  const password=element('');
  const role=element(roleValue,{
    options:[
      {value:'',dataset:{roleDescription:''}},
      {value:'treasurer',dataset:{roleDescription:'Tesouraria Geral, relatórios e exclusão auditada'}},
      {value:'accountant',dataset:{roleDescription:'Portal contábil somente leitura'}},
      {value:'department_leader',dataset:{roleDescription:'Somente departamento selecionado'}},
      {value:'member',dataset:{roleDescription:'Portal pessoal e contribuições'}},
    ],selectedIndex:['', 'treasurer','accountant','department_leader','member'].indexOf(roleValue)
  });
  const departments=element([]);
  const hint=element();
  const passHint=element();
  const save=element();
  const preview=element();
  const deptField=element();
  const mapping={
    'select[name="person_id"]':person,
    'input[name="full_name"]':name,
    'input[name="email"]':email,
    'input[name="password"]':password,
    'select[name="church_role"]':role,
    'select[name="department_ids[]"]':departments,
    '[data-user-member-help]':hint,
    '[data-user-password-help]':passHint,
    'button[type="submit"]':save,
    '[data-user-role-preview]':preview,
    '[data-user-department-field]':deptField
  };
  const form={querySelector:key=>mapping[key]??null};
  const context=vm.createContext({
    document:{readyState:'complete',querySelector:key=>key==='form[data-user-create-from-member]'?form:null},
  });
  vm.runInContext(src,context);
  return {person,name,email,password,role,departments,hint,passHint,save,preview,deptField};
}
const existing=fixture({storedEmail:'membro@igreja.exemplo',linked:true});
assert.equal(existing.name.value,'Membro Já Cadastrado');
assert.equal(existing.name.readOnly,true);
assert.equal(existing.email.value,'membro@igreja.exemplo');
assert.equal(existing.email.readOnly,true);
assert.equal(existing.password.disabled,true,'existing member login keeps current password');
assert.match(existing.hint.textContent,/já possui um login/);
assert.match(existing.preview.textContent,/Tesouraria Geral/);
assert.equal(existing.deptField.hidden,true,'non-department role cannot change department scope');
assert.equal(existing.departments.disabled,true);

const accountant=fixture({storedEmail:'contadora@igreja.exemplo',roleValue:'accountant',linked:true});
assert.match(accountant.preview.textContent,/somente leitura/);
assert.equal(accountant.departments.disabled,true,'accountant must not inherit leader departments');

const unlinked=fixture({storedEmail:'',linked:false});
assert.equal(unlinked.name.value,'Membro Já Cadastrado');
assert.equal(unlinked.email.readOnly,false,'when no email ask only for email');
assert.equal(unlinked.password.disabled,false,'new login asks password');
assert.match(unlinked.hint.textContent,/Informe um e-mail pessoal válido/);

const leader=fixture({roleValue:'department_leader'});
assert.equal(leader.person.required,true);
assert.equal(leader.departments.required,true);
assert.equal(leader.departments.disabled,false);
assert.equal(leader.deptField.hidden,false);
assert.match(leader.preview.textContent,/Somente departamento/);
assert.match(leader.save.textContent,/autorizar departamentos/);

const simpleMember=fixture({roleValue:'member'});
assert.equal(simpleMember.person.required,true);
assert.equal(simpleMember.deptField.hidden,true);
assert.match(simpleMember.preview.textContent,/Portal pessoal/);

const blank=fixture({roleValue:'',selectedId:''});
assert.match(blank.preview.textContent,/Selecione o cargo/);
assert.equal(blank.person.required,false);
assert.equal(blank.name.readOnly,false);
assert.equal(blank.email.readOnly,false);
console.log('PASS: existing member selected without retyping, login reuse, accountant read-only preview, treasurer access, department scoping and no accidental admin role.');
