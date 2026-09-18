# -*- coding: utf-8 -*-
"""Compile a gettext .po file into the binary .mo WordPress loads.

    python3 bin/po2mo.py languages/wp-ai-advisor-nb_NO.po

The .po file is the source of truth; the .mo is generated from it. Written out
by hand because msgfmt is not always available.
"""
import os
import re
import struct
import sys


def unescape(value):
    out, i = '', 0
    while i < len(value):
        if value[i] == '\\' and i + 1 < len(value):
            out += {'n': '\n', 't': '\t', 'r': '\r', '"': '"', '\\': '\\'}.get(value[i + 1], value[i + 1])
            i += 2
        else:
            out += value[i]
            i += 1
    return out


def parse_po(path):
    """Returns {msgid: msgstr} for every translated, non-fuzzy singular entry."""
    entries = {}
    msgid = msgstr = None
    target = None
    fuzzy = False
    plural = False

    def flush():
        if msgid is not None and msgstr:
            entries[msgid] = msgstr

    with open(path, encoding='utf-8') as handle:
        for raw in handle:
            line = raw.rstrip('\n')

            if line.startswith('#,') and 'fuzzy' in line:
                fuzzy = True
                continue

            if line.startswith('#') or not line.strip():
                if not fuzzy and not plural:
                    flush()
                msgid = msgstr = target = None
                fuzzy = plural = False
                continue

            if line.startswith('msgctxt '):
                # Contexts would need the EOT-joined key; none are used yet.
                plural = True
                continue

            if line.startswith('msgid_plural '):
                plural = True
                continue

            if line.startswith('msgid '):
                msgid = unescape(line[7:-1])
                target = 'id'
                continue

            if line.startswith('msgstr '):
                msgstr = unescape(line[8:-1])
                target = 'str'
                continue

            if line.startswith('"') and target:
                chunk = unescape(line[1:-1])
                if target == 'id':
                    msgid += chunk
                else:
                    msgstr += chunk

    if not fuzzy and not plural:
        flush()

    return entries


def write_mo(entries, path):
    # Each entry is NUL-terminated in the blob and the offsets count that
    # terminator; building the blob with join() drops the last one and shifts
    # every string offset by a byte.
    items = sorted(entries.items())
    count = len(items)
    ids = strs = b''
    offsets = []

    for key, value in items:
        kb, vb = key.encode('utf-8'), value.encode('utf-8')
        offsets.append((len(ids), len(kb), len(strs), len(vb)))
        ids += kb + b'\x00'
        strs += vb + b'\x00'

    keystart = 7 * 4 + 16 * count
    valuestart = keystart + len(ids)
    koffsets, voffsets = [], []

    for o1, l1, o2, l2 in offsets:
        koffsets += [l1, o1 + keystart]
        voffsets += [l2, o2 + valuestart]

    out = struct.pack('<Iiiiiii', 0x950412DE, 0, count, 7 * 4, 7 * 4 + count * 8, 0, 0)
    out += struct.pack('<' + 'i' * len(koffsets), *koffsets)
    out += struct.pack('<' + 'i' * len(voffsets), *voffsets)
    out += ids + strs

    with open(path, 'wb') as handle:
        handle.write(out)

    return count


def main():
    if len(sys.argv) < 2:
        print(__doc__)
        return 1

    for po in sys.argv[1:]:
        entries = parse_po(po)

        if '' not in entries:
            print(f'{po}: no header entry; refusing to write a .mo')
            return 1

        mo = re.sub(r'\.po$', '.mo', po)
        count = write_mo(entries, mo)
        print(f'{os.path.basename(mo)}: {count - 1} translations')

    return 0


if __name__ == '__main__':
    sys.exit(main())
