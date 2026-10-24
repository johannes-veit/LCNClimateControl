'use strict';
const fs=require('fs'),vm=require('vm'),assert=require('assert');
const html=fs.readFileSync('LCNClimateControl/module.html','utf8');
function extract(name,next) {
  const a=html.indexOf('function '+name+'('), b=next==='END' ? html.indexOf('\nrenderStructure();',a+5) : html.indexOf('function '+next+'(',a+5);
  if(a<0||b<0)throw Error('missing function '+name);
  return html.slice(a,b);
}
const callbacks=[];
const ctx={
  Date, JSON,Error,Number,String,
  actionInFlight:null, actionGeneration:0, actionTimeout:0,
  lastActionKey:'',lastActionTime:0, ACTION_GUARD_MS:0,ACTION_TIMEOUT_MS:6000,
  requestAction:(id,value)=>({then:()=>{},catch:(cb)=>callbacks.push(cb)}),
  window:{setTimeout:()=>12,clearTimeout:()=>{}},
  actionKey:(id,value)=>id+value,
  scheduleQueuedAction:()=>{},rollbackOptimisticAction:(action)=>ctx.rollbacks.push(action.ident),
  rollbacks:[],showWarning:()=>{},
  rejectUnconfirmedAction:(action,why)=>{
    ctx.rollbacks.push(action.ident);
    ctx.clearActionInFlight(false);
  },
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
vm.runInContext(extract('handleMessage','END'),s2);
s2.handleMessage({type:'meta',error:'Konfiguration fehlerhaft: EG Bad'});
assert.equal(s2.rolled,1);
assert.equal(s2.cleared,1);
assert.equal(s2.warned,1);
console.log('PASS: old async rejection cannot corrupt follow-up; invalid configuration immediately rolls back UI');

const limitHelpers=extract('heatingLimits','formatTemp');
const heatBounds=vm.runInNewContext(limitHelpers+'; heatingLimits', {Number});
assert.equal(heatBounds({name:'OG Schlafen 1',heatMin:12,heatMax:22}).min,12);
assert.equal(heatBounds({name:'OG Schlafen 2',heatMin:12,heatMax:22}).max,22);
assert.equal(heatBounds({name:'EG Bad',heatMin:18,heatMax:24}).max,24);
assert.equal(heatBounds({name:'EG Bad'}).max,24);
const ogControls=extract('createHeatRow','createCoolRow');
const ogUpdate=extract('patchHeatRow','patchCoolRow');
assert(ogControls.includes('slider.max = String(limits.max)'), 'room-specific slider maximum missing');
assert(ogControls.includes('clamp(raw + 1, min, max)'), 'plus can exceed room-specific ceiling');
assert(ogControls.includes('clamp(raw - 1, min, max)'), 'minus can exceed room-specific floor');
assert(ogControls.includes('plus.disabled = Number(slider.value) >= max'), 'slider plus not disabled at native maximum');
assert(ogUpdate.includes('ref.slider.max = String(max)'), 'backend row updates not reflected in slider');
assert(ogUpdate.includes('ref.plus.disabled = switching || raw >= max'), 'plus must disable when switching or beyond 22');
console.log('PASS: heater tile uses backend 12..22 limits only for OG bedrooms; 18..24 remains default');

const guardCtx={
  state:{pendingMode:null},
  actionInFlight:null, actionQueue:[],
  rowKey:row=>String(row.targetId)
};
vm.createContext(guardCtx);
vm.runInContext(extract('isRoomSwitching','upsertRow'),guardCtx);
const roomA={targetId:49519,busy:false},roomB={targetId:34041,busy:false};
assert.equal(guardCtx.isRoomSwitching(roomA),false,'idle room must be operable');
assert.equal(guardCtx.isRoomSwitching({...roomA,busy:true}),true,'active heating/cooling room blocked');
assert.equal(guardCtx.isRoomSwitching(roomB),false,'independent idle room stays operable');
guardCtx.actionInFlight={ident:'Heat_49519',targetId:49519};
assert.equal(guardCtx.isRoomSwitching(roomA),true,'local send must lock target immediately');
assert.equal(guardCtx.isRoomSwitching(roomB),false,'a second room must stay operable');
guardCtx.actionInFlight=null;
guardCtx.actionQueue=[{ident:'Cool_49519',targetId:49519}];
assert.equal(guardCtx.isRoomSwitching(roomA),true,'queued command keeps same room locked');
assert.equal(guardCtx.isRoomSwitching(roomB),false,'different rooms not globally locked');
guardCtx.actionQueue=[];
assert.equal(guardCtx.isRoomSwitching(roomA),false,'room unlocks without arbitrary timeout');
guardCtx.state.pendingMode=0;
assert.equal(guardCtx.isRoomSwitching(roomA),true,'mode transition locks all affected rooms');
assert.equal(guardCtx.isRoomSwitching(roomB),true,'mode transition blocks rapid room switches');
guardCtx.state.pendingMode=null;
assert.equal(guardCtx.isRoomSwitching(roomA),false,'mode completion unlocks immediately');
guardCtx.actionInFlight={ident:'Mode',value:1};
assert.equal(guardCtx.isRoomSwitching(roomA),true,'in-flight mode switch locks rooms');
guardCtx.actionInFlight=null;
guardCtx.actionQueue=[{ident:'Mode',value:1}];
assert.equal(guardCtx.isRoomSwitching(roomA),true,'queued mode switch locks rooms');
guardCtx.actionQueue=[];
const rowHtml=extract('createHeatRow','createCoolRow');
const coolingHtml=extract('createCoolRow','patchHeatRow');
const patchHeatHtml=extract('patchHeatRow','patchCoolRow');
const patchCoolHtml=extract('patchCoolRow','patchMode');
assert(rowHtml.includes('if (!current || isRoomSwitching(current)) return;'),
 'heating +/- and slider need a command guard');
assert(coolingHtml.includes('isRoomSwitching(current)'),
 'cooling on/off needs a command guard');
assert(patchHeatHtml.includes('ref.slider.disabled = switching') &&
       patchHeatHtml.includes('ref.plus.disabled = switching || raw >= max'),
 'heating controls must truly disable, not merely grey');
assert(patchCoolHtml.includes('ref.on.disabled = switching') &&
       patchCoolHtml.includes('ref.off.disabled = switching'),
 'both cooling states disabled during physical operation');
assert(html.includes('.room-row.switching .slider-wrap'),
 'busy row visual greying missing');
assert(html.includes("else patchUI();"),
 'a backend meta event must update all pending mode locks');
console.log('PASS: rooms are greyed/blocked only during live or locally queued operations and unlock immediately');

const recoveryCtx={
  queueTimer:42, actionQueue:[
    {ident:'Heat_701',targetId:701,previousValue:20},
    {ident:'Heat_49519',targetId:49519,previousValue:19}
  ], actionInFlight:{ident:'Heat_34041',targetId:34041},
  clearedTimers:[],rolledBack:[],queuedDispatches:0,warnings:[],
  window:{clearTimeout(id){recoveryCtx.clearedTimers.push(id);}},
  rollbackOptimisticAction(a){recoveryCtx.rolledBack.push(a.ident);},
  clearActionInFlight(dispatch){assert.equal(dispatch,false,
      'lost-connection handling must NOT dispatch queued actions');
    recoveryCtx.actionInFlight=null;
  },
  showWarning(v){recoveryCtx.warnings.push(v);}
};
vm.createContext(recoveryCtx);
vm.runInContext(extract('stopQueuedActions','dispatchAction'),recoveryCtx);
recoveryCtx.rejectUnconfirmedAction(recoveryCtx.actionInFlight,'connection lost');
assert.deepEqual(recoveryCtx.rolledBack,['Heat_701','Heat_49519','Heat_34041'],
 'no queued optimistic action should remain after disconnected request');
assert.equal(recoveryCtx.actionQueue.length,0,'outstanding commands must be discarded');
assert.equal(recoveryCtx.queueTimer,0,'no future queued dispatch may fire');
assert.deepEqual(recoveryCtx.clearedTimers,[42],'queued timer must be cancelled');
assert.equal(recoveryCtx.actionInFlight,null,'stale in-flight command must clear');
assert.equal(recoveryCtx.warnings.length,1,'exactly one local warning per interruption');
const acceptedCtx={};
vm.createContext(acceptedCtx);
vm.runInContext(extract('isActionAcknowledged','clearActionInFlight'),acceptedCtx);
assert.equal(acceptedCtx.isActionAcknowledged(
  {type:'row',row:{targetId:49519,busy:true,displayTarget:19}},
  {ident:'Heat_49519',targetId:49519,kind:'heat',value:16}),true,
  'worker busy already proves Symcon accepted the queued heating command');
assert.equal(acceptedCtx.isActionAcknowledged(
  {type:'row',row:{targetId:34041,busy:true,cooling:false}},
  {ident:'Cool_34041',targetId:34041,kind:'cool',value:true}),true,
  'cooling relay job acceptance must not wait for final relay switching');
assert.equal(acceptedCtx.isActionAcknowledged(
  {type:'row',row:{targetId:49519,busy:true,displayTarget:19}},
  {ident:'Heat_34041',targetId:34041,kind:'heat',value:16}),false,
  'different room must never acknowledge this command');
assert.equal(acceptedCtx.isActionAcknowledged(
  {type:'row',row:{targetId:49519,busy:false,displayTarget:19}},
  {ident:'Heat_49519',targetId:49519,kind:'heat',value:16}),false,
  'idle wrong target does not prove action acceptance');
assert(/const ACTION_TIMEOUT_MS = 30000;/.test(html),
 'unrealistically short six-second action timeout must remain removed');
assert(html.includes('rejectUnconfirmedAction(actionInFlight,'),
 'transport exceptions should abort queued actions without retries');
console.log('PASS: async busy acknowledgement and interrupted transport fail safely without duplicate commands');
