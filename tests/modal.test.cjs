'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { test } = require('node:test');

const modules = process.env.CONNECTOR_UI_NODE_MODULES;
assert.ok(modules && path.isAbsolute(modules),
    'CONNECTOR_UI_NODE_MODULES must point to the temporary absolute node_modules directory.');
const { JSDOM } = require(path.join(modules, 'jsdom'));
const makeJQuery = require(path.join(modules, 'jquery'));

const blade = fs.readFileSync(path.join(__dirname,
    '../src/resources/views/users/partials/edit-modal.blade.php'), 'utf8');
const scriptMatch = blade.match(/<script\b[^>]*>([\s\S]*?)<\/script>/i);
assert.ok(scriptMatch, 'The production modal must contain its handler script.');
const productionScript = scriptMatch[1];
const csrf = 'fixture-csrf-token';
const adminMarkup = blade.slice(0, scriptMatch.index)
    .replace(/@push\([^\n]*\)/g, '')
    .replace(/@can\([^)]*\)|@endcan/g, '')
    .replace(/@csrf\(\)/g, '<input type="hidden" name="_token" value="' + csrf + '">')
    .replace(/\{\{[\s\S]*?\}\}/g, 'fixture-value');

function fixture(t) {
    const dom = new JSDOM('<!doctype html><html><body>' + adminMarkup + '</body></html>', {
        url: 'https://seat.example.test/seat-connector/users',
        runScripts: 'outside-only',
    });
    t.after(() => dom.window.close());
    const $ = makeJQuery(dom.window);
    dom.window.$ = $;
    dom.window.jQuery = $;
    const gets = [];
    const writes = [];

    function transport(details) {
        const deferred = $.Deferred();
        const request = deferred.promise({
            aborted: false,
            // Cancellation can lose a network race. Allow later delivery so the
            // production version guards, rather than the stub, provide safety.
            abort() {
                this.aborted = true;
                return this;
            },
        });
        return {
            ...details,
            request,
            resolve(value) { deferred.resolve(value); },
            reject(xhr = {}, state = 'error') { deferred.reject(xhr, state); },
        };
    }

    $.getJSON = function (url) {
        const entry = transport({ url });
        gets.push(entry);
        return entry.request;
    };
    $.ajax = function (options) {
        const entry = transport({ options });
        writes.push(entry);
        return entry.request;
    };
    dom.window.eval(productionScript);

    return {
        dom, $, gets, writes,
        show(id = 10, connector = 'discord') {
            const button = $('<button>').attr({
                'data-user-id': id,
                'data-name-override': '',
                'data-connector-type': connector,
                'data-strict-exemptions-url': '/users/' + id + '/strict-exemptions',
            });
            $('#userModal').trigger($.Event('show.bs.modal', { relatedTarget: button[0] }));
        },
        hide() { $('#userModal').trigger('hidden.bs.modal'); },
        load(id = 10, characters = [
            { id: 1001, name: 'Authenticated Main', has_token: true, exempt: false },
            { id: 1002, name: 'Parked Alt', has_token: false, exempt: true },
        ]) {
            this.show(id);
            gets.at(-1).resolve({
                characters,
                update_url: '/users/' + id + '/strict-exemptions',
            });
        },
        submit() { $('#strict-exemptions-form').trigger('submit'); },
        button() { return $('#strict-exemptions-form button[type="submit"]'); },
        checkboxes() { return $('#strict-exemptions-form input[name="characters[]"]'); },
        status() { return $('#strict-exemptions-status').text(); },
    };
}

test('the production script renders character names as text and preserves saved flags', (t) => {
    const h = fixture(t);
    const hostile = '<img src=x onerror="window.injected=true">';
    h.load(10, [
        { id: 1001, name: hostile, has_token: false, exempt: true },
        { id: 1002, name: 'Main', has_token: true, exempt: false },
    ]);

    assert.equal(h.$('#strict-exemptions-characters img').length, 0);
    assert.equal(h.dom.window.injected, undefined);
    assert.ok(h.$('#strict-exemptions-characters').text().includes(hostile));
    assert.ok(h.$('#strict-exemptions-characters').text().includes('invalid token'));
    assert.ok(h.$('#strict-exemptions-characters').text().includes('valid token'));
    assert.equal(h.checkboxes().eq(0).prop('checked'), true);
    assert.equal(h.checkboxes().eq(1).prop('checked'), false);
});

test('failed reload cannot submit an empty selection over previously saved flags', (t) => {
    const h = fixture(t);
    h.load();
    assert.equal(h.checkboxes().eq(1).prop('checked'), true);
    h.hide();
    h.show();
    h.gets.at(-1).reject({ status: 500 });

    assert.equal(h.checkboxes().length, 0);
    assert.equal(h.button().prop('disabled'), true);
    assert.match(h.status(), /could not load/i);
    h.submit();
    assert.equal(h.writes.length, 0);
});

test('malformed load data also leaves saving disabled', (t) => {
    const h = fixture(t);
    h.show();
    h.gets.at(-1).resolve({ characters: {}, update_url: '/unsafe-empty-save' });

    assert.equal(h.button().prop('disabled'), true);
    assert.match(h.status(), /could not load/i);
    h.submit();
    assert.equal(h.writes.length, 0);
});

test('a null load response reports an error and leaves saving disabled', (t) => {
    const h = fixture(t);
    h.show();
    h.gets.at(-1).resolve(null);

    assert.equal(h.button().prop('disabled'), true);
    assert.match(h.status(), /could not load/i);
    h.submit();
    assert.equal(h.writes.length, 0);
});

test('switching identities cancels the old GET and ignores a late response', (t) => {
    const h = fixture(t);
    h.show(10);
    const previous = h.gets.at(-1);
    h.show(20);
    assert.equal(previous.request.aborted, true);
    const current = h.gets.at(-1);
    current.resolve({
        characters: [{ id: 2001, name: 'Current User', has_token: true, exempt: false }],
        update_url: '/users/20/strict-exemptions',
    });
    previous.resolve({
        characters: [{ id: 1001, name: 'Previous User', has_token: false, exempt: true }],
        update_url: '/users/10/strict-exemptions',
    });

    assert.equal(h.checkboxes().length, 1);
    assert.equal(h.checkboxes().val(), '2001');
    assert.ok(!h.$('#strict-exemptions-characters').text().includes('Previous User'));
    h.submit();
    assert.equal(h.writes.at(-1).options.url, '/users/20/strict-exemptions');
});

test('non-Discord identities do not load or submit Discord exemptions', (t) => {
    const h = fixture(t);
    h.show(10, 'teamspeak');

    assert.equal(h.gets.length, 0);
    assert.equal(h.$('#strict-exemptions-form').css('display'), 'none');
    assert.equal(h.button().prop('disabled'), true);
    h.submit();
    assert.equal(h.writes.length, 0);
});

test('clearing all flags sends an explicit empty array with CSRF and freezes save controls', (t) => {
    const h = fixture(t);
    h.load();
    h.checkboxes().prop('checked', false);
    h.submit();
    const write = h.writes.at(-1);

    assert.equal(write.options.method, 'PUT');
    assert.equal(write.options.contentType, 'application/json');
    assert.equal(write.options.dataType, 'json');
    assert.deepEqual(JSON.parse(write.options.data), { _token: csrf, characters: [] });
    assert.equal(h.button().prop('disabled'), true);
    h.checkboxes().each(function () { assert.equal(h.$(this).prop('disabled'), true); });
    h.submit();
    assert.equal(h.writes.length, 1);

    write.resolve({ success: true, message: 'Exemptions saved.' });
    assert.equal(h.status(), 'Exemptions saved.');
    assert.equal(h.button().prop('disabled'), false);
    h.checkboxes().each(function () { assert.equal(h.$(this).prop('disabled'), false); });
});

test('a rejected save reports uncertainty and restores the controls', (t) => {
    const h = fixture(t);
    h.load();
    h.submit();
    h.writes.at(-1).reject({ status: 500 });

    assert.match(h.status(), /could not confirm|reopen/i);
    assert.equal(h.button().prop('disabled'), false);
    h.checkboxes().each(function () { assert.equal(h.$(this).prop('disabled'), false); });
});

for (const [name, payload] of [
    ['login HTML', '<html><body>Sign in</body></html>'],
    ['null payload', null],
    ['missing success flag', { message: 'Incorrect success' }],
    ['failed success flag', { success: false, message: 'Incorrect success' }],
    ['missing message', { success: true }],
]) {
    test('an unexpected ' + name + ' save response cannot appear successful', (t) => {
        const h = fixture(t);
        h.load();
        h.submit();
        h.writes.at(-1).resolve(payload);

        assert.match(h.status(), /could not confirm|reopen/i);
        assert.notEqual(h.status(), 'Incorrect success');
        assert.equal(h.button().prop('disabled'), false);
    });
}

test('a late PUT cannot change another identity or unlock its pending save', (t) => {
    const h = fixture(t);
    h.load(10);
    h.submit();
    const previous = h.writes.at(-1);
    h.hide();
    h.load(20, [
        { id: 2001, name: 'Current Main', has_token: true, exempt: false },
        { id: 2002, name: 'Current Alt', has_token: false, exempt: true },
    ]);
    h.submit();
    const current = h.writes.at(-1);
    const pendingStatus = h.status();

    previous.resolve({ success: true, message: 'Old identity saved.' });
    assert.equal(h.status(), pendingStatus);
    assert.equal(h.button().prop('disabled'), true);
    h.checkboxes().each(function () { assert.equal(h.$(this).prop('disabled'), true); });
    assert.equal(h.checkboxes().eq(0).val(), '2001');

    current.resolve({ success: true, message: 'Current identity saved.' });
    assert.equal(h.status(), 'Current identity saved.');
    assert.equal(h.button().prop('disabled'), false);
});
