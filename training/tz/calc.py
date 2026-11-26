"""Wahrscheinlichkeitsrechner: exakt (ganze Zahlen) und als Tabelle für die Simulation.

P(Gebot wahr | eigene Hand), wenn die fremden Karten eine gleichverteilte Auswahl von U aus den
D ungesehenen Karten sind. Identisch zu tbot_prob() in core/TromboneBot.php.
"""

import os
from math import comb

import numpy as np

from .actions import DECK_JOKERS, DECK_SIZE

_CACHE = os.path.join(os.path.dirname(__file__), "..", "cache", "calc-v1.npz")
MAX_U = 42   # 7 andere × 6 Karten


def prob_exact(deck, own, U, ca, cb, j, na, nb):
    """(Zähler, Nenner) als ganze Zahlen.

    deck: "32" | "52"; own: eigene Kartenzahl; U: Karten in fremden Händen;
    ca, cb, j: eigene Karten der Ränge a, b und eigene Joker; na, nb: benötigte Anzahlen (nb = 0: ein Rang).
    """
    D = DECK_SIZE[deck] - own
    J = DECK_JOKERS[deck] - j
    ra = 4 - ca
    rb = 4 - cb if nb else 0
    rest = D - ra - rb - J
    num = 0
    for x in range(ra + 1):
        for y in range(rb + 1):
            for z in range(J + 1):
                k = U - x - y - z
                if k < 0 or k > rest:
                    continue
                missing = max(0, na - ca - x) + (max(0, nb - cb - y) if nb else 0)
                if missing <= j + z:
                    num += comb(ra, x) * comb(rb, y) * comb(J, z) * comb(rest, k)
    return num, comb(D, U)


def _build():
    # Index: [deck(2), own-1 (6), U (0..42), na (0..6), nb>0 (2), ca (0..4), cb (0..4), j (0..2)]
    shape = (2, 6, MAX_U + 1, 7, 2, 5, 5, 3)
    prob = np.zeros(shape, dtype=np.float64)
    state = np.zeros(shape, dtype=np.int8)      # 0 unmöglich, 1 offen, 2 sicher
    for di, deck in enumerate(("32", "52")):
        for own in range(1, 7):
            D = DECK_SIZE[deck] - own
            for U in range(0, min(D, MAX_U) + 1):
                for nbi, nb in enumerate((0, 2)):
                    for na in (range(1, 7) if nb == 0 else (2, 3)):
                        for ca in range(5):
                            for cb in (range(5) if nb else (0,)):
                                for j in range(DECK_JOKERS[deck] + 1):
                                    num, den = prob_exact(deck, own, U, ca, cb, j, na, nb)
                                    idx = (di, own - 1, U, na, nbi, ca, cb, j)
                                    prob[idx] = num / den
                                    state[idx] = 0 if num == 0 else (2 if num == den else 1)
    return prob, state


def tables():
    """(prob float64, state int8), aus dem Cache oder neu berechnet (dauert einige Sekunden)."""
    if os.path.exists(_CACHE):
        z = np.load(_CACHE)
        return z["prob"], z["state"]
    prob, state = _build()
    os.makedirs(os.path.dirname(_CACHE), exist_ok=True)
    np.savez_compressed(_CACHE, prob=prob, state=state)
    return prob, state
