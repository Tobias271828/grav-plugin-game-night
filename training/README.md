# TromboneZero – Training

Hier entsteht das Netz des Trombone-Bots „TromboneZero“ (T0). Trainiert wird lokal in Python auf der CPU,
ausschließlich durch Spiele des Netzes gegen sich selbst, gegen ältere und leicht veränderte Fassungen von
sich und gegen mathematische Rechner-Bots – ohne menschliche Spiele. Der Server bekommt nur die fertige
Gewichtsdatei `core/trombone-net.bin`. Dieser Ordner gehört nicht in die Release-Pakete.

## Bauplan

| | |
| --- | --- |
| Netz | 579 Eingaben → 256 → 256 (ReLU) → 313 Aktionen (0 = Anzweifeln, 1–312 = Gebote); etwa 295 000 Gewichte, Datei 1,1 MB |
| Eingaben | Merkmalsversion 2: Tisch, eigene Hand, Gebote, Rechner ohne und mit den Ansagen der anderen, je Mitspieler Ansagen und sechs Glaubwürdigkeitswerte |
| Harte Regeln | Maske aus dem Rechner: nie ein unmögliches Gebot, nie ein sicher wahres Gebot anzweifeln |
| Training | PPO im Self-Play auf der CPU, ganze Spiele, Personenzahlen 2 bis 8 gemischt |
| Gegner | je Platz das lernende Netz (50 %), ältere oder leicht verrauschte Fassungen (25 %), Rechner-Bots in acht Stilen (25 %); dazu 20 % Rechner-Tische |
| Lernsignal | −1 je verlorener Runde, zusätzlich −2 für den Spielverlust |
| Start | kurzes Nachahmen der Rechner-Bots, keine menschlichen Spiele |
| Auswahl | Liga: jedes Netz gegen alle Stile und gegen jedes andere Netz |
| Auf dem Server | Temperatur (Empfehlung 0,5) beim Export in die letzte Schicht eingerechnet; Auswertung in reinem PHP |

## Einrichten

```sh
cd training
python3 -m venv .venv && . .venv/bin/activate
pip install -r requirements.txt --extra-index-url https://download.pytorch.org/whl/cpu
python gen_refs.py               # erzeugt ../tests/ref/ (liegt nicht im Repository; die Tests brauchen es)
python -m tests.test_core        # etwa 2 Minuten; der Teil „php“ braucht php im Pfad
```

## Die Skripte

| Skript | Zweck |
| --- | --- |
| `pipeline.py` | **Alles in einem Durchlauf:** prüfen, Referenzdateien erzeugen, Einstellungen suchen, langer Lauf, Auswahl (Liga, gemittelte Gewichte, Temperatur), Bericht, Angreifer-Test, Export. Derselbe Befehl setzt nach einem Abbruch fort; `--quick` ist ein Probedurchlauf in wenigen Minuten. |
| `train.py` | Self-Play-Training (PPO). Schreibt `config.json`, `log.csv`, `latest.pt` und Zwischenstände `net_*.pt` in den Laufordner; mit `--resume` fortsetzbar. |
| `tune.py` | Sucht gute Einstellungen: Zufallssuche mit schrittweiser Auslese, Ergebnis `best.json`. |
| `evaluate.py` | Misst Netze: gegen Rechner-Bots aller Stile, Netz gegen Netz, Liga mehrerer Netze (`league`), Nutzen der Zähler, Self-Play-Statistik. |
| `export.py` | Schreibt `core/trombone-net.bin` (wahlweise gemittelte Gewichte, Temperatur) und prüft die Datei gegen PyTorch und gegen PHP. |
| `gen_refs.py` | Erzeugt die Referenzdateien `tests/ref/` für `tests/bot-test.php` (Testnetz mit Zufallsgewichten und Referenzspiele; braucht nur numpy). Sie liegen nicht im Repository; das Ergebnis ist bei jedem Aufruf dasselbe. |

**Erzeugte Dateien:** Im Repository liegt nichts Erzeugtes außer dem fertigen Netz `core/trombone-net.bin`.
`tests/ref/`, `runs/` und `cache/` sind in `.gitignore` eingetragen und entstehen aus dem Code; GitHub Actions
erzeugt `tests/ref/` vor den PHP-Tests selbst. Die Rechner-Bots, gegen die trainiert und gemessen wird, sind
Formeln (`tz/bots.py`) und brauchen kein Training.

Alle Einstellungen stehen mit Erklärung in `tz/config.py`; jede lässt sich mit `--set name=wert` überschreiben.

## Aufbau

| Datei | Inhalt | Gegenstück in PHP |
| --- | --- | --- |
| `tz/actions.py` | Aktionsraum: 0 = Anzweifeln, 1–312 = Gebote | `tbot_bids()` |
| `tz/calc.py` | Wahrscheinlichkeitsrechner, exakt und als Tabelle | `tbot_prob()` |
| `tz/rules.py` | Regeln und Glaubwürdigkeitszähler, Zug für Zug (Referenz) | `tb_act()`, `tb_cred_update()` |
| `tz/features.py` | 579 Netzeingaben und Aktionsmaske (Referenz) | `tbot_features()`, `tbot_mask()` |
| `tz/sim.py` | dasselbe für viele Spiele gleichzeitig (numpy) | – |
| `tz/bots.py` | Rechner-Bots mit Stilen | – |
| `tz/arena.py` | Besetzung der Plätze, Rollouts, Vorteile (GAE) | – |
| `tz/trainer.py` | PPO, Pool älterer und verrauschter Netze, Vortraining | – |
| `tz/evaluate.py` | Messungen | – |
| `tz/netfile.py` | Format der Gewichtsdatei | `tbot_net_info()`, `tbot_net()` |
| `tz/refgames.py` | Referenzspiele erzeugen und nachspielen | `tests/bot-test.php` |

**Wichtig:** `rules.py`, `calc.py` und `features.py` müssen zum PHP-Code passen. Wer dort etwas ändert, ändert
beide Seiten, erhöht bei geänderten Merkmalen `FEATURE_VERSION` (Python) und `TBOT_FEATURE_VERSION` (PHP),
lässt `python gen_refs.py` laufen und danach `python -m tests.test_core` und `php tests/bot-test.php`.
Ein Netz, das auf anderen Regeln oder Merkmalen trainiert wurde, spielt auf dem Server unbemerkt schlechter.

## Wie trainiert wird

- **Spiele:** ganze Spiele bis zum Spielverlust, damit Kartenzahlen und Glaubwürdigkeitszähler so entstehen
  wie am Tisch. Kleine Tische kommen häufiger vor (`n_probs`): der Dreiertisch mit 32 Karten, der Dreiertisch
  mit 52 Karten, der Vierer- und der Fünfertisch je 16,7 %; zu zweit und zu sechst bis acht je 8,3 %.
- **Lernsignal:** −1 für die Person, die eine Runde verliert, zusätzlich −`game_penalty` (Vorgabe 2) für den
  Spielverlust. Innerhalb einer Runde wird nicht abgezinst, über eine Rundengrenze mit `gamma_round`.
- **Gegner pro Platz**, über ein ganzes Spiel fest: das lernende Netz (`p_self`), ein Netz aus dem Pool
  (`p_pool`: ältere Zwischenstände oder das aktuelle Netz mit leicht verrauschten Gewichten) oder ein
  Rechner-Bot mit zufälligem Stil. Gelernt wird nur aus den Zügen der lernenden Plätze.
- **Rechner-Tische** (`p_calc_table`, Vorgabe 20 % der Spiele): ein lernender Platz, sonst nur Rechner-Bots –
  meist alle im selben Stil (`calc_table_same`), sonst jeder Platz in einem anderen. Die übrigen Spiele sind
  ohnehin gemischt (Netze und Rechner-Bots verschiedener Stile an einem Tisch). Erst an Tischen eines Stils
  lernt das Netz, die Glaubwürdigkeitszähler zu nutzen.
- **Nur fürs Training, ohne Kosten auf dem Server** (`tz/model.py`, beide in der Vorgabe aus, weil sie in
  Testläufen nichts brachten): ein Wertkopf, der die verdeckten Karten sieht (`priv_critic`), und ein Nebenkopf,
  der vorhersagt, ob das aktuelle Gebot wahr ist und welche Karten die anderen halten (`aux_coef`).
- **Netzeingaben** (Merkmalsversion 2, Aufbau in `tz/features.py`): eigene Hand, Gebote, der Rechner – einmal
  ohne und einmal mit den Ansagen der anderen als Karten –, je Mitspieler die Ansagen der Runde (höchste, erstes
  und letztes Gebot) und sechs Glaubwürdigkeitswerte aus den aufgedeckten Runden des Spiels: Deckung des ersten,
  zweiten und späterer Gebote, Zweifelquote, Trefferquote, Anteil wahrer angezweifelter Gebote.
- **Rechner-Bots** (`tz/bots.py`): acht Stile, darunter „glaeubig“ (rechnet Ansagen als Karten) sowie „erstbluff“
  und „spaetbluff“ (bluffen nur beim ersten bzw. nur bei späteren Geboten einer Runde) – damit die Zähler im
  Training etwas zu unterscheiden haben.
- **Harte Regeln** gelten im Training wie auf dem Server: nie ein unmögliches Gebot, nie ein sicher wahres
  Gebot anzweifeln (`features.mask`).
- **Vortraining** (`bc_iters`, etwa eine Minute): Das frische Netz ahmt zuerst die Rechner-Bots nach, damit
  es nicht mit völlig zufälligen Geboten beginnt. Mit `--set bc_iters=0` beginnt es wirklich bei null; das
  funktioniert auch, braucht aber am Anfang deutlich mehr Züge.

## Messgrößen

Überall steht das **Verhältnis** = Verlustquote geteilt durch den fairen Anteil (1/n bei einem Platz).
1 heißt: verliert so oft wie alle anderen; kleiner ist besser.

- `ratio` in `log.csv`: lernende Plätze im Trainingsmix (Gegner werden mit der Zeit stärker – die Zahl sagt
  wenig über den Fortschritt).
- `ratio_calc`: ein Platz des Netzes gegen n − 1 Rechner-Bots im Stil „standard“, Mittel über n = 3..8.
  Das ist die Messlatte der Abnahme: **≤ 0,8 für jedes n von 3 bis 8** (`python evaluate.py report`).
- `evaluate.py duel A B`: zwei Netze am selben Tisch – der eigentliche Vergleich zweier Stände.
- `evaluate.py league A B C …`: mehrere Netze auf einmal – jedes gegen alle Stile und gegen jedes andere;
  die Wertung (Mittel aus beidem) benutzt auch `tune.py`. Ein Netz ist eine Datei `net_xxxxx.pt`, wahlweise
  mit `@ema` (gleitendes Mittel der Gewichte) und `@T0.5` (Temperatur: unter 1 spielt das Netz seine
  bevorzugten Züge häufiger).
- **Durchschaubarkeit:** ein frisches Netz nur gegen die eingefrorene Endfassung trainieren
  (`--set frozen_opponent=…/net_xxxxx.pt`); je näher dessen `ratio` an 1 bleibt, desto schwerer ist der Bot
  auszunutzen.
- **Nutzen der Zähler:** `evaluate.py counters` vergleicht echte Zähler mit Zählern auf dem Vorwert.

Der Rechner-Bot „standard“ ist eine schwache Messlatte, und ein Tisch nur aus „glaeubig“-Bots (rechnen die
Ansagen der anderen als Karten und zweifeln kaum) ist ein Sonderfall: Dort verliert jeder Außenseiter, auch das
Netz – umgekehrt verliert „glaeubig“ am Tisch der Netze. Der Bericht misst deshalb gegen alle Stile; was zählt,
ist der Vergleich der Netze untereinander.
