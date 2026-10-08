// Tagapatakbo lang: binabasa ang listahan ng tawag ({fn, args}) mula sa stdin bilang JSON, tinatawag ang mga
// function ng script na ibinigay sa unang argument, at ipini-print ang mga sagot bilang JSON. Walang assertion
// dito: ang inaasahang value ay nasa PHPUnit class na tumatawag nito. Ibinabalik din ang args pagkatapos ng
// tawag, para makita kung binago ng function ang ibinigay sa kanya.
// Plain na script ang file (hindi module), kaya pinapatakbo ito gaya ng sa browser: sa sariling context na may
// `self`, at doon kinukuha ang iisang pangalang inilalagay nito.
const fs = require('fs');
const vm = require('vm');

const page = {};
page.self = page;
vm.runInNewContext(fs.readFileSync(process.argv[2], 'utf8'), page, { filename: process.argv[2] });
const api = page.ItemTableFit;

let input = '';
process.stdin.setEncoding('utf8');
process.stdin.on('data', (chunk) => { input += chunk; });
process.stdin.on('end', () => {
  const calls = JSON.parse(input);
  const out = calls.map((call) => {
    if (typeof api[call.fn] !== 'function') return { error: 'no function: ' + call.fn };
    try {
      const value = api[call.fn](...call.args);
      return { value: value === undefined ? null : value, args: call.args };
    } catch (e) {
      return { error: String((e && e.message) || e) };
    }
  });
  process.stdout.write(JSON.stringify(out));
});
