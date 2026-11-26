"""Aktionsraum von Trombone: Index 0 = Anzweifeln, 1..312 = Gebote aufsteigend nach tb_cmp.

Muss mit tbot_bids() in core/TromboneBot.php übereinstimmen.
"""

import numpy as np

RANKS = list(range(2, 15))
# benötigte Karten je Kategorie: (n_a, n_b)
NEED = [(1, 0), (2, 0), (2, 2), (3, 0), (3, 2), (4, 0), (5, 0), (6, 0)]
N_ACTIONS = 313
CHALLENGE = 0
MAX_CARDS = 6
MAX_PLAYERS = 8
DECK_SIZE = {"32": 33, "52": 54}       # inklusive Joker
DECK_JOKERS = {"32": 1, "52": 2}
DECK_MIN_RANK = {"32": 7, "52": 2}


def _all_bids():
    bids = []
    for t in range(8):
        for a in RANKS:
            if t == 2:
                bids += [(t, a, b) for b in RANKS if b < a]
            elif t == 4:
                bids += [(t, a, b) for b in RANKS if b != a]
            else:
                bids.append((t, a, 0))
    bids.sort()
    return bids


BIDS = _all_bids()                     # Index i ↔ Aktion i + 1
assert len(BIDS) == 312
BID_INDEX = {bid: i + 1 for i, bid in enumerate(BIDS)}


def bid_legal(bid, deck):
    """Gibt es dieses Gebot mit diesem Deck (wie tb_valid_bid)?"""
    t, a, b = bid
    lo = DECK_MIN_RANK[deck]
    if a < lo or (b and b < lo):
        return False
    return not (t == 7 and deck == "32")


# Tabellen für die vektorisierte Simulation (Index = Aktion; Zeile 0 = Anzweifeln)
ACT_T = np.array([0] + [b[0] for b in BIDS], dtype=np.int64)
ACT_A = np.array([0] + [b[1] - 2 for b in BIDS], dtype=np.int64)          # Rangindex 0..12
ACT_B = np.array([0] + [max(b[2] - 2, 0) for b in BIDS], dtype=np.int64)
ACT_NA = np.array([0] + [NEED[b[0]][0] for b in BIDS], dtype=np.int64)
ACT_NB = np.array([0] + [NEED[b[0]][1] for b in BIDS], dtype=np.int64)
# DECK_LEGAL[d, Aktion]: d = 0 für 32 Karten, 1 für 52 Karten (Anzweifeln hier immer True)
DECK_LEGAL = np.array(
    [[True] + [bid_legal(b, d) for b in BIDS] for d in ("32", "52")], dtype=bool
)
assert DECK_LEGAL[0].sum() == 125 and DECK_LEGAL[1].sum() == 313


def bid_to_json(bid):
    t, a, b = bid
    return {"t": t, "a": a, "b": b or None}


def bid_from_json(j):
    return (j["t"], j["a"], j.get("b") or 0)
