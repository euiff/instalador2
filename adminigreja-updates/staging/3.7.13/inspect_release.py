#!/usr/bin/env python3
import base64,gzip,hashlib,io,json,tarfile,re
from pathlib import Path
root=Path(__file__).resolve().parents[2]
m=json.loads((root/'latest.json').read_text())
raw=base64.b64decode(''.join((root/p).read_text().strip() for p in m['parts']),validate=True)
assert hashlib.sha256(raw).hexdigest()==m['sha256']
with tarfile.open(fileobj=io.BytesIO(gzip.decompress(raw))) as tf:
 files={v.name:tf.extractfile(v).read().decode('utf8','replace') for v in tf if v.isfile()}
 for name in ['public/members.php','public/users.php','public/portal-home.php','public/portal.php','public/member-profile.php','public/master-members.php','public/master-enuves.php','public/lib/View.php','public/lib/Auth.php','public/lib/ManagementOverviewService.php']:
  v=files.get(name)
  if not v:continue
  lines=v.splitlines()
  print('\n====== '+name+' ['+str(len(lines))+'] ======')
  if name.endswith('View.php'):keep=set(range(10,165))
  elif name.endswith('Auth.php'):keep=set(range(155,min(300,len(lines))))
  elif name.endswith('master-enuves.php'):keep=set(range(0,min(115,len(lines))))
  elif name.endswith('portal-home.php'):keep=set(range(0,min(180,len(lines))))
  elif name.endswith('users.php'):keep=set(range(0,min(220,len(lines))))
  elif name.endswith('members.php'):keep=set(range(0,min(140,len(lines))))
  elif name.endswith('portal.php'):keep=set(range(0,min(150,len(lines))))
  else:keep=set(range(0,min(100,len(lines))))
  for i in sorted(keep):
   if i>=len(lines):continue
   l=lines[i]
   if 'password' in l.lower() and len(l)>250:l='[redacted long password-related line]'
   print(f'{i+1:03d}: '+l[:1100])
 print('\n======= PORTAL TABLES + ROLE REFERENCES ========')
 for path,body in files.items():
  if not path.endswith('.php'):continue
  if any(s in path for s in ['portal','department','congreg','member','master','users','Auth.php']):
   for l in body.splitlines():
    if re.search(r'FROM (congregations|ministry_members|member_contributions|member_portal|church_members|donations)|INTO (congregations|ministry_members|church_members)|UPDATE (congregations|ministry_members|church_members)|CREATE TABLE (congregations|ministry_members)',l,re.I):
     print(path+': '+l[:350])
