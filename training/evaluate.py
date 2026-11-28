"""Messungen für ein trainiertes Netz (Zwischenstand .pt).

  python evaluate.py calc NETZ [--style standard|alle] [--rounds N]   Verlustquote gegen Rechner-Bots (Abnahme)
  python evaluate.py duel NETZ_A NETZ_B                              zwei Netze am selben Tisch
  python evaluate.py counters NETZ                                   Nutzen der Glaubwürdigkeitszähler
  python evaluate.py stats NETZ                                      Self-Play-Statistik (Vorwerte der Zähler)
  python evaluate.py bots                                            Rechner-Bots untereinander
  python evaluate.py report NETZ [--out bericht.json]                alles zusammen als Bericht
  python evaluate.py league NETZ1 NETZ2 … [--anchor NETZ]            mehrere Netze: alle Stile und jedes gegen jedes

NETZ ist eine Datei net_xxxxx.pt, wahlweise mit „@ema“ (gemittelte Gewichte) und/oder „@T0.8“ (Temperatur
beim Spielen), z. B. runs/lauf/net_01500.pt@ema.

Verhältnis = Verlustquote geteilt durch den fairen Anteil (1/n bei einem Platz). Kleiner ist besser.
"""

import argparse
import json
import os

import torch

from tz import bots, evaluate as ev
from tz.config import DEFAULTS


def cmd_calc(args):
    net = ev.load_spec(args.net)
    styles = bots.STYLE_NAMES if args.style == "alle" else [args.style]
    out = {}
    for style in styles:
        res = ev.vs_calc(net, net.cfg, rounds=args.rounds, seed=args.seed, style=style, ns=range(2, 9))
        ev.print_table(f"\nNetz gegen n-1 Rechner-Bots im Stil „{style}“", res)
        out[style] = res
    return out


def cmd_duel(args):
    a, b = ev.load_spec(args.net), ev.load_spec(args.other)
    res = ev.duel(a, b, a.cfg, rounds=args.rounds, seed=args.seed)
    ev.print_table(f"\nA = {args.net} (halber Tisch) gegen B = {args.other}", res)
    res1 = ev.duel(a, b, a.cfg, rounds=args.rounds, seed=args.seed + 1, seats_a=1)
    ev.print_table("\nA auf einem Platz, sonst nur B", res1)
    return {"half": res, "single": res1}


def cmd_counters(args):
    net = ev.load_spec(args.net)
    out = {}
    for style in ("wild", "ehrlich"):
        real = ev.vs_calc(net, net.cfg, rounds=args.rounds, seed=args.seed, style=style)
        blind = ev.vs_calc(net, net.cfg, rounds=args.rounds, seed=args.seed, style=style, blind=True)
        ev.print_table(f"\nGegen „{style}“ mit echten Zählern", real)
        ev.print_table(f"Gegen „{style}“ mit Zählern auf dem Vorwert", blind)
        print(f"  Nutzen der Zähler gegen „{style}“: {blind['mean_ratio'] - real['mean_ratio']:+.3f} "
              "(positiv = die Zähler helfen)")
        out[style] = {"real": real, "blind": blind}
    return out


def cmd_stats(args):
    net = ev.load_spec(args.net)
    st = ev.selfplay_stats(net, net.cfg, rounds=args.rounds, seed=args.seed)
    m = st["cred_mu"]
    print(f"mittlere Deckung des 1. / 2. / späteren Gebots einer Runde: {m[0]:.3f} / {m[1]:.3f} / {m[2]:.3f} "
          f"(Anteile der Gebote: {' / '.join(f'{v:.2f}' for v in st['bids_by_pos'])})")
    print(f"Zweifelquote {m[3]:.3f}, Trefferquote beim Anzweifeln {m[4]:.3f}, angezweifelte Gebote wahr {m[5]:.3f}")
    print(f"Gebote pro Runde {st['bids_per_round']:.2f}, Runden pro Spiel {st['rounds_per_game']:.1f}")
    print("Als Vorwerte für einen neuen Lauf: --set cred_mu=" + json.dumps([round(v, 3) for v in st["cred_mu"]]).replace(" ", ""))
    return st


def cmd_bots(args):
    cfg = dict(DEFAULTS)
    print("Ein Rechner-Bot (Zeile) gegen n-1 Bots eines Stils (Spalte): mittleres Verhältnis über n = 3..8")
    print(" " * 10 + "".join(f"{s:>10}" for s in bots.STYLE_NAMES))
    out = {}
    for focus in bots.STYLE_NAMES:
        row = []
        for style in bots.STYLE_NAMES:
            res = ev.vs_calc(None, cfg, rounds=args.rounds, seed=args.seed, style=style, focus_style=focus)
            row.append(res["mean_ratio"])
        out[focus] = row
        print(f"{focus:>10}" + "".join(f"{v:10.3f}" for v in row))
    return out


def cmd_report(args):
    args.style = "alle"
    out = {"net": args.net, "calc": cmd_calc(args), "counters": cmd_counters(args), "stats": cmd_stats(args)}
    std = out["calc"]["standard"]["rows"]
    ok = all(r["ratio"] <= 0.8 for r in std if r["n"] >= 3)
    print("\nAbnahme (Verhältnis ≤ 0,8 gegen „standard“ für n = 3..8): " + ("erfüllt" if ok else "NICHT erfüllt"))
    out["accepted"] = ok
    return out


def cmd_league(args):
    res = ev.league([args.net, *args.other], rounds=args.rounds, seed=args.seed, jobs=args.jobs, anchors=args.anchor)
    ev.print_league(res)
    return res


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("cmd", choices=["calc", "duel", "counters", "stats", "bots", "report", "league"])
    ap.add_argument("net", nargs="?")
    ap.add_argument("other", nargs="*")
    ap.add_argument("--anchor", action="append", default=[], help="league: Netz, gegen das alle antreten, ohne selbst gewertet zu werden")
    ap.add_argument("--jobs", type=int, default=max(1, (os.cpu_count() or 2) // 2), help="league: Prozesse")
    ap.add_argument("--style", default="standard")
    ap.add_argument("--rounds", type=int, default=60000)
    ap.add_argument("--seed", type=int, default=1)
    ap.add_argument("--out", help="Ergebnis zusätzlich als JSON speichern")
    args = ap.parse_args()
    if args.cmd != "bots" and not args.net:
        ap.error("NETZ fehlt")
    if args.cmd != "league":
        args.other = args.other[0] if args.other else None
    torch.set_num_threads(2)
    res = globals()[f"cmd_{args.cmd}"](args)
    if args.out:
        with open(args.out, "w") as fh:
            json.dump(res, fh, indent=1)


if __name__ == "__main__":
    main()
