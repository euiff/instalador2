'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const path=require('node:path');

class FakeClassList {
  constructor(){this.classes=new Set();}
  toggle(name,on){if(on)this.classes.add(name);else this.classes.delete(name);}
  contains(name){return this.classes.has(name);}
}
class FakeElement {
  constructor(tag='span'){
    this.tagName=tag.toUpperCase();this.nodeType=1;
    this.parentNode=null;this.children=[];this.dataset={};
    this.attrs={};this.listeners={};this.classList=new FakeClassList();
    this.style={};this.className='';
  }
  appendChild(child){child.parentNode=this;this.children.push(child);return child;}
  insertBefore(child,ref){let i=this.children.indexOf(ref);if(i<0)i=0;child.parentNode=this;this.children.splice(i,0,child);return child;}
  setAttribute(name,value){this.attrs[name]=String(value);}
  addEventListener(name,fn){this.listeners[name]=fn;}
  click(){this.listeners.click();}
  querySelectorAll(){return [];}
  matches(){return false;}
}
class FakeInput extends FakeElement {
  constructor(type='password'){
    super('input');this.type=type;this.value='MinhaSenhaTeste!';this.disabled=false;
    this.focused=0;
  }
  matches(selector){return selector==='input[type="password"]' && this.type==='password';}
  focus(){this.focused++;}
}
const parent=new FakeElement('label');
const login=new FakeInput();
parent.appendChild(login);
const fields=[login];
let observer;
class FakeObserver {
  constructor(cb){this.cb=cb;observer=this;}
  observe(){}
}
const document={
  readyState:'complete',
  documentElement:new FakeElement('html'),
  createElement:(tag)=>new FakeElement(tag),
  querySelectorAll:(selector)=>fields.filter(x=>x.type==='password'&&x.dataset.eyeReady!=='1'),
  addEventListener(){throw Error('DOMContentLoaded not expected for ready page');}
};
const source=fs.readFileSync(path.resolve(process.argv[2]),'utf8');
vm.runInNewContext(source,{document,HTMLInputElement:FakeInput,MutationObserver:FakeObserver});
assert.equal(login.dataset.eyeReady,'1');
const wrap=login.parentNode;
assert.equal(wrap.className,'im-password-eye-wrap');
assert.equal(wrap.children[0],login);
const eye=wrap.children[1];
assert.equal(eye.type,'button');
assert.equal(eye.attrs['aria-label'],'Mostrar senha');
assert.equal(eye.attrs['aria-pressed'],'false');
eye.click();
assert.equal(login.type,'text');
assert.equal(login.value,'MinhaSenhaTeste!');
assert.equal(eye.attrs['aria-pressed'],'true');
assert.equal(eye.attrs['aria-label'],'Ocultar senha');
assert.ok(login.classList.contains('im-password-visible'));
eye.click();
assert.equal(login.type,'password');
assert.equal(eye.attrs['aria-pressed'],'false');
assert.equal(eye.attrs['aria-label'],'Mostrar senha');
assert.equal(login.value,'MinhaSenhaTeste!');
// Re-scanning and mutation events must not duplicate the eye.
observer.cb([{addedNodes:[login]}]);
assert.equal(wrap.children.length,2);
// Input inserted inside a dialog after page load receives the same control.
const modal=new FakeElement('div');
const second=new FakeInput();
modal.appendChild(second);
fields.push(second);
observer.cb([{addedNodes:[second]}]);
assert.equal(second.dataset.eyeReady,'1');
assert.equal(second.parentNode.children[1].tagName,'BUTTON');
second.parentNode.children[1].click();
assert.equal(second.type,'text');
assert.equal(second.value,'MinhaSenhaTeste!');
console.log('PASS password reveal / hide, stable input value, accessible labels, no form submit, dynamically inserted dialogs');
