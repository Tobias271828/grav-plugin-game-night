"""Referenz der Spielregeln in reinem Python, Zug für Zug wie tb_act() in core/Trombone.php.

Nur der Teil, den das Training braucht (start, bid, challenge, next) samt Glaubwürdigkeitszählern.
Langsam, aber leicht mit dem PHP-Code zu vergleichen; die schnelle Simulation (sim.py) wird gegen
diese Referenz getestet. Bei Abweichungen gilt der PHP-Code.
"""

from .actions import MAX_CARDS, NEED, bid_legal

# Glaubwürdigkeitszähler je Platz (ganze Zahlen, wie tb_cred_update):
#   0..5  je Stelle p = 0, 1, 2 (erstes, zweites, drittes oder späteres eigenes Gebot einer Runde):
#         Zahl der Gebote [2p] und Summe ihrer Deckung in Sechzigsteln [2p + 1]
#   6     Züge, in denen angezweifelt werden konnte      7  davon angezweifelt     8  davon zu Recht
#   9     eigene Gebote, die angezweifelt wurden        10  davon wahr
CRED_POS = 3
CRED_N = 2 * CRED_POS + 5
CRED_TURNS, CRED_CHAL, CRED_WON, CRED_HIT, CRED_HIT_TRUE = range(2 * CRED_POS, CRED_N)
CRED_ZERO = (0,) * CRED_N


def hand_counts(cards):
    """Karten ["14S", "X", …] → ({Rang: Anzahl}, Joker)."""
    count, jokers = {}, 0
    for c in cards:
        if c == "X":
            jokers += 1
        else:
            r = int(c[:-1])
            count[r] = count.get(r, 0) + 1
    return count, jokers


def bid_true(bid, cards):
    t, a, b = bid
    count, jokers = hand_counts(cards)
    na, nb = NEED[t]
    missing = max(0, na - count.get(a, 0))
    if nb:
        missing += max(0, nb - count.get(b, 0))
    return missing <= jokers


def support60(bid, cards):
    """Deckung eines Gebots durch die eigene Hand in Sechzigsteln (0..60, immer ganzzahlig)."""
    t, a, b = bid
    count, jokers = hand_counts(cards)
    na, nb = NEED[t]
    covered = min(na, count.get(a, 0)) + (min(nb, count.get(b, 0)) if nb else 0) + jokers
    need = na + nb
    return min(covered, need) * 60 // need


class Game:
    def __init__(self, deck, order):
        """deck: "32" | "52"; order: Plätze in Zugreihenfolge (wie players nach dem Auslosen)."""
        self.deck = deck
        self.players = [{"seat": s, "cards": 1, "out": False} for s in order]
        self.starter = order[0]
        self.round = 0
        self.phase = "lobby"
        self.turn = None
        self.hands = {}
        self.bids = []            # [(seat, (t, a, b))]
        self.loser = None
        self.last = None          # Auflösung der letzten Runde
        self.cred = {s: list(CRED_ZERO) for s in order}

    def player(self, seat):
        return next(p for p in self.players if p["seat"] == seat)

    def active(self):
        return [p for p in self.players if not p["out"]]

    def next_seat(self, seat):
        seats = [p["seat"] for p in self.players]
        i = seats.index(seat)
        for k in range(1, len(seats) + 1):
            p = self.players[(i + k) % len(seats)]
            if not p["out"]:
                return p["seat"]
        return seat

    def deal(self, hands):
        """hands: {seat: [Karten]} – muss zu den Kartenzahlen passen."""
        for p in self.active():
            assert len(hands[p["seat"]]) == p["cards"], "Kartenzahl passt nicht"
        self.hands = {p["seat"]: list(hands[p["seat"]]) for p in self.active()}
        self.round += 1
        self.bids = []
        self.phase = "bidding"
        self.turn = self.starter

    def bid(self, seat, bid):
        assert self.phase == "bidding" and seat == self.turn, "nicht am Zug"
        assert bid_legal(bid, self.deck), "ungültiges Gebot"
        assert not self.bids or bid > self.bids[-1][1], "Gebot nicht höher"
        self.bids.append((seat, bid))
        self.turn = self.next_seat(seat)

    def challenge(self, seat):
        assert self.phase == "bidding" and seat == self.turn and self.bids, "Anzweifeln nicht möglich"
        last_seat, last_bid = self.bids[-1]
        all_cards = [c for h in self.hands.values() for c in h]
        true = bid_true(last_bid, all_cards)
        loser = seat if true else last_seat
        winner = last_seat if true else seat
        # Glaubwürdigkeitszähler (wie tb_cred_update)
        nth = {}
        for i, (s, b) in enumerate(self.bids):
            c = self.cred.setdefault(s, list(CRED_ZERO))
            p = min(nth.get(s, 0), CRED_POS - 1)
            nth[s] = nth.get(s, 0) + 1
            c[2 * p] += 1
            c[2 * p + 1] += support60(b, self.hands[s])
            if i > 0:
                c[CRED_TURNS] += 1
        c = self.cred.setdefault(seat, list(CRED_ZERO))
        c[CRED_TURNS] += 1
        c[CRED_CHAL] += 1
        if not true:
            c[CRED_WON] += 1
        c = self.cred.setdefault(last_seat, list(CRED_ZERO))
        c[CRED_HIT] += 1
        if true:
            c[CRED_HIT_TRUE] += 1
        lp = self.player(loser)
        game_over = lp["cards"] >= MAX_CARDS
        if not game_over:
            lp["cards"] += 1
        self.last = {"true": true, "loser": loser, "winner": winner}
        self.starter = winner
        self.turn = None
        if game_over:
            self.phase = "over"
            self.loser = loser
        else:
            self.phase = "reveal"
        return self.last
