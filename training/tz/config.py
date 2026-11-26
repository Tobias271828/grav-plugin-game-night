"""Voreinstellungen des Trainings. Jeder Wert lässt sich mit --set name=wert überschreiben."""

import json

DEFAULTS = {
    # Netz
    "hidden": [256, 256],       # 128/128 ist auf dem Server etwa ein Drittel schneller, spielt aber messbar schwächer
    "value_hidden": 64,
    # Hilfen nur fürs Training (siehe model.py); der Server merkt davon nichts
    "priv_critic": False,       # Wertkopf sieht die verdeckten Karten
    "aux_coef": 0.0,            # Gewicht des Nebenkopfs „Gebot wahr? Karten der anderen?“ (0 = aus)
    "ema_decay": 0.98,          # gleitendes Mittel der Gewichte je Iteration (liegt in jedem Zwischenstand als "ema")
    # Spiele: Gewichte der Personenzahlen 2..8 (werden auf Summe 1 gebracht). Kleine Tische zählen stärker:
    # 3 bis 5 Personen je 16,7 %, 2 und 6 bis 8 je 8,3 %. Zu dritt gibt es zwei Varianten (32 und 52 Karten, je
    # zur Hälfte); damit jede so oft vorkommt wie der Vierer- oder Fünfertisch, hat die 3 das doppelte Gewicht.
    "n_probs": [1, 4, 2, 2, 1, 1, 1],
    # Lernsignal: -1 je verlorener Runde, zusätzlich -game_penalty für den Spielverlust
    "game_penalty": 2.0,
    "gamma_round": 0.97,        # Abzinsung über eine Rundengrenze
    "gae_lambda": 0.95,
    # Gegner-Pool je Platz: lernendes Netz, Netz aus dem Pool (ältere Stände, leicht veränderte Gewichte), Rechner-Bot
    "p_self": 0.5,
    "p_pool": 0.25,             # Rest: Rechner-Bots
    "pool_slots": 4,            # so viele Pool-Netze spielen gleichzeitig mit
    "pool_perturbed": 0.35,     # Anteil der Pool-Netze, die das aktuelle Netz mit verrauschten Gewichten sind
    "perturb_sigma": 0.05,      # Rauschen relativ zur Streuung der Gewichte einer Schicht
    "pool_recent": 0.5,         # so oft kommt ein Pool-Netz aus den jüngsten 20 % der Zwischenstände
    "snapshot_every": 10,       # alle so viele Iterationen ein Zwischenstand in den Pool
    "pool_max": 60,             # ältere Zwischenstände werden ausgedünnt
    "p_calc_table": 0.2,        # Anteil der Spiele als Rechner-Tisch: ein lernender Platz, sonst nur Rechner-Bots
    "calc_table_same": 0.6,     # davon so oft alle Bots im selben Stil (sonst jeder Platz ein eigener)
    "calc_styles": None,        # None = alle Stile aus bots.STYLES
    "calc_jitter": 0.08,        # Streuung der Stil-Zahlen pro Platz
    # Glaubwürdigkeit: Vorwerte und ihr Gewicht
    "cred_mu": [0.38, 0.32, 0.35, 0.18, 0.55, 0.45],   # siehe features.CRED_MU; messen mit evaluate.py stats
    "cred_k": 5.0,
    # Vortraining: Züge der Rechner-Bots nachahmen (0 = aus, dann beginnt das Netz bei null)
    "bc_iters": 3000,
    "bc_games": 1024,
    "bc_lr": 1e-3,
    "value_warmup": 5,          # so viele Iterationen lernt danach zuerst nur der Wertkopf
    # Rollouts
    "threads": None,            # Rechenkerne für den Lernschritt (None = alle übrigen, höchstens 8)
    "workers": 4,               # Prozesse für die Simulation (0 = im Hauptprozess)
    "games_per_worker": 256,
    "rollout_steps": 128,
    # PPO
    "lr": 3e-4,
    "lr_final": 1e-4,           # lineare Absenkung bis zum Ende
    "epochs": 3,
    "minibatch": 2048,
    "clip": 0.2,
    "ent_coef": 0.01,
    "ent_coef_final": 0.003,
    "vf_coef": 0.5,
    "max_grad_norm": 0.5,
    "target_kl": 0.03,          # Epoche abbrechen, wenn die Strategie zu weit springt
    # Ablauf
    "total_steps": 200_000_000, # Züge insgesamt (alle Plätze)
    "eval_every": 20,           # alle so viele Iterationen gegen Rechner-Bots messen
    "eval_rounds": 20000,
    "checkpoint_every": 20,
    "seed": 1,
    "init_from": None,          # Pfad eines Netzes: mit dessen Gewichten beginnen statt mit dem Vortraining
    "frozen_opponent": None,    # Pfad eines Netzes: nur gegen dieses eingefrorene Netz trainieren (Durchschaubarkeit)
}


def parse_value(text):
    try:
        return json.loads(text)
    except json.JSONDecodeError:
        return text


def apply_overrides(cfg, pairs):
    for pair in pairs or []:
        name, _, value = pair.partition("=")
        if name not in DEFAULTS:
            raise SystemExit(f"Unbekannte Einstellung: {name}")
        cfg[name] = parse_value(value)
    return cfg
