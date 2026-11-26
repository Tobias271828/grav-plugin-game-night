"""Messungen: Verlustquote gegen Rechner-Bots, Netz gegen Netz, Nutzen der Zähler, Statistik des Self-Play."""

import numpy as np
import torch

from . import bots
from .rules import CRED_POS
from .arena import Arena
from .config import DEFAULTS
from .model import make_net


def load_net(path, ema=False):
    """Netz aus einem Zwischenstand (.pt) laden. Rückgabe: Net (mit .cfg). ema: das gleitende Mittel der Gewichte."""
    ck = torch.load(path, weights_only=False)
    cfg = {**DEFAULTS, **ck.get("cfg", {})}
    cfg.setdefault("priv_critic", False)
    if "priv_critic" not in ck.get("cfg", {}):     # Stände von vor diesen Schaltern
        cfg["priv_critic"], cfg["aux_coef"] = False, 0.0
    net = make_net(cfg)
    net.load_state_dict(ck["ema"] if ema and ck.get("ema") else ck["net"])
    net.cfg = cfg
    net.steps = ck.get("steps", 0)
    return net


def _one_hot_n(n):
    p = [0.0] * 7
    p[n - 2] = 1.0
    return p


def _table(cfg, n, assigner, seed, games=512):
    return Arena(cfg, seed, n_games=games, assigner=assigner, n_probs=_one_hot_n(n))


def _run(arena, net, pool, rounds):
    """Rollouts ohne Lernen, bis genug Runden gespielt sind. Rückgabe: Zähler."""
    total = None
    while total is None or total["rounds"].sum() < rounds:
        _, st = arena.rollout(net, pool, 64, collect=False)
        total = st if total is None else {k: total[k] + st[k] for k in st}
    return total


def _summary(st, n):
    rounds, lost, fair = st["rounds"][n], st["lost"][n], st["fair"][n]
    rate = lost / rounds
    share = fair / rounds                                   # fairer Anteil (bei einem Platz: 1/n)
    games = max(st["games"][n], 1)
    return {
        "n": n, "rounds": int(rounds), "rate": rate, "fair": share, "ratio": rate / share,
        "ci": 1.96 * (rate * (1 - rate) / rounds) ** 0.5 / share,
        "game_ratio": (st["glost"][n] / games) / (st["gfair"][n] / games) if st["gfair"][n] else float("nan"),
        "games": int(st["games"][n]),
    }


def vs_calc(net, cfg, rounds=20000, seed=0, style="standard", ns=range(3, 9), focus_style=None, blind=False):
    """Ein Platz (Netz, oder mit focus_style ein Rechner-Bot) gegen n-1 Rechner-Bots eines Stils.

    Rückgabe: {"rows": [je n: rate, fair = 1/n, ratio = rate·n, ci, game_ratio], "mean_ratio": Mittel über n}.
    ratio < 1 heißt: verliert seltener als der faire Anteil. Ziel der Abnahme: ≤ 0,8 für jedes n von 3 bis 8.
    """
    table = np.array(bots.STYLES[style], np.float32)
    focus = np.array(bots.STYLES[focus_style], np.float32) if focus_style else table

    def assigner(arena, idx):
        m = len(idx)
        kind = np.full((m, 8), -1, np.int64)
        slot = (arena.rng.random(m) * arena.sim.n[idx]).astype(np.int64)
        kind[np.arange(m), slot] = 0
        arena.kind[idx] = kind
        params = np.broadcast_to(table, (m, 8, bots.N_PARAMS)).copy()
        params[np.arange(m), slot] = focus
        arena.params[idx] = params

    rows = []
    for n in ns:
        arena = _table(cfg, n, assigner, seed * 100 + n)
        arena.blind_cred = blind
        rows.append(_summary(_run(arena, None if focus_style else net, [], rounds / len(list(ns))), n))
    return {"rows": rows, "mean_ratio": float(np.mean([r["ratio"] for r in rows])),
            "max_ratio": float(np.max([r["ratio"] for r in rows]))}


def duel(net_a, net_b, cfg, rounds=20000, seed=0, ns=range(3, 9), seats_a=None):
    """Netz A gegen Netz B am selben Tisch. seats_a: Plätze von A (Vorgabe: die Hälfte, abgerundet, mind. 1)."""
    def assigner(arena, idx):
        m = len(idx)
        n = arena.sim.n[idx]
        k = np.full(m, seats_a) if seats_a else np.maximum(1, n // 2)
        order = np.argsort(arena.rng.random((m, 8)) + (np.arange(8)[None, :] >= n[:, None]) * 2, axis=1)
        rank = np.argsort(order, axis=1)
        arena.kind[idx] = np.where(rank < k[:, None], 0, 1)

    rows = []
    for n in ns:
        arena = _table(cfg, n, assigner, seed * 100 + n)
        rows.append(_summary(_run(arena, net_a, [net_b], rounds / len(list(ns))), n))
    return {"rows": rows, "mean_ratio": float(np.mean([r["ratio"] for r in rows]))}


def selfplay_stats(net, cfg, rounds=20000, seed=0):
    """Statistik des Self-Play (alle Plätze dasselbe Netz) – daraus kommen die Vorwerte der Glaubwürdigkeit."""
    def assigner(arena, idx):
        arena.kind[idx] = 0

    arena = Arena(cfg, seed, n_games=512, assigner=assigner)
    st = _run(arena, net, [], rounds)
    t = arena.sim.totals.astype(float)
    turns, chal, won, hit, hit_true = t[2 * CRED_POS:]
    bids_n = t[0:2 * CRED_POS:2].sum()
    return {
        "cred_mu": [t[2 * p + 1] / 60 / max(1.0, t[2 * p]) for p in range(CRED_POS)] + [chal / turns, won / chal, hit_true / hit],
        "bids_by_pos": [t[2 * p] / bids_n for p in range(CRED_POS)],
        "bids_per_round": bids_n / chal, "rounds_per_game": float(st["rounds"].sum() / max(1, st["games"].sum())),
    }


def load_spec(spec):
    """Netz aus einer Angabe „datei.pt“, „datei.pt@ema“ (gemittelte Gewichte) oder „datei.pt@T0.8“
    (Temperatur beim Spielen); beides kombinierbar: „datei.pt@ema@T0.8“."""
    path, *opts = spec.split("@")
    net = load_net(path, ema="ema" in opts)
    for o in opts:
        if o.startswith("T"):
            net.temperature = float(o[1:])
    return net


def _league_calc(args):
    spec, style, rounds, seed = args
    torch.set_num_threads(1)
    net = load_spec(spec)
    return vs_calc(net, net.cfg, rounds=rounds, seed=seed, style=style)["mean_ratio"]


def _league_duel(args):
    a, b, rounds, seed = args
    torch.set_num_threads(1)
    na = load_spec(a)
    return duel(na, load_spec(b), na.cfg, rounds=rounds, seed=seed)["mean_ratio"]


def league(specs, rounds=30000, seed=5, jobs=4, anchors=()):
    """Vergleich mehrerer Netze: jedes gegen Rechner-Bots aller Stile und jedes gegen jedes am halben Tisch.

    anchors: weitere Netze, gegen die alle antreten, die aber selbst nicht gewertet werden.
    Rückgabe je Netz: calc (Mittel über alle Stile), worst (schlechtester Stil), styles, duel (Mittel über
    alle Gegner), duels {Gegner: Verhältnis}, score = Mittel aus calc und duel. Kleiner ist besser.
    """
    from concurrent.futures import ProcessPoolExecutor
    import multiprocessing as mp
    styles = bots.STYLE_NAMES
    calc_jobs = [(s, st, rounds, seed) for s in specs for st in styles]
    duel_jobs = [(a, b, max(6000, rounds // 2), seed + 1) for a in specs for b in [*specs, *anchors] if a != b]
    with ProcessPoolExecutor(jobs, mp_context=mp.get_context("spawn")) as ex:
        calc = list(ex.map(_league_calc, calc_jobs))
        duels = list(ex.map(_league_duel, duel_jobs))
    out = {}
    for s in specs:
        by_style = {st: v for (a, st, _, _), v in zip(calc_jobs, calc) if a == s}
        d = {b: v for (a, b, _, _), v in zip(duel_jobs, duels) if a == s}
        c = float(np.mean(list(by_style.values())))
        dm = float(np.mean(list(d.values()))) if d else 1.0
        out[s] = {"calc": c, "worst": max(by_style.values()), "styles": by_style, "duel": dm, "duels": d,
                  "score": 0.5 * c + 0.5 * dm}
    return out


def print_league(res):
    names = list(res)
    short = [f"N{i + 1}" for i in range(len(names))]
    for k, n in zip(short, names):
        print(f"  {k} = {n}")
    print("\n  Netz  Wertung   Stile  schlechtester Stil       Netze   " + "  ".join(f"{k:>5}" for k in short) + "  (Anker)")
    for k, n in sorted(zip(short, names), key=lambda kn: res[kn[1]]["score"]):
        r = res[n]
        worst = max(r["styles"], key=r["styles"].get)
        cells = "  ".join(f"{r['duels'][m]:5.3f}" if m in r["duels"] else "    –" for m in names)
        anchors = "  ".join(f"{v:.3f}" for m, v in r["duels"].items() if m not in names)
        print(f"  {k:4}  {r['score']:.3f}    {r['calc']:.3f}  {r['worst']:.3f} ({worst:10}) {r['duel']:8.3f}   {cells}  {anchors}")
    print("  Stile: Verhältnis gegen n-1 Rechner-Bots, Mittel über alle Stile. Netze: Verhältnis am halben Tisch gegen "
          "jedes andere Netz\n  (Zeile gegen Spalte). Unter 1 = verliert seltener als fair; Wertung = Mittel aus beidem.")


def print_table(title, res):
    print(title)
    print("   n   Runden  Verlustquote   fair   Verhältnis (±95 %)   Spielverlust-Verh.")
    for r in res["rows"]:
        print(f"  {r['n']:2d}  {r['rounds']:7d}    {r['rate']:6.3f}     {r['fair']:5.3f}    {r['ratio']:5.3f} ± {r['ci']:.3f}"
              f"         {r['game_ratio']:5.3f}")
    print(f"  Mittel des Verhältnisses: {res['mean_ratio']:.3f}   (1 = fair, Ziel gegen Rechner-Bots ≤ 0,8)")
