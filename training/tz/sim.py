"""Schnelle Simulation: viele Trombone-Spiele gleichzeitig als numpy-Felder.

Bildet dieselben Regeln, Zähler, Merkmale und Masken ab wie rules.py / features.py (und damit wie der
PHP-Code); tests/test_sim.py vergleicht beide Zug für Zug. Die Spielenden eines Spiels stehen in
„Slots“ 0..n-1 in Zugreihenfolge. Nicht abgebildet: remove, propose (braucht das Training nicht).
"""

import numpy as np

from . import calc
from .actions import ACT_A, ACT_B, ACT_NA, ACT_NB, ACT_T, DECK_LEGAL, MAX_CARDS, N_ACTIONS
from .features import CRED_K, CRED_MU, N_FEATURES, OFF_OPP, OFF_TRUST, OPP_BLOCK, OPP_CRED
from .rules import CRED_CHAL, CRED_HIT, CRED_HIT_TRUE, CRED_N, CRED_POS, CRED_TURNS, CRED_WON

_CARD_RANK = np.array([i // 4 for i in range(52)] + [13, 13])           # 13 = Joker
_NOT_IN_32 = np.array([i // 4 < 5 for i in range(52)] + [False, True])   # Ränge 2–6 und der zweite Joker
_RANK_IN_DECK = np.array([[r >= 5 for r in range(13)], [True] * 13], dtype=np.float32)
_TWO = ACT_NB > 0
_ARANGE7 = np.arange(7)
_ARANGE_ACT = np.arange(N_ACTIONS)


class Sim:
    def __init__(self, n_games, rng, n_probs, mu=CRED_MU, k=CRED_K):
        """n_probs: Wahrscheinlichkeiten der Personenzahlen 2..8 (Länge 7)."""
        self.B = B = n_games
        self.rng = rng
        self.n_probs = np.asarray(n_probs, dtype=np.float64) / np.sum(n_probs)
        self.mu, self.k = mu, k
        prob, state = calc.tables()
        self.prob = prob.astype(np.float32).ravel()
        self.prob64 = prob.ravel()
        self.state = state.ravel()
        self.n = np.zeros(B, np.int64)
        self.deck = np.zeros(B, np.int64)             # 0 = 32 Karten, 1 = 52 Karten
        self.cards = np.zeros((B, 8), np.int64)
        self.cnt = np.zeros((B, 8, 13), np.int64)
        self.jok = np.zeros((B, 8), np.int64)
        self.turn = np.zeros(B, np.int64)
        self.starter = np.zeros(B, np.int64)
        self.cur = np.zeros(B, np.int64)              # aktuelles Gebot als Aktionsindex, 0 = keines
        self.last = np.full(B, -1, np.int64)          # Slot des letzten Gebots
        self.nbids = np.zeros(B, np.int64)
        self.claims = np.zeros((B, 8, 13), np.int64)
        self.bidn = np.zeros((B, 8), np.int64)
        self.first = np.zeros((B, 8, 13), np.int64)   # Ansagen des ersten Gebots der Runde je Slot
        self.lastc = np.zeros((B, 8, 13), np.int64)   # Ansagen des letzten Gebots der Runde je Slot
        self.before = np.zeros((B, 8, 13), np.int64)  # claims vor dem aktuellen Gebot
        self.cred = np.zeros((B, 8, CRED_N), np.int64)     # abgeschlossene Runden (Aufbau siehe rules.py)
        self.pend = np.zeros((B, 8, CRED_N), np.int64)     # laufende Runde (wird beim Zweifel übernommen)
        self.totals = np.zeros(CRED_N, np.int64)        # Summe aller Zähler (für die Vorwerte der Glaubwürdigkeit)
        self.g = np.arange(B)
        self.reset(self.g)
        self.new_round(self.g)

    # -- Spielablauf ---------------------------------------------------------

    def reset(self, idx, n=None, deck=None):
        """Neue Spiele in den Zeilen idx (Personenzahl und Deck zufällig oder vorgegeben)."""
        m = len(idx)
        if m == 0:
            return
        if n is None:
            n = self.rng.choice(np.arange(2, 9), size=m, p=self.n_probs)
        if deck is None:
            deck = np.where(n == 2, 0, np.where(n == 3, self.rng.integers(0, 2, m), 1))
        self.n[idx] = n
        self.deck[idx] = deck
        self.cards[idx] = (np.arange(8)[None, :] < np.asarray(n)[:, None]).astype(np.int64)
        self.cred[idx] = 0
        self.starter[idx] = 0

    def new_round(self, idx, hands=None):
        """Austeilen in den Zeilen idx. hands (nur für Tests): (cnt [m, 8, 13], jok [m, 8])."""
        m = len(idx)
        if m == 0:
            return
        if hands is None:
            keys = self.rng.random((m, 54), dtype=np.float32)
            keys += (_NOT_IN_32[None, :] & (self.deck[idx] == 0)[:, None]) * 2.0
            ranks = _CARD_RANK[np.argsort(keys, axis=1)[:, :48]]
            cs = np.cumsum(self.cards[idx], axis=1)
            owner = (np.arange(48)[None, :, None] >= cs[:, None, :]).sum(-1)      # 8 = nicht ausgeteilt
            flat = (np.arange(m)[:, None] * 9 + owner) * 14 + ranks
            c = np.bincount(flat.ravel(), minlength=m * 126).reshape(m, 9, 14)
            self.cnt[idx] = c[:, :8, :13]
            self.jok[idx] = c[:, :8, 13]
        else:
            self.cnt[idx], self.jok[idx] = hands
        self.cur[idx] = 0
        self.last[idx] = -1
        self.nbids[idx] = 0
        self.claims[idx] = 0
        self.first[idx] = 0
        self.lastc[idx] = 0
        self.before[idx] = 0
        self.bidn[idx] = 0
        self.pend[idx] = 0
        self.turn[idx] = self.starter[idx]

    def step(self, act, auto=True):
        """Aktion act[B] des Slots am Zug in jedem Spiel ausführen.

        Rückgabe: dict mit ended [B] (Runde zu Ende), over [B] (Spiel zu Ende), loser [B] (Slot, sonst -1),
        true [B] (angezweifeltes Gebot war wahr), n [B] (Personenzahl vor einem Neustart).
        auto=False: beendete Runden/Spiele nicht selbst neu starten (für Tests).
        """
        g, s = self.g, self.turn
        is_bid = act > 0
        info = {"ended": ~is_bid, "over": np.zeros(self.B, bool), "loser": np.full(self.B, -1, np.int64),
                "true": np.zeros(self.B, bool), "n": self.n.copy()}

        gb, sb, a = g[is_bid], s[is_bid], act[is_bid]
        if len(gb):
            ra, rb, na, nb = ACT_A[a], ACT_B[a], ACT_NA[a], ACT_NB[a]
            two = nb > 0
            covered = np.minimum(na, self.cnt[gb, sb, ra]) + np.where(two, np.minimum(nb, self.cnt[gb, sb, rb]), 0) + self.jok[gb, sb]
            need = na + nb
            pos = np.minimum(self.bidn[gb, sb], CRED_POS - 1)
            self.pend[gb, sb, 2 * pos] += 1
            self.pend[gb, sb, 2 * pos + 1] += np.minimum(covered, need) * 60 // need
            self.pend[gb, sb, CRED_TURNS] += self.nbids[gb] > 0
            self.before[gb] = self.claims[gb]
            self.claims[gb, sb, ra] = np.maximum(self.claims[gb, sb, ra], na)
            g2, s2, r2 = gb[two], sb[two], rb[two]
            self.claims[g2, s2, r2] = np.maximum(self.claims[g2, s2, r2], nb[two])
            one = np.zeros((len(gb), 13), np.int64)
            one[np.arange(len(gb)), ra] = na
            one[np.flatnonzero(two), r2] = nb[two]
            self.lastc[gb, sb] = one
            f = self.bidn[gb, sb] == 0
            self.first[gb[f], sb[f]] = one[f]
            self.bidn[gb, sb] += 1
            self.cur[gb] = a
            self.last[gb] = sb
            self.nbids[gb] += 1
            self.turn[gb] = (sb + 1) % self.n[gb]

        gc, sc = g[~is_bid], s[~is_bid]
        if len(gc):
            a = self.cur[gc]
            assert (a > 0).all(), "Anzweifeln ohne Gebot"
            ar = np.arange(len(gc))
            tot = self.cnt[gc].sum(1)
            na, nb = ACT_NA[a], ACT_NB[a]
            missing = np.maximum(0, na - tot[ar, ACT_A[a]]) + np.where(nb > 0, np.maximum(0, nb - tot[ar, ACT_B[a]]), 0)
            true = missing <= self.jok[gc].sum(1)
            lastb = self.last[gc]
            loser = np.where(true, sc, lastb)
            self.cred[gc] += self.pend[gc]
            self.cred[gc, sc, CRED_TURNS] += 1
            self.cred[gc, sc, CRED_CHAL] += 1
            self.cred[gc, sc, CRED_WON] += ~true
            self.cred[gc, lastb, CRED_HIT] += 1
            self.cred[gc, lastb, CRED_HIT_TRUE] += true
            self.totals += self.pend[gc].sum((0, 1))
            self.totals[CRED_TURNS:] += [len(gc), len(gc), int((~true).sum()), len(gc), int(true.sum())]
            over = self.cards[gc, loser] >= MAX_CARDS
            self.cards[gc[~over], loser[~over]] += 1
            self.starter[gc] = np.where(true, lastb, sc)
            info["loser"][gc] = loser
            info["true"][gc] = true
            info["over"][gc] = over
            if auto:
                self.reset(gc[over])
                self.new_round(gc)
        return info

    # -- Sicht des Slots am Zug ---------------------------------------------

    def observe(self):
        """Merkmale [B, 579] float32 und Maske [B, 313] bool für den Slot am Zug in jedem Spiel."""
        B, g, s = self.B, self.g, self.turn
        own_cnt = self.cnt[g, s]
        own_j = self.jok[g, s]
        own = self.cards[g, s]
        total = self.cards.sum(1)
        U = total - own
        cur = self.cur
        has = cur > 0
        x = np.zeros((B, N_FEATURES), np.float32)

        x[:, 0] = self.deck
        x[g, self.n - 1] = 1.0
        x[:, 8] = total / 48
        x[:, 9] = np.minimum(self.nbids, 20) / 20
        x[g, 9 + own] = 1.0
        x[:, 16:29] = own_cnt / 4
        x[:, 29] = own_j / 2
        x[:, 30:43] = self.claims[g, s] / 6
        x[:, 43] = has
        gh, ch = g[has], cur[has]
        x[gh, 44 + ACT_T[ch]] = 1.0
        x[gh, 52 + ACT_A[ch]] = 1.0
        two = _TWO[ch]
        x[gh[two], 65 + ACT_B[ch[two]]] = 1.0

        # Tabellenindex: [deck, own-1, U, na, nb>0, ca, cb, j]
        base = (self.deck * 6 + own - 1) * 43 + U
        ca = own_cnt[:, ACT_A]
        cb = own_cnt[:, ACT_B] * _TWO[None, :]
        idx = ((((base[:, None] * 7 + ACT_NA[None, :]) * 2 + _TWO[None, :]) * 5 + ca) * 5 + cb) * 3 + own_j[:, None]
        self._idx = idx
        st = self.state[idx]
        x[:, 78] = np.where(has, self.prob[idx[g, cur]], 0.0)
        kk = np.arange(1, 7)
        idx_k = ((base[:, None, None] * 7 + kk[None, None, :]) * 2 * 5 + own_cnt[:, :, None]) * 5 * 3 + own_j[:, None, None]
        x[:, 79:157] = (self.prob[idx_k] * _RANK_IN_DECK[self.deck][:, :, None]).reshape(B, 78)

        # Rechner mit Ansagen: höchste Ansage der anderen je Rang zählt als Karten (höchstens 4)
        cl = self.claims.copy()
        cl[g, s] = 0
        t_cnt = np.minimum(4, own_cnt + cl.max(1))
        idx_t = ((base[:, None, None] * 7 + kk[None, None, :]) * 2 * 5 + t_cnt[:, :, None]) * 5 * 3 + own_j[:, None, None]
        x[:, OFF_TRUST + 1:OFF_TRUST + 79] = (self.prob[idx_t] * _RANK_IN_DECK[self.deck][:, :, None]).reshape(B, 78)
        cl = self.before.copy()                      # aktuelles Gebot: ohne dieses Gebot selbst
        cl[g, s] = 0
        b_cnt = np.minimum(4, own_cnt + cl.max(1))
        ta = b_cnt[g, ACT_A[cur]]
        tb = b_cnt[g, ACT_B[cur]] * _TWO[cur]
        idx_c = ((((base * 7 + ACT_NA[cur]) * 2 + _TWO[cur]) * 5 + ta) * 5 + tb) * 3 + own_j
        x[:, OFF_TRUST] = np.where(has, self.prob[idx_c], 0.0)

        o = (s[:, None] + 1 + _ARANGE7[None, :]) % self.n[:, None]
        pres = _ARANGE7[None, :] < (self.n - 1)[:, None]
        g2 = g[:, None]
        blk = np.zeros((B, 7, OPP_BLOCK), np.float32)
        blk[:, :, 0] = 1.0
        blk[:, :, 1] = self.cards[g2, o] / 6
        blk[:, :, 2] = o == self.last[:, None]
        blk[:, :, 3] = np.minimum(self.bidn[g2, o], 8) / 8
        blk[:, :, 4:17] = self.claims[g2, o] / 6
        blk[:, :, 17:30] = self.first[g2, o] / 6
        blk[:, :, 30:43] = self.lastc[g2, o] / 6
        c = self.cred[g2, o].astype(np.float32)
        mu, k = self.mu, self.k
        for p in range(CRED_POS):
            blk[:, :, OPP_CRED + p] = (c[:, :, 2 * p + 1] / 60 + k * mu[p]) / (c[:, :, 2 * p] + k)
        blk[:, :, OPP_CRED + CRED_POS] = (c[:, :, CRED_CHAL] + k * mu[CRED_POS]) / (c[:, :, CRED_TURNS] + k)
        blk[:, :, OPP_CRED + CRED_POS + 1] = (c[:, :, CRED_WON] + k * mu[CRED_POS + 1]) / (c[:, :, CRED_CHAL] + k)
        blk[:, :, OPP_CRED + CRED_POS + 2] = (c[:, :, CRED_HIT_TRUE] + k * mu[CRED_POS + 2]) / (c[:, :, CRED_HIT] + k)
        blk *= pres[:, :, None]
        x[:, OFF_OPP:] = blk.reshape(B, 7 * OPP_BLOCK)

        # Maske (wie features.mask)
        legal = DECK_LEGAL[self.deck] & (_ARANGE_ACT[None, :] > cur[:, None])
        legal[:, 0] = False
        possible = legal & (st > 0)
        possible[:, 0] = has & (st[g, cur] != 2)
        none = ~possible.any(1)
        if none.any():
            fb = legal[none]
            fb[:, 0] = ~fb.any(1)
            possible[none] = fb
        return x, possible

    def private(self):
        """Nur fürs Training: verdeckte Karten der anderen aus Sicht des Slots am Zug [B, 7 · 14] float32
        (je Mitspieler in Zugreihenfolge: Anzahl je Rang / 4, Joker / 2) und ob das aktuelle Gebot wahr ist [B]."""
        g, s = self.g, self.turn
        o = (s[:, None] + 1 + _ARANGE7[None, :]) % self.n[:, None]
        pres = _ARANGE7[None, :] < (self.n - 1)[:, None]
        p = np.zeros((self.B, 7, 14), np.float32)
        p[:, :, :13] = self.cnt[g[:, None], o] / 4
        p[:, :, 13] = self.jok[g[:, None], o] / 2
        p *= pres[:, :, None]
        tot = self.cnt.sum(1)
        a = self.cur
        missing = np.maximum(0, ACT_NA[a] - tot[g, ACT_A[a]]) + np.where(_TWO[a], np.maximum(0, ACT_NB[a] - tot[g, ACT_B[a]]), 0)
        true = (a > 0) & (missing <= self.jok.sum(1))
        return p.reshape(self.B, 98), true

    def probs(self, rows, trust=None):
        """P aller Gebote [len(rows), 313] aus Sicht des Slots am Zug (nach observe()).

        trust [len(rows)] in 0..1: Anteil, mit dem die Ansagen der anderen als echte Karten gezählt werden
        (für leichtgläubige Rechner-Bots): P = (1 - trust) · P + trust · P(mit den angesagten Karten).
        """
        p = self.prob[self._idx[rows]]
        if trust is not None and (trust > 0).any():
            t = trust > 0
            r = rows[t]
            s = self.turn[r]
            cl = self.claims[r].copy()
            cl[np.arange(len(r)), s] = 0
            own_cnt = np.minimum(4, self.cnt[r, s] + cl.max(1))
            own = self.cards[r, s]
            base = (self.deck[r] * 6 + own - 1) * 43 + (self.cards[r].sum(1) - own)
            ca = own_cnt[:, ACT_A]
            cb = own_cnt[:, ACT_B] * _TWO[None, :]
            idx = ((((base[:, None] * 7 + ACT_NA[None, :]) * 2 + _TWO[None, :]) * 5 + ca) * 5 + cb) * 3 + self.jok[r, s][:, None]
            w = trust[t][:, None].astype(np.float32)
            p[t] = (1 - w) * p[t] + w * self.prob[idx]
        return p
