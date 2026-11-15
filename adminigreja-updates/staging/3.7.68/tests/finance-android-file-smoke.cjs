'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const {File}=require('node:buffer');
const source=fs.readFileSync(require('node:path').join(__dirname,'../public/assets/finance-ocr-3752.js'),'utf8');

function createHarness(){
  const listeners={},submitted=[],requestCalls=[];
  const state={files:[],value:''};
  const input={
    addEventListener(name,fn){listeners[name]=fn},
    click(){state.clicked=true},
  };
  Object.defineProperty(input,'files',{
    get(){return state.files},
    set(files){state.files=Array.from(files)},
  });
  Object.defineProperty(input,'value',{
    get(){return state.value},
    set(v){state.value=v;if(v==='')state.files=[]},
  });
  function node(){return {hidden:true,textContent:'',innerHTML:'',style:{},disabled:false,
    addEventListener(){},setAttribute(name,value){this[name]=value},
    scrollIntoView(){}};}
  const status=node(),list=node(),duplicates=node(),review=node(),precise=node(),help=node(),reselect=node();
  const form={
    querySelector(name){
      if(name==='input[name="document_file"]')return input;
      const map={'[data-fiscal-status]':status,'[data-fiscal-files]':list,
        '[data-fiscal-duplicates]':duplicates,'[data-fiscal-review]':review,
        '[data-fiscal-precise]':precise,'[data-fiscal-file-help]':help,
        '[data-fiscal-reselect]':reselect};
      return map[name]||null;
    },
    addEventListener(name,fn){submitted.push({name,fn})},
  };
  class DataTransferMock{
    constructor(){
      const files=[];this.files=files;this.items={add:f=>files.push(f)};
    }
  }
  const context=vm.createContext({
    File,DataTransfer:DataTransferMock,FormData,AbortController,
    HTMLSelectElement:class {},Event:class {},
    window:{},document:{querySelector:s=>s==='form[data-finance-attachment][data-fiscal-autofill]'?form:null},
    fetch:async (url,config)=>{
      requestCalls.push({url,config});
      if(url!=='/finance-scan.php')throw Error('Unexpected endpoint '+url);
      return {
        ok:true,
        json:async()=>({
          ok:true,fields:{},file_hash:'a'.repeat(64),
          duplicates:[],blocking:false,message:'Leitura concluída.'
        })
      };
    },
  });
  vm.runInContext(source,context,{filename:'finance-ocr-3752.js'});
  assert.equal(typeof listeners.change,'function','main finance picker change handler is registered');
  async function select(file){
    state.files=file?[file]:[];
    state.value=file?file.name:'';
    await listeners.change();
    // Await the internal pending read and duplicate check promises.
    for(let i=0;i<10;i++)await new Promise(r=>setImmediate(r));
  }
  return {select,input,state,status,list,duplicates,review,precise,help,reselect,requests:requestCalls,submitted};
}
async function main(){
  const valid=createHarness();
  const original=new File(['%PDF-1.4\nsafe local content'],'E000000020261031234136211213224.pdf',{
    type:'application/pdf'
  });
  await valid.select(original);
  assert.equal(valid.requests.length,2,'AI scan plus duplicate check must run after stable byte snapshot');
  assert.equal(valid.requests[0].config.body.get('action'),'scan_file');
  assert.notEqual(valid.input.files[0],original,'replace the ephemeral Android file with an in-memory copy');
  assert.equal(valid.input.files[0].size,original.size,'stable copy should preserve attachment size');
  assert.equal(Buffer.compare(Buffer.from(await valid.input.files[0].arrayBuffer()),
    Buffer.from(await original.arrayBuffer())),0,'stable copy contains original bytes');
  assert.equal(valid.help.hidden,true,'no extra warning on success');
  assert.match(valid.status.textContent,/Leitura concluída|confira/i);
  assert(!valid.status.textContent.includes('A requested file or directory'));
  const missing=createHarness();
  const inaccessible=new File(['%PDF-1.4\nunavailable'],'E000000020261031234136211213224.pdf',{
    type:'application/pdf'
  });
  inaccessible.arrayBuffer=async()=>{
    const e=new Error('A requested file or directory could not be found at the time an operation was processed.');
    e.name='NotFoundError';throw e;
  };
  await missing.select(inaccessible);
  assert.equal(missing.requests.length,0,'unavailable Android file MUST NOT be sent to server');
  assert.equal(missing.input.files.length,0,'unavailable input MUST NOT be submitted with finance form');
  assert.equal(missing.help.hidden,false,'help visible for provider issues');
  assert.equal(missing.reselect.hidden,false,'reselect button is visible');
  assert.match(missing.status.textContent,/Downloads/);
  assert.doesNotMatch(missing.status.textContent,/A requested file or directory/,'hide raw native error');
  assert.match(missing.list.innerHTML,/não foi possível acessar os bytes/i);
  console.log('PASS Android finance PDF: stable copy preserved for AI + final attachment; missing provider triggers safe retry without raw error, without requests.');
}
main().catch(e=>{console.error(e);process.exitCode=1});
