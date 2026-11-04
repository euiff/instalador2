#!/usr/bin/env python3
import base64,gzip,hashlib,json,io,tarfile,re
from pathlib import Path
root=Path(__file__).resolve().parents[2]
m=json.loads((root/'latest.json').read_text())
raw=base64.b64decode(''.join((root/p).read_text().strip() for p in m['parts']),validate=True)
assert hashlib.sha256(raw).hexdigest()==m['sha256']
with tarfile.open(fileobj=io.BytesIO(gzip.decompress(raw))) as tar:
 files={f.name:tar.extractfile(f).read().decode('utf8','replace') for f in tar if f.isfile() and f.name.endswith(('.php','.sql','.js'))}
 for name in ['public/finance.php','public/lib/FinanceOperations.php','public/lib/DepartmentTreasuryService.php','public/department-treasury.php','public/accounting-file.php','public/lib/AccountingService.php','public/lib/UploadService.php','public/lib/View.php','public/finance-control.php','public/lib/DepartmentReportService.php']:
  body=files.get(name,'')
  if not body:continue
  print('\n===== '+name+' '+str(len(body.splitlines()))+' LINES =====')
  lines=body.splitlines()
  # Summarize strategic sections plus targeted contexts
  if name.endswith('FinanceOperations.php'):keep=set(range(min(220,len(lines))))
  elif name.endswith('DepartmentTreasuryService.php'):keep=set(range(min(280,len(lines))))
  elif name.endswith('finance.php'):keep=set(range(min(255,len(lines))))
  elif name.endswith('department-treasury.php'):keep=set(range(min(240,len(lines))))
  elif name.endswith('accounting-file.php'):keep=set(range(min(125,len(lines))))
  elif name.endswith('AccountingService.php'):keep=set(range(min(105,len(lines))))
  else:keep=set(range(min(80,len(lines))))
  for i,l in enumerate(lines):
   if re.search(r'(proof|receipt|comprovante|document|anexo|upload|arquivo|createEntry|finance_entries|print|pdf|file_id|file_path|file_url)',l,re.I):
    keep.update(range(max(0,i-5),min(len(lines),i+7)))
  output=0
  for i in sorted(keep):
   line=lines[i]
   if re.search(r'password_hash|secret_key|api_key|authorization:',line,re.I):continue
   print(f'{i+1:03d}: '+line[:950]);output+=1
   if output>=370:print('TRUNCATED');break
 print('\n==== RELATED SCHEMA/TABLES ====')
 for name,body in files.items():
  if not name.endswith('.sql'):continue
  if re.search(r'finance_attachments|finance_entries|receipts|accounting_documents|department_documents',body,re.I):
   print(name)
   for l in body.splitlines():
    if re.search(r'finance_attachments|finance_entries|receipts|accounting_documents|department_documents',l,re.I):print('  '+l[:420])
