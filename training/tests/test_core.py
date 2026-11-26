"""Tests für Rechner, Regeln, Merkmale und Simulation samt Abgleich mit PHP.

Aufruf (im Ordner training/):  python -m tests.test_core            (alles)
                               python -m tests.test_core calc sim   (einzelne Teile: calc, gae, sim, ref, php)
"""

import itertools
import json
import os
import random
import subprocess
import sys
import tempfile
from fractions import Fraction

import numpy as np

from tz import calc
from tz.actions import BIDS, BID_INDEX, DECK_JOKERS, DECK_MIN_RANK, NEED, bid_legal
from tz.features import features, mask
from tz.netfile import read_net
from tz.refgames import generate, load, random_hands, replay
from tz.rules import Game, bid_true
from tz.sim import Sim

ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "..")


def _deck(deck):
    return ["X"] * DECK_JOKERS[deck] + [f"{r}{s}" for r in range(DECK_MIN_RANK[deck], 15) for s in "SHDC"]


def test_calc():
    """Rechner gegen vollständige Aufzählung (kleine Fälle) und gegen Stichproben (große Fälle)."""
    rng = random.Random(1)
    n_enum = 0
    for deck in ("32", "52"):
        cards = _deck(deck)
        for _ in range(25):
            own = rng.randint(1, 3)
            U = rng.randint(0, 2)
            hand = rng.sample(cards, own)
            rest = list(cards)
            for c in hand:
                rest.remove(c)
            bids = rng.sample([b for b in BIDS if bid_legal(b, deck)], 12)
            g = Game(deck, [0, 1])
            g.players[0]["cards"], g.players[1]["cards"] = own, U
            g.hands = {0: hand, 1: []}
            from tz.features import bid_prob
            combos = list(itertools.combinations(rest, U))
            for bid in bids:
                if U == 0:
                    g.players = g.players[:1]
                hits = sum(bid_true(bid, hand + list(c)) for c in combos)
                num, den = bid_prob(g, 0, bid)
                assert Fraction(num, den) == Fraction(hits, len(combos)), (deck, hand, U, bid, num, den, hits)
                n_enum += 1
                if U == 0:
                    g.players = [{"seat": 0, "cards": own, "out": False}, {"seat": 1, "cards": U, "out": False}]
    # Stichproben: große Fälle
    n_mc = 0
    for _ in range(12):
        deck = rng.choice(["32", "52"])
        cards = _deck(deck)
        own = rng.randint(1, 6)
        U = rng.randint(5, 24 if deck == "32" else 42)
        hand = rng.sample(cards, own)
        rest = list(cards)
        for c in hand:
            rest.remove(c)
        g = Game(deck, [0, 1])
        g.players[0]["cards"], g.players[1]["cards"] = own, U
        g.hands = {0: hand, 1: []}
        from tz.features import bid_prob
        bids = rng.sample([b for b in BIDS if bid_legal(b, deck)], 6)
        N = 20000
        samples = [hand + rng.sample(rest, U) for _ in range(N)]
        for bid in bids:
            num, den = bid_prob(g, 0, bid)
            p = num / den
            hits = sum(bid_true(bid, s) for s in samples) / N
            sd = (p * (1 - p) / N) ** 0.5
            assert abs(hits - p) <= 5 * sd + 1e-9, (deck, hand, U, bid, p, hits)
            n_mc += 1
    # Tabelle = exakte Formel
    prob, state = calc.tables()
    for _ in range(3000):
        di = rng.randint(0, 1)
        own, U = rng.randint(1, 6), rng.randint(0, 27)
        nb = rng.choice([0, 2])
        na = rng.randint(1, 6) if nb == 0 else rng.choice([2, 3])
        ca, cb, j = rng.randint(0, 4), (rng.randint(0, 4) if nb else 0), rng.randint(0, di + 1)
        num, den = calc.prob_exact(("32", "52")[di], own, U, ca, cb, j, na, nb)
        idx = (di, own - 1, U, na, 1 if nb else 0, ca, cb, j)
        assert abs(prob[idx] - num / den) < 1e-15 and state[idx] == (0 if num == 0 else 2 if num == den else 1)
    print(f"calc: {n_enum} Fälle aufgezählt, {n_mc} per Stichprobe, Tabelle stimmt")


def _hands_from_sim(sim, b):
    hands = {}
    for s in range(int(sim.n[b])):
        h = ["X"] * int(sim.jok[b, s])
        for r in range(13):
            h += [f"{r + 2}{'SHDC'[i]}" for i in range(int(sim.cnt[b, s, r]))]
        hands[s] = h
    return hands


def test_sim(steps=400, B=48):
    """Vektorisierte Simulation Zug für Zug gegen die Referenz (rules.py, features.py)."""
    rng = np.random.default_rng(3)
    sim = Sim(B, rng, [1, 2, 1, 1, 1, 1, 1])
    games = []
    for b in range(B):
        g = Game(("32", "52")[int(sim.deck[b])], list(range(int(sim.n[b]))))
        g.deal(_hands_from_sim(sim, b))
        games.append(g)
    max_d, rounds, finished = 0.0, 0, 0
    for _ in range(steps):
        x, m = sim.observe()
        act = np.zeros(B, np.int64)
        for b, g in enumerate(games):
            assert g.turn == int(sim.turn[b])
            ref_m = np.array(mask(g, g.turn))
            assert (ref_m == m[b]).all(), "Maske weicht ab"
            d = float(np.abs(np.array(features(g, g.turn)) - x[b]).max())
            max_d = max(max_d, d)
            assert d < 2e-6, f"Merkmale weichen ab: {d}"
            cur = BID_INDEX[g.bids[-1][1]] if g.bids else 0
            if rng.random() < 0.8:
                pool = np.flatnonzero(m[b])
            else:
                pool = np.array([i for i, bid in enumerate(BIDS, 1) if i > cur and bid_legal(bid, g.deck)] + ([0] if cur else []))
            bids_only = pool[pool > 0]
            if 0 in pool and (len(bids_only) == 0 or rng.random() < 0.2):
                act[b] = 0
            else:
                act[b] = bids_only[min(len(bids_only) - 1, rng.integers(0, rng.integers(0, 30) + 1))]
        info = sim.step(act, auto=False)
        ended = np.flatnonzero(info["ended"])
        for b, g in enumerate(games):
            if act[b]:
                g.bid(g.turn, BIDS[act[b] - 1])
            else:
                res = g.challenge(g.turn)
                rounds += 1
                assert res["true"] == bool(info["true"][b]) and res["loser"] == int(info["loser"][b])
                assert (g.phase == "over") == bool(info["over"][b])
                for p in g.players:
                    assert p["cards"] == int(sim.cards[b, p["seat"]])
                    assert g.cred[p["seat"]] == sim.cred[b, p["seat"]].tolist(), "Zähler weichen ab"
                assert g.starter == int(sim.starter[b])
        over = np.flatnonzero(info["over"])
        sim.reset(over)
        sim.new_round(ended)
        for b in ended:
            if info["over"][b]:
                finished += 1
                games[b] = Game(("32", "52")[int(sim.deck[b])], list(range(int(sim.n[b]))))
            games[b].deal(_hands_from_sim(sim, b))
    print(f"sim: {steps * B} Züge, {rounds} Runden, {finished} Spiele, größte Abweichung der Merkmale {max_d:.1e}")


def test_ref():
    """Die eingecheckten Referenzspiele (tests/ref/) passen zur Python-Referenz."""
    net = read_net(os.path.join(ROOT, "tests", "ref", "test-net.bin"))
    turns = 0
    for rec in load(os.path.join(ROOT, "tests", "ref", "trombone-ref.jsonl")):
        turns += replay(rec, net)[0]
    print(f"ref: {turns} Züge der Referenzdatei nachgespielt")


def test_php(n_games=60):
    """PHP spielt Zufallsspiele (tests/bot-test.php gen); Python spielt sie nach und vergleicht alles."""
    net_path = os.path.join(ROOT, "tests", "ref", "test-net.bin")
    net = read_net(net_path)
    out = subprocess.run(["php", os.path.join(ROOT, "tests", "bot-test.php"), "gen", "42", str(n_games), net_path],
                         capture_output=True, text=True, check=True).stdout
    turns, max_x, max_p, sizes = 0, 0.0, 0.0, set()
    for line in out.splitlines():
        rec = json.loads(line)
        sizes.add((rec["deck"], len(rec["order"])))
        t, dx, dp = replay(rec, net)
        turns, max_x, max_p = turns + t, max(max_x, dx), max(max_p, dp)
    assert turns >= 1000, turns
    print(f"php: {n_games} Spiele aus PHP mit {turns} Zügen nachgespielt ({len(sizes)} Tischgrößen/Decks), "
          f"größte Abweichung: Merkmale {max_x:.1e}, Verteilung {max_p:.1e}")


def test_gae():
    """Vorteile an einem Spiel von Hand: zwei Plätze, zwei Runden, die zweite beendet das Spiel."""
    from tz.arena import Arena
    from tz.config import DEFAULTS
    cfg = {**DEFAULTS, "gamma_round": 0.9, "gae_lambda": 1.0, "game_penalty": 2.0}
    arena = Arena(cfg, 1, n_games=1)
    val = np.array([[0.1], [0.2], [0.3], [0.4], [0.5]], np.float32)
    lrn = np.ones((5, 1), bool)
    actor = np.array([[0], [1], [0], [0], [1]])
    ended = np.array([[0], [0], [1], [0], [1]], bool)       # Zug 2 und Zug 4 sind Zweifel
    over = np.array([[0], [0], [0], [0], [1]], bool)
    loser = np.array([[-1], [-1], [1], [-1], [0]])
    adv, valid = arena._gae(val, lrn, actor, ended, over, loser)
    ret = (adv + val)[:, 0]
    want = [0.9 * -3.0, -1.0, 0.9 * -3.0, -3.0, 0.0]
    assert np.allclose(ret, want, atol=1e-6), ret
    assert valid.all()
    # ohne Spielende am Schluss haben die letzten Züge beider Plätze keine Fortsetzung
    over[4] = False
    _, valid = arena._gae(val, lrn, actor, ended, over, loser)
    assert valid[:, 0].tolist() == [True, True, True, False, False]
    print("gae: Vorteile stimmen mit der Rechnung von Hand überein")


if __name__ == "__main__":
    which = sys.argv[1:] or ["calc", "gae", "sim", "ref", "php"]
    for name in which:
        globals()[f"test_{name}"]()
    print("OK")
