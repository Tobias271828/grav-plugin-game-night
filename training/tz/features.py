"""Merkmale (Netzeingaben) und Aktionsmaske – Referenz in reinem Python für einen einzelnen Zustand.

Muss mit tbot_features() und tbot_mask() in core/TromboneBot.php übereinstimmen (Merkmalsversion
FEATURE_VERSION). Die schnelle Simulation (sim.py) berechnet dasselbe vektorisiert.

Aufbau (579 Werte, alle in 0..1):
  0   Tisch (10):        Deck 52 (1), Personenzahl 2..8 One-Hot (7), Karten im Spiel / 48, Gebote / 20
  10  eigene Hand (20):  Kartenzahl 1..6 One-Hot (6), Anzahl je Rang / 4 (13), Joker / 2
  30  eigene Ansagen (13): je Rang höchste selbst angesagte Anzahl / 6
  43  aktuelles Gebot (35): vorhanden, Kategorie One-Hot (8), Rang a One-Hot (13), Rang b One-Hot (13)
  78  Rechner (79):      P(aktuelles Gebot), je Rang P(mindestens k), k = 1..6 (Rang zuerst)
  157 Rechner mit Ansagen (79): dasselbe, wenn die Ansagen der anderen als echte Karten zählen
                         (je Rang die höchste von anderen angesagte Anzahl, zur eigenen Hand addiert,
                         höchstens 4). Beim aktuellen Gebot zählt dieses Gebot selbst nicht mit – der Wert
                         sagt, wie gut es zu allen früheren Ansagen passt.
  236 Mitspieler (7 × 49): vorhanden, Karten / 6, hat das letzte Gebot, Gebote / 8,
                         je Rang höchste angesagte Anzahl / 6 (13), dasselbe nur für das erste Gebot der
                         Runde (13) und nur für das letzte (13), Glaubwürdigkeit (6)

Glaubwürdigkeit (geglättet mit Vorwert μ und Gewicht k): mittlere Deckung des ersten, des zweiten und
späterer Gebote einer Runde durch die eigene Hand (3), Zweifelquote, Trefferquote beim Anzweifeln,
Anteil wahrer Gebote, wenn die Person angezweifelt wurde.
"""

from .actions import BIDS, BID_INDEX, DECK_JOKERS, DECK_MIN_RANK, N_ACTIONS, NEED, bid_legal
from .calc import prob_exact
from .rules import CRED_CHAL, CRED_HIT, CRED_HIT_TRUE, CRED_POS, CRED_TURNS, CRED_WON, CRED_ZERO, hand_counts

FEATURE_VERSION = 2
N_FEATURES = 579
OFF_TABLE, OFF_HAND, OFF_OWN_CLAIMS, OFF_BID, OFF_CALC, OFF_TRUST, OFF_OPP = 0, 10, 30, 43, 78, 157, 236
OPP_BLOCK = 49
OPP_CRED = 43            # Beginn der Glaubwürdigkeit im Mitspielerblock
N_CRED = CRED_POS + 3    # Zahl der Glaubwürdigkeitswerte = Zahl der Vorwerte
# Vorwerte der Glaubwürdigkeit: Deckung des 1., 2., späteren Gebots; Zweifelquote; Trefferquote; Anteil wahrer
# angezweifelter Gebote – und das Gewicht k. Stehen im Kopf der Gewichtsdatei; PHP liest sie von dort.
CRED_MU = (0.40, 0.38, 0.36, 0.20, 0.55, 0.45)
CRED_K = 5.0


def cred_features(c, mu=CRED_MU, k=CRED_K):
    out = [(c[2 * p + 1] / 60 + k * mu[p]) / (c[2 * p] + k) for p in range(CRED_POS)]
    out.append((c[CRED_CHAL] + k * mu[CRED_POS]) / (c[CRED_TURNS] + k))
    out.append((c[CRED_WON] + k * mu[CRED_POS + 1]) / (c[CRED_CHAL] + k))
    out.append((c[CRED_HIT_TRUE] + k * mu[CRED_POS + 2]) / (c[CRED_HIT] + k))
    return out


def _bid_claims(bid):
    out = [0] * 13
    t, a, b = bid
    na, nb = NEED[t]
    out[a - 2] = na
    if nb:
        out[b - 2] = nb
    return out


def _claims(bids, seat):
    """Je Rangindex die höchste von seat in dieser Runde angesagte Anzahl."""
    out = [0] * 13
    for s, bid in bids:
        if s == seat:
            out = [max(x, y) for x, y in zip(out, _bid_claims(bid))]
    return out


def _others_claims(bids, seat):
    """Je Rangindex die höchste Anzahl, die irgendjemand außer seat in dieser Runde angesagt hat."""
    out = [0] * 13
    for s, bid in bids:
        if s != seat:
            out = [max(x, y) for x, y in zip(out, _bid_claims(bid))]
    return out


def _view(game, seat):
    """Was der Rechner braucht: eigene Zählung, U und die Mitspielenden in Zugreihenfolge nach seat."""
    act = game.active()
    i = next(k for k, p in enumerate(act) if p["seat"] == seat)
    others = act[i + 1:] + act[:i]
    count, jokers = hand_counts(game.hands[seat])
    own = len(game.hands[seat])
    U = sum(p["cards"] for p in others)
    return act, others, count, jokers, own, U


def bid_prob(game, seat, bid):
    """(Zähler, Nenner) für ein Gebot aus Sicht von seat."""
    _, _, count, jokers, own, U = _view(game, seat)
    t, a, b = bid
    na, nb = NEED[t]
    return prob_exact(game.deck, own, U, count.get(a, 0), count.get(b, 0) if nb else 0, jokers, na, nb)


def features(game, seat, mu=CRED_MU, k=CRED_K):
    act, others, count, jokers, own, U = _view(game, seat)
    x = [0.0] * N_FEATURES
    lo = DECK_MIN_RANK[game.deck]

    # Tisch
    x[0] = 1.0 if game.deck == "52" else 0.0
    x[1 + len(act) - 2] = 1.0
    x[8] = (own + U) / 48
    x[9] = min(len(game.bids), 20) / 20

    # eigene Hand
    x[OFF_HAND + own - 1] = 1.0
    for r in range(2, 15):
        x[OFF_HAND + 6 + r - 2] = count.get(r, 0) / 4
    x[OFF_HAND + 19] = jokers / 2

    # eigene Ansagen
    for i, n in enumerate(_claims(game.bids, seat)):
        x[OFF_OWN_CLAIMS + i] = n / 6

    # aktuelles Gebot
    if game.bids:
        t, a, b = game.bids[-1][1]
        x[OFF_BID] = 1.0
        x[OFF_BID + 1 + t] = 1.0
        x[OFF_BID + 9 + a - 2] = 1.0
        if b:
            x[OFF_BID + 22 + b - 2] = 1.0
        num, den = bid_prob(game, seat, (t, a, b))
        x[OFF_CALC] = num / den

    # Rechner: je Rang mindestens k Karten
    for r in range(lo, 15):
        for kk in range(1, 7):
            num, den = prob_exact(game.deck, own, U, count.get(r, 0), 0, jokers, kk, 0)
            x[OFF_CALC + 1 + (r - 2) * 6 + kk - 1] = num / den

    # Rechner mit Ansagen: die Ansagen der anderen zählen als Karten (höchstens 4 je Rang)
    cl = _others_claims(game.bids, seat)
    if game.bids:
        t, a, b = game.bids[-1][1]
        na, nb = NEED[t]
        before = _others_claims(game.bids[:-1], seat)      # ohne das aktuelle Gebot selbst
        ca = min(4, count.get(a, 0) + before[a - 2])
        cb = min(4, count.get(b, 0) + before[b - 2]) if nb else 0
        num, den = prob_exact(game.deck, own, U, ca, cb, jokers, na, nb)
        x[OFF_TRUST] = num / den
    for r in range(lo, 15):
        c = min(4, count.get(r, 0) + cl[r - 2])
        for kk in range(1, 7):
            num, den = prob_exact(game.deck, own, U, c, 0, jokers, kk, 0)
            x[OFF_TRUST + 1 + (r - 2) * 6 + kk - 1] = num / den

    # Mitspielende in Zugreihenfolge nach seat
    last_seat = game.bids[-1][0] if game.bids else None
    for i, p in enumerate(others[:7]):
        o = OFF_OPP + i * OPP_BLOCK
        mine = [bid for s, bid in game.bids if s == p["seat"]]
        x[o] = 1.0
        x[o + 1] = p["cards"] / 6
        x[o + 2] = 1.0 if p["seat"] == last_seat else 0.0
        x[o + 3] = min(len(mine), 8) / 8
        for j, n in enumerate(_claims(game.bids, p["seat"])):
            x[o + 4 + j] = n / 6
        if mine:
            for j, n in enumerate(_bid_claims(mine[0])):
                x[o + 17 + j] = n / 6
            for j, n in enumerate(_bid_claims(mine[-1])):
                x[o + 30 + j] = n / 6
        x[o + OPP_CRED:o + OPP_BLOCK] = cred_features(game.cred.get(p["seat"], CRED_ZERO), mu, k)
    return x


def mask(game, seat):
    """Erlaubte Aktionen nach Spielregeln und harten Regeln des Rechners (Liste von 313 bool).

    1. Gebote müssen zum Deck passen und höher sein als das aktuelle; Anzweifeln braucht ein Gebot.
    2. Nie ein Gebot mit P = 0, nie anzweifeln bei P = 1.
    3. Bleibt nichts übrig (aktuelles Gebot sicher wahr, nichts Höheres möglich): alle höheren Gebote
       sind erlaubt – Anzweifeln wäre der sichere Verlust. Gibt es kein höheres Gebot: anzweifeln.
    """
    cur = BID_INDEX[game.bids[-1][1]] if game.bids else 0
    legal = [False] * N_ACTIONS
    possible = [False] * N_ACTIONS
    for i, bid in enumerate(BIDS, start=1):
        if i > cur and bid_legal(bid, game.deck):
            legal[i] = True
            possible[i] = bid_prob(game, seat, bid)[0] > 0
    if cur:
        num, den = bid_prob(game, seat, BIDS[cur - 1])
        possible[0] = num != den
    if any(possible):
        return possible
    if any(legal):
        return legal
    legal[0] = True
    return legal
