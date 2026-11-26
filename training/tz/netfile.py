"""Gewichtsdatei core/trombone-net.bin lesen und schreiben (Format siehe tbot_net_info in TromboneBot.php)."""

import json
import struct

import numpy as np

from .actions import N_ACTIONS
from .features import CRED_K, CRED_MU, FEATURE_VERSION, N_CRED, N_FEATURES

MAGIC = b"TBN1"
FORMAT_VERSION = 2


def write_net(path, layers, mu=CRED_MU, k=CRED_K, meta=None, feature_version=FEATURE_VERSION):
    """layers: [(W [aus, ein], b [aus]), …] als numpy-Felder."""
    sizes = [layers[0][0].shape[1]] + [w.shape[0] for w, _ in layers]
    assert sizes[0] == N_FEATURES and sizes[-1] == N_ACTIONS, sizes
    assert len(mu) == N_CRED, mu
    meta_bytes = json.dumps(meta or {}, ensure_ascii=False, sort_keys=True).encode("utf-8")
    out = [MAGIC, struct.pack("<3H", FORMAT_VERSION, feature_version, len(layers)),
           struct.pack(f"<{len(sizes)}H", *sizes), struct.pack(f"<{1 + N_CRED}f", k, *mu),
           struct.pack("<I", len(meta_bytes)), meta_bytes]
    for w, b in layers:
        assert w.shape[0] == b.shape[0]
        out.append(np.ascontiguousarray(w, dtype="<f4").tobytes())
        out.append(np.ascontiguousarray(b, dtype="<f4").tobytes())
    with open(path, "wb") as fh:
        fh.write(b"".join(out))


def read_net(path):
    with open(path, "rb") as fh:
        data = fh.read()
    assert data[:4] == MAGIC, "keine Trombone-Gewichtsdatei"
    fmt, feat, n_layers = struct.unpack_from("<3H", data, 4)
    assert fmt == FORMAT_VERSION, f"Formatversion {fmt}"
    pos = 10
    sizes = struct.unpack_from(f"<{n_layers + 1}H", data, pos)
    pos += 2 * (n_layers + 1)
    k, *mu = struct.unpack_from(f"<{1 + N_CRED}f", data, pos)
    pos += 4 * (1 + N_CRED)
    (meta_len,) = struct.unpack_from("<I", data, pos)
    pos += 4
    meta = json.loads(data[pos:pos + meta_len].decode("utf-8")) if meta_len else {}
    pos += meta_len
    layers = []
    for i in range(n_layers):
        n_in, n_out = sizes[i], sizes[i + 1]
        w = np.frombuffer(data, dtype="<f4", count=n_in * n_out, offset=pos).reshape(n_out, n_in)
        pos += 4 * n_in * n_out
        b = np.frombuffer(data, dtype="<f4", count=n_out, offset=pos)
        pos += 4 * n_out
        layers.append((w, b))
    assert pos == len(data), "Dateilänge passt nicht"
    return {"layers": layers, "mu": tuple(mu), "k": k, "meta": meta, "feature_version": feat, "sizes": list(sizes)}


def forward(layers, x, dtype=np.float32):
    """Ausgaben des Netzes für Merkmale x ([N, Merkmale] oder [Merkmale])."""
    h = np.asarray(x, dtype=dtype)
    for i, (w, b) in enumerate(layers):
        h = h @ w.T.astype(dtype) + b.astype(dtype)
        if i < len(layers) - 1:
            h = np.maximum(h, 0)
    return h


def policy(layers, x, mask, dtype=np.float32):
    """Verteilung über die Aktionen; ausgeblendete haben 0."""
    logits = forward(layers, x, dtype).astype(np.float64)
    logits = np.where(mask, logits, -np.inf)
    logits -= logits.max(axis=-1, keepdims=True)
    p = np.exp(logits)
    return p / p.sum(axis=-1, keepdims=True)
