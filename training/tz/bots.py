"""Rechner-Bots: einfache Schwellen-Bots als Trainingsgegner und Messlatte (vektorisiert).

Ein Stil besteht aus fünf Zahlen, die über ein ganzes Spiel gleich bleiben:
  tau    Zweifelschwelle: anzweifeln, wenn P(aktuelles Gebot) < tau
  bluff  Bluffquote beim ersten eigenen Gebot einer Runde: mit dieser Wahrscheinlichkeit irgendein knapp
         höheres Gebot statt des ehrlichen
  q      Ehrlichkeit: geboten wird das niedrigste Gebot mit P ≥ q · (bestes P unter den höheren Geboten)
  trust  Leichtgläubigkeit: Anteil, mit dem die Ansagen der anderen als echte Karten gezählt werden
  bluff2 Bluffquote bei allen späteren eigenen Geboten der Runde
Zusätzlich zweifelt jeder Bot an, wenn das aktuelle Gebot eher falsch ist als sein bestes Gebot wahr.
Die harten Regeln (Maske) gelten auch für diese Bots.
"""

import numpy as np

STYLES = {
    "standard": (0.35, 0.08, 0.80, 0.0, 0.08),    # Messlatte der Abnahme
    "ehrlich": (0.35, 0.00, 0.95, 0.0, 0.00),
    "wild": (0.30, 0.45, 0.60, 0.0, 0.45),        # blufft wild
    "frueh": (0.55, 0.05, 0.80, 0.0, 0.05),       # zweifelt früh
    "selten": (0.12, 0.05, 0.80, 0.0, 0.05),      # zweifelt selten
    "glaeubig": (0.35, 0.05, 0.80, 0.7, 0.05),    # glaubt den Ansagen
    "erstbluff": (0.35, 0.60, 0.85, 0.3, 0.02),   # blufft beim ersten Gebot, danach nah an der eigenen Hand
    "spaetbluff": (0.35, 0.02, 0.85, 0.3, 0.50),  # beginnt ehrlich, blufft in späteren Geboten
}
N_PARAMS = 5
STYLE_NAMES = list(STYLES)


PARAM_MIN = np.array([0.02, 0, 0.3, 0, 0], np.float32)
PARAM_MAX = np.array([0.9, 0.9, 1, 1, 0.9], np.float32)


def STYLE_TABLE(styles=None):
    return np.array([STYLES[s] for s in (styles or STYLE_NAMES)], dtype=np.float32)


def sample_params(rng, shape, styles=None, jitter=0.0):
    """Zufällige Stile für Plätze: Feld shape + (N_PARAMS,). jitter streut die Zahlen um den Stil."""
    names = styles or STYLE_NAMES
    table = np.array([STYLES[s] for s in names], dtype=np.float32)
    idx = rng.integers(0, len(names), size=shape)
    p = table[idx]
    if jitter:
        p = p + rng.normal(0, jitter, p.shape).astype(np.float32) * np.array([1, 1, 0.5, 1, 1], np.float32)
        p = np.clip(p, PARAM_MIN, PARAM_MAX).astype(np.float32)
    return p, idx


def act(sim, rows, mask, params, rng):
    """Aktionen der Rechner-Bots in den Spielen rows (nach sim.observe()). mask, params: je Zeile von rows."""
    m = len(rows)
    tau, q, trust = params[:, 0], params[:, 2], params[:, 3]
    bluff = np.where(sim.bidn[rows, sim.turn[rows]] == 0, params[:, 1], params[:, 4])
    p = sim.probs(rows, trust)
    ar = np.arange(m)
    p_cur = p[ar, sim.cur[rows]]
    cand = mask[:, 1:]
    pc = np.where(cand, p[:, 1:], -1.0)
    p_best = pc.max(1)
    n_cand = cand.sum(1)
    honest = np.argmax(cand & (pc >= q[:, None] * p_best[:, None] - 1e-9), axis=1)
    pick = np.minimum(rng.integers(1, 9, m), np.maximum(n_cand, 1))
    bluff_bid = np.argmax(np.cumsum(cand, axis=1) >= pick[:, None], axis=1)
    do_bluff = (rng.random(m) < bluff) & (n_cand > 0)
    challenge = mask[:, 0] & ((p_cur < tau) | (1 - p_cur > p_best)) & ~do_bluff
    challenge |= n_cand == 0
    return np.where(challenge, 0, np.where(do_bluff, bluff_bid, honest) + 1)
