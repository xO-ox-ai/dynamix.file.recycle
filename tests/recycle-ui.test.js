'use strict';

const assert = require('assert');

class ClassList {
    constructor() { this.values = new Set(); }
    add(value) { this.values.add(value); }
    remove(value) { this.values.delete(value); }
    contains(value) { return this.values.has(value); }
    toggle(value, force) { if (force) this.values.add(value); else this.values.delete(value); }
}

class Element {
    constructor(tagName) {
        this.tagName = tagName.toUpperCase();
        this.children = [];
        this.attributes = {};
        this.className = '';
        this.classList = new ClassList();
        this.parentNode = null;
        this.nextSibling = null;
        this.disabled = false;
        this.value = '';
        this.listeners = {};
    }
    setAttribute(name, value) { this.attributes[name] = String(value); }
    getAttribute(name) { return Object.prototype.hasOwnProperty.call(this.attributes, name) ? this.attributes[name] : null; }
    addEventListener(name, listener) { this.listeners[name] = listener; }
    appendChild(child) {
        child.parentNode = this;
        this.children.push(child);
        this.syncSiblings();
        return child;
    }
    insertBefore(child, before) {
        child.parentNode = this;
        const index = before ? this.children.indexOf(before) : -1;
        if (index >= 0) this.children.splice(index, 0, child);
        else this.children.push(child);
        this.syncSiblings();
        return child;
    }
    syncSiblings() {
        this.children.forEach((child, index) => {
            child.nextSibling = this.children[index + 1] || null;
        });
    }
}

const controls = new Element('div');
controls.id = 'buttons';
const done = controls.appendChild(new Element('input'));
done.type = 'button';
done.setAttribute('onclick', "done('Browse')");
const nativeDelete = controls.appendChild(new Element('input'));
nativeDelete.type = 'button';
nativeDelete.value = 'Delete';
nativeDelete.setAttribute('onclick', 'doActions(1,this.value)');
const nativeCopy = controls.appendChild(new Element('input'));
nativeCopy.type = 'button';
nativeCopy.value = 'Copy';
nativeCopy.setAttribute('onclick', 'doActions(3,this.value)');
controls.querySelectorAll = (selector) => selector === 'input[type="button"]' ? controls.children : [];

global.window = {
    __recycleRuntime: {
        enabled: true,
        version: 'test',
        i18n: { btnBatch: 'Recycle', btnTitle: 'Move selected to Recycle Bin' }
    },
    location: { pathname: '/Main/Browse' },
    confirm: () => true
};
let selectedChecks = [];
const rowAction = new Element('i');
rowAction.setAttribute('data', '/mnt/disk1/example.txt');
rowAction.setAttribute('type', 'f');
global.document = {
    readyState: 'complete',
    body: { contains: () => true, appendChild: () => {} },
    getElementById: (id) => id === 'buttons' ? controls : (id === 'row_1' ? rowAction : null),
    querySelectorAll: (selector) => selector === 'i[id^="check_"].fa-check-square-o' ? selectedChecks : [],
    createElement: (tagName) => new Element(tagName),
    addEventListener: () => {}
};

require('../source/dynamix.file.recycle/javascript/recycle.js');

assert.strictEqual(controls.children[1], nativeDelete, 'native Delete control moved unexpectedly');
const recycle = controls.children[2];
assert.strictEqual(recycle.id, 'recycle-selected-button', 'batch recycle control was not created');
assert.strictEqual(recycle.className, 'dfm_control extra recycle-batch-action', 'native DFM selection classes are missing');
assert.strictEqual(recycle.value, 'Recycle', 'localized batch label is missing');
assert.strictEqual(recycle.disabled, true, 'batch control must start disabled without a selection');
assert.strictEqual(recycle.nextSibling, nativeCopy, 'batch recycle control is not immediately after Delete');
assert.strictEqual(controls.children.filter((item) => item.id === 'recycle-selected-button').length, 1, 'batch control was inserted more than once');

const check = new Element('i');
check.id = 'check_1';
selectedChecks = [check];
const requests = [];
global.fetch = (url, options) => {
    const body = new URLSearchParams(options.body);
    const action = body.get('action');
    requests.push({ action, path: body.get('path') });
    const payload = action === 'inspect'
        ? { ok: true, inspection_token: 'token-1', path: body.get('path') }
        : { ok: true };
    return Promise.resolve({ status: 200, ok: true, json: () => Promise.resolve(payload) });
};
let refreshed = false;
global.window.loadList = () => { refreshed = true; };

recycle.disabled = false;
recycle.listeners.click();

setTimeout(() => {
    assert.deepStrictEqual(requests.map((request) => request.action), ['inspect', 'recycle'], 'batch action did not inspect before mutation');
    assert.strictEqual(requests[0].path, '/mnt/disk1/example.txt', 'selected DFM row path was not inspected');
    assert.strictEqual(requests[1].path, '/mnt/disk1/example.txt', 'inspected path was not recycled');
    assert.strictEqual(refreshed, true, 'DFM list was not refreshed after recycling');
    requests.length = 0;
    refreshed = false;
    global.fetch = (url, options) => {
        const body = new URLSearchParams(options.body);
        const action = body.get('action');
        requests.push({ action, path: body.get('path') });
        if (action === 'inspect') {
            return Promise.resolve({ status: 200, ok: true, json: () => Promise.resolve({ ok: true, inspection_token: 'token-2' }) });
        }
        return Promise.resolve({ status: 500, ok: false, json: () => Promise.resolve({ ok: false, error: 'simulated failure' }) });
    };
    recycle.disabled = false;
    recycle.listeners.click();
    setTimeout(() => {
        assert.strictEqual(refreshed, false, 'failed non-mutating recycle unexpectedly refreshed DFM and cleared selection');
        assert.strictEqual(recycle.disabled, false, 'failed non-mutating recycle did not restore the selected action state');
        console.log('DFM bottom-control contract passed.');
        process.exit(0);
    }, 20);
}, 20);
