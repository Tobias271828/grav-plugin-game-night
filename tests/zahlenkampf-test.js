/*
 * Tests der Zahlenkampf-Spiellogik (assets/js/zahlenkampf-logic.js) mit gezielt gebauten Stellungen.
 * Aufruf: node tests/zahlenkampf-test.js   (Exit-Code 0 = alles in Ordnung)
 */
'use strict';

const ZK = require('../assets/js/zahlenkampf-logic.js');

let checks = 0, failures = 0;
function check(ok, what, detail) {
    checks++;
    if (!ok) {
        failures++;
        console.log(`  ✗ ${what}` + (detail !== undefined ? ' — ' + JSON.stringify(detail) : ''));
    }
}

/** Partie mit den angegebenen Steinen: [Seite, Form, Wert, Reihe, Spalte, Teile?]. */
function position(stones, turn = 0) {
    const st = ZK.initState();
    st.stones = stones.map(([side, shape, v, r, c, parts], id) => ({ id, side, shape, v, r, c, ...(parts ? { parts } : {}) }));
    st.turn = [turn];
    return st;
}
const find = (st, v, side) => st.stones.find(s => s.v === v && s.side === side);
const move = (st, v, r, c) => ZK.applyMove(st, st.turn[0], find(st, v, st.turn[0]).id, r, c);
const has = (st, v, side) => st.stones.some(s => s.v === v && s.side === side);
// Damit die Gegenseite noch ziehen kann (sonst „kann nicht mehr ziehen“): Runde weit weg
const SPARE = [[1, 'rd', 7, 15, 3], [0, 'rd', 3, 0, 4]];

console.log('• Aufstellung und Züge');
{
    const st = ZK.initState();
    check(st.stones.length === 48, '48 Steine', st.stones.length);
    check(ZK.TOTAL[0] === 1312 && ZK.TOTAL[1] === 1752, 'Summen: Gerade 1312, Ungerade 1752', ZK.TOTAL);
    check(ZK.GOAL[0].n === 12 && ZK.GOAL[0].sum === 1314 && ZK.GOAL[1].sum === 984, 'Ziele: 12 Steine, ¾ der gegnerischen Summe', ZK.GOAL);
    const rows = side => [...new Set(st.stones.filter(s => s.side === side).map(s => s.r))].sort((a, b) => a - b);
    check(JSON.stringify(rows(0)) === '[0,1,2,3]' && JSON.stringify(rows(1)) === '[12,13,14,15]', 'Aufstellung wie in Fulkes Bild (hintere vier Reihen)', [rows(0), rows(1)]);
    check(ZK.value(find(st, 91, 0)) === 91 && ZK.value(find(st, 190, 1)) === 190, 'Pyramiden 91 und 190');
    check(!ZK.findCaptures(st, 0).length && !ZK.findCaptures(st, 1).length, 'zu Beginn fällt nichts (auch nicht durch eigene Nachbarn eingeschlossen)');
    check(ZK.zone(7) === 0 && ZK.zone(8) === 1, 'zwei Lager à 8 × 8');
}
{
    const st = position([[0, 'rd', 4, 5, 3], [0, 'tri', 20, 8, 3], [0, 'sq', 15, 8, 6], [0, 'rd', 2, 9, 3], ...SPARE]);
    const targets = v => ZK.movesFor(st, find(st, v, 0)).map(m => `${m.r},${m.c}`).sort();
    check(JSON.stringify(targets(4)) === JSON.stringify(['4,2', '4,4', '6,2', '6,4']), 'Runde: 1 Feld diagonal', targets(4));
    const tri = targets(20);
    check(tri.includes('8,1') && tri.includes('8,5') && tri.includes('6,3') && !tri.includes('10,3'), 'Dreieck: 2 Felder gerade, nicht über einen Stein', tri);
    check(tri.includes('9,1') && tri.includes('7,5') && !tri.includes('10,4') && !tri.includes('10,2'),
        'Dreieck fliegt wie der Springer: erst 2 gerade (Weg und Eckfeld frei), dann 1 quer', tri);
    const sq = targets(15);
    check(sq.includes('5,6') && sq.includes('11,6') && sq.includes('5,5') && sq.includes('11,7') && !sq.includes('8,3'),
        'Quadrat: 3 Felder gerade oder 3 gerade + 1 quer, nie auf besetzte Felder', sq);
    check(!sq.includes('7,3') && !sq.includes('9,3'), 'Quadrat fliegt nicht, wenn das Eckfeld des langen Schritts besetzt ist', sq);
}

console.log('• Schlagen');
{
    let st = position([[0, 'rd', 36, 7, 3], [1, 'tri', 36, 9, 5], ...SPARE]);
    st = move(st, 36, 8, 4);
    check(st && st.taken[0].n === 1 && st.taken[0].sum === 36 && !has(st, 36, 1), 'Gleichheit schlägt ohne zu ziehen', st && st.log);
    check(st && st.stones.length === 3, 'geschlagene Steine verlassen das Brett (keine Gefangenen)', st && st.stones.length);
}
{
    let st = position([[0, 'rd', 16, 7, 1], [0, 'tri', 20, 9, 5], [1, 'sq', 36, 9, 3], ...SPARE]);
    st = move(st, 16, 8, 2);
    check(st && st.log[0].caps[0] && st.log[0].caps[0].why.t === 'add', 'Addition (16 + 20 = 36)', st && st.log[0]);
    for (const [a, b, target, kind] of [[72, 8, 9, 'div'], [25, 16, 9, 'sub'], [8, 6, 48, 'mul']]) {
        let s2 = position([[0, 'tri', a, 9, 1], [0, 'rd', b, 7, 3], [1, 'sq', target, 9, 3], ...SPARE]);
        s2 = ZK.applyMove(s2, 0, s2.stones[1].id, 8, 4);
        check(s2 && s2.log[0].caps.length === 1 && s2.log[0].caps[0].why.t === kind, `${kind} (${a}, ${b} → ${target})`, s2 && s2.log[0]);
    }
}
{
    // Erst fällt der gegnerische Stein, dann (falls die Regel noch gilt) der eigene
    let st = position([[0, 'rd', 4, 6, 6], [1, 'rd', 4, 8, 6], ...SPARE]);
    st = move(st, 4, 7, 7);   // Gerade 4 zieht diagonal neben die Ungerade 4 – beide erreichen sich
    check(st && !has(st, 4, 1) && has(st, 4, 0), 'beide könnten sich schlagen: zuerst fällt der Stein der Gegenseite, der eigene bleibt', st && st.log);
    st = position([[0, 'rd', 9, 7, 3], [1, 'rd', 9, 9, 5], [1, 'rd', 3, 9, 3], [1, 'tri', 6, 8, 6], ...SPARE]);
    // Gerade 9 zieht nach (8,4): schlägt die Ungerade 9 (Gleichheit); danach könnte Ungerade (3 und 6 erreichen 8,4) 3 + 6 = 9 schlagen
    st = move(st, 9, 8, 4);
    check(st && !has(st, 9, 1) && !has(st, 9, 0) && st.log[0].lost.length === 1, 'danach fällt der eigene Stein, wenn die Regel noch gilt', st && st.log[0]);
    check(st && st.taken[1].n === 1 && st.taken[1].sum === 9, '… und zählt für die Gegenseite', st && st.taken);
}
{
    // Einschließen: alle Zielfelder besetzt oder außerhalb, mindestens eines von der Gegenseite
    // Runde 5 von Ungerade in der Ecke (15,0): Zielfelder (14,1) und drei außerhalb → eine gegnerische Runde auf (14,1) genügt
    let st = position([[1, 'rd', 5, 15, 0], [0, 'rd', 2, 13, 2], [0, 'rd', 3, 0, 4], [1, 'rd', 7, 15, 6]]);
    st = move(st, 2, 14, 1);
    check(st && st.log[0].caps.some(c => c.why.t === 'obl'), 'Einschließen mit Brettrand', st && st.log[0]);
    st = position([[1, 'rd', 5, 15, 0], [1, 'rd', 3, 14, 1], ...SPARE]);
    check(!ZK.findCaptures(st, 0).length, 'nur eigene Steine und Rand: nicht eingeschlossen');
    // Dreieck: die vier Felder in 2 Abstand
    st = position([[1, 'tri', 99, 8, 4], [0, 'rd', 2, 6, 4], [0, 'rd', 4, 10, 4], [0, 'rd', 6, 8, 2], [0, 'rd', 8, 7, 5], ...SPARE]);
    st = move(st, 8, 8, 6);
    check(st && st.log[0].caps.some(c => c.why.t === 'obl'), 'Dreieck eingeschlossen (Felder in 2 Abstand)', st && st.log[0]);
}
{
    let st = position([[0, 'tri', 36, 6, 3], [0, 'rd', 2, 6, 6], [1, 'rd', 3, 7, 3], [1, 'sq', 36, 8, 3], ...SPARE]);
    st = move(st, 2, 7, 7);
    check(st && !st.captured.length, 'versperrter Weg: kein Schlag');
}
{
    // Pyramide: ganz nie durch Gleichheit, ganz durch eine Rechenart, Teile auch durch Gleichheit
    const parts = () => [{ shape: 'sq', v: 64 }, { shape: 'sq', v: 49 }, { shape: 'tri', v: 36 }, { shape: 'tri', v: 25 }, { shape: 'rd', v: 16 }];
    let st = position([[0, 'rd', 190, 7, 3], [1, 'P', 190, 9, 5, parts()], ...SPARE]);
    st = move(st, 190, 8, 4);
    check(st && st.stones.some(s => s.shape === 'P' && ZK.value(s) === 190), 'ganze Pyramide fällt nicht durch Gleichheit', st && st.log[0]);
    check(st && st.log[0].lost.length === 1, '… schlägt aber ihrerseits die 190 (sie erreicht sie diagonal)', st && st.log[0]);
    st = position([[0, 'rd', 100, 7, 3], [0, 'tri', 90, 9, 1], [1, 'P', 190, 9, 3, parts()], ...SPARE]);
    st = move(st, 100, 8, 4);
    check(st && !st.stones.some(s => s.shape === 'P') && st.taken[0].n === 1 && st.taken[0].sum === 190, 'Pyramide fällt ganz (100 + 90 = 190), zählt als ein Stein', st && st.taken);
    st = position([[0, 'rd', 36, 7, 3], [1, 'P', 190, 9, 5, parts()], ...SPARE]);
    st = move(st, 36, 8, 4);
    const p = st && st.stones.find(s => s.shape === 'P');
    check(p && ZK.value(p) === 154 && !p.parts.some(x => x.v === 36), 'ein Teil fällt durch Gleichheit (36), die Zahl sinkt auf 154', st && st.log[0]);
    check(st && st.taken[0].n === 0 && st.taken[0].sum === 36, 'ein Teil zählt mit seinem Wert, aber nicht als Stein', st && st.taken);
    const p2 = position([[1, 'P', 25, 8, 4, [{ shape: 'tri', v: 25 }]], ...SPARE]).stones[0];
    check(JSON.stringify(ZK.movesFor(position([[1, 'P', 25, 8, 4, [{ shape: 'tri', v: 25 }]], ...SPARE], 1), p2).filter(m => Math.abs(m.r - 8) + Math.abs(m.c - 4) === 2).map(m => m.r - 8 + ',' + (m.c - 4)).sort())
        === JSON.stringify(['-2,0', '0,-2', '0,2', '2,0']), 'Pyramide zieht nur noch wie ihre verbliebenen Formen');
}

console.log('• Fliegen');
{
    let st = position([[0, 'tri', 36, 6, 3], [1, 'tri', 36, 9, 5], ...SPARE]);
    check(ZK.movesFor(st, find(st, 36, 0)).some(m => m.r === 7 && m.c === 5), 'fliegender Zug, nach dem etwas fällt, ist erlaubt');
    st = move(st, 36, 7, 5);
    check(st && !has(st, 36, 1) && st.taken[0].n === 1, 'fliegend angekommen schlägt (Gleichheit)', st && st.log);
    // fliegend erreichbare Felder zählen beim Schlagen: Dreieck 20 bei (5,3) erreicht (7,4) wie der Springer
    st = position([[0, 'tri', 20, 5, 3], [1, 'rd', 20, 7, 4], ...SPARE]);
    st = move(st, 3, 1, 5);
    check(st && !has(st, 20, 1), 'Fliegen zählt als Reichweite (Springer-Feld)', st && st.log);
    st = position([[0, 'tri', 20, 5, 3], [0, 'rd', 2, 6, 3], [1, 'rd', 20, 7, 4], ...SPARE]);
    st = move(st, 3, 1, 5);
    check(st && has(st, 20, 1), 'versperrter langer Schritt: fliegend nicht erreichbar, kein Schlag', st && st.log);
}
{
    // Einschließen zählt nur die geraden Zielfelder, nicht die fliegenden
    const st = position([[1, 'tri', 99, 8, 4], [0, 'rd', 2, 6, 4], [0, 'rd', 4, 10, 4], [0, 'rd', 6, 8, 2], [0, 'rd', 8, 8, 6], ...SPARE]);
    check(ZK.findCaptures(st, 0).some(c => c.why.t === 'obl'), 'Einschließen: Springerfelder müssen nicht besetzt sein');
}

console.log('• Triumph und Sieg');
{
    // Endsteine eigene, Mitte darf gegnerisch sein, alles im gegnerischen Lager, Werte bilden eine Progression
    let st = position([[0, 'rd', 4, 12, 2], [1, 'rd', 6, 12, 3], [0, 'rd', 8, 13, 5], [1, 'rd', 3, 15, 0], [0, 'rd', 3, 0, 7]]);
    st = move(st, 8, 12, 4);
    check(st && st.over && st.winner === 0 && st.end.type === 'triumph' && st.end.kind === 'arith' && st.end.values.join() === '4,6,8',
        'Triumph: 4 – 6 – 8, gegnerischer Stein in der Mitte', st && st.end);
    st = position([[0, 'rd', 4, 12, 2], [0, 'rd', 6, 12, 3], [1, 'rd', 8, 12, 4], [1, 'rd', 3, 15, 0], [0, 'rd', 3, 0, 7]]);
    st = move(st, 3, 1, 6);
    check(st && !st.over, 'kein Triumph, wenn ein Endstein dem Gegner gehört', st && st.end);
    st = position([[0, 'rd', 4, 7, 2], [0, 'rd', 6, 7, 3], [0, 'rd', 8, 6, 5], [1, 'rd', 3, 15, 0], [0, 'rd', 3, 0, 7]]);
    st = move(st, 8, 7, 4);
    check(st && !st.over, 'kein Triumph im eigenen Lager', st && st.end);
    st = position([[0, 'rd', 4, 12, 2], [0, 'rd', 6, 12, 3], [0, 'rd', 8, 13, 5], [1, 'P', 4, 15, 0, [{ shape: 'rd', v: 4 }]], [0, 'rd', 3, 0, 7]]);
    st = move(st, 8, 12, 4);
    check(st && !st.over, 'kein Triumph, solange die gegnerische Pyramide steht');
    st = position([[0, 'rd', 2, 12, 1], [0, 'rd', 4, 12, 2], [1, 'rd', 6, 12, 3], [0, 'rd', 8, 13, 5], [1, 'rd', 3, 15, 0], [0, 'rd', 3, 0, 7]]);
    st = move(st, 8, 12, 4);
    check(st && st.over && st.end.values.join() === '2,4,6,8', 'längere Reihe: 2 – 4 – 6 – 8 zusammen', st && st.end);
    st = position([[0, 'rd', 2, 12, 1], [0, 'rd', 4, 12, 2], [1, 'rd', 6, 12, 3], [0, 'rd', 10, 13, 5], [1, 'rd', 3, 15, 0], [0, 'rd', 3, 0, 7]]);
    st = move(st, 10, 12, 4);
    check(st && !st.over, '2, 4, 6, 10 ist keine Progression (und 2, 4, 6 endet gegnerisch)', st && st.end);
    st = position([[0, 'rd', 4, 12, 2], [0, 'rd', 6, 12, 3], [0, 'tri', 8, 10, 5], [1, 'rd', 3, 15, 0], [0, 'rd', 3, 0, 7]]);
    st = move(st, 8, 12, 4);
    check(st && st.over && st.end.type === 'triumph', 'Triumph auch mit fliegend angekommenem Stein', st && st.end);
    const P = ZK.progression;
    check(P([3, 4, 6]) === 'harm' && P([12, 6, 4, 3]) === 'harm' && P([2, 4, 8, 16]) === 'geo' && P([8, 2, 6, 4]) === 'arith',
        'Progressionen: harmonisch, geometrisch, arithmetisch (Reihenfolge egal)');
    check(P([2, 4, 7]) === null && P([4, 4, 4]) === null && P([2, 4]) === null, 'keine Progression: 2, 4, 7 / gleiche Werte / nur zwei');
}
{
    let st = position([[0, 'rd', 36, 7, 3], [1, 'tri', 36, 9, 5], ...SPARE]);
    st.taken[0] = { n: 11, sum: 0 };
    st = move(st, 36, 8, 4);
    check(st && st.over && st.end.type === 'bodies' && st.winner === 0, 'Sieg nach Körpern ab 12 Steinen', st && st.end);
    st = position([[0, 'rd', 36, 7, 3], [1, 'tri', 36, 9, 5], ...SPARE]);
    st.taken[0] = { n: 0, sum: 1300 };
    st = move(st, 36, 8, 4);
    check(st && st.over && st.end.type === 'goods' && st.end.sum === 1336, 'Sieg nach Gütern ab ¾ der gegnerischen Summe (1314)', st && st.end);
    st = position([[0, 'rd', 9, 7, 3], [1, 'rd', 9, 9, 5], [1, 'rd', 3, 9, 3], [1, 'tri', 6, 8, 6], ...SPARE]);
    st.taken[1] = { n: 11, sum: 0 };
    st = move(st, 9, 8, 4);
    check(st && st.over && st.winner === 1 && st.end.type === 'bodies', 'die Gegenseite kann durch den Gegenschlag gewinnen', st && st.end);
    st = position([[0, 'rd', 36, 7, 3], [1, 'tri', 36, 9, 5], ...SPARE], 1);
    check(!move(st, 36, 8, 4), 'wer nicht dran ist, kann nicht ziehen');
}

console.log(`\n${checks} Prüfungen, ` + (failures ? `${failures} FEHLGESCHLAGEN` : 'alle bestanden'));
process.exit(failures ? 1 : 0);
