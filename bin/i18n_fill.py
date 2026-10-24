#!/usr/bin/env python3
"""Fills empty msgstr entries in the es_ES and fa_IR catalogues from a Python
file defining  TR = [(english_prefix, es, fa), ...]  (longest prefix wins).

  python bin/i18n_fill.py path/to/translations.py

Only empty translations are touched, so existing ones are never overwritten.
Run `python bin/i18n.py check` and `python bin/i18n.py mo` afterwards.
"""
import importlib.util
import os
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
import i18n  # noqa: E402

LOCALES = ((1, 'es_ES'), (2, 'fa_IR'))


def load(path):
    spec = importlib.util.spec_from_file_location('tr_input', path)
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod.TR


def find(tr, msgid):
    best = None
    for item in tr:
        if msgid.startswith(item[0]) and (best is None or len(item[0]) > len(best[0])):
            best = item
    return best


def main():
    if len(sys.argv) != 2:
        print(__doc__)
        sys.exit(1)

    tr = load(sys.argv[1])
    lang_dir = os.path.join(i18n.ROOT, 'languages')

    for idx, loc in LOCALES:
        path = os.path.join(lang_dir, 'smart-login-%s.po' % loc)
        entries = i18n.parse_po(path)
        with open(path, encoding='utf-8') as fh:
            out = [fh.read().split('\n\n', 1)[0]]
        filled = 0

        for e in entries:
            if e['msgid'] == '':
                continue
            out.append('')
            out.extend(e['comments'])
            if e['msgctxt']:
                out.append(i18n.po_str('msgctxt', e['msgctxt']))
            out.append(i18n.po_str('msgid', e['msgid']))
            if e['msgid_plural'] is not None:
                out.append(i18n.po_str('msgid_plural', e['msgid_plural']))
                for k in sorted(e['msgstr']):
                    out.append(i18n.po_str('msgstr[%d]' % k, e['msgstr'][k]))
            else:
                value = e['msgstr'].get(0, '')
                if not value:
                    match = find(tr, e['msgid'])
                    if match:
                        value = match[idx]
                        filled += 1
                out.append(i18n.po_str('msgstr', value))

        with open(path, 'w', encoding='utf-8', newline='\n') as fh:
            fh.write('\n'.join(out) + '\n')
        print('%s: filled %d' % (loc, filled))


if __name__ == '__main__':
    main()
