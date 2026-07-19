'use strict';

const assert = require('assert');

class ClassList {
    constructor(owner) {
        this.owner = owner;
        this.values = new Set();
    }
    add(value) { this.values.add(value); this.owner.className = Array.from(this.values).join(' '); }
    contains(value) { return this.values.has(value); }
    toggle(value, force) {
        if (force) this.add(value);
        else this.values.delete(value);
    }
}

class Element {
    constructor(tagName) {
        this.tagName = tagName.toUpperCase();
        this.children = [];
        this.attributes = {};
        this.className = '';
        this.classList = new ClassList(this);
        this.firstChild = null;
        this.parentNode = null;
    }
    setAttribute(name, value) { this.attributes[name] = String(value); }
    getAttribute(name) { return Object.prototype.hasOwnProperty.call(this.attributes, name) ? this.attributes[name] : null; }
    removeAttribute(name) { delete this.attributes[name]; }
    appendChild(child) {
        child.parentNode = this;
        this.children.push(child);
        this.firstChild = this.children[0] || null;
        return child;
    }
    insertBefore(child, before) {
        child.parentNode = this;
        const index = before ? this.children.indexOf(before) : -1;
        if (index >= 0) this.children.splice(index, 0, child);
        else this.children.push(child);
        this.firstChild = this.children[0] || null;
        return child;
    }
    querySelector(selector) {
        if (selector === 'i[id^="row_"][data][type]') return this.action || null;
        if (selector === '.recycle-slot') {
            return this.children.find((child) => child.className === 'recycle-slot') || null;
        }
        if (selector.startsWith('.recycle-action[data-item-id="')) {
            return this.children.find((child) => child.className === 'recycle-action') || null;
        }
        return null;
    }
}

const row = new Element('tr');
const checkboxCell = row.appendChild(new Element('td'));
const typeCell = row.appendChild(new Element('td'));
const nameCell = row.appendChild(new Element('td'));
const ownerCell = row.appendChild(new Element('td'));
const permissionsCell = row.appendChild(new Element('td'));
const sizeCell = row.appendChild(new Element('td'));
const hiddenActionCell = row.appendChild(new Element('td'));
hiddenActionCell.classList.add('responsive-hidden');

const originalName = nameCell.appendChild(new Element('a'));
const action = new Element('i');
action.setAttribute('id', 'row_1');
action.setAttribute('data', '/mnt/disk2/Movies/example.mkv');
action.setAttribute('type', 'f');
row.action = action;

const table = new Element('table');
table.querySelectorAll = (selector) => selector === 'tbody:not(.tablesorter-infoOnly) > tr' ? [row] : [];

global.window = {
    __recycleRuntime: {
        enabled: true,
        version: 'test',
        i18n: { btnTitle: 'Move to Recycle Bin' }
    },
    location: { pathname: '/Main/Browse' },
    CSS: { escape: (value) => value }
};
global.document = {
    readyState: 'complete',
    body: { contains: () => true, appendChild: () => {} },
    querySelector: (selector) => selector === 'table.indexer' ? table : null,
    createElement: (tagName) => new Element(tagName),
    addEventListener: () => {}
};
global.MutationObserver = class {
    observe() {}
    disconnect() {}
};

require('../source/dynamix.file.recycle/javascript/recycle.js');

assert.strictEqual(row.getAttribute('data-recycle-injected'), '1', 'row was not decorated');
assert.strictEqual(nameCell.children[0].className, 'recycle-slot', 'control slot is not first in the name cell');
assert.strictEqual(nameCell.children[1], originalName, 'existing name content moved unexpectedly');
assert.strictEqual(hiddenActionCell.querySelector('.recycle-slot'), null, 'control was placed in a responsive-hidden action cell');

const button = nameCell.children[0].children[0];
assert.strictEqual(button.className, 'recycle-action', 'recycle button was not created');
assert.strictEqual(button.getAttribute('data-recycle-path'), '/mnt/disk2/Movies/example.mkv', 'row path was not preserved');
assert.strictEqual(button.children[0].className, 'fa fa-trash-o recycle-icon', 'native icon class is missing');

void checkboxCell;
void typeCell;
void ownerCell;
void permissionsCell;
void sizeCell;
console.log('DFM responsive UI contract passed.');
