#!/usr/bin/env python3
import base64,gzip,hashlib,io,json,re,tarfile
from pathlib import Path
root=Path(__file__).resolve().parents[2]
m=json.loads((root/'latest.json').read_text())
raw=base64.b64decode(''.join((root/p).read_text().strip() for p in m['parts']),validate=True)
assert hashlib.sha256(raw).hexdigest()==m['sha256']
files={}
with tarfile.open(fileobj=io.BytesIO(gzip.decompress(raw))) as tf:
 for member in tf:
  if member.isfile():files[member.name]=tf.extractfile(member).read()
assert {f['path'] for f in m['files']}==set(files)
assert all(hashlib.sha256(files[x['path']]).hexdigest()==x['sha256'] for x in m['files'])
routes={k:v.decode('utf8','replace') for k,v in files.items() if k.startswith('public/') and k.endswith('.php') and '/lib/' not in k}
services={k:v.decode('utf8','replace') for k,v in files.items() if k.startswith('public/lib/') and k.endswith('.php')}
print('RELEASE',m['version'],'FILES',len(files),'PHP',sum(k.endswith('.php') for k in files),'ROUTES',len(routes),'SERVICES',len(services))
required={}
for k,s in {**routes,**services}.items():
 m1=re.findall(r'''(?:require|require_once|include|include_once)\s*(?:\(\s*)?__DIR__\s*\.\s*['"](/[^'"]+)['"]''',s)
 for r in m1:
  check=(Path(k).parent / r.lstrip('/')).as_posix()
  required.setdefault(k,[]).append((r,check,check in files))
missing={}
for k,reqs in required.items():
 for src,check,ok in reqs:
  if not ok:missing.setdefault(k,[]).append(check)
print('MISSING DIRECT REQUIRE REF',len(missing),'SAMPLES',json.dumps(dict(list(missing.items())[:12]),ensure_ascii=False))
print('COMMON DIRECT DEPENDENCIES',json.dumps({q:sum(any(q in dst for _,dst,_ in v) for v in required.values()) for q in ['bootstrap.php','lib/PortalService.php','lib/MemberDepartmentService.php']},ensure_ascii=False))
print('ROUTE SUMMARY')
for name,text in routes.items():
 required_ability=re.findall(r"\$auth->requireAbility\(['\"]([^'\"]+)",text)
 can=re.findall(r"\$auth->can\(['\"]([^'\"]+)",text)
 post=('REQUEST_METHOD' in text and 'POST' in text)
 csrf=('verify_csrf()' in text)
 report=('application/pdf' in text or 'window.print' in text or 'ExpenseReceiptPdf' in text)
 upload=('$_FILES' in text)
 role=('requireLogin()' in text or 'requireAbility(' in text or 'isSuperAdmin(' in text)
 print(f"ROUTE {name} login={role} ability={','.join(set(required_ability)) or '-'} post={post} csrf={csrf} pdf={report} upload={upload} authChecks={','.join(sorted(set(can))[:6])}")
print('SERVICES SUMMARY')
for name,text in services.items():
 print(f"SERVICE {name} bytes={len(text)} classes={','.join(re.findall(r'final class (\w+)',text))} methods={len(re.findall(r'function\s+\w+\(',text))}")
print('IMPORTANT CODE EXCERPTS')
for name in ['public/lib/MemberPortalAccessService.php','public/lib/ExpenseReceiptPdf.php','public/lib/UploadService.php','public/lib/AccountingService.php','public/lib/MasterMemberService.php','public/lib/PastoralAgendaService.php','public/lib/EventRegistrationService.php','public/lib/ManagementOverviewService.php','public/lib/AutomationRunner.php','public/lib/ChurchMemberLeadershipService.php','public/lib/EnuvesImportService.php','public/lib/KidsService.php']:
 s=services.get(name,'').splitlines()
 print('=======',name,len(s),'=======')
 keep=set(range(min(len(s),45)))
 for i,line in enumerate(s):
  if re.search(r'pdf|public function|generate|write|INSERT INTO|UPDATE |DELETE FROM|SELECT |namespace|send|upload|password|token|member_portal|recovery|member_id|account_id|department_id|currentChurch|function __construct',line,re.I):keep.update(range(max(0,i-2),min(len(s),i+3)))
 for i in sorted(keep)[:120]:
  print('%04d: %s'%(i+1,s[i][:900]))

print('TARGET ROUTES INSPECTION')
for name in ['public/events.php','public/members.php','public/payments.php','public/master.php','public/cron.php','public/master-automations.php','public/expense-receipt.php','public/accounting-file.php','public/system-course.php','public/portal.php','public/master-enuves.php']:
 text=routes[name]
 ls=text.splitlines()
 print('===== TARGET '+name+' '+str(len(ls))+' =====')
 ids=set(range(min(25,len(ls))))
 for i,l in enumerate(ls):
  if any(term in l.lower() for term in ['request_method','$_post','verify_csrf','csrf','action','insert into','update ','delete from','create(','requireability','password','token','hash_equals','sha256']): ids.update(range(max(0,i-4),min(len(ls),i+7)))
 for i in sorted(ids)[:200]:print('%04d: %s'%(i+1,ls[i][:1500]))
print('TARGET END')

print('END AUDIT')
