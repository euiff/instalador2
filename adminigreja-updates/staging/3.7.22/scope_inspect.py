#!/usr/bin/env python3
import base64, gzip, hashlib, io, json, tarfile, re
from pathlib import Path
root=Path(__file__).resolve().parents[2]
m=json.loads((root/'latest.json').read_text())
parts=''.join((root/p).read_text().strip() for p in m['parts'])
raw=base64.b64decode(parts,validate=True)
assert hashlib.sha256(raw).hexdigest()==m['sha256']
with tarfile.open(fileobj=io.BytesIO(gzip.decompress(raw))) as tf:
    files={f.name:tf.extractfile(f).read().decode('utf8','replace') for f in tf if f.isfile()}
for p in ['public/lib/FinanceCsvImport.php','public/lib/FinanceOperations.php','public/lib/RegistryService.php','public/lib/DepartmentTreasuryService.php','public/lib/DepartmentReportService.php','public/lib/accounting-departments.php','public/lib/AccountingService.php','public/lib/FinanceOperations.php','public/lib/dashboard-department-treasury.php','public/finance.php','public/department-report.php','public/department-treasury.php','public/donate.php','public/portal.php','public/lib/MemberDepartmentService.php']:
    lines=files.get(p,'').splitlines()
    print('======= '+p+' '+str(len(lines))+' =======')
    if p in ['public/lib/FinanceCsvImport.php','public/lib/FinanceOperations.php','public/lib/DepartmentTreasuryService.php','public/lib/DepartmentReportService.php']:
        keep=list(range(len(lines)))
    else:
        inds=set()
        for i,l in enumerate(lines):
            if re.search(r'finance_entries|finance_documents|department_id|member_id|person_id|tithe|dizimo|dízimo|offer|oferta|contribution|donation|account_id|department_members|member_departments|depa.*report|general',l,re.I):
                inds.update(range(max(0,i-4),min(len(lines),i+7)))
        keep=sorted(inds)
    if len(keep)>580:keep=keep[:580]
    for i in keep:print(f'{i+1:04d} {lines[i][:1200]}')

for p in ['public/finance.php','public/lib/FinanceOperations.php','public/finance-import.php','public/lib/FinanceCsvImport.php','public/finance-control.php']:
 lines=files.get(p,'').splitlines()
 print('======= EXTRA '+p+' '+str(len(lines))+' =======')
 for i,l in enumerate(lines):
  if p=='public/finance.php' and (i<180 or 460<i<720) or p!='public/finance.php' and (re.search(r'account_id|department_id|finance_entries|receive|def account|function account|function import|post_expense',l,re.I) or i<25): print(f'{i+1:04d} {l[:1500]}')
