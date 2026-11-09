// Real local HTTP + DB, fake loopback-only Fast2SMS transport. No real SMS or account balance used.
const http=require('node:http'), assert=require('node:assert/strict'), {spawn,execFileSync}=require('node:child_process');
const root=require('node:path').resolve(__dirname,'..');
const php=process.env.PHP_BIN||'php';
const fixture=(...args)=>execFileSync(php,['-d','extension=pdo_pgsql','php-backend/tests/sms_fixture.php',...args],{cwd:root,encoding:'utf8'});
fixture();
const delivered=new Map(); let rejected=false;
const provider=http.createServer(async(req,res)=>{
  if(req.method==='GET' && req.url.startsWith('/test-code?')) {
    res.setHeader('Content-Type','application/json');res.end(JSON.stringify({code:delivered.get(new URL(req.url,'http://localhost').searchParams.get('phone'))}));return;
  }
  let body='';for await(const c of req)body+=c;
  const data=new URLSearchParams(body);
  assert.equal(req.headers.authorization,'local-test-key'); assert.equal(data.get('route'),'otp');
  res.setHeader('Content-Type','application/json');
  if(rejected){res.end(JSON.stringify({return:false,status_code:999}));return;}
  delivered.set(data.get('numbers'),data.get('variables_values'));
  res.end(JSON.stringify({return:true,request_id:'local-test'}));
});
const base='http://127.0.0.1:5261'; let app; const accounts=[];let passed=0;
function check(ok,label){assert.ok(ok,label);passed++;}
async function api(path,body){const r=await fetch(base+path,body?{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)}:{});return {status:r.status,...await r.json()};}
const wait=ms=>new Promise(r=>setTimeout(r,ms));
(async()=>{
 await new Promise(r=>provider.listen(5260,'127.0.0.1',r));
 app=spawn(php,['-d','extension=pdo_pgsql','-d','extension=curl','-S','127.0.0.1:5261','router.php'],{cwd:root,env:{...process.env,PHONE_VERIFICATION_REQUIRED:'true',EMAIL_VERIFICATION_REQUIRED:'false',FAST2SMS_API_KEY:'local-test-key',FAST2SMS_ROUTE:'otp',FAST2SMS_BASE_URL:'http://127.0.0.1:5260',DISABLE_RATE_LIMITS:'true'},stdio:'ignore'});
 for(let i=0;i<60;i++){try{if((await api('/api/health')).phone_verification)break;}catch{}await wait(100);}
 if(process.argv.includes('--serve')){console.log('Local SMS test app ready on 5261; fake delivery only.');return;}
 for(const [index,path] of ['/api/auth.php?action=signup','/api/auth/signup'].entries()){
  const phone='98'+String(Date.now()+index).slice(-8),username='smstest_'+String(Date.now()).slice(-8)+'_'+index;
  accounts.push(username);
  const body={username,email:username+'@example.invalid',phone,password:'Test-Password-123',confirm_password:'Test-Password-123'};
  check((await api(path,body)).status>=400,'reject unverified signup');
  check((await api('/api/otp/send',{phone})).success,'send SMS through existing API transport');
  check((await api('/api/otp/send',{phone})).status===429,'resend cooldown');
  check((await api('/api/otp/verify',{phone,otp:'wrong'})).status===400,'reject wrong code');
  const code=delivered.get(phone);check(/^\d{6}$/.test(code),'six digit OTP delivered to provider');
  check((await api('/api/otp/verify',{phone,otp:code})).verified,'verify correct code');
  const signup=await api(path,body);check(signup.success,'signup after verification: '+(signup.error||signup.status));
  check(!(await api('/api/otp/verify',{phone,otp:code})).verified,'signup consumes verification');
  check((await api('/api/auth/phone-reset/reset',{phone,password:'Changed-Password-123',confirm_password:'Changed-Password-123'})).status===400,'cannot reset without recovery verification');
  check((await api('/api/auth/phone-reset/send',{phone})).success,'send recovery SMS');
  check((await api('/api/otp/verify',{phone,otp:delivered.get(phone)})).status===400,'recovery code cannot verify signup');
  check((await api('/api/auth/phone-reset/verify',{phone,otp:delivered.get(phone)})).verified,'verify recovery code');
  check((await api('/api/auth/phone-reset/reset',{phone,password:'Changed-Password-123',confirm_password:'Changed-Password-123'})).success,'reset password using SMS');
  check((await api('/api/auth/login',{username,password:'Changed-Password-123'})).success,'login using new password');
  check((await api('/api/auth/phone-reset/reset',{phone,password:'Another-Password-123',confirm_password:'Another-Password-123'})).status===400,'reset code cannot be replayed');
  fixture('unverify',username); delivered.delete(phone);
  check((await api('/api/auth/phone-reset/send',{phone})).success,'unverified legacy phone receives generic response');
  check(!delivered.has(phone),'unverified legacy phone does not receive a reset OTP');

 }
 rejected=true;const phone='97'+String(Date.now()).slice(-8);
 check((await api('/api/otp/send',{phone})).status===502,'provider rejection is not reported as delivered');
 rejected=false;check((await api('/api/otp/send',{phone})).success,'failed send does not burn cooldown');
 console.log('PASS '+passed+' Fast2SMS transport, signup, verification and recovery checks (no real SMS sent)');
})().catch(e=>{console.error(e);process.exitCode=1;}).finally(()=>{
 if(process.argv.includes('--serve')&&!process.exitCode)return;
 if(app)app.kill();provider.close(); fixture('cleanup',...accounts);
});
