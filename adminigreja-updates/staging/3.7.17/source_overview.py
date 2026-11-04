#!/usr/bin/env python3
import base64,gzip,hashlib,json,io,tarfile,re
from pathlib import Path
root=Path(__file__).resolve().parents[2]
m=json.loads((root/'latest.json').read_text())
raw=base64.b64decode(''.join((root/p).read_text().strip() for p in m['parts']),validate=True)
assert hashlib.sha256(raw).hexdigest()==m['sha256']
with tarfile.open(fileobj=io.BytesIO(gzip.decompress(raw))) as tar:
 files={f.name:tar.extractfile(f).read().decode('utf8','replace') for f in tar if f.isfile() and f.name.endswith(('.php','.sql','.js'))}
 for name in ['public/learning.php','public/lib/LearningService.php','public/database/033_learning_courses_classes.sql','public/lib/Auth.php','public/lib/View.php','public/bootstrap.php','public/master.php','public/users.php','public/lib/MemberPortalAccessService.php']:
  if name not in files:continue
  lines=files[name].splitlines();print('\n======= '+name+' ('+str(len(lines))+' lines) ========')
  if name in ('public/learning.php','public/lib/LearningService.php','public/database/033_learning_courses_classes.sql'):keep=set(range(len(lines)))
  elif name=='public/lib/Auth.php':keep=set(range(0,min(280,len(lines))))
  elif name=='public/lib/View.php':
   keep=set(range(1,min(100,len(lines))))
   for i,t in enumerate(lines):
    if re.search(r'learning|ensino|curso|portal|portal-home',t,re.I):keep.update(range(max(0,i-4),min(len(lines),i+5)))
  else:
   keep=set(range(1,min(120,len(lines))))
   for i,t in enumerate(lines):
    if re.search(r'learning|courses|church|seed|Auth|user_role|owner',t,re.I):keep.update(range(max(0,i-3),min(len(lines),i+4)))
  n=0
  for i in sorted(keep):
   if n>620:break
   if re.search(r'secret_key|api_key|password_hash|auth_token',lines[i],re.I):continue
   print(f'{i+1:03d}: {lines[i][:1000]}');n+=1
 print('\n=== ALL FILES ===')
 print('\n'.join(sorted(files)))
