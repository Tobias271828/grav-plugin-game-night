"""Arena: Simulation plus Besetzung der Plätze (lernendes Netz, ältere Netze, Rechner-Bots) und Rollouts."""

import numpy as np
import torch

from . import bots
from .features import OFF_OPP, OPP_BLOCK, OPP_CRED
from .model import sample
from .sim import Sim

LEARNER = 0      # kind 0: lernendes Netz; kind 1..K: Netz aus dem Pool; kind -1: Rechner-Bot


class Arena:
    def __init__(self, cfg, seed, n_games=None, assigner=None, n_probs=None):
        self.cfg = cfg
        self.rng = np.random.default_rng(seed)
        self.gen = torch.Generator().manual_seed(seed)
        self.B = n_games or cfg["games_per_worker"]
        self.kind = np.zeros((self.B, 8), np.int64)
        self.params = np.zeros((self.B, 8, bots.N_PARAMS), np.float32)
        self.style = np.zeros((self.B, 8), np.int64)
        self.assigner = assigner or self._assign_training
        self.blind_cred = False        # Glaubwürdigkeit auf den Vorwert setzen (für die Messung „Nutzen der Zähler“)
        self.sim = Sim(self.B, self.rng, n_probs if n_probs is not None else cfg["n_probs"],
                       mu=tuple(cfg["cred_mu"]), k=cfg["cred_k"])
        self._assign(np.arange(self.B))

    def _assign_training(self, _, idx):
        """Gegner-Pool pro Spiel: jeder Platz bleibt über das ganze Spiel gleich besetzt."""
        cfg, m = self.cfg, len(idx)
        if m == 0:
            return
        r = self.rng.random((m, 8))
        k_slots = max(1, cfg["pool_slots"])
        kind = np.where(r < cfg["p_self"], LEARNER,
                        np.where(r < cfg["p_self"] + cfg["p_pool"], self.rng.integers(1, k_slots + 1, (m, 8)), -1))
        # mindestens ein lernender Platz pro Spiel
        forced = (self.rng.random(m) * self.sim.n[idx]).astype(np.int64)
        kind[np.arange(m), forced] = LEARNER
        params, style = bots.sample_params(self.rng, (m, 8), cfg.get("calc_styles"), cfg["calc_jitter"])
        # Rechner-Tische: ein lernender Platz, sonst nur Rechner-Bots – oft alle im selben Stil. Nur an solchen
        # Tischen unterscheiden sich die Mitspielenden deutlich vom Self-Play; dort lernt das Netz, einen
        # Tisch an den Zählern zu erkennen (ehrlich, leichtgläubig, wild) und sich darauf einzustellen.
        table = self.rng.random(m) < cfg.get("p_calc_table", 0.0)
        kind[table] = -1
        same = table & (self.rng.random(m) < cfg.get("calc_table_same", 0.0))
        if same.any():
            p1, s1 = bots.sample_params(self.rng, (m, 1), cfg.get("calc_styles"), 0.0)
            jit = params - bots.STYLE_TABLE(cfg.get("calc_styles"))[style]
            params[same] = np.clip(p1[same] + 0.5 * jit[same], bots.PARAM_MIN, bots.PARAM_MAX)
            style[same] = s1[same]
        kind[np.arange(m), forced] = LEARNER
        self.kind[idx] = kind
        self.params[idx], self.style[idx] = params, style

    def rollout(self, net, pool, steps, collect=True):
        """steps Züge in allen Spielen. net: lernendes Netz, pool: Liste der Pool-Netze (kind 1..K).

        Rückgabe: (batch, stats). batch (nur bei collect): obs, mask, act, logp, adv, ret für alle Züge der
        lernenden Plätze, deren Fortsetzung im Rollout liegt. stats: Zähler je Personenzahl (Index n).
        """
        cfg, sim, B, g = self.cfg, self.sim, self.B, np.arange(self.B)
        T = steps
        val = np.zeros((T, B), np.float32)
        lrn = np.zeros((T, B), bool)
        actor = np.zeros((T, B), np.int64)
        ended = np.zeros((T, B), bool)
        over = np.zeros((T, B), bool)
        loser = np.full((T, B), -1, np.int64)
        obs_l, mask_l, act_l, logp_l, priv_l, true_l = [], [], [], [], [], []
        extra = collect and net is not None and (net.priv or net.aux is not None)
        # Zähler: Runden/Spiele je n, Verluste der lernenden Plätze, ihr fairer Anteil, Verluste je Bot-Stil
        st = {k: np.zeros(9) for k in ("rounds", "lost", "fair", "games", "glost", "gfair", "decisions", "challenges")}
        st["entropy_sum"] = 0.0
        for t in range(T):
            x, mask = sim.observe()
            if self.blind_cred:
                blk = x[:, OFF_OPP:].reshape(B, 7, OPP_BLOCK)
                blk[:, :, OPP_CRED:] = np.array(cfg["cred_mu"], np.float32) * blk[:, :, :1]
            s = sim.turn.copy()
            kind = self.kind[g, s]
            act = np.zeros(B, np.int64)
            rows = np.flatnonzero(kind == LEARNER)
            if len(rows):
                if net is None:      # Rechner-Bot auf dem gemessenen Platz (Vergleich der Stile)
                    a = bots.act(sim, rows, mask[rows], self.params[rows, s[rows]], self.rng)
                    lp = v = np.zeros(len(rows), np.float32)
                else:
                    priv = None
                    if extra:
                        priv, true = sim.private()
                        priv_l.append(priv[rows]); true_l.append(true[rows])
                        priv = priv[rows] if net.priv else None
                    a, lp, v = sample(net, x[rows], mask[rows], self.gen, priv=priv)
                act[rows] = a
                val[t, rows] = v
                lrn[t, rows] = True
                if collect:
                    obs_l.append(x[rows]); mask_l.append(mask[rows]); act_l.append(a); logp_l.append(lp)
                np.add.at(st["decisions"], sim.n[rows], 1)
                np.add.at(st["challenges"], sim.n[rows], a == 0)
            for k, other in enumerate(pool, start=1):
                rows = np.flatnonzero(kind == k)
                if len(rows):
                    act[rows] = sample(other, x[rows], mask[rows], self.gen)[0]
            rows = np.flatnonzero((kind == -1) | (kind > len(pool)))
            if len(rows):
                act[rows] = bots.act(sim, rows, mask[rows], self.params[rows, s[rows]], self.rng)
            actor[t] = s
            info = sim.step(act)
            ended[t], over[t], loser[t] = info["ended"], info["over"], info["loser"]
            e = np.flatnonzero(info["ended"])
            if len(e):
                n_e = info["n"][e]
                is_l = self.kind[e, info["loser"][e]] == LEARNER
                share = (self.kind[e] == LEARNER).sum(1) / n_e
                np.add.at(st["rounds"], n_e, 1)
                np.add.at(st["lost"], n_e, is_l)
                np.add.at(st["fair"], n_e, share)
                o = info["over"][e]
                np.add.at(st["games"], n_e[o], 1)
                np.add.at(st["glost"], n_e[o], is_l[o])
                np.add.at(st["gfair"], n_e[o], share[o])
            done = np.flatnonzero(info["over"])
            if len(done):
                self._assign(done)
        if not collect:
            return None, st
        adv, valid = self._gae(val, lrn, actor, ended, over, loser)
        sel = valid[lrn]
        batch = {
            "obs": np.concatenate(obs_l)[sel], "mask": np.concatenate(mask_l)[sel],
            "act": np.concatenate(act_l)[sel], "logp": np.concatenate(logp_l)[sel],
            "adv": adv[lrn][sel], "ret": (adv + val)[lrn][sel],
        }
        if extra:
            batch["priv"] = np.concatenate(priv_l)[sel]
            batch["true"] = np.concatenate(true_l)[sel]
        return batch, st

    def _assign(self, idx):
        """Plätze neuer Spiele besetzen; Slots jenseits der Personenzahl bekommen kind -2."""
        self.assigner(self, idx)
        empty = np.arange(8)[None, :] >= self.sim.n[idx][:, None]
        k = self.kind[idx]
        k[empty] = -2
        self.kind[idx] = k

    def _gae(self, val, lrn, actor, ended, over, loser):
        """Vorteile je (Spiel, Slot) rückwärts über den Rollout.

        Belohnung: -1 für die Person, die eine Runde verliert, zusätzlich -game_penalty beim Spielverlust.
        Innerhalb einer Runde wird nicht abgezinst, über eine Rundengrenze mit gamma_round.
        Züge ohne Fortsetzung im Rollout (valid = False) dienen nur als Stütze und werden nicht gelernt.
        """
        cfg = self.cfg
        T, B = val.shape
        g = np.arange(B)
        gr, lam, pen = cfg["gamma_round"], cfg["gae_lambda"], cfg["game_penalty"]
        acc = np.zeros((B, 8), np.float32)
        disc = np.ones((B, 8), np.float32)
        nv = np.zeros((B, 8), np.float32)
        nadv = np.zeros((B, 8), np.float32)
        has = np.zeros((B, 8), bool)
        adv = np.zeros((T, B), np.float32)
        valid = np.zeros((T, B), bool)
        for t in range(T - 1, -1, -1):
            e = np.flatnonzero(ended[t])
            if len(e):
                acc[e] *= gr
                disc[e] *= gr
                o = e[over[t][e]]
                acc[o] = 0.0
                disc[o] = 0.0
                has[o] = True
                acc[e, loser[t][e]] -= 1.0
                acc[o, loser[t][o]] -= pen
            r = g[lrn[t]]
            s = actor[t][r]
            v = val[t][r]
            boot = np.where(has[r, s], nv[r, s], v)
            delta = acc[r, s] + disc[r, s] * boot - v
            a = delta + disc[r, s] * lam * nadv[r, s]
            adv[t, r] = a
            valid[t, r] = has[r, s]
            nv[r, s], nadv[r, s], acc[r, s], disc[r, s], has[r, s] = v, a, 0.0, 1.0, True
        return adv, valid
