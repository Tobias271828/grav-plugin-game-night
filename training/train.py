"""TromboneZero trainieren (Self-Play mit PPO, nur CPU).

  python train.py runs/name                       neuer Lauf mit den Voreinstellungen (tz/config.py)
  python train.py runs/name --set lr=1e-4 --set workers=8
  python train.py runs/name --resume              abgebrochenen Lauf fortsetzen
  python train.py runs/name --config best.json    Einstellungen aus einer Datei (z. B. von tune.py)

Der Lauf schreibt config.json, log.csv, latest.pt (zum Fortsetzen) und net_<Iteration>.pt (Zwischenstände).
"""

import argparse
import json
import os

from tz.config import DEFAULTS, apply_overrides
from tz.trainer import train


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("run_dir")
    ap.add_argument("--set", action="append", metavar="name=wert", help="Einstellung überschreiben (mehrfach möglich)")
    ap.add_argument("--config", help="JSON-Datei mit Einstellungen")
    ap.add_argument("--resume", action="store_true")
    args = ap.parse_args()
    cfg = dict(DEFAULTS)
    saved = os.path.join(args.run_dir, "config.json")
    if args.resume and os.path.exists(saved):
        cfg.update(json.load(open(saved)))
    elif os.path.exists(os.path.join(args.run_dir, "latest.pt")):
        raise SystemExit(f"{args.run_dir} enthält schon einen Lauf – mit --resume fortsetzen oder einen anderen Ordner wählen.")
    if args.config:
        cfg.update(json.load(open(args.config)))
    apply_overrides(cfg, args.set)
    train(cfg, args.run_dir, resume=args.resume)


if __name__ == "__main__":
    main()
