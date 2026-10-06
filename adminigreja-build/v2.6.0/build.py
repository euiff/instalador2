from pathlib import Path
import base64, hashlib, json, tarfile, io, gzip, re, sys

if len(sys.argv) < 2:
    raise SystemExit("usage: build.py WORKDIR")
root=Path(sys.argv[1]).resolve()
repo=Path.cwd()

# Install module files
src=repo/'adminigreja-build'/'v2.6.0'
for name in ['finance_schema.php','finance_core.php','finance_public.php','finance_report.php','finance_routes.php']:
    (root/'app'/name).write_bytes((src/name).read_bytes())

css=(root/'assets'/'app.css').read_text(encoding='utf-8')
css+='\n'+(src/'finance-pro.css').read_text(encoding='utf-8')+'\n'
(root/'assets'/'app.css').write_text(css,encoding='utf-8')
(root/'public'/'assets'/'app.css').write_text(css,encoding='utf-8')
(root/'VERSION').write_text('2.6.0\n',encoding='utf-8')

# Bootstrap schema migration hook
p=root/'app'/'bootstrap.php'
s=p.read_text(encoding='utf-8')
needle="require_once __DIR__ . '/migrations.php';\n    migrate_runtime_schema();"
repl=needle+"\n    require_once __DIR__ . '/finance_schema.php';\n    migrate_finance_pro_schema();"
if needle not in s:
    raise SystemExit('bootstrap migration hook not found')
s=s.replace(needle,repl,1)
p.write_text(s,encoding='utf-8')

# Public front controller
p=root/'public'/'index.php'
s=p.read_text(encoding='utf-8')
needle="require dirname(__DIR__) . '/app/layout.php';"
repl=needle+"\nrequire_once dirname(__DIR__) . '/app/finance_core.php';\nrequire_once dirname(__DIR__) . '/app/finance_public.php';\nrequire_once dirname(__DIR__) . '/app/finance_report.php';\nrequire_once dirname(__DIR__) . '/app/finance_routes.php';"
if needle not in s:
    raise SystemExit('layout require not found')
s=s.replace(needle,repl,1)
needle="$route = (string)($_GET['r'] ?? 'dashboard');"
if needle not in s:
    raise SystemExit('route assignment not found')
s=s.replace(needle,needle+"\nfinance_public_handle($route);",1)
needle="$cid = require_church();"
if needle not in s:
    raise SystemExit('church dispatch point not found')
s=s.replace(needle,needle+"\nfinance_pro_handle($route,$cid);",1)
p.write_text(s,encoding='utf-8')

# Layout / cache / menu
p=root/'app'/'layout.php'
s=p.read_text(encoding='utf-8')
s=re.sub(r'href="/assets/app\.css(?:\?v=[^"]*)?"','href="/assets/app.css?v=2.6.0"',s)
icon_anchor="""        'finance'=>'<path d="M12 1v22M17 5H9.5a3.5 3.5 0 0 0 0 7H14a3.5 3.5 0 0 1 0 7H6"/>',"""
icons="""        'treasury'=>'<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18M7 15h2M15 15h2"/>',
        'reconciliation'=>'<path d="M4 7h16M4 12h16M4 17h10"/><circle cx="18" cy="17" r="3"/><path d="M17 17l1 1 2-2"/>',
        'budgets'=>'<path d="M4 19V9M10 19V5M16 19v-7M22 19H2"/>',
        'transparency'=>'<path d="M3 3v18h18"/><path d="M7 16l4-5 3 3 5-7"/><circle cx="18" cy="6" r="2"/>',
"""
if icon_anchor not in s:
    raise SystemExit('finance icon anchor not found')
s=s.replace(icon_anchor,icons+icon_anchor,1)
old="echo nav_item('finance','Movimentações').nav_item('payables','Contas a pagar').nav_item('reports','Relatórios');"
new="echo nav_item('treasury','Painel da Tesouraria').nav_item('finance','Lançamentos').nav_item('payables','Contas a pagar').nav_item('reconciliation','Conciliação bancária').nav_item('budgets','Planejamento').nav_item('reports','Prestação de contas').nav_item('transparency','Transparência');"
if old not in s:
    raise SystemExit('treasury navigation block not found')
s=s.replace(old,new,1)
p.write_text(s,encoding='utf-8')

# Documentation note for fresh installations
schema=root/'database'/'schema.sql'
base=schema.read_text(encoding='utf-8')
base+='\n\n-- AdminIgreja 2.6: estrutura financeira profissional é criada em app/finance_schema.php na inicialização.\n'
schema.write_text(base,encoding='utf-8')

# Build strict USTAR, exactly as legacy updater accepts
files=sorted([p for p in root.rglob('*') if p.is_file()],key=lambda p:p.relative_to(root).as_posix())
tb=io.BytesIO()
with tarfile.open(fileobj=tb,mode='w',format=tarfile.USTAR_FORMAT) as tf:
    for p in files:
        rel=p.relative_to(root).as_posix()
        info=tf.gettarinfo(str(p),arcname=rel)
        info.uid=0;info.gid=0;info.uname='';info.gname='';info.mtime=0
        with p.open('rb') as fh:
            tf.addfile(info,fh)

tar=tb.getvalue()
pos=0
seen=[]
while pos+512<=len(tar):
    h=tar[pos:pos+512];pos+=512
    if h.strip(b'\0')==b'':
        break
    name=h[:100].rstrip(b'\0 ').decode()
    prefix=h[345:500].rstrip(b'\0 ').decode()
    if prefix:
        name=prefix+'/'+name
    size=int((h[124:136].rstrip(b'\0 ').strip() or b'0'),8)
    typ=h[156:157]
    if typ not in (b'0',b'\0'):
        raise SystemExit(f'forbidden TAR type {typ!r} for {name}')
    seen.append(name)
    pos+=((size+511)//512)*512

pkg=repo/'.build260'/'v2.6.0.tar.gz'
pkg.parent.mkdir(parents=True,exist_ok=True)
with pkg.open('wb') as out:
    with gzip.GzipFile(filename='',mode='wb',fileobj=out,mtime=0) as gz:
        gz.write(tar)

raw=pkg.read_bytes()
enc=base64.b64encode(raw).decode()
channel=repo/'adminigreja-updates'
release=channel/'releases'
release.mkdir(parents=True,exist_ok=True)
for old in release.glob('v2.6.0.*.b64.txt'):
    old.unlink()
parts=[]
for idx,start in enumerate(range(0,len(enc),8000),1):
    name=f'releases/v2.6.0.{idx:02d}.b64.txt'
    (channel/name).write_text(enc[start:start+8000],encoding='utf-8')
    parts.append(name)

manifest={
    'version':'2.6.0',
    'min_php':'8.0',
    'encoding':'base64',
    'parts':parts,
    'sha256':hashlib.sha256(raw).hexdigest(),
    'files':[{'path':p.relative_to(root).as_posix(),'sha256':hashlib.sha256(p.read_bytes()).hexdigest()} for p in files]
}
(channel/'latest.json').write_text(json.dumps(manifest,ensure_ascii=False,indent=2)+'\n',encoding='utf-8')
print('regular USTAR entries:',len(seen))
print('release parts:',len(parts))
print('sha256:',manifest['sha256'])
