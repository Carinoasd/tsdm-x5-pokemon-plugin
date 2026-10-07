#!/usr/bin/env python3
"""Convert the archived X2 database dump (pm.sql) into X5 canonical-schema INSERT SQL.

Reads the full X2 backup dump (old column names: txt/sd/god/hpn/minmoney...),
transforms every pm_* table into the canonical X5 schema (new column names +
JSON-consolidated effort_values / drop_money / effects / equipment), and emits
a single importable .sql file.

Supported input: UTF-8 (optional BOM), archived X2 fixed-order INSERT INTO
`pm_*` VALUES statements, single-quoted MySQL strings, integer literals and
NULL. Backslash escapes must be enabled (the standard dump mode). Explicit
NO_BACKSLASH_ESCAPES, expressions, column lists and other INSERT formats are
rejected rather than guessed. Ordinary dump DDL/comments are ignored. A final
mysqldump SQL_MODE restoration is allowed, but no data may follow it. Invalid
input never creates or replaces the output file.

Usage:
    python x2_to_x5_export.py <input.sql> <output.sql>
"""

import io
import json
import os
import re
import sys
import tempfile


MYSQL_ESCAPES = {
    "0": "\0", "b": "\b", "n": "\n", "r": "\r", "t": "\t", "Z": "\x1a",
    "\\": "\\", "'": "'", '"': '"', "%": "\\%", "_": "\\_",
}
INTEGER_LITERAL = re.compile(r"[+-]?[0-9]+\Z")


def quoted_string(text, start):
    """Read one single-quoted MySQL literal with normal backslash semantics."""
    value = []
    i = start + 1
    while i < len(text):
        c = text[i]
        if c == "\\":
            i += 1
            if i == len(text):
                raise ValueError("unfinished backslash escape")
            # Unknown escapes lose their slash; LIKE wildcards retain it.
            value.append(MYSQL_ESCAPES.get(text[i], text[i]))
        elif c == "'":
            if i + 1 < len(text) and text[i + 1] == "'":
                value.append("'")
                i += 1
            else:
                return "".join(value), i + 1
        else:
            value.append(c)
        i += 1
    raise ValueError("unterminated quoted string")


def sql_statements(text):
    """Split dump statements, keeping quoted data out of delimiter/comment logic."""
    parts = []
    i = 0
    while i < len(text):
        c = text[i]
        if c in "'\"`":
            start, quote = i, c
            i += 1
            while i < len(text):
                if text[i] == "\\" and quote != "`":
                    i += 2
                    continue
                if text[i] == quote:
                    i += 1
                    if i < len(text) and text[i] == quote:
                        i += 1
                        continue
                    break
                i += 1
            else:
                raise ValueError("unterminated quoted string or identifier")
            parts.append(text[start:i])
            continue
        if c == "#" or (text.startswith("--", i) and
                         (i + 2 == len(text) or text[i + 2].isspace())):
            end = text.find("\n", i)
            i = len(text) if end == -1 else end + 1
            parts.append(" ")
            continue
        if text.startswith("/*", i):
            end = text.find("*/", i + 2)
            if end == -1:
                raise ValueError("unterminated SQL comment")
            comment = text[i + 2:end]
            # mysqldump wraps SET directives in executable version comments.
            if comment.startswith("!") or comment.startswith("M!"):
                parts.append(re.sub(r"^(?:M)?!\d*\s*", "", comment))
            else:
                parts.append(" ")
            i = end + 2
            continue
        if c == ";":
            statement = "".join(parts).strip()
            if statement:
                yield statement
            parts = []
        else:
            parts.append(c)
        i += 1
    if "".join(parts).strip():
        raise ValueError("SQL statement is missing its terminating semicolon")


def sql_mode_is_known(statement, previous):
    """Recognize dump SQL_MODE assignments without evaluating SQL expressions."""
    if not re.match(r"SET\s", statement, re.IGNORECASE):
        return previous
    # Mask string values so text inside unrelated SET strings is never a directive.
    masked = list(statement)
    i = 0
    while i < len(statement):
        if statement[i] == "'":
            _, end = quoted_string(statement, i)
            masked[i:end] = " " * (end - i)
            i = end
        else:
            i += 1
    assignments = re.finditer(r"(?<![\w@])(?:@@(?:SESSION\.|LOCAL\.)?)?`?SQL_MODE`?\s*=\s*",
                              "".join(masked), re.IGNORECASE)
    known = previous
    for assignment in assignments:
        # Whitespace in the masked value is not part of the actual assignment.
        start = statement.index("=", assignment.start()) + 1
        while start < len(statement) and statement[start].isspace():
            start += 1
        if start < len(statement) and statement[start] == "'":
            mode, end = quoted_string(statement, start)
            if statement[end:].strip() and not statement[end:].lstrip().startswith(","):
                raise ValueError("unsupported SQL_MODE expression")
            if "NO_BACKSLASH_ESCAPES" in {m.strip().upper() for m in mode.split(",")}:
                raise ValueError("NO_BACKSLASH_ESCAPES dumps are not supported")
            known = True
        elif re.fullmatch(r"@OLD_SQL_MODE\s*", statement[start:], re.IGNORECASE):
            known = False  # Standard dump trailer; its original mode is unknown.
        else:
            raise ValueError("unsupported SQL_MODE expression")
    return known


def parse_inserts(text: str):
    """Yield (table, rows) for every `INSERT INTO `pm_x` VALUES ...;` block."""
    pattern = re.compile(
        r"INSERT\s+INTO\s+`(pm_\w+)`\s+VALUES\s*(.*)\Z", re.IGNORECASE | re.DOTALL
    )
    target_pattern = re.compile(
        r"INSERT\s+(?:(?:LOW_PRIORITY|DELAYED|HIGH_PRIORITY|IGNORE)\s+)*INTO\s+"
        r"(?:`([^`]+)`|([A-Za-z_][A-Za-z_0-9]*))(?=\s|\()", re.IGNORECASE
    )
    known_mode = True
    for statement in sql_statements(text):
        known_mode = sql_mode_is_known(statement, known_mode)
        if not re.match(r"INSERT\b", statement, re.IGNORECASE):
            continue
        target = target_pattern.match(statement)
        if target and not (target.group(1) or target.group(2)).lower().startswith("pm_"):
            # Full backups may also contain Discuz tables; retain the old filter.
            continue
        m = pattern.fullmatch(statement)
        if m is None:
            raise ValueError("unsupported INSERT format; expected archived X2 VALUES without a column list")
        if not known_mode:
            raise ValueError("INSERT follows an unknown restored SQL_MODE")
        try:
            yield m.group(1).lower(), parse_values_tuples(m.group(2))
        except ValueError as error:
            raise ValueError(f"{m.group(1)}: {error}") from error


def parse_values_tuples(body: str):
    """Parse the supported VALUES grammar; reject partial rows and expressions."""
    rows = []
    i = 0
    n = len(body)
    while True:
        while i < n and body[i].isspace():
            i += 1
        if i >= n or body[i] != "(":
            raise ValueError("expected a VALUES tuple")
        i += 1
        vals = []
        while True:
            while i < n and body[i].isspace():
                i += 1
            if i >= n:
                raise ValueError("unfinished VALUES tuple")
            if body[i] == "'":
                value, i = quoted_string(body, i)
            else:
                start = i
                while i < n and body[i] not in ",)":
                    i += 1
                token = body[start:i].strip()
                if token.upper() == "NULL":
                    value = None
                elif INTEGER_LITERAL.fullmatch(token):
                    value = int(token)
                else:
                    raise ValueError("expected a string, integer literal or NULL")
            vals.append(value)
            while i < n and body[i].isspace():
                i += 1
            if i >= n or body[i] not in ",)":
                raise ValueError("expected a comma or closing parenthesis")
            delimiter = body[i]
            i += 1
            if delimiter == ")":
                rows.append(vals)
                break
        while i < n and body[i].isspace():
            i += 1
        if i == n:
            return rows
        if body[i] != ",":
            raise ValueError("expected a comma between VALUES tuples")
        i += 1


def esc(v):
    """Render a value for the output SQL."""
    if v is None:
        return "NULL"
    if isinstance(v, (int, float)):
        return str(v)
    escapes = {"\0": r"\0", "\b": r"\b", "\n": r"\n", "\r": r"\r", "\t": r"\t",
               "\x1a": r"\Z", "\\": r"\\", "'": r"\'"}
    return "'" + "".join(escapes.get(c, c) for c in str(v)) + "'"


def num(v):
    if v is None or v == "":
        return 0
    if isinstance(v, int) or (isinstance(v, str) and INTEGER_LITERAL.fullmatch(v.strip())):
        return int(v)
    raise ValueError("expected an integer in a numeric X2 column")


def norm_uid(v):
    return num(v)


# ---------------------------------------------------------------- mappings

def row_pm_data(v):
    """old: id,name,money,txt,sex,xs,xs2,hp,atk,def,spatk,spdef,sd,mapid,
    capture,met,shop,hpn,atkn,defn,spatkn,spdefn,sdn,birth,birthodds,
    pnclevel,god,minmoney,maxmoney,strength"""
    (i, name, money, txt, sex, xs, xs2, hp, atk, d, spatk, spdef, sd,
     mapid, capture, met, shop, hpn, atkn, defn, spatkn, spdefn, sdn,
     birth, birthodds, pnclevel, god, minmoney, maxmoney, strength) = v[:30]
    ev = json.dumps(
        {
            "hp": num(hpn), "atk": num(atkn), "def": num(defn),
            "spatk": num(spatkn), "spdef": num(spdefn), "spd": num(sdn),
        },
        ensure_ascii=False,
    )
    dm = "[%s,%s]" % (num(minmoney), num(maxmoney))
    return [
        num(i), name, num(money), txt or "", num(sex), xs or "", xs2 or "",
        num(hp), num(atk), num(d), num(spatk), num(spdef), num(sd),
        mapid or "", num(capture), num(met), num(shop), ev,
        num(birth), num(god), dm, num(strength),
    ]


def _module_name(*values):
    """First identifier-like value (module names live in sitemname or tpname)."""
    for v in values:
        v = (v or "").strip()
        if re.match(r"^[A-Za-z][A-Za-z0-9_]*$", v):
            return v
    return ""


def row_pm_itemdata(v):
    """old: id,name,tpname,shop,money,txt,type,lvask,xsask,addhp,addexp,
    addlv,addgood,ballid,upitem,captmax,captmin,sitemname,hot,zbtype,
    equipment_hp,equipment_atk,equipment_def,equipment_spatk,
    equipment_spdef,equipment_sd"""
    (i, name, tpname, shop, money, txt, typ, lvask, xsask, addhp, addexp,
     addlv, addgood, ballid, upitem, captmax, captmin, sitemname, hot,
     zbtype, eq_hp, eq_atk, eq_def, eq_spatk, eq_spdef, eq_sd) = v[:26]
    effects = json.dumps(
        {
            "hp": num(addhp), "exp": num(addexp), "level": num(addlv),
            "intimacy": num(addgood),
        },
        ensure_ascii=False,
    )
    equip = json.dumps(
        {
            "hp": num(eq_hp), "atk": num(eq_atk), "def": num(eq_def),
            "spatk": num(eq_spatk), "spdef": num(eq_spdef), "spd": num(eq_sd),
        },
        ensure_ascii=False,
    )
    # Old rows keep the module function name in sitemname (enhancers/PP)
    # or tpname (stones/candies); backfill it into the module column.
    module = _module_name(sitemname, tpname)
    return [
        num(i), name, tpname or "", txt or "", num(shop), num(money),
        num(typ), module, num(lvask), xsask or "", effects, num(ballid),
        num(upitem), num(captmax), sitemname or "", num(zbtype), equip,
    ]


def row_pm_map(v):
    """old: id,name,kg,minlevel,maxlevel,expn,site,exp"""
    (i, name, kg, minlevel, maxlevel, expn, site, exp_) = v[:8]
    return [
        num(i), name, num(kg), num(minlevel), num(maxlevel), num(exp_),
        site or "", expn or "", "", 50, 50,
    ]


def row_pm_skill(v):
    """old: id,pmid,name,txt,lv,powr,num,type,tn,category"""
    (i, pmid, name, txt, lv, powr, num_u, typ, tn, category) = v[:10]
    return [
        num(i), pmid or "", name, txt or "", num(lv), num(powr), num(num_u),
        num(typ), tn or "", category or "",
    ]


def row_pm_mypm(v):
    """old: id,pctime,uid,pmname,nowname,pmno,level,exp,sex,sx,hp,hpg,
    atkg,defg,spatkg,spdefg,sdg,good,itemevolve,ballid,site,state,
    statetime,gduptime,hpn,atkn,defn,spatkn,spdefn,sdn,initialuid,swap,
    wakenum,sg,equipmentid1,equipmentid2,equipmentid3,equipmentid4,sx2,txkg,tx"""
    (i, pctime, uid, pmname, nowname, pmno, level, exp, sex, sx, hp, hpg,
     atkg, defg, spatkg, spdefg, sdg, good, itemevolve, ballid, site,
     state, statetime, gduptime, hpn, atkn, defn, spatkn, spdefn, sdn,
     initialuid, swap, wakenum, sg, eq1, eq2, eq3, eq4, sx2, txkg, tx) = v[:41]
    # X2 stores arbitrary 0-100 values in sg; X3/X5 semantics: only ==1 is shiny
    is_shiny = 1 if num(sg) == 1 else 0
    return [
        num(i), num(pctime), norm_uid(uid), pmname or "", nowname or "",
        num(pmno), num(level), num(exp), num(sex), sx or "", num(hp),
        num(hpg), num(atkg), num(defg), num(spatkg), num(spdefg), num(sdg),
        num(good), num(itemevolve), num(ballid), num(site), num(state),
        num(statetime), num(gduptime), num(hpn), num(atkn), num(defn),
        num(spatkn), num(spdefn), num(sdn), norm_uid(initialuid), num(swap),
        is_shiny, num(eq1), num(eq2), num(eq3), num(eq4), 0,
    ]


def row_pm_usersdata(v):
    """old: uid,username,npcid,hpg,hp,atkg,spatkg,defg,spdefg,sdg,allure,
    capture,level,ppkname,ppktime,ppkround,ppk,ppkfight,ppkdodge,ppkot,
    ppkpriority,exchanguid,exchangepmid,dataall,datawin,datalost,fullexp,
    npcsg,boxnum,strength,pkid,hpn,atkn,defn,spatkn,spdefn,sdn,money"""
    (uid, username, npcid, hpg, hp, atkg, spatkg, defg, spdefg, sdg, allure,
     capture, level, ppkname, ppktime, ppkround, ppk, ppkfight, ppkdodge,
     ppkot, ppkpriority, exchanguid, exchangepmid, dataall, datawin,
     datalost, fullexp, npcsg, boxnum, strength, pkid, hpn, atkn, defn,
     spatkn, spdefn, sdn, money) = v[:38]
    return [
        norm_uid(uid), num(npcid), num(hpg), num(hp), num(atkg), num(spatkg),
        num(defg), num(spdefg), num(sdg), num(allure), num(capture),
        num(level), num(dataall), num(datawin), num(datalost), num(fullexp),
        num(boxnum), num(strength), 100, num(money),
    ]


def row_pm_myskill(v):
    """old: uid,petid,skillid,skillnum (no id column)"""
    (uid, petid, skillid, skillnum) = v[:4]
    return [norm_uid(uid), num(petid), num(skillid), num(skillnum)]


def row_pm_myitem(v):
    """old: id,itemid,num,ball,pmid,uid"""
    (i, itemid, num_u, ball, pmid, uid) = v[:6]
    return [num(i), norm_uid(uid), str(itemid), num(num_u)]


def row_pm_up(v):
    """old evolution table -> pm_evolution: id,pmid,cond,val,targetpmid,priority"""
    (i, pmid, cond, val, targetpmid, priority) = v[:6]
    return [num(i), num(pmid), num(targetpmid), cond or "level", val or "", num(priority)]


# table -> (output columns, row transformer)
MAPPINGS = {
    "pm_config": (["key", "value", "data_type"], lambda v: v[:3]),
    "pm_data": (
        ["id", "name", "money", "description", "sex", "xs", "xs2", "hp",
         "atk", "def", "spatk", "spdef", "speed", "mapid", "capture",
         "met", "shop", "effort_values", "birth", "is_legendary",
         "drop_money", "strength"],
        row_pm_data,
    ),
    "pm_itemdata": (
        ["id", "name", "tpname", "description", "shop", "money", "type",
         "module", "lvask", "xsask", "effects", "ballid", "upitem",
         "captmax", "sitemname", "zbtype", "equipment"],
        row_pm_itemdata,
    ),
    "pm_map": (
        ["id", "name", "is_enabled", "min_level", "max_level", "experience",
         "site", "boss_config", "region", "pos_x", "pos_y"],
        row_pm_map,
    ),
    "pm_skill": (
        ["id", "available_pokemons", "name", "description", "level_required",
         "power", "max_uses", "type", "element", "category"],
        row_pm_skill,
    ),
    "pm_mypm": (
        ["id", "pctime", "uid", "pmname", "nickname", "species_id", "level",
         "exp", "sex", "sx", "hp", "hpg", "atkg", "defg", "spatkg",
         "spdefg", "sdg", "good", "itemevolve", "ballid", "site", "state",
         "statetime", "gduptime", "hpn", "atkn", "defn", "spatkn", "spdefn",
         "sdn", "initialuid", "swap", "is_shiny", "equipmentid1",
         "equipmentid2", "equipmentid3", "equipmentid4", "created_at"],
        row_pm_mypm,
    ),
    "pm_usersdata": (
        ["uid", "npcid", "hpg", "hp", "atkg", "spatkg", "defg", "spdefg",
         "sdg", "allure", "capture", "level", "dataall", "datawin",
         "datalost", "fullexp", "boxnum", "strength", "str", "money"],
        row_pm_usersdata,
    ),
    "pm_myskill": (
        ["uid", "petid", "skillid", "skillnum"],
        row_pm_myskill,
    ),
    "pm_myitem": (
        ["id", "uid", "itemid", "nums"],
        row_pm_myitem,
    ),
    "pm_up": (
        ["id", "from_id", "to_id", "method", "condition_value", "priority"],
        row_pm_up,
    ),
}

# output table name for pm_up
RENAME = {"pm_up": "pm_evolution"}

# not migrated (dropped in X5)
SKIP = {"pm_sitemm"}

# The archived dump has no column lists, so another row shape cannot be inferred.
SOURCE_ARITY = {
    "pm_config": 3, "pm_data": 30, "pm_itemdata": 26, "pm_map": 8,
    "pm_skill": 10, "pm_mypm": 41, "pm_usersdata": 38, "pm_myskill": 4,
    "pm_myitem": 6, "pm_up": 6,
}


def main():
    if len(sys.argv) != 3:
        print("usage: x2_to_x5_export.py <input.sql> <output.sql>")
        return 1
    src, dst = sys.argv[1], sys.argv[2]
    dst = os.path.abspath(dst)
    if os.path.isdir(dst):
        raise SystemExit(f"output path is a directory: {dst}")

    # Do not normalize real CR/LF characters that occur inside SQL strings.
    with open(src, "r", encoding="utf-8-sig", newline="") as f:
        text = f.read()

    out = io.StringIO()
    out.write("-- ============================================================\n")
    out.write("-- TSDM Pokemon Plugin — X2 full data import (X5 canonical schema)\n")
    out.write("-- Generated by scripts/migrate/x2_to_x5_export.py\n")
    out.write("-- Source: X2 database backup (pm.sql)\n")
    out.write("-- Usage: import after install.php has created the tables\n")
    out.write("-- ============================================================\n\n")
    out.write("SET sql_mode = '';\n")
    out.write("SET NAMES utf8mb4;\n\n")

    stats = {}
    buffers = {t: [] for t in MAPPINGS}
    BATCH = 100

    for table, rows in parse_inserts(text):
        if table in SKIP:
            continue
        if table not in MAPPINGS:
            print(f"[warn] unhandled table {table} ({len(rows)} rows) — skipped")
            continue
        cols, fn = MAPPINGS[table]
        out_name = RENAME.get(table, table)
        for row_number, v in enumerate(rows, 1):
            if len(v) != SOURCE_ARITY[table]:
                raise ValueError(f"{table} row {row_number}: expected {SOURCE_ARITY[table]} values, got {len(v)}")
            try:
                new_row = fn(v)
            except (TypeError, ValueError) as error:
                raise ValueError(f"{table} row {row_number}: transformation failed") from error
            buffers[table].append(new_row)
            if len(buffers[table]) >= BATCH:
                flush(out, out_name, cols, buffers[table])
                stats[table] = stats.get(table, 0) + len(buffers[table])
                buffers[table] = []

    for table, rows in buffers.items():
        if rows:
            out_name = RENAME.get(table, table)
            flush(out, out_name, MAPPINGS[table][0], rows)
            stats[table] = stats.get(table, 0) + len(rows)

    if not stats:
        raise ValueError("no supported X2 data rows found")

    # Validate the entire dump before publishing any output. An interrupted write
    # must not leave a partial SQL file under the requested destination name.
    temporary = None
    try:
        with tempfile.NamedTemporaryFile(mode="w", encoding="utf-8", newline="\n",
                                         dir=os.path.dirname(dst), prefix=".x2-export-",
                                         suffix=".tmp", delete=False) as f:
            temporary = f.name
            f.write(out.getvalue())
        os.replace(temporary, dst)
    finally:
        if temporary is not None and os.path.exists(temporary):
            os.unlink(temporary)

    print("=== conversion complete ===")
    for t in sorted(stats):
        print(f"  {RENAME.get(t, t):16s} {stats[t]:>7d} rows")
    total = sum(stats.values())
    print(f"  {'TOTAL':16s} {total:>7d} rows")
    print(f"output: {dst}")


def flush(out, table, cols, rows):
    col_sql = ", ".join("`%s`" % c for c in cols)
    out.write(f"INSERT INTO `{table}` ({col_sql}) VALUES\n")
    parts = []
    for r in rows:
        parts.append("(" + ", ".join(esc(x) for x in r) + ")")
    out.write(",\n".join(parts))
    out.write(";\n")


if __name__ == "__main__":
    try:
        sys.exit(main())
    except (ValueError, OSError) as error:
        print(f"[error] {error}", file=sys.stderr)
        sys.exit(1)
