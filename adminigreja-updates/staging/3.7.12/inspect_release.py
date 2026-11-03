#!/usr/bin/env python3
import json,base64,gzip,tarfile,io,hashlib,re
from pathlib import Path
root=Path(__file__).resolve().parents[2]
m=json.loads((root/'latest.json').read_text())
raw=''.join((root/p).read_text().strip() for p in m['parts'])
archive=base64.b64decode(raw,validate=True)
assert hashlib.sha256(archive).hexdigest()==m['sha256']
with tarfile.open(fileobj=io.BytesIO(gzip.decompress(archive))) as tf:
  print('RELEASE',m['version'],'FILES',len(m['files']))
  files={a.name:tf.extractfile(a).read().decode('utf-8','replace') for a in tf if a.isfile() and a.name.endswith(('.php','.sql'))}
  names=['public/department-report.php','public/department-treasury.php','public/lib/DepartmentReportService.php','public/lib/DepartmentTreasuryService.php','public/master.php','public/members.php','public/lib/View.php']
  for name in names:
    if name not in files:continue
    lines=files[name].splitlines()
    print('\n====',name,len(lines),'====')
    if name.endswith('members.php'):
      sel=set(range(min(len(lines),200)))
    elif name.endswith('departments.php'):sel=set(range(min(len(lines),195)))
    elif name.endswith('master.php'):sel=set(range(min(len(lines),150)))
    elif name.endswith('Auth.php'):sel=set(range(min(len(lines),230)))
    elif name.endswith('View.php'):sel=set(range(105,min(len(lines),180)))
    elif name.endswith('users.php'):sel=set(range(min(len(lines),120)))
    else:sel=set(range(min(len(lines),220)))
    for i in sorted(sel):
      line=lines[i]
      if re.search(r'(password|secret|token|apikey)',line,re.I):
        if len(line)>240:continue
      print(f'{i+1:03d}: {line[:330]}')
  print('\n===== TABLE DEFINITIONS =====')
  patterns=[r'CREATE TABLE IF NOT EXISTS\s+(?:\w+\.)?(?:members|ministry_departments|department_access|church_members|congregations|users|churches)\s*\(',r'CREATE TABLE\s+(?:members|ministry_departments|department_access|church_members|congregations|users|churches)\s*\(']
  for name,src in files.items():
    if not name.endswith('.sql'):continue
    for pattern in patterns:
      for match in re.finditer(pattern,src,re.I):
        print('\nFILE',name)
        print(src[match.start():match.start()+2100])
        break
  print('\n===== MEMBER REFERENCES =====')
  for name,src in files.items():
    if name.endswith('.sql'):
      for line in src.splitlines():
        if re.search(r'(member_id|person_id|REFERENCES members|department_members|department_participants)',line,re.I):
          print(name,':',line[:280])
