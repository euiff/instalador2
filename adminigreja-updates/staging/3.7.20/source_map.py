#!/usr/bin/env python3
import base64,hashlib,json,io,gzip,tarfile,re
from pathlib import Path
root=Path(__file__).resolve().parents[2]
m=json.loads((root/'latest.json').read_text())
raw=base64.b64decode(''.join((root/p).read_text().strip() for p in m["parts"]),validate=True)
assert hashlib.sha256(raw).hexdigest()==m['sha256']
with tarfile.open(fileobj=io.BytesIO(gzip.decompress(raw))) as tar:
    f={e.name:tar.extractfile(e).read().decode('utf8','replace') for e in tar if e.isfile() and e.name.endswith(('.php','.sql','.js'))}
for name in ['public/users.php','public/lib/MemberDepartmentService.php','public/lib/DepartmentRoleAccess.php','public/lib/MemberPortalAccessService.php','public/department-people.php','public/department-team.php','public/database/043_member_departments.sql','public/lib/Auth.php','public/members.php']:
    lines=f.get(name,'').splitlines()
    print('======= '+name+' ('+str(len(lines))+' lines) =======')
    if name in ('public/users.php','public/lib/MemberDepartmentService.php','public/lib/DepartmentRoleAccess.php','public/database/043_member_departments.sql'):ids=range(len(lines))
    elif name in ('public/department-people.php','public/lib/MemberPortalAccessService.php','public/department-team.php'):
        ids=range(min(len(lines),350))
    else:
        indices=set(range(min(len(lines),55)))
        for i,line in enumerate(lines):
            if re.search(r'member|department|position|role|leader|manage_users|\$ability|church_role|create_user|createAccess',line,re.I):indices.update(range(max(0,i-3),min(len(lines),i+5)))
        ids=sorted(indices)
    for i in ids:
        if i>=len(lines):continue
        print('%04d: %s'%(i+1,lines[i][:1400]))
