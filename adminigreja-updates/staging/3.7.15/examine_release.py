#!/usr/bin/env python3
import base64,gzip,hashlib,json,tarfile,io,re
from pathlib import Path
root=Path(__file__).resolve().parents[2]
m=json.loads((root/'latest.json').read_text())
raw=base64.b64decode(''.join((root/p).read_text().strip() for p in m['parts']),validate=True)
assert hashlib.sha256(raw).hexdigest()==m['sha256']
with tarfile.open(fileobj=io.BytesIO(gzip.decompress(raw))) as archive:
 fs={f.name:archive.extractfile(f).read().decode('utf-8','replace') for f in archive if f.isfile()}
 print('VERSION',m['version'])
 for name in ['public/lib/Ui.php','public/lib/View.php','public/assets/management-3711.js','public/users.php','public/member-portal-access.php','public/settings.php']:
  if name not in fs:continue
  lines=fs[name].splitlines()
  print('===== '+name+' ('+str(len(lines))+' lines)')
  keep=range(len(lines)) if name.endswith(('Ui.php','View.php')) else (range(150,len(lines)) if name.endswith('users.php') else range(min(len(lines),110)))
  for i in keep:
   if name.endswith('management-3711.js') and i>38:continue
   if re.search(r'password_hash|secret_key|api_key',lines[i],re.I):continue
   print(f'{i+1}: '+lines[i][:420])
 print('===== PASSWORD FIELD LOCATIONS =====')
 for name,body in fs.items():
  if not name.endswith('.php'):continue
  for i,line in enumerate(body.splitlines(),1):
   if re.search(r'type\\s*=\\s*[\\x22\\x27]password[\\x22\\x27]|type=\\\\[\\x22\\x27]password',line,re.I):
    print(name+':'+str(i)+':'+line[:390])
