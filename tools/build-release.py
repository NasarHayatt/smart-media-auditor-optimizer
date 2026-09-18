"""Build the installable source ZIP without development dependencies or local state."""
from pathlib import Path
import hashlib
import json
import re
import sys
import zipfile

root = Path(__file__).resolve().parents[1]
destination = Path(sys.argv[1]).resolve() if len(sys.argv) > 1 else root.parent / 'releases'
destination.mkdir(parents=True, exist_ok=True)

# Keep the template in sync with literal PHP and JavaScript translations.
messages = set()
for source in list((root / 'includes').glob('*.php')) + list((root / 'assets').glob('*.js')):
    text = source.read_text(encoding='utf-8')
    for match in re.finditer(r"(?:t|__|esc_html__|esc_attr__|esc_html_e|esc_attr_e)\(\s*'((?:\\.|[^'\\])*)'", text):
        messages.add(match.group(1).replace("\\'", "'").replace('\\\\', '\\'))
pot = 'msgid ""\nmsgstr ""\n"Project-Id-Version: Smart Media Auditor & Optimizer 1.2.0\\n"\n"Content-Type: text/plain; charset=UTF-8\\n"\n"Content-Transfer-Encoding: 8bit\\n"\n\n'
for message in sorted(messages):
    pot += 'msgid ' + json.dumps(message, ensure_ascii=False) + '\nmsgstr ""\n\n'
(root / 'languages' / 'smart-media-auditor-optimizer.pot').write_text(pot, encoding='utf-8')

archive = destination / 'smart-media-auditor-optimizer-1.2.0.zip'
skip_parts = {'vendor', 'node_modules', '.git', '__pycache__'}
with zipfile.ZipFile(archive, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as output:
    for source in sorted(root.rglob('*')):
        relative = source.relative_to(root)
        if not source.is_file() or any(part in skip_parts for part in relative.parts):
            continue
        if source.name.startswith('.') or source.suffix in {'.log', '.zip', '.sha256', '.pyc'}:
            continue
        entry = zipfile.ZipInfo(str(Path(root.name) / relative).replace('\\', '/'), (2026, 9, 16, 0, 0, 0))
        entry.external_attr = 0o644 << 16
        entry.compress_type = zipfile.ZIP_DEFLATED
        output.writestr(entry, source.read_bytes())

with zipfile.ZipFile(archive) as check:
    assert check.testzip() is None, 'ZIP integrity verification failed'
    assert f'{root.name}/smart-media-auditor-optimizer.php' in check.namelist()
    assert all('/vendor/' not in name for name in check.namelist())
    count = len(check.namelist())
digest = hashlib.sha256(archive.read_bytes()).hexdigest()
archive.with_suffix('.zip.sha256').write_text(f'{digest}  {archive.name}\n', encoding='ascii')
print(f'Created {archive}\n{count} files; {archive.stat().st_size:,} bytes\nSHA-256: {digest}')
