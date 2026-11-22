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

/** Leere Partie (Spiel läuft) mit den angegebenen Steinen: [Seite, Form, Wert, Reihe, Spalte, Teile?]. */
function position(stones, settings = { triumph: true, bodies: 0, goods: 0 }, turn = 0) {
    const st = ZK.start(ZK.initState(settings));
    st.stones = stones.map(([side, shape, v, r, c, parts], id) => ({ id, side, shape, v, r, c, fly: false, conv: false, ...(parts ? { parts } : {}) }));
    st.nextId = stones.length;
    st.turn = [turn];
    return st;
}
const find = (st, v, side) => st.stones.find(s => s.v === v && s.side === side);
const move = (st, v, r, c) => ZK.applyMove(st, st.turn[0], find(st, v, st.turn[0]).id, r, c);
// Damit die Gegenseite ziehen kann (sonst „kann nicht mehr ziehen“): Runde weit weg
const SPARE = [[1, 'rd', 7, 15, 0], [0, 'rd', 3, 0, 7]];

console.log('• Aufstellung und Züge');
{
    const st = ZK.start(ZK.initState());
    check(st.stones.length === 48, '48 Steine', st.stones.length);
    const sum = side => st.stones.filter(s => s.side === side).reduce((n, s) => n + ZK.value(s), 0);
    check(sum(0) === 1312 && sum(1) === 1752, 'Summe der Werte: Gerade 1312, Ungerade 1752', [sum(0), sum(1)]);
    check(ZK.value(find(st, 91, 0)) === 91 && ZK.value(find(st, 190, 1)) === 190, 'Könige 91 und 190');
    check(!ZK.findCaptures(st, 0).length && !ZK.findCaptures(st, 1).length, 'zu Beginn fällt nichts');
    check(st.turn[0] === 0, 'Gerade beginnt');
}
{
    const st = position([[0, 'rd', 4, 5, 3], [0, 'tri', 20, 8, 3], [0, 'sq', 15, 8, 6], [0, 'rd', 2, 9, 3], ...SPARE]);
    const targets = v => ZK.movesFor(st, find(st, v, 0)).map(m => `${m.r},${m.c}${m.fly ? 'f' : ''}`).sort();
    check(JSON.stringify(targets(4)) === JSON.stringify(['4,2', '4,4', '6,2', '6,4']), 'Runde: 1 Feld diagonal', targets(4));
    const tri = targets(20);
    check(tri.includes('8,1') && tri.includes('8,5') && tri.includes('6,3') && !tri.includes('10,3'), 'Dreieck: 2 Felder gerade, nicht über einen Stein', tri);
    check(tri.includes('9,1f') && tri.includes('10,4f'), 'Dreieck fliegt wie der Springer (auch über Steine)', tri);
    const sq = targets(15);
    check(sq.includes('5,6') && sq.includes('11,6') && !sq.includes('8,3') && sq.includes('7,3f') && !sq.includes('9,3f'),
        'Quadrat: 3 Felder gerade, fliegt 1 + 3, nie auf besetzte Felder', sq);
}

console.log('• Schlagen');
{
    // Gleichheit: 36 (Runde von Gerade) erreicht diagonal die 36 von Ungerade
    let st = position([[0, 'rd', 36, 7, 3], [1, 'tri', 36, 9, 5], ...SPARE]);
    st = move(st, 36, 8, 4);
    check(st && st.taken[0].n === 1 && !st.stones.some(s => s.side === 1 && s.v === 36), 'Gleichheit schlägt ohne zu ziehen', st && st.log);
    const prisoner = st.stones.find(s => s.side === 0 && s.conv);
    check(prisoner && prisoner.v === 36 && prisoner.shape === 'tri' && prisoner.r === 0, 'Gefangene*r wechselt die Seite und steht hinten im eigenen Lager', prisoner);
}
{
    // Addition: 16 + 20 = 36, beide erreichen das Ziel
    let st = position([[0, 'rd', 16, 7, 1], [0, 'tri', 20, 9, 5], [1, 'sq', 36, 9, 3], ...SPARE]);
    st = move(st, 16, 8, 2);
    check(st && st.log[0].caps[0] && st.log[0].caps[0].why.t === 'add', 'Addition (16 + 20 = 36)', st && st.log[0]);
    // Division, Subtraktion, Multiplikation
    for (const [a, b, target, kind] of [[72, 8, 9, 'div'], [25, 16, 9, 'sub'], [8, 6, 48, 'mul']]) {
        let s2 = position([[0, 'tri', a, 9, 1], [0, 'rd', b, 8, 4], [1, 'sq', target, 9, 3], ...SPARE]);
        s2.stones[1].r = 7; s2.stones[1].c = 3;
        s2 = ZK.applyMove(s2, 0, s2.stones[1].id, 8, 4);
        check(s2 && s2.log[0].caps.length === 1 && s2.log[0].caps[0].why.t === kind, `${kind} (${a}, ${b} → ${target})`, s2 && s2.log[0]);
    }
}
{
    // Einschließen: alle vier Nachbarn eigene Steine
    let st = position([[1, 'tri', 99, 8, 4], [0, 'rd', 2, 7, 4], [0, 'rd', 4, 9, 4], [0, 'rd', 6, 8, 3], [0, 'rd', 8, 9, 6], ...SPARE]);
    st = move(st, 8, 8, 5);
    check(st && st.log[0].caps.some(c => c.why.t === 'obl'), 'Einschließen schlägt', st && st.log[0]);
}
{
    // Nicht in Reichweite (Weg versperrt) → nichts
    let st = position([[0, 'tri', 36, 6, 3], [0, 'rd', 2, 6, 6], [1, 'rd', 3, 7, 3], [1, 'sq', 36, 8, 3], ...SPARE]);
    st = move(st, 2, 7, 7);
    check(st && !st.captured.length, 'versperrter Weg: kein Schlag');
}
{
    // König: nicht durch Gleichheit, aber ganz (Gesamtwert) oder ein Teil
    const king = [{ shape: 'sq', v: 64 }, { shape: 'sq', v: 49 }, { shape: 'tri', v: 36 }, { shape: 'tri', v: 25 }, { shape: 'rd', v: 16 }];
    let st = position([[0, 'rd', 190, 7, 3], [1, 'K', 190, 9, 5, king], ...SPARE]);
    st = move(st, 190, 8, 4);
    check(st && !st.captured.length, 'König fällt nicht durch Gleichheit');
    st = position([[0, 'rd', 100, 7, 3], [0, 'tri', 90, 9, 1], [1, 'K', 190, 9, 3, king.map(p => ({ ...p }))], ...SPARE]);
    st = move(st, 100, 8, 4);
    check(st && st.log[0].caps[0] && st.log[0].caps[0].stone.shape === 'K' && !st.stones.some(s => s.shape === 'K'), 'König fällt ganz (100 + 90 = 190)', st && st.log[0]);
    st = position([[0, 'rd', 4, 7, 3], [0, 'tri', 12, 9, 1], [1, 'K', 190, 9, 3, king.map(p => ({ ...p }))], ...SPARE]);
    st = move(st, 4, 8, 4);
    const k = st && st.stones.find(s => s.shape === 'K');
    check(k && ZK.value(k) === 174 && !k.parts.some(p => p.v === 16), 'König verliert einen Teil (4 + 12 = 16)', st && st.log[0]);
    check(st && st.stones.some(s => s.side === 0 && s.conv && s.v === 16 && s.shape === 'rd'), 'der Königsteil wird Gefangene*r');
}

console.log('• Fliegen, Sieg');
{
    // Fliegend darf man nichts schlagen
    const st = position([[0, 'tri', 36, 6, 3], [1, 'tri', 36, 9, 5], ...SPARE]);
    const flyToCapture = ZK.movesFor(st, find(st, 36, 0)).find(m => m.r === 7 && m.c === 5);
    check(!flyToCapture, 'fliegender Zug, nach dem etwas fiele, ist nicht erlaubt');
}
{
    // Triumph: König weg, drei eigene Steine (nicht fliegend angekommen) in einer Reihe im gegnerischen Lager
    let st = position([[0, 'rd', 4, 12, 2], [0, 'rd', 6, 12, 3], [0, 'rd', 8, 13, 5], [1, 'rd', 3, 15, 0], [0, 'rd', 3, 0, 7]]);
    st = move(st, 8, 12, 4);
    check(st && st.over && st.end.type === 'triumph' && st.end.kinds.includes('arith'), 'Triumph: 4, 6, 8 im gegnerischen Lager', st && st.end);
    st = position([[0, 'rd', 4, 12, 2], [0, 'rd', 6, 12, 3], [0, 'rd', 8, 13, 5], [1, 'K', 91, 15, 0, [{ shape: 'rd', v: 4 }]], [0, 'rd', 3, 0, 7]]);
    st = move(st, 8, 12, 4);
    check(st && !st.over, 'kein Triumph, solange der gegnerische König steht');
    check(JSON.stringify(ZK.proportions([3, 4, 6])) === '["harm"]' && JSON.stringify(ZK.proportions([2, 4, 8])) === '["geo"]', 'harmonische und geometrische Proportion');
}
{
    let st = position([[0, 'rd', 36, 7, 3], [1, 'tri', 36, 9, 5], ...SPARE], { triumph: false, bodies: 1, goods: 0 });
    st = move(st, 36, 8, 4);
    check(st && st.over && st.end.type === 'bodies' && st.winner === 0, 'Sieg nach Körpern', st && st.end);
    st = position([[0, 'rd', 36, 7, 3], [1, 'tri', 36, 9, 5], ...SPARE], { triumph: false, bodies: 0, goods: 30 });
    st = move(st, 36, 8, 4);
    check(st && st.over && st.end.type === 'goods', 'Sieg nach Gütern', st && st.end);
    st = position([[0, 'rd', 36, 7, 3], [1, 'tri', 36, 9, 5], ...SPARE], { triumph: false, bodies: 0, goods: 0 }, 1);
    check(!move(st, 36, 8, 4), 'wer nicht dran ist, kann nicht ziehen');
}

console.log(`\n${checks} Prüfungen, ` + (failures ? `${failures} FEHLGESCHLAGEN` : 'alle bestanden'));
process.exit(failures ? 1 : 0);
