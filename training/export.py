"""Netz als Gewichtsdatei für den Server exportieren und gegen PHP prüfen.

  python export.py runs/name/net_00500.pt                 schreibt ../core/trombone-net.bin
  python export.py runs/name/net_00500.pt --out datei.bin
  python export.py … --ema --temperature 0.6             gemittelte Gewichte, weniger Zufall beim Spielen
  python export.py … --no-php                             ohne den Abgleich mit PHP (wenn kein php installiert ist)

Geprüft wird: Die Datei lässt sich wieder lesen, numpy rechnet wie PyTorch, und PHP (tests/bot-test.php)
kommt bei Zufallsspielen auf dieselben Merkmale, Masken und Verteilungen (Abweichung höchstens 1e-4).
"""

import argparse
import datetime
import json
import os
import subprocess

import numpy as np
import torch

from tz import evaluate as ev
from tz.features import FEATURE_VERSION, N_FEATURES
from tz.netfile import forward, read_net, write_net
from tz.refgames import replay

ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..")


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("net")
    ap.add_argument("--out", default=os.path.join(ROOT, "core", "trombone-net.bin"))
    ap.add_argument("--no-php", action="store_true")
    ap.add_argument("--note", default="", help="freier Text für die Metadaten")
    ap.add_argument("--ema", action="store_true", help="die gemittelten Gewichte des Zwischenstands exportieren")
    ap.add_argument("--temperature", type=float, default=1.0,
                    help="Temperatur beim Spielen: unter 1 wählt der Bot seine bevorzugten Züge häufiger. Wird in die "
                         "letzte Schicht eingerechnet (Gewichte und Schwellen durch T), der Server braucht nichts dafür")
    args = ap.parse_args()
    assert 0.05 <= args.temperature <= 2, "Temperatur zwischen 0,05 und 2"

    net = ev.load_net(args.net, ema=args.ema)
    cfg = net.cfg
    meta = {
        "name": "TromboneZero", "short": "T0", "exported": datetime.date.today().isoformat(),
        "steps": int(net.steps), "hidden": list(cfg["hidden"]), "feature_version": FEATURE_VERSION,
        "game_penalty": cfg["game_penalty"], "n_probs": cfg["n_probs"], "note": args.note,
        "temperature": args.temperature, "ema": args.ema,
        "source": os.path.basename(os.path.dirname(os.path.abspath(args.net))) + "/" + os.path.basename(args.net),
    }
    with torch.no_grad():       # Temperatur einrechnen: Logits / T = (W / T) · h + b / T
        net.pi.weight.div_(args.temperature)
        net.pi.bias.div_(args.temperature)
    layers = net.export_layers()
    write_net(args.out, layers, mu=tuple(cfg["cred_mu"]), k=cfg["cred_k"], meta=meta)
    back = read_net(args.out)
    x = np.random.default_rng(0).random((256, N_FEATURES), dtype=np.float32)
    with torch.no_grad():
        want = net(torch.from_numpy(x))[0].numpy()
    d = float(np.abs(forward(back["layers"], x) - want).max())
    scale = max(1.0, float(np.abs(want).max()))     # float32: die Abweichung wächst mit der Größe der Ausgaben
    assert d < 1e-5 * scale, f"numpy weicht von PyTorch ab: {d} (Ausgaben bis {scale:.0f})"
    size = os.path.getsize(args.out)
    print(f"{args.out}: {size / 1024:.0f} KB, Schichten {back['sizes']}, μ = {[round(v, 3) for v in back['mu']]}, "
          f"k = {back['k']}, Abweichung numpy/PyTorch {d:.1e}")

    if not args.no_php:
        out = subprocess.run(["php", os.path.join(ROOT, "tests", "bot-test.php"), "gen", "7", "25", args.out],
                             capture_output=True, text=True, check=True).stdout
        turns, max_x, max_p = 0, 0.0, 0.0
        for line in out.splitlines():
            t, dx, dp = replay(json.loads(line), back)
            turns, max_x, max_p = turns + t, max(max_x, dx), max(max_p, dp)
        assert turns > 500
        print(f"PHP-Abgleich: {turns} Züge, größte Abweichung Merkmale {max_x:.1e}, Verteilung {max_p:.1e} (erlaubt 1e-4)")
    print("Fertig. Danach: php tests/bot-test.php && php tests/api-test.php && php tools/server-check.php")


if __name__ == "__main__":
    main()
