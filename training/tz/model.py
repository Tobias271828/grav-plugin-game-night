"""Das Netz: kleines MLP mit Strategie- und Wertkopf. Exportiert wird nur die Strategie.

Zwei Hilfen, die nur das Training kennt und die den Server nichts kosten:
  priv  Der Wertkopf sieht zusätzlich die verdeckten Karten aller (N_PRIV Werte, sim.private()). Er schätzt
        damit genauer, wie gut eine Lage wirklich ist; die Strategie bekommt davon nur das sauberere Lernsignal.
  aux   Ein Nebenkopf sagt aus dem Rumpf vorher, ob das aktuelle Gebot wahr ist und wie viele Karten jedes
        Rangs die anderen halten (N_AUX Werte). Das zwingt den Rumpf, aus Ansagen auf Karten zu schließen.
"""

import numpy as np
import torch
from torch import nn

from .actions import N_ACTIONS
from .features import N_FEATURES

N_PRIV = 7 * 14      # je Mitspieler: Anzahl je Rang / 4 (13), Joker / 2
N_AUX = 15           # Gebot wahr (Logit), Karten der anderen je Rang / 4 (13), ihre Joker / 2


class Net(nn.Module):
    def __init__(self, hidden=(128, 128), value_hidden=64, priv=False, aux=False):
        super().__init__()
        sizes = [N_FEATURES, *hidden]
        self.body = nn.ModuleList(nn.Linear(i, o) for i, o in zip(sizes, sizes[1:]))
        self.pi = nn.Linear(sizes[-1], N_ACTIONS)
        self.priv = bool(priv)
        if self.priv:
            self.v = nn.Sequential(nn.Linear(N_FEATURES + N_PRIV, 256), nn.ReLU(), nn.Linear(256, 128), nn.ReLU(),
                                   nn.Linear(128, 1))
        else:
            self.v = nn.Sequential(nn.Linear(sizes[-1], value_hidden), nn.ReLU(), nn.Linear(value_hidden, 1))
        self.aux = nn.Linear(sizes[-1], N_AUX) if aux else None
        nn.init.orthogonal_(self.pi.weight, 0.01)
        nn.init.zeros_(self.pi.bias)

    def forward(self, x, value_only=False, priv=None, aux=False):
        """value_only: der Wertkopf lernt allein, Rumpf und Strategie bleiben unberührt.

        Rückgabe: (Logits, Wert) – mit aux=True zusätzlich die Ausgaben des Nebenkopfs.
        Ohne priv liefert ein Netz mit sehendem Wertkopf den Wert 0 (zum Spielen braucht ihn niemand).
        """
        h = x
        for layer in self.body:
            h = torch.relu(layer(h))
        if self.priv:
            v = self.v(torch.cat([x, priv], dim=-1)).squeeze(-1) if priv is not None else torch.zeros(x.shape[0])
        else:
            v = self.v(h.detach() if value_only else h).squeeze(-1)
        if aux:
            return self.pi(h), v, self.aux(h)
        return self.pi(h), v

    def export_layers(self):
        """[(W, b), …] der Strategie als numpy float32 (für netfile.write_net)."""
        return [(l.weight.detach().numpy().astype(np.float32), l.bias.detach().numpy().astype(np.float32))
                for l in [*self.body, self.pi]]


def make_net(cfg):
    return Net(tuple(cfg["hidden"]), cfg["value_hidden"], priv=cfg.get("priv_critic", False),
               aux=cfg.get("aux_coef", 0) > 0)


def masked_logits(logits, mask):
    return logits.masked_fill(~mask, -1e9)


@torch.no_grad()
def sample(net, x, mask, generator=None, priv=None):
    """Aktionen würfeln. x, mask: numpy. Rückgabe: act, logp, value als numpy.

    net.temperature (falls gesetzt, nur für Messungen): Logits durch diese Zahl teilen – unter 1 spielt das
    Netz seine bevorzugten Züge häufiger.
    """
    logits, v = net(torch.from_numpy(x), priv=None if priv is None else torch.from_numpy(priv))
    t = getattr(net, "temperature", 1.0)
    if t != 1.0:
        logits = logits / t
    logits = masked_logits(logits, torch.from_numpy(mask))
    logp_all = torch.log_softmax(logits, dim=-1)
    u = torch.rand(logits.shape, generator=generator).clamp_(1e-12, 1 - 1e-7)
    a = torch.argmax(logp_all - torch.log(-torch.log(u)), dim=-1)     # Gumbel-Max
    return a.numpy(), logp_all.gather(1, a[:, None]).squeeze(1).numpy(), v.numpy()
