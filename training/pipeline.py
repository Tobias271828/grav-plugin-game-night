"""TromboneZero von Anfang bis Ende: prüfen, Einstellungen suchen, lange trainieren, auswählen, ausliefern.

  python pipeline.py runs/t0                     alles mit den Vorgaben (rund anderthalb Tage)
  python pipeline.py runs/t0                     … derselbe Befehl setzt nach einem Abbruch dort fort
  python pipeline.py runs/t0 --quick             Probedurchlauf mit winzigen Budgets (einige Minuten; das Netz taugt
                                                 nichts und wird nicht nach core/ geschrieben)
  python pipeline.py runs/t0 --no-tune           ohne Suche: der lange Lauf nimmt die Vorgaben aus tz/config.py
  python pipeline.py runs/t0 --redo auswahl      ab dieser Stufe neu (Stufen siehe unten). Fertige Trainingsläufe in
                                                 suche/ und training/ bleiben dabei erhalten; wer sie wirklich neu
                                                 rechnen will, löscht den Ordner

Im Repository liegt nichts Erzeugtes außer dem fertigen Netz core/trombone-net.bin. Alles andere entsteht hier:

  pruefen   Tests des Trainingscodes; Referenzspiele und Testnetz für die PHP-Tests (tests/ref/, nicht im
            Repository) neu erzeugen; PHP gegen Python abgleichen
  probe     kurzer Lauf: misst die Geschwindigkeit des Rechners und schätzt die Dauer der übrigen Stufen
  suche     Kandidaten mit zufälligen Einstellungen trainieren und in drei Stufen auslesen (tune.py)
  training  der lange Lauf mit der besten Einstellung (train.py); legt laufend Zwischenstände ab
  auswahl   Liga der letzten Zwischenstände (gegen alle Rechner-Bot-Stile und gegeneinander), auch mit
            gemittelten Gewichten (@ema); danach die Temperatur beim Spielen wählen
  bericht   Abnahme gegen den Rechner-Bot „standard“, alle Stile, Nutzen der Zähler, Self-Play-Statistik
  angriff   Durchschaubarkeit: ein Angreifer trainiert nur gegen das eingefrorene Endnetz
  export    core/trombone-net.bin schreiben, mit PHP abgleichen, PHP-Tests

Der Ordner (hier runs/t0) enthält danach: pipeline.json (Stand und Ergebnisse aller Stufen), pipeline.log,
suche/, training/, angriff/ und bericht.json. Die Rechner-Bots, gegen die trainiert und gemessen wird, brauchen
kein Training (tz/bots.py); „trainierte Testgegner“ sind die Kandidaten der Suche und die Zwischenstände.
"""

import argparse
import csv
import glob
import json
import math
import os
import shlex
import shutil
import subprocess
import sys
import time

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(HERE)
STAGES = ["pruefen", "probe", "suche", "training", "auswahl", "bericht", "angriff", "export"]


class Pipeline:
    def __init__(self, args):
        self.a = args
        self.dir = os.path.abspath(args.dir)
        os.makedirs(self.dir, exist_ok=True)
        self.state_path = os.path.join(self.dir, "pipeline.json")
        self.state = json.load(open(self.state_path)) if os.path.exists(self.state_path) else {}
        self.logfile = open(os.path.join(self.dir, "pipeline.log"), "a", encoding="utf-8")
        self.php = shlex.split(args.php) if shutil.which(shlex.split(args.php)[0]) else None

    # ---------------------------------------------------------------- Hilfen

    def say(self, text=""):
        print(text, flush=True)
        self.logfile.write(text + "\n")
        self.logfile.flush()

    def run(self, cmd, cwd=HERE, check=True):
        """Befehl ausführen; die Ausgabe erscheint hier und in pipeline.log."""
        self.say("$ " + " ".join(shlex.quote(c) for c in cmd))
        proc = subprocess.Popen(cmd, cwd=cwd, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True, errors="replace")
        tail = []
        for line in proc.stdout:
            self.say("  " + line.rstrip("\n"))
            tail = (tail + [line])[-5:]
        if proc.wait() != 0 and check:
            raise SystemExit(f"Abbruch: Befehl endete mit Fehler {proc.returncode}. Nach dem Beheben denselben "
                             "pipeline.py-Aufruf wiederholen – fertige Stufen bleiben erhalten.")
        return proc.returncode

    def py(self, *args, **kw):
        return self.run([sys.executable, *args], **kw)

    def save(self, stage, result):
        self.state[stage] = {"fertig": time.strftime("%Y-%m-%d %H:%M"), **result}
        with open(self.state_path, "w") as fh:
            json.dump(self.state, fh, indent=1)

    def sets(self, extra=()):
        """Feste Einstellungen für jeden Trainingslauf als --set-Argumente."""
        out = []
        for pair in [*self.a.set, *extra]:
            out += ["--set", pair]
        return out

    def league(self, specs, seed):
        from tz import evaluate as ev
        res = ev.league(specs, rounds=self.a.rounds, seed=seed, jobs=self.a.jobs)
        names = list(res)
        self.say("  Wertung   Stile   schlechtester   Netze   Netz")
        for n in sorted(names, key=lambda n: res[n]["score"]):
            r = res[n]
            self.say(f"  {r['score']:.3f}     {r['calc']:.3f}   {r['worst']:.3f}           {r['duel']:.3f}   {os.path.relpath(n, self.dir)}")
        return res

    # ---------------------------------------------------------------- Stufen

    def pruefen(self):
        self.py("-m", "tests.test_core")
        self.py("gen_refs.py")
        if self.php:
            self.run([*self.php, os.path.join(ROOT, "tests", "bot-test.php")], cwd=ROOT)
        else:
            self.say("  Hinweis: php nicht gefunden – der Abgleich mit PHP entfällt (auch beim Export).")
        return {"php": bool(self.php)}

    def probe(self):
        run = os.path.join(self.dir, "probe")
        shutil.rmtree(run, ignore_errors=True)
        self.py("train.py", run, *self.sets([f"total_steps={int(self.a.probe_steps)}", f"workers={self.a.workers}",
                                              "eval_every=1000000000", "checkpoint_every=1000000000"]))
        rows = list(csv.DictReader(open(os.path.join(run, "log.csv"))))
        sps = float(rows[-1]["sps"])
        a = self.a
        tune = 0 if a.no_tune else sum(a.tune_steps * 3 ** s * max(1, math.ceil(a.tune_trials / 3 ** s)) for s in range(a.tune_stages))
        # Die Suche läuft mit --parallel Läufen zu je einem Simulationsprozess; grob so schnell wie ein voller Lauf
        hours = {"suche": tune / sps / 3600, "training": a.final_steps / sps / 3600,
                 "angriff": 0 if a.no_exploit else a.exploit_steps / sps / 3600}
        self.say(f"  {sps:,.0f} Züge pro Sekunde. Grobe Dauer: Suche {hours['suche']:.1f} h, langer Lauf "
                 f"{hours['training']:.1f} h, Angriff {hours['angriff']:.1f} h; dazu Messungen (1–3 h).")
        shutil.rmtree(run, ignore_errors=True)
        return {"sps": sps, "stunden": hours}

    def suche(self):
        if self.a.no_tune:
            self.say("  übersprungen (--no-tune): Der lange Lauf nimmt die Vorgaben.")
            return {"config": {}, "grund": "ohne Suche"}
        d = os.path.join(self.dir, "suche")
        cmd = ["tune.py", d, "--trials", str(self.a.tune_trials), "--steps", str(self.a.tune_steps),
               "--stages", str(self.a.tune_stages), "--parallel", str(self.a.parallel), "--workers", "1",
               "--eval-rounds", str(max(2000, self.a.rounds // 2)), *self.sets()]
        if not any(pair.startswith("hidden=") for pair in self.a.set):
            from tz.config import DEFAULTS      # die Netzgröße steht fest (auf dem Webspace gemessen), nicht mitsuchen
            cmd += ["--set", "hidden=" + json.dumps(DEFAULTS["hidden"])]
        if os.path.exists(os.path.join(d, "results.json")):
            cmd.append("--resume")
        self.py(*cmd)
        res = json.load(open(os.path.join(d, "results.json")))
        score = res["stages"][-1]["score"]
        best = min(score, key=score.get)
        # Liegt der Sieger nur knapp vor den Vorgaben (t00), ist das Rauschen – dann bei den Vorgaben bleiben
        if best == "t00" or ("t00" in score and score["t00"] - score[best] < 0.02):
            self.say(f"  Bester Lauf {best} ({score[best]:.3f}) liegt nicht klar vor den Vorgaben – es bleibt bei den Vorgaben.")
            return {"config": {}, "grund": f"{best} nicht klar besser als t00", "wertung": score}
        cfg = dict(res["trials"][best])
        cfg["lr_final"] = cfg["lr"] / 3            # für den langen Lauf die Absenkung wieder einschalten
        cfg["ent_coef_final"] = cfg["ent_coef"] / 3
        self.say(f"  Beste Einstellung: {best} ({score[best]:.3f}) → {json.dumps(cfg)}")
        return {"config": cfg, "grund": f"{best} gewinnt die Suche", "wertung": score}

    def training(self):
        from tz.config import DEFAULTS, apply_overrides
        run = os.path.join(self.dir, "training")
        if os.path.exists(os.path.join(run, "latest.pt")):
            self.py("train.py", run, "--resume")
        else:
            cfg = {**self.state.get("suche", {}).get("config", {})}
            merged = {**DEFAULTS, **cfg}
            apply_overrides(merged, self.a.set)
            per_iter = max(1, self.a.workers) * merged["games_per_worker"] * merged["rollout_steps"]
            iters = max(1, int(self.a.final_steps // per_iter))
            os.makedirs(run, exist_ok=True)
            cfg_path = os.path.join(run, "pipeline-config.json")
            json.dump(cfg, open(cfg_path, "w"), indent=1)
            # rund 100 Zwischenstände über den ganzen Lauf (je etwa 2 MB)
            self.py("train.py", run, "--config", cfg_path,
                    *self.sets([f"total_steps={int(self.a.final_steps)}", f"workers={self.a.workers}",
                                f"checkpoint_every={max(1, iters // (12 if self.a.quick else 100))}"]))
        nets = sorted(glob.glob(os.path.join(run, "net_*.pt")))
        if len(nets) < 2:
            raise SystemExit("Abbruch: Der lange Lauf hat weniger als zwei Zwischenstände geschrieben.")
        return {"zwischenstaende": len(nets), "letzter": nets[-1]}

    def auswahl(self):
        nets = sorted(glob.glob(os.path.join(self.dir, "training", "net_*.pt")))[-self.a.candidates:]
        self.say(f"  Runde 1: die letzten {len(nets)} Zwischenstände")
        r1 = self.league(nets, seed=11)
        top = sorted(nets, key=lambda n: r1[n]["score"])[:3]
        self.say("  Runde 2: die besten drei, je mit normalen und mit gemittelten Gewichten (@ema)")
        r2 = self.league([*top, *[n + "@ema" for n in top]], seed=12)
        best = min(r2, key=lambda n: r2[n]["score"])
        temps = [1.0, 0.7, 0.5, 0.3]
        self.say(f"  Runde 3: Temperatur für {os.path.relpath(best, self.dir)}")
        specs = {t: f"{best}@T{t:g}" for t in temps}
        r3 = self.league(list(specs.values()), seed=13)
        score = {t: r3[s]["score"] for t, s in specs.items()}
        t_best = min(score, key=score.get)
        if score[0.5] - score[t_best] < 0.005:      # im Rauschen: die erprobte Empfehlung
            t_best = 0.5
        path, *opts = best.split("@")
        self.say(f"  Gewählt: {os.path.relpath(path, self.dir)}" + (" mit gemittelten Gewichten" if opts else "")
                 + f", Temperatur {t_best:g}")
        return {"netz": path, "ema": bool(opts), "temperatur": t_best, "spec": specs[t_best],
                "wertung": {"zwischenstaende": {os.path.basename(n): r1[n]["score"] for n in nets},
                            "ema": {os.path.relpath(n, self.dir): r2[n]["score"] for n in r2},
                            "temperatur": {f"{t:g}": v for t, v in score.items()}}}

    def bericht(self):
        out = os.path.join(self.dir, "bericht.json")
        self.py("evaluate.py", "report", self.state["auswahl"]["spec"], "--rounds", str(self.a.rounds), "--out", out)
        rep = json.load(open(out))
        if not rep["accepted"]:
            self.say("  ACHTUNG: Abnahme nicht erfüllt (Verhältnis über 0,8 gegen „standard“ an mindestens einer "
                     "Tischgröße). Das Netz wird trotzdem exportiert – vor dem Ausliefern ansehen.")
        return {"abnahme": bool(rep["accepted"]), "datei": out}

    def angriff(self):
        if self.a.no_exploit:
            self.say("  übersprungen (--no-exploit).")
            return {"verhaeltnis": None}
        sel = self.state["auswahl"]
        run = os.path.join(self.dir, "angriff")
        mark = os.path.join(run, "gegen.txt")       # gegen welches Netz dieser Lauf trainiert
        if os.path.exists(mark) and open(mark).read() != sel["spec"]:
            shutil.rmtree(run)                      # die Auswahl hat sich geändert: von vorn
        if os.path.exists(os.path.join(run, "latest.pt")):
            self.py("train.py", run, "--resume")
        else:
            os.makedirs(run, exist_ok=True)
            open(mark, "w").write(sel["spec"])
            self.py("train.py", run, *self.sets([f"frozen_opponent={sel['spec']}",
                                                  f"init_from={sel['netz']}" + ("@ema" if sel["ema"] else ""),
                                                  f"total_steps={int(self.a.exploit_steps)}", f"workers={self.a.workers}",
                                                  "eval_every=1000000000", "checkpoint_every=1000000000"]))
        rows = [float(r["ratio"]) for r in csv.DictReader(open(os.path.join(run, "log.csv"))) if r["ratio"]]
        late = rows[len(rows) // 2:]
        ratio = sum(late) / len(late)
        self.say(f"  Verhältnis des Angreifers in der zweiten Hälfte: {ratio:.3f} "
                 + ("(über 0,9: schwer auszunutzen)" if ratio > 0.9 else
                    "(unter 0,9: ausnutzbar – höhere Temperatur erwägen oder mit mehr Pool-Gegnern weitertrainieren)"))
        return {"verhaeltnis": ratio}

    def export(self):
        sel = self.state["auswahl"]
        out = os.path.join(self.dir, "trombone-net.bin") if self.a.quick else os.path.join(ROOT, "core", "trombone-net.bin")
        cmd = ["export.py", sel["netz"], "--temperature", f"{sel['temperatur']:g}", "--out", out,
               "--note", f"pipeline.py, {os.path.basename(self.dir)}"]
        if sel["ema"]:
            cmd.append("--ema")
        if not self.php:
            cmd.append("--no-php")
        self.py(*cmd)
        tests = {}
        if self.php and not self.a.quick:
            for t in ("bot-test.php", "api-test.php"):
                tests[t] = self.run([*self.php, os.path.join(ROOT, "tests", t)], cwd=ROOT, check=False) == 0
            if not all(tests.values()):
                self.say("  ACHTUNG: PHP-Tests fehlgeschlagen – siehe Ausgabe oben.")
        self.say(f"  Gewichtsdatei: {out}")
        return {"datei": out, "tests": tests}

    # ---------------------------------------------------------------- Ablauf

    def main(self):
        if self.a.redo:
            for s in STAGES[STAGES.index(self.a.redo):]:
                self.state.pop(s, None)
        for i, stage in enumerate(STAGES, 1):
            if stage in self.state:
                self.say(f"[{i}/{len(STAGES)}] {stage}: schon erledigt ({self.state[stage]['fertig']})")
                continue
            self.say(f"\n[{i}/{len(STAGES)}] {stage}  ({time.strftime('%Y-%m-%d %H:%M')})")
            start = time.time()
            self.save(stage, getattr(self, stage)())
            self.say(f"  fertig nach {(time.time() - start) / 60:.1f} Minuten")
        sel, rep, att = self.state["auswahl"], self.state["bericht"], self.state["angriff"]
        self.say("\nZusammenfassung")
        self.say(f"  Netz:       {os.path.relpath(sel['netz'], self.dir)}" + (" (@ema)" if sel["ema"] else "")
                 + f", Temperatur {sel['temperatur']:g}")
        self.say(f"  Abnahme:    {'erfüllt' if rep['abnahme'] else 'NICHT erfüllt'} (Einzelheiten: {rep['datei']})")
        if att["verhaeltnis"] is not None:
            self.say(f"  Angreifer:  {att['verhaeltnis']:.3f} (über 0,9 = schwer auszunutzen)")
        self.say(f"  Datei:      {self.state['export']['datei']}")
        if self.a.quick:
            self.say("  Das war der Probedurchlauf (--quick): Dieses Netz ist nicht zum Ausliefern gedacht.")
        else:
            self.say("  Danach: Browsertests (tests/e2e/README.md), tools/server-check.php auf dem Webspace, am Tisch "
                     "ausprobieren, core/trombone-net.bin committen.")


def main():
    cores = os.cpu_count() or 4
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("dir", help="Ordner für diesen Durchlauf, z. B. runs/t0")
    ap.add_argument("--quick", action="store_true", help="Probedurchlauf mit winzigen Budgets")
    ap.add_argument("--no-tune", action="store_true", help="ohne Suche nach Einstellungen")
    ap.add_argument("--no-exploit", action="store_true", help="ohne den Angreifer-Lauf")
    ap.add_argument("--redo", choices=STAGES, help="ab dieser Stufe neu rechnen")
    ap.add_argument("--workers", type=int, default=min(6, max(1, cores // 2)), help="Simulationsprozesse des langen Laufs")
    ap.add_argument("--parallel", type=int, default=max(1, cores // 2), help="gleichzeitige Läufe in der Suche")
    ap.add_argument("--jobs", type=int, default=max(1, cores // 2), help="Prozesse beim Messen")
    ap.add_argument("--tune-trials", type=int, default=12)
    ap.add_argument("--tune-steps", type=float, default=20e6, help="Züge je Lauf in der ersten Stufe der Suche")
    ap.add_argument("--tune-stages", type=int, default=3)
    ap.add_argument("--final-steps", type=float, default=3e9, help="Züge des langen Laufs")
    ap.add_argument("--exploit-steps", type=float, default=100e6)
    ap.add_argument("--probe-steps", type=float, default=5e6)
    ap.add_argument("--rounds", type=int, default=60000, help="Runden je Messung")
    ap.add_argument("--candidates", type=int, default=8, help="so viele letzte Zwischenstände kommen in die Auswahl")
    ap.add_argument("--set", action="append", default=[], metavar="name=wert",
                    help="feste Einstellung für alle Trainingsläufe (mehrfach möglich), z. B. hidden=[128,128]")
    ap.add_argument("--php", default="php", help="PHP-Aufruf für die Tests")
    args = ap.parse_args()
    if args.quick:
        args.tune_trials, args.tune_steps, args.tune_stages = 3, 3e5, 2
        args.final_steps, args.exploit_steps, args.probe_steps = 1.5e6, 4e5, 2e5
        args.rounds, args.candidates = 2000, 4
        args.workers, args.parallel = min(args.workers, 2), min(args.parallel, 3)
        args.set = ["bc_iters=150", "games_per_worker=64", "rollout_steps=64", *args.set]
    Pipeline(args).main()


if __name__ == "__main__":
    main()
