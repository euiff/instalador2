#!/usr/bin/env python3
import base64,gzip,hashlib,json,io,tarfile,re
from pathlib import Path
root=Path(__file__).resolve().parents[2]
m=json.loads((root/'latest.json').read_text())
raw=base64.b64decode(''.join((root/p).read_text().strip() for p in m['parts']),validate=True)
assert hashlib.sha256(raw).hexdigest()==m['sha256']
with tarfile.open(fileobj=io.BytesIO(gzip.decompress(raw))) as tar:
 files={f.name:tar.extractfile(f).read().decode('utf8','replace') for f in tar if f.isfile() and f.name.endswith(('.php','.sql','.js'))}
 for name in ['public/finance.php','public/lib/UploadService.php','public/lib/AccountingService.php']:
  body=files.get(name,'')
  if not body:continue
  lines=body.splitlines()
  print('\n===== '+name+' '+str(len(lines))+' LINES =====')
  limits={'public/finance.php':(470,570),'public/lib/UploadService.php':(165,195),'public/lib/AccountingService.php':(235,335)}
  a,b=limits[name]
  for i in range(a-1,min(b,len(lines))):
   print(f'{i+1:03d}: '+lines[i][:1250])
 print('\n==== RELATED SCHEMA/TABLES ====')
 for name,body in files.items():
  if not name.endswith('.sql'):continue
  if re.search(r'finance_attachments|finance_entries|receipts|accounting_documents|department_documents',body,re.I):
   print(name)
   for l in body.splitlines():
    if re.search(r'finance_attachments|finance_entries|receipts|accounting_documents|department_documents',l,re.I):print('  '+l[:420])
