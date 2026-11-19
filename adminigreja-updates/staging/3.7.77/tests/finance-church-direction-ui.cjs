'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const {File}=require('node:buffer');
const source=fs.readFileSync(require('node:path').join(__dirname,'../public/assets/finance-ocr-3752.js'),'utf8');
const e2e='E12345678'+'A'.repeat(23);
function harness({ocrFailure=null,responseFields=null}={}){
  const requestCalls=[],readCalls=[],listeners={},fields={};
  const state={files:[],value:''};
  const input={addEventListener(name,fn){listeners[name]=fn},click(){}};
  Object.defineProperty(input,'files',{get(){return state.files},set(value){state.files=Array.from(value)}});
  Object.defineProperty(input,'value',{get(){return state.value},set(value){state.value=value;if(!value)state.files=[]}});
  function node(){return {hidden:true,disabled:false,textContent:'',innerHTML:'',style:{},listeners:{},
    addEventListener(name,callback){this.listeners[name]=callback},
    dispatchEvent(){},setAttribute(k,v){this[k]=v},scrollIntoView(){}}}
  const status=node(),list=node(),duplicates=node(),review=node(),precise=node(),help=node(),reselect=node();
  for(const name of ['document_number','bank_reference_type','transaction_date','amount','counterparty','direction','category','payment_method','document_type','description']){
    fields[name]=Object.assign(node(),{value:''});
  }
  fields._csrf=Object.assign(node(),{name:'_csrf',value:'testing-only'});
  const map={
    'input[name="document_file"]':input,'[data-fiscal-status]':status,
    '[data-fiscal-files]':list,'[data-fiscal-duplicates]':duplicates,
    '[data-fiscal-review]':review,'[data-fiscal-precise]':precise,
    '[data-fiscal-file-help]':help,'[data-fiscal-reselect]':reselect,
    'input[name="_csrf"]':fields._csrf
  };
  const form={
    querySelector(query){
      if(map[query])return map[query];
      const name=/^\[name="(.+)"\]$/.exec(query)?.[1];
      return fields[name]||null;
    },
    addEventListener(event,fn){listeners[event]=fn}
  };
  class DataTransferMock {
    constructor(){this.files=[];this.items={add:f=>this.files.push(f)}}
  }
  const context=vm.createContext({
    File,DataTransfer:DataTransferMock,FormData,AbortController,Event:class {},
    HTMLSelectElement:class {},Number,console,
    window:{IgrejaLocalOCR:{
      extract:async(file,options)=>{
        readCalls.push(file);
        if(ocrFailure)throw ocrFailure;
        options.onStatus('OCR local reconhecendo');
        return {text:'Pix enviado\nValor: R$ 55,00\nE2E: '+e2e,
          fileHash:'a'.repeat(64),confidence:87,
          warnings:[],source:'pdf_text',pages:1}
      }
    }},
    document:{querySelector:query=>
      query==='form[data-finance-attachment][data-fiscal-autofill]'?form:null},
    fetch:async(url,options)=>{
      requestCalls.push({url,options});
      assert.equal(url,'/finance-scan.php','text goes only to own same-origin PHP');
      const action=options.body.get('action');
      const local=action==='scan';
      const result={
        ok:true,engine:local?'local_ocr':action==='scan_file'?'gemini_vision':'duplicate_check',
        fields:local?(responseFields||{
          document_type:'proof',document_number:e2e,bank_reference_type:'pix_e2e',
          bank_reference:e2e,amount:'55.00',date:'2026-11-18',
          issuer:'Banco Exemplo',warnings:[]
        }):{},
        file_hash:'a'.repeat(64),duplicates:[],blocking:false,message:'Leitura concluída'
      };
      return {ok:true,json:async()=>result};
    }
  });
  vm.runInContext(source,context,{filename:'finance-ocr-3752.js'});
  assert.equal(typeof listeners.change,'function','document picker is listening');
  async function select(file){
    state.files=file?[file]:[];state.value=file?file.name:'';
    await listeners.change();
    for(let i=0;i<12;i++)await new Promise(r=>setImmediate(r));
  }
  async function triggerAI(){
    precise.listeners.click();
    for(let i=0;i<15;i++)await new Promise(r=>setImmediate(r));
  }
  return {select,triggerAI,input,status,precise,readCalls,requests:requestCalls,fields,help,review};
}
(async()=>{
  const original=new File(['%PDF-1.4\nsample digital PDF'],'documento.pdf',{type:'application/pdf'});
  const h=harness();
  await h.select(original);
  assert.equal(h.readCalls.length,1,'automatic scanning runs locally');
  assert.equal(h.requests.length,2,'one local text parse + one duplicate check. UI STATUS: '+h.status.textContent+' | REVIEW: '+h.review.innerHTML.slice(0,170));
  assert.equal(h.requests[0].options.body.get('action'),'scan','default is local parse');
  assert(!h.requests.some(x=>x.options.body.get('action')==='scan_file'),
    'no external IA request is ever triggered automatically');
  assert.match(h.requests[0].options.body.get('ocr_text'),/Valor: R\$ 55,00/);
  assert.equal(h.requests[0].options.body.get('file_hash'),'a'.repeat(64));
  assert.equal(h.fields.document_number.value,e2e,'bank reference filled');
  assert.equal(h.fields.amount.value,'55,00','BRL filled');
  assert.match(h.review.innerHTML,/leitor próprio/);
  assert.match(h.status.textContent,/sem gastar créditos/);
  assert.equal(h.precise.hidden,false,'AI available as optional button');
  assert.equal(h.input.files[0].size,original.size,'original attachment remains available');
  await h.triggerAI();
  assert.equal(h.requests.filter(x=>x.options.body.get('action')==='scan_file').length,1,
    'only clicking user controls sends document to paid AI');

  const localFailure=harness({ocrFailure:new Error('Falha na foto')});
  await localFailure.select(original);
  assert.equal(localFailure.requests.length,0,'OCR failure does not silently send document to external AI');
  assert.equal(localFailure.precise.hidden,false,'manual AI option available after failed local reading');
  assert.match(localFailure.status.textContent,/IA opcional/);
  const sent=harness({responseFields:{
    document_type:'proof',bank_reference_type:'pix_e2e',document_number:e2e,
    payer:'IGREJA DE DEUS NO BRASIL - CAMPO BELO',receiver:'IGREJA DE DEUS NO BRASIL',
    direction:'expense',suggested_direction:'expense',direction_source:'church_is_payer',
    direction_reason:'A igreja pagou. Sugerimos SAÍDA.',
    issuer:'IGREJA DE DEUS NO BRASIL',amount:'2172.03',date:'2026-11-18',
    warnings:[]
  }});
  await sent.select(original);
  assert.equal(sent.fields.direction.value,'expense','church as payer preselects expense');
  assert.equal(sent.fields.counterparty.value,'IGREJA DE DEUS NO BRASIL','receiver used as counterparty');
  assert.match(sent.review.innerHTML,/Sugestão de lançamento: SAÍDA/,'explicit expense suggestion shown');
  assert.match(sent.review.innerHTML,/Pagador do Pix/,'payer and receiver are visible before posting');
  assert(!sent.requests.some(x=>x.options.body.get('action')==='scan_file'),
    'payer direction runs without external AI');

  const received=harness({responseFields:{
    document_type:'proof',payer:'MARIA',receiver:'IGREJA DE DEUS NO BRASIL - CAMPO BELO',
    direction:'income',suggested_direction:'income',direction_source:'church_is_receiver',
    direction_reason:'Igreja recebeu. Sugerimos ENTRADA.',
    issuer:'MARIA',amount:'100.00',date:'2026-11-18',warnings:[]
  }});
  await received.select(original);
  assert.equal(received.fields.direction.value,'income','church as receiver preselects income');
  assert.match(received.review.innerHTML,/Sugestão de lançamento: ENTRADA/,'income explanation shown');

  const internal=harness({responseFields:{
    document_type:'proof',payer:'IGREJA DE DEUS - CAMPO BELO',receiver:'IGREJA DE DEUS - CAMPO BELO',
    direction:null,suggested_direction:null,direction_source:'both_parties_church',
    direction_reason:'Pode ser transferência interna; confirme antes de salvar.',
    amount:'100.00',warnings:['Não contabilizar duas vezes.']
  }});
  await internal.select(original);
  assert.equal(internal.fields.direction.value,'','internal transfer not silently classified');
  assert.equal(internal.fields.direction.required,true,'internal transfer requires explicit type selection');
  assert.match(internal.review.innerHTML,/precisa de conferência/,'explicit review shown for internal transfer');
  assert.match(internal.review.innerHTML,/Nenhum valor foi lançado automaticamente/,'no automatic posting');
  console.log('PASS: local OCR and explicit optional AI, church-payer expense, church-receiver income, internal transfers require manual review, no automatic posting.');
})().catch(e=>{console.error(e);process.exitCode=1});
