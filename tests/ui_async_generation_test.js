'use strict';
const fs=require('fs'),vm=require('vm'),assert=require('assert');
const html=fs.readFileSync('LCNClimateControl/module.html','utf8');
function extract(name,next) {
  const a=html.indexOf('function '+name+'('), b=html.indexOf('function '+next+'(',a+5);
  if(a<0||b<0)throw Error('missing function '+name);
  return html.slice(a,b);
}
const callbacks=[];
const ctx={
  Date, JSON,Error,Number,String,
  actionInFlight:null, actionGeneration:0, actionTimeout:0,
  lastActionKey:'',lastActionTime:0, ACTION_GUARD_MS:0,ACTION_TIMEOUT_MS:6000,
  requestAction:(id,value)=>({then:()=>({catch:(cb)=>callbacks.push(cb)})}),
  window:{setTimeout:()=>12,clearTimeout:()=>{}},
  actionKey:(id,value)=>id+value,
  scheduleQueuedAction:()=>{},rollbackOptimisticAction:(action)=>ctx.rollbacks.push(action.ident),
  rollbacks:[],showWarning:()=>{},
  clearActionInFlight:()=>{ctx.actionGeneration++;ctx.actionInFlight=null;},
};
vm.createContext(ctx);
vm.runInContext(extract('dispatchAction','sendAction'),ctx);
ctx.dispatchAction({ident:'Mode',value:1});
assert.equal(callbacks.length,1,'first callback installed');
ctx.clearActionInFlight();
ctx.dispatchAction({ident:'Mode',value:0});
assert.equal(callbacks.length,2,'second callback installed');
callbacks[0](new Error('late rejection'));
assert.equal(ctx.actionInFlight.value,0,'old callback destroyed newer action');
assert.equal(ctx.rollbacks.length,0,'old callback rolled back newer action');
callbacks[1](new Error('current rejection'));
assert.equal(ctx.actionInFlight,null);
assert.equal(ctx.rollbacks.length,1,'current failure rollback missing');
const s2={actionInFlight:{ident:'Mode',value:1},rolled:0,cleared:0,warned:0,
 parseMessage:x=>x,applyMessage:()=>{},isActionAcknowledged:()=>false,
 rollbackOptimisticAction:()=>s2.rolled++,clearActionInFlight:()=>{s2.cleared++;s2.actionInFlight=null;},
 showWarning:()=>s2.warned++};
vm.createContext(s2);
vm.runInContext(extract('handleMessage','renderStructure'),s2);
s2.handleMessage({type:'meta',error:'Konfiguration fehlerhaft: EG Bad'});
assert.equal(s2.rolled,1);
assert.equal(s2.cleared,1);
assert.equal(s2.warned,1);
console.log('PASS: old async rejection cannot corrupt follow-up; invalid configuration immediately rolls back UI');
