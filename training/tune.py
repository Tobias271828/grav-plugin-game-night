"""Einstellungen des Trainings suchen: Zufallssuche mit schrittweiser Auslese (Successive Halving).

  python tune.py runs/tune1 --trials 12 --steps 20e6 --parallel 4
  python tune.py runs/tune1 --resume            abgebrochene Suche fortsetzen (fertige Stufen bleiben)

Ablauf: --trials zufällige Einstellungen trainieren je --steps Züge. Dann werden alle gemessen, das beste
Drittel trainiert mit dreifachem Budget weiter, und so fort (--stages Stufen). Gemessen wird (tz/evaluate.league)
  Stile: mittleres Verlust-Verhältnis gegen Rechner-Bots, über alle Stile und n = 3..8, und
  Netze: mittleres Verhältnis am halben Tisch gegen jeden anderen Kandidaten derselben Stufe – und gegen
         jedes mit --anchor genannte Netz (z. B. den besten Stand eines früheren Laufs als feste Messlatte).
Die Wertung ist das Mittel aus beidem (kleiner ist besser); dazu wird der schlechteste Stil ausgegeben.
Gemessen werden die gemittelten Gewichte (@ema). Ergebnis: results.json und best.json im Ordner;
best.json passt zu  python train.py runs/final --config runs/tune1/best.json --set total_steps=…

Während der Suche bleiben Lernrate und Entropie-Gewicht konstant (keine Absenkung), damit sich Läufe sauber
verlängern lassen. Jeder Lauf nutzt --workers Prozesse; --parallel Läufe gleichzeitig.
"""

import argparse
import json
import os
import subprocess
import sys

import numpy as np

from tz.config import DEFAULTS

HERE = os.path.dirname(os.path.abspath(__file__))


def sample_config(rng):
    """Eine zufällige Einstellung. Der Suchraum ist bewusst klein gehalten – hier anpassen."""
    lr = float(10 ** rng.uniform(-4.0, -3.0))
    ent = float(10 ** rng.uniform(-2.5, -1.5))
    p_self = float(rng.choice([0.4, 0.5, 0.6]))
    return {
        "lr": lr, "lr_final": lr,
        "ent_coef": ent, "ent_coef_final": ent,
        "gamma_round": float(rng.choice([0.9, 0.95, 0.97, 1.0])),
        "gae_lambda": float(rng.choice([0.9, 0.95, 0.98])),
        "epochs": int(rng.choice([2, 3, 4])),
        "minibatch": int(rng.choice([4096, 8192, 16384])),
        "rollout_steps": int(rng.choice([64, 128, 256])),
        "clip": float(rng.choice([0.1, 0.2, 0.3])),
        "p_self": p_self,
        "p_pool": float(rng.choice([0.15, 0.25, 0.35])),
        "p_calc_table": float(rng.choice([0.1, 0.2, 0.3])),
        "calc_table_same": float(rng.choice([0.3, 0.6, 0.9])),
        "pool_perturbed": float(rng.choice([0.0, 0.35, 0.7])),
        "perturb_sigma": float(rng.choice([0.02, 0.05, 0.1])),
        "hidden": [int(v) for v in rng.choice([[256, 256], [256, 256], [128, 128], [256, 128]])],
    }


def run_trials(jobs, parallel):
    """jobs: [(Name, Befehl, Logdatei)]; höchstens parallel gleichzeitig. Gibt die fehlgeschlagenen Namen zurück."""
    pending, running, failed = list(jobs), [], []
    while pending or running:
        while pending and len(running) < parallel:
            name, cmd, log = pending.pop(0)
            running.append((name, subprocess.Popen(cmd, stdout=open(log, "a"), stderr=subprocess.STDOUT, cwd=HERE)))
            print(f"  gestartet: {name}", flush=True)
        name, proc = running.pop(0)
        if proc.wait() != 0:
            failed.append(name)
            print(f"  FEHLER in {name} (siehe Logdatei)", flush=True)
    return failed


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("dir")
    ap.add_argument("--trials", type=int, default=12)
    ap.add_argument("--steps", type=float, default=20e6, help="Züge je Lauf in der ersten Stufe")
    ap.add_argument("--stages", type=int, default=3)
    ap.add_argument("--parallel", type=int, default=max(1, (os.cpu_count() or 2) // 2))
    ap.add_argument("--workers", type=int, default=1, help="Simulationsprozesse je Lauf")
    ap.add_argument("--eval-rounds", type=int, default=30000)
    ap.add_argument("--seed", type=int, default=1)
    ap.add_argument("--set", action="append", default=[], metavar="name=wert", help="feste Einstellung für alle Läufe")
    ap.add_argument("--anchor", action="append", default=[], metavar="NETZ", help="festes Vergleichsnetz (mehrfach möglich)")
    ap.add_argument("--resume", action="store_true")
    args = ap.parse_args()

    os.makedirs(args.dir, exist_ok=True)
    state_path = os.path.join(args.dir, "results.json")
    if args.resume and os.path.exists(state_path):
        state = json.load(open(state_path))
    else:
        rng = np.random.default_rng(args.seed)
        trials = {"t00": {}}                     # t00 = Voreinstellungen als Vergleich
        for i in range(1, args.trials):
            trials[f"t{i:02d}"] = sample_config(rng)
        state = {"trials": trials, "stages": [], "alive": list(trials)}
    fixed = {}
    for pair in args.set:
        name, _, value = pair.partition("=")
        fixed[name] = json.loads(value)

    for stage in range(len(state["stages"]), args.stages):
        steps = int(args.steps * 3 ** stage)
        alive = state["alive"]
        print(f"Stufe {stage + 1}: {len(alive)} Läufe bis {steps:,} Züge", flush=True)
        jobs = []
        for name in alive:
            run = os.path.join(args.dir, name)
            os.makedirs(run, exist_ok=True)
            cfg = {**DEFAULTS, "lr_final": DEFAULTS["lr"], "ent_coef_final": DEFAULTS["ent_coef"], **state["trials"][name],
                   **fixed, "workers": args.workers, "total_steps": steps, "seed": args.seed,
                   "eval_every": 10 ** 9, "checkpoint_every": 20}
            cfg_path = os.path.join(run, "tune-config.json")
            json.dump(cfg, open(cfg_path, "w"), indent=1)
            cmd = [sys.executable, "train.py", run, "--config", cfg_path]
            if os.path.exists(os.path.join(run, "latest.pt")):
                cmd.append("--resume")
            jobs.append((name, cmd, os.path.join(run, "train.log")))
        failed = run_trials(jobs, args.parallel)
        alive = [n for n in alive if n not in failed]
        nets = {n: os.path.join(args.dir, n, "latest.pt") + "@ema" for n in alive}
        from tz import evaluate as ev
        res = ev.league([nets[n] for n in alive], rounds=args.eval_rounds, seed=5 + stage, jobs=args.parallel,
                        anchors=args.anchor)
        calc = {n: res[nets[n]]["calc"] for n in alive}
        worst = {n: res[nets[n]]["worst"] for n in alive}
        duel = {n: res[nets[n]]["duel"] for n in alive}
        score = {n: res[nets[n]]["score"] for n in alive}
        ranking = sorted(alive, key=lambda n: score[n])
        state["stages"].append({"steps": steps, "calc": calc, "worst": worst, "duel": duel, "score": score,
                                "failed": failed, "detail": {n: res[nets[n]] for n in alive}})
        state["alive"] = ranking[:max(1, (len(ranking) + 2) // 3)]
        print("  Lauf   Wertung   Stile   schlechtester Stil   Netze")
        for n in ranking:
            print(f"  {n}   {score[n]:.3f}    {calc[n]:.3f}   {worst[n]:.3f}                {duel[n]:.3f}   {json.dumps(state['trials'][n])}")
        best = {**state["trials"][ranking[0]], **fixed}
        json.dump(state, open(state_path, "w"), indent=1)
        json.dump(best, open(os.path.join(args.dir, "best.json"), "w"), indent=1)
        print(f"  beste Einstellung bisher: {ranking[0]} → {os.path.join(args.dir, 'best.json')}", flush=True)
    print("Fertig. Für den langen Lauf: Absenkung von Lernrate und Entropie wieder einschalten, z. B. "
          "--set lr_final=<lr/3> --set ent_coef_final=<ent/3>.")


if __name__ == "__main__":
    main()
