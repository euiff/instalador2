#!/usr/bin/env python3
import base64,gzip,hashlib,io,json,tarfile,re
from pathlib import Path
root=Path(__file__).resolve().parents[2];m=json.loads((root/'latest.json').read_text())
blob=base64.b64decode(''.join((root/p).read_text().strip() for p in m['parts']),validate=True)
assert hashlib.sha256(blob).hexdigest()==m['sha256']
with tarfile.open(fileobj=io.BytesIO(gzip.decompress(blob))) as tf:
 files={f.name:tf.extractfile(f).read().decode('utf-8','replace') for f in tf if f.isfile() and f.name.endswith(('.php','.sql'))}
 selection={
 'public/lib/MemberDepartmentService.php':(0,245),
 'public/department-people.php':(0,210),
 'public/lib/DepartmentTreasuryService.php':(0,290),
 'public/department-treasury.php':(0,195),
 'public/lib/PortalService.php':(0,170),
 'public/users.php':(0,175),
 'public/lib/Auth.php':(170,251),
 'public/portal-home.php':(145,170),
 'public/lib/View.php':(1,175),
 'public/lib/FinanceOperations.php':(0,100),
 'public/database/040_department_treasury.sql':(0,200)
 }
 for path,(a,b) in selection.items():
  if path not in files:continue
  lines=files[path].splitlines()
  print(f'\n===== {path} [{len(lines)}] =====')
  for i in range(a,min(b,len(lines))):
   line=lines[i]
   if re.search('password_hash|password=|secret_key|apikey',line,re.I) and len(line)>500:line='[redacted]'
   print(f'{i+1:03d}: {line[:900]}')
 print('\n==== FILES matching dept ====')
 print('\n'.join(f for f in files if re.search(r'(department|portal|finance|treasury)',f,re.I)))
