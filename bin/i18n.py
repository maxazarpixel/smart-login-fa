#!/usr/bin/env python3
"""Dependency-free i18n helper (no gettext / WP-CLI required).

  python bin/i18n.py pot        Regenerate languages/smart-login.pot from the PHP sources
  python bin/i18n.py update     Merge new/removed strings from the .pot into every languages/*.po
  python bin/i18n.py mo         Compile every languages/*.po to .mo
  python bin/i18n.py check      Report untranslated / fuzzy-free stats per locale
"""
import os
import re
import struct
import sys
import glob

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
LANG = os.path.join(ROOT, 'languages')
DOMAIN = 'smart-login'
SKIP_DIRS = {'.git', 'bin', 'languages', 'node_modules', '.claude'}

STR = r"""(?:'((?:[^'\\]|\\.)*)'|"((?:[^"\\]|\\.)*)")"""
# function -> argument layout
FUNCS = {
    '__': 'm', '_e': 'm', 'esc_html__': 'm', 'esc_html_e': 'm', 'esc_attr__': 'm', 'esc_attr_e': 'm',
    '_x': 'mc', '_ex': 'mc', 'esc_html_x': 'mc', 'esc_attr_x': 'mc',
    '_n': 'ms', '_nx': 'msc',
}
CALL = re.compile(r'\b(' + '|'.join(FUNCS) + r')\s*\(\s*', re.S)
STRRE = re.compile(STR, re.S)
COMMENT = re.compile(r'/\*.*?\*/|(?<![:\'"])//[^\n]*|#[^\n]*', re.S)


def unescape(single, double):
    if single is not None:
        return re.sub(r"\\(['\\])", r'\1', single)
    s = double
    s = s.replace('\\n', '\n').replace('\\t', '\t').replace('\\r', '\r')
    return re.sub(r'\\(["\\$])', r'\1', s)


def read_string(src, pos):
    m = STRRE.match(src, pos)
    if not m:
        return None, pos
    return unescape(m.group(1), m.group(2)), m.end()


def skip_comma(src, pos):
    m = re.compile(r'\s*,\s*').match(src, pos)
    return m.end() if m else None


def extract():
    entries = {}
    for dp, dn, fn in os.walk(ROOT):
        dn[:] = [d for d in dn if d not in SKIP_DIRS]
        for f in fn:
            if not f.endswith('.php'):
                continue
            path = os.path.join(dp, f)
            rel = os.path.relpath(path, ROOT).replace(os.sep, '/')
            with open(path, encoding='utf-8') as fh:
                src = fh.read()
            line_at = lambda p: src.count('\n', 0, p) + 1
            for m in CALL.finditer(src):
                layout = FUNCS[m.group(1)]
                pos = m.end()
                args = []
                ok = True
                for i, kind in enumerate(layout):
                    s, pos = read_string(src, pos)
                    if s is None:
                        ok = False
                        break
                    args.append(s)
                    if i < len(layout) - 1:
                        pos = skip_comma(src, pos)
                        if pos is None:
                            ok = False
                            break
                if not ok:
                    continue
                # domain check (plural forms have a non-literal count in between)
                if m.group(1) in ('_n', '_nx'):
                    dm = re.compile(r"[^;]{0,160}?,\s*'%s'\s*\)" % DOMAIN, re.S).match(src, pos)
                    if not dm:
                        continue
                else:
                    pos2 = skip_comma(src, pos)
                    if pos2 is None:
                        continue
                    dom, _ = read_string(src, pos2)
                    if dom != DOMAIN:
                        continue
                if layout == 'm':
                    key = ('', args[0], None)
                elif layout == 'mc':
                    key = (args[1], args[0], None)
                elif layout == 'ms':
                    key = ('', args[0], args[1])
                else:
                    key = (args[2], args[0], args[1])
                # translators comment directly above
                pre = src[max(0, m.start() - 400):m.start()]
                tr = ''
                cm = re.search(r'/\*\s*translators:((?:(?!\*/).)*)\*/(?:(?!smart-login)[^;{}])*$', pre, re.S | re.I)
                if cm:
                    tr = ' '.join(cm.group(1).split())
                e = entries.setdefault(key, {'refs': [], 'tr': tr})
                e['refs'].append('%s:%d' % (rel, line_at(m.start())))
                if tr and not e['tr']:
                    e['tr'] = tr
    return entries


def esc(s):
    return s.replace('\\', '\\\\').replace('"', '\\"').replace('\n', '\\n').replace('\t', '\\t')


def po_str(name, s):
    if '\n' in s[:-1]:
        parts = s.split('\n')
        lines = ['%s ""' % name]
        for i, p in enumerate(parts):
            seg = p + ('\n' if i < len(parts) - 1 else '')
            if seg:
                lines.append('"%s"' % esc(seg))
        return '\n'.join(lines)
    return '%s "%s"' % (name, esc(s))


def version():
    with open(os.path.join(ROOT, 'smart-login.php'), encoding='utf-8') as fh:
        return re.search(r'^\s*\*\s*Version:\s*(\S+)', fh.read(), re.M).group(1)


HEADER = '''# Copyright (C) AzarPixel
# This file is distributed under the GPL v2 or later.
msgid ""
msgstr ""
"Project-Id-Version: Smart Login {ver}\\n"
"Report-Msgid-Bugs-To: https://www.azarpixel.com\\n"
"POT-Creation-Date: 2026-01-01 00:00+0000\\n"
"MIME-Version: 1.0\\n"
"Content-Type: text/plain; charset=UTF-8\\n"
"Content-Transfer-Encoding: 8bit\\n"
"X-Domain: smart-login\\n"
'''


def cmd_pot():
    entries = extract()
    os.makedirs(LANG, exist_ok=True)
    out = [HEADER.format(ver=version()).replace('# Copyright', '# Copyright', 1)]
    for key in sorted(entries, key=lambda k: (entries[k]['refs'][0], k[1])):
        ctx, msgid, plural = key
        e = entries[key]
        out.append('')
        if e['tr']:
            out.append('#. translators: ' + e['tr'].replace('\n', ' '))
        out.append('#: ' + ' '.join(e['refs']))
        if re.search(r'%(\d+\$)?[sd]', msgid):
            out.append('#, php-format')
        if ctx:
            out.append(po_str('msgctxt', ctx))
        out.append(po_str('msgid', msgid))
        if plural is not None:
            out.append(po_str('msgid_plural', plural))
            out.append('msgstr[0] ""')
            out.append('msgstr[1] ""')
        else:
            out.append('msgstr ""')
    with open(os.path.join(LANG, DOMAIN + '.pot'), 'w', encoding='utf-8', newline='\n') as fh:
        fh.write('\n'.join(out) + '\n')
    print('pot: %d strings' % len(entries))


# ---- PO parsing / writing -------------------------------------------------

def parse_po(path):
    """Return (header_lines_text, ordered list of dict entries)."""
    with open(path, encoding='utf-8') as fh:
        text = fh.read()
    blocks = re.split(r'\n\s*\n', text.strip('\n'))
    entries = []
    for b in blocks:
        ent = {'comments': [], 'msgctxt': None, 'msgid': None, 'msgid_plural': None, 'msgstr': {}}
        cur = None
        for line in b.split('\n'):
            if line.startswith('#'):
                ent['comments'].append(line)
                continue
            m = re.match(r'(msgctxt|msgid_plural|msgid|msgstr(?:\[(\d+)\])?)\s+(".*")\s*$', line)
            if m:
                name = m.group(1)
                val = unq(m.group(3))
                if name.startswith('msgstr'):
                    idx = int(m.group(2)) if m.group(2) is not None else 0
                    ent['msgstr'][idx] = val
                    cur = ('msgstr', idx)
                else:
                    ent[name] = val
                    cur = (name, None)
                continue
            m = re.match(r'\s*(".*")\s*$', line)
            if m and cur:
                val = unq(m.group(1))
                if cur[0] == 'msgstr':
                    ent['msgstr'][cur[1]] += val
                else:
                    ent[cur[0]] += val
        if ent['msgid'] is not None:
            entries.append(ent)
    return entries


def unq(s):
    s = s[1:-1]
    out = []
    i = 0
    while i < len(s):
        c = s[i]
        if c == '\\' and i + 1 < len(s):
            n = s[i + 1]
            out.append({'n': '\n', 't': '\t', 'r': '\r', '"': '"', '\\': '\\'}.get(n, n))
            i += 2
        else:
            out.append(c)
            i += 1
    return ''.join(out)


def ekey(e):
    return (e['msgctxt'] or '', e['msgid'], e['msgid_plural'])


def cmd_update():
    pot = parse_po(os.path.join(LANG, DOMAIN + '.pot'))
    for po_path in sorted(glob.glob(os.path.join(LANG, DOMAIN + '-*.po'))):
        with open(po_path, encoding='utf-8') as fh:
            head = fh.read().split('\n\n', 1)[0]
        old = {ekey(e): e for e in parse_po(po_path)}
        out = [head]
        added = 0
        for p in pot:
            if p['msgid'] == '':
                continue
            o = old.get(ekey(p))
            out.append('')
            out.extend(c for c in p['comments'])
            if p['msgctxt']:
                out.append(po_str('msgctxt', p['msgctxt']))
            out.append(po_str('msgid', p['msgid']))
            if p['msgid_plural'] is not None:
                out.append(po_str('msgid_plural', p['msgid_plural']))
                n = max(2, len(o['msgstr']) if o else 2)
                for i in range(n):
                    out.append(po_str('msgstr[%d]' % i, o['msgstr'].get(i, '') if o else ''))
            else:
                out.append(po_str('msgstr', o['msgstr'].get(0, '') if o else ''))
            if not o:
                added += 1
        with open(po_path, 'w', encoding='utf-8', newline='\n') as fh:
            fh.write('\n'.join(out) + '\n')
        print('%s: +%d new' % (os.path.basename(po_path), added))


def cmd_mo():
    for po_path in sorted(glob.glob(os.path.join(LANG, DOMAIN + '-*.po'))):
        msgs = {}
        for e in parse_po(po_path):
            if e['msgid'] == '':
                msgs[''] = e['msgstr'][0]
                continue
            if e['msgid_plural'] is not None:
                forms = [e['msgstr'][i] for i in sorted(e['msgstr'])]
                if not any(forms):
                    continue
                key = e['msgid'] + '\0' + e['msgid_plural']
                val = '\0'.join(forms)
            else:
                val = e['msgstr'].get(0, '')
                if not val:
                    continue
                key = e['msgid']
            if e['msgctxt']:
                key = e['msgctxt'] + '\x04' + key
            msgs[key] = val
        keys = sorted(msgs)
        ids = b''
        strs = b''
        offs = []
        for k in keys:
            kb = k.encode('utf-8')
            vb = msgs[k].encode('utf-8')
            offs.append((len(ids), len(kb), len(strs), len(vb)))
            ids += kb + b'\0'
            strs += vb + b'\0'
        n = len(keys)
        kstart = 7 * 4 + 16 * n
        vstart = kstart + len(ids)
        table = b''
        for o1, l1, o2, l2 in offs:
            table += struct.pack('<II', l1, o1 + kstart)
        vtable = b''
        for o1, l1, o2, l2 in offs:
            vtable += struct.pack('<II', l2, o2 + vstart)
        data = struct.pack('<Iiiiiii', 0x950412de, 0, n, 28, 28 + 8 * n, 0, 0) + table + vtable + ids + strs
        mo_path = po_path[:-3] + '.mo'
        with open(mo_path, 'wb') as fh:
            fh.write(data)
        print('%s: %d messages' % (os.path.basename(mo_path), n - (1 if '' in msgs else 0)))


def cmd_check():
    pot = [e for e in parse_po(os.path.join(LANG, DOMAIN + '.pot')) if e['msgid']]
    for po_path in sorted(glob.glob(os.path.join(LANG, DOMAIN + '-*.po'))):
        ents = {ekey(e): e for e in parse_po(po_path)}
        missing = []
        bad = []
        for p in pot:
            e = ents.get(ekey(p))
            if not e or not any(e['msgstr'].values()):
                missing.append(p['msgid'])
                continue
            # placeholder parity
            ph = lambda s: sorted(re.findall(r'%(?:\d+\$)?[sd]|\{[a-z_]+\}', s))
            for v in e['msgstr'].values():
                if v and ph(v) != ph(p['msgid']) and p['msgid_plural'] is None:
                    bad.append(p['msgid'])
                    break
        print('%s: %d/%d translated, %d placeholder mismatches' % (os.path.basename(po_path), len(pot) - len(missing), len(pot), len(bad)))
        for m in missing[:40]:
            print('  MISSING:', repr(m[:80]))
        for m in bad:
            print('  PLACEHOLDER:', repr(m[:80]))


if __name__ == '__main__':
    cmds = {'pot': cmd_pot, 'update': cmd_update, 'mo': cmd_mo, 'check': cmd_check}
    if len(sys.argv) != 2 or sys.argv[1] not in cmds:
        print(__doc__)
        sys.exit(1)
    cmds[sys.argv[1]]()
