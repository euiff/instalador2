#!/usr/bin/env python3
import base64,gzip,hashlib,json,io,tarfile,re
from pathlib import Path
root=Path(__file__).resolve().parents[2]
m=json.loads((root/'latest.json').read_text())
raw=base64.b64decode(''.join((root/p).read_text().strip() for p in m['parts']),validate=True)
assert hashlib.sha256(raw).hexdigest()==m['sha256']
with tarfile.open(fileobj=io.BytesIO(gzip.decompress(raw))) as tar:
  files={e.name:tar.extractfile(e).read().decode('utf8','replace') for e in tar if e.isfile() and e.name.endswith(('.php','.sql','.js'))}
for p in ['public/finance.php','public/lib/FinanceOperations.php','public/lib/DepartmentTreasuryService.php','public/department-treasury.php','public/registers.php','public/accounting.php','public/accounting-parse.php','public/lib/ReceiptParser.php','public/lib/AccountingService.php','public/lib/GeneralTreasuryAccountScope.php','public/lib/ExpenseReceiptService.php','public/assets/accounting-ocr.js','public/lib/View.php']:
  lines=files.get(p,'').splitlines()
  print('\n==== '+p+' ('+str(len(lines))+' lines) ====')
  for i,line in enumerate(lines):
    if i>=1900:break
    print(f'{i+1:04d}: {line[:700]}')
