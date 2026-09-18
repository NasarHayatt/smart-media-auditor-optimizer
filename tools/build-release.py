"""Build the installable WordPress plugin ZIP.

Reads the version from the plugin header, regenerates the translation
template from the current sources, and packages only the files a site
actually needs. Development and planning files are deliberately excluded.
"""
from pathlib import Path
import hashlib
import json
import re
import sys
import zipfile

root = Path(__file__).resolve().parents[1]
destination = Path(sys.argv[1]).resolve() if len(sys.argv) > 1 else root.parent / 'releases'
destination.mkdir(parents=True, exist_ok=True)

main_file = root / 'smart-media-auditor-optimizer.php'
header = main_file.read_text(encoding='utf-8')
version = re.search(r'^\s*\*\s*Version:\s*(.+)$', header, re.MULTILINE).group(1).strip()
print(f'Building version {version}')

# Keep the translation template in sync with the literal strings in PHP and JS.
messages = set()
sources = list((root / 'includes').rglob('*.php')) + list((root / 'assets').glob('*.js'))
sources.append(main_file)
pattern = re.compile(
    r"(?:t|__|_e|esc_html__|esc_attr__|esc_html_e|esc_attr_e|_n)\(\s*'((?:\\.|[^'\\])*)'"
)
for source in sources:
    for match in pattern.finditer(source.read_text(encoding='utf-8')):
        messages.add(match.group(1).replace("\\'", "'").replace('\\\\', '\\'))

pot = (
    'msgid ""\nmsgstr ""\n'
    f'"Project-Id-Version: Smart Media Auditor & Optimizer {version}\\n"\n'
    '"Content-Type: text/plain; charset=UTF-8\\n"\n'
    '"Content-Transfer-Encoding: 8bit\\n"\n\n'
)
for message in sorted(messages):
    pot += 'msgid ' + json.dumps(message, ensure_ascii=False) + '\nmsgstr ""\n\n'
(root / 'languages' / 'smart-media-auditor-optimizer.pot').write_text(pot, encoding='utf-8')
print(f'Translation template: {len(messages)} strings')

# Only these paths ship. Anything not listed is development tooling.
SHIP = (
    'smart-media-auditor-optimizer.php',
    'uninstall.php',
    'readme.txt',
    'LICENSE',
    'includes',
    'assets',
    'languages',
)
SKIP_SUFFIXES = {'.log', '.zip', '.sha256', '.pyc', '.map'}

archive = destination / f'smart-media-auditor-optimizer-{version}.zip'
included = []
with zipfile.ZipFile(archive, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as output:
    for name in SHIP:
        source = root / name
        if not source.exists():
            raise SystemExit(f'Missing required path: {name}')
        paths = sorted(source.rglob('*')) if source.is_dir() else [source]
        for path in paths:
            if not path.is_file() or path.name.startswith('.') or path.suffix in SKIP_SUFFIXES:
                continue
            relative = path.relative_to(root)
            entry = zipfile.ZipInfo(
                str(Path(root.name) / relative).replace('\\', '/'),
                (2026, 9, 18, 0, 0, 0),
            )
            entry.external_attr = 0o644 << 16
            entry.compress_type = zipfile.ZIP_DEFLATED
            output.writestr(entry, path.read_bytes())
            included.append(str(relative).replace('\\', '/'))

with zipfile.ZipFile(archive) as check:
    names = check.namelist()
    assert check.testzip() is None, 'ZIP integrity verification failed'
    assert f'{root.name}/smart-media-auditor-optimizer.php' in names, 'main plugin file missing'
    for forbidden in ('/vendor/', '/node_modules/', '/tests/', '/tools/', '/docs/', '/.git'):
        offenders = [name for name in names if forbidden in name]
        assert not offenders, f'{forbidden} must not ship: {offenders[:3]}'

digest = hashlib.sha256(archive.read_bytes()).hexdigest()
archive.with_suffix('.zip.sha256').write_text(f'{digest}  {archive.name}\n', encoding='ascii')
print(f'Created {archive}')
print(f'{len(included)} files, {archive.stat().st_size:,} bytes')
print(f'SHA-256: {digest}')
