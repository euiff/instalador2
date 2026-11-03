#!/usr/bin/env python3
import json,base64,gzip,hashlib,tarfile,io,re,sys
from pathlib import Path
root=Path(__file__).resolve().parents[2]
m=json.loads((root/'latest.json').read_text())
raw=''.join((root/p).read_text().strip() for p in m['parts'])
data=base64.b64decode(raw)
assert hashlib.sha256(data).hexdigest()==m['sha256']
with tarfile.open(fileobj=io.BytesIO(gzip.decompress(data)),mode='r:') as t:
    files={n.name:n for n in t if n.isfile()}
    print('BASE',m['version'],'FILES',len(files))
    selected=['public/lib/Auth.php','public/lib/View.php','public/bootstrap.php','public/finance.php','public/finance-control.php','public/finance-import.php','public/users.php','public/lib/UploadService.php','public/lib/FinanceOperations.php','public/lib/FinanceCsvImport.php','public/database/009_finance_ledger.sql','public/database/014_church_permissions.sql','public/database/028_finance_registers_receivables.sql','public/database/029_finance_period_locks.sql','public/database/030_finance_import_batches.sql','public/database/040_pastoral_agenda.sql']
    if len(sys.argv)>1:selected=sys.argv[1:]
    for p in selected:
        if p not in files:print('MISSING',p);continue
        lines=t.extractfile(files[p]).read().decode('utf-8','replace').splitlines()
        print('\n###',p,'lines',len(lines))
        if p.endswith('.sql'):
            for i,s in enumerate(lines): print(f'{i+1:03}: {s[:280]}')
        elif p.endswith('Auth.php') or p.endswith('View.php') or p.endswith('bootstrap.php') or p.endswith('users.php') or p.endswith('UploadService.php'):
            for i,s in enumerate(lines[:290]):print(f'{i+1:03}: {s[:270]}')
        else:
            pats=('INSERT','SELECT','UPDATE','CREATE TABLE','permission','upload','receipt','invoice','photo','attachment','document','finance_entries','expense','file','account','category','tax','source')
            picks=set(range(min(35,len(lines))))
            for i,s in enumerate(lines):
                if any(p.lower() in s.lower() for p in pats):
                    for k in range(max(0,i-1),min(len(lines),i+2)):picks.add(k)
            for i in sorted(picks):
                if i<min(len(lines),230):print(f'{i+1:03}: {lines[i][:270]}')
    print('\n### SQL / ROLE REFS')
    for name,node in files.items():
        if name.endswith('.sql') or name in ('public/lib/Auth.php','public/lib/FinanceOperations.php'):
            txt=t.extractfile(node).read().decode('utf-8','replace')
            for match in re.finditer(r'(?im)^.{0,150}(accountant|contabil|contador|invoice|receipt|fiscal_document|document_upload|finance_entries).{0,180}$',txt):
                s=match.group(0);print(name,':',s[:300])
