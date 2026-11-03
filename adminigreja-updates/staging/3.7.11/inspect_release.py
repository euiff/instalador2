#!/usr/bin/env python3
import json,base64,gzip,tarfile,io,hashlib
from pathlib import Path
root=Path(__file__).resolve().parents[2]
m=json.loads((root/'latest.json').read_text())
archive=base64.b64decode(''.join((root/p).read_text().strip() for p in m['parts']))
assert hashlib.sha256(archive).hexdigest()==m['sha256']
with tarfile.open(fileobj=io.BytesIO(gzip.decompress(archive))) as tf:
  names=['public/dashboard.php','public/assets/management.css','public/assets/management.js','public/lib/View.php','public/members.php','public/users.php','public/department-treasury.php']
  for name in names:
    member=tf.getmember(name);lines=tf.extractfile(member).read().decode('utf-8').splitlines()
    print('\n=== '+name+' '+str(len(lines))+' lines ===')
    if name=='public/lib/View.php': indexes=list(range(0,142))+list(range(180,215))
    elif name=='public/assets/management.js':indexes=list(range(min(85,len(lines))))
    elif name=='public/assets/management.css':indexes=list(range(min(110,len(lines))))
    elif name=='public/dashboard.php':indexes=list(range(min(210,len(lines))))
    elif name=='public/users.php':indexes=list(range(min(90,len(lines))))
    else:indexes=list(range(min(100,len(lines))))
    for i in indexes:
      if i<len(lines):print(f'{i+1:03}: {lines[i][:360]}')
