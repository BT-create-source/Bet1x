"""
Build the cricket pricing model from Cricsheet's free ball-by-ball data.

    python php-backend/tools/build_cricket_model.py [--cache DIR]

Downloads (once, into --cache) the Cricsheet JSON archives for men's international T20s, the major
T20 leagues and ODIs (open data, ODC-BY licence — https://cricsheet.org), and writes
php-backend/data/cricket-model.json, which lib/odds-engine.php reads at runtime. PHP never needs this
script or numpy; re-run it every few months to fold in new matches.

What it learns, per format (T20, ODI):

  E[b][w], SD[b][w]   runs still to come in an innings with b legal balls left and w wickets down
                      (from FIRST innings only, where nothing truncates the innings early)
  chase               logistic model of P(chasing side wins | runs needed, balls left, wickets down),
                      on the single feature z = (E[b][w] - needed) / SD[b][w], fitted by IRLS
  milestone[m]        mean/sd runs from the current state to the end of over m (6/10/15/20 or 10..50)
  over[o][w]          mean/sd runs in over o, given w wickets down when it starts

and reports calibration (Brier score and a reliability table) on a held-out 20% of matches, so the
accuracy claim is measured, not asserted.
"""

import argparse, io, json, math, os, random, sys, urllib.request, zipfile
import numpy as np

SOURCES = {
    'T20': ['t20s_male_json.zip', 'ipl_male_json.zip', 'bbl_male_json.zip', 'psl_male_json.zip', 'cpl_male_json.zip'],
    'ODI': ['odis_male_json.zip'],
}
MAX_BALLS = {'T20': 120, 'ODI': 300}
MILESTONES = {'T20': [6, 10, 15, 20], 'ODI': [10, 20, 30, 40, 50]}
OUT = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'data', 'cricket-model.json')


def fetch(name, cache):
    os.makedirs(cache, exist_ok=True)
    path = os.path.join(cache, name)
    if not os.path.exists(path):
        print('downloading', name, file=sys.stderr)
        urllib.request.urlretrieve('https://cricsheet.org/downloads/' + name, path)
    return path


def matches(fmt, cache):
    """Yield (match_id, innings_list, winner, team_order) for clean, full-length, decided matches."""
    for name in SOURCES[fmt]:
        with zipfile.ZipFile(fetch(name, cache)) as z:
            for fn in z.namelist():
                if not fn.endswith('.json'):
                    continue
                try:
                    m = json.loads(z.read(fn))
                except Exception:
                    continue
                info = m.get('info', {})
                if int(info.get('overs', 0) or 0) != MAX_BALLS[fmt] // 6:
                    continue
                out = info.get('outcome', {})
                if 'method' in out or out.get('result') == 'no result':
                    continue                       # rain-adjusted or no result: not clean evidence
                inns = [i for i in m.get('innings', []) if not i.get('super_over')]
                if len(inns) < 2:
                    continue
                if any('target' in i and int(i['target'].get('overs', MAX_BALLS[fmt] // 6)) != MAX_BALLS[fmt] // 6 for i in inns):
                    continue                       # reduced-overs chase
                winner = out.get('winner')         # None for a tie
                if winner is None and out.get('result') != 'tie':
                    continue
                yield fn, inns[:2], winner


def ball_states(innings):
    """Per legal-or-not delivery: (balls_bowled_before, wkts_before, runs_before, runs_this, legal, wkt)."""
    seq = []
    balls = wk = runs = 0
    for ov in innings.get('overs', []):
        for d in ov.get('deliveries', []):
            ex = d.get('extras', {})
            legal = not ('wides' in ex or 'noballs' in ex)
            r = d['runs']['total']
            w = sum(1 for x in d.get('wickets', []) if x.get('kind') not in ('retired hurt', 'retired not out'))
            seq.append((balls, wk, runs, r, legal, w))
            runs += r
            wk += w
            if legal:
                balls += 1
    return seq, runs, balls, wk


def smooth_table(sum_, sq, n, max_b, prior_n=25):
    """Mean/SD per (balls_left, wkts) with shrinkage toward the wicket-neighbour and ball-neighbour cells."""
    mean = np.zeros_like(sum_); sd = np.zeros_like(sum_)
    # pass 1: raw where well-populated, aggregated over wickets where not
    agg_n = n.sum(axis=1); agg_mean = np.divide(sum_.sum(axis=1), np.maximum(agg_n, 1))
    for b in range(max_b + 1):
        for w in range(10):
            k = n[b, w]
            raw = sum_[b, w] / k if k else agg_mean[b]
            m = (k * raw + prior_n * agg_mean[b] * (1 - 0.06 * w)) / (k + prior_n)
            mean[b, w] = m
            var = (sq[b, w] / k - raw * raw) if k > 1 else (0.35 * max(m, 1)) ** 2
            sd[b, w] = math.sqrt(max(var, 1.0))
    # pass 2: smooth along balls, enforce monotonicity (more balls -> more runs; more wickets down -> fewer)
    for w in range(10):
        mean[:, w] = np.convolve(np.pad(mean[:, w], 3, mode='edge'), np.ones(7) / 7, mode='valid')
        sd[:, w] = np.convolve(np.pad(sd[:, w], 3, mode='edge'), np.ones(7) / 7, mode='valid')
        mean[:, w] = np.maximum.accumulate(mean[:, w])
    for b in range(max_b + 1):
        mean[b, :] = np.minimum.accumulate(mean[b, :])
    mean[0, :] = 0; sd[0, :] = 1
    return mean, sd


def irls(x, y, iters=25):
    """Logistic regression y ~ sigmoid(a + c*x) by Newton/IRLS. y may be 0.5 for ties."""
    X = np.column_stack([np.ones_like(x), x])
    beta = np.zeros(2)
    for _ in range(iters):
        p = 1 / (1 + np.exp(-(X @ beta)))
        W = p * (1 - p) + 1e-9
        H = X.T @ (X * W[:, None])
        g = X.T @ (y - p)
        beta += np.linalg.solve(H + 1e-6 * np.eye(2), g)
    return beta


def build(fmt, cache):
    maxb = MAX_BALLS[fmt]
    all_m = list(matches(fmt, cache))
    random.Random(7).shuffle(all_m)
    cut = int(len(all_m) * 0.8)
    train, test = all_m[:cut], all_m[cut:]
    print(fmt, 'matches:', len(all_m), 'train', len(train), 'test', len(test), file=sys.stderr)

    S = np.zeros((maxb + 1, 10)); Q = np.zeros((maxb + 1, 10)); N = np.zeros((maxb + 1, 10))
    over_s = np.zeros((maxb // 6, 10)); over_q = np.zeros((maxb // 6, 10)); over_n = np.zeros((maxb // 6, 10))
    ms = {m: [np.zeros((m * 6, 10)), np.zeros((m * 6, 10)), np.zeros((m * 6, 10))] for m in MILESTONES[fmt]}

    for _, inns, _w in train:
        for idx, inn in enumerate(inns):
            seq, total, balls, wk = ball_states(inn)
            # runs at the end of each over, and wickets at each over start
            runs_after_ball = {}
            for (b, w, r, rr, legal, wkt) in seq:
                if legal:
                    runs_after_ball[b + 1] = r + rr
            if idx == 0:
                seen = set()
                for (b, w, r, rr, legal, wkt) in seq:
                    if (b, w) in seen or w > 9:
                        continue
                    seen.add((b, w))
                    left = total - r
                    S[maxb - b, w] += left; Q[maxb - b, w] += left * left; N[maxb - b, w] += 1
            # per-over and milestone tables use both innings, but only overs actually completed
            state_at_ball = {}
            for (b, w, r, rr, legal, wkt) in seq:
                state_at_ball.setdefault(b, (w, r))
            for o in range(min(balls // 6, maxb // 6)):
                if o * 6 in state_at_ball and (o + 1) * 6 in runs_after_ball:
                    w0, r0 = state_at_ball[o * 6]
                    if w0 > 9: continue
                    runs = runs_after_ball[(o + 1) * 6] - r0
                    over_s[o, w0] += runs; over_q[o, w0] += runs * runs; over_n[o, w0] += 1
            for m, (ss, qq, nn) in ms.items():
                end = m * 6
                if end not in runs_after_ball:
                    continue
                fin = runs_after_ball[end]
                for b0 in range(end):
                    if b0 in state_at_ball:
                        w0, r0 = state_at_ball[b0]
                        if w0 > 9: continue
                        d = fin - r0
                        ss[b0, w0] += d; qq[b0, w0] += d * d; nn[b0, w0] += 1

    E, SD = smooth_table(S, Q, N, maxb)

    def table(s, q, n, prior=20):
        mean = np.zeros_like(s); sd = np.zeros_like(s)
        agg = np.divide(s.sum(axis=1), np.maximum(n.sum(axis=1), 1))
        for i in range(s.shape[0]):
            for w in range(10):
                k = n[i, w]
                raw = s[i, w] / k if k else agg[i]
                mean[i, w] = (k * raw + prior * agg[i] * (1 - 0.05 * w)) / (k + prior)
                var = (q[i, w] / k - raw * raw) if k > 1 else (0.5 * max(mean[i, w], 1)) ** 2
                sd[i, w] = math.sqrt(max(var, 1.0))
            mean[i, :] = np.minimum.accumulate(mean[i, :])
        return mean, sd

    over_mean, over_sd = table(over_s, over_q, over_n)
    milestone = {}
    for m, (ss, qq, nn) in ms.items():
        mm, sdd = table(ss, qq, nn)
        milestone[str(m)] = {'mean': np.round(mm, 2).tolist(), 'sd': np.round(sdd, 2).tolist()}

    def chase_rows(data):
        xs, ys = [], []
        for _, inns, winner in data:
            seq1, total1, _, _ = ball_states(inns[0])
            target = total1 + 1
            batting2 = inns[1].get('team')
            y = 0.5 if winner is None else (1.0 if winner == batting2 else 0.0)
            seq2, _, _, _ = ball_states(inns[1])
            last = None
            for (b, w, r, rr, legal, wkt) in seq2:
                if (b, w) == last or w > 9:
                    continue
                last = (b, w)
                need = target - r
                left = maxb - b
                if need <= 0 or left <= 0:
                    continue
                xs.append((E[left, w] - need) / SD[left, w]); ys.append(y)
        return np.array(xs), np.array(ys)

    xtr, ytr = chase_rows(train)
    a, c = irls(xtr, ytr)

    # Held-out calibration of the full model: P(chasing side wins) at every ball of every test chase,
    # and P(batting-first side wins) at every ball of every test first innings.
    def p_chase(need, left, w):
        if need <= 0: return 1.0
        if left <= 0 or w >= 10: return 0.0
        return 1 / (1 + math.exp(-(a + c * (E[left, w] - need) / SD[left, w])))

    qz = np.array([-1.75, -1.15, -0.67, -0.32, 0.0, 0.32, 0.67, 1.15, 1.75])
    qw = np.array([0.06, 0.09, 0.12, 0.13, 0.20, 0.13, 0.12, 0.09, 0.06]); qw = qw / qw.sum()

    def p_first(r, left, w):
        tot = r + E[left, w] + qz * SD[left, w]
        return float(np.sum(qw * np.array([1 - p_chase(int(round(t)) + 1, maxb, 0) for t in tot])))

    preds, outs = [], []
    for _, inns, winner in test:
        t1 = inns[0].get('team')
        y1 = 0.5 if winner is None else (1.0 if winner == t1 else 0.0)
        seq1, total1, _, _ = ball_states(inns[0])
        for i, (b, w, r, rr, legal, wkt) in enumerate(seq1):
            if i % 6 or w > 9: continue
            preds.append(p_first(r, maxb - b, w)); outs.append(y1)
        target = total1 + 1
        seq2, _, _, _ = ball_states(inns[1])
        for i, (b, w, r, rr, legal, wkt) in enumerate(seq2):
            if i % 6 or w > 9: continue
            preds.append(1 - p_chase(target - r, maxb - b, w)); outs.append(y1)
    preds = np.array(preds); outs = np.array(outs)
    brier = float(np.mean((preds - outs) ** 2))
    base = float(np.mean((np.full_like(outs, outs.mean()) - outs) ** 2))
    bins = []
    for lo in np.arange(0, 1, 0.1):
        sel = (preds >= lo) & (preds < lo + 0.1)
        if sel.sum() > 50:
            bins.append({'predicted': round(float(preds[sel].mean()), 3), 'actual': round(float(outs[sel].mean()), 3), 'n': int(sel.sum())})
    print(fmt, 'chase logit a=%.3f c=%.3f  Brier %.4f (no-skill %.4f)' % (a, c, brier, base), file=sys.stderr)
    for row in bins:
        print('   predicted %.2f  actual %.2f  (n=%d)' % (row['predicted'], row['actual'], row['n']), file=sys.stderr)

    return {
        'max_balls': maxb,
        'matches': len(all_m),
        'E': np.round(E, 2).tolist(),
        'SD': np.round(SD, 2).tolist(),
        'chase': {'a': round(float(a), 4), 'c': round(float(c), 4)},
        'over': {'mean': np.round(over_mean, 2).tolist(), 'sd': np.round(over_sd, 2).tolist()},
        'milestones': MILESTONES[fmt],
        'milestone': milestone,
        'calibration': {'brier': round(brier, 4), 'no_skill_brier': round(base, 4), 'reliability': bins,
                        'test_matches': len(test)},
    }


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--cache', default=os.path.join(os.path.expanduser('~'), '.cricsheet-cache'))
    args = ap.parse_args()
    model = {'source': 'Cricsheet (cricsheet.org), ODC-BY', 'formats': {}}
    for fmt in ('T20', 'ODI'):
        model['formats'][fmt] = build(fmt, args.cache)
    os.makedirs(os.path.dirname(OUT), exist_ok=True)
    with open(OUT, 'w') as f:
        json.dump(model, f, separators=(',', ':'))
    print('wrote', os.path.abspath(OUT), os.path.getsize(OUT), 'bytes', file=sys.stderr)


if __name__ == '__main__':
    main()
