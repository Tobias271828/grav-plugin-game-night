"""PPO-Self-Play: Rollouts (optional in mehreren Prozessen), Lernschritt, Pool, Zwischenstände."""

import copy
import csv
import json
import multiprocessing as mp
import os
import time

import numpy as np
import torch

from . import bots, evaluate
from .arena import Arena
from .model import make_net, masked_logits


def _load_nets(cfg, state, pool_states):
    net = make_net(cfg)
    net.load_state_dict(state)
    pool = []
    for ps in pool_states:
        p = make_net(cfg)
        p.load_state_dict(ps)
        pool.append(p)
    return net, pool


def _frozen_assigner(arena, idx):
    """Durchschaubarkeit: ein lernender Platz, alle anderen spielt das eingefrorene Netz (Pool-Netz 1)."""
    m = len(idx)
    kind = np.ones((m, 8), np.int64)
    kind[np.arange(m), (arena.rng.random(m) * arena.sim.n[idx]).astype(np.int64)] = 0
    arena.kind[idx] = kind


def _worker(conn, cfg, seed):
    torch.set_num_threads(1)
    arena = Arena(cfg, seed, assigner=_frozen_assigner if cfg["frozen_opponent"] else None)
    while True:
        msg = conn.recv()
        if msg is None:
            break
        state, pool_states, steps = msg
        net, pool = _load_nets(cfg, state, pool_states)
        conn.send(arena.rollout(net, pool, steps))


class Rollouts:
    """Verteilt Rollouts auf Prozesse; jeder Prozess behält seine Spiele über die Iterationen."""

    def __init__(self, cfg):
        self.cfg = cfg
        self.procs, self.conns, self.local = [], [], None
        if cfg["workers"] <= 0:
            self.local = Arena(cfg, cfg["seed"] * 1000, assigner=_frozen_assigner if cfg["frozen_opponent"] else None)
            return
        ctx = mp.get_context("spawn")
        for w in range(cfg["workers"]):
            parent, child = ctx.Pipe()
            p = ctx.Process(target=_worker, args=(child, cfg, cfg["seed"] * 1000 + w), daemon=True)
            p.start()
            self.procs.append(p)
            self.conns.append(parent)

    def run(self, net, pool):
        steps = self.cfg["rollout_steps"]
        if self.local is not None:
            results = [self.local.rollout(net, pool, steps)]
        else:
            state = {k: v.clone() for k, v in net.state_dict().items()}
            pool_states = [p.state_dict() for p in pool]
            for c in self.conns:
                c.send((state, pool_states, steps))
            results = [c.recv() for c in self.conns]
        batch = {k: np.concatenate([r[0][k] for r in results]) for k in results[0][0]}
        stats = {k: sum(r[1][k] for r in results) for k in results[0][1]}
        return batch, stats

    def close(self):
        for c in self.conns:
            c.send(None)
        for p in self.procs:
            p.join(timeout=5)


def perturbed(net, sigma, gen):
    """Kopie des Netzes mit leicht veränderten Gewichten (Rauschen relativ zur Streuung je Schicht)."""
    other = copy.deepcopy(net)
    with torch.no_grad():
        for p in other.parameters():
            p.add_(torch.randn(p.shape, generator=gen) * sigma * (p.std() if p.numel() > 1 else 1.0))
    return other


def _calc_assigner(arena, idx):
    arena.kind[idx] = -1
    arena.params[idx], arena.style[idx] = bots.sample_params(arena.rng, (len(idx), 8), arena.cfg.get("calc_styles"), arena.cfg["calc_jitter"])


def pretrain(net, cfg, log):
    """Vortraining: das Netz ahmt die Züge der Rechner-Bots nach (gemischte Stile), bevor das Self-Play beginnt.

    Ohne das spielt ein frisches Netz zunächst fast zufällige, viel zu hohe Gebote und braucht lange, bis es
    überhaupt sinnvolle Runden sieht. Menschliche Spiele werden nicht verwendet. bc_iters = 0 schaltet es ab.
    """
    arena = Arena(cfg, cfg["seed"] * 1000 + 999, n_games=cfg["bc_games"], assigner=_calc_assigner)
    sim, g = arena.sim, np.arange(arena.B)
    opt = torch.optim.Adam(net.parameters(), lr=cfg["bc_lr"])
    acc, loss_sum = 0.0, 0.0
    for i in range(1, cfg["bc_iters"] + 1):
        x, mask = sim.observe()
        act = bots.act(sim, g, mask, arena.params[g, sim.turn], arena.rng)
        logits, _ = net(torch.from_numpy(x))
        logp = torch.log_softmax(masked_logits(logits, torch.from_numpy(mask)), dim=-1)
        target = torch.from_numpy(act)
        loss = -logp.gather(1, target[:, None]).mean()
        opt.zero_grad()
        loss.backward()
        opt.step()
        acc += float((logp.argmax(1) == target).float().mean())
        loss_sum += loss.item()
        done = np.flatnonzero(sim.step(act)["over"])
        if len(done):
            arena._assign(done)
        if i % 500 == 0:
            log(f"Vortraining {i}/{cfg['bc_iters']}: Verlust {loss_sum / 500:.3f}, Treffer {acc / 500:.3f}")
            acc, loss_sum = 0.0, 0.0


def ppo_update(net, opt, batch, cfg, ent_coef, policy=True):
    obs = torch.from_numpy(batch["obs"])
    mask = torch.from_numpy(batch["mask"])
    act = torch.from_numpy(batch["act"])
    logp_old = torch.from_numpy(batch["logp"])
    adv = torch.from_numpy(batch["adv"])
    ret = torch.from_numpy(batch["ret"])
    priv = torch.from_numpy(batch["priv"]) if "priv" in batch else None
    aux_coef = cfg.get("aux_coef", 0) if net.aux is not None and priv is not None else 0
    if aux_coef:
        true = torch.from_numpy(batch["true"]).float()
        has_bid = obs[:, 43] > 0                                     # Merkmal „aktuelles Gebot vorhanden“
        others = priv.view(-1, 7, 14).sum(1)                         # Karten der anderen je Rang / 4, Joker / 2
    adv = (adv - adv.mean()) / (adv.std() + 1e-8)
    n = len(act)
    out = {"pi_loss": 0.0, "v_loss": 0.0, "entropy": 0.0, "kl": 0.0, "clipfrac": 0.0, "aux": 0.0}
    updates = 0
    for _ in range(cfg["epochs"]):
        perm = torch.randperm(n)
        stop = False
        for i in range(0, n, cfg["minibatch"]):
            idx = perm[i:i + cfg["minibatch"]]
            pv = priv[idx] if net.priv and priv is not None else None
            if aux_coef:
                logits, v, ax = net(obs[idx], value_only=not policy, priv=pv, aux=True)
            else:
                logits, v = net(obs[idx], value_only=not policy, priv=pv)
            logp_all = torch.log_softmax(masked_logits(logits, mask[idx]), dim=-1)
            logp = logp_all.gather(1, act[idx][:, None]).squeeze(1)
            ratio = torch.exp(logp - logp_old[idx])
            a = adv[idx]
            pi_loss = -torch.min(ratio * a, torch.clamp(ratio, 1 - cfg["clip"], 1 + cfg["clip"]) * a).mean()
            v_loss = 0.5 * (v - ret[idx]).pow(2).mean()
            entropy = -(logp_all.exp() * logp_all.clamp(min=-50)).sum(-1).mean()
            loss = cfg["vf_coef"] * v_loss + ((pi_loss - ent_coef * entropy) if policy else 0.0)
            if aux_coef:
                hb = has_bid[idx]
                bce = torch.nn.functional.binary_cross_entropy_with_logits(ax[:, 0][hb], true[idx][hb]) if hb.any() else 0.0
                aux_loss = bce + 10.0 * (ax[:, 1:] - others[idx]).pow(2).mean()
                loss = loss + aux_coef * aux_loss
                out["aux"] += aux_loss.item()
            opt.zero_grad()
            loss.backward()
            torch.nn.utils.clip_grad_norm_(net.parameters(), cfg["max_grad_norm"])
            opt.step()
            kl = float((logp_old[idx] - logp).mean().detach())
            updates += 1
            out["pi_loss"] += pi_loss.item(); out["v_loss"] += v_loss.item(); out["entropy"] += entropy.item()
            out["kl"] += kl; out["clipfrac"] += ((ratio - 1).abs() > cfg["clip"]).float().mean().item()
            if policy and cfg["target_kl"] and kl > 1.5 * cfg["target_kl"]:
                stop = True
                break
        if stop:
            break
    return {k: v / max(updates, 1) for k, v in out.items()}


def ratio(stats, lost="lost", fair="fair", ns=range(2, 9)):
    """Verluste der lernenden Plätze geteilt durch ihren fairen Anteil (1 = so gut wie die anderen, kleiner ist besser)."""
    f = sum(stats[fair][n] for n in ns)
    return float(sum(stats[lost][n] for n in ns) / f) if f else float("nan")


def train(cfg, run_dir, resume=False, log=lambda *a: print(*a, flush=True)):
    os.makedirs(run_dir, exist_ok=True)
    torch.manual_seed(cfg["seed"])
    torch.set_num_threads(cfg.get("threads") or max(1, min(8, (os.cpu_count() or 2) - cfg["workers"])))
    net = make_net(cfg)
    opt = torch.optim.Adam(net.parameters(), lr=cfg["lr"], eps=1e-5)
    gen = torch.Generator().manual_seed(cfg["seed"] + 7)
    rng = np.random.default_rng(cfg["seed"] + 11)
    it, steps_done, snapshots, ema = 0, 0, [], None
    latest = os.path.join(run_dir, "latest.pt")
    if resume and os.path.exists(latest):
        ck = torch.load(latest, weights_only=False)
        net.load_state_dict(ck["net"])
        opt.load_state_dict(ck["opt"])
        it, steps_done, snapshots = ck["iter"], ck["steps"], ck["snapshots"]
        ema = ck.get("ema")
        log(f"Fortsetzung bei Iteration {it}, {steps_done:,} Züge")
    else:
        with open(os.path.join(run_dir, "config.json"), "w") as fh:
            json.dump(cfg, fh, indent=1)
        if cfg.get("init_from"):
            net.load_state_dict(evaluate.load_spec(cfg["init_from"]).state_dict())
            log(f"Start mit den Gewichten aus {cfg['init_from']}")
        elif cfg["bc_iters"] and not cfg["frozen_opponent"]:
            pretrain(net, cfg, log)
    if not (resume and os.path.exists(latest)) or ema is None:
        ema = {k: v.clone() for k, v in net.state_dict().items()}
    frozen = None
    if cfg["frozen_opponent"]:
        frozen = evaluate.load_spec(cfg["frozen_opponent"])   # auch „…pt@T0.5“
        t = getattr(frozen, "temperature", 1.0)
        if t != 1.0:                 # Temperatur in die letzte Schicht einrechnen (die Prozesse bekommen nur Gewichte)
            with torch.no_grad():
                frozen.pi.weight.div_(t)
                frozen.pi.bias.div_(t)
            frozen.temperature = 1.0
    rollouts = Rollouts(cfg)
    per_iter = max(1, cfg["workers"]) * cfg["games_per_worker"] * cfg["rollout_steps"]
    log_path = os.path.join(run_dir, "log.csv")
    fields = ["iter", "steps", "hours", "sps", "ratio", "game_ratio", "ratio_calc", "entropy", "kl", "v_loss",
              "clipfrac", "challenge_rate", "samples", "pool"]
    if not (resume and os.path.exists(log_path)):
        with open(log_path, "w", newline="") as fh:
            csv.writer(fh).writerow(fields)
    t_start = time.time()
    hours0 = 0.0
    if resume and os.path.exists(latest):
        hours0 = ck.get("hours", 0.0)
    pool = []
    warmup = cfg["value_warmup"] if cfg["bc_iters"] and not cfg["frozen_opponent"] and not cfg.get("init_from") else 0
    try:
        while steps_done < cfg["total_steps"]:
            frac = min(1.0, steps_done / cfg["total_steps"])
            lr = cfg["lr"] + (cfg["lr_final"] - cfg["lr"]) * frac
            ent = cfg["ent_coef"] + (cfg["ent_coef_final"] - cfg["ent_coef"]) * frac
            for group in opt.param_groups:
                group["lr"] = lr
            # Pool-Netze dieser Iteration: ältere Zwischenstände oder das aktuelle Netz mit verrauschten Gewichten
            if frozen is not None:
                pool = [frozen]
            else:
                pool = []
                for _ in range(cfg["pool_slots"]):
                    if not snapshots or rng.random() < cfg["pool_perturbed"]:
                        pool.append(perturbed(net, cfg["perturb_sigma"], gen))
                    else:
                        recent = max(1, len(snapshots) // 5)
                        j = len(snapshots) - 1 - rng.integers(0, recent) if rng.random() < cfg["pool_recent"] else rng.integers(0, len(snapshots))
                        p = make_net(cfg)
                        p.load_state_dict(snapshots[j])
                        pool.append(p)
            t0 = time.time()
            batch, stats = rollouts.run(net, pool)
            # nach dem Vortraining lernt zuerst nur der Wertkopf, sonst zerstören verrauschte Vorteile die Strategie
            info = ppo_update(net, opt, batch, cfg, ent, policy=it >= warmup)
            it += 1
            steps_done += per_iter
            # gleitendes Mittel der Gewichte: glättet das Hin und Her der letzten Iterationen (siehe export.py --ema)
            d = cfg.get("ema_decay", 0.0) if it > warmup else 0.0
            for k, v in net.state_dict().items():
                ema[k].mul_(d).add_(v, alpha=1 - d) if v.dtype.is_floating_point else ema[k].copy_(v)
            row = {
                "iter": it, "steps": steps_done, "hours": round(hours0 + (time.time() - t_start) / 3600, 3),
                "sps": int(per_iter / (time.time() - t0)), "ratio": round(ratio(stats), 4),
                "game_ratio": round(ratio(stats, "glost", "gfair"), 4), "ratio_calc": "",
                "entropy": round(info["entropy"], 4), "kl": round(info["kl"], 5), "v_loss": round(info["v_loss"], 4),
                "clipfrac": round(info["clipfrac"], 3),
                "challenge_rate": round(float(stats["challenges"].sum() / max(1, stats["decisions"].sum())), 4),
                "samples": len(batch["act"]), "pool": len(snapshots),
            }
            if it % cfg["snapshot_every"] == 0 and frozen is None:
                snapshots.append({k: v.clone() for k, v in net.state_dict().items()})
                if len(snapshots) > cfg["pool_max"]:
                    # ausdünnen: jeden zweiten der älteren Hälfte entfernen
                    half = len(snapshots) // 2
                    snapshots = snapshots[:half:2] + snapshots[half:]
            if it % cfg["eval_every"] == 0:
                res = evaluate.vs_calc(net, cfg, rounds=cfg["eval_rounds"], seed=it)
                row["ratio_calc"] = round(res["mean_ratio"], 4)
            with open(log_path, "a", newline="") as fh:
                csv.writer(fh).writerow([row[f] for f in fields])
            log(" ".join(f"{k}={row[k]}" for k in fields if row[k] != ""))
            if it % cfg["checkpoint_every"] == 0 or steps_done >= cfg["total_steps"]:
                ck = {"net": net.state_dict(), "opt": opt.state_dict(), "iter": it, "steps": steps_done,
                      "snapshots": snapshots, "cfg": cfg, "hours": row["hours"], "ema": ema}
                torch.save(ck, latest + ".tmp")
                os.replace(latest + ".tmp", latest)
                torch.save({"net": net.state_dict(), "ema": ema, "cfg": cfg, "iter": it, "steps": steps_done},
                           os.path.join(run_dir, f"net_{it:05d}.pt"))
    finally:
        rollouts.close()
    return net
