"""Regenerate languages/wp-ai-advisor.pot from the plugin's i18n calls.

A small stand-in for `wp i18n make-pot` / xgettext, so translations can be
refreshed without those tools installed.

    python3 bin/make-pot.py

Strings whose text domain is wrong or which are not plain literals are reported
rather than silently dropped - that report is the point of running it.
"""
import re, glob, os, sys, collections

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DOMAIN = 'wp-ai-advisor'

# Functions and which argument positions hold translatable text.
FUNCS = {
    '__': (1,), '_e': (1,), 'esc_html__': (1,), 'esc_html_e': (1,),
    'esc_attr__': (1,), 'esc_attr_e': (1,), '_x': (1, 2), 'esc_html_x': (1, 2),
    '_n': (1, 2),
}

def php_strings(src):
    """Yield (func, lineno, [literal args or None], comment_before)."""
    pattern = re.compile(r"\b(" + "|".join(map(re.escape, FUNCS)) + r")\s*\(")
    for m in pattern.finditer(src):
        func = m.group(1)
        i = m.end()
        depth, args, cur, in_str, quote, esc = 1, [], '', False, '', False
        while i < len(src) and depth > 0:
            ch = src[i]
            if in_str:
                if esc: esc = False
                elif ch == '\\': esc = True
                elif ch == quote: in_str = False
                cur += ch
            else:
                if ch in "'\"": in_str, quote, cur = True, ch, cur + ch
                elif ch == '(': depth += 1; cur += ch
                elif ch == ')':
                    depth -= 1
                    if depth == 0: args.append(cur); break
                    cur += ch
                elif ch == ',' and depth == 1: args.append(cur); cur = ''
                else: cur += ch
            i += 1
        lineno = src.count('\n', 0, m.start()) + 1
        # translator comment on the lines just above
        before = src[:m.start()].rsplit('\n', 4)[0:]
        chunk = src[max(0, m.start()-400):m.start()]
        cm = re.findall(r'/\*\s*(translators:.*?)\s*\*/', chunk, re.S)
        comment = cm[-1].replace('\n', ' ').strip() if cm else None
        if comment:
            tail = chunk[chunk.rfind(cm[-1]):]
            if tail.count('\n') > 3:
                comment = None
        yield func, lineno, args, comment

def literal(arg):
    arg = arg.strip()
    m = re.fullmatch(r"'((?:\\.|[^'\\])*)'", arg, re.S)
    if m:
        return m.group(1).replace("\\'", "'").replace('\\\\', '\\')
    m = re.fullmatch(r'"((?:\\.|[^"\\])*)"', arg, re.S)
    if m:
        s = m.group(1)
        for a, b in (('\\"', '"'), ('\\n', '\n'), ('\\t', '\t'), ('\\\\', '\\')):
            s = s.replace(a, b)
        return s
    return None

def escape(s):
    return (s.replace('\\', '\\\\').replace('"', '\\"')
             .replace('\n', '\\n').replace('\t', '\\t'))

entries = collections.OrderedDict()
files = sorted(f for f in glob.glob(ROOT + '/**/*.php', recursive=True)
               if '/tests/' not in f)
skipped = []
for path in files:
    src = open(path).read()
    rel = os.path.relpath(path, ROOT)
    for func, lineno, args, comment in php_strings(src):
        positions = FUNCS[func]
        if len(args) < max(positions):
            continue
        # domain must be the last arg and match
        dom = literal(args[-1])
        if dom != DOMAIN:
            skipped.append(f'{rel}:{lineno} {func} (domain={dom!r})')
            continue
        texts = [literal(args[p - 1]) for p in positions]
        if any(t is None for t in texts):
            skipped.append(f'{rel}:{lineno} {func} (non-literal)')
            continue
        if func in ('_x', 'esc_html_x'):
            key = (texts[1], texts[0], None)   # (context, msgid, plural)
        elif func == '_n':
            key = (None, texts[0], texts[1])
        else:
            key = (None, texts[0], None)
        e = entries.setdefault(key, {'refs': [], 'comment': comment})
        e['refs'].append(f'{rel}:{lineno}')
        if comment and not e['comment']:
            e['comment'] = comment

out = ['# Copyright (C) 2026 WP AI Advisor',
       '# This file is distributed under the GPL-2.0-or-later license.',
       'msgid ""', 'msgstr ""',
       '"Project-Id-Version: WP AI Advisor 0.4.0\\n"',
       '"Report-Msgid-Bugs-To: https://github.com/andregieaver/wp-ai-advisor/issues\\n"',
       '"MIME-Version: 1.0\\n"',
       '"Content-Type: text/plain; charset=UTF-8\\n"',
       '"Content-Transfer-Encoding: 8bit\\n"',
       '"Plural-Forms: nplurals=2; plural=(n != 1);\\n"',
       '"X-Domain: wp-ai-advisor\\n"', '']
for (ctx, msgid, plural), e in entries.items():
    if e['comment']:
        out.append('#. ' + e['comment'])
    out.append('#: ' + ' '.join(e['refs']))
    if ctx:
        out.append(f'msgctxt "{escape(ctx)}"')
    out.append(f'msgid "{escape(msgid)}"')
    if plural:
        out.append(f'msgid_plural "{escape(plural)}"')
        out.append('msgstr[0] ""')
        out.append('msgstr[1] ""')
    else:
        out.append('msgstr ""')
    out.append('')

os.makedirs(ROOT + '/languages', exist_ok=True)
open(ROOT + '/languages/wp-ai-advisor.pot', 'w').write('\n'.join(out))
print(f'{len(entries)} strings from {len(files)} files')
if skipped:
    print('SKIPPED (check these):')
    for s in skipped[:20]:
        print('  ' + s)

sys.exit(1 if skipped else 0)
