"""Referenzspiele im JSONL-Format von tests/bot-test.php erzeugen und nachspielen (Abgleich PHP ↔ Python)."""

import json
import random

import numpy as np

from .actions import BIDS, DECK_JOKERS, DECK_MIN_RANK, bid_legal, BID_INDEX
from .features import N_FEATURES, features, mask
from .netfile import policy
from .rules import CRED_ZERO, Game


def random_hands(game, rng):
    cards = ["X"] * DECK_JOKERS[game.deck]
    cards += [f"{r}{s}" for r in range(DECK_MIN_RANK[game.deck], 15) for s in "SHDC"]
    rng.shuffle(cards)
    hands = {}
    for p in game.active():
        hands[p["seat"]] = [cards.pop() for _ in range(p["cards"])]
    return hands


def _sparse(x):
    return [[i, float(v)] for i, v in enumerate(x) if v != 0]


def generate(seed, n_games, net=None, x_every=1, p_every=1, max_turns=None):
    """Zufallsspiele mit der Python-Referenz spielen; gibt die Spiele als Liste von dicts zurück."""
    rng = random.Random(seed)
    games, turns = [], 0
    mu, k = (net["mu"], net["k"]) if net else (None, None)
    kw = {"mu": mu, "k": k} if net else {}
    for _ in range(n_games):
        n = rng.randint(2, 8)
        deck = "32" if n == 2 else ("52" if n >= 4 else rng.choice(["32", "52"]))
        order = rng.sample(range(8), n)
        game = Game(deck, order)
        rec = {"deck": deck, "order": order, "rounds": []}
        while game.phase != "over":
            hands = random_hands(game, rng)
            game.deal(hands)
            rnd = {"hands": {str(s): h for s, h in hands.items()}, "turns": []}
            while game.phase == "bidding":
                seat = game.turn
                m = mask(game, seat)
                turn = {"seat": seat, "mask": "".join("1" if v else "0" for v in m)}
                x = features(game, seat, **kw)
                if turns % x_every == 0:
                    turn["x"] = _sparse(x)
                if net and turns % p_every == 0:
                    p = policy(net["layers"], np.array(x), np.array(m), dtype=np.float32)
                    turn["p"] = [[i, round(float(v), 6)] for i, v in enumerate(p) if v >= 1e-6]
                cur = BID_INDEX[game.bids[-1][1]] if game.bids else 0
                if rng.random() < 0.8:
                    pool = [i for i, v in enumerate(m) if v]
                else:
                    pool = [i for i, b in enumerate(BIDS, 1) if i > cur and bid_legal(b, deck)] + ([0] if cur else [])
                bids_only = [i for i in pool if i]
                if 0 in pool and (not bids_only or rng.random() < 0.22):
                    act = 0
                else:
                    act = bids_only[min(len(bids_only) - 1, rng.randint(0, rng.randint(0, 25)))]
                turn["act"] = act
                rnd["turns"].append(turn)
                turns += 1
                if act:
                    game.bid(seat, BIDS[act - 1])
                else:
                    game.challenge(seat)
            rnd.update({"true": game.last["true"], "loser": game.last["loser"], "over": game.phase == "over",
                        "cards": {str(p["seat"]): p["cards"] for p in game.players},
                        "cred": {str(s): list(c) for s, c in game.cred.items()}})
            rec["rounds"].append(rnd)
        games.append(rec)
        if max_turns and turns >= max_turns:
            break
    return games


def replay(rec, net=None, tol_x=1e-9, tol_p=1e-4):
    """Ein Referenzspiel nachspielen und alles vergleichen. Gibt (Züge, max. Abw. Merkmale, max. Abw. p) zurück."""
    game = Game(rec["deck"], rec["order"])
    kw = {"mu": net["mu"], "k": net["k"]} if net else {}
    turns, max_x, max_p = 0, 0.0, 0.0
    for rnd in rec["rounds"]:
        game.deal({int(s): h for s, h in rnd["hands"].items()})
        for turn in rnd["turns"]:
            seat = turn["seat"]
            assert game.phase == "bidding" and game.turn == seat, "falscher Platz am Zug"
            m = mask(game, seat)
            assert "".join("1" if v else "0" for v in m) == turn["mask"], "Maske weicht ab"
            x = features(game, seat, **kw)
            if "x" in turn:
                want = [0.0] * N_FEATURES
                for i, v in turn["x"]:
                    want[i] = v
                d = max(abs(a - b) for a, b in zip(x, want))
                max_x = max(max_x, d)
                assert d <= tol_x, f"Merkmale weichen ab: {d}"
            if "p" in turn and net:
                p = policy(net["layers"], np.array(x), np.array(m), dtype=np.float32)
                want = np.zeros(len(p))
                for i, v in turn["p"]:
                    want[i] = v
                d = float(np.abs(p - want).max())
                max_p = max(max_p, d)
                assert d <= tol_p, f"Verteilung weicht ab: {d}"
            if turn["act"]:
                game.bid(seat, BIDS[turn["act"] - 1])
            else:
                game.challenge(seat)
            turns += 1
        assert game.last["true"] == rnd["true"] and game.last["loser"] == rnd["loser"], "Auflösung weicht ab"
        assert (game.phase == "over") == rnd["over"], "Spielende weicht ab"
        for s, n in rnd["cards"].items():
            assert game.player(int(s))["cards"] == n, "Kartenzahl weicht ab"
        cred = rnd["cred"]
        cred = {str(i): c for i, c in enumerate(cred)} if isinstance(cred, list) else cred
        for s, c in cred.items():
            assert game.cred.get(int(s), list(CRED_ZERO)) == c, f"Zähler weichen ab: {game.cred.get(int(s))} / {c}"
    return turns, max_x, max_p


def load(path):
    with open(path, encoding="utf-8") as fh:
        return [json.loads(line) for line in fh if line.strip()]
