"""Referenzdateien für die PHP-Tests schreiben: tests/ref/test-net.bin und tests/ref/trombone-ref.jsonl.

Nur nötig, wenn sich Regeln, Rechner, Merkmale (FEATURE_VERSION) oder das Dateiformat ändern.
Aufruf: python gen_refs.py   (danach: php ../tests/bot-test.php)
"""

import json
import os

import numpy as np

from tz.actions import N_ACTIONS
from tz.features import N_FEATURES
from tz.netfile import read_net, write_net
from tz.refgames import generate

REF = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "tests", "ref")


def main():
    os.makedirs(REF, exist_ok=True)
    rng = np.random.default_rng(20261125)
    sizes = [N_FEATURES, 12, 12, N_ACTIONS]
    layers = [(rng.normal(0, 0.25, (o, i)).astype(np.float32), rng.normal(0, 0.5, o).astype(np.float32))
              for i, o in zip(sizes, sizes[1:])]
    net_path = os.path.join(REF, "test-net.bin")
    write_net(net_path, layers, meta={"name": "Testnetz (Zufallsgewichte, nur für tests/bot-test.php)"})
    net = read_net(net_path)
    games = generate(seed=4, n_games=14, net=net, x_every=16, p_every=16)
    with open(os.path.join(REF, "trombone-ref.jsonl"), "w", encoding="utf-8") as fh:
        for g in games:
            fh.write(json.dumps(g, separators=(",", ":")) + "\n")
    turns = sum(len(r["turns"]) for g in games for r in g["rounds"])
    print(f"{len(games)} Spiele, {turns} Züge → {REF}")


if __name__ == "__main__":
    main()
