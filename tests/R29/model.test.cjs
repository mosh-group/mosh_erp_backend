/* Isolated frontend calculations; no browser, server, or production data. */
const test=require('node:test'),assert=require('node:assert/strict'),path=require('node:path');
const m=require(path.resolve(__dirname,'../../../MOSH_VANILLA_FRONTEND/js/r29-model.js'));
const employee={id:'E1',annualLeaveDays:14,leaveRecords:[]};
test('Strict real calendar dates',()=>{assert(m.valid('2024-02-29'));assert(!m.valid('2026-02-29'));assert(!m.valid('2026-13-01'));});
test('Inclusive leap-year range',()=>assert.equal(m.days('2024-02-28','2024-03-01'),3));
test('Calendar year has no legacy carry-forward',()=>{const d=m.leave({...employee,leaveOpeningBalance:40},2026);assert.equal(d.available,14);assert.equal(d.carryForward,0);assert.equal(d.start,'2026-01-01');assert.equal(d.end,'2026-12-31');});
test('Prior use is counted in chosen year only',()=>{const e={...employee,leaveUsedBeforeSystemByYear:{2026:{days:3}}};assert.equal(m.leave(e,2026).available,11);assert.equal(m.leave(e,2027).available,14);});
test('Cross-year leave prorates by calendar days',()=>{const e={...employee,leaveRecords:[{id:'L1',type:'annual',status:'approved',startDate:'2026-12-30',endDate:'2027-01-02',days:4}]};assert.equal(m.leave(e,2026).used,2);assert.equal(m.leave(e,2027).used,2);});
test('Pending request counted once',()=>{const r={id:'L2',employeeId:'E1',type:'annual_leave',status:'pending',payload:{startDate:'2026-10-01',endDate:'2026-10-02',days:2}};assert.equal(m.leave(employee,2026,{},[r,r]).pending,2);});
test('Approved duplicate record counted once',()=>{const r={id:'L1',type:'annual',status:'approved',startDate:'2026-10-01',endDate:'2026-10-02',days:2};assert.equal(m.leave({...employee,leaveRecords:[r,r]},2026).used,2);});
test('Five scores are a simple average',()=>assert.equal(m.score(['A','B','C','D','E'],[100,90,80,70,60]),80));
test('Invalid scores and duplicate labels rejected',()=>{assert.throws(()=>m.score(['A','B','C','D','E'],[100,90,80,70,101]));assert.throws(()=>m.score(['A','A','C','D','E'],[1,2,3,4,5]));});
test('Urgent sorting stable before pagination',()=>{const a=[{id:'1'},{id:'2',isUrgent:true},{id:'3',urgent:true},{id:'4'}];assert.deepEqual(m.sortUrgent(a).map(x=>x.id),['2','3','1','4']);assert.deepEqual(a.map(x=>x.id),['1','2','3','4']);});
test('Explicit urgent flag has precedence',()=>{assert.equal(m.urgent({isUrgent:false,urgent:true,priority:'urgent'}),false);assert.equal(m.urgent({priority:'urgent'}),true);});
test('Probation reminders seven days and expiry, not drafts',()=>{const p={id:'E1',name:'Test',probationEndDate:'2026-10-24',employmentStatus:'active'};assert.equal(m.probation(p,'2026-10-16').length,0);assert.deepEqual(m.probation(p,'2026-10-17').map(x=>x.stage),['before7']);assert.deepEqual(m.probation(p,'2026-10-24').map(x=>x.stage),['before7','end']);assert.equal(m.probation({...p,employmentStatus:'draft'},'2026-10-24').length,0);});
