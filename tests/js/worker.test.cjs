const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const model = require('../../assets/model.js');
for (const hidden of [false, true]) {
 test(`worker advances independently of broken reports (hidden=${hidden})`, async () => {
  const calls=[], timers=[];
  const element={addEventListener(){},textContent:'',className:''};
  const context={
   wp:{i18n:{__:s=>s}},SMAOModel:model,smaoConfig:{root:'http://test/wp-json/smao/v1/',nonce:'test'},
   URLSearchParams,FormData:class { *[Symbol.iterator](){} },AbortController,
   location:{search:''},
   document:{hidden,querySelector:s=>['#smao-progress','#smao-report-body','.smao-filters','#smao-notice'].includes(s)?element:null,querySelectorAll:()=>[],addEventListener(){}},
   window:{setTimeout(fn,ms){const t={fn,ms,cancelled:false};timers.push(t);return t;},clearTimeout(t){t.cancelled=true;}},
   fetch:async(url,options)=>{calls.push({url,options});if(url.includes('/report'))return {ok:false,status:500,json:async()=>({message:'Report unavailable'})};return {ok:true,json:async()=>({scan:{state:options.method==='POST'?'complete':'running',total:1,source_totals:[1]},live:{runtime:{browser_worker:true,poll_interval:2},queue_counts:{}}})};}
  };
  vm.runInNewContext(fs.readFileSync(require.resolve('../../assets/admin.js'),'utf8'),context);
  await new Promise(resolve=>setImmediate(resolve));
  const worker=timers.find(t=>t.ms===300&&!t.cancelled);assert.ok(worker);
  await worker.fn();await new Promise(resolve=>setImmediate(resolve));
  assert.ok(calls.some(c=>c.url.endsWith('/control')&&JSON.parse(c.options.body).command==='tick'));
  if(!hidden) assert.ok(calls.some(c=>c.url.includes('/report')));
 });
}
