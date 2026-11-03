#!/usr/bin/env python3
import json,base64,gzip,tarfile,io,hashlib,re
from pathlib import Path
root=Path(__file__).resolve().parents[2]
manifest=json.loads((root/'latest.json').read_text())
enc="".join((root/p).read_text().strip() for p in manifest['parts'])
archive=base64.b64decode(enc,validate=True)
assert hashlib.sha256(archive).hexdigest()==manifest['sha256']
with tarfile.open(fileobj=io.BytesIO(gzip.decompress(archive))) as tf:
  filenames=[x.name for x in tf if x.isfile()]
  print('BASE',manifest['version'],'FILES',len(filenames))
  wanted=['public/department-report.php','public/lib/DepartmentReportService.php','public/portal-home.php','public/lib/View.php','public/lib/Auth.php','public/finance.php','public/finance-control.php','public/lib/AccountingService.php','public/accounting.php','public/accounting-package.php','public/finance-budget.php','public/lib/BudgetService.php','public/users.php','public/database/028_finance_registers_receivables.sql','public/lib/FinanceOperations.php']
  for path in wanted:
    with tarfile.open(fileobj=io.BytesIO(gzip.decompress(archive))) as t:
      try:c=t.extractfile(path).read().decode('utf-8')
      except KeyError:continue
    lines=c.splitlines()
    print('\n===== '+path+' | '+str(len(lines))+' lines =====')
    if path.endswith(('View.php','Auth.php')): selected=set(range(min(len(lines),265)))
    elif path.endswith('finance.php'):selected=set(range(0,75))
    elif path.endswith('accounting.php'):selected=set(range(0,115))
    elif path.endswith('portal-home.php'):selected=set(range(0,165))
    elif path.endswith('users.php'):selected=set(range(0,45))
    elif path.endswith('AccountingService.php'): selected=set(range(0,80))|set(range(235,min(500,len(lines))))
    elif path.endswith('accounting-package.php'): selected=set(range(0,85))
    elif path.endswith('finance-control.php'): selected=set(range(0,50))
    else:selected=set(range(len(lines)))
    for i in selected:
      if i<len(lines):
        v=lines[i]
        # Avoid revealing credentials or embedded potentially sensitive content from old code
        if ('password' in v.lower() or 'token' in v.lower()) and ('private ' not in v): v='[line omitted]'
        print(f'{i+1:03d} '+v[:360])
