#!/usr/bin/env python3
import base64,gzip,hashlib,json,io,tarfile,re
from pathlib import Path
root=Path(__file__).resolve().parents[2]
m=json.loads((root/'latest.json').read_text())
raw=base64.b64decode(''.join((root/p).read_text().strip() for p in m['parts']),validate=True)
assert hashlib.sha256(raw).hexdigest()==m['sha256']
with tarfile.open(fileobj=io.BytesIO(gzip.decompress(raw))) as tar:
  files={f.name:tar.extractfile(f).read().decode('utf8','replace') for f in tar if f.isfile() and f.name.endswith(('.php','.sql','.js','.json'))}
  for p in ['public/lib/Auth.php','public/lib/View.php','public/lib/SystemCourseImageService.php','public/system-course.php','public/master-system-course-images.php','public/assets/password-visibility.js','public/assets/password-visibility.css']:
    lines=files.get(p,'').splitlines()
    print('\n===== '+p+' ('+str(len(lines))+' lines) =====')
    if p.endswith('Auth.php'):
      keep=set(range(min(200,len(lines))))
      for i,l in enumerate(lines):
        if re.search(r'function __construct|function requireLogin|function user|function isSuperAdmin|password|session|function can|function login|function logout',l,re.I):
          keep.update(range(max(0,i-4),min(i+18,len(lines))))
    elif p.endswith('View.php'):
      keep=set(range(min(100,len(lines))))
      for i,l in enumerate(lines):
        if re.search(r'password-visibility|function header|function footer|<head|<script|</head>',l,re.I):keep.update(range(max(0,i-3),min(i+7,len(lines))))
    elif p.endswith('SystemCourseImageService.php'):keep=set(range(len(lines)))
    elif p.endswith('system-course.php'):keep=set(range(min(160,len(lines))))
    else:keep=set(range(min(130,len(lines))))
    for i in sorted(keep):
      if i>=len(lines):continue
      print(f'{i+1:03d}: {lines[i][:1000]}')
