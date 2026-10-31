// A minimal DevTools protocol client on Node's built-in WebSocket (no packages), plus the page helpers the timing
// script needs: evaluate, wait for a condition, and read the long tasks / long animation frames the page recorded.

export const sleep = ms => new Promise(r => setTimeout(r, ms));

export class Cdp {
    constructor(ws) {
        this.ws = ws; this.id = 0; this.calls = new Map(); this.handlers = [];
        ws.addEventListener('message', ev => {
            const msg = JSON.parse(typeof ev.data === 'string' ? ev.data : ev.data.toString());
            if (msg.id && this.calls.has(msg.id)) {
                const {ok, fail} = this.calls.get(msg.id);
                this.calls.delete(msg.id);
                if (msg.error) fail(new Error(msg.error.message + (msg.error.data ? ': ' + msg.error.data : ''))); else ok(msg.result);
            } else if (msg.method) for (const h of this.handlers) h(msg);
        });
    }
    static async connect(url) {
        const ws = new WebSocket(url);
        await new Promise((ok, fail) => { ws.addEventListener('open', ok, {once: true}); ws.addEventListener('error', fail, {once: true}); });
        return new Cdp(ws);
    }
    send(method, params = {}, sessionId) {
        const id = ++this.id;
        this.ws.send(JSON.stringify({id, method, params, ...(sessionId ? {sessionId} : {})}));
        return new Promise((ok, fail) => this.calls.set(id, {ok, fail}));
    }
    on(fn) { this.handlers.push(fn); }
    close() { try { this.ws.close(); } catch (e) { /* already closed */ } }
}

// Recorded in the page before any of its scripts run: every long task (50 ms or more) and every long animation frame
// (the task plus its style, layout and paint), with their start times on the page clock.
export const OBSERVER = `(() => {
    window.__perf = {lt: [], loaf: []};
    try { new PerformanceObserver(l => { for (const e of l.getEntries()) __perf.lt.push([e.startTime, e.duration]); }).observe({type: 'longtask', buffered: true}); } catch (e) {}
    try { new PerformanceObserver(l => { for (const e of l.getEntries()) __perf.loaf.push([e.startTime, e.duration]); }).observe({type: 'long-animation-frame', buffered: true}); } catch (e) {}
})();`;

export class Page {
    constructor(cdp, sessionId) { this.cdp = cdp; this.sid = sessionId; }
    static async open(cdp, {width = 1600, height = 1000} = {}) {
        const {targetId} = await cdp.send('Target.createTarget', {url: 'about:blank'});
        const {sessionId} = await cdp.send('Target.attachToTarget', {targetId, flatten: true});
        const p = new Page(cdp, sessionId);
        p.targetId = targetId;
        await p.send('Page.enable');
        await p.send('Runtime.enable');
        await p.send('Emulation.setDeviceMetricsOverride', {width, height, deviceScaleFactor: 1, mobile: false});
        await p.send('Page.addScriptToEvaluateOnNewDocument', {source: OBSERVER});
        p.errors = [];
        cdp.on(msg => { if (msg.sessionId === sessionId && msg.method === 'Runtime.exceptionThrown') p.errors.push(msg.params.exceptionDetails.exception?.description || msg.params.exceptionDetails.text); });
        // Native dialogs (confirm) are answered yes, as staff would.
        cdp.on(msg => { if (msg.sessionId === sessionId && msg.method === 'Page.javascriptDialogOpening') p.send('Page.handleJavaScriptDialog', {accept: true}).catch(() => {}); });
        return p;
    }
    send(method, params) { return this.cdp.send(method, params, this.sid); }
    async ev(expression, timeout = 900000) {
        const res = await Promise.race([
            this.send('Runtime.evaluate', {expression, awaitPromise: true, returnByValue: true}),
            sleep(timeout).then(() => { throw new Error('evaluate timed out: ' + expression.slice(0, 80)); }),
        ]);
        if (res.exceptionDetails) throw new Error('page error: ' + (res.exceptionDetails.exception?.description || res.exceptionDetails.text) + ' in ' + expression.slice(0, 120));
        return res.result.value;
    }
    async goto(url) {
        await this.send('Page.navigate', {url});
        await this.waitFor('document.readyState === "complete"', 120000);
    }
    /** Polls until the expression is truthy; returns the page clock (performance.now) at that moment. */
    async waitFor(expr, timeout = 120000, every = 25) {
        const end = Date.now() + timeout;
        while (Date.now() < end) {
            try {
                const v = await this.ev(`(() => { try { return (${expr}) ? performance.now() : 0; } catch (e) { return 0; } })()`, timeout);
                if (v) return v;
            } catch (e) { if (!/Cannot find context|Execution context|navigat/i.test(e.message)) throw e; }
            await sleep(every);
        }
        throw new Error('timed out waiting for: ' + expr);
    }
    now() { return this.ev('performance.now()'); }
    /** The long tasks and frames that started in [from, to). */
    async tasks(from, to = Infinity) {
        const {lt, loaf} = await this.ev('({lt: __perf.lt, loaf: __perf.loaf})');
        const pick = list => list.filter(([s]) => s >= from && s < to);
        return {lt: pick(lt), loaf: pick(loaf)};
    }
}
