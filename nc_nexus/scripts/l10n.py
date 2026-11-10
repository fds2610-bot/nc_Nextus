#!/usr/bin/env python3
"""Übersetzungen von nc_nexus pflegen.

    python3 scripts/l10n.py check   – fehlende deutsche Übersetzungen auflisten (Exit 1, wenn welche fehlen)
    python3 scripts/l10n.py write   – l10n/de.json neu sortiert nach de.js, de_DE.json und de_DE.js übertragen

Quelle der Wahrheit ist l10n/de.json. Gesucht wird nach t('nc_nexus', …), n('nc_nexus', …, …) im
Frontend und nach Exception-Meldungen im Backend (der Controller übersetzt sie per IL10N).
"""
import glob
import json
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DE = os.path.join(ROOT, 'l10n', 'de.json')


def keys():
    src = ''.join(open(f, encoding='utf-8').read() for f in glob.glob(os.path.join(ROOT, 'src', '**', '*.*'), recursive=True))
    php = ''.join(open(f, encoding='utf-8').read() for f in glob.glob(os.path.join(ROOT, 'lib', '**', '*.php'), recursive=True))
    singles = set(re.findall(r"\bt\('nc_nexus', '([^']+)'", src)) | set(re.findall(r"Exception\('([^']+)'\)", php))
    plurals = {f'_{a}_::_{b}_' for a, b in re.findall(r"\bn\('nc_nexus', '([^']+)', '([^']+)'", src)}
    return singles | plurals


def main():
    data = json.load(open(DE, encoding='utf-8'))
    if sys.argv[1:] == ['check']:
        missing = sorted(k for k in keys() if k not in data['translations'])
        print('\n'.join(missing) if missing else 'Alle Texte übersetzt.')
        sys.exit(1 if missing else 0)
    if sys.argv[1:] == ['write']:
        tr = dict(sorted(data['translations'].items(), key=lambda kv: kv[0].lower()))
        plural = data['pluralForm']
        for base in ('de', 'de_DE'):
            with open(os.path.join(ROOT, 'l10n', base + '.json'), 'w', encoding='utf-8') as f:
                f.write(json.dumps({'translations': tr, 'pluralForm': plural}, ensure_ascii=False, indent=4) + '\n')
            with open(os.path.join(ROOT, 'l10n', base + '.js'), 'w', encoding='utf-8') as f:
                body = json.dumps(tr, ensure_ascii=False, indent=4).replace('\n', '\n    ')
                f.write(f'OC.L10N.register(\n    "nc_nexus",\n    {body},\n"{plural}");\n')
        return
    print(__doc__)
    sys.exit(2)


if __name__ == '__main__':
    main()
